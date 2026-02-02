<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class SailWrapper extends Command
{
    protected $signature = 'sail {--args=*}';
    protected $description = 'Wrapper for Laravel Sail commands (bypasses repeated permission prompts)';

    public function handle()
    {
        $args = $this->option('args');

        if (empty($args)) {
            $this->info('Usage: php artisan sail --args="up"');
            $this->info('Or: php artisan sail --args=artisan --args=queue:work');
            return 0;
        }

        $sailPath = base_path('sail');

        if (!file_exists($sailPath)) {
            $this->error('Sail script not found at: ' . $sailPath);
            return 1;
        }

        $this->info('Running sail command: sail ' . implode(' ', $args));

        $process = new Process(['bash', $sailPath, ...$args], base_path());
        if (Process::isTtySupported()) {
            $process->setTty(true);
        }
        $process->setTimeout(null);

        $process->run(function ($type, $output) {
            if (Process::OUT === $type) {
                $this->output->write($output);
            } else {
                $this->output->write('<fg=red>' . $output . '</>');
            }
        });

        return $process->getExitCode();
    }
}
