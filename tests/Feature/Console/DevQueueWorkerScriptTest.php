<?php

use Symfony\Component\Process\Process;

test('composer dev scripts use the dev queue worker wrapper', function () {
    $composer = json_decode(file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['dev'][1])->toContain('./scripts/dev-queue-worker.sh');
    expect($composer['scripts']['dev:ssr'][2])->toContain('./scripts/dev-queue-worker.sh');
});

test('dev queue worker wrapper restarts clean worker exits and surfaces failures', function () {
    $tempDir = sys_get_temp_dir().'/dev-queue-worker-'.bin2hex(random_bytes(8));

    mkdir($tempDir, 0777, true);

    $fakeSailPath = $tempDir.'/fake-sail.sh';
    $stateFile = $tempDir.'/runs.txt';

    try {
        file_put_contents($fakeSailPath, <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

count=0

if [[ -f "$STATE_FILE" ]]; then
    count=$(cat "$STATE_FILE")
fi

count=$((count + 1))
printf '%s' "$count" > "$STATE_FILE"

if [[ "$count" -lt 3 ]]; then
    exit 0
fi

exit 7
BASH);
        chmod($fakeSailPath, 0755);

        $process = new Process(
            ['bash', base_path('scripts/dev-queue-worker.sh')],
            base_path(),
            [
                'PATH' => (string) getenv('PATH'),
                'QUEUE_WORKER_SAIL_BIN' => $fakeSailPath,
                'QUEUE_WORKER_RESTART_DELAY_SECONDS' => '0',
                'STATE_FILE' => $stateFile,
            ],
        );

        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();

        expect($process->getExitCode())->toBe(7);
        expect(trim(file_get_contents($stateFile)))->toBe('3');
        expect($output)->toContain('[dev-queue-worker] starting queue worker run=1');
        expect($output)->toContain('[dev-queue-worker] queue worker exited cleanly with code 0; restarting after 0s');
        expect($output)->toContain('[dev-queue-worker] starting queue worker run=3');
        expect($output)->toContain('[dev-queue-worker] queue worker exited with code 7; not restarting');
    } finally {
        if (file_exists($fakeSailPath)) {
            unlink($fakeSailPath);
        }

        if (file_exists($stateFile)) {
            unlink($stateFile);
        }

        if (is_dir($tempDir)) {
            rmdir($tempDir);
        }
    }
});
