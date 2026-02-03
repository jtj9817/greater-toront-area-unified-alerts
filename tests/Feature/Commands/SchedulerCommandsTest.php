<?php

use App\Console\Commands\SchedulerReportCommand;
use App\Console\Commands\SchedulerRunAndLogCommand;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('scheduler:run-and-log writes heartbeat on success', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-02-03 12:00:00'));
    config(['cache.default' => 'array']);
    Cache::flush();

    Artisan::shouldReceive('call')
        ->once()
        ->with('schedule:run', ['--no-interaction' => true])
        ->andReturn(0);
    Artisan::shouldReceive('output')
        ->once()
        ->andReturn("\e[32mOK\e[0m\nRan 1 scheduled task\n");

    Log::spy();

    $exitCode = app(SchedulerRunAndLogCommand::class)->handle();

    expect($exitCode)->toBe(0);
    expect(Cache::get(SchedulerRunAndLogCommand::LAST_TICK_AT_KEY))->toBe(CarbonImmutable::now()->timestamp);
    expect(Cache::get(SchedulerRunAndLogCommand::LAST_TICK_EXIT_CODE_KEY))->toBe(0);
    expect(Cache::get(SchedulerRunAndLogCommand::LAST_TICK_DURATION_MS_KEY))->toBeInt();
});

test('scheduler:run-and-log writes failure heartbeat when schedule:run throws', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-02-03 12:00:00'));
    config(['cache.default' => 'array']);
    Cache::flush();

    Artisan::shouldReceive('call')
        ->once()
        ->with('schedule:run', ['--no-interaction' => true])
        ->andThrow(new RuntimeException('boom'));

    Log::spy();

    $exitCode = app(SchedulerRunAndLogCommand::class)->handle();

    expect($exitCode)->toBe(1);
    expect(Cache::get(SchedulerRunAndLogCommand::LAST_TICK_AT_KEY))->toBe(CarbonImmutable::now()->timestamp);
    expect(Cache::get(SchedulerRunAndLogCommand::LAST_TICK_EXIT_CODE_KEY))->toBe(1);
    expect(Cache::get(SchedulerRunAndLogCommand::LAST_TICK_DURATION_MS_KEY))->toBeInt();
});

test('scheduler:status fails when heartbeat is missing', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-02-03 12:00:00'));
    config(['cache.default' => 'array']);
    Cache::flush();

    $this->artisan('scheduler:status')
        ->expectsOutput('Scheduler heartbeat missing')
        ->assertExitCode(1);
});

test('scheduler:status fails when heartbeat is stale', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-02-03 12:00:00'));
    config(['cache.default' => 'array']);
    Cache::flush();

    Cache::put(SchedulerRunAndLogCommand::LAST_TICK_AT_KEY, CarbonImmutable::now()->subMinutes(10)->timestamp, now()->addDay());
    Cache::put(SchedulerRunAndLogCommand::LAST_TICK_EXIT_CODE_KEY, 0, now()->addDay());
    Cache::put(SchedulerRunAndLogCommand::LAST_TICK_DURATION_MS_KEY, 123, now()->addDay());

    $this->artisan('scheduler:status --max-age=5')
        ->expectsOutputToContain('Scheduler heartbeat stale')
        ->assertExitCode(1);
});

test('scheduler:status succeeds when heartbeat is fresh', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-02-03 12:00:00'));
    config(['cache.default' => 'array']);
    Cache::flush();

    Cache::put(SchedulerRunAndLogCommand::LAST_TICK_AT_KEY, CarbonImmutable::now()->subMinutes(2)->timestamp, now()->addDay());
    Cache::put(SchedulerRunAndLogCommand::LAST_TICK_EXIT_CODE_KEY, 0, now()->addDay());
    Cache::put(SchedulerRunAndLogCommand::LAST_TICK_DURATION_MS_KEY, 123, now()->addDay());

    $this->artisan('scheduler:status --max-age=5')
        ->expectsOutputToContain('Scheduler OK')
        ->assertExitCode(0);
});

test('scheduler:report logs schedule output lines', function () {
    Artisan::partialMock()
        ->shouldReceive('call')
        ->once()
        ->with('schedule:list', ['--no-interaction' => true])
        ->andReturn(0);

    Artisan::shouldReceive('output')
        ->once()
        ->andReturn("\e[31mline one\e[0m\n\nline two\n");

    Log::spy();

    $command = app(SchedulerReportCommand::class);
    $command->setLaravel(app());

    $exitCode = $command->run(new ArrayInput(['--startup' => true]), new BufferedOutput);

    expect($exitCode)->toBe(0);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Scheduler schedule:list' && $context['line'] === 'line one')
        ->once();

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Scheduler schedule:list' && $context['line'] === 'line two')
        ->once();
});

test('scheduler:report returns failure when schedule:list throws', function () {
    Artisan::partialMock()
        ->shouldReceive('call')
        ->once()
        ->with('schedule:list', ['--no-interaction' => true])
        ->andThrow(new RuntimeException('boom'));

    Log::spy();

    $command = app(SchedulerReportCommand::class);
    $command->setLaravel(app());

    $exitCode = $command->run(new ArrayInput([]), new BufferedOutput);

    expect($exitCode)->toBe(1);
});
