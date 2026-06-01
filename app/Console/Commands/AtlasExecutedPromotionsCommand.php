<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasExecutedPromotionsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Legacy Cleanup Executed Promotions command — emits the executed-promotions
 * registry and runs the Re-Promotion Rule gate over representative requests
 * (a fully satisfied re-promotion vs. one that skipped reading the destination).
 *
 * @see docs/engineering-knowledge-base/legacy-cleanup/executed-promotions.md
 */
class AtlasExecutedPromotionsCommand extends Command
{
    protected $signature = 'atlas:aaeos:executed-promotions {--json : Print machine-readable JSON}';

    protected $description = 'Resolve legacy-source authority (Redirect Principle) and apply the ordered Re-Promotion Rule gate.';

    public function handle(AtlasExecutedPromotionsService $service): int
    {
        try {
            $registry = $service->registry();

            // Safe defaults: a re-promotion that satisfies all five steps (allow)
            // and one that skipped reading the destination first (block at step 1).
            $compliant = $service->evaluateRePromotion([
                'source_theme' => 'Skill system',
                'destination_read' => true,
                'missing_decision_identified' => true,
                'owner_doc_patched' => true,
                'source_link_preserved' => true,
                'docs_health_validated' => true,
            ]);

            $skippedDiff = $service->evaluateRePromotion([
                'source_theme' => 'Skill system',
                'missing_decision_identified' => true,
                'owner_doc_patched' => true,
            ]);

            $payload = [
                'schema_version' => AtlasExecutedPromotionsService::SCHEMA,
                'ok' => true,
                'registry' => $registry,
                're_promotion_examples' => [
                    'all_steps_satisfied' => $compliant,
                    'skipped_destination_diff' => $skippedDiff,
                ],
                'generated_at' => now()->toJSON(),
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

                return self::SUCCESS;
            }

            $this->components->twoColumnDetail('executed promotions', (string) $registry['promotion_count']);
            $this->components->twoColumnDetail('re-promotion steps', (string) count($registry['re_promotion_steps']));

            $this->table(
                ['source theme', 'destination authority'],
                collect($registry['promotions'])->map(fn (array $row): array => [
                    (string) $row['source_theme'],
                    (string) $row['destination'],
                ])->all(),
            );

            $this->components->twoColumnDetail(
                're-promotion (all steps)',
                $compliant['allowed'] ? 'allow' : 'block @ ' . (string) $compliant['blocking_step'],
            );
            $this->components->twoColumnDetail(
                're-promotion (skipped diff)',
                $skippedDiff['allowed'] ? 'allow' : 'block @ ' . (string) $skippedDiff['blocking_step'],
            );

            $this->info('Redirect Principle: a promoted source\'s destination doc is the authority; never seed a parallel flow.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $envelope = [
                'schema_version' => AtlasExecutedPromotionsService::SCHEMA,
                'ok' => false,
                'error' => $e->getMessage(),
            ];
            $this->line(json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{"ok":false}');

            return self::FAILURE;
        }
    }
}
