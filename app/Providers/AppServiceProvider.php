<?php

namespace App\Providers;

use App\Services\OpenStreetTspClient;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(OpenStreetTspClient::class, function ($app): OpenStreetTspClient {
            $config = $app['config']->get('services.openstreet');

            return new OpenStreetTspClient(
                http: $app->make(HttpFactory::class),
                baseUrl: $config['url'],
                apiKey: $config['key'],
                timeout: (int) $config['timeout'],
                retries: (int) $config['retries'],
                connectTimeout: (int) $config['connect_timeout'],
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    /**
     * 10 optimization requests per minute per authenticated user.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('route-optimize', static fn (Request $request): Limit => Limit::perMinute(10)
            ->by((string) ($request->user()?->id ?: $request->ip())));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
