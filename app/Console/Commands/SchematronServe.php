<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Runs the KoSIT validator as the schematron HTTP sidecar (layer 2
 * validation). Blocks the terminal; stop with Ctrl+C.
 */
class SchematronServe extends Command
{
    protected $signature = 'schematron:serve {--port=8081}';

    protected $description = 'Spustí KoSIT schematron validátor ako HTTP sidecar (EN 16931 + Peppol)';

    public function handle(): int
    {
        $jar = base_path('tools/schematron/validator.jar');
        if (!is_file($jar)) {
            $this->error('Chýba tools/schematron/validator.jar — stiahnite KoSIT validator:');
            $this->line('https://github.com/itplr-kosit/validator/releases (validator-*-standalone.jar)');

            return self::FAILURE;
        }

        $port = (string) $this->option('port');
        $this->info("Schematron sidecar štartuje na http://localhost:{$port} …");

        $process = new Process([
            config('schematron.java', 'java'),
            '-jar', $jar,
            '-s', base_path('tools/schematron/scenarios.xml'),
            '-r', base_path(),
            '-D', '-G',
            '-P', $port,
        ], base_path(), timeout: null);

        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        return $process->getExitCode() ?? self::FAILURE;
    }
}
