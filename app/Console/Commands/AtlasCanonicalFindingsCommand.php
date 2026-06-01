<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCanonicalFindingsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Surfaces the architecture-audit canonical-findings decider for one finding.
 *
 * @see docs/engineering-knowledge-base/architecture-audit/canonical-findings.md
 */
class AtlasCanonicalFindingsCommand extends Command
{
    protected $signature = 'atlas:aaeos:canonical-findings {--json : Emit canonical JSON}';

    protected $description = 'Classify an observed flow against the canonical architecture-audit truths and disorders, then resolve its consolidation owner.';

    public function handle(AtlasCanonicalFindingsService $service): int
    {
        try {
            // Safe default: a duplicated orchestration flow currently living in a
            // surface adapter — the discipline must promote it (to Core) rather
            // than patch it in place.
            $payload = $service->classify([
                'logic_layer' => 'surface_adapter',
                'duplicates_existing' => true,
                'capability_kind' => 'orchestration',
            ]);

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode(
                    $payload,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ));

                return self::SUCCESS;
            }

            $this->components->twoColumnDetail('Canonical Findings verdict', (string) $payload['verdict']);
            $this->components->twoColumnDetail('Logic layer', (string) $payload['logic_layer']);
            $this->components->twoColumnDetail('Patch in place forbidden', $payload['patch_in_place_forbidden'] ? 'yes' : 'no');
            $this->components->twoColumnDetail('Promotion owner', (string) $payload['promotion_owner']);
            $this->components->twoColumnDetail('Violated truths', (string) $payload['violated_truth_count']);
            $this->components->twoColumnDetail('Disorders', (string) count((array) $payload['disorders']));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $envelope = [
                'schema' => AtlasCanonicalFindingsService::RECEIPT_SCHEMA,
                'ok' => false,
                'error' => $e->getMessage(),
            ];

            $this->line((string) json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
