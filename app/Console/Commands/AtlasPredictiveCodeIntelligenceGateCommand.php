<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Learning\PredictiveFailure\PredictiveCodeIntelligenceCorrelationGateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasPredictiveCodeIntelligenceGateCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:cognition:predictive-code-intelligence-gate
        {--fixture=live : live, mature, zero-outcomes or stale-code}
        {--domain=learning : Predictive-failure domain}
        {--window=60 : Metrics/history window in days}
        {--auto-refresh : Allow code-intelligence auto-refresh before evaluating}
        {--receipt= : Optional path to write receipt JSON}
        {--write-receipt : Write to configured receipt path when --receipt is omitted}
        {--strict : Exit non-zero unless predictive code intelligence is certified}
        {--json : Print canonical JSON}';

    protected $description = 'L6-11 gate: certify predictive code intelligence only when code-gate and resolved failure outcomes agree.';

    public function handle(PredictiveCodeIntelligenceCorrelationGateService $gate): int
    {
        $payload = $gate->evaluate([
            'fixture' => trim((string) $this->option('fixture')),
            'domain' => trim((string) $this->option('domain')),
            'window_days' => (int) $this->option('window'),
            'auto_refresh' => (bool) $this->option('auto-refresh'),
        ]);

        $receiptPath = $this->receiptPath();
        if ($receiptPath !== '') {
            File::ensureDirectoryExists(dirname($receiptPath));
            File::put($receiptPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            $payload['receipt_path'] = $receiptPath;
        }

        $exit = (bool) $this->option('strict') && ! (bool) ($payload['certified'] ?? false)
            ? self::FAILURE
            : self::SUCCESS;

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return $exit;
        }

        $this->components->twoColumnDetail('Predictive code intelligence', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Certified', (bool) YesNo::format($payload['certified'] ?? false));
        $this->components->twoColumnDetail('Code gate', (string) data_get($payload, 'assessment.code_gate_status', 'unknown'));
        $this->components->twoColumnDetail('Outcomes', (string) data_get($payload, 'assessment.outcomes_recorded', 0));
        $this->components->twoColumnDetail('Failure signatures', (string) data_get($payload, 'assessment.failure_signature_outcomes', 0));

        return $exit;
    }

    private function receiptPath(): string
    {
        $explicit = trim((string) ($this->option('receipt') ?: ''));
        if ($explicit !== '') {
            return $explicit;
        }

        return (bool) $this->option('write-receipt')
            ? (string) config('atlas.cognition.predictive_code_intelligence_gate.receipt_path', storage_path('app/atlas/evidence/predictive-code-intelligence-gate.json'))
            : '';
    }
}
