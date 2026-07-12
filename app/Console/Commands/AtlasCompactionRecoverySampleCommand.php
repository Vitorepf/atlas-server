<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\LongHorizon\CompactionRecoverySampler;
use Illuminate\Console\Command;

final class AtlasCompactionRecoverySampleCommand extends Command
{
    protected $signature = 'atlas:compaction:recovery-sample
        {--limit=50 : Maximum recent receipts to sample}
        {--days=14 : Receipt lookback window}
        {--no-record : Do not append the provider-safe evidence JSONL}
        {--json : Emit canonical JSON}';

    protected $description = 'MAXF-02 — sample compaction receipts and measure read-only recovery fidelity.';

    public function handle(CompactionRecoverySampler $sampler): int
    {
        $payload = $sampler->sample(
            limit: (int) $this->option('limit'),
            days: (int) $this->option('days'),
            minimumSamples: (int) config('atlas.compaction.recovery_sample_min_receipts', 20),
            recordEvidence: ! (bool) $this->option('no-record'),
        );

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        } else {
            $this->components->twoColumnDetail('status', (string) ($payload['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('sampled_receipts', (string) ($payload['sampled_receipts'] ?? 0));
            $this->components->twoColumnDetail('recovery_rate', (string) ($payload['recovery_rate'] ?? 'n/a'));
        }

        return ($payload['passed'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }
}
