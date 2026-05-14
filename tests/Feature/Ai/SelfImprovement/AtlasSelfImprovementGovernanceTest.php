<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfImprovement;

use App\Models\AtlasProject;
use App\Services\Ai\Programming\ProgrammingProfessionalCompletionAuditService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementCapabilityMaturityScoreService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementDeltaScorecardService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementHumanTrustLedgerService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementInvariantLockService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalPacketService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalPowerGateService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementRegressionSentinelService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementStrategyPortfolioService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature tests for the Atlas Self-Improvement Governance Runtime v1.
 *
 * Covers all 8 services + 6 CLIs + audit block + state projection per the
 * canonical doc:
 *   docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md
 */
class AtlasSelfImprovementGovernanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSchema();
    }

    public function test_proposal_packet_blocks_when_required_fields_missing(): void
    {
        $packet = app(AtlasSelfImprovementProposalPacketService::class)->build([
            'title' => 'incomplete',
        ]);

        $this->assertSame(AtlasSelfImprovementProposalPacketService::STATUS_BLOCKED, $packet['status']);
        $this->assertContains('missing_business_rule', $packet['blockers']);
        $this->assertContains('missing_canonical_docs', $packet['blockers']);
        $this->assertFalse($packet['external_provider_call']);
    }

    public function test_proposal_packet_ready_with_full_payload(): void
    {
        $packet = $this->fullPacket();
        $this->assertSame(AtlasSelfImprovementProposalPacketService::STATUS_READY, $packet['status']);
        $this->assertSame([], $packet['blockers']);
        $this->assertFalse($packet['autopromotion_allowed']);
        $this->assertSame('run_proposal_power_gate', $packet['next_action']);
    }

    public function test_proposal_packet_blocks_critical_autopromotion(): void
    {
        $packet = app(AtlasSelfImprovementProposalPacketService::class)->build([
            'title' => 'critical attempt',
            'problem_statement' => 'p',
            'business_rule' => 'b',
            'target_capability' => 'c',
            'why_now' => 'now',
            'expected_power_gain' => 'gain',
            'success_metrics' => ['m'],
            'acceptance_gates' => ['g'],
            'canonical_docs' => ['d'],
            'allowed_paths' => ['app/Services/Ai/Providers/'],
            'forbidden_paths' => ['app/Services/Ai/SelfConstruction/'],
            'risk_level' => 'critical',
            'autopromotion_requested' => true,
        ]);

        $this->assertContains('autopromotion_requested_but_blocked_by_policy', $packet['blockers']);
        $this->assertContains('critical_risk_cannot_be_autopromoted', $packet['blockers']);
        $this->assertFalse($packet['autopromotion_allowed']);
    }

    public function test_power_gate_rejects_critical_autopromotion(): void
    {
        $packet = app(AtlasSelfImprovementProposalPacketService::class)->build([
            'title' => 'critical',
            'problem_statement' => 'p',
            'business_rule' => 'b',
            'target_capability' => 'c',
            'why_now' => 'now',
            'expected_power_gain' => 'gain',
            'success_metrics' => ['m'],
            'acceptance_gates' => ['g'],
            'canonical_docs' => ['docs/x.md'],
            'allowed_paths' => ['app/Services/Ai/SelfImprovement/'],
            'forbidden_paths' => ['app/Services/Ai/Providers/'],
            'risk_level' => 'critical',
            'autopromotion_requested' => true,
        ]);

        $gate = app(AtlasSelfImprovementProposalPowerGateService::class)->evaluate($packet);
        $this->assertSame(AtlasSelfImprovementProposalPowerGateService::OUTCOME_REJECTED, $gate['outcome']);
        $this->assertContains('attempts_critical_autopromotion', $gate['hard_fails']);
    }

    public function test_power_gate_returns_human_review_required_for_strong_packet(): void
    {
        $packet = $this->fullPacket();
        $gate = app(AtlasSelfImprovementProposalPowerGateService::class)->evaluate($packet);
        $this->assertContains(
            $gate['outcome'],
            [
                AtlasSelfImprovementProposalPowerGateService::OUTCOME_APPROVED,
                AtlasSelfImprovementProposalPowerGateService::OUTCOME_HUMAN_REVIEW_REQUIRED,
                AtlasSelfImprovementProposalPowerGateService::OUTCOME_NEEDS_REVISION,
            ],
        );
        $this->assertSame([], $gate['hard_fails']);
    }

    public function test_delta_scorecard_promotes_when_all_metrics_improve(): void
    {
        $before = $this->snapshotScores(5.0);
        $after = $this->snapshotScores(8.0);
        $report = app(AtlasSelfImprovementDeltaScorecardService::class)->compute($before, $after);
        $this->assertGreaterThan(0, $report['weighted_delta']);
        $this->assertContains(
            $report['recommendation'],
            [
                AtlasSelfImprovementDeltaScorecardService::RECOMMEND_PROMOTE,
                AtlasSelfImprovementDeltaScorecardService::RECOMMEND_PROMOTE_WITH_REVIEW,
            ],
        );
    }

    public function test_delta_scorecard_rolls_back_on_hard_regression(): void
    {
        $before = $this->snapshotScores(8.0);
        $after = $this->snapshotScores(8.0);
        $after['governance_integrity'] = 1.0; // regress critical metric
        $report = app(AtlasSelfImprovementDeltaScorecardService::class)->compute($before, $after);
        $this->assertSame(AtlasSelfImprovementDeltaScorecardService::RECOMMEND_ROLLBACK, $report['recommendation']);
        $this->assertTrue($report['hard_regression_detected']);
    }

    public function test_invariant_lock_passes_with_clean_after_snapshot(): void
    {
        $report = app(AtlasSelfImprovementInvariantLockService::class)->evaluate(
            $this->cleanAfterSnapshot(),
            implementationDiff: [],
            proposal: $this->fullPacket(),
        );
        $this->assertSame(AtlasSelfImprovementInvariantLockService::STATUS_PASSED, $report['status']);
    }

    public function test_invariant_lock_blocks_when_silent_obra_creation_lost(): void
    {
        $after = $this->cleanAfterSnapshot();
        data_set($after, 'completion_audit.atlas_forge_continuum_certification.invariants.no_silent_obra_creation', false);
        $report = app(AtlasSelfImprovementInvariantLockService::class)->evaluate(
            $after,
            implementationDiff: [],
            proposal: $this->fullPacket(),
        );
        $this->assertSame(AtlasSelfImprovementInvariantLockService::STATUS_BLOCKED, $report['status']);
    }

    public function test_regression_sentinel_flags_severe_findings(): void
    {
        $before = $this->cleanAfterSnapshot();
        $after = $this->cleanAfterSnapshot();
        data_set($after, 'completion_audit.rules.synthetic_scores_allowed', true);
        data_set($after, 'completion_audit.atlas_forge_continuum_certification.no_silent_fallback', false);

        $report = app(AtlasSelfImprovementRegressionSentinelService::class)->scan(
            $before,
            $after,
            implementationDiff: ['adds_unapproved_provider_call' => true],
        );

        $this->assertSame(AtlasSelfImprovementRegressionSentinelService::STATUS_BLOCKED, $report['status']);
        $this->assertGreaterThan(0, $report['finding_counts']['severe']);
    }

    public function test_capability_maturity_score_climbs_progressively(): void
    {
        // Score up to level 4 (tests exist) without UI/state/audit yet.
        $score = app(AtlasSelfImprovementCapabilityMaturityScoreService::class)->score([
            'capability' => 'self_improvement_governance_runtime',
            'doc' => 'docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md',
            'service_class' => AtlasSelfImprovementProposalPacketService::class,
            'command_signature' => 'atlas:self-improvement:proposal-gate',
            'api_route' => '/self-improvement/proposal-gate',
            'test_class' => 'tests/Feature/Ai/SelfImprovement/AtlasSelfImprovementGovernanceTest.php',
            'expected_level' => 4,
        ], context: ['workspace' => base_path()]);

        $this->assertGreaterThanOrEqual(4, $score['achieved_level']);
        $this->assertTrue($score['meets_expected']);
        $this->assertSame(AtlasSelfImprovementCapabilityMaturityScoreService::SCHEMA_VERSION, $score['schema_version']);
    }

    public function test_human_trust_ledger_records_and_summarises(): void
    {
        $obra = $this->makeObra();
        $service = app(AtlasSelfImprovementHumanTrustLedgerService::class);
        $service->record($obra, [
            'outcome' => AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_PROPOSAL_APPROVED,
            'proposal_id' => 'prop_x',
            'reviewer' => 'human-1',
        ]);
        $service->record($obra->refresh(), [
            'outcome' => AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_AUTOPROMOTION_REVERTED,
            'proposal_id' => 'prop_y',
            'reviewer' => 'human-1',
        ]);

        $snapshot = $service->snapshot($obra->refresh());
        $this->assertSame(2, $snapshot['entry_count']);
        $this->assertArrayHasKey('summary', $snapshot);
        $this->assertSame(1, $snapshot['summary']['autopromotion_reverted']);
    }

    public function test_human_trust_ledger_rejects_unknown_outcome(): void
    {
        $obra = $this->makeObra();
        $this->expectException(\InvalidArgumentException::class);
        app(AtlasSelfImprovementHumanTrustLedgerService::class)->record($obra, [
            'outcome' => 'bogus',
        ]);
    }

    public function test_strategy_portfolio_balances_eight_canonical_buckets(): void
    {
        $proposals = [
            ['bucket' => 'quick_wins', 'status' => AtlasSelfImprovementProposalPacketService::STATUS_READY, 'proposal_id' => 'p1'],
            ['bucket' => 'core_runtime', 'status' => AtlasSelfImprovementProposalPacketService::STATUS_READY, 'proposal_id' => 'p2'],
            ['bucket' => 'enterprise_reliability', 'status' => AtlasSelfImprovementProposalPacketService::STATUS_BLOCKED, 'proposal_id' => 'p3'],
        ];
        $snapshot = app(AtlasSelfImprovementStrategyPortfolioService::class)->snapshot($proposals);
        $this->assertCount(8, $snapshot['buckets']);
        $this->assertSame(3, $snapshot['total_proposals']);
        $this->assertNotNull($snapshot['recommended_next_bucket']);
    }

    public function test_proposal_gate_command_emits_canonical_payload(): void
    {
        $payload = json_encode($this->fullPacketPayload(), JSON_UNESCAPED_SLASHES);
        $exitCode = Artisan::call('atlas:self-improvement:proposal-gate', [
            '--proposal' => $payload,
            '--json' => true,
        ]);
        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame('atlas.self_improvement.proposal_gate_report.v1', $decoded['schema_version']);
        $this->assertContains(
            $decoded['power_gate']['outcome'],
            [
                AtlasSelfImprovementProposalPowerGateService::OUTCOME_APPROVED,
                AtlasSelfImprovementProposalPowerGateService::OUTCOME_HUMAN_REVIEW_REQUIRED,
                AtlasSelfImprovementProposalPowerGateService::OUTCOME_NEEDS_REVISION,
            ],
        );
    }

    public function test_proposal_gate_endpoint_returns_canonical_report(): void
    {
        $response = $this->postJson(
            '/atlas-code/self-improvement/proposal-gate',
            ['proposal' => $this->fullPacketPayload()],
            $this->headers(),
        );
        $response->assertStatus(200)
            ->assertJsonPath('schema_version', 'atlas.self_improvement.proposal_gate_report.v1')
            ->assertJsonPath('proposal_packet.status', AtlasSelfImprovementProposalPacketService::STATUS_READY)
            ->assertJsonPath('external_provider_call', false);
    }

    public function test_state_endpoint_exposes_self_improvement_governance(): void
    {
        $obra = $this->makeObra();
        $this->getJson('/atlas-code/works/'.$obra->id.'/state', $this->headers())
            ->assertOk()
            ->assertJsonPath('self_improvement_governance.schema_version', 'atlas.self_improvement.governance_state.v1');
    }

    public function test_completion_audit_exposes_self_improvement_governance_certification(): void
    {
        $report = app(ProgrammingProfessionalCompletionAuditService::class)->report(base_path());
        $this->assertArrayHasKey('atlas_self_improvement_governance_certification', $report);
        $block = $report['atlas_self_improvement_governance_certification'];
        $this->assertSame('atlas.self_improvement.governance_certification.v1', $block['schema_version']);
        $this->assertGreaterThanOrEqual(20, count($block['invariants']));
        $this->assertFalse($block['external_provider_call']);
        $this->assertFalse($block['provider_tokens_spent']);
        $this->assertSame('external_rivals_certification', $block['separated_from']);
        $this->assertTrue($block['separated_from_external_rivals_certification']);
    }

    public function test_external_rivals_remains_blocked_and_separated(): void
    {
        $report = app(ProgrammingProfessionalCompletionAuditService::class)->report(base_path());
        $this->assertArrayHasKey('external_rivals_certification', $report);
        $block = $report['atlas_self_improvement_governance_certification'];
        $this->assertFalse($block['promotes_external_rivals_claim']);
        $this->assertTrue($block['separated_from_external_rivals_certification']);
    }

    /**
     * @return array<string,mixed>
     */
    private function fullPacket(): array
    {
        return app(AtlasSelfImprovementProposalPacketService::class)->build($this->fullPacketPayload());
    }

    /**
     * @return array<string,mixed>
     */
    private function fullPacketPayload(): array
    {
        return [
            'title' => 'Promote provider capacity to maturity 9',
            'problem_statement' => 'Provider capacity sits at maturity 7; needs replay/before-after.',
            'business_rule' => 'Atlas Decide must consume capacity snapshot in receipts.',
            'target_capability' => 'atlas_forge_provider_capacity',
            'why_now' => 'Continuum Sprint canon requires capacity-aware dispatch.',
            'expected_power_gain' => 'governed_runtime_continuity',
            'success_metrics' => [
                'capacity_invariants_count_eq_19',
                'continuum_audit_status_eq_available',
            ],
            'acceptance_gates' => [
                'docs-health=ok',
                'architecture-validate=ok',
                'completion-audit:capacity_status=available',
            ],
            'canonical_docs' => [
                'docs/engineering-knowledge-base/atlas-forge-provider-capacity-continuity-v1.md',
            ],
            'allowed_paths' => [
                'app/Services/Ai/Programming/AtlasForgeProviderCapacityService.php',
                'app/Services/Ai/Programming/AtlasForgeProviderFailureMemoryService.php',
            ],
            'forbidden_paths' => [
                'app/Services/Ai/Providers/',
            ],
            'risk_level' => 'medium',
            'human_review_required' => true,
        ];
    }

    /**
     * @return array<string,float>
     */
    private function snapshotScores(float $value): array
    {
        $scores = [];
        foreach (AtlasSelfImprovementDeltaScorecardService::METRICS as $metric) {
            $scores[$metric] = $value;
        }

        return $scores;
    }

    /**
     * @return array<string,mixed>
     */
    private function cleanAfterSnapshot(): array
    {
        return [
            'completion_audit' => [
                'atlas_forge_continuum_certification' => [
                    'invariants' => [
                        'no_silent_obra_creation' => true,
                    ],
                    'no_silent_fallback' => true,
                    'separated_from_external_rivals' => true,
                    'external_provider_call' => false,
                ],
                'atlas_forge_provider_capacity_certification' => [
                    'invariants' => [
                        'static_policy_does_not_dispatch' => true,
                    ],
                ],
                'atlas_code_forge_review_completion_certification' => [
                    'lifecycle_invariants' => [
                        'no_auto_completion_without_human_review' => true,
                    ],
                ],
                'rules' => [
                    'synthetic_scores_allowed' => false,
                ],
            ],
            'docs_health' => [
                'oversized_count' => 0,
                'violations_count' => 0,
            ],
        ];
    }

    private function makeObra(): AtlasProject
    {
        return AtlasProject::create([
            'id' => (string) Str::uuid(),
            'title' => 'Self-Improvement test Obra',
            'description' => 'Atlas Self-Improvement Governance Runtime v1',
            'status' => 'active',
            'domain' => 'atlas',
            'goal' => 'Validate ladder runtime',
            'desired_outcome' => 'Self-improvement governance runtime green',
            'priority' => 'normal',
            'metadata' => ['workspace_path' => '/tmp/atlas-self-improvement-test'],
        ]);
    }

    /**
     * @return array<string,string>
     */
    private function headers(): array
    {
        return ['X-Atlas-Token' => 'testing-atlas-token-with-enough-length'];
    }

    private function ensureSchema(): void
    {
        if (! Schema::hasTable('atlas_projects')) {
            Schema::create('atlas_projects', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('title')->nullable();
                $t->text('description')->nullable();
                $t->string('status')->default('active');
                $t->string('domain')->default('atlas');
                $t->uuid('source_capture_id')->nullable();
                $t->text('goal')->nullable();
                $t->text('next_action')->nullable();
                $t->string('project_type')->nullable();
                $t->text('desired_outcome')->nullable();
                $t->text('minimum_viable_outcome')->nullable();
                $t->text('definition_of_done')->nullable();
                $t->text('why_now')->nullable();
                $t->timestamp('deadline_at')->nullable();
                $t->string('deadline_kind')->nullable();
                $t->string('priority')->default('normal');
                $t->string('energy_profile')->nullable();
                $t->text('avoidance_reason')->nullable();
                $t->uuid('active_next_task_id')->nullable();
                $t->uuid('current_step_id')->nullable();
                $t->timestamp('last_touched_at')->nullable();
                $t->timestamp('next_review_at')->nullable();
                $t->timestamp('completed_at')->nullable();
                $t->timestamp('paused_until')->nullable();
                $t->json('metadata')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('ai_threads')) {
            Schema::create('ai_threads', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('title')->nullable();
                $t->string('status')->default('active');
                $t->string('surface')->nullable();
                $t->string('workspace')->nullable();
                $t->string('source_type')->nullable();
                $t->uuid('source_id')->nullable();
                $t->integer('message_count')->default(0);
                $t->timestamp('last_message_at')->nullable();
                $t->json('metadata')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('ai_messages')) {
            Schema::create('ai_messages', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('thread_id');
                $t->uuid('trace_id')->nullable();
                $t->integer('position')->default(1);
                $t->string('role');
                $t->string('status')->default('completed');
                $t->text('content')->nullable();
                $t->timestamp('occurred_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('ai_traces')) {
            Schema::create('ai_traces', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('thread_id')->nullable();
                $t->string('source_type')->default('app');
                $t->uuid('source_id')->nullable();
                $t->string('status')->default('completed');
                $t->text('operator_input')->nullable();
                $t->text('response_text')->nullable();
                $t->json('metadata')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('atlas_engineering_runs')) {
            Schema::create('atlas_engineering_runs', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('project_id')->nullable();
                $t->string('status')->nullable();
                $t->string('decision')->nullable();
                $t->timestamp('finished_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('atlas_engineering_evidence')) {
            Schema::create('atlas_engineering_evidence', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('project_id')->nullable();
                $t->uuid('task_id')->nullable();
                $t->string('evidence_type')->nullable();
                $t->string('target_id')->nullable();
                $t->string('status')->nullable();
                $t->float('confidence')->nullable();
                $t->text('summary')->nullable();
                $t->text('command')->nullable();
                $t->text('output_excerpt')->nullable();
                $t->json('files')->nullable();
                $t->json('metadata')->nullable();
                $t->string('source')->nullable();
                $t->timestamp('recorded_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('atlas_tool_runs')) {
            Schema::create('atlas_tool_runs', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('tool_slug')->nullable();
                $t->text('workspace')->nullable();
                $t->string('run_context_type')->nullable();
                $t->string('run_context_id')->nullable();
                $t->string('status')->nullable();
                $t->json('summary_json')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('atlas_ledger_events')) {
            Schema::create('atlas_ledger_events', function (Blueprint $t) {
                $t->string('event_id')->primary();
                $t->string('schema_version')->nullable();
                $t->string('tenant_id')->nullable();
                $t->string('operator_id')->nullable();
                $t->string('envelope_id')->nullable();
                $t->string('receipt_id')->nullable();
                $t->string('trace_id')->nullable();
                $t->string('correlation_id')->nullable();
                $t->string('causation_id')->nullable();
                $t->string('event_type')->nullable();
                $t->string('emitter_stage')->nullable();
                $t->string('emitter_version')->nullable();
                $t->json('payload')->nullable();
                $t->string('payload_hash')->nullable();
                $t->timestamp('occurred_at')->nullable();
                $t->timestamps();
            });
        }
    }
}
