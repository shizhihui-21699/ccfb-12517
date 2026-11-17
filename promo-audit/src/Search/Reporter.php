<?php

declare(strict_types=1);

namespace PromoAudit\Search;

use PromoAudit\Domain\Product;

/**
 * 把发现渲染成控制台报告 / JSON(含可复现下单步骤)。
 */
final class Reporter
{
    /** @param array<string,Product> $catalog */
    public function __construct(private readonly array $catalog) {}

    /** @param list<Finding> $findings */
    public function toConsole(array $findings, int $evaluations, int $carts): string
    {
        $out = [];
        $out[] = str_repeat('=', 72);
        $out[] = sprintf('优惠叠加审计报告 | 枚举购物车 %d 个 | 规则求值 %d 次 | 发现 %d 类问题',
            $carts, $evaluations, count($findings));
        $out[] = str_repeat('=', 72);

        foreach ($findings as $i => $f) {
            $out[] = '';
            $out[] = sprintf('[%d] %s  合计实付 %s 元 / 成本 %s 元%s',
                $i + 1,
                $f->typeLabel(),
                number_format($f->totalFinalCents / 100, 2),
                number_format($f->totalCostCents / 100, 2),
                $f->legalSingleBestCents !== null
                    ? sprintf(' / 单订单最优 %s 元', number_format($f->legalSingleBestCents / 100, 2))
                    : ''
            );
            $out[] = str_repeat('-', 72);
            foreach ($f->notes as $n) {
                $out[] = '  ! ' . $n;
            }
            $out[] = '  复现步骤:';
            foreach ($this->reproLines($f) as $line) {
                $out[] = '    ' . $line;
            }
        }
        return implode(PHP_EOL, $out) . PHP_EOL;
    }

    /** @param list<Finding> $findings */
    public function toJson(array $findings, int $evaluations, int $carts): string
    {
        $data = [
            'summary' => ['carts' => $carts, 'evaluations' => $evaluations, 'findings' => count($findings)],
            'findings' => array_map(function (Finding $f) {
                return [
                    'type' => $f->type,
                    'type_label' => $f->typeLabel(),
                    'severity' => $f->severity(),
                    'total_final' => $f->totalFinalCents / 100,
                    'total_cost' => $f->totalCostCents / 100,
                    'legal_single_best' => $f->legalSingleBestCents === null ? null : $f->legalSingleBestCents / 100,
                    'notes' => $f->notes,
                    'repro' => $this->reproLines($f),
                    'orders' => array_map(function (array $o) {
                        return [
                            'member_level' => $o['member_level'],
                            'items' => $o['cart']->lines,
                            'sequence' => $o['sequence'],
                            'gross' => $o['result']->grossCents / 100,
                            'final' => $o['result']->finalCents / 100,
                            'steps' => array_map(fn (array $s) => [
                                'rule' => $s['rule'],
                                'desc' => $s['desc'],
                                'off' => $s['off'] / 100,
                                'payable_after' => $s['after'] / 100,
                            ], $o['result']->steps),
                        ];
                    }, $f->orders),
                ];
            }, $findings),
        ];
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    /** @return list<string> */
    private function reproLines(Finding $f): array
    {
        $lines = [];
        $multi = count($f->orders) > 1;
        foreach ($f->orders as $idx => $o) {
            $prefix = $multi ? sprintf('订单%d: ', $idx + 1) : '';
            if ($o['member_level'] !== 'none') {
                $lines[] = sprintf('%s使用「%s」会员账号', $prefix, $o['member_level']);
            }
            $items = [];
            foreach ($o['cart']->lines as $pid => $qty) {
                $items[] = sprintf('%s(%s)x%d', $this->catalog[$pid]->name, $pid, $qty);
            }
            $lines[] = sprintf('%s加购: %s', $prefix, implode(', ', $items));
            if ($o['sequence'] !== []) {
                $lines[] = sprintf('%s依次使用: %s', $prefix, implode(' → ', $o['sequence']));
            }
            $lines[] = sprintf('%s应付 %s 元', $prefix, number_format($o['result']->finalCents / 100, 2));
        }
        if ($multi) {
            $lines[] = '两单合并结算(同一用户、同一收货地址),合计实付 '
                . number_format($f->totalFinalCents / 100, 2) . ' 元';
        }
        return $lines;
    }
}
