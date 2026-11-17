<?php

declare(strict_types=1);

namespace PromoAudit\Search;

use PromoAudit\Domain\Cart;
use PromoAudit\Domain\ExclusionMatrix;
use PromoAudit\Domain\PricingEngine;
use PromoAudit\Domain\Product;
use PromoAudit\Domain\PromotionRule;
use PromoAudit\Domain\RuleContext;

/**
 * 状态空间搜索器。
 *
 * 搜索空间 = 购物车组合 × 会员等级 × 券序列 × 拆单方案,四层剪枝:
 *
 * 1. 购物车层:限制 SKU 种类数 / 单 SKU 数量 / 总件数(配置可调)。
 * 2. 序列层:对「同一购物车 + 同一可用券集」的求价用 DP(状态 = 当前应付 × 已用规则掩码),
 *    把 n! 的排列枚举压成 O(不同应付值 × 2^n);同类规则排列天然合并。
 * 3. 单调性:积分抵现对最终价单调递减,只取「用满」和「不用」两档;数量维度上,
 *    一旦某购物车已 ≤0,记录最小复现后不再加件。
 * 4. 拆单层:不枚举「订单对」,而是先缓存每个购物车的单订单最优价,
 *    拆单时只做 2^distinct 的购物车内划分 + 3^k 的券预算分配(k=券数),查表求和。
 */
final class Explorer
{
    /** @var array<string,Product> */
    private array $catalog;
    /** @var array<string,PromotionRule> */
    private array $rules;
    /** @var list<string> 消耗券预算的规则 id(折扣券等) */
    private array $couponIds;
    /** @var array<string,int> 规则 id => bit 位 */
    private array $ruleBits;

    /** @var array<string,array<string,array<int,array{int,list<string>}>>> cartKey => level => couponMask => [final, seq] */
    private array $bestWithPoints = [];
    private array $bestNoPoints = [];

    /** @var array<string,Finding> */
    private array $findings = [];

    private int $evaluations = 0;

    /**
     * @param array<string,Product> $catalog
     * @param array<string,PromotionRule> $rules
     * @param array<string,array<string,int>> $memberPrices
     */
    public function __construct(
        array $catalog,
        array $rules,
        private readonly array $memberPrices,
        private readonly ExclusionMatrix $exclusions,
        private readonly SearchConfig $config,
    ) {
        $this->catalog = $catalog;
        $this->rules = $rules;
        $this->couponIds = array_values(array_map(
            fn ($r) => $r->id(),
            array_filter($rules, fn (PromotionRule $r) => $r->consumesCouponBudget())
        ));
        $this->ruleBits = [];
        $i = 0;
        foreach (array_keys($rules) as $rid) {
            $this->ruleBits[$rid] = 1 << $i++;
        }
    }

    /** @return array{findings:list<Finding>,evaluations:int,carts:int} */
    public function run(): array
    {
        $carts = $this->enumerateCarts();

        // Phase A:单订单,缓存每个 (cart, level, couponMask) 的最优价
        foreach ($carts as $cart) {
            foreach ($this->config->memberLevels as $level) {
                foreach ($this->couponMasks() as $mask) {
                    $this->singleBest($cart, $level, $mask, true);
                    $this->singleBest($cart, $level, $mask, false);
                }
            }
        }
        $this->collectSingleOrderFindings($carts);

        // Phase B:拆单
        if ($this->config->auditSplitOrders) {
            $this->collectSplitFindings($carts);
        }

        $findings = array_values($this->findings);
        usort($findings, fn (Finding $a, Finding $b) =>
            $b->severity() <=> $a->severity()
            ?: $a->totalFinalCents <=> $b->totalFinalCents
            ?: $a->totalItems() <=> $b->totalItems()
        );
        $findings = array_slice($findings, 0, $this->config->maxFindings);

        return ['findings' => $findings, 'evaluations' => $this->evaluations, 'carts' => count($carts)];
    }

