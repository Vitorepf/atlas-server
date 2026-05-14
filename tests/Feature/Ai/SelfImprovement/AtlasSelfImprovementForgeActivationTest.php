<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfImprovement;

use App\Models\AtlasProject;
use App\Services\Ai\Programming\ProgrammingProfessionalCompletionAuditService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementForgeActivationService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementHumanTrustLedgerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AtlasSelfImprovementForgeActivationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSchema();
        Storage::fake('local');
    }

    public function test_plan_fails_closed_without_proposal(): void
    {
        $service = app(AtlasSelfImprovementForgeActivationService::class);
        $payload = $service->plan([]);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('no_proposal_payload_provided', $payload['blockers']);
        $this->assertNull($payload['created_obra_id']);
    }

    public function test_rejected_gate_does_not_create_obra(): void
    {
        $service = app(AtlasSelfImprovementForgeActivationService::class);
        $payload = $service->plan([
            'proposal' => $this->criticalAutopromotionProposal(),
        ]);
        // Critical autopromotion → power gate rejected → activation blocked.
        $this->assertContains($payload['status'], ['blocked']);
        $this->assertNull($payload['created_obra_id']);
        $this->assertContains('proposal_power_gate_rejected', $payload['blockers']);
    }

    public function test_needs_revision_does_not_create_obra(): void
    {
        $service = app(AtlasSelfImprovementForgeActivationService::class);
        $weak = ['title' => 'incomplete'];
        $payload = $service->plan(['proposal' => $weak]);
        $this->assertNull($payload['created_obra_id']);
        $this->assertContains($payload['status'], [
            AtlasSelfImprovementForgeActivationService::STATUS_BLOCKED,
            AtlasSelfImprovementForgeActivationService::STATUS_NEEDS_REVISION,
        ]);
    }

    public function test_human_review_required_blocks_until_explicit_accept(): void
    {
        $service = app(AtlasSelfImprovementForgeActivationService::class);
        $payload = $service->plan(['proposal' => $this->strongProposal()]);
        // Power gate marks human_review_required for strong proposals.
        $this->assertSame(
            AtlasSelfImprovementForgeActivationService::STATUS_PENDING_HUMAN_REVIEW,
            $payload['status'],
        );
        $this->assertNull($payload['created_obra_id']);
        $this->assertSame('await_explicit_accept_with_reviewer_and_reason', $payload['next_action']);
    }

    public function test_accept_with_reviewer_and_reason_creates_obra(): void
    {
        $service = app(AtlasSelfImprovementForgeActivationService::class);
        $plan = $service->plan(['proposal' => $this->strongProposal()]);
        $accepted = $service->accept($plan['activation_id'], [
            'reviewer' => 'human-1',
            'reason' => 'maturity gain validated',
        ]);

        $this->assertSame(
            AtlasSelfImprovementForgeActivationService::STATUS_OBRA_CREATED,
            $accepted['status'],
        );
        $this->assertNotNull($accepted['created_obra_id']);
        $this->assertNotNull($accepted['approval']);
        $this->assertSame('human-1', $accepted['approval']['reviewer']);
        $this->assertNotNull($accepted['approval']['receipt_hash']);
        $this->assertNotNull($accepted['work_intake']);
        $obra = AtlasProject::query()->whereKey($accepted['created_obra_id'])->first();
        $this->assertNotNull($obra);
        $this->assertSame('programming', $obra->domain);
        $this->assertNotEmpty(data_get($obra->metadata, 'self_improvement_activation.activation_id'));
        $this->assertSame($plan['activation_id'], data_get($obra->metadata, 'self_improvement_activation.activation_id'));
    }

    public function test_accept_does_not_create_obra_for_blocked_missing_canonical_docs(): void
    {
        $service = app(AtlasSelfImprovementForgeActivationService::class);
        $workspace = sys_get_temp_dir().'/atlas-missing-docs-'.Str::ulid();
        mkdir($workspace, 0777, true);

        $plan = $service->plan([
            'proposal' => $this->strongProposal(),
            'workspace' => $workspace,
        ]);
        $this->assertSame(AtlasSelfImprovementForgeActivationService::STATUS_BLOCKED, $plan['status']);
        $this->assertNotEmpty($plan['missing_required_docs']);

        $accepted = $service->accept($plan['activation_id'], [
            'reviewer' => 'human-docs',
            'reason' => 'should remain blocked',
        ]);

        $this->assertSame(AtlasSelfImprovementForgeActivationService::STATUS_BLOCKED, $accepted['status']);
        $this->assertContains('cannot_accept_blocked_activation', $accepted['blockers']);
        $this->assertNull($accepted['created_obra_id']);
    }

    public function test_accept_is_idempotent_and_does_not_create_duplicate_obras(): void
    {
        $service = app(AtlasSelfImprovementForgeActivationService::class);
        $initialCount = AtlasProject::query()->count();
        $plan = $service->plan(['proposal' => $this->strongProposal()]);

        $first = $service->accept($plan['activation_id'], [
            'reviewer' => 'human-idempotent',
            'reason' => 'first accept',
        ]);
        $second = $service->accept($plan['activation_id'], [
            'reviewer' => 'human-idempotent',
            'reason' => 'retry accept',
        ]);

        $this->assertSame($first['created_obra_id'], $second['created_obra_id']);
        $this->assertSame($initialCount + 1, AtlasProject::query()->count());
    }

    public function test_accept_without_reviewer_blocks(): void
    {
        $service = app(AtlasSelfImprovementForgeActivationService::class);
        $plan = $service->plan(['proposal' => $this->strongProposal()]);
        $blocked = $service->accept($plan['activation_id'], ['reviewer' => null, 'reason' => null]);
        $this->assertContains('accept_requires_reviewer_and_reason', $blocked['blockers']);
        $this->assertNull($blocked['created_obra_id']);
    }

    public function test_reject_records_reason_and_does_not_create_obra(): void
    {
        $service = app(AtlasSelfImprovementForgeActivationService::class);
        $plan = $service->plan(['proposal' => $this->strongProposal()]);
        $rejected = $service->reject($plan['activation_id'], [
            'reviewer' => 'human-2',
            'reason' => 'scope too broad for current sprint',
        ]);
        $this->assertSame(AtlasSelfImprovementForgeActivationService::STATUS_REJECTED, $rejected['status']);
        $this->assertNull($rejected['created_obra_id']);
        $this->assertNotNull($rejected['rejection']);
        $this->assertSame('human-2', $rejected['rejection']['reviewer']);
    }

    public function test_reject_records_global_trust_ledger_evidence(): void
    {
        $service = app(AtlasSelfImprovementForgeActivationService::class);
        $plan = $service->plan(['proposal' => $this->strongProposal()]);

        $service->reject($plan['activation_id'], [
            'reviewer' => 'human-ledger-reject',
            'reason' => 'global rejection evidence',
        ]);

        $payloads = DB::table('atlas_ledger_events')
            ->where('event_type', 'SELF_IMPROVEMENT_TRUST_LEDGER_ENTRY')
            ->pluck('payload')
            ->all();

        $this->assertTrue(collect($payloads)->contains(
            fn (mixed $payload): bool => is_string($payload)
                && str_contains($payload, AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_PROPOSAL_REJECTED_FOR_FORGE),
        ));
    }

    public function test_created_obra_intake_is_populated(): void
    {
        $service = app(AtlasSelfImprovementForgeActivationService::class);
        $plan = $service->plan(['proposal' => $this->strongProposal()]);
        $accepted = $service->accept($plan['activation_id'], [
            'reviewer' => 'human-3',
            'reason' => 'governance alignment',
        ]);
        $obra = AtlasProject::query()->whereKey($accepted['created_obra_id'])->first();
        $intake = data_get($obra->metadata, 'latest_atlas_code_forge_work_intake');
        $this->assertIsArray($intake);
        $this->assertNotEmpty($intake['business_rule']);
        $this->assertNotEmpty($intake['acceptance_criteria']);
        $this->assertNotEmpty($intake['canonical_docs']);
        $this->assertSame('medium', $intake['risk_level']);
    }

    public function test_canonical_docs_hashes_are_present_when_files_exist(): void
    {
        $service = app(AtlasSelfImprovementForgeActivationService::class);
        $plan = $service->plan(['proposal' => $this->strongProposal()]);
        $docs = $plan['docs'];
        $this->assertArrayHasKey('docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md', $docs);
        $this->assertArrayHasKey('docs/engineering-knowledge-base/atlas-forge-continuum-os.md', $docs);
        $this->assertArrayHasKey('docs/engineering-knowledge-base/atlas-programming-forge-flow.md', $docs);
        $required = $docs['docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md'];
        $this->assertTrue($required['present']);
        $this->assertNotNull($required['hash']);
    }

    public function test_before_snapshot_includes_all_baselines(): void
    {
        $service = app(AtlasSelfImprovementForgeActivationService::class);
        $plan = $service->plan(['proposal' => $this->strongProposal()]);
        $snap = $plan['before_snapshot'];
        foreach (['maturity', 'invariant_lock', 'regression_sentinel', 'strategy_portfolio', 'trust_ledger'] as $key) {
            $this->assertArrayHasKey($key, $snap);
            $this->assertNotNull($snap[$key]['hash']);
        }
        $this->assertNull($plan['after_snapshot']);
    }

    public function test_trust_ledger_records_accept_outcome_when_obra_exists(): void
    {
        $this->assertTrue(in_array(
            AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_PROPOSAL_ACCEPTED_FOR_FORGE,
            AtlasSelfImprovementHumanTrustLedgerService::KNOWN_OUTCOMES,
            true,
        ));

        $service = app(AtlasSelfImprovementForgeActivationService::class);
        $plan = $service->plan(['proposal' => $this->strongProposal()]);
        $accepted = $service->accept($plan['activation_id'], [
            'reviewer' => 'human-4',
            'reason' => 'trust ledger smoke',
        ]);
        $obra = AtlasProject::query()->whereKey($accepted['created_obra_id'])->first();
        $memory = app(AtlasSelfImprovementHumanTrustLedgerService::class)->memoryFor($obra->refresh());
        $this->assertGreaterThanOrEqual(1, $memory['entry_count']);
        $outcomes = array_map(static fn (array $e): string => (string) $e['outcome'], $memory['entries']);
        $this->assertContains(
            AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_PROPOSAL_ACCEPTED_FOR_FORGE,
            $outcomes,
        );
    }

    public function test_no_auto_fast_path(): void
    {
        $service = app(AtlasSelfImprovementForgeActivationService::class);
        $plan = $service->plan(['proposal' => $this->strongProposal()]);
        $accepted = $service->accept($plan['activation_id'], [
            'reviewer' => 'human-5',
            'reason' => 'no auto fast path test',
        ]);
        $this->assertFalse($accepted['auto_fast_path_executed']);
        $this->assertSame('open_atlas_code_forge', $accepted['next_action']);
    }

    public function test_no_provider_call_or_token_spend(): void
    {
        $service = app(AtlasSelfImprovementForgeActivationService::class);
        $plan = $service->plan(['proposal' => $this->strongProposal()]);
        $this->assertFalse($plan['external_provider_call']);
        $this->assertFalse($plan['provider_tokens_spent']);
    }

    public function test_completion_audit_exposes_certification(): void
    {
        $report = app(ProgrammingProfessionalCompletionAuditService::class)->report(base_path());
        $this->assertArrayHasKey('atlas_self_improvement_forge_activation_certification', $report);
        $block = $report['atlas_self_improvement_forge_activation_certification'];
        $this->assertSame('atlas.self_improvement.forge_activation_certification.v1', $block['schema_version']);
        $this->assertGreaterThanOrEqual(20, count($block['invariants']));
        $this->assertFalse($block['external_provider_call']);
        $this->assertFalse($block['provider_tokens_spent']);
        $this->assertFalse($block['auto_fast_path_executed']);
        $this->assertSame('external_rivals_certification', $block['separated_from']);
    }

    public function test_api_post_creates_activation_pending_human_review(): void
    {
        $response = $this->postJson(
            '/atlas-code/self-improvement/forge-activations',
            ['proposal' => $this->strongProposal()],
            $this->headers(),
        );
        $response->assertStatus(201)
            ->assertJsonPath('status', AtlasSelfImprovementForgeActivationService::STATUS_PENDING_HUMAN_REVIEW)
            ->assertJsonPath('created_obra_id', null)
            ->assertJsonPath('external_provider_call', false);
    }

    public function test_api_post_accept_creates_obra(): void
    {
        $service = app(AtlasSelfImprovementForgeActivationService::class);
        $plan = $service->plan(['proposal' => $this->strongProposal()]);
        $response = $this->postJson(
            '/atlas-code/self-improvement/forge-activations/'.$plan['activation_id'].'/accept',
            ['reviewer' => 'human-6', 'reason' => 'api accept test'],
            $this->headers(),
        );
        $response->assertStatus(201)
            ->assertJsonPath('status', AtlasSelfImprovementForgeActivationService::STATUS_OBRA_CREATED);
    }

    public function test_api_get_returns_planned_activation(): void
    {
        $service = app(AtlasSelfImprovementForgeActivationService::class);
        $plan = $service->plan(['proposal' => $this->strongProposal()]);
        $response = $this->getJson(
            '/atlas-code/self-improvement/forge-activations/'.$plan['activation_id'],
            $this->headers(),
        );
        $response->assertOk()
            ->assertJsonPath('activation_id', $plan['activation_id'])
            ->assertJsonPath('schema_version', AtlasSelfImprovementForgeActivationService::SCHEMA_VERSION);
    }

    public function test_cli_strict_blocks_without_proposal(): void
    {
        $exit = Artisan::call('atlas:self-improvement:activate-forge', ['--strict' => true, '--json' => true]);
        $this->assertSame(1, $exit);
    }

    /**
     * @return array<string,mixed>
     */
    private function strongProposal(): array
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
            'title' => 'critical attempt',
            'problem_statement' => 'p',
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
