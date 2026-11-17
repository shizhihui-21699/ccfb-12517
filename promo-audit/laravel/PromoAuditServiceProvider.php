<?php

declare(strict_types=1);

namespace PromoAudit\Laravel;

use Illuminate\Support\ServiceProvider;

/**
 * Laravel 集成:注册 artisan 命令与配置。
 *
 * 安装方式(二选一):
 *  1. 把 src/ 整个目录作为包引入(composer path repository),在 config/app.php
 *     或 bootstrap/providers.php 注册本 Provider;
 *  2. 直接把 src/ 拷进 app/Services/PromoAudit,把 laravel/ 下的两个文件
 *     拷进 app/Providers 与 app/Console/Commands 并改命名空间。
 */
final class PromoAuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/promo_audit.php', 'promo_audit');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/promo_audit.php' => config_path('promo_audit.php'),
        ], 'promo-audit-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\PromoAuditCommand::class,
            ]);
        }
    }
}
