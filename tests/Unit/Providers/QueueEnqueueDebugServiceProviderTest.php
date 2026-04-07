<?php

use App\Providers\QueueEnqueueDebugServiceProvider;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Log;

uses(Tests\TestCase::class);

afterEach(function () {
    putenv('QUEUE_DEBUG_ENQUEUES');
    putenv('QUEUE_DEBUG_ENQUEUES_MATCH');
    putenv('QUEUE_DEBUG_ENQUEUES_STACK');
    unset($_ENV['QUEUE_DEBUG_ENQUEUES'], $_ENV['QUEUE_DEBUG_ENQUEUES_MATCH'], $_ENV['QUEUE_DEBUG_ENQUEUES_STACK']);
});

test('queue enqueue debug provider does not register listener when debug is disabled', function () {
    putenv('QUEUE_DEBUG_ENQUEUES=false');
    $_ENV['QUEUE_DEBUG_ENQUEUES'] = 'false';

    $dispatcher = app('events');
    $before = count($dispatcher->getListeners(JobQueued::class));

    (new QueueEnqueueDebugServiceProvider(app()))->boot();

    $after = count($dispatcher->getListeners(JobQueued::class));

    expect($after)->toBe($before);
});

test('queue enqueue debug provider uses default matchers when env matcher is empty', function () {
    putenv('QUEUE_DEBUG_ENQUEUES_MATCH=');
    $_ENV['QUEUE_DEBUG_ENQUEUES_MATCH'] = '';

    $provider = new QueueEnqueueDebugServiceProvider(app());
    $matchers = invokePrivate($provider, 'matchers');

    expect($matchers)->toBe([
        \App\Jobs\FetchFireIncidentsJob::class,
        \App\Jobs\FetchPoliceCallsJob::class,
        \App\Jobs\FetchTransitAlertsJob::class,
        \App\Jobs\FetchGoTransitAlertsJob::class,
        \App\Jobs\GenerateDailyDigestJob::class,
        \App\Jobs\FanOutAlertNotificationsJob::class,
        \App\Jobs\DispatchAlertNotificationChunkJob::class,
    ]);
});

test('queue enqueue debug provider matcher list trims values and drops empty entries', function () {
    putenv('QUEUE_DEBUG_ENQUEUES_MATCH=  App\\Jobs\\AJob  , , App\\Jobs\\BJob ,   ');
    $_ENV['QUEUE_DEBUG_ENQUEUES_MATCH'] = '  App\\Jobs\\AJob  , , App\\Jobs\\BJob ,   ';

    $provider = new QueueEnqueueDebugServiceProvider(app());
    $matchers = invokePrivate($provider, 'matchers');

    expect($matchers)->toBe([
        'App\\Jobs\\AJob',
        'App\\Jobs\\BJob',
    ]);
});

test('queue enqueue debug provider matcher supports wildcard exact and partial matching', function () {
    $provider = new QueueEnqueueDebugServiceProvider(app());

    expect(invokePrivate($provider, 'matches', 'App\\Jobs\\SomeJob', ['*']))->toBeTrue();
    expect(invokePrivate($provider, 'matches', 'App\\Jobs\\SomeJob', ['App\\Jobs\\SomeJob']))->toBeTrue();
    expect(invokePrivate($provider, 'matches', 'App\\Jobs\\SomeJob', ['SomeJob']))->toBeTrue();
    expect(invokePrivate($provider, 'matches', '', ['*']))->toBeFalse();
    expect(invokePrivate($provider, 'matches', 'App\\Jobs\\SomeJob', ['AnotherJob']))->toBeFalse();
});

test('queue enqueue debug provider logs warning when payload extraction fails', function () {
    putenv('QUEUE_DEBUG_ENQUEUES=true');
    putenv('QUEUE_DEBUG_ENQUEUES_MATCH=*');
    $_ENV['QUEUE_DEBUG_ENQUEUES'] = 'true';
    $_ENV['QUEUE_DEBUG_ENQUEUES_MATCH'] = '*';

    $logger = \Mockery::mock();
    $logger->shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'Queue enqueue debug: failed to decode payload'
                && $context['connection'] === 'database'
                && $context['queue'] === 'default'
                && $context['job_id'] === 'job-1'
                && is_string($context['error']);
        });
    $logger->shouldReceive('info')->never();

    Log::shouldReceive('channel')
        ->once()
        ->with('queue_enqueues')
        ->andReturn($logger);

    (new QueueEnqueueDebugServiceProvider(app()))->boot();

    app('events')->dispatch(new JobQueued(
        connectionName: 'database',
        queue: 'default',
        id: 'job-1',
        job: new stdClass,
        payload: '{invalid-json',
        delay: null,
    ));
});

