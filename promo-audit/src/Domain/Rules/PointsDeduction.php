<?php

declare(strict_types=1);

namespace PromoAudit\Domain\Rules;

use PromoAudit\Domain\PromotionRule;
use PromoAudit\Domain\RuleContext;

/**
 * 积分抵现:N 积分抵 1 元,最多抵当前应付的 maxPercent%。
 * 积分本身有钱包价值(pointValueCents),低于成本判定要把积分成本算进去。
 */
final class PointsDeduction implements PromotionRule
{
    public function __construct(
        private readonly string $id,
        private readonly int $pointsPerYuan,   // 多少积分抵 1 元
        private readonly float $maxPercent,    // 最多抵当前应付的比例,如 0.5
    ) {}

    public static function fromArray(array $r): self
    {
        return new self(
            id: (string) $r['id'],
            pointsPerYuan: (int) $r['points_per_yuan'],
            maxPercent: (float) $r['max_percent'],
        );
    }

    public function id(): string { return $this->id; }
    public function type(): string { return 'points'; }
    public function consumesCouponBudget(): bool { return false; } // 积分池共享,不算券

    public function describe(): string
    {
        return sprintf('积分抵现(%d分=1元,最高抵%.0f%%)', $this->pointsPerYuan, $this->maxPercent * 100);
    }

    public function isApplicable(RuleContext $ctx): bool
    {
        return $ctx->userPoints > 0 && $ctx->currentPayableCents > 0;
    }

    public function discount(RuleContext $ctx): int
    {
        if (!$this->isApplicable($ctx)) {
            return 0;
        }
        $pointsValue = intdiv($ctx->userPoints, $this->pointsPerYuan) * 100;
        $cap = (int) floor($ctx->currentPayableCents * $this->maxPercent);
        return min($pointsValue, $cap);
    }

    public function maxPossibleDiscount(RuleContext $ctx): int
    {
        $pointsValue = intdiv($ctx->userPoints, $this->pointsPerYuan) * 100;
        return min($pointsValue, (int) floor($ctx->currentPayableCents * $this->maxPercent));
    }
}
