<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\AtlasAcosLongHorizonGateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class AtlasAcosLongHorizonGateCommand extends Command
{
    protected $signature = 'atlas:cognition:acos-long-horizon-gate
        {--fixture=live : live, mature or short-window}
        {--series= : Override Fable delta-series JSONL path}
        {--receipt= : Optional path to write receipt JSON}
        {--write-receipt : Write to configured receipt path when --receipt is omitted}
        {--strict : Exit non-zero unless ACOS long-horizon readiness is certified}
        {--json : Print canonical JSON}';

    protected $description = 'L6-9 honest ACOS long-horizon gate: requires score floors plus >=30 days of resolved-evidence delta series.';

    public function handle(AtlasAcosLongHorizonGateService $gate): int
    {
        $series = trim((string) ($this->option('series') ?: ''));
        $payload = $gate->evaluate(array_filter([
            'fixture' => trim((string) $this->option('fixture')),
            'series_path' => $series !== '' ? $series : null,
        ], static fn (mixed $value): bool => $value !== null));

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
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exit;
        }

        $this->components->twoColumnDetail('ACOS long-horizon gate', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Certified', (bool) ($payload['certified'] ?? false) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Overall', (string) data_get($payload, 'assessment.overall_score', 'unknown'));
        $this->components->twoColumnDetail('Pipeline', (string) data_get($payload, 'assessment.pipeline_score', 'unknown'));
        $this->components->twoColumnDetail('Window', (string) data_get($payload, 'assessment.calendar_span_days', 0).' days');

        return $exit;
    }

    private function receiptPath(): string
    {
        $explicit = trim((string) ($this->option('receipt') ?: ''));
        if ($explicit !== '') {
            return $explicit;
        }

        return (bool) $this->option('write-receipt')
            ? (string) config('atlas.cognition.acos_long_horizon_gate.receipt_path', storage_path('app/atlas/evidence/acos-long-horizon-gate.json'))
            : '';
    }
}
