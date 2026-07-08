<?php

namespace App\Providers;

use App\Services\OpenStreetRouteClient;
use App\Services\OpenStreetTspClient;
use App\Services\PolylineDecoder;
use App\Services\TravelTimeService;
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
                mode: $config['mode'],
            );
        });

        $this->app->singleton(OpenStreetRouteClient::class, function ($app): OpenStreetRouteClient {
            $config = $app['config']->get('services.openstreet');

            return new OpenStreetRouteClient(
                http: $app->make(HttpFactory::class),
                decoder: $app->make(PolylineDecoder::class),
                baseUrl: $config['route_url'],
                apiKey: $config['key'],
                mode: $config['mode'],
                timeout: (int) $config['route_timeout'],
                precision: (int) $config['route_precision'],
                connectTimeout: (int) $config['route_connect_timeout'],
            );
        });

        $this->app->singleton(TravelTimeService::class, function ($app): TravelTimeService {
            return new TravelTimeService(
                http: $app->make(HttpFactory::class),
                client: $app->make(OpenStreetRouteClient::class),
                poolCap: (int) $app['config']->get('services.openstreet.route_pool_cap'),
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
        RateLimiter::for('tour-optimize', static fn (Request $request): Limit => Limit::perMinute(10)
            ->by((string) ($request->user()?->id ?: $request->ip())));

        // Synchronous tour reads (geometry tracing 002, available drivers 006): fast
        // calls fired once per optimized tour — a separate, higher quota than the
        // optimization limiter, shared by these lightweight reads.
        RateLimiter::for('tour-read', static fn (Request $request): Limit => Limit::perMinute(30)
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