    // ---------------------------------------------------------------- 购物车枚举

    /** @return list<Cart> */
    private function enumerateCarts(): array
    {
        $out = [];
        $ids = array_keys($this->catalog);
        $walk = function (int $idx, array $lines, int $items) use (&$walk, &$out, $ids): void {
            if ($lines !== []) {
                $out[] = new Cart($lines);
            }
            for ($i = $idx; $i < count($ids); $i++) {
                if (count($lines) >= $this->config->maxDistinctSkus && !isset($lines[$ids[$i]])) {
                    continue;
                }
                for ($q = 1; $q <= $this->config->maxQtyPerSku; $q++) {
                    if ($items + $q > $this->config->maxTotalItems) {
                        break;
                    }
                    $lines[$ids[$i]] = $q;
                    $walk($i + 1, $lines, $items + $q);
                }
                unset($lines[$ids[$i]]);
            }
        };
        $walk(0, [], 0);
        return $out;
    }

    // ---------------------------------------------------------------- 单订单 DP

    /** @return list<int> 所有券预算子集掩码(券可不用,故为幂集) */
    private function couponMasks(): array
    {
        $masks = [0];
        foreach ($this->couponIds as $cid) {
            $bit = $this->ruleBits[$cid];
            foreach ($masks as $m) {
                $masks[] = $m | $bit;
            }
        }
        return array_values(array_unique($masks));
    }

    /**
     * 单订单最低实付(对券序列做 DP)。结果缓存。
     * @return array{int,list<string>} [最低实付(分), 达到它的规则序列]
     */
    private function singleBest(Cart $cart, string $level, int $couponMask, bool $withPoints): array
    {
        $cache =& $this->cache($withPoints);
        $key = $cart->key();
        if (isset($cache[$key][$level][$couponMask])) {
            return $cache[$key][$level][$couponMask];
        }

        [$gross, $unitPrices] = $cart->grossWithUnitPrices($this->catalog, $level, $this->memberPrices);
        $points = $withPoints ? $this->config->userPoints : 0;

        // 允许使用的规则:券按 couponMask,非券规则全部可用
        $allowed = [];
        foreach ($this->rules as $rid => $rule) {
            $bit = $this->ruleBits[$rid];
            if ($rule->consumesCouponBudget() && !($couponMask & $bit)) {
                continue;
            }
            $allowed[$rid] = $bit;
        }

        $memo = [];
        $dfs = function (int $payable, int $usedMask) use (&$dfs, &$memo, $allowed, $cart, $level, $gross, $unitPrices, $points): array {
            $mkey = $payable . '|' . $usedMask;
            if (isset($memo[$mkey])) {
                return $memo[$mkey];
            }
            $best = [$payable, []]; // 随时可以停止用券
            foreach ($allowed as $rid => $bit) {
                if ($usedMask & $bit) {
                    continue;
                }
                // 互斥剪枝:与已用规则冲突则跳过(仅当引擎配置为强制校验时)
                if ($this->config->enforceExclusions) {
                    $conflict = false;
                    foreach ($allowed as $rid2 => $bit2) {
                        if (($usedMask & $bit2) && $this->exclusions->areExclusive($rid, $rid2)) {
                            $conflict = true;
                            break;
                        }
                    }
                    if ($conflict) {
                        continue;
                    }
                }
                $this->evaluations++;
                $ctx = new RuleContext($cart, $this->catalog, $unitPrices, $level, $gross, $payable, $points);
                $rule = $this->rules[$rid];
                if (!$rule->isApplicable($ctx)) {
                    continue;
                }
                $off = $rule->discount($ctx);
                if ($off <= 0) {
                    continue;
                }
                $next = $payable - $off;
                if ($this->config->clampAtZero && $next < 0) {
                    $next = 0;
                }
                [$subFinal, $subSeq] = $dfs($next, $usedMask | $bit);
                if ($subFinal < $best[0]) {
                    $best = [$subFinal, array_merge([$rid], $subSeq)];
                }
            }
            return $memo[$mkey] = $best;
        };

        return $cache[$key][$level][$couponMask] = $dfs($gross, 0);
    }

