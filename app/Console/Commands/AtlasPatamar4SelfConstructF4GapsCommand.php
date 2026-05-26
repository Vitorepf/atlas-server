<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionSubsystemBuilderService;
use Illuminate\Console\Command;

/**
 * A6 · Self-construct the 2 services still missing for full F4 probe
 * coverage (cache_compact + agrn_reindex_advice). Fires
 * AtlasSelfConstructionSubsystemBuilderService::propose() once per gap
 * with rationale `operator_request` so the proposals enter the canon
 * promotion workflow.
 *
 * The proposals are append-only JSONL receipts (no auto-promotion).
 * Operator runs `atlas:self-construction:approve-subsystem --proposal=ID
 * --decision=approve` to promote.
 *
 * Authority doc: docs/engineering-knowledge-base/atlas-subsystem-auto-rebalance.md (§ A6)
 */
class AtlasPatamar4SelfConstructF4GapsCommand extends Command
{
    protected $signature = 'atlas:patamar4:self-construct-f4-gaps
        {--json : Emit JSON envelope}';

    protected $description = 'Propose ASCB scaffolds for the 2 F4 probe gaps (cache pool + AGRN stale fraction).';

    public function handle(AtlasSelfConstructionSubsystemBuilderService $ascb): int
    {
        $gaps = [
            [
                'acronym' => 'ACPS',
                'name' => 'Atlas Cache Pool Service',
                'group' => 'memory_core',
                'rationale' => 'F4 cache_compact probe needs a canonical cache pool with size()+age() metrics. Service does not yet exist; AtlasSubsystemAutoRebalanceService emits probe_status=unwired for cache_compact until ACPS lands.',
            ],
            [
                'acronym' => 'AGRN-ISF',
                'name' => 'AGRN indexStaleFraction probe extension',
                'group' => 'aucri',
                'rationale' => 'F4 agrn_reindex_advice probe needs AtlasGraphRetrievalNetworkService::indexStaleFraction() to return a real ratio. Method missing; AtlasSubsystemAutoRebalanceService emits probe_status=unwired for agrn_reindex until method exists.',
            ],
        ];

        $proposals = [];
        foreach ($gaps as $gap) {
            try {
                $proposal = $ascb->propose([
                    'gap_kind' => AtlasSelfConstructionSubsystemBuilderService::GAP_OPERATOR_REQUEST,
                    'subsystem_acronym' => $gap['acronym'],
                    'subsystem_name' => $gap['name'],
                    'group' => $gap['group'],
                    'rationale' => $gap['rationale'],
                ]);
                $proposals[] = [
                    'proposal_id' => $proposal['proposal_id'] ?? null,
                    'proposal_hash' => $proposal['proposal_hash'] ?? null,
                    'acronym' => $gap['acronym'],
                    'service_class' => $proposal['proposed_subsystem']['service_class'] ?? null,
                    'doc_path' => $proposal['proposed_subsystem']['doc_path'] ?? null,
                ];
            } catch (\Throwable $e) {
                $proposals[] = [
                    'acronym' => $gap['acronym'],
                    'error' => substr($e->getMessage(), 0, 160),
                ];
            }
        }

        $envelope = [
            'schema_version' => 'atlas.patamar4.self_construct_f4_gaps.v1',
            'gap_count' => count($gaps),
            'proposal_count' => count(array_filter($proposals, fn ($p) => isset($p['proposal_id']))),
            'proposals' => $proposals,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        foreach ($proposals as $p) {
            $this->components->twoColumnDetail($p['acronym'] ?? '?', $p['proposal_id'] ?? ($p['error'] ?? 'unknown'));
        }

        return self::SUCCESS;
    }
}
