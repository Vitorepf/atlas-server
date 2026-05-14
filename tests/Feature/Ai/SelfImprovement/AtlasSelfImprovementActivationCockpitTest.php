<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfImprovement;

use App\Services\Ai\Programming\ProgrammingProfessionalCompletionAuditService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementActivationCockpitService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementForgeActivationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Atlas Self-Improvement Activation Cockpit v1 feature tests.
 *
 * Audits the human-first cockpit projection that surfaces activation
 * proposals → power gate → baseline → approval → Obra inside Atlas Code.
 * Every test asserts the canonical invariants: read-only projection, no
 * provider call, no token spend, no auto Fast Path, no completion claim
 * promotion, and explicit separation from `external_rivals_certification`.
 */
class AtlasSelfImprovementActivationCockpitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSchema();
        Storage::fake('local');
    }

    public function test_cockpit_emits_canonical_schema_when_empty(): void
    {
        $cockpit = app(AtlasSelfImprovementActivationCockpitService::class)->cockpit([]);

        $this->assertSame(AtlasSelfImprovementActivationCockpitService::SCHEMA_VERSION, $cockpit['schema_version']);
        $this->assertTrue($cockpit['is_read_model']);
        $this->assertFalse($cockpit['external_provider_call']);
        $this->assertFalse($cockpit['provider_tokens_spent']);
        $this->assertFalse($cockpit['auto_fast_path_executed']);
        $this->assertFalse($cockpit['completion_claim_promoted']);
        $this->assertSame('external_rivals_certification', $cockpit['separated_from']);
        $this->assertSame(0, $cockpit['counters']['total']);
        $this->assertNull($cockpit['selected_activation']);
    }

    public function test_cockpit_lists_planned_activations_with_human_labels(): void
    {
        $service = app(AtlasSelfImprovementForgeActivationService::class);
        $plan = $service->plan(['proposal' => $this->strongProposal()]);

        $cockpit = app(AtlasSelfImprovementActivationCockpitService::class)->cockpit([]);

        $this->assertGreaterThanOrEqual(1, $cockpit['counters']['total']);
        $row = collect($cockpit['activations'])
            ->firstWhere('activation_id', $plan['activation_id']);
        $this->assertNotNull($row);
        $this->assertSame(AtlasSelfImprovementForgeActivationService::STATUS_PENDING_HUMAN_REVIEW, $row['status']);
        $this->assertSame('Aguardando humano', $row['status_label']);
        $this->assertSame('bronze', $row['tone']);
        $this->assertNotEmpty($row['title']);
        $this->assertNotNull($row['next_safe_action']);
    }

    public function test_cockpit_detail_surfaces_proposal_power_gate_and_before_snapshot(): void
    {
        $service = app(AtlasSelfImprovementForgeActivationService::class);
        $plan = $service->plan(['proposal' => $this->strongProposal()]);

        $cockpit = app(AtlasSelfImprovementActivationCockpitService::class)->cockpit([
            'activation_id' => $plan['activation_id'],
        ]);

        $detail = $cockpit['selected_activation'];
        $this->assertNotNull($detail);
        $this->assertSame($plan['activation_id'], $detail['activation_id']);
        $this->assertArrayHasKey('proposal_summary', $detail);
        $this->assertArrayHasKey('power_gate', $detail);
        $this->assertArrayHasKey('before_snapshot', $detail);
        $this->assertSame('human_review_required', $detail['power_gate']['outcome']);
        $this->assertSame('Humano precisa aprovar antes de criar Obra.', $detail['power_gate']['label']);
        $this->assertSame('bronze', $detail['power_gate']['tone']);
        $this->assertNotNull($detail['before_snapshot']['maturity']['hash']);
        $this->assertNotNull($detail['before_snapshot']['invariant_lock']['hash']);
        $this->assertNotNull($detail['before_snapshot']['regression_sentinel']['hash']);
        $this->assertSame('Este snapshot serve para comparar se o Atlas melhorou depois.', $detail['before_snapshot']['rationale']);
    }

    public function test_cockpit_detail_for_missing_activation_returns_blocked(): void
    {
        $cockpit = app(AtlasSelfImprovementActivationCockpitService::class)->cockpit([
            'activation_id' => 'act_does_not_exist',
        ]);

        $this->assertSame('blocked', $cockpit['selected_activation']['status']);
        $this->assertContains('activation_not_found', $cockpit['selected_activation']['blockers']);
    }

    public function test_cockpit_filter_by_status_keeps_counters_unchanged(): void
    {
        $service = app(AtlasSelfImprovementForgeActivationService::class);
        $service->plan(['proposal' => $this->strongProposal()]);

        $cockpit = app(AtlasSelfImprovementActivationCockpitService::class)->cockpit([
            'status' => 'obra_created',
        ]);

        $this->assertGreaterThanOrEqual(1, $cockpit['counters']['total']);
        $this->assertEmpty($cockpit['activations']);
    }

    public function test_cockpit_detail_after_accept_surfaces_open_obra_action_and_receipt(): void
    {
        $service = app(AtlasSelfImprovementForgeActivationService::class);
        $plan = $service->plan(['proposal' => $this->strongProposal()]);
        $accepted = $service->accept($plan['activation_id'], [
            'reviewer' => 'cockpit-test-reviewer',
            'reason' => 'cockpit projection should expose approval receipt and obra',
        ]);
        $this->assertNotNull($accepted['created_obra_id']);

        $cockpit = app(AtlasSelfImprovementActivationCockpitService::class)->cockpit([
            'activation_id' => $plan['activation_id'],
        ]);
        $detail = $cockpit['selected_activation'];

        $this->assertSame('obra_created', $detail['status']);
        $this->assertSame('Obra criada', $detail['status_label']);
        $this->assertSame('moss', $detail['tone']);
        $this->assertNotNull($detail['approval_receipt']);
        $this->assertSame('cockpit-test-reviewer', $detail['approval_receipt']['reviewer']);
        $this->assertNotNull($detail['approval_receipt']['receipt_hash']);
        $this->assertNotNull($detail['created_obra']);
        $this->assertSame($accepted['created_obra_id'], $detail['created_obra']['obra_id']);
        $this->assertTrue($detail['open_obra_action']['enabled']);
        $this->assertSame($accepted['created_obra_id'], $detail['open_obra_action']['obra_id']);
        $this->assertFalse($detail['fast_path_started']);
    }

    public function test_cockpit_for_rejected_activation_surfaces_rejection_and_no_obra(): void
    {
        $service = app(AtlasSelfImprovementForgeActivationService::class);
        $plan = $service->plan(['proposal' => $this->strongProposal()]);
        $service->reject($plan['activation_id'], [
            'reviewer' => 'cockpit-rejector',
            'reason' => 'projection should expose rejection visibly',
        ]);

        $cockpit = app(AtlasSelfImprovementActivationCockpitService::class)->cockpit([
            'activation_id' => $plan['activation_id'],
        ]);
        $detail = $cockpit['selected_activation'];

        $this->assertSame('rejected', $detail['status']);
        $this->assertSame('rec-red', $detail['tone']);
        $this->assertNotNull($detail['rejection']);
        $this->assertSame('cockpit-rejector', $detail['rejection']['reviewer']);
        $this->assertNull($detail['created_obra']);
        $this->assertFalse($detail['open_obra_action']['enabled']);
    }

    public function test_api_cockpit_list_returns_canonical_schema(): void
    {
        $service = app(AtlasSelfImprovementForgeActivationService::class);
        $service->plan(['proposal' => $this->strongProposal()]);

        $response = $this->getJson(
            '/atlas-code/self-improvement/activation-cockpit',
            $this->headers(),
        );

        $response->assertOk()
            ->assertJsonPath('schema_version', AtlasSelfImprovementActivationCockpitService::SCHEMA_VERSION)
            ->assertJsonPath('external_provider_call', false)
            ->assertJsonPath('auto_fast_path_executed', false)
            ->assertJsonPath('separated_from', 'external_rivals_certification')
            ->assertJsonPath('is_read_model', true);
    }

    public function test_api_cockpit_detail_returns_humanised_projection(): void
    {
        $service = app(AtlasSelfImprovementForgeActivationService::class);
        $plan = $service->plan(['proposal' => $this->strongProposal()]);

        $response = $this->getJson(
            '/atlas-code/self-improvement/activation-cockpit/'.$plan['activation_id'],
            $this->headers(),
        );

        $response->assertOk()
            ->assertJsonPath('selected_activation.activation_id', $plan['activation_id'])
            ->assertJsonPath('selected_activation.power_gate.outcome', 'human_review_required')
            ->assertJsonPath('selected_activation.power_gate.label', 'Humano precisa aprovar antes de criar Obra.')
            ->assertJsonPath('selected_activation.fast_path_started', false);
    }

    public function test_api_cockpit_detail_unknown_id_returns_404(): void
    {
        $response = $this->getJson(
            '/atlas-code/self-improvement/activation-cockpit/act_does_not_exist',
            $this->headers(),
        );

        $response->assertStatus(404)
            ->assertJsonPath('selected_activation.status', 'blocked');
    }

    public function test_forge_controller_accept_now_carries_human_summary_and_next_safe_action(): void
    {
        $service = app(AtlasSelfImprovementForgeActivationService::class);
        $plan = $service->plan(['proposal' => $this->strongProposal()]);

        $response = $this->postJson(
            '/atlas-code/self-improvement/forge-activations/'.$plan['activation_id'].'/accept',
            ['reviewer' => 'human-enrich', 'reason' => 'cockpit enrichment regression'],
            $this->headers(),
        );

        $response->assertStatus(201)
            ->assertJsonPath('status', AtlasSelfImprovementForgeActivationService::STATUS_OBRA_CREATED);
        $payload = $response->json();
        $this->assertIsString($payload['human_summary'] ?? null);
        $this->assertIsString($payload['next_safe_action'] ?? null);
    }

    public function test_cli_cockpit_strict_succeeds_when_no_activation_selected(): void
    {
        $exit = Artisan::call('atlas:self-improvement:activation-cockpit', ['--strict' => true, '--json' => true]);
        $this->assertSame(0, $exit);
    }

    public function test_cli_cockpit_strict_fails_for_blocked_activation(): void
    {
        $service = app(AtlasSelfImprovementForgeActivationService::class);
        // Critical autopromotion proposal → power gate rejects → STATUS_BLOCKED
        // with `proposal_power_gate_rejected` blocker; cockpit --strict
        // must surface that as exit 1.
        $plan = $service->plan(['proposal' => [
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
        ]]);
        $this->assertSame(AtlasSelfImprovementForgeActivationService::STATUS_BLOCKED, $plan['status']);

        $exit = Artisan::call('atlas:self-improvement:activation-cockpit', [
            '--strict' => true,
            '--json' => true,
            '--activation' => $plan['activation_id'],
        ]);
        $this->assertSame(1, $exit);
    }

    public function test_no_provider_call_or_token_spend_or_fast_path_at_any_layer(): void
    {
        $service = app(AtlasSelfImprovementForgeActivationService::class);
        $plan = $service->plan(['proposal' => $this->strongProposal()]);

        $cockpit = app(AtlasSelfImprovementActivationCockpitService::class)->cockpit([]);
        $detail = app(AtlasSelfImprovementActivationCockpitService::class)->cockpit([
            'activation_id' => $plan['activation_id'],
        ])['selected_activation'];

        foreach ([$cockpit, $detail] as $payload) {
            $this->assertFalse($payload['external_provider_call']);
            $this->assertFalse($payload['provider_tokens_spent']);
            $this->assertFalse($payload['auto_fast_path_executed']);
            $this->assertFalse($payload['completion_claim_promoted']);
            $this->assertSame('external_rivals_certification', $payload['separated_from']);
        }
    }

    public function test_completion_audit_exposes_cockpit_certification(): void
    {
        $report = app(ProgrammingProfessionalCompletionAuditService::class)->report(base_path());
        $this->assertArrayHasKey('atlas_self_improvement_activation_cockpit_certification', $report);
        $cert = $report['atlas_self_improvement_activation_cockpit_certification'];
        $this->assertSame('atlas.self_improvement.activation_cockpit_certification.v1', $cert['schema_version']);
        $this->assertGreaterThanOrEqual(25, count($cert['invariants']));
        $this->assertFalse($cert['external_provider_call']);
        $this->assertFalse($cert['provider_tokens_spent']);
        $this->assertFalse($cert['auto_fast_path_executed']);
        $this->assertFalse($cert['promotes_external_rivals_claim']);
        $this->assertSame('external_rivals_certification', $cert['separated_from']);
        $this->assertTrue($cert['invariants']['no_auto_fast_path_execution']);
        $this->assertTrue($cert['invariants']['no_provider_call']);
        $this->assertTrue($cert['invariants']['no_token_spend']);
        $this->assertTrue($cert['invariants']['completion_claim_not_promoted']);
        $this->assertTrue($cert['invariants']['external_rivals_separated']);
    }

    public function test_external_rivals_certification_remains_present_and_unaffected(): void
    {
        $report = app(ProgrammingProfessionalCompletionAuditService::class)->report(base_path());
        $this->assertArrayHasKey('external_rivals_certification', $report);
        // Cockpit is purely a projection — it must not unlock the external
        // rivals claim under any circumstance.
        $external = $report['external_rivals_certification'];
        $this->assertIsArray($external);
    }

    /**
     * @return array<string,mixed>
     */
    private function strongProposal(): array
    {
        return [
            'title' => 'Cockpit projection coverage proposal',
            'problem_statement' => 'Self-improvement activations need a human-first cockpit projection.',
            'business_rule' => 'Operator must see proposal, gate, baseline and approval visibly before Obra creation.',
            'target_capability' => 'self_improvement_activation_cockpit',
            'why_now' => 'Activation flow currently invisible in Atlas Code.',
            'expected_power_gain' => 'visible_governed_activation_lifecycle',
            'success_metrics' => ['cockpit_read_model_available', 'human_summary_present'],
            'acceptance_gates' => [
                'docs-health=ok',
                'completion-audit:cockpit_status=available',
            ],
            'canonical_docs' => [
                'docs/engineering-knowledge-base/atlas-self-improvement-activation-cockpit-v1.md',
                'docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md',
            ],
            'allowed_paths' => [
                'app/Services/Ai/SelfImprovement/AtlasSelfImprovementActivationCockpitService.php',
            ],
            'forbidden_paths' => [
                'app/Services/Ai/Providers/',
            ],
            'risk_level' => 'medium',
            'human_review_required' => true,
        ];
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
