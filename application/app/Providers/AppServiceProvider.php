<?php

namespace App\Providers;

use App\Domain\Support\AdminSiteLabels;
use App\Domain\Support\SchemaAvailability;
use App\Domain\Themes\PublicationProjectionCache;
use App\Domain\Themes\PublicationReadMemo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(PublicationReadMemo::class, fn () => new PublicationReadMemo);
        $this->app->scoped(PublicationProjectionCache::class, fn () => new PublicationProjectionCache);
        $this->app->scoped(AdminSiteLabels::class, fn () => new AdminSiteLabels);
        $this->app->scoped(SchemaAvailability::class);
        if (PHP_SAPI === 'cli') {
            set_time_limit(0); // Queue job limits are enforced by Laravel/pcntl.
        }
        foreach (['APP_KEY' => 'app.key', 'DB_PASSWORD' => 'database.connections.pgsql.password', 'REDIS_PASSWORD' => 'database.redis.default.password'] as $name => $config) {
            $file = env($name.'_FILE');
            if ($file) {
                if (! is_readable($file)) {
                    throw new \RuntimeException('Không đọc được secret file '.$name.'_FILE');
                }
                $this->app['config']->set($config, trim(file_get_contents($file)));
                if ($name === 'REDIS_PASSWORD') {
                    $this->app['config']->set('database.redis.cache.password', trim(file_get_contents($file)));
                }
            }
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer('admin.*', function (\Illuminate\View\View $view): void {
            $labels = app(AdminSiteLabels::class);
            $labels->primeView($view->getData());
            $view->with('siteLabels', $labels);
        });
        Queue::looping(function (): void {
            $role = env('RUNTIME_WORKER_ROLE', 'publish');
            Cache::store('redis')->put('runtime:worker:'.$role.':heartbeat', now()->timestamp, 420);
        });
        Schedule::call(function (): void {
            Cache::store('redis')->put('runtime:scheduler:heartbeat', now()->timestamp, 180);
        })->name('runtime-heartbeat')->everyMinute()->withoutOverlapping(2);
        Schedule::command('sources:schedule')->everyMinute()->withoutOverlapping(2);
        Schedule::command('baidu:dispatch')->everyMinute()->withoutOverlapping(2);
        Schedule::command('tram:outbox')->everyMinute()->withoutOverlapping(2);
        Schedule::command('tram:public-cache --dispatch')->everyMinute()->withoutOverlapping(2);
        Schedule::command('tram:fanmulu-recommendations')->everyMinute()->withoutOverlapping(10)->runInBackground();
        Schedule::command('tram:reconcile')->everyMinute()->withoutOverlapping(2);
        Schedule::command('keywords:dispatch')->everyMinute()->withoutOverlapping(2);
        Schedule::command('campaigns:dispatch')->everyMinute()->withoutOverlapping(2);
        Schedule::command('quick-apply:dispatch')->everyMinute()->withoutOverlapping(2);
        Schedule::command('site-presentation:dispatch')->everyMinute()->withoutOverlapping(2);
        Schedule::command('websites:dispatch')->everyMinute()->withoutOverlapping(2);
        Schedule::command('websites:bulk-edit-dispatch')->everyMinute()->withoutOverlapping(2);
        Schedule::command('websites:deletions-dispatch')->everyMinute()->withoutOverlapping(2);
        Schedule::command('movies:library-dispatch')->everyMinute()->withoutOverlapping(2);
        Schedule::command('catalog:deletions-dispatch')->everyMinute()->withoutOverlapping(2);
    }
}
