<?php

declare(strict_types=1);

namespace PromoAudit\Search;

final class SearchConfig
{
    /**
     * @param list<string> $memberLevels 要枚举的会员等级
     * @param int $maxDistinctSkus 购物车内最多不同 SKU 数(剪枝)
     * @param int $maxQtyPerSku 单 SKU 最大数量(剪枝)
     * @param int $maxTotalItems 购物车内商品总数上限(剪枝)
     * @param int $userPoints 用户积分余额
     * @param bool $clampAtZero 引擎是否把应付截断到 0(模拟线上是否修了该 bug)
     * @param bool $enforceExclusions 引擎是否在服务端校验互斥
     * @param bool $auditSplitOrders 是否启用拆单搜索
     * @param int $maxFindings 报告最多保留多少条发现
     */
    public function __construct(
        public readonly array $memberLevels = ['none', 'gold'],
        public readonly int $maxDistinctSkus = 4,
        public readonly int $maxQtyPerSku = 3,
        public readonly int $maxTotalItems = 10,
        public readonly int $userPoints = 20000,
        public readonly bool $clampAtZero = false,
        public readonly bool $enforceExclusions = true,
        public readonly bool $auditSplitOrders = true,
        public readonly int $maxFindings = 50,
    ) {}

    public static function fromArray(array $c): self
    {
        return new self(
            memberLevels: $c['member_levels'] ?? ['none', 'gold'],
            maxDistinctSkus: $c['max_distinct_skus'] ?? 4,
            maxQtyPerSku: $c['max_qty_per_sku'] ?? 3,
            maxTotalItems: $c['max_total_items'] ?? 10,
            userPoints: $c['user_points'] ?? 0,
            clampAtZero: $c['clamp_at_zero'] ?? false,
            enforceExclusions: $c['enforce_exclusions'] ?? true,
            auditSplitOrders: $c['audit_split_orders'] ?? true,
            maxFindings: $c['max_findings'] ?? 50,
        );
    }
}
