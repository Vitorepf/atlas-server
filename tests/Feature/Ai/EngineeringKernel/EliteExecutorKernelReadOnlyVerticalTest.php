<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\CanonicalKernelPayload;
use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use App\Services\Ai\EngineeringKernel\ExecutionOrder;
use App\Services\Ai\EngineeringKernel\OutcomeObservation;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

final class EliteExecutorKernelReadOnlyVerticalTest extends TestCase
{
    private const ROLE_IDS = [
        'product_strategy', 'product_management', 'domain_research', 'ux_research',
        'interaction_design', 'visual_design', 'architecture', 'backend', 'frontend',
        'mobile', 'data', 'qa_testing', 'appsec_privacy', 'performance_resilience',
        'devops_sre', 'observability', 'release', 'documentation_dx',
        'maintenance_simplification', 'outcome_analysis', 'evidence_audit', 'final_certification',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_19_050000_extend_atlas_ledger_events_with_timeline_fields.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        parent::tearDown();
    }

    public function test_read_only_order_executes_idempotently_with_correlated_evidence(): void
    {
        $kernel = app(EliteExecutorKernel::class);
        $order = ExecutionOrder::fromArray($this->orderData());

        $first = $kernel->execute($order);
        $replay = $kernel->execute(ExecutionOrder::fromArray($order->toArray()));

        $this->assertSame('completed_read_only', $first->status);
        $this->assertSame($first->outcomeHash, $replay->outcomeHash);
        $this->assertSame($order->productIntentVerdictHash, $first->correlatedHashes['intent']);
        $this->assertSame($order->specHash, $first->correlatedHashes['spec']);
        $this->assertSame($this->evidenceHash(), $first->correlatedHashes['evidence']);
        $this->assertFalse($first->claimEligible);
    }

    public function test_reusing_idempotency_key_with_changed_order_hash_is_refused(): void
    {
        $kernel = app(EliteExecutorKernel::class);
        $kernel->execute(ExecutionOrder::fromArray($this->orderData()));
        $this->app->forgetInstance(EliteExecutorKernel::class);
        $changed = $this->orderData();
        $changed['spec_hash'] = hash('sha256', 'changed-spec');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('idempotency_key_reused_with_changed_order');
        $kernel->execute(ExecutionOrder::fromArray($changed));
    }

    public function test_caller_verified_flags_without_real_acceptance_bundle_never_promote(): void
    {
        $data = $this->orderData();
        unset($data['evidence_policy']['acceptance_bundle']);

        $outcome = app(EliteExecutorKernel::class)->execute(ExecutionOrder::fromArray($data));

        $this->assertSame('held', $outcome->status);
    }

    public function test_replay_is_reconstructed_from_canonical_ledger_after_new_kernel_instance(): void
    {
        $first = app(EliteExecutorKernel::class)->execute(ExecutionOrder::fromArray($this->orderData()));
        $this->app->forgetInstance(EliteExecutorKernel::class);

        $replay = app(EliteExecutorKernel::class)->execute(ExecutionOrder::fromArray($this->orderData()));

        $this->assertSame($first->outcomeHash, $replay->outcomeHash);
        $this->assertDatabaseHas('atlas_ledger_events', ['scope_type' => 'engineering_delivery', 'scope_id' => 'delivery-read-only']);
    }

    public function test_missing_or_stale_evidence_never_completes_read_only(): void
    {
        $kernel = app(EliteExecutorKernel::class);
        $data = $this->orderData();
        $data['evidence_policy']['fresh'] = false;

        $outcome = $kernel->execute(ExecutionOrder::fromArray($data));

        $this->assertSame('held', $outcome->status);
        $this->assertNotEmpty($outcome->uncertainties);
    }

    public function test_dispositions_for_a_different_roster_are_held_not_implicitly_accepted(): void
    {
        $data = $this->orderData();
        $dispositions = $data['evidence_policy']['role_dispositions'];
        $first = array_key_first($dispositions);
        $entry = $dispositions[$first];
        unset($dispositions[$first]);
        $dispositions['foreign_role'] = $entry;
        $data['evidence_policy']['role_dispositions'] = $dispositions;

        $outcome = app(EliteExecutorKernel::class)->execute(ExecutionOrder::fromArray($data));

        $this->assertSame('held', $outcome->status);
        $this->assertContains('role_dispositions_do_not_match_order_roster', $outcome->uncertainties);
    }

    public function test_observe_outcome_returns_typed_non_claiming_learning_receipt(): void
    {
        $kernel = app(EliteExecutorKernel::class);
        $outcome = $kernel->execute(ExecutionOrder::fromArray($this->orderData()));
        $receipt = $kernel->observeOutcome(OutcomeObservation::fromArray([
            'schema_version' => 'atlas.outcome_observation.v1',
            'run_id' => 'run-read-only',
            'delivery_id' => 'delivery-read-only',
            'release_hash' => $outcome->correlatedHashes['release'],
            'order_hash' => $outcome->correlatedHashes['order'],
            'outcome_hash' => $outcome->outcomeHash,
            'window' => '0h',
            'observed_at' => '2026-07-11T00:00:00+00:00',
            'metrics' => ['status' => 'read_only'],
            'provenance' => ['source' => 'kernel_test'],
        ]));

        $this->assertSame('held_for_causal_adjudication', $receipt->status);
        $this->assertNotNull($receipt->ledgerEventRef);
    }

