<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * L5-1 · governed weekly agenda proposal for the Atlas Loop.
 *
 * This is deliberately SUGGEST-only: it reads resolved Loop signals, composes a
 * weekly engineering agenda, and can persist that agenda as a self-improvement
 * backlog draft. It never creates an Obra, never approves itself, and never
 * calls a provider.
 */
final class AtlasLoopWeeklyAgendaProposalService
{
    public const SCHEMA_VERSION = 'atlas.loop.weekly_agenda_proposal.v1';

    public const EXECUTION_SCHEMA_VERSION = 'atlas.loop.weekly_agenda_execution_receipt.v1';

    public function __construct(
        private readonly AtlasLoopMorningDigestService $morningDigest,
        private readonly AtlasLoopBacklogAutoFeederService $backlogFeeder,
        private readonly AtlasSelfImprovementProposalBacklogService $proposalBacklog,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function propose(array $options = []): array
    {
        $hours = max(24, min(336, (int) ($options['hours'] ?? config('atlas.loop.weekly_agenda.window_hours', 168))));
        $maxItems = max(1, min(10, (int) ($options['max_items'] ?? config('atlas.loop.weekly_agenda.max_items', 5))));

        $digest = $this->arrayOptionOr($options, 'digest', fn (): array => $this->morningDigest->digest($hours));
        $scorecard = $this->arrayOptionOr($options, 'scorecard', fn (): array => $this->scorecard());
        $deltaReport = $this->arrayOptionOr($options, 'delta_report', fn (): array => $this->deltaReport());
        $backlogFeed = $this->arrayOptionOr($options, 'backlog_feed', fn (): array => $this->backlogFeeder->feed(null, [
            'window_hours' => $hours,
            'write' => false,
        ]));
        $finalCapture = $this->arrayOptionOr($options, 'final_capture', fn (): array => $this->finalCapture());
        $weeklyReport = $this->arrayOptionOr($options, 'weekly_report', fn (): array => $this->weeklyReport());

        $sourceSummary = [
            'morning_digest' => $this->summarizeDigest($digest),
            'scorecard' => $this->summarizeScorecard($scorecard),
            'delta_report' => $this->summarizeDeltaReport($deltaReport),
            'backlog_feed' => $this->summarizeBacklogFeed($backlogFeed),
            'final_capture' => $this->summarizeFinalCapture($finalCapture),
            'weekly_report' => $this->summarizeWeeklyReport($weeklyReport),
        ];

        $agenda = array_slice($this->priorities($sourceSummary), 0, $maxItems);
        $blockedCandidates = $this->blockedCandidates($sourceSummary);
        $finalCaptureReady = (string) data_get($sourceSummary, 'final_capture.status') === 'ready';
        $status = $finalCaptureReady && $agenda !== []
            ? 'ready_for_operator_review'
            : 'needs_resolved_evidence';

        $proposalPayload = $this->backlogProposalPayload($agenda, $blockedCandidates, $sourceSummary);
        $executeApproved = (bool) ($options['execute_approved'] ?? false);
        $operatorApproval = $this->stringOrNull($options['operator_approval'] ?? null);
        $createdProposal = null;
        if (((bool) ($options['create_proposal'] ?? false) || $executeApproved) && $status === 'ready_for_operator_review') {
            $createdProposal = $this->proposalBacklog->createProposal([
                'proposal' => $proposalPayload,
                'source' => 'postmortem',
                'affected_domains' => ['autonomous_evolution', 'self_improvement', 'operator_experience'],
                'constraints' => [
                    'suggest_only',
                    'operator_approval_required_before_execution',
                    'no_provider_call',
                    'no_obra_creation',
                ],
            ]);

            if ((bool) ($options['prioritize_proposal'] ?? true)) {
                $createdProposal = $this->proposalBacklog->prioritize((string) $createdProposal['proposal_id'], [
                    'strategy_bucket' => 'core_runtime',
                ]);
            }
        }

        $approvedExecution = null;
        if ($executeApproved) {
            $approvedExecution = $this->executeApprovedAgenda(
                $agenda,
                $blockedCandidates,
                $sourceSummary,
                is_array($createdProposal) ? $createdProposal : null,
                $operatorApproval,
            );
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => Carbon::now()->toIso8601String(),
            'title' => 'Fable L5-1 governed weekly engineering agenda',
            'window' => [
                'hours' => $hours,
                'max_items' => $maxItems,
            ],
            'agenda' => $agenda,
            'blocked_candidates' => $blockedCandidates,
            'source_summary' => $sourceSummary,
            'operator_approval' => [
                'required' => true,
                'auto_execute_allowed' => false,
                'approval_surface' => 'atlas:self-improvement:proposal-backlog',
                'created_proposal_id' => is_array($createdProposal) ? ($createdProposal['proposal_id'] ?? null) : null,
                'created_proposal_status' => is_array($createdProposal) ? ($createdProposal['status'] ?? null) : null,
                'approval_recorded' => is_array($approvedExecution)
                    ? (bool) data_get($approvedExecution, 'operator_approval.approved', false)
                    : false,
                'next_safe_action' => is_array($createdProposal)
                    ? 'operator_reviews_and_explicitly_approves_or_rejects_the_backlog_proposal'
                    : 'rerun_with_--create-proposal_or_create_backlog_draft_manually',
            ],
            'backlog_proposal_payload' => $proposalPayload,
            'created_backlog_proposal' => $createdProposal === null ? null : $this->summarizeCreatedProposal($createdProposal),
            'approved_execution' => $approvedExecution,
            'claim_policy' => [
                'mode' => 'suggest_only',
                'read_only_without_create_proposal' => ! (bool) ($options['create_proposal'] ?? false),
                'provider_calls_made' => false,
                'provider_tokens_spent' => false,
                'obra_created' => false,
                'auto_execution_started' => false,
                'operator_approval_required' => true,
                'operator_approval_recorded' => is_array($approvedExecution)
                    ? (bool) data_get($approvedExecution, 'operator_approval.approved', false)
                    : false,
                'approved_execution_receipt_written' => is_array($approvedExecution)
                    && ($approvedExecution['status'] ?? null) === 'executed',
            ],
        ];
    }

    /**
     * Execute the approved weekly agenda in the narrow, safe L5-1 sense:
     * record explicit operator approval, evaluate/prioritize the backlog
     * proposal, and persist an append-only receipt. It does not call providers,
     * create Obras, merge code, or auto-run agenda items.
     *
     * @param  list<array<string,mixed>>  $agenda
     * @param  list<array<string,mixed>>  $blockedCandidates
     * @param  array<string,mixed>  $sourceSummary
     * @param  array<string,mixed>|null  $createdProposal
     * @return array<string,mixed>
     */
    private function executeApprovedAgenda(
        array $agenda,
        array $blockedCandidates,
        array $sourceSummary,
        ?array $createdProposal,
        ?string $operatorApproval,
    ): array {
        if ($operatorApproval === null) {
            return [
                'schema_version' => self::EXECUTION_SCHEMA_VERSION,
                'status' => 'blocked',
                'blockers' => ['operator_approval_required_for_weekly_agenda_execution'],
                'provider_calls_made' => false,
                'provider_tokens_spent' => false,
                'obra_created' => false,
                'merged_to_main' => false,
            ];
        }

        if ($createdProposal === null || ! is_string($createdProposal['proposal_id'] ?? null)) {
            return [
                'schema_version' => self::EXECUTION_SCHEMA_VERSION,
                'status' => 'blocked',
                'blockers' => ['backlog_proposal_required_for_weekly_agenda_execution'],
                'provider_calls_made' => false,
                'provider_tokens_spent' => false,
                'obra_created' => false,
                'merged_to_main' => false,
            ];
        }

        $proposalId = (string) $createdProposal['proposal_id'];
        $evaluated = $this->proposalBacklog->evaluateProposal($proposalId);
        $prioritized = $this->proposalBacklog->prioritize($proposalId, [
            'strategy_bucket' => 'core_runtime',
        ]);

        $receiptId = 'weekly_agenda_exec_'.Carbon::now()->format('YmdHis').'_'.substr(hash('sha256', $proposalId.$operatorApproval), 0, 12);
        $path = 'atlas/loop/weekly-agenda/executions/'.$receiptId.'.json';
        $receipt = [
            'schema_version' => self::EXECUTION_SCHEMA_VERSION,
            'receipt_id' => $receiptId,
            'status' => 'executed',
            'generated_at' => Carbon::now()->toIso8601String(),
            'operator_approval' => [
                'approved' => true,
                'approved_by' => 'operator',
                'approval_hash' => hash('sha256', $operatorApproval),
                'approval_excerpt' => mb_substr($operatorApproval, 0, 120),
            ],
            'agenda_priority_ids' => array_values(array_map(static fn (array $item): string => (string) $item['id'], $agenda)),
            'blocked_candidate_ids' => array_values(array_map(static fn (array $item): string => (string) $item['id'], $blockedCandidates)),
            'proposal_id' => $proposalId,
            'proposal_status_after_evaluate' => $evaluated['status'] ?? null,
            'proposal_status_after_prioritize' => $prioritized['status'] ?? null,
            'execution_effect' => [
                'backlog_proposal_created' => true,
                'proposal_evaluated' => ($evaluated['proposal_id'] ?? null) === $proposalId,
                'proposal_prioritized' => ($prioritized['proposal_id'] ?? null) === $proposalId,
                'week_unlocked_by_operator' => true,
                'agenda_items_auto_executed' => false,
            ],
            'source_snapshot' => [
                'morning_digest_headline' => data_get($sourceSummary, 'morning_digest.headline'),
                'scorecard_overall' => data_get($sourceSummary, 'scorecard.overall_score'),
                'pipeline_score' => data_get($sourceSummary, 'scorecard.pipeline_score'),
                'cost_coverage_pct_24h' => data_get($sourceSummary, 'morning_digest.cost.coverage_pct_24h'),
            ],
            'claim_policy' => [
                'provider_calls_made' => false,
                'provider_tokens_spent' => false,
                'obra_created' => false,
                'merged_to_main' => false,
                'auto_execution_started' => false,
                'operator_approval_required' => true,
                'operator_approval_recorded' => true,
            ],
            'receipt_path' => $path,
        ];

        Storage::disk('local')->put($path, (string) json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $receipt;
    }

    /**
     * @return array<string,mixed>
     */
    private function scorecard(): array
    {
        try {
            $output = new BufferedOutput;
            $exitCode = Artisan::call('atlas:cognition:scorecard', [
                '--json' => true,
            ], $output);
            $decoded = json_decode($output->fetch(), true);

            return is_array($decoded)
                ? array_merge($decoded, ['command_exit_code' => $exitCode])
                : ['status' => 'unavailable', 'reason' => 'scorecard_not_json', 'command_exit_code' => $exitCode];
        } catch (Throwable $e) {
            return [
                'status' => 'unavailable',
                'reason' => 'scorecard_failed',
                'error' => mb_substr($e->getMessage(), 0, 160),
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function deltaReport(): array
    {
        try {
            $output = new BufferedOutput;
            $exitCode = Artisan::call('atlas:fable:delta-series', [
                '--report' => true,
                '--json' => true,
            ], $output);
            $decoded = json_decode($output->fetch(), true);

            return is_array($decoded)
                ? array_merge($decoded, ['command_exit_code' => $exitCode])
                : ['status' => 'unavailable', 'reason' => 'delta_report_not_json', 'command_exit_code' => $exitCode];
        } catch (Throwable $e) {
            return [
                'status' => 'unavailable',
                'reason' => 'delta_report_failed',
                'error' => mb_substr($e->getMessage(), 0, 160),
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function finalCapture(): array
    {
        $path = storage_path('app/atlas/evidence/fable-l4-14-final-capture.json');
        if (! is_file($path)) {
            return [
                'status' => 'missing',
                'path' => $path,
                'reason' => 'fable_l4_14_final_capture_missing',
            ];
        }

        try {
            $capture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return [
                'status' => 'unavailable',
                'path' => $path,
                'reason' => 'fable_l4_14_final_capture_unreadable',
                'error' => mb_substr($e->getMessage(), 0, 160),
            ];
        }

        if (! is_array($capture)) {
            return [
                'status' => 'unavailable',
                'path' => $path,
                'reason' => 'fable_l4_14_final_capture_not_array',
            ];
        }

        $reportPath = (string) data_get($capture, 'final_report.report_path', '');
        if ($reportPath !== '' && File::exists($reportPath)) {
            try {
                $report = json_decode((string) file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($report)) {
                    $capture['_loaded_final_report_summary'] = [
                        'status' => $report['status'] ?? null,
                        'l4_10_real_execution_claim_allowed' => data_get($report, 'claim_policy.l4_10_real_execution_claim_allowed'),
                        'forge_l4_10_status' => data_get($report, 'resolved_sources.forge_l4_10_proof.status')
                            ?? data_get($report, 'source_reports.forge_l4_10_proof.status')
                            ?? data_get($report, 'handoff_packet.summary.forge_l4_10_status')
                            ?? data_get($report, 'summary.forge_l4_10_status')
                            ?? data_get($report, 'checks.forge_l4_10.status'),
                    ];
                }
            } catch (Throwable) {
                $capture['_loaded_final_report_summary'] = [
                    'status' => 'unavailable',
                    'reason' => 'final_report_unreadable',
                ];
            }
        }

        return $capture;
    }

    /**
     * @return array<string,mixed>
     */
    private function weeklyReport(): array
    {
        $path = (string) config('atlas.loop.weekly_report.report_path', storage_path('app/atlas/evidence/weekly-engineering-report.json'));
        if (! is_file($path)) {
            return [
                'status' => 'missing',
                'path' => $path,
                'reason' => 'weekly_report_missing',
            ];
        }

        try {
            $report = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return [
                'status' => 'unavailable',
                'path' => $path,
                'reason' => 'weekly_report_unreadable',
                'error' => mb_substr($e->getMessage(), 0, 160),
            ];
        }

        return is_array($report)
            ? $report + ['path' => $path]
            : ['status' => 'unavailable', 'path' => $path, 'reason' => 'weekly_report_not_array'];
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return list<array<string,mixed>>
     */
    private function priorities(array $summary): array
    {
        $items = [];
        $failedCanaries = (int) data_get($summary, 'morning_digest.canaries.failed_24h', 0);
        if ($failedCanaries > 0) {
            $items[] = $this->priority(
                'stabilize_red_canaries',
                100,
                'Stabilizar canários vermelhos antes de expandir autonomia',
                "{$failedCanaries} canary failure(s) in the current Loop window.",
                'Fix-forward the failing canary targets, then re-run the digest until failed_24h reaches zero.',
                ['atlas:loop:morning-digest --json', 'targeted canary test for each latest_failed target'],
            );
        }

        $costCoverage = (float) data_get($summary, 'morning_digest.cost.coverage_pct_24h', 0.0);
        $costEvents = (int) data_get($summary, 'morning_digest.cost.events_24h', 0);
        if ($costEvents === 0 || $costCoverage <= 0.0) {
            $items[] = $this->priority(
                'restore_cost_coverage',
                92,
                'Restaurar cobertura de custo antes de roteamento ou governador',
                "Cost coverage is {$costCoverage}% across {$costEvents} telemetry event(s).",
                'Repair measured cost capture before starting L5-6 or L5-7.',
                ['atlas:loop:morning-digest --json', 'atlas:fable:delta-series --report --json'],
            );
        }

        $drainable = (int) data_get($summary, 'morning_digest.funnel.drainable_remaining', 0);
        if ($drainable > 0) {
            $items[] = $this->priority(
                'drain_certified_proposals',
                86,
                'Drenar propostas certificadas pela porta governada',
                "{$drainable} certified proposal(s) remain drainable after the L4 loop.",
                'Continue governed merge-loop drainage with canary revalidation and no frozen-gate targets.',
                ['atlas:loop:funnel --json', 'atlas:loop:morning-digest --json'],
            );
        }

        $pipelineScore = (float) (
            data_get($summary, 'scorecard.pipeline_score')
            ?? data_get($summary, 'delta_report.pipeline_score')
            ?? 0.0
        );
        if ($pipelineScore > 0.0 && $pipelineScore < 7.0) {
            $items[] = $this->priority(
                'raise_pipeline_score',
                78,
                'Fechar fraqueza de pipeline no scorecard',
                "Pipeline score is {$pipelineScore}, below the L5-ready floor of 7.0.",
                'Mint or repair resolved pipeline receipts before broadening autonomy.',
                ['atlas:cognition:scorecard --json', 'atlas:cognition:mint-pipeline-receipts --limit=8 --json'],
            );
        }

        $backlogCandidates = (int) data_get($summary, 'backlog_feed.candidate_count', 0);
        if ($backlogCandidates > 0) {
            $items[] = $this->priority(
                'triage_auto_fed_backlog',
                72,
                'Triar intents auto-alimentados do backlog',
                "{$backlogCandidates} backlog candidate(s) are visible in dry-run.",
                'Promote only deduped, file-addressable intents into the Loop manifest.',
                ['atlas:loop:backlog-feed --dry-run --json'],
            );
        }

        $pendingReview = (int) data_get($summary, 'morning_digest.operator_review.pending_parked_for_review', 0);
        if ($pendingReview > 0) {
            $items[] = $this->priority(
                'clear_operator_review_queue',
                68,
                'Resolver fila parked do operador',
                "{$pendingReview} proposal(s) are parked for explicit operator review.",
                'Review, approve, or reject parked proposals before increasing loop throughput.',
                ['atlas:loop:operator-review --json'],
            );
        }

        usort($items, static fn (array $a, array $b): int => ((int) $b['score']) <=> ((int) $a['score']));

        return $items;
    }

    /**
     * @return array<string,mixed>
     */
    private function priority(string $id, int $score, string $title, string $evidence, string $recommendedAction, array $acceptanceGates): array
    {
        return [
            'id' => $id,
            'score' => $score,
            'title' => $title,
            'evidence' => $evidence,
            'recommended_action' => $recommendedAction,
            'acceptance_gates' => array_values($acceptanceGates),
            'operator_decision_required' => true,
            'auto_execute_allowed' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return list<array<string,mixed>>
     */
    private function blockedCandidates(array $summary): array
    {
        $blocked = [];
        $l410Allowed = data_get($summary, 'final_capture.l4_10_real_execution_claim_allowed');
        $l410Status = (string) (data_get($summary, 'final_capture.forge_l4_10_status') ?? '');
        if ($l410Allowed !== true || $l410Status === 'real_execution_blocked') {
            $blocked[] = [
                'id' => 'l5_2_loop_to_obra_bridge',
                'title' => 'Loop->Obra bridge multi-arquivo',
                'status' => 'blocked_by_operator_gated_l4_10_real_execution',
                'reason' => 'L5-2 needs a real L4-10 multi-node Forge execution receipt before it can be claimed as proven.',
                'next_safe_action' => 'obtain_operator_authorized_real_forge_receipt_before_starting_l5_2',
            ];
        }

        return $blocked;
    }

    /**
     * @param  list<array<string,mixed>>  $agenda
     * @param  list<array<string,mixed>>  $blockedCandidates
     * @param  array<string,mixed>  $sourceSummary
     * @return array<string,mixed>
     */
    private function backlogProposalPayload(array $agenda, array $blockedCandidates, array $sourceSummary): array
    {
        $top = $agenda[0]['title'] ?? 'Review weekly Loop evidence';
        $canaries = (int) data_get($sourceSummary, 'morning_digest.canaries.failed_24h', 0);
        $drainable = (int) data_get($sourceSummary, 'morning_digest.funnel.drainable_remaining', 0);
        $costCoverage = (float) data_get($sourceSummary, 'morning_digest.cost.coverage_pct_24h', 0.0);

        return [
            'title' => 'Fable L5-1 weekly engineering agenda: '.$top,
            'problem_statement' => sprintf(
                'Atlas has finished the L4 maintenance layer and now needs a governed weekly agenda from resolved evidence: %d canary failure(s), %d drainable proposal(s), %.1f%% cost coverage.',
                $canaries,
                $drainable,
                $costCoverage,
            ),
            'business_rule' => 'Atlas may propose a weekly engineering agenda at SUGGEST level only; explicit operator approval is required before any execution or activation.',
            'target_capability' => 'governed_weekly_engineering_agenda',
            'why_now' => 'Fable Lista 4 final capture is ready, while current Loop evidence still shows concrete risks and remaining drainable work.',
            'expected_power_gain' => 'weekly_prioritization_from_resolved_loop_evidence_without_auto_execution',
            'canonical_docs' => [
                'docs/fable-lista-5-14-itens.md',
                'docs/fable-lista-4-14-itens.md',
                'docs/fable-listas-4-5-6-execution-prompt.md',
                'docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md',
            ],
            'allowed_paths' => [
                'app/Services/Ai/AutonomousEvolution',
                'app/Console/Commands',
                'tests/Feature/Loop',
                'docs/fable-lista-5-14-itens.md',
            ],
            'forbidden_paths' => [
                'database/migrations',
                'app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php',
                'app/Services/Ai/AutonomousEvolution/AtlasLoopProposalPromotionGate.php',
                'app/Services/Ai/AutonomousEvolution/AtlasLoopHarnessGuard.php',
                '.env',
            ],
            'success_metrics' => [
                'weekly agenda generated from resolved Loop evidence',
                'agenda persisted as proposal backlog draft only after explicit --create-proposal',
                'operator approval remains required before activation',
                'no provider calls and no Obra creation during agenda proposal',
            ],
            'acceptance_gates' => array_values(array_unique(array_merge(
                ['atlas:loop:weekly-agenda --json --strict'],
                ...array_map(static fn (array $item): array => (array) ($item['acceptance_gates'] ?? []), $agenda),
            ))),
            'risk_level' => $blockedCandidates === [] ? 'medium' : 'high',
            'human_review_required' => true,
            'autopromotion_requested' => false,
            'requires_provider_cost_approval' => false,
            'rollback_strategy' => [
                'rollback_kind' => 'archive_backlog_draft_or_update_weekly_agenda',
                'checkpoint_required' => false,
                'forge_workspace_required' => false,
                'evidence_refs_required' => true,
                'human_approval_for_rollback' => false,
            ],
            'test_strategy' => [
                'unit_tests_required' => false,
                'feature_tests_required' => true,
                'docs_health_required' => true,
                'architecture_validate_required' => false,
                'completion_audit_required' => false,
                'lint_required' => true,
                'rivals_separated_from_claim' => true,
            ],
            'agenda_priority_ids' => array_values(array_map(static fn (array $item): string => (string) $item['id'], $agenda)),
            'blocked_candidate_ids' => array_values(array_map(static fn (array $item): string => (string) $item['id'], $blockedCandidates)),
            'weekly_report_feed' => [
                'status' => (string) data_get($sourceSummary, 'weekly_report.status', 'missing'),
                'feed_status' => (string) data_get($sourceSummary, 'weekly_report.feed_status', 'missing'),
                'path' => data_get($sourceSummary, 'weekly_report.path'),
                'agenda_item_count' => (int) data_get($sourceSummary, 'weekly_report.agenda_item_count', 0),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $digest
     * @return array<string,mixed>
     */
    private function summarizeDigest(array $digest): array
    {
        return [
            'status' => (string) ($digest['status'] ?? 'unknown'),
            'headline' => $digest['headline'] ?? null,
            'funnel' => [
                'merged' => (int) data_get($digest, 'sections.funnel.stages.merged', 0),
                'drainable_remaining' => (int) (data_get($digest, 'sections.funnel.conversion.drainable_remaining')
                    ?? data_get($digest, 'sections.funnel.stages.drainable')
                    ?? 0),
                'retired_stale' => (int) data_get($digest, 'sections.funnel.branches.retired_stale', 0),
                'verdict' => data_get($digest, 'sections.funnel.verdict'),
            ],
            'canaries' => [
                'ran_24h' => (int) data_get($digest, 'sections.canaries.ran_24h', 0),
                'failed_24h' => (int) data_get($digest, 'sections.canaries.failed_24h', 0),
                'latest_failed_count' => count((array) data_get($digest, 'sections.canaries.latest_failed', [])),
            ],
            'cost' => [
                'events_24h' => (int) data_get($digest, 'sections.cost.events_24h', 0),
                'measured_events_24h' => (int) data_get($digest, 'sections.cost.measured_events_24h', 0),
                'coverage_pct_24h' => (float) data_get($digest, 'sections.cost.coverage_pct_24h', 0.0),
                'total_cost_usd_24h' => (float) data_get($digest, 'sections.cost.total_cost_usd_24h', 0.0),
            ],
            'operator_review' => [
                'pending_parked_for_review' => (int) data_get($digest, 'sections.operator_review.pending_parked_for_review', 0),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function summarizeScorecard(array $report): array
    {
        return [
            'status' => array_key_exists('ok', $report) ? ((bool) $report['ok'] ? 'ok' : 'not_strict_ready') : (string) ($report['status'] ?? 'unknown'),
            'overall_score' => (float) data_get($report, 'report.score.overall_out_of_10', 0.0),
            'code_score' => (float) data_get($report, 'report.score.dimensions.code.score_out_of_10', 0.0),
            'doc_score' => (float) data_get($report, 'report.score.dimensions.doc.score_out_of_10', 0.0),
            'pipeline_score' => (float) data_get($report, 'report.score.dimensions.pipeline.score_out_of_10', 0.0),
            'benchmark_claim_allowed' => (bool) data_get($report, 'report.claim_policy.benchmark_claim_allowed', false),
            'subsystem_count' => (int) data_get($report, 'report.subsystem_count', 0),
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function summarizeDeltaReport(array $report): array
    {
        return [
            'status' => (string) ($report['status'] ?? 'unknown'),
            'scorecard_overall' => (float) data_get($report, 'today.metrics.scorecard_overall', 0.0),
            'pipeline_score' => (float) (
                data_get($report, 'today.metrics.pipeline_score')
                ?? data_get($report, 'scorecard.pipeline')
                ?? 0.0
            ),
            'merged_to_main' => (int) data_get($report, 'today.metrics.loop_proposals_merged_to_main', 0),
            'impact_receipt_coverage_pct' => (float) data_get($report, 'today.metrics.loop_impact_receipt_coverage_pct', 0.0),
            'semantic_recall_real' => (bool) data_get($report, 'today.metrics.semantic_recall_real', false),
        ];
    }

    /**
     * @param  array<string,mixed>  $feed
     * @return array<string,mixed>
     */
    private function summarizeBacklogFeed(array $feed): array
    {
        return [
            'status' => (string) ($feed['status'] ?? 'unknown'),
            'candidate_count' => (int) ($feed['candidate_count'] ?? 0),
            'actions_count' => (int) ($feed['actions_count'] ?? 0),
            'writes_enabled' => (bool) ($feed['writes_enabled'] ?? false),
            'source_counts' => is_array($feed['source_counts'] ?? null) ? $feed['source_counts'] : [],
        ];
    }

    /**
     * @param  array<string,mixed>  $capture
     * @return array<string,mixed>
     */
    private function summarizeFinalCapture(array $capture): array
    {
        return [
            'status' => (string) ($capture['status'] ?? 'unknown'),
            'final_report_status' => (string) (data_get($capture, 'final_report.status') ?? 'unknown'),
            'final_report_claim_status' => (string) (data_get($capture, 'final_report.report_status') ?? data_get($capture, '_loaded_final_report_summary.status') ?? 'unknown'),
            'l4_10_real_execution_claim_allowed' => data_get($capture, '_loaded_final_report_summary.l4_10_real_execution_claim_allowed'),
            'forge_l4_10_status' => data_get($capture, '_loaded_final_report_summary.forge_l4_10_status'),
            'cold_session_recoverable' => (bool) data_get($capture, 'cold_session_recovery.recoverable_from_recorded_artifacts_only', false),
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function summarizeWeeklyReport(array $report): array
    {
        return [
            'status' => (string) ($report['status'] ?? 'unknown'),
            'path' => $report['path'] ?? data_get($report, 'written_report_path'),
            'readable_in_two_minutes' => (bool) data_get($report, 'window.readable_in_two_minutes', false),
            'word_count' => (int) data_get($report, 'window.word_count', 0),
            'feed_status' => (string) data_get($report, 'agenda_feed.feed_status', 'unknown'),
            'agenda_item_count' => (int) data_get($report, 'agenda_feed.agenda_item_count', 0),
            'operator_approval_required' => (bool) data_get($report, 'agenda_feed.operator_approval_required', true),
        ];
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function summarizeCreatedProposal(array $item): array
    {
        return [
            'proposal_id' => $item['proposal_id'] ?? null,
            'status' => $item['status'] ?? null,
            'title' => $item['title'] ?? null,
            'proposal_packet_status' => data_get($item, 'proposal_packet.status'),
            'priority_score' => $item['priority_score'] ?? null,
            'next_safe_action' => $item['next_safe_action'] ?? null,
            'linked_obra_id' => $item['linked_obra_id'] ?? null,
            'external_provider_call' => (bool) ($item['external_provider_call'] ?? false),
            'provider_tokens_spent' => (bool) ($item['provider_tokens_spent'] ?? false),
            'auto_fast_path_executed' => (bool) ($item['auto_fast_path_executed'] ?? false),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function arrayOptionOr(array $options, string $key, callable $fallback): array
    {
        $value = $options[$key] ?? null;
        if (is_array($value)) {
            return $value;
        }

        try {
            $fallbackValue = $fallback();

            return is_array($fallbackValue) ? $fallbackValue : [];
        } catch (Throwable $e) {
            return [
                'status' => 'unavailable',
                'reason' => $key.'_fallback_failed',
                'error' => mb_substr($e->getMessage(), 0, 160),
            ];
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