test('queue enqueue debug provider toggles stack inclusion and compacts stack frames', function () {
    putenv('QUEUE_DEBUG_ENQUEUES_STACK=true');
    $_ENV['QUEUE_DEBUG_ENQUEUES_STACK'] = 'true';

    $provider = new QueueEnqueueDebugServiceProvider(app());

    expect(invokePrivate($provider, 'includeStack'))->toBeTrue();

    putenv('QUEUE_DEBUG_ENQUEUES_STACK=false');
    $_ENV['QUEUE_DEBUG_ENQUEUES_STACK'] = 'false';

    expect(invokePrivate($provider, 'includeStack'))->toBeFalse();

    $frames = [
        ['file' => '/tmp/one.php', 'line' => 10, 'function' => 'handle', 'class' => 'App\\Jobs\\SomeJob'],
        ['function' => 'missingFileAndLine'],
        ['file' => '/tmp/two.php', 'line' => 20, 'function' => 'run'],
    ];

    $compacted = invokePrivate($provider, 'compactStack', $frames, 18);

    expect($compacted)->toBe([
        [
            'file' => '/tmp/one.php',
            'line' => 10,
            'call' => 'App\\Jobs\\SomeJob::handle',
        ],
        [
            'file' => '/tmp/two.php',
            'line' => 20,
            'call' => 'run',
        ],
    ]);
});

test('queue enqueue debug provider logs info for matching jobs', function () {
    putenv('QUEUE_DEBUG_ENQUEUES=true');
    putenv('QUEUE_DEBUG_ENQUEUES_MATCH=*');
    putenv('QUEUE_DEBUG_ENQUEUES_STACK=false');
    $_ENV['QUEUE_DEBUG_ENQUEUES'] = 'true';
    $_ENV['QUEUE_DEBUG_ENQUEUES_MATCH'] = '*';
    $_ENV['QUEUE_DEBUG_ENQUEUES_STACK'] = 'false';

    $payload = json_encode([
        'displayName' => 'App\\Jobs\\FetchFireIncidentsJob',
        'uuid' => 'test-uuid-123',
        'attempts' => null,
        'maxTries' => 3,
        'timeout' => null,
        'retryUntil' => null,
    ]);

    $logger = \Mockery::mock();
    $logger->shouldReceive('info')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            if ($message !== 'Queue job enqueued') {
                return false;
            }

            // Verify required top-level keys
            if ($context['job_id'] !== 'job-42'
                || $context['display_name'] !== 'App\\Jobs\\FetchFireIncidentsJob'
                || $context['connection'] !== 'database'
                || $context['queue'] !== 'default'
                || $context['delay'] !== null) {
                return false;
            }

            // Verify enqueuer shape
            if (! is_int($context['enqueuer']['pid'])
                || ! is_string($context['enqueuer']['hostname'])) {
                return false;
            }

            // Verify payload_meta excludes null values
            if (array_key_exists('attempts', $context['payload_meta'])
                || array_key_exists('timeout', $context['payload_meta'])
                || array_key_exists('retryUntil', $context['payload_meta'])) {
                return false;
            }

            if ($context['payload_meta']['uuid'] !== 'test-uuid-123'
                || $context['payload_meta']['maxTries'] !== 3) {
                return false;
            }

            // Verify stack is null when stack env is disabled
            if ($context['stack'] !== null) {
                return false;
            }

            return true;
        });
    $logger->shouldNotReceive('warning');

    Log::shouldReceive('channel')
        ->once()
        ->with('queue_enqueues')
        ->andReturn($logger);

    (new QueueEnqueueDebugServiceProvider(app()))->boot();

    app('events')->dispatch(new JobQueued(
        connectionName: 'database',
        queue: 'default',
        id: 'job-42',
        job: new stdClass,
        payload: $payload,
        delay: null,
    ));
});

test('queue enqueue debug provider stack is null when stack toggle is disabled', function () {
    putenv('QUEUE_DEBUG_ENQUEUES=true');
    putenv('QUEUE_DEBUG_ENQUEUES_MATCH=*');
    putenv('QUEUE_DEBUG_ENQUEUES_STACK=false');
    $_ENV['QUEUE_DEBUG_ENQUEUES'] = 'true';
    $_ENV['QUEUE_DEBUG_ENQUEUES_MATCH'] = '*';
    $_ENV['QUEUE_DEBUG_ENQUEUES_STACK'] = 'false';

    $payload = json_encode([
        'displayName' => 'App\\Jobs\\FetchFireIncidentsJob',
    ]);

    $logger = \Mockery::mock();
    $logger->shouldReceive('info')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return $context['stack'] === null;
        });

    Log::shouldReceive('channel')
        ->once()
        ->with('queue_enqueues')
        ->andReturn($logger);

    (new QueueEnqueueDebugServiceProvider(app()))->boot();

    app('events')->dispatch(new JobQueued(
        connectionName: 'database',
        queue: 'default',
        id: 'stack-off',
        job: new stdClass,
        payload: $payload,
        delay: null,
    ));
});