    public function test_observe_outcome_refuses_unknown_correlation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outcome_observation_unknown_correlation');

        app(EliteExecutorKernel::class)->observeOutcome(OutcomeObservation::fromArray([
            'schema_version' => 'atlas.outcome_observation.v1',
            'run_id' => 'unknown', 'delivery_id' => 'unknown',
            'release_hash' => hash('sha256', 'unknown-release'),
            'order_hash' => hash('sha256', 'unknown-order'),
            'outcome_hash' => hash('sha256', 'unknown-outcome'),
            'window' => '0h', 'observed_at' => '2026-07-11T00:00:00+00:00',
            'metrics' => ['status' => 'unknown'], 'provenance' => ['source' => 'kernel_test'],
        ]));
    }

    /** @return array<string,mixed> */
    private function orderData(): array
    {
        $roles = [];
        $dispositions = [];
        foreach (self::ROLE_IDS as $role) {
            $roles[$role] = ['depth' => 'standard', 'independent' => true];
            $dispositions[$role] = [
                'status' => 'pass',
                'evidence_hash' => hash('sha256', $role),
                'signature' => hash('sha256', 'signature-'.$role),
            ];
        }
        $rosterHash = hash('sha256', json_encode(CanonicalKernelPayload::normalize($roles), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $authority = ['kind' => 'read_only', 'role_roster_catalog_hash' => $rosterHash];
        $authorityHash = hash('sha256', json_encode(CanonicalKernelPayload::normalize($authority), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return [
            'schema_version' => 'atlas.execution_order.v2',
            'run_id' => 'run-read-only',
            'delivery_id' => 'delivery-read-only',
            'mode' => 'dev',
            'risk_class' => 'R2',
            'complexity_band' => 'C2',
            'duration_regime' => 'interactive',
            'work_topology' => 'single',
            'product_intent_verdict_hash' => hash('sha256', 'read-only-intent'),
            'spec_hash' => hash('sha256', 'read-only-spec'),
            'world_model_snapshot_hash' => hash('sha256', 'read-only-world'),
            'workspace' => '/tmp/atlas-read-only',
            'base_commit' => str_repeat('b', 40),
            'allowed_scope' => ['README.md'],
            'forbidden_scope' => ['.env'],
            'authority_envelope' => $authority,
            'decision_receipt' => ['hash' => hash('sha256', 'read-only-decision'), 'authority_hash' => $authorityHash, 'role_roster_catalog_hash' => $rosterHash],
            'operator_contract' => ['presence' => 'intent_and_authority'],
            'role_roster' => $roles,
            'role_roster_catalog_hash' => $rosterHash,
            'provider_route' => ['provider' => 'none', 'model' => 'none'],
            'tool_permissions' => ['read' => true, 'mutate' => false],
            'evidence_policy' => [
                'required' => true,
                'status' => 'verified',
                'fresh' => true,
                'evidence_hash' => $this->evidenceHash(),
                'role_dispositions' => $dispositions,
                'acceptance_bundle' => $this->honestAcceptanceBundle(),
            ],
            'release_policy' => ['kind' => 'none_read_only'],
            'rollback_policy' => ['kind' => 'none_read_only'],
            'outcome_policy' => ['windows' => ['0h', '24h', '7d', '30d', '90d', '150d']],
            'experiment_ref' => 'experiment-read-only',
            'idempotency_key' => 'read-only-key',
            'budget_posture' => 'unbounded_quality_first',
        ];
    }

    private function evidenceHash(): string
    {
        return CanonicalKernelPayload::hash($this->honestAcceptanceBundle());
    }

    /** @return array<string,mixed> */
    private function honestAcceptanceBundle(): array
    {
        return [
            'criteria_hash' => 'frozen-hash-001', 'frozen_hash' => 'frozen-hash-001',
            'changed_files' => ['README.md'],
            'changed_public_symbols' => [['symbol' => 'README', 'has_criterion' => true, 'has_test' => true]],
            'execution' => ['commands' => ['php artisan test tests/Unit/ExampleTest.php'], 'claimed_status' => 'passed', 'tests_run' => 3, 'assertions_executed' => 3, 'selected_tests' => ['tests/Unit/ExampleTest.php'], 'artifacts' => []],
            'mutation_report' => ['kill_ratio' => 0.8, 'mutants_generated' => 3, 'decision_surface_added' => true],
            'security_scan' => ['ran' => true, 'secret_free' => true, 'critical_sast' => 0, 'critical_cve' => 0],
            'judges' => [['name' => 'a', 'provider_family' => 'anthropic', 'approved' => true], ['name' => 'b', 'provider_family' => 'openai', 'approved' => true]],
            'context_sufficiency' => 90,
        ];
    }
}
