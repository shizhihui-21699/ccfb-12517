<?php

declare(strict_types=1);

namespace PromoAudit\Search;

use PromoAudit\Domain\Cart;
use PromoAudit\Domain\OrderResult;

/**
 * 一条漏洞发现,带可复现的下单步骤。
 */
final class Finding
{
    public const NON_POSITIVE = 'non_positive';         // 实付 <= 0
    public const BELOW_COST = 'below_cost';             // 实付 < 成本
    public const EXCLUSION_BYPASS = 'exclusion_bypass'; // 拆单/缺校验绕过互斥
    public const SPLIT_ARBITRAGE = 'split_arbitrage';   // 拆单比单订单更便宜(多次触发满减等)

    /**
     * @param list<array{cart:Cart,member_level:string,sequence:list<string>,result:OrderResult}> $orders
     * @param list<string> $notes
     */
    public function __construct(
        public readonly string $type,
        public readonly array $orders,
        public readonly int $totalFinalCents,
        public readonly int $totalCostCents,
        public readonly ?int $legalSingleBestCents,
        public readonly array $notes,
    ) {}

    public function severity(): int
    {
        return match ($this->type) {
            self::NON_POSITIVE => 4,
            self::EXCLUSION_BYPASS => 3,
            self::BELOW_COST => 2,
            default => 1,
        };
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::NON_POSITIVE => '实付为零/负数',
            self::BELOW_COST => '实付低于成本',
            self::EXCLUSION_BYPASS => '绕过互斥限制',
            self::SPLIT_ARBITRAGE => '拆单套利',
        };
    }

    /** 去重签名:类型 + 涉及规则集合 + 单/拆单 */
    public function signature(): string
    {
        $rules = [];
        foreach ($this->orders as $o) {
            foreach ($o['result']->appliedRuleIds as $r) {
                $rules[$r] = true;
            }
        }
        $ids = array_keys($rules);
        sort($ids);
        return $this->type . '|' . count($this->orders) . '|' . implode(',', $ids);
    }

    public function totalItems(): int
    {
        $n = 0;
        foreach ($this->orders as $o) {
            $n += $o['cart']->totalItems();
        }
        return $n;
    }
}
