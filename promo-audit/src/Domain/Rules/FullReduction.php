<?php

declare(strict_types=1);

namespace PromoAudit\Domain\Rules;

use PromoAudit\Domain\PromotionRule;
use PromoAudit\Domain\RuleContext;

/**
 * 满减:满 threshold 减 reduction,可限定类目。
 */
final class FullReduction implements PromotionRule
{
    /**
     * @param list<string>|null $categories null = 全场
     * @param string $thresholdBase 'original'|'current' 门槛基数(见 PromotionRule 注释)
     */
    public function __construct(
        private readonly string $id,
        private readonly int $thresholdCents,
        private readonly int $reductionCents,
        private readonly ?array $categories = null,
        private readonly string $thresholdBase = 'original',
    ) {}

    public static function fromArray(array $r): self
    {
        return new self(
            id: (string) $r['id'],
            thresholdCents: (int) round($r['threshold'] * 100),
            reductionCents: (int) round($r['reduction'] * 100),
            categories: $r['categories'] ?? null,
            thresholdBase: $r['threshold_base'] ?? 'original',
        );
    }

    public function id(): string { return $this->id; }
    public function type(): string { return 'full_reduction'; }
    public function consumesCouponBudget(): bool { return false; } // 自动满减,每单都触发

    public function describe(): string
    {
        $scope = $this->categories === null ? '全场' : implode('/', $this->categories);
        return sprintf('满%.2f减%.2f(%s,门槛基数:%s)',
            $this->thresholdCents / 100, $this->reductionCents / 100, $scope, $this->thresholdBase);
    }

    public function isApplicable(RuleContext $ctx): bool
    {
        $base = $this->thresholdBase === 'original'
            ? $ctx->scopeGross($this->categories)
            : $ctx->scopeShareOfPayable($this->categories);
        return $base >= $this->thresholdCents;
    }

    public function discount(RuleContext $ctx): int
    {
        return $this->isApplicable($ctx) ? $this->reductionCents : 0;
    }

    public function maxPossibleDiscount(RuleContext $ctx): int
    {
        // 乐观假设门槛必然满足
        return $this->reductionCents;
    }
}