    private function &cache(bool $withPoints): array
    {
        if ($withPoints) {
            return $this->bestWithPoints;
        }
        return $this->bestNoPoints;
    }

    // ---------------------------------------------------------------- 发现收集:单订单

    /** @param list<Cart> $carts */
    private function collectSingleOrderFindings(array $carts): void
    {
        $engine = $this->makeEngine();
        foreach ($carts as $cart) {
            $cost = $cart->totalCost($this->catalog);
            foreach ($this->config->memberLevels as $level) {
                $fullMask = array_reduce($this->couponMasks(), fn ($a, $b) => $a | $b, 0);
                [$final, $seq] = $this->singleBest($cart, $level, $fullMask, true);
                if ($seq === []) {
                    continue;
                }
                if ($final > 0 && $final >= $cost) {
                    continue;
                }
                $result = $engine->price($cart, $level, $seq, $this->config->userPoints);
                $type = $final <= 0 ? Finding::NON_POSITIVE : Finding::BELOW_COST;
                $this->addFinding(new Finding(
                    type: $type,
                    orders: [['cart' => $cart, 'member_level' => $level, 'sequence' => $seq, 'result' => $result]],
                    totalFinalCents: $final,
                    totalCostCents: $cost,
                    legalSingleBestCents: null,
                    notes: $this->config->clampAtZero ? ['引擎已配置零值截断'] : ['引擎未做零值截断(clamp_at_zero=false)'],
                ));
            }
        }
    }

    // ---------------------------------------------------------------- 发现收集:拆单

    /** @param list<Cart> $carts */
    private function collectSplitFindings(array $carts): void
    {
        $engine = $this->makeEngine();
        $fullMask = array_reduce($this->couponMasks(), fn ($a, $b) => $a | $b, 0);

        foreach ($carts as $cart) {
            if ($cart->distinctSkus() < 1 || $cart->totalItems() < 2) {
                continue;
            }
            $cost = $cart->totalCost($this->catalog);
            foreach ($this->config->memberLevels as $level) {
                [$legalBest] = $this->singleBest($cart, $level, $fullMask, true);

                foreach ($this->partitions($cart) as [$a, $b]) {
                    // 券预算分配:每张券给 A、给 B、或不用
                    foreach ($this->budgetSplits() as [$maskA, $maskB]) {
                        // 积分池共享:积分只给其中一单(两种都试)
                        foreach ([true, false] as $pointsToA) {
                            [$fa, $seqA] = $pointsToA
                                ? $this->singleBest($a, $level, $maskA, true)
                                : $this->singleBest($a, $level, $maskA, false);
                            [$fb, $seqB] = $pointsToA
                                ? $this->singleBest($b, $level, $maskB, false)
                                : $this->singleBest($b, $level, $maskB, true);
                            $splitTotal = $fa + $fb;

                            if ($splitTotal >= $legalBest) {
                                continue; // 拆单没有更便宜,无套利
                            }

                            $resA = $engine->price($a, $level, $seqA, $pointsToA ? $this->config->userPoints : 0);
                            $resB = $engine->price($b, $level, $seqB, $pointsToA ? 0 : $this->config->userPoints);

                            // 是否跨订单用到了互斥对
                            $bypass = null;
                            foreach ($resA->appliedRuleIds as $ra) {
                                foreach ($resB->appliedRuleIds as $rb) {
                                    if ($this->exclusions->areExclusive($ra, $rb)) {
                                        $bypass = [$ra, $rb];
                                        break 2;
                                    }
                                }
                            }

                            $type = $bypass !== null ? Finding::EXCLUSION_BYPASS
                                : ($splitTotal <= 0 ? Finding::NON_POSITIVE
                                : ($splitTotal < $cost ? Finding::BELOW_COST : Finding::SPLIT_ARBITRAGE));

                            $notes = [sprintf('拆单合计 %.2f 元 < 单订单最优 %.2f 元', $splitTotal / 100, $legalBest / 100)];
                            if ($bypass !== null) {
                                $notes[] = sprintf('互斥规则 %s 与 %s 被拆到两个订单同时使用', $bypass[0], $bypass[1]);
                            }
                            $notes[] = '积分池按单订单独占估算(下界)';

                            $this->addFinding(new Finding(
                                type: $type,
                                orders: [
                                    ['cart' => $a, 'member_level' => $level, 'sequence' => $seqA, 'result' => $resA],
                                    ['cart' => $b, 'member_level' => $level, 'sequence' => $seqB, 'result' => $resB],
                                ],
                                totalFinalCents: $splitTotal,
                                totalCostCents: $cost,
                                legalSingleBestCents: $legalBest,
                                notes: $notes,
                            ));
                        }
                    }
                }
            }
        }
    }

