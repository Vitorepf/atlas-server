<?php

namespace Tests\Feature\Ai\LongHorizon;

use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\LongHorizon\LongHorizonRecoveryPlannerService;
use InvalidArgumentException;
use Tests\TestCase;

class LongHorizonRecoveryPlannerServiceTest extends TestCase
{
    public function test_healthy_dev_session_resolves_to_execute_with_high_confidence(): void
    {
        $plan = $this->planner()->plan($this->baseInput());

        $this->assertSame(AtlasLongHorizonCanon::RECOVERY_PLAN_SCHEMA_VERSION, $plan['schema_version']);
        $this->assertSame(AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE, $plan['safe_resume_mode']);
        $this->assertSame('continue_with_next_stage', $plan['next_safe_action']);
        $this->assertFalse($plan['escalate_to_forge']);
        $this->assertNull($plan['escalation_reason']);
        $this->assertGreaterThan(0.9, $plan['confidence']);
        $this->assertSame(64, strlen((string) $plan['plan_hash']));
    }

    public function test_stale_advisory_freshness_routes_to_read_only(): void
    {
        $input = $this->baseInput();
        $input['freshness_result'] = [
            'status' => 'stale_advisory',
            'stale_refs' => ['spec:auth-flow-v2'],
        ];

        $plan = $this->planner()->plan($input);

        $this->assertSame(AtlasLongHorizonCanon::SAFE_RESUME_READ_ONLY, $plan['safe_resume_mode']);
        $this->assertContains('spec:auth-flow-v2', $plan['required_context_refresh']);
        $this->assertContains('freshness_stale_advisory', $plan['reasons']);
    }

    public function test_stale_failed_closed_freshness_forces_blocked(): void
    {
        $input = $this->baseInput();
        $input['freshness_result'] = [
            'status' => 'stale_failed_closed',
            'stale_refs' => ['spec:critical-anchor'],
        ];

        $plan = $this->planner()->plan($input);

        $this->assertSame(AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED, $plan['safe_resume_mode']);
        $this->assertSame('resolve_blocker_before_resume', $plan['next_safe_action']);
        $this->assertSame(0.0, $plan['confidence']);
        $this->assertContains('spec:critical-anchor', $plan['required_context_refresh']);
    }

    public function test_unresolved_loss_with_recovery_queries_asks_human(): void
    {
        $input = $this->baseInput();
        $input['continuation_pack']['compaction_receipt'] = [
            'loss_risk' => AtlasLongHorizonCanon::LOSS_RISK_HIGH,
            'unresolved_loss' => [['ref' => 'mem:critical-decision']],
            'recovery_queries' => ['SELECT * FROM atlas_memory_entries WHERE tag = critical'],
            'receipt_hash' => hash('sha256', 'comp-fixture'),
        ];

        $plan = $this->planner()->plan($input);

        $this->assertSame(AtlasLongHorizonCanon::SAFE_RESUME_ASK_HUMAN, $plan['safe_resume_mode']);
        $this->assertContains('unresolved_loss_critical_with_recovery_queries', $plan['reasons']);
    }

    public function test_unresolved_loss_without_recovery_queries_blocks(): void
    {
        $input = $this->baseInput();
        $input['continuation_pack']['compaction_receipt'] = [
            'loss_risk' => AtlasLongHorizonCanon::LOSS_RISK_HIGH,
            'unresolved_loss' => [['ref' => 'mem:lost']],
            'recovery_queries' => [],
        ];

        $plan = $this->planner()->plan($input);

        $this->assertSame(AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED, $plan['safe_resume_mode']);
    }

    public function test_failure_capsule_routes_to_repair(): void
    {
        $input = $this->baseInput();
        $input['failure_capsule'] = [
            'gate' => 'verification_gate',
            'status' => 'failed',
            'capsule_hash' => hash('sha256', 'capsule-fixture'),
            'escalation_signal_delta' => [],
        ];

        $plan = $this->planner()->plan($input);

        $this->assertSame(AtlasLongHorizonCanon::SAFE_RESUME_REPAIR, $plan['safe_resume_mode']);
        $this->assertSame('rerun_failed_stage_with_repair_loop', $plan['next_safe_action']);
        $actions = array_column($plan['remediation_steps'], 'action');
        $this->assertContains('route_to_dev_repair_loop', $actions);
        $this->assertContains('failure_capsule:'.$input['failure_capsule']['capsule_hash'], $plan['evidence_refs']);
    }

    public function test_review_required_routes_to_review(): void
    {
        $input = $this->baseInput();
        $input['review_required'] = true;

        $plan = $this->planner()->plan($input);

        $this->assertSame(AtlasLongHorizonCanon::SAFE_RESUME_REVIEW, $plan['safe_resume_mode']);
        $this->assertSame('open_review_for_latest_stage', $plan['next_safe_action']);
    }

