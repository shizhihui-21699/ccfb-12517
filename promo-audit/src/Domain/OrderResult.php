<?php

declare(strict_types=1);

namespace PromoAudit\Domain;

/**
 * 一单(或一次结算)的计价结果。
 */
final class OrderResult
{
    /**
     * @param list<array{rule:string,desc:string,off:int,after:int}> $steps 每一步的扣减明细
     * @param list<string> $appliedRuleIds 实际生效(减免>0)的规则
     */
    public function __construct(
        public readonly Cart $cart,
        public readonly string $memberLevel,
        public readonly int $grossCents,
        public readonly int $finalCents,
        public readonly array $steps,
        public readonly array $appliedRuleIds,
    ) {}
}
