<?php

use App\Providers\QueueExecutionDebugServiceProvider;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;

uses(Tests\TestCase::class);

afterEach(function () {
    putenv('QUEUE_DEBUG_EXECUTION');
    putenv('QUEUE_DEBUG_EXECUTION_MATCH');
    unset($_ENV['QUEUE_DEBUG_EXECUTION'], $_ENV['QUEUE_DEBUG_EXECUTION_MATCH']);
});

test('queue execution debug provider does not register listeners when debug is disabled', function () {
    putenv('QUEUE_DEBUG_EXECUTION=false');
    $_ENV['QUEUE_DEBUG_EXECUTION'] = 'false';

    $dispatcher = app('events');
    $beforeProcessing = count($dispatcher->getListeners(JobProcessing::class));
    $beforeProcessed = count($dispatcher->getListeners(JobProcessed::class));
    $beforeFailed = count($dispatcher->getListeners(JobFailed::class));

    (new QueueExecutionDebugServiceProvider(app()))->boot();

    $afterProcessing = count($dispatcher->getListeners(JobProcessing::class));
    $afterProcessed = count($dispatcher->getListeners(JobProcessed::class));
    $afterFailed = count($dispatcher->getListeners(JobFailed::class));

    expect($afterProcessing)->toBe($beforeProcessing);
    expect($afterProcessed)->toBe($beforeProcessed);
    expect($afterFailed)->toBe($beforeFailed);
});

test('queue execution debug provider uses default matchers when env matcher is empty', function () {
    putenv('QUEUE_DEBUG_EXECUTION_MATCH=');
    $_ENV['QUEUE_DEBUG_EXECUTION_MATCH'] = '';

    $provider = new QueueExecutionDebugServiceProvider(app());
    $matchers = invokeQueueExecutionDebugPrivate($provider, 'matchers');

    expect($matchers)->toBe([
        \App\Jobs\FetchFireIncidentsJob::class,
        \App\Jobs\FetchPoliceCallsJob::class,
        \App\Jobs\FetchTransitAlertsJob::class,
        \App\Jobs\FetchGoTransitAlertsJob::class,
        \App\Jobs\GenerateDailyDigestJob::class,
    ]);
});

test('queue execution debug provider matcher supports wildcard exact and partial matching', function () {
    $provider = new QueueExecutionDebugServiceProvider(app());

    expect(invokeQueueExecutionDebugPrivate($provider, 'matches', 'App\\Jobs\\SomeJob', ['*']))->toBeTrue();
    expect(invokeQueueExecutionDebugPrivate($provider, 'matches', 'App\\Jobs\\SomeJob', ['App\\Jobs\\SomeJob']))->toBeTrue();
    expect(invokeQueueExecutionDebugPrivate($provider, 'matches', 'App\\Jobs\\SomeJob', ['SomeJob']))->toBeTrue();
    expect(invokeQueueExecutionDebugPrivate($provider, 'matches', '', ['*']))->toBeFalse();
    expect(invokeQueueExecutionDebugPrivate($provider, 'matches', 'App\\Jobs\\SomeJob', ['AnotherJob']))->toBeFalse();
});

test('queue execution debug provider logs processing processed and failed events for matching jobs', function () {
    putenv('QUEUE_DEBUG_EXECUTION=true');
    putenv('QUEUE_DEBUG_EXECUTION_MATCH=*');
    $_ENV['QUEUE_DEBUG_EXECUTION'] = 'true';
    $_ENV['QUEUE_DEBUG_EXECUTION_MATCH'] = '*';

    $logger = \Mockery::mock();
    $logger->shouldReceive('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Queue job processing'
            && $context['job_id'] === 'job-1'
            && $context['display_name'] === 'App\\Jobs\\FetchFireIncidentsJob'
            && $context['connection'] === 'database'
            && $context['queue'] === 'default'
            && $context['attempt'] === 2);
    $logger->shouldReceive('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Queue job processed'
            && $context['job_id'] === 'job-1'
            && $context['display_name'] === 'App\\Jobs\\FetchFireIncidentsJob');
    $logger->shouldReceive('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Queue job failed'
            && $context['job_id'] === 'job-1'
            && ($context['exception']['class'] ?? null) === RuntimeException::class
            && ($context['exception']['message'] ?? null) === 'boom');

    Log::shouldReceive('channel')
        ->times(3)
        ->with('queue_execution')
        ->andReturn($logger);

    (new QueueExecutionDebugServiceProvider(app()))->boot();

    $job = mockQueueJob();

    app('events')->dispatch(new JobProcessing('database', $job));
    app('events')->dispatch(new JobProcessed('database', $job));
    app('events')->dispatch(new JobFailed('database', $job, new RuntimeException('boom')));
});

/**
 * @param  array<string, mixed>  $overrides
 */
function mockQueueJob(array $overrides = []): Job
{
    $job = \Mockery::mock(Job::class);

    $values = array_merge([
        'job_id' => 'job-1',
        'uuid' => 'uuid-1',
        'display_name' => 'App\\Jobs\\FetchFireIncidentsJob',
        'queued_job_class' => 'App\\Jobs\\FetchFireIncidentsJob',
        'connection' => 'database',
        'queue' => 'default',
        'attempts' => 2,
    ], $overrides);

    $job->shouldReceive('getJobId')->andReturn($values['job_id']);
    $job->shouldReceive('uuid')->andReturn($values['uuid']);
    $job->shouldReceive('resolveName')->andReturn($values['display_name']);
    $job->shouldReceive('resolveQueuedJobClass')->andReturn($values['queued_job_class']);
    $job->shouldReceive('getConnectionName')->andReturn($values['connection']);
    $job->shouldReceive('getQueue')->andReturn($values['queue']);
    $job->shouldReceive('attempts')->andReturn($values['attempts']);
    $job->shouldReceive('payload')->andReturn([]);

    return $job;
}

function invokeQueueExecutionDebugPrivate(object $target, string $method, mixed ...$args): mixed
{
    $reflection = new ReflectionMethod($target, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($target, ...$args);
}
