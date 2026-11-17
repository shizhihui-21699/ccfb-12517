<?php

declare(strict_types=1);

namespace PromoAudit\Domain\Rules;

use PromoAudit\Domain\PromotionRule;
use PromoAudit\Domain\RuleContext;

/**
 * 折扣券:对范围内金额按比例打折,可设门槛与封顶。
 */
final class DiscountCoupon implements PromotionRule
{
    /**
     * @param list<string>|null $categories
     * @param float $rate 折扣率,0.8 = 八折
     */
    public function __construct(
        private readonly string $id,
        private readonly float $rate,
        private readonly int $thresholdCents = 0,
        private readonly ?int $capCents = null,
        private readonly ?array $categories = null,
        private readonly string $thresholdBase = 'original',
    ) {}

    public static function fromArray(array $r): self
    {
        return new self(
            id: (string) $r['id'],
            rate: (float) $r['rate'],
            thresholdCents: (int) round(($r['threshold'] ?? 0) * 100),
            capCents: isset($r['cap']) ? (int) round($r['cap'] * 100) : null,
            categories: $r['categories'] ?? null,
            thresholdBase: $r['threshold_base'] ?? 'original',
        );
    }

    public function id(): string { return $this->id; }
    public function type(): string { return 'discount_coupon'; }
    public function consumesCouponBudget(): bool { return true; }

    public function describe(): string
    {
        $scope = $this->categories === null ? '全场' : implode('/', $this->categories);
        return sprintf('%.1f折券(门槛%.2f,封顶%s,%s)',
            $this->rate * 10, $this->thresholdCents / 100,
            $this->capCents === null ? '无' : number_format($this->capCents / 100, 2), $scope);
    }

    public function isApplicable(RuleContext $ctx): bool
    {
        $base = $this->thresholdBase === 'original'
            ? $ctx->scopeGross($this->categories)
            : $ctx->scopeShareOfPayable($this->categories);
        return $base >= $this->thresholdCents && $base > 0;
    }

    public function discount(RuleContext $ctx): int
    {
        if (!$this->isApplicable($ctx)) {
            return 0;
        }
        // 对「当前应付中属于本券范围的分摊额」打折
        $eligible = $ctx->scopeShareOfPayable($this->categories);
        $off = (int) round($eligible * (1.0 - $this->rate));
        if ($this->capCents !== null) {
            $off = min($off, $this->capCents);
        }
        return $off;
    }

    public function maxPossibleDiscount(RuleContext $ctx): int
    {
        $eligible = $ctx->scopeShareOfPayable($this->categories);
        $off = (int) round($eligible * (1.0 - $this->rate));
        return $this->capCents === null ? $off : min($off, $this->capCents);
    }
}
