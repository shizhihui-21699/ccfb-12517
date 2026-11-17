<?php

declare(strict_types=1);

namespace PromoAudit\Domain;

/**
 * 购物车:productId => 数量。不可变,所有修改返回新实例。
 */
final class Cart
{
    /** @param array<string,int> $lines */
    public function __construct(
        public readonly array $lines,
    ) {
        foreach ($lines as $qty) {
            if ($qty <= 0) {
                throw new \InvalidArgumentException('Cart lines must have positive quantity');
            }
        }
    }

    public function withLine(string $productId, int $qty): self
    {
        $lines = $this->lines;
        if ($qty <= 0) {
            unset($lines[$productId]);
        } else {
            $lines[$productId] = $qty;
        }
        ksort($lines);
        return new self($lines);
    }

    public function totalItems(): int
    {
        return array_sum($this->lines);
    }

    public function distinctSkus(): int
    {
        return count($this->lines);
    }

    /**
     * 按会员价(若有)计算商品总价,并返回每个 SKU 的生效单价。
     *
     * @param array<string,Product> $catalog
     * @param array<string,array<string,int>> $memberPrices memberLevel => [productId => cents]
     * @return array{0:int,1:array<string,int>} [总价(分), 生效单价表]
     */
    public function grossWithUnitPrices(array $catalog, string $memberLevel, array $memberPrices): array
    {
        $total = 0;
        $unitPrices = [];
        foreach ($this->lines as $pid => $qty) {
            $p = $catalog[$pid];
            $unit = $memberPrices[$memberLevel][$pid] ?? $p->priceCents;
            $unitPrices[$pid] = $unit;
            $total += $unit * $qty;
        }
        return [$total, $unitPrices];
    }

    /**
     * 指定类目/全场范围的商品金额(基于生效单价)。
     *
     * @param array<string,Product> $catalog
     * @param array<string,int> $unitPrices
     * @param list<string>|null $categories null 表示全场
     */
    public function scopeAmount(array $catalog, array $unitPrices, ?array $categories): int
    {
        $sum = 0;
        foreach ($this->lines as $pid => $qty) {
            if ($categories !== null && !in_array($catalog[$pid]->category, $categories, true)) {
                continue;
            }
            $sum += $unitPrices[$pid] * $qty;
        }
        return $sum;
    }

    /**
     * 成本合计。
     * @param array<string,Product> $catalog
     */
    public function totalCost(array $catalog): int
    {
        $sum = 0;
        foreach ($this->lines as $pid => $qty) {
            $sum += $catalog[$pid]->costCents * $qty;
        }
        return $sum;
    }

    /** 规范化 key,用于状态去重 */
    public function key(): string
    {
        $parts = [];
        foreach ($this->lines as $pid => $qty) {
            $parts[] = $pid . 'x' . $qty;
        }
        return implode(',', $parts);
    }
}
