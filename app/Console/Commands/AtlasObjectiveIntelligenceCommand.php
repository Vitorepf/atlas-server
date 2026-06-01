<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasObjectiveIntelligenceService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Projects the Atlas Objective Intelligence contract and demonstrates the
 * execution gate on a sample mission: an objective with no Definition of Done
 * is NOT ready to execute, while a complete one is. Runtime witness for the
 * documented "Nao executar meta complexa sem objetivo e DoD" rule.
 *
 * @see docs/engineering-knowledge-base/atlas-objective-intelligence.md
 */
class AtlasObjectiveIntelligenceCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-objective-intelligence {--json}';

    protected $description = 'Project the Atlas Objective Intelligence contract and the objective execution gate (objective + DoD + metric + constraints).';

    public function handle(AtlasObjectiveIntelligenceService $service): int
    {
        try {
            $contract = $service->contract();

            // Safe default sample: a complete, ready-to-execute objective record.
            $sample = $service->buildObjective([
                'mission_id' => 'mission_demo',
                'objective' => 'Launch a functional ecommerce and record the first real sale.',
                'primary_metric' => 'first_sale_recorded',
                'secondary_metrics' => ['conversion_rate', 'visits'],
                'constraints' => ['budget <= 0 external spend', 'no scraping'],
                'assumptions' => ['catalog data is available locally'],
                'risks' => ['traffic may be insufficient'],
                'definition_of_done' => ['storefront live', 'payment configured', 'first sale or real blocker recorded'],
                'blockers' => [],
            ]);

            $result = [
                'schema_version' => $contract['schema_version'],
                'contract' => $contract,
                'sample_objective' => $sample,
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

                return self::SUCCESS;
            }

            $this->components->twoColumnDetail('minimum fields', (string) count($contract['minimum_fields']));
            $this->components->twoColumnDetail('non-inventable fields', implode(', ', $contract['non_inventable_fields']));
            $this->components->twoColumnDetail('sample objective_id', $sample['objective_id']);
            $this->components->twoColumnDetail('sample ready_to_execute', $sample['ready_to_execute'] ? 'yes' : 'no');
            $this->components->twoColumnDetail('sample dod_hash', substr($sample['dod_hash'], 0, 16).'...');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $envelope = [
                'schema_version' => AtlasObjectiveIntelligenceService::SCHEMA_VERSION,
                'error' => true,
                'message' => $e->getMessage(),
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }
    }
}
