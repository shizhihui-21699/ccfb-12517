<?php

declare(strict_types=1);

namespace PromoAudit\Domain;

/**
 * 一条优惠规则。引擎对规则的唯一要求:给定购物车和「当前应付」,算出能减多少分。
 *
 * 规则的「门槛基数」是漏洞高发区:
 *  - original: 门槛看优惠前的商品金额(很多系统的实现,叠减后仍满足门槛 → 容易叠穿)
 *  - current:  门槛看当前应付(较安全)
 */
interface PromotionRule
{
    public function id(): string;

    public function type(): string;

    /** 人类可读描述,用于报告 */
    public function describe(): string;

    /** 该券是否计入「单张限用一次」的券预算(满减/会员价这类自动规则返回 false) */
    public function consumesCouponBudget(): bool;

    /**
     * @param RuleContext $ctx 当前计价上下文
     */
    public function isApplicable(RuleContext $ctx): bool;

    /** 返回可减免的分值(>=0)。引擎负责做负值截断(如果配置了截断)。 */
    public function discount(RuleContext $ctx): int;

    /**
     * 乐观上界:忽略一切相互作用,这条规则最多能减多少分。用于剪枝。
     */
    public function maxPossibleDiscount(RuleContext $ctx): int;
}
