<?php

declare(strict_types=1);

namespace PromoAudit\Domain;

/**
 * 商品。金额一律用「分」(int) 表示,避免浮点误差。
 */
final class Product
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $category,
        public readonly int $priceCents,
        public readonly int $costCents,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            id: (string) $row['id'],
            name: (string) $row['name'],
            category: (string) $row['category'],
            priceCents: (int) round($row['price'] * 100),
            costCents: (int) round($row['cost'] * 100),
        );
    }
}
