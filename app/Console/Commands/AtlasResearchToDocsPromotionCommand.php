<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasResearchToDocsPromotionService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Exercises the Research To Documentation Promotion runtime: resolves a sample
 * promotion attempt end-to-end (route the research result, validate the doc
 * delta and source trace, evaluate the 6-item promotion gate) and prints whether
 * the research is promoted to law, paused, downgraded to an isolated spike, or
 * archived as a lead.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/research-to-docs-promotion.md
 */
class AtlasResearchToDocsPromotionCommand extends Command
{
    protected $signature = 'atlas:aaeos:research-to-docs-promotion {--json}';

    protected $description = 'Resolve an Atlas research-to-docs promotion attempt: route, validate delta + source trace, evaluate the promotion gate.';

    public function handle(AtlasResearchToDocsPromotionService $promotion): int
    {
        try {
            $delta = [
                'source_basis' => 'evidence-ledger:run-77 + provider release notes',
                'decision' => 'route runtime-boundary change to the boundary doc',
                'allowed_actions' => ['update runtime boundary doc', 'add AP'],
                'forbidden_actions' => ['declare runtime without green gates'],
                'owner' => 'research-self-improvement',
                'validation' => 'php artisan atlas:engineering:knowledge docs-health --json',
                'promotion_gate' => '6-item promotion gate',
                'rollback_or_fail_closed' => 'fail closed: keep prior canonical doc',
            ];

            $result = $promotion->resolvePromotion(
                researchResult: 'changes_runtime_boundary',
                delta: $delta,
                sourceTrace: ['ap', 'source_url_or_repo_evidence'],
                gateChecklist: [
                    'research_packet_exists' => true,
                    'source_tier_is_acceptable' => true,
                    'conflicts_are_documented' => true,
                    'canonical_owner_doc_updated' => true,
                    'ap_or_plan_exists_for_structural_work' => true,
                    'validation_plan_exists' => true,
                ],
            );

            $payload = $result + ['generated_at' => now()->toJSON()];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

                return self::SUCCESS;
            }

            $this->components->twoColumnDetail('research result', $result['research_result']);
            $this->components->twoColumnDetail('destination', (string) ($result['route']['destination'] ?? '-'));
            $this->components->twoColumnDetail('delta valid', $result['delta']['valid'] ? 'yes' : 'no');
            $this->components->twoColumnDetail('source trace', $result['source_trace']['has_source_trace'] ? 'yes' : 'no');
            $this->components->twoColumnDetail('gate decision', (string) $result['gate']['decision']);
            $this->components->twoColumnDetail('promoted to law', $result['promoted_to_law'] ? 'yes' : 'no');
            $this->components->twoColumnDetail('resolution', $result['resolution']);
            $this->info($result['reason']);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $envelope = [
                'schema' => AtlasResearchToDocsPromotionService::SCHEMA_VERSION,
                'ok' => false,
                'error' => $e->getMessage(),
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
