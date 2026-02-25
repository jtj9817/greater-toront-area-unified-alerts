<?php

function createSailWrapperScript(string $body): string
{
    $path = sys_get_temp_dir().'/sail-wrapper-'.bin2hex(random_bytes(6)).'.sh';

    file_put_contents($path, "#!/usr/bin/env bash\nset -euo pipefail\n{$body}\n");
    @chmod($path, 0755);

    return $path;
}

test('sail wrapper shows usage when no args are provided', function () {
    $this->artisan('sail')
        ->expectsOutputToContain('Usage: php artisan sail')
        ->assertExitCode(0);
});

test('sail wrapper returns error when configured sail script path is missing', function () {
    $missingPath = sys_get_temp_dir().'/missing-sail-'.bin2hex(random_bytes(6)).'.sh';
    config()->set('commands.sail_wrapper.bin', $missingPath);

    $this->artisan('sail', [
        '--args' => ['up'],
    ])
        ->expectsOutputToContain('Sail script not found at:')
        ->assertExitCode(1);
});

test('sail wrapper runs configured script and returns child exit code', function () {
    $scriptPath = createSailWrapperScript(<<<'BASH'
echo "wrapper-stdout:$1:$2"
echo "wrapper-stderr:$1:$2" >&2
exit 17
BASH);

    config()->set('commands.sail_wrapper.bin', $scriptPath);

    try {
        $this->artisan('sail', [
            '--args' => ['artisan', 'about'],
        ])
            ->expectsOutputToContain('Running sail command: sail artisan about')
            ->expectsOutputToContain('wrapper-stdout:artisan:about')
            ->assertExitCode(17);
    } finally {
        @unlink($scriptPath);
    }
});