    public function test_human_decisions_pending_routes_to_ask_human(): void
    {
        $input = $this->baseInput();
        $input['human_decisions_pending'] = [
            ['id' => 'hd-001', 'question' => 'Approve plan without regression suite?'],
        ];

        $plan = $this->planner()->plan($input);

        $this->assertSame(AtlasLongHorizonCanon::SAFE_RESUME_ASK_HUMAN, $plan['safe_resume_mode']);
        $this->assertCount(1, $plan['required_human_decisions']);
    }

    public function test_large_dev_scope_escalates_to_forge(): void
    {
        $input = $this->baseInput();
        // Build a context_manifest with > DEFAULT_DEV_SCOPE_MODULE_LIMIT modules.
        $input['continuation_pack']['context_manifest'] = [
            ['ref' => 'app/Services/Foo/Bar.php'],
            ['ref' => 'app/Services/Auth/Login.php'],
            ['ref' => 'app/Services/Billing/Engine.php'],
            ['ref' => 'app/Services/Provider/Router.php'],
        ];

        $plan = $this->planner()->plan($input);

        $this->assertSame(AtlasLongHorizonCanon::SAFE_RESUME_ESCALATE_TO_FORGE, $plan['safe_resume_mode']);
        $this->assertTrue($plan['escalate_to_forge']);
        $this->assertNotNull($plan['escalation_reason']);
        $this->assertSame('emit_dev_to_forge_escalation_packet', $plan['next_safe_action']);
    }

    public function test_pack_marked_blocked_propagates(): void
    {
        $input = $this->baseInput();
        $input['continuation_pack']['safe_resume_mode'] = AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED;
        $input['continuation_pack']['blockers'] = [
            ['kind' => 'pack_blocker', 'description' => 'manifest signature missing'],
        ];

        $plan = $this->planner()->plan($input);

        $this->assertSame(AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED, $plan['safe_resume_mode']);
        $this->assertNotEmpty($plan['blockers']);
        $blockerKinds = array_column($plan['blockers'], 'kind');
        $this->assertContains('pack_blocker', $blockerKinds);
    }

    public function test_plan_hash_is_stable_for_equivalent_inputs(): void
    {
        $planner = $this->planner();
        $a = $planner->plan($this->baseInput());
        $b = $planner->plan($this->baseInput());

        // recovery_plan_id randomizes, so plans differ by id but the canonical
        // hash recomputed from the payload (excluding volatile fields) is
        // stable.
        $payloadA = $a;
        $payloadB = $b;
        $hashA = LongHorizonRecoveryPlannerService::canonicalPlanHash($payloadA);
        $hashB = LongHorizonRecoveryPlannerService::canonicalPlanHash($payloadB);
        $this->assertSame($hashA, $hashB);
        $this->assertSame(64, strlen($hashA));

        $different = $this->baseInput();
        $different['review_required'] = true;
        $planDifferent = $planner->plan($different);
        $this->assertNotSame(
            $hashA,
            LongHorizonRecoveryPlannerService::canonicalPlanHash($planDifferent),
            'Different inputs MUST yield different hashes.',
        );
    }

    public function test_plan_emits_json_stable_envelope(): void
    {
        $plan = $this->planner()->plan($this->baseInput());
        $json = json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertNotFalse($json);
        $decoded = json_decode((string) $json, true);
        foreach ([
            'schema_version', 'recovery_plan_id', 'scope_type', 'scope_id',
            'safe_resume_mode', 'next_safe_action', 'required_context_refresh',
            'required_human_decisions', 'blockers', 'remediation_steps',
            'escalate_to_forge', 'escalation_reason', 'confidence',
            'evidence_refs', 'reasons', 'plan_hash',
        ] as $key) {
            $this->assertArrayHasKey($key, $decoded, "missing canonical key: {$key}");
        }
    }

    public function test_invalid_scope_type_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->planner()->plan([
            'scope_type' => 'unknown_scope',
            'scope_id' => 'whatever',
        ]);
    }

    public function test_missing_scope_id_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->planner()->plan([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_DEV_SESSION,
            'scope_id' => '   ',
        ]);
    }

    private function planner(): LongHorizonRecoveryPlannerService
    {
        return app(LongHorizonRecoveryPlannerService::class);
    }

    /**
     * @return array<string,mixed>
     */
    private function baseInput(): array
    {
        return [
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_DEV_SESSION,
            'scope_id' => 'run-recovery-fixture',
            'continuation_pack' => [
                'pack_hash' => hash('sha256', 'pack-fixture'),
                'safe_resume_mode' => AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE,
                'blockers' => [],
                'human_decisions_required' => [],
                'context_manifest' => [
                    ['ref' => 'stage_receipt:abc123', 'stage' => 'plan'],
                ],
                'evidence_refs' => ['atlas_dev_run_index:run-recovery-fixture'],
                'stale_refs' => [],
                'missing_required_refs' => [],
            ],
            'freshness_result' => [
                'status' => 'fresh',
                'stale_refs' => [],
            ],
        ];
    }
}
