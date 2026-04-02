<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Alerts\Providers\FireAlertSelectProvider;
use App\Services\Alerts\Providers\GoTransitAlertSelectProvider;
use App\Services\Alerts\Providers\MiwayAlertSelectProvider;
use App\Services\Alerts\Providers\PoliceAlertSelectProvider;
use App\Services\Alerts\Providers\TransitAlertSelectProvider;
use App\Services\Alerts\Providers\YrtAlertSelectProvider;
use App\Services\Weather\WeatherCacheService;
use App\Services\Weather\WeatherFetchService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->tag([
            FireAlertSelectProvider::class,
            PoliceAlertSelectProvider::class,
            TransitAlertSelectProvider::class,
            GoTransitAlertSelectProvider::class,
            MiwayAlertSelectProvider::class,
            YrtAlertSelectProvider::class,
        ], 'alerts.select-providers');

        $this->app->singleton(WeatherFetchService::class, function ($app) {
            $providers = array_map(
                fn (string $class) => $app->make($class),
                config('weather.providers', []),
            );

            return new WeatherFetchService($providers);
        });

        $this->app->singleton(WeatherCacheService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureSceneIntelAuthorization();
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : Password::min(8)
                ->mixedCase()
                ->letters()
                ->numbers()
        );
    }

    protected function configureSceneIntelAuthorization(): void
    {
        Gate::define('scene-intel.create-manual-entry', function (User $user): bool {
            if ($user->email_verified_at === null) {
                return false;
            }

            $allowedEmails = config('scene_intel.manual_entry.allowed_emails', []);

            if (! is_array($allowedEmails) || $allowedEmails === []) {
                return false;
            }

            return in_array(
                strtolower((string) $user->email),
                $allowedEmails,
                true,
            );
        });
    }
}
