<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\AtlasContextQualityCertificationService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasContextQualityCertifyCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:context:quality-certify
        {--cases=1200 : Synthetic context stress cases}
        {--target=9.8 : Minimum quality score}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero unless ready}';

    protected $description = 'Run Atlas context/memory quality certification gate without providers or external rivals.';

    public function handle(AtlasContextQualityCertificationService $service): int
    {
        $payload = $service->certify([
            'cases' => (int) $this->option('cases'),
            'target_score' => (float) $this->option('target'),
        ]);

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);

            return (bool) $this->option('strict') && $payload['status'] !== 'ready'
                ? self::FAILURE
                : self::SUCCESS;
        }

        $this->components->twoColumnDetail('Atlas Context Quality Certification', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('Status', (string) $payload['status']);
        $this->components->twoColumnDetail('Score', (string) $payload['quality_score']);
        $this->components->twoColumnDetail('Target', (string) $payload['target_score']);
        $this->components->twoColumnDetail('Cases', (string) data_get($payload, 'summary.case_count', 0));
        $this->components->twoColumnDetail('Components ready', data_get($payload, 'summary.components_ready', 0).'/'.data_get($payload, 'summary.component_count', 0));
        $this->components->twoColumnDetail('AUCRI blocks', (string) data_get($payload, 'summary.aucri_blocks_executed', 0));
        $this->components->twoColumnDetail('Hash', (string) $payload['certification_hash']);

        return (bool) $this->option('strict') && $payload['status'] !== 'ready'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
