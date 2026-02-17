<?php

use App\Services\TorontoFireFeedService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Command\Command;

test('scheduled fetch commands use short overlap mutex expiry', function () {
    $schedule = app(Schedule::class);

    $expected = [
        'fire:fetch-incidents' => 10,
        'police:fetch-calls' => 10,
        'transit:fetch-alerts' => 10,
        'go-transit:fetch-alerts' => 10,
    ];

    foreach ($expected as $commandSubstring => $expiresAtMinutes) {
        $event = collect($schedule->events())->first(function ($event) use ($commandSubstring) {
            return is_string($event->command) && str_contains($event->command, $commandSubstring);
        });

        expect($event)->not->toBeNull();
        expect($event->withoutOverlapping)->toBeTrue();
        expect($event->expiresAt)->toBe($expiresAtMinutes);
    }
});

test('scheduled callback mutex is released even when the callback throws', function () {
    config(['cache.default' => 'array']);
    Cache::flush();

    $schedule = app(Schedule::class);

    $event = $schedule->call(function (): void {
        throw new RuntimeException('boom');
    })
        ->name('test:mutex-release')
        ->withoutOverlapping(1);

    expect(fn () => $event->run(app()))->toThrow(RuntimeException::class);
    expect(fn () => $event->run(app()))->toThrow(RuntimeException::class);
});

test('queue depth monitor logs error when threshold exceeded', function () {
    Log::spy();

    Schema::dropIfExists('jobs');
    Schema::create('jobs', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    $now = time();
    $rows = [];

    for ($i = 0; $i < 101; $i++) {
        $rows[] = [
            'queue' => 'default',
            'payload' => json_encode(['test' => true]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $now,
            'created_at' => $now,
        ];
    }

    DB::table('jobs')->insert($rows);

    $schedule = app(Schedule::class);
    $event = collect($schedule->events())->first(function ($event) {
        return is_string($event->description) && $event->description === 'monitor:queue-depth';
    });

    expect($event)->not->toBeNull();

    $event->run(app());

    Log::shouldHaveReceived('error')
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'Queue depth exceeded threshold'
                && ($context['depth'] ?? null) === 101
                && ($context['threshold'] ?? null) === 100;
        })
        ->once();
});

test('fire fetch command returns failure and logs when database is unavailable', function () {
    Log::spy();

    $service = Mockery::mock(TorontoFireFeedService::class);
    $service->shouldReceive('fetch')->once()->andReturn([
        'updated_at' => '2026-02-03 12:00:00',
        'events' => [],
    ]);
    app()->instance(TorontoFireFeedService::class, $service);

    $originalSqliteDatabase = config('database.connections.sqlite.database');
    config(['database.connections.sqlite.database' => '/__invalid__/db.sqlite']);
    DB::purge('sqlite');

    $exitCode = Artisan::call('fire:fetch-incidents');

    config(['database.connections.sqlite.database' => $originalSqliteDatabase]);
    DB::purge('sqlite');

    expect($exitCode)->toBe(Command::FAILURE);

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => $message === 'FetchFireIncidentsCommand failed' && isset($context['exception']))
        ->atLeast()
        ->once();
});
