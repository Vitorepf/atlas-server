<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfImprovement;

use App\Models\AtlasProject;
use App\Services\Ai\Programming\ProgrammingProfessionalCompletionAuditService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementClosedLoopService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementForgeActivationService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementHumanTrustLedgerService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementNextCycleRecommendationService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementResultLedgerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AtlasSelfImprovementClosedLoopLevel7Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSchema();
        Storage::fake('local');
    }

    public function test_backlog_create_persists_and_does_not_create_obra(): void
    {
        $count = AtlasProject::query()->count();
        $service = app(AtlasSelfImprovementProposalBacklogService::class);
        $item = $service->createProposal([
            'proposal' => $this->strongProposal(),
            'source' => 'operator',
        ]);

        $this->assertSame(AtlasSelfImprovementProposalBacklogService::ITEM_SCHEMA_VERSION, $item['schema_version']);
        $this->assertSame(AtlasSelfImprovementProposalBacklogService::STATUS_DRAFT, $item['status']);
        $this->assertStringStartsWith('prop_', $item['proposal_id']);
        $this->assertSame($count, AtlasProject::query()->count(), 'Backlog must not create an Obra');
        $this->assertFalse($item['external_provider_call']);
        $this->assertFalse($item['auto_fast_path_executed']);
        $this->assertSame('external_rivals_certification', $item['separated_from']);
    }

    public function test_evaluate_proposal_calls_power_gate_and_updates_status(): void
    {
        $service = app(AtlasSelfImprovementProposalBacklogService::class);
        $item = $service->createProposal([
            'proposal' => $this->strongProposal(),
            'source' => 'operator',
        ]);

        $evaluated = $service->evaluateProposal($item['proposal_id']);
        $this->assertNotNull($evaluated['power_gate']);
        $this->assertContains((string) $evaluated['power_gate']['outcome'], [
            'approved',
            'needs_revision',
            'rejected',
            'human_review_required',
        ]);
        // Strong proposal expects human_review_required.
        $this->assertSame(AtlasSelfImprovementProposalBacklogService::STATUS_PENDING_HUMAN_REVIEW, $evaluated['status']);
    }

    public function test_rejected_gate_blocks_activation_link_path(): void
    {
        $service = app(AtlasSelfImprovementProposalBacklogService::class);
        $item = $service->createProposal([
            'proposal' => $this->criticalAutopromotionProposal(),
            'source' => 'operator',
        ]);
        $evaluated = $service->evaluateProposal($item['proposal_id']);
        $this->assertSame(AtlasSelfImprovementProposalBacklogService::STATUS_REJECTED, $evaluated['status']);
        // closed loop must report the rejection honestly.
        $loop = app(AtlasSelfImprovementClosedLoopService::class)->project($item['proposal_id']);
        $this->assertSame('blocked', $loop['stages'][AtlasSelfImprovementClosedLoopService::STAGE_POWER_GATE_EVALUATED]['status']);
    }

    public function test_prioritize_sets_strategy_bucket_and_priority_score(): void
    {
        $service = app(AtlasSelfImprovementProposalBacklogService::class);
        $item = $service->createProposal([
            'proposal' => $this->strongProposal(),
            'source' => 'operator',
        ]);
        $prioritized = $service->prioritize($item['proposal_id']);
        $this->assertNotNull($prioritized['strategy_bucket']);
        $this->assertIsNumeric($prioritized['priority_score']);
        $this->assertSame('proposal_priority_decision.v1', substr((string) $prioritized['priority_decision']['schema_version'], -29));
        $this->assertTrue($prioritized['priority_decision']['human_approval_required']);
        $this->assertFalse($prioritized['priority_decision']['auto_activation_allowed']);
    }

    public function test_closed_loop_projects_stages_for_draft_proposal(): void
    {
        $service = app(AtlasSelfImprovementProposalBacklogService::class);
        $item = $service->createProposal([
            'proposal' => $this->strongProposal(),
            'source' => 'operator',
        ]);
        $loop = app(AtlasSelfImprovementClosedLoopService::class)->project($item['proposal_id']);
        $this->assertSame(AtlasSelfImprovementClosedLoopService::SCHEMA_VERSION, $loop['schema_version']);
        $this->assertSame('done', $loop['stages'][AtlasSelfImprovementClosedLoopService::STAGE_PROPOSAL_CAPTURED]['status']);
        $this->assertSame('todo', $loop['stages'][AtlasSelfImprovementClosedLoopService::STAGE_OBRA_CREATED]['status']);
        $this->assertSame('draft', $loop['loop_health']);
        $this->assertFalse($loop['can_activate']);
        $this->assertFalse($loop['can_open_obra']);
        $this->assertFalse($loop['can_measure_delta']);
    }

    public function test_closed_loop_unknown_proposal_returns_404_shape(): void
    {
        $loop = app(AtlasSelfImprovementClosedLoopService::class)->project('prop_does_not_exist');
        $this->assertSame('blocked', $loop['status']);
        $this->assertContains('proposal_not_found', $loop['blockers']);
    }

    public function test_measure_result_requires_required_fields(): void
    {
        $ledger = app(AtlasSelfImprovementResultLedgerService::class);
        $entry = $ledger->record([]);
        $this->assertSame('blocked', $entry['status']);
        $this->assertContains('proposal_id_required', $entry['blockers']);
        $this->assertContains('obra_id_required', $entry['blockers']);
    }

    public function test_measure_result_records_entry_and_updates_trust_ledger(): void
    {
        $service = app(AtlasSelfImprovementForgeActivationService::class);
        $plan = $service->plan(['proposal' => $this->strongProposal()]);
        $accepted = $service->accept($plan['activation_id'], [
            'reviewer' => 'measure-test',
            'reason' => 'measure setup',
        ]);
        $this->assertNotNull($accepted['created_obra_id']);

        $ledger = app(AtlasSelfImprovementResultLedgerService::class);
        $entry = $ledger->record([
            'proposal_id' => 'prop_synthetic_'.uniqid(),
            'obra_id' => $accepted['created_obra_id'],
            'before_snapshot' => $this->canonicalSnapshot(7.0),
            'after_snapshot' => $this->canonicalSnapshot(9.0),
            'reviewer' => 'measure-test',
            'reason' => 'closed-loop test major improvement',
            'context' => [
                'evidence_refs' => ['doc:fixture@hash1', 'doc:fixture2@hash2', 'doc:fixture3@hash3', 'doc:fixture4@hash4'],
                'what_changed' => 'normalized scoring',
                'why_it_mattered' => 'better evidence baseline',
                'proposal_packet' => $plan['proposal_packet'],
            ],
        ]);

        $this->assertSame(AtlasSelfImprovementResultLedgerService::ENTRY_SCHEMA_VERSION, $entry['schema_version']);
        $this->assertNotEmpty($entry['result_entry_id']);
        $this->assertContains($entry['delta_grade'], [
            AtlasSelfImprovementResultLedgerService::GRADE_MAJOR_IMPROVEMENT,
            AtlasSelfImprovementResultLedgerService::GRADE_IMPROVED,
        ]);
        $this->assertNotNull($entry['delta_scorecard']);
        $this->assertIsArray($entry['learning_packet']);
        $this->assertFalse($entry['completion_claim_promoted']);
        $this->assertFalse($entry['auto_fast_path_executed']);
        $this->assertSame('external_rivals_certification', $entry['separated_from']);
        $this->assertContains($entry['trust_outcome_recorded'], [
            AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_SELF_IMPROVEMENT_MAJOR_IMPROVEMENT,
            AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_SELF_IMPROVEMENT_IMPROVED,
        ]);
    }

    public function test_invalid_evidence_records_invalid_grade(): void
    {
        $service = app(AtlasSelfImprovementForgeActivationService::class);
        $plan = $service->plan(['proposal' => $this->strongProposal()]);
        $accepted = $service->accept($plan['activation_id'], [
            'reviewer' => 'invalid-test',
            'reason' => 'invalid-evidence setup',
        ]);

        $entry = app(AtlasSelfImprovementResultLedgerService::class)->record([
            'proposal_id' => 'prop_invalid_'.uniqid(),
            'obra_id' => $accepted['created_obra_id'],
            'before_snapshot' => $this->canonicalSnapshot(7.0),
            'after_snapshot' => $this->canonicalSnapshot(7.5),
            'reviewer' => 'invalid-test',
            'reason' => 'no evidence provided',
            'context' => [], // empty refs → invalid
        ]);
        $this->assertSame(AtlasSelfImprovementResultLedgerService::GRADE_INVALID, $entry['delta_grade']);
        $this->assertSame(
            AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_SELF_IMPROVEMENT_INVALID_EVIDENCE,
            $entry['trust_outcome_recorded']
        );
        $this->assertFalse($entry['should_become_rule']);
    }

    public function test_regressed_grade_blocks_learning_promotion(): void
    {
        $service = app(AtlasSelfImprovementForgeActivationService::class);
        $plan = $service->plan(['proposal' => $this->strongProposal()]);
        $accepted = $service->accept($plan['activation_id'], [
            'reviewer' => 'reg-test',
            'reason' => 'regression setup',
        ]);

        $entry = app(AtlasSelfImprovementResultLedgerService::class)->record([
            'proposal_id' => 'prop_reg_'.uniqid(),
            'obra_id' => $accepted['created_obra_id'],
            'before_snapshot' => $this->canonicalSnapshot(9.0),
            'after_snapshot' => $this->canonicalSnapshot(4.0),
            'reviewer' => 'reg-test',
            'reason' => 'regression measurement',
            'context' => [
                'evidence_refs' => ['doc:fixture@hash1', 'doc:fixture2@hash2'],
            ],
        ]);
        $this->assertSame(AtlasSelfImprovementResultLedgerService::GRADE_REGRESSED, $entry['delta_grade']);
        $this->assertSame(
            AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_SELF_IMPROVEMENT_REGRESSED,
            $entry['trust_outcome_recorded']
        );
        $this->assertFalse($entry['should_become_rule']);
    }

    public function test_next_cycle_recommendation_never_creates_proposal(): void
    {
        $svc = app(AtlasSelfImprovementNextCycleRecommendationService::class);
        $rec = $svc->recommend(null, null);
        $this->assertSame(AtlasSelfImprovementNextCycleRecommendationService::SCHEMA_VERSION, $rec['schema_version']);
        $this->assertNull($rec['recommendation']);
        $this->assertTrue($rec['human_approval_required']);
        $this->assertFalse($rec['auto_activation_allowed']);
    }

    public function test_next_cycle_recommendation_for_major_improvement_suggests_broaden_scope(): void
    {
        $svc = app(AtlasSelfImprovementNextCycleRecommendationService::class);
        $entry = [
            'result_entry_id' => 'res_test',
            'proposal_id' => 'prop_test',
            'obra_id' => 'obra_test',
            'delta_grade' => AtlasSelfImprovementResultLedgerService::GRADE_MAJOR_IMPROVEMENT,
            'should_become_rule' => false,
            'learning_packet' => ['confidence' => 0.9],
        ];
        $rec = $svc->recommend($entry, ['title' => 'parent', 'target_capability' => 'cap']);
        $this->assertSame(
            AtlasSelfImprovementNextCycleRecommendationService::REC_BROADEN_SCOPE,
            $rec['recommendation']
        );
        $this->assertNotNull($rec['proposed_next_proposal_payload']);
        $this->assertFalse($rec['proposed_next_proposal_payload']['autopromotion_requested']);
    }

    public function test_command_center_exposes_self_improvement_origin_for_si_obra(): void
    {
        $service = app(AtlasSelfImprovementForgeActivationService::class);
        $plan = $service->plan(['proposal' => $this->strongProposal()]);
        $accepted = $service->accept($plan['activation_id'], [
            'reviewer' => 'cc-test',
            'reason' => 'command-center origin smoke',
        ]);
        $this->assertNotNull($accepted['created_obra_id']);

        $snap = app(\App\Services\Ai\Programming\AtlasCodeObraCommandCenterService::class)
            ->snapshot(['obra_id' => $accepted['created_obra_id']]);
        $this->assertArrayHasKey('self_improvement_origin', $snap);
        $this->assertNotNull($snap['self_improvement_origin']);
        $this->assertSame($plan['activation_id'], $snap['self_improvement_origin']['activation_id']);
        $this->assertStringContainsString('Esta Obra veio de Self-Improvement', $snap['self_improvement_origin']['human_message']);
        $this->assertTrue($snap['self_improvement_origin']['measure_result_action']['enabled']);
    }

    public function test_command_center_self_improvement_origin_is_null_for_manual_obra(): void
    {
        $project = AtlasProject::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'title' => 'manual obra',
            'description' => 'manual',
            'status' => 'active',
            'domain' => 'programming',
            'priority' => 'normal',
            'metadata' => [
                'origin' => 'manual',
            ],
        ]);
        $snap = app(\App\Services\Ai\Programming\AtlasCodeObraCommandCenterService::class)
            ->snapshot(['obra_id' => $project->getKey()]);
        $this->assertArrayHasKey('self_improvement_origin', $snap);
        $this->assertNull($snap['self_improvement_origin']);
    }

    public function test_api_proposals_index_returns_canonical_schema(): void
    {
        $response = $this->getJson(
            '/atlas-code/self-improvement/proposals',
            $this->headers(),
        );
        $response->assertOk()
            ->assertJsonPath('schema_version', AtlasSelfImprovementProposalBacklogService::SCHEMA_VERSION)
            ->assertJsonPath('is_read_model', true)
            ->assertJsonPath('external_provider_call', false)
            ->assertJsonPath('separated_from', 'external_rivals_certification');
    }

    public function test_api_proposals_create_persists_without_creating_obra(): void
    {
        $count = AtlasProject::query()->count();
        $response = $this->postJson(
            '/atlas-code/self-improvement/proposals',
            ['proposal' => $this->strongProposal(), 'source' => 'operator'],
            $this->headers(),
        );
        $response->assertStatus(201);
        $this->assertSame($count, AtlasProject::query()->count());
    }

    public function test_cli_proposal_backlog_strict_fails_when_empty(): void
    {
        $exit = Artisan::call('atlas:self-improvement:proposal-backlog', ['--json' => true, '--strict' => true]);
        $this->assertSame(1, $exit);
    }

    public function test_cli_closed_loop_strict_fails_for_unknown_proposal(): void
    {
        $exit = Artisan::call('atlas:self-improvement:closed-loop', [
            '--proposal' => 'prop_does_not_exist',
            '--json' => true,
            '--strict' => true,
        ]);
        $this->assertSame(1, $exit);
    }

    public function test_cli_measure_result_strict_fails_without_obra(): void
    {
        $exit = Artisan::call('atlas:self-improvement:measure-result', [
            '--proposal' => 'prop_x',
            '--json' => true,
            '--strict' => true,
        ]);
        $this->assertSame(1, $exit);
    }

    public function test_completion_audit_exposes_level7_certification(): void
    {
        $report = app(ProgrammingProfessionalCompletionAuditService::class)->report(base_path());
        $this->assertArrayHasKey('atlas_self_improvement_closed_loop_level7_certification', $report);
        $cert = $report['atlas_self_improvement_closed_loop_level7_certification'];
        $this->assertSame('atlas.self_improvement.closed_loop_level7_certification.v1', $cert['schema_version']);
        $this->assertGreaterThanOrEqual(30, count($cert['invariants']));
        $this->assertFalse($cert['external_provider_call']);
        $this->assertFalse($cert['auto_fast_path_executed']);
        $this->assertSame('external_rivals_certification', $cert['separated_from']);
        $this->assertTrue($cert['invariants']['trust_ledger_outcomes_extended']);
        $this->assertTrue($cert['invariants']['no_auto_activation']);
        $this->assertTrue($cert['invariants']['no_completion_claim_promotion']);
        $this->assertTrue($cert['invariants']['regressions_block_promotion']);
    }

    public function test_external_rivals_certification_remains_separated(): void
    {
        $report = app(ProgrammingProfessionalCompletionAuditService::class)->report(base_path());
        $this->assertArrayHasKey('external_rivals_certification', $report);
        $external = $report['external_rivals_certification'];
        $this->assertIsArray($external);
        // Closed loop level 7 cert must NEVER unlock this.
        $this->assertNotSame('available', (string) ($external['status'] ?? ''));
    }

    public function test_trust_ledger_outcomes_include_self_improvement_grades(): void
    {
        $this->assertContains(
            AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_SELF_IMPROVEMENT_MAJOR_IMPROVEMENT,
            AtlasSelfImprovementHumanTrustLedgerService::KNOWN_OUTCOMES,
        );
        $this->assertContains(
            AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_SELF_IMPROVEMENT_IMPROVED,
            AtlasSelfImprovementHumanTrustLedgerService::KNOWN_OUTCOMES,
        );
        $this->assertContains(
            AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_SELF_IMPROVEMENT_NEUTRAL,
            AtlasSelfImprovementHumanTrustLedgerService::KNOWN_OUTCOMES,
        );
        $this->assertContains(
            AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_SELF_IMPROVEMENT_REGRESSED,
            AtlasSelfImprovementHumanTrustLedgerService::KNOWN_OUTCOMES,
        );
        $this->assertContains(
            AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_SELF_IMPROVEMENT_INVALID_EVIDENCE,
            AtlasSelfImprovementHumanTrustLedgerService::KNOWN_OUTCOMES,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function strongProposal(): array
    {
        return [
            'title' => 'Closed Loop Level 7 coverage proposal',
            'problem_statement' => 'Backlog precisa registrar propostas antes de virarem activations.',
            'business_rule' => 'Atlas precisa fechar o loop proposal→learning sem auto-promoção.',
            'target_capability' => 'self_improvement_closed_loop_level7',
            'why_now' => 'Loop ainda não fecha; cockpit existe mas backlog não.',
            'expected_power_gain' => 'closed_loop_visibility_with_evidence',
            'success_metrics' => ['proposal_backlog_persistent', 'result_ledger_available'],
            'acceptance_gates' => [
                'docs-health=ok',
                'completion-audit:l7_status=available',
            ],
            'canonical_docs' => [
                'docs/engineering-knowledge-base/atlas-self-improvement-closed-loop-level7-v1.md',
                'docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md',
            ],
            'allowed_paths' => [
                'app/Services/Ai/SelfImprovement/',
            ],
            'forbidden_paths' => [
                'app/Services/Ai/Providers/',
            ],
            'risk_level' => 'medium',
            'human_review_required' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function criticalAutopromotionProposal(): array
    {
        return [
            'title' => 'critical autopromotion attempt',
            'problem_statement' => 'attempts to bypass policy',
            'business_rule' => 'b',
            'target_capability' => 'c',
            'why_now' => 'now',
            'expected_power_gain' => 'gain',
            'success_metrics' => ['m'],
            'acceptance_gates' => ['g'],
            'canonical_docs' => ['docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md'],
            'allowed_paths' => ['app/Services/Ai/Providers/'],
            'forbidden_paths' => ['app/Services/Ai/SelfConstruction/'],
            'risk_level' => 'critical',
            'autopromotion_requested' => true,
        ];
    }

    /**
     * Canonical snapshot used by `measure-result`: carries `metrics` for
     * the Delta Scorecard PLUS the `completion_audit` / `docs_health`
     * blocks expected by the Invariant Lock and Regression Sentinel
     * services. Without these, those services flag the snapshot as
     * blocked and the result grade defaults to regressed.
     *
     * @return array<string,mixed>
     */
    private function canonicalSnapshot(float $score): array
    {
        return [
            'metrics' => $this->scoreSnapshot($score),
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

    /**
     * @return array<string,mixed>
     */
    private function scoreSnapshot(float $score): array
    {
        $metrics = [
            'functional_correctness',
            'business_rule_alignment',
            'canonical_documentation_adherence',
            'test_and_risk_coverage',
            'enterprise_architecture_quality',
            'governance_integrity',
            'operator_experience',
            'automation_level',
            'human_intervention_load',
            'evidence_and_observability',
            'runtime_safety',
            'provider_cost_token_impact',
            'regressions_and_new_blockers',
        ];

        return array_fill_keys($metrics, $score);
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
        if (! Schema::hasTable('atlas_programming_work_items')) {
            Schema::create('atlas_programming_work_items', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('code', 64)->unique();
                $t->text('intent_text');
                $t->string('intent_type', 40)->index();
                $t->string('scope_mode', 24)->index();
                $t->string('risk_level', 16)->default('medium')->index();
                $t->string('owner', 80)->nullable()->index();
                $t->string('workspace', 255)->nullable();
                $t->string('status', 32)->index();
                $t->string('current_stage', 40)->nullable();
                $t->json('tasks_json')->nullable();
                $t->json('metadata')->nullable();
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
