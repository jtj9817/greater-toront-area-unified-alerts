<?php

test('sail wrapper shows usage when no args are provided', function () {
    $this->artisan('sail')
        ->expectsOutputToContain('Usage: php artisan sail')
        ->assertExitCode(0);
});
