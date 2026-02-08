<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Symfony\Component\Process\Process;

$basePath = dirname(__DIR__, 2);
$timestamp = date('Y_m_d_His');
$logDir = $basePath.'/storage/logs/manual_tests';
$logFile = $logDir.'/typed_domain_refactor_phase5_command_sequence_'.$timestamp.'.log';

if (! is_dir($logDir) && ! mkdir($logDir, 0775, true) && ! is_dir($logDir)) {
    fwrite(STDERR, "Error: Failed to create log directory: {$logDir}\n");
    exit(1);
}

if (! file_exists($logFile) && @touch($logFile) === false) {
    fwrite(STDERR, "Error: Failed to create log file: {$logFile}\n");
    exit(1);
}

@chmod($logFile, 0664);

/**
 * @param  array<string, mixed>  $context
 */
function logLine(string $logFile, string $level, string $message, array $context = []): void
{
    $time = date('Y-m-d H:i:s');
    $suffix = $context === [] ? '' : ' '.json_encode($context, JSON_UNESCAPED_SLASHES);
    $line = "[{$time}] [{$level}] {$message}{$suffix}\n";

    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

/**
 * @param  array{label: string, command: string, allow_failure?: bool}  $step
 * @return array{status: string, exit_code: int|null, duration_seconds: float}
 */
function runStep(string $basePath, string $logFile, array $step): array
{
    $label = $step['label'];
    $command = $step['command'];
    $allowFailure = (bool) ($step['allow_failure'] ?? false);

    logLine($logFile, 'INFO', "Starting step: {$label}", [
        'command' => $command,
        'allow_failure' => $allowFailure,
    ]);

    $startedAt = microtime(true);

    $process = new Process(['bash', '-lc', $command], $basePath);
    $process->setTimeout(null);

    $output = '';
    $process->run(function (string $type, string $buffer) use (&$output, $logFile): void {
        $output .= $buffer;
        echo $buffer;
        file_put_contents($logFile, $buffer, FILE_APPEND);
    });

    $duration = round(microtime(true) - $startedAt, 2);
    $exitCode = $process->getExitCode();

    if ($process->isSuccessful()) {
        logLine($logFile, 'INFO', "Step passed: {$label}", [
            'exit_code' => $exitCode,
            'duration_seconds' => $duration,
        ]);

        return [
            'status' => 'passed',
            'exit_code' => $exitCode,
            'duration_seconds' => $duration,
        ];
    }

    logLine($logFile, $allowFailure ? 'WARN' : 'ERROR', "Step failed: {$label}", [
        'exit_code' => $exitCode,
        'duration_seconds' => $duration,
        'output_tail' => mb_substr($output, -5000),
    ]);

    return [
        'status' => $allowFailure ? 'failed_allowed' : 'failed',
        'exit_code' => $exitCode,
        'duration_seconds' => $duration,
    ];
}

$steps = [
    [
        'label' => 'Sail: backend test suite',
        'command' => 'CI=true ./vendor/bin/sail artisan test',
        'allow_failure' => true,
    ],
    [
        'label' => 'Local: backend test suite',
        'command' => 'php artisan test',
    ],
    [
        'label' => 'Local: pint style check',
        'command' => './vendor/bin/pint --test',
    ],
    [
        'label' => 'Sail: pint style check',
        'command' => './vendor/bin/sail artisan pint --test',
        'allow_failure' => true,
    ],
    [
        'label' => 'Local: frontend lint check',
        'command' => 'pnpm run lint:check',
    ],
    [
        'label' => 'Local: frontend typecheck',
        'command' => 'pnpm run types',
    ],
    [
        'label' => 'Local: frontend build',
        'command' => 'pnpm run build',
    ],
    [
        'label' => 'Local: frontend coverage',
        'command' => 'pnpm exec vitest run --coverage',
    ],
    [
        'label' => 'Local: frontend test suite',
        'command' => 'pnpm test',
    ],
    [
        'label' => 'Sail: frontend build',
        'command' => './vendor/bin/sail pnpm run build',
        'allow_failure' => true,
    ],
];

$exitCode = 0;
$results = [];

logLine($logFile, 'INFO', '=== Starting command sequence runner ===', [
    'base_path' => $basePath,
    'log_file' => $logFile,
]);

foreach ($steps as $index => $step) {
    $stepNumber = $index + 1;
    logLine($logFile, 'INFO', "--- Step {$stepNumber}/".count($steps).' ---');

    $result = runStep($basePath, $logFile, $step);
    $results[] = [
        'label' => $step['label'],
        'result' => $result,
    ];

    if ($result['status'] === 'failed') {
        $exitCode = 1;
        logLine($logFile, 'ERROR', 'Stopping sequence due to required step failure.');
        break;
    }
}

$passed = count(array_filter($results, fn (array $item): bool => $item['result']['status'] === 'passed'));
$failedAllowed = count(array_filter($results, fn (array $item): bool => $item['result']['status'] === 'failed_allowed'));
$failed = count(array_filter($results, fn (array $item): bool => $item['result']['status'] === 'failed'));

logLine($logFile, 'INFO', '=== Sequence summary ===', [
    'steps_run' => count($results),
    'passed' => $passed,
    'failed_allowed' => $failedAllowed,
    'failed' => $failed,
    'exit_code' => $exitCode,
]);

foreach ($results as $item) {
    logLine($logFile, 'INFO', 'Step result', [
        'label' => $item['label'],
        'status' => $item['result']['status'],
        'exit_code' => $item['result']['exit_code'],
        'duration_seconds' => $item['result']['duration_seconds'],
    ]);
}

logLine($logFile, 'INFO', 'Log written', ['path' => $logFile]);

exit($exitCode);
