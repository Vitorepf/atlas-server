<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ArchitectureEvolutionProposalAdmissionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * L6-4: code-graph driven auto-architecture proposals.
 *
 * This is proposal-only. It reads Code Intelligence, builds a structural review
 * envelope, parks it in the existing self-improvement backlog when requested,
 * and delegates admission to the existing structural-redesign gate. It never
 * applies a refactor and never changes merge/never-merge policy.
 */
final class AtlasLoopAutoArchitectureProposalService
{
    public const SCHEMA_VERSION = 'atlas.loop.auto_architecture_proposal.v1';

    public const PROPOSAL_ENVELOPE_SCHEMA = 'atlas.architecture.redesign_proposal.v1';

    private const PROPOSAL_INDEX_VERSION = 'v2_gpt55_provider_topology';

    public function __construct(
        private readonly AtlasSelfImprovementProposalBacklogService $proposalBacklog,
        private readonly ArchitectureEvolutionProposalAdmissionService $admission,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function propose(array $options = []): array
    {
        $limit = max(1, min(25, (int) ($options['candidate_limit'] ?? config('atlas.loop.auto_architecture_proposals.candidate_limit', 5))));
        $minFiles = max(1, (int) ($options['min_file_count'] ?? config('atlas.loop.auto_architecture_proposals.min_file_count', 20)));
        $minSymbols = max(1, (int) ($options['min_symbol_count'] ?? config('atlas.loop.auto_architecture_proposals.min_symbol_count', 120)));
        $writeReceipt = (bool) ($options['write_receipt'] ?? false);
        $createProposal = (bool) ($options['create_proposal'] ?? false);
        $receiptPath = $this->stringOrNull($options['receipt_path'] ?? null)
            ?? (string) config('atlas.loop.auto_architecture_proposals.receipt_path', storage_path('app/atlas/evidence/auto-architecture-proposal.json'));
        $indexPath = $this->stringOrNull($options['proposal_index_path'] ?? null)
            ?? (string) config('atlas.loop.auto_architecture_proposals.proposal_index_path', 'atlas/loop/auto-architecture/proposal-index.json');
        $operatorReview = $this->stringOrNull($options['operator_review'] ?? null);

        $blockers = $this->schemaBlockers();
        $candidates = $blockers === [] ? $this->hotspots($limit, $minFiles, $minSymbols) : [];
        if ($blockers === [] && $candidates === []) {
            $blockers[] = 'no_code_graph_structural_hotspot_over_floor';
        }

        $top = $candidates[0] ?? null;
        $proposalEnvelope = is_array($top) ? $this->proposalEnvelope($top, $candidates, $operatorReview) : null;
        $admission = is_array($proposalEnvelope)
            ? $this->admission->admit($this->admissionPayload($proposalEnvelope, $operatorReview))
            : null;

        $created = null;
        if ($createProposal && is_array($top) && is_array($proposalEnvelope)) {
            $created = $this->createBacklogDraft($top, $proposalEnvelope, $indexPath);
        }

        $operatorReviewRecorded = $operatorReview !== null;
        // Anti-over-claim: the operator review only counts toward the L6-4
        // completion claim when the STRUCTURAL ADMISSION GATE itself recognized
        // the operator signature. The gate is the authority, not a self-asserted
        // flag — a review string that never threads into admission (e.g. a future
        // wiring regression) must NOT be enough to claim completion.
        $operatorSignatureGateAccepted = is_array($admission)
            && (bool) ($admission['operator_signature'] ?? false) === true
            && ! in_array(
                ArchitectureEvolutionProposalAdmissionService::BLOCKER_MISSING_OPERATOR_SIGNATURE,
                (array) ($admission['blockers'] ?? []),
                true,
            );
        $status = $this->status($blockers, $created);
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => Carbon::now()->toIso8601String(),
            'source' => [
                'kind' => 'code_intelligence_read_model',
                'tables' => [
                    'atlas_engineering_code_modules',
                    'atlas_engineering_code_symbols',
                    'atlas_engineering_doc_links',
                ],
                'min_file_count' => $minFiles,
                'min_symbol_count' => $minSymbols,
                'candidate_limit' => $limit,
            ],
            'candidates' => $candidates,
            'top_candidate' => $top,
            'proposal_envelope' => $proposalEnvelope,
            'admission' => $admission,
            'operator_review' => [
                'recorded' => $operatorReviewRecorded,
                'required_for_l6_4_completion' => true,
                'admission_gate_accepted_operator_signature' => $operatorSignatureGateAccepted,
                'review_hash' => $operatorReviewRecorded ? hash('sha256', $operatorReview) : null,
                'review_excerpt' => $operatorReviewRecorded ? mb_substr($operatorReview, 0, 160) : null,
            ],
            'created_backlog_proposal' => $created,
            'blockers' => $blockers,
            'claim_policy' => [
                'provider_calls_made' => false,
                'provider_tokens_spent' => false,
                'code_workspace_mutated' => false,
                'refactor_applied' => false,
                'obra_created' => false,
                'merged_to_main' => false,
                'never_merge_changed' => false,
                'merge_gate_changed' => false,
                'proposal_backlog_write_requested' => $createProposal,
                'proposal_backlog_written' => is_array($created) && (bool) ($created['proposal_available'] ?? false),
                'operator_review_recorded' => $operatorReviewRecorded,
                'admission_gate_accepted_operator_signature' => $operatorSignatureGateAccepted,
                // The L6-4 DoD ("1 refactor estrutural proposto a partir de
                // metrica de grafo, revisado pelo operador") is claimable ONLY
                // when: a real code-graph hotspot was selected (top), a backlog
                // draft is actually parked, an operator review string was
                // recorded, AND the structural admission gate itself recognized
                // the operator signature. The gate is the source of truth — a
                // self-asserted review flag alone can never unlock the claim.
                'completion_claim_allowed' => is_array($top)
                    && is_array($created)
                    && (bool) ($created['proposal_available'] ?? false)
                    && $operatorReviewRecorded
                    && $operatorSignatureGateAccepted,
            ],
        ];

        if ($writeReceipt) {
            $this->writeReceipt($receiptPath, $payload);
            $payload['written_receipt_path'] = $receiptPath;
        }

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function schemaBlockers(): array
    {
        $required = [
            'atlas_engineering_code_modules',
            'atlas_engineering_code_symbols',
            'atlas_engineering_doc_links',
        ];

        return array_values(array_map(
            static fn (string $table): string => 'table_missing:'.$table,
            array_filter($required, static fn (string $table): bool => ! Schema::hasTable($table)),
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function hotspots(int $limit, int $minFiles, int $minSymbols): array
    {
        $modules = DB::table('atlas_engineering_code_modules')
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->get();

        $hotspots = [];
        foreach ($modules as $module) {
            $fileCount = (int) ($module->file_count ?? 0);
            $symbolCount = (int) ($module->symbol_count ?? 0);
            if ($fileCount < $minFiles && $symbolCount < $minSymbols) {
                continue;
            }

            $moduleId = (string) ($module->id ?? '');
            $classCount = $this->symbolCount($moduleId, ['class', 'interface', 'trait', 'enum']);
            $methodCount = $this->symbolCount($moduleId, ['method', 'function']);
            $docLinkCount = DB::table('atlas_engineering_doc_links')
                ->where('module_id', $moduleId)
                ->where('status', 'current')
                ->count();
            $relatedDocs = array_slice($this->jsonStringList($module->related_docs_json ?? []), 0, 20);
            $relatedTests = array_slice($this->jsonStringList($module->related_tests_json ?? []), 0, 20);
            $score = $this->scoreModule($module, $fileCount, $symbolCount, $classCount, $methodCount, $docLinkCount);
            $reasons = $this->reasons($module, $fileCount, $symbolCount, $methodCount, $docLinkCount, $minFiles, $minSymbols);
            $sovereigntyLayers = $this->sovereigntyLayersTouched($relatedDocs);
            $rootPath = trim((string) ($module->root_path ?? ''));

            $candidate = [
                'candidate_id' => 'arch_hotspot_'.substr(hash('sha256', $moduleId.'|'.(string) ($module->source_hash ?? '')), 0, 16),
                'module_id' => $moduleId,
                'module_slug' => (string) ($module->slug ?? ''),
                'module_name' => (string) ($module->name ?? ''),
                'layer' => (string) ($module->layer ?? ''),
                'root_path' => $rootPath,
                'docs_status' => (string) ($module->docs_status ?? 'unknown'),
                'metrics' => [
                    'score' => $score,
                    'file_count' => $fileCount,
                    'symbol_count' => $symbolCount,
                    'class_count' => $classCount,
                    'method_count' => $methodCount,
                    'route_count' => (int) ($module->route_count ?? 0),
                    'command_count' => (int) ($module->command_count ?? 0),
                    'test_count' => (int) ($module->test_count ?? 0),
                    'doc_link_count' => $docLinkCount,
                    'symbols_per_file' => round($symbolCount / max(1, $fileCount), 2),
                    'methods_per_class' => round($methodCount / max(1, $classCount), 2),
                ],
                'reasons' => $reasons,
                'related_docs' => $relatedDocs,
                'related_tests' => $relatedTests,
                'touches_sovereignty_layer' => $sovereigntyLayers !== [],
                'sovereignty_layers_touched' => $sovereigntyLayers,
                'proposal_floor_met' => $reasons !== [],
            ];
            $candidate['candidate_hash'] = hash('sha256', json_encode([
                $candidate['module_slug'],
                $candidate['root_path'],
                $candidate['metrics'],
                $candidate['reasons'],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $hotspots[] = $candidate;
        }

        usort($hotspots, static function (array $a, array $b): int {
            return ($b['metrics']['score'] <=> $a['metrics']['score'])
                ?: strcmp((string) $a['module_slug'], (string) $b['module_slug']);
        });

        return array_slice($hotspots, 0, $limit);
    }

    /**
     * @param  list<string>  $types
     */
    private function symbolCount(string $moduleId, array $types): int
    {
        if ($moduleId === '') {
            return 0;
        }

        return (int) DB::table('atlas_engineering_code_symbols')
            ->where('module_id', $moduleId)
            ->whereNull('archived_at')
            ->whereIn('symbol_type', $types)
            ->count();
    }

    private function scoreModule(object $module, int $files, int $symbols, int $classes, int $methods, int $docLinks): float
    {
        $score = ($files * 1.2)
            + ($symbols * 0.18)
            + ($classes * 1.5)
            + ($methods * 0.5)
            + ((int) ($module->route_count ?? 0) * 3.0)
            + ((int) ($module->command_count ?? 0) * 2.5);

        if ((int) ($module->test_count ?? 0) === 0) {
            $score += 18.0;
        }
        if ((string) ($module->docs_status ?? '') !== 'documented') {
            $score += 12.0;
        }
        if ($docLinks === 0) {
            $score += 10.0;
        }

        return round($score, 2);
    }

    /**
     * @return list<string>
     */
    private function reasons(object $module, int $files, int $symbols, int $methods, int $docLinks, int $minFiles, int $minSymbols): array
    {
        $reasons = [];
        if ($files >= $minFiles) {
            $reasons[] = 'large_module_file_count';
        }
        if ($symbols >= $minSymbols) {
            $reasons[] = 'large_module_symbol_count';
        }
        if ($methods >= max(40, (int) floor($minSymbols / 2))) {
            $reasons[] = 'method_pressure';
        }
        if ((int) ($module->test_count ?? 0) === 0 && $symbols >= max(20, (int) floor($minSymbols / 2))) {
            $reasons[] = 'low_test_signal_for_large_surface';
        }
        if ((string) ($module->docs_status ?? '') !== 'documented' || $docLinks === 0) {
            $reasons[] = 'architecture_doc_gap';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @return array<string,mixed>
     */
    private function proposalEnvelope(array $top, array $candidates, ?string $operatorReview): array
    {
        $proposalHash = substr((string) ($top['candidate_hash'] ?? hash('sha256', (string) ($top['module_slug'] ?? 'unknown'))), 0, 20);
        $root = (string) ($top['root_path'] ?? '');
        $docs = array_values(array_unique(array_merge(
            ['docs/engineering-knowledge-base/atlas-architecture-evolution-proposal-runtime.md'],
            array_slice((array) ($top['related_docs'] ?? []), 0, 4),
        )));

        return [
            'schema' => self::PROPOSAL_ENVELOPE_SCHEMA,
            'schema_version' => self::PROPOSAL_ENVELOPE_SCHEMA,
            'proposal_id' => 'arch_redesign_'.$proposalHash,
            'title' => 'Structural architecture review for '.$this->candidateName($top),
            'proposal_type' => 'structural',
            'structural' => true,
            'self_refactor' => true,
            'affected_layers' => array_values(array_filter([
                (string) ($top['layer'] ?? ''),
                'code_intelligence',
                'self_improvement_backlog',
            ])),
            'canonical_docs' => $docs,
            'structural_changes' => [[
                'target_doc' => $docs[0],
                'target_module' => (string) ($top['module_slug'] ?? ''),
                'target_root_path' => $root,
                'current_state_snapshot_hash' => 'sha256:'.(string) ($top['candidate_hash'] ?? ''),
                'proposed_state_description' => 'Park an operator-reviewed structural decomposition plan for '.$this->candidateName($top).' based on code-intelligence hotspot metrics; no code mutation before receipt, replay, and review gates.',
                'schema_changes' => [],
                'layer_count_before' => null,
                'layer_count_after' => null,
            ]],
            'motivating_evidence' => array_map(static fn (array $candidate): array => [
                'module_slug' => (string) ($candidate['module_slug'] ?? ''),
                'root_path' => (string) ($candidate['root_path'] ?? ''),
                'limitation_observed' => implode(',', (array) ($candidate['reasons'] ?? [])),
                'metrics' => (array) ($candidate['metrics'] ?? []),
            ], array_slice($candidates, 0, 5)),
            'expected_metrics_delta' => [
                'throughput' => 'less repeated local patching around high-pressure modules',
                'reliability' => 'smaller review surfaces with preserved invariants',
                'operator_friction' => 'one parked structural work item instead of scattered micro-fixes',
            ],
            'touches_sovereignty_layer' => (bool) ($top['touches_sovereignty_layer'] ?? false),
            'sovereignty_layers_touched' => (array) ($top['sovereignty_layers_touched'] ?? []),
            'safety_sovereignty_block_applied' => (bool) ($top['touches_sovereignty_layer'] ?? false),
            'invariant_scope' => $this->invariantScope(),
            'rollback_plan' => $this->rollbackPlan($root),
            'operator_signature' => $operatorReview === null ? null : [
                'signed' => true,
                'signer' => 'operator',
                'review_hash' => hash('sha256', $operatorReview),
            ],
            'architect_signature' => null,
            'proposed_by_actor' => [
                'kind' => 'agent',
                'id' => 'atlas_loop_l6_4_auto_architecture',
                'autonomy_level' => 'L6-directional',
                'promotion_blocked_below_l13' => true,
            ],
            'proposed_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function admissionPayload(array $proposalEnvelope, ?string $operatorReview): array
    {
        return [
            'proposal_type' => 'structural',
            'self_refactor' => true,
            'affected_layers' => (array) ($proposalEnvelope['affected_layers'] ?? []),
            'invariant_scope' => (array) ($proposalEnvelope['invariant_scope'] ?? []),
            'rollback_plan' => (array) ($proposalEnvelope['rollback_plan'] ?? []),
            'operator_signature' => $operatorReview === null ? null : [
                'signed' => true,
                'signer' => 'operator',
            ],
            'architect_signature' => null,
        ];
    }

    /**
     * @return list<string>
     */
    private function invariantScope(): array
    {
        return [
            'never_merge_default_remains_db_governed',
            'frozen_judge_certification_gates_and_harness_guard_not_targets',
            'proposal_only_no_code_mutation_before_receipt',
            'canonical_docs_remain_source_of_truth',
            'provider_calls_forbidden_in_proposal_stage',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function rollbackPlan(string $rootPath): array
    {
        return [
            'proposal_stage' => 'archive_or_reject_backlog_proposal; no code changes to revert',
            'implementation_stage' => 'if later approved, use branch/sandbox plus git revert only; never reset --hard',
            'scope_guard' => $rootPath === '' ? 'target_root_missing_until_operator_review' : 'future_changes_limited_to_'.$rootPath,
            'required_before_apply' => [
                'operator_signature',
                'architect_review',
                'replay_receipt',
                'focused_tests',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function createBacklogDraft(array $top, array $proposalEnvelope, string $indexPath): array
    {
        $key = hash('sha256', self::PROPOSAL_INDEX_VERSION.'|'.(string) ($top['candidate_hash'] ?? ''));
        $existing = $this->existingBacklogProposal($key, $indexPath);
        if (is_array($existing)) {
            return $existing + [
                'proposal_available' => true,
                'reused' => true,
            ];
        }

        $item = $this->proposalBacklog->createProposal([
            'proposal' => $this->backlogProposalPayload($top, $proposalEnvelope),
            'source' => 'postmortem',
            'affected_domains' => ['architecture', 'code_intelligence', 'autonomous_evolution'],
            'constraints' => [
                'proposal_only',
                'operator_review_required_before_execution',
                'architect_review_required_before_execution',
                'no_provider_call',
                'no_obra_creation',
                'no_merge',
            ],
        ]);
        $evaluated = $this->proposalBacklog->evaluateProposal((string) $item['proposal_id']);
        $prioritized = $this->proposalBacklog->prioritize((string) $item['proposal_id'], [
            'strategy_bucket' => 'core_runtime',
        ]);

        $summary = $this->createdSummary($prioritized, false) + [
            'evaluated_status' => $evaluated['status'] ?? null,
            'proposal_available' => true,
            'reused' => false,
        ];
        $this->rememberBacklogProposal($key, $indexPath, $summary);

        return $summary;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function existingBacklogProposal(string $key, string $indexPath): ?array
    {
        if ($key === '') {
            return null;
        }

        $index = $this->readStorageJson($indexPath);
        $proposalId = $this->stringOrNull(data_get($index, "proposals.{$key}.proposal_id"));
        if ($proposalId === null) {
            return null;
        }
        $item = $this->proposalBacklog->getProposal($proposalId);

        return is_array($item) ? $this->createdSummary($item, true) : null;
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function rememberBacklogProposal(string $key, string $indexPath, array $summary): void
    {
        if ($key === '') {
            return;
        }
        $index = $this->readStorageJson($indexPath);
        $proposals = is_array($index['proposals'] ?? null) ? $index['proposals'] : [];
        $proposals[$key] = [
            'proposal_id' => $summary['proposal_id'] ?? null,
            'status' => $summary['status'] ?? null,
            'recorded_at' => Carbon::now()->toIso8601String(),
        ];
        Storage::disk('local')->put($indexPath, (string) json_encode([
            'schema_version' => 'atlas.loop.auto_architecture_proposal_index.v1',
            'updated_at' => Carbon::now()->toIso8601String(),
            'proposals' => $proposals,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string,mixed>
     */
    private function backlogProposalPayload(array $top, array $proposalEnvelope): array
    {
        $root = (string) ($top['root_path'] ?? '');

        return [
            'title' => (string) ($proposalEnvelope['title'] ?? 'Structural architecture review'),
            'problem_statement' => 'Code Intelligence identified a structural hotspot in '.$this->candidateName($top).' that should be reviewed as an architecture work item instead of farmed as isolated micro-fixes.',
            'business_rule' => 'Structural self-refactors are proposal-only until operator review, architect review, replay evidence, rollback plan, and invariant preservation are complete.',
            'target_capability' => 'l6_4_auto_architecture_proposals',
            'why_now' => 'Lista 6 L6-4 requires Atlas to read its own code graph and park structural refactor proposals from measured architecture pressure.',
            'expected_power_gain' => 'lower operator friction and safer future throughput by decomposing a measured hotspot under review gates',
            'success_metrics' => [
                'structural_hotspot_selected_from_code_intelligence',
                'proposal_parked_for_operator_review',
                'no_refactor_applied_before_review',
                'invariants_and_rollback_plan_present',
            ],
            'acceptance_gates' => [
                'atlas:engineering:knowledge code-gate --strict --json',
                'operator_review_recorded_before_execution',
                'architect_review_recorded_before_execution',
                'focused_tests_selected_before_any_future_refactor',
            ],
            'canonical_docs' => array_values((array) ($proposalEnvelope['canonical_docs'] ?? [
                'docs/engineering-knowledge-base/atlas-architecture-evolution-proposal-runtime.md',
            ])),
            'allowed_paths' => array_values(array_filter([
                $root,
                'docs/engineering-knowledge-base/atlas-architecture-evolution-proposal-runtime.md',
            ])),
            'forbidden_paths' => [
                'app/Services/Ai/AutonomousEvolution/AtlasLoopHarnessGuard.php',
                'app/Services/Ai/AutonomousEvolution/AtlasLoopIntentVerifierFactory.php',
                'database/migrations/2026_06_12_000100*',
            ],
            'risk_level' => ((bool) ($top['touches_sovereignty_layer'] ?? false)) ? 'critical' : 'high',
            'human_review_required' => true,
            'autopromotion_requested' => false,
            'provider_topology_recommendation' => [
                'preferred_primary_runtime' => 'hermes_cli',
                'preferred_model' => 'gpt-5.5',
                'fallback_runtime' => 'minimax_m3',
                'requires_human_review' => true,
                'capacity_aware' => true,
                'fallback_governed' => true,
                'provider_calls_made_now' => false,
            ],
            'architecture_redesign_proposal' => $proposalEnvelope,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function createdSummary(array $item, bool $reused): array
    {
        return [
            'proposal_id' => $item['proposal_id'] ?? null,
            'title' => $item['title'] ?? null,
            'status' => $item['status'] ?? null,
            'proposal_packet_status' => data_get($item, 'proposal_packet.status'),
            'next_safe_action' => $item['next_safe_action'] ?? null,
            'strategy_bucket' => $item['strategy_bucket'] ?? null,
            'priority_score' => $item['priority_score'] ?? null,
            'linked_obra_id' => $item['linked_obra_id'] ?? null,
            'external_provider_call' => (bool) ($item['external_provider_call'] ?? false),
            'provider_tokens_spent' => (bool) ($item['provider_tokens_spent'] ?? false),
            'auto_fast_path_executed' => (bool) ($item['auto_fast_path_executed'] ?? false),
            'reused' => $reused,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function readStorageJson(string $path): array
    {
        if (! Storage::disk('local')->exists($path)) {
            return [];
        }

        try {
            $decoded = json_decode((string) Storage::disk('local')->get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function status(array $blockers, mixed $created): string
    {
        if ($blockers !== []) {
            return 'blocked';
        }

        return is_array($created) && (bool) ($created['proposal_available'] ?? false)
            ? 'parked_for_operator_review'
            : 'ready_for_operator_review';
    }

    /**
     * @param  list<string>  $docs
     * @return list<string>
     */
    private function sovereigntyLayersTouched(array $docs): array
    {
        $layers = [
            'atlas-sovereign-operating-system',
            'atlas-epistemic-operating-system',
            'atlas-evidence-certification-runtime',
            'atlas-trust-ledger-canonical',
            'atlas-cartography-nomenclature-contract',
            'atlas-canonical-glossary-and-naming',
            'atlas-cognition-operating-system',
            'atlas-ai-knowledge-governance-system',
        ];

        $hit = [];
        $haystack = implode("\n", $docs);
        foreach ($layers as $layer) {
            if (str_contains($haystack, $layer)) {
                $hit[] = $layer;
            }
        }

        return $hit;
    }

    /**
     * @return list<string>
     */
    private function jsonStringList(mixed $value): array
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                $value = [];
            }
        }

        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return array_values(array_unique($out));
    }

    private function candidateName(array $candidate): string
    {
        $name = trim((string) ($candidate['module_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        return trim((string) ($candidate['module_slug'] ?? 'unknown-module')) ?: 'unknown-module';
    }

    private function writeReceipt(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
