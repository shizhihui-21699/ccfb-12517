<?php

declare(strict_types=1);

namespace PromoAudit;

use PromoAudit\Domain\ExclusionMatrix;
use PromoAudit\Domain\Product;
use PromoAudit\Domain\PromotionRule;
use PromoAudit\Domain\Rules\DiscountCoupon;
use PromoAudit\Domain\Rules\FullReduction;
use PromoAudit\Domain\Rules\PointsDeduction;
use PromoAudit\Search\SearchConfig;

/**
 * 从数组/JSON 加载商品、规则、互斥配置与搜索配置。
 * Laravel 下可直接传 config('promo_audit')。
 */
final class AuditLoader
{
    /**
     * @return array{
     *   catalog: array<string,Product>,
     *   rules: array<string,PromotionRule>,
     *   member_prices: array<string,array<string,int>>,
     *   exclusions: ExclusionMatrix,
     *   search: SearchConfig,
     * }
     */
    public static function fromArray(array $data): array
    {
        $catalog = [];
        foreach ($data['products'] ?? [] as $row) {
            $p = Product::fromArray($row);
            $catalog[$p->id] = $p;
        }

        $rules = [];
        foreach ($data['rules'] ?? [] as $row) {
            $rule = self::buildRule($row);
            $rules[$rule->id()] = $rule;
        }

        $memberPrices = [];
        foreach ($data['member_prices'] ?? [] as $level => $map) {
            foreach ($map as $pid => $yuan) {
                $memberPrices[$level][$pid] = (int) round($yuan * 100);
            }
        }

        $pairs = [];
        foreach ($data['exclusions'] ?? [] as $pair) {
            $pairs[] = [(string) $pair[0], (string) $pair[1]];
        }

        return [
            'catalog' => $catalog,
            'rules' => $rules,
            'member_prices' => $memberPrices,
            'exclusions' => new ExclusionMatrix($pairs),
            'search' => SearchConfig::fromArray($data['search'] ?? []),
        ];
    }

    public static function fromJsonFile(string $path): array
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException("无法读取规则文件: {$path}");
        }
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return self::fromArray($data);
    }

    private static function buildRule(array $row): PromotionRule
    {
        return match ($row['type']) {
            'full_reduction' => FullReduction::fromArray($row),
            'discount_coupon' => DiscountCoupon::fromArray($row),
            'points' => PointsDeduction::fromArray($row),
            default => throw new \InvalidArgumentException("未知规则类型: {$row['type']}"),
        };
    }
}
