<?php

namespace App\Console\Commands;

use App\Services\Ai\Mobile\SelfDiagnosticEmitter;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasSelfDiagnosticCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:self-diagnostic {--dry-run : Calcula metricas sem criar inbox item}';

    protected $description = 'Avalia desempenho recente do Atlas e emite self_diagnostic quando ha regressao confirmada.';

    public function handle(SelfDiagnosticEmitter $diagnostics): int
    {
        $result = $diagnostics->run((bool) $this->option('dry-run'));

        $this->line($this->encode($result));

        return self::SUCCESS;
    }
}