    /**
     * 购物车的二划分。每行可以:全给 A / 全给 B / 按数量拆开(qA ∈ 1..q-1)。
     * 数量拆分是「同一满减规则被拆单多次触发」的来源,不能省。
     * 用 seen-set 消除 A/B 对称;每行至多 qty+1 种选择,n 行至多 Π(qty+1) 种划分。
     * @return \Generator<array{0:Cart,1:Cart}>
     */
    private function partitions(Cart $cart): \Generator
    {
        $ids = array_keys($cart->lines);
        $choices = [];
        foreach ($cart->lines as $pid => $q) {
            $opts = [[$q, 0], [0, $q]];
            for ($qA = 1; $qA < $q; $qA++) {
                $opts[] = [$qA, $q - $qA];
            }
            $choices[] = $opts;
        }
        $seen = [];
        $rec = function (int $i, array $a, array $b) use (&$rec, &$seen, $choices, $ids): \Generator {
            if ($i === count($choices)) {
                if ($a === [] || $b === []) {
                    return;
                }
                $ka = (new Cart($a))->key();
                $kb = (new Cart($b))->key();
                $sig = $ka < $kb ? $ka . '#' . $kb : $kb . '#' . $ka;
                if (isset($seen[$sig])) {
                    return;
                }
                $seen[$sig] = true;
                yield [new Cart($a), new Cart($b)];
                return;
            }
            $pid = $ids[$i];
            foreach ($choices[$i] as [$qA, $qB]) {
                if ($qA > 0) { $a[$pid] = $qA; } else { unset($a[$pid]); }
                if ($qB > 0) { $b[$pid] = $qB; } else { unset($b[$pid]); }
                yield from $rec($i + 1, $a, $b);
            }
        };
        yield from $rec(0, [], []);
    }

    /** @return list<array{0:int,1:int}> 券预算的 (A,B) 分配掩码 */
    private function budgetSplits(): array
    {
        $out = [[0, 0]];
        foreach ($this->couponMasks() as $mA) {
            foreach ($this->couponMasks() as $mB) {
                if ($mA & $mB) {
                    continue; // 同一张券不能两单都用
                }
                $out[] = [$mA, $mB];
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------- 工具

    private function makeEngine(): PricingEngine
    {
        return new PricingEngine(
            $this->catalog,
            $this->rules,
            $this->memberPrices,
            clampAtZero: $this->config->clampAtZero,
            enforceExclusions: $this->config->enforceExclusions,
            exclusions: $this->exclusions,
        );
    }

    private function addFinding(Finding $f): void
    {
        $sig = $f->signature();
        $old = $this->findings[$sig] ?? null;
        // 同签名保留「件数最少、实付最低」的最小复现
        if ($old === null
            || $f->totalItems() < $old->totalItems()
            || ($f->totalItems() === $old->totalItems() && $f->totalFinalCents < $old->totalFinalCents)) {
            $this->findings[$sig] = $f;
        }
    }
}
