<?php

/**
 * 优惠审计配置。生产使用时,products / rules / exclusions 建议改成从数据库加载:
 * 见 laravel/Console/PromoAuditCommand.php 中的 loadFromDatabase() 示例。
 */
return [
    // 商品目录(可分块从 DB 读)
    'products' => [
        // ['id' => 'cola', 'name' => '可乐', 'category' => 'drink', 'price' => 3.00, 'cost' => 2.00],
    ],

    // 会员价:等级 => [商品id => 价格]
    'member_prices' => [
        // 'gold' => ['milk' => 10.00],
    ],

    // 优惠规则。type: full_reduction | discount_coupon | points
    'rules' => [
        // 满减:threshold_base=original 表示门槛看优惠前金额(常见实现,也是叠穿高发配置)
        // ['id' => 'FR1', 'type' => 'full_reduction', 'threshold' => 100, 'reduction' => 25,
        //  'categories' => null, 'threshold_base' => 'original'],
        // 折扣券:rate=0.8 八折,cap 封顶(元),categories=null 全场
        // ['id' => 'DC1', 'type' => 'discount_coupon', 'rate' => 0.8, 'threshold' => 50,
        //  'cap' => 40, 'categories' => null],
        // 积分抵现
        // ['id' => 'PT1', 'type' => 'points', 'points_per_yuan' => 100, 'max_percent' => 0.5],
    ],

    // 互斥对
    'exclusions' => [
        // ['DC1', 'DC2'],
    ],

    // 搜索引擎参数
    'search' => [
        'member_levels' => ['none', 'gold'],
        'max_distinct_skus' => 4,   // 购物车内最多 SKU 种类(剪枝)
        'max_qty_per_sku' => 2,     // 单 SKU 最大数量(剪枝)
        'max_total_items' => 8,     // 购物车内商品总数上限(剪枝)
        'user_points' => 2000,      // 模拟用户积分余额
        'clamp_at_zero' => false,   // 被审引擎是否做零值截断;排查时保持 false 才能看到叠穿
        'enforce_exclusions' => true,
        'audit_split_orders' => true,
        'max_findings' => 50,
    ],
];
