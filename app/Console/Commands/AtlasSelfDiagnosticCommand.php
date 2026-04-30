<?php

namespace App\Console\Commands;

use App\Services\Ai\Mobile\SelfDiagnosticEmitter;
use Illuminate\Console\Command;

class AtlasSelfDiagnosticCommand extends Command
{
    protected $signature = 'atlas:self-diagnostic {--dry-run : Calcula metricas sem criar inbox item}';

    protected $description = 'Avalia desempenho recente do Atlas e emite self_diagnostic quando ha regressao confirmada.';

    public function handle(SelfDiagnosticEmitter $diagnostics): int
    {
        $result = $diagnostics->run((bool) $this->option('dry-run'));

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
