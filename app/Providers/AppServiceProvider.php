<?php

namespace App\Providers;

use App\Services\Auth\AuthGateway;
use App\Services\Auth\DevAuthGateway;
use App\Services\Auth\McNuttAuthGateway;
use App\Services\Notifications\LogNotificationGateway;
use App\Services\Notifications\McNuttNotificationGateway;
use App\Services\Notifications\NotificationGateway;
use App\Support\Context;
use App\Support\Money;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Context::class);

        $this->app->singleton(AuthGateway::class, function ($app) {
            $driver = config('finance.auth.driver');
            if ($driver === 'dev') {
                if ($app->environment('production')) {
                    throw new RuntimeException('AUTH_DRIVER=dev is not allowed in production.');
                }

                return new DevAuthGateway;
            }

            return new McNuttAuthGateway(config('finance.auth'));
        });

        $this->app->singleton(NotificationGateway::class, function ($app) {
            if (config('finance.notifications.driver') === 'log') {
                if ($app->environment('production')) {
                    throw new RuntimeException('NOTIFICATIONS_DRIVER=log is not allowed in production.');
                }

                return new LogNotificationGateway;
            }

            return new McNuttNotificationGateway(config('finance.notifications'));
        });
    }

    public function boot(): void
    {
        Blade::directive('money', fn ($expr) => "<?php echo e(\\App\\Support\\Money::format($expr)); ?>");

        RateLimiter::for('public', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));
        RateLimiter::for('public-token', fn (Request $r) => Limit::perMinute(20)->by($r->ip()));
        RateLimiter::for('public-write', fn (Request $r) => [Limit::perMinute(10)->by($r->ip()), Limit::perHour(60)->by($r->ip())]);
        RateLimiter::for('sensitive', fn (Request $r) => Limit::perMinute(20)->by(optional($r->session())->getId() ?? $r->ip()));
        RateLimiter::for('auth', fn (Request $r) => Limit::perMinute(20)->by($r->ip()));

        if ($this->app->environment('production')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }
    }
}
