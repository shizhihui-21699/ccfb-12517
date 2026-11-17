<?php

declare(strict_types=1);

namespace PromoAudit\Domain;

/**
 * 计价上下文:一条规则在序列中被应用时能看到的一切。
 */
final class RuleContext
{
    /**
     * @param array<string,Product> $catalog
     * @param array<string,int> $unitPrices 生效单价(已含会员价)
     * @param int $grossCents 商品金额(会员价后、任何券前)
     * @param int $currentPayableCents 当前应付(序列中前面的规则已扣减)
     * @param int $userPoints 用户可用积分
     */
    public function __construct(
        public readonly Cart $cart,
        public readonly array $catalog,
        public readonly array $unitPrices,
        public readonly string $memberLevel,
        public readonly int $grossCents,
        public readonly int $currentPayableCents,
        public readonly int $userPoints,
    ) {}

    public function withPayable(int $payable): self
    {
        return new self(
            $this->cart, $this->catalog, $this->unitPrices,
            $this->memberLevel, $this->grossCents, $payable, $this->userPoints,
        );
    }

    /**
     * 范围内商品金额占当前应付的比例分摊值。
     * 真实系统中「品类券在部分减免后的可用基数」常见实现就是按比例分摊。
     *
     * @param list<string>|null $categories
     */
    public function scopeShareOfPayable(?array $categories): int
    {
        if ($categories === null) {
            return $this->currentPayableCents;
        }
        $scopeGross = $this->cart->scopeAmount($this->catalog, $this->unitPrices, $categories);
        if ($this->grossCents <= 0) {
            return 0;
        }
        return (int) floor($this->currentPayableCents * $scopeGross / $this->grossCents);
    }

    /**
     * @param list<string>|null $categories
     */
    public function scopeGross(?array $categories): int
    {
        return $this->cart->scopeAmount($this->catalog, $this->unitPrices, $categories);
    }
}
