<?php

declare(strict_types=1);

namespace PromoAudit\Domain;

/**
 * 计价引擎:按给定顺序对一单应用一组规则。
 *
 * 两个关键开关,用来复现真实系统的缺陷模式:
 *  - clampAtZero=false:不在每步/最终把应付截断到 0(很多线上 bug 就是漏了截断)
 *  - enforceExclusions=false:引擎不校验互斥(校验只在前端/下单页做 → 可被绕过)
 */
final class PricingEngine
{
    /**
     * @param array<string,Product> $catalog
     * @param array<string,PromotionRule> $rules
     * @param array<string,array<string,int>> $memberPrices memberLevel => [productId => cents]
     */
    public function __construct(
        private readonly array $catalog,
        private readonly array $rules,
        private readonly array $memberPrices,
        private readonly bool $clampAtZero = false,
        private readonly bool $enforceExclusions = true,
        private readonly ?ExclusionMatrix $exclusions = null,
    ) {}

    /**
     * @param list<string> $ruleSequence 规则 id,按应用顺序
     */
    public function price(Cart $cart, string $memberLevel, array $ruleSequence, int $userPoints): OrderResult
    {
        if ($this->enforceExclusions && $this->exclusions !== null) {
            $violation = $this->exclusions->firstViolation($ruleSequence);
            if ($violation !== null) {
                // 引擎拒绝:等价于真实系统下单接口报错。搜索层一般已剪掉,这里兜底。
                return new OrderResult($cart, $memberLevel, 0, PHP_INT_MAX, [], []);
            }
        }

        [$gross, $unitPrices] = $cart->grossWithUnitPrices($this->catalog, $memberLevel, $this->memberPrices);

        $ctx = new RuleContext($cart, $this->catalog, $unitPrices, $memberLevel, $gross, $gross, $userPoints);
        $payable = $gross;
        $steps = [];
        $applied = [];

        foreach ($ruleSequence as $rid) {
            $rule = $this->rules[$rid] ?? null;
            if ($rule === null || !$rule->isApplicable($ctx)) {
                continue;
            }
            $off = $rule->discount($ctx);
            if ($off <= 0) {
                continue;
            }
            $payable -= $off;
            if ($this->clampAtZero && $payable < 0) {
                $payable = 0;
            }
            $steps[] = ['rule' => $rid, 'desc' => $rule->describe(), 'off' => $off, 'after' => $payable];
            $applied[] = $rid;
            $ctx = $ctx->withPayable($payable);
        }

        return new OrderResult($cart, $memberLevel, $gross, $payable, $steps, $applied);
    }

    /**
     * 乐观最低可达价:假设剩余所有规则都能按最大额度减免(忽略相互作用与门槛)。
     * 用于剪枝:若乐观值仍高于关注阈值,则该分支不可能产生更差(更低)的结果。
     *
     * @param list<string> $remainingRuleIds
     */
    public function optimisticFloor(Cart $cart, string $memberLevel, int $currentPayable, array $remainingRuleIds, int $userPoints): int
    {
        [, $unitPrices] = $cart->grossWithUnitPrices($this->catalog, $memberLevel, $this->memberPrices);
        $gross = 0;
        foreach ($cart->lines as $pid => $qty) {
            $gross += $unitPrices[$pid] * $qty;
        }
        $ctx = new RuleContext($cart, $this->catalog, $unitPrices, $memberLevel, $gross, $currentPayable, $userPoints);
        $floor = $currentPayable;
        foreach ($remainingRuleIds as $rid) {
            $rule = $this->rules[$rid] ?? null;
            if ($rule !== null) {
                $floor -= $rule->maxPossibleDiscount($ctx);
            }
        }
        return $floor;
    }
}
