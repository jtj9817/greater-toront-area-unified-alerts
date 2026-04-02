<?php

use App\Providers\AppServiceProvider;
use App\Services\Alerts\Providers\YrtAlertSelectProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rules\Password;

uses(Tests\TestCase::class);

afterEach(function () {
    CarbonImmutable::setTestNow();

    // Restore safe defaults for the rest of the test suite.
    $this->app->detectEnvironment(fn () => 'testing');
    config(['app.env' => 'testing']);
    Password::defaults(fn (): ?Password => null);
});

test('app service provider configures CarbonImmutable dates', function () {
    (new AppServiceProvider($this->app))->boot();

    expect(Date::now())->toBeInstanceOf(CarbonImmutable::class);
});

test('app service provider uses 8-char password default outside production', function () {
    (new AppServiceProvider($this->app))->boot();

    $rule = Password::defaults();

    $reflection = new ReflectionClass($rule);
    $min = $reflection->getProperty('min');
    $min->setAccessible(true);

    expect($min->getValue($rule))->toBe(8);
});

test('app service provider uses 12-char password default in production', function () {
    $this->app->detectEnvironment(fn () => 'production');
    config(['app.env' => 'production']);

    (new AppServiceProvider($this->app))->boot();

    $rule = Password::defaults();

    $reflection = new ReflectionClass($rule);
    $min = $reflection->getProperty('min');
    $min->setAccessible(true);

    expect($min->getValue($rule))->toBe(12);
});

test('app service provider tags yrt select provider for unified alerts', function () {
    $providers = collect(app()->tagged('alerts.select-providers'))
        ->map(fn (object $provider) => $provider::class)
        ->all();

    expect($providers)->toContain(YrtAlertSelectProvider::class);
});
