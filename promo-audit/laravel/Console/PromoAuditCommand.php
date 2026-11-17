<?php

declare(strict_types=1);

namespace PromoAudit\Laravel\Console;

use Illuminate\Console\Command;
use PromoAudit\AuditLoader;
use PromoAudit\Search\Explorer;
use PromoAudit\Search\Reporter;

/**
 * php artisan promo:audit [--json=storage/app/promo_audit.json] [--no-split]
 *
 * 有发现时退出码为 1,可直接接 CI / 定时任务告警。
 */
final class PromoAuditCommand extends Command
{
    protected $signature = 'promo:audit
                            {--json= : 把 JSON 报告写到指定路径}
                            {--no-split : 关闭拆单搜索}';

    protected $description = '穷举商品组合、券顺序与拆单方式,排查优惠叠加漏洞(零付/负付/低于成本/绕过互斥)';

    public function handle(): int
    {
        $data = config('promo_audit');

        // 生产环境建议从 DB 加载,替换 config 中的静态数组:
        // $data['products']   = DB::table('products')->select('id','name','category','price','cost')->get()->map(fn($r)=>(array)$r)->all();
        // $data['rules']      = DB::table('promotion_rules')->where('enabled', true)->get()->map(fn($r)=>json_decode($r->payload, true))->all();
        // $data['exclusions'] = DB::table('promotion_exclusions')->get()->map(fn($r)=>[$r->rule_a, $r->rule_b])->all();

        $loaded = AuditLoader::fromArray($data);
        $search = $loaded['search'];
        if ($this->option('no-split')) {
            $search = new \PromoAudit\Search\SearchConfig(
                memberLevels: $search->memberLevels,
                maxDistinctSkus: $search->maxDistinctSkus,
                maxQtyPerSku: $search->maxQtyPerSku,
                maxTotalItems: $search->maxTotalItems,
                userPoints: $search->userPoints,
                clampAtZero: $search->clampAtZero,
                enforceExclusions: $search->enforceExclusions,
                auditSplitOrders: false,
                maxFindings: $search->maxFindings,
            );
        }

        $start = microtime(true);
        $explorer = new Explorer(
            $loaded['catalog'],
            $loaded['rules'],
            $loaded['member_prices'],
            $loaded['exclusions'],
            $search,
        );
        $result = $explorer->run();
        $elapsed = microtime(true) - $start;

        $reporter = new Reporter($loaded['catalog']);
        $this->line($reporter->toConsole($result['findings'], $result['evaluations'], $result['carts']));
        $this->info(sprintf('耗时 %.2f s', $elapsed));

        if ($path = $this->option('json')) {
            file_put_contents($path, $reporter->toJson($result['findings'], $result['evaluations'], $result['carts']));
            $this->info("JSON 报告已写入 {$path}");
        }

        return $result['findings'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