test('queue enqueue debug provider stack is bounded list when stack toggle is enabled', function () {
    putenv('QUEUE_DEBUG_ENQUEUES=true');
    putenv('QUEUE_DEBUG_ENQUEUES_MATCH=*');
    putenv('QUEUE_DEBUG_ENQUEUES_STACK=true');
    $_ENV['QUEUE_DEBUG_ENQUEUES'] = 'true';
    $_ENV['QUEUE_DEBUG_ENQUEUES_MATCH'] = '*';
    $_ENV['QUEUE_DEBUG_ENQUEUES_STACK'] = 'true';

    $payload = json_encode([
        'displayName' => 'App\\Jobs\\FetchFireIncidentsJob',
    ]);

    $logger = \Mockery::mock();
    $logger->shouldReceive('info')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            $stack = $context['stack'];

            // Stack must be a non-empty list
            if (! is_array($stack) || $stack === []) {
                return false;
            }

            // Each frame must have file (string) and line (int)
            foreach ($stack as $frame) {
                if (! is_string($frame['file']) || ! is_int($frame['line'])) {
                    return false;
                }
            }

            // Stack should be bounded (at most 18 frames)
            return count($stack) <= 18;
        });

    Log::shouldReceive('channel')
        ->once()
        ->with('queue_enqueues')
        ->andReturn($logger);

    (new QueueEnqueueDebugServiceProvider(app()))->boot();

    app('events')->dispatch(new JobQueued(
        connectionName: 'database',
        queue: 'default',
        id: 'stack-on',
        job: new stdClass,
        payload: $payload,
        delay: null,
    ));
});

test('queue enqueue debug provider produces no log for non-matching jobs', function () {
    putenv('QUEUE_DEBUG_ENQUEUES=true');
    putenv('QUEUE_DEBUG_ENQUEUES_MATCH=App\\Jobs\\SomeOtherJob');
    $_ENV['QUEUE_DEBUG_ENQUEUES'] = 'true';
    $_ENV['QUEUE_DEBUG_ENQUEUES_MATCH'] = 'App\\Jobs\\SomeOtherJob';

    Log::shouldReceive('channel')->never();

    (new QueueEnqueueDebugServiceProvider(app()))->boot();

    $payload = json_encode([
        'displayName' => 'App\\Jobs\\FetchFireIncidentsJob',
    ]);

    app('events')->dispatch(new JobQueued(
        connectionName: 'database',
        queue: 'default',
        id: 'no-match',
        job: new stdClass,
        payload: $payload,
        delay: null,
    ));
});

test('queue enqueue debug provider matches method skips empty matcher strings', function () {
    $provider = new QueueEnqueueDebugServiceProvider(app());

    // Empty string matcher should be skipped — no match
    expect(invokePrivate($provider, 'matches', 'App\\Jobs\\SomeJob', ['']))->toBeFalse();

    // Empty string mixed with valid matcher — empty is skipped, valid matches
    expect(invokePrivate($provider, 'matches', 'App\\Jobs\\SomeJob', ['', 'SomeJob']))->toBeTrue();
});

test('queue enqueue debug provider payload meta filters null values', function () {
    $provider = new QueueEnqueueDebugServiceProvider(app());

    $meta = invokePrivate($provider, 'payloadMeta', [
        'uuid' => 'abc-123',
        'attempts' => null,
        'maxTries' => 5,
        'timeout' => null,
        'retryUntil' => null,
    ]);

    expect($meta)->toBe([
        'uuid' => 'abc-123',
        'maxTries' => 5,
    ]);
});

test('queue enqueue debug provider compact stack enforces frame limit', function () {
    $provider = new QueueEnqueueDebugServiceProvider(app());

    $frames = [
        ['file' => '/tmp/one.php', 'line' => 10, 'function' => 'a'],
        ['file' => '/tmp/two.php', 'line' => 20, 'function' => 'b'],
    ];

    $compacted = invokePrivate($provider, 'compactStack', $frames, 1);

    expect($compacted)->toHaveCount(1);
    expect($compacted[0]['file'])->toBe('/tmp/one.php');
    expect($compacted[0]['line'])->toBe(10);
    expect($compacted[0]['call'])->toBe('a');
});

function invokePrivate(object $target, string $method, mixed ...$args): mixed
{
    $reflection = new ReflectionMethod($target, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($target, ...$args);
}
