<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\RouterRuntime;

use App\Services\Ai\RouterRuntime\AtlasHyperflowSpecialistFlowsReadinessService;
use App\Services\Ai\RouterRuntime\RouterRuntimeCanon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Etapa 1 — Hyperflow + Specialist Flows readiness gate tests.
 *
 * Hard invariants:
 *  - canonical schema_version + 13 declared checks;
 *  - claim_policy never opens the door to external superiority claims;
 *  - benchmark_not_run holds even when the gate is `passed`;
 *  - artisan command emits stable JSON shape and surfaces the right exit
 *    code (0 when passed, 1 when blocked).
 */
class AtlasHyperflowSpecialistFlowsReadinessTest extends TestCase
{
    public function test_report_emits_canonical_envelope_with_required_keys(): void
    {
        $report = app(AtlasHyperflowSpecialistFlowsReadinessService::class)->report();

        $this->assertSame(
            AtlasHyperflowSpecialistFlowsReadinessService::SCHEMA_VERSION,
            $report['schema_version'],
        );
        foreach ([
            'schema_version',
            'status',
            'generated_at',
            'summary',
            'checks',
            'remaining_blockers',
            'claim_policy',
            'note',
            'writes',
        ] as $key) {
            $this->assertArrayHasKey($key, $report, "envelope missing key {$key}");
        }
        $this->assertContains($report['status'], ['passed', 'blocked']);
        $this->assertFalse($report['writes'], 'readiness gate must never write');
        $this->assertIsArray($report['checks']);
        $this->assertGreaterThanOrEqual(13, count($report['checks']));
    }

    public function test_report_lists_every_canonical_check_id(): void
    {
        $report = app(AtlasHyperflowSpecialistFlowsReadinessService::class)->report();
        $ids = array_map(static fn (array $check): string => (string) ($check['id'] ?? ''), (array) $report['checks']);

        foreach ([
            'hyperflow_entry_wired',
            'desktop_auto_auto_preserved',
            'specialist_flows_registered',
            'non_programming_flows_present',
            'programming_flows_present',
            'non_programming_does_not_route_to_dev',
            'specialist_flow_runtime_emits_contract',
            'specialist_flow_execution_emits_handler',
            'receipts_evidence_telemetry_available',
            'dev_forge_handoff_explicit',
            'benchmark_not_run',
            'no_external_superiority_claim',
            'tests_present',
        ] as $required) {
            $this->assertContains($required, $ids, "missing canonical check_id {$required}");
        }
    }

    public function test_claim_policy_blocks_external_superiority_and_marks_benchmark_not_run(): void
    {
        $report = app(AtlasHyperflowSpecialistFlowsReadinessService::class)->report();
        $policy = (array) $report['claim_policy'];

        $this->assertFalse($policy['declares_atlas_complete']);
        $this->assertFalse($policy['allows_external_superiority_claim']);
        $this->assertFalse($policy['rivals_compared']);
        $this->assertTrue($policy['benchmark_not_run']);
        $this->assertTrue($policy['requires_human_authorization_to_run_benchmark']);
    }

    public function test_specialist_flows_registry_contains_14_canonical_flows(): void
    {
        $registry = RouterRuntimeCanon::ALLOWED_FLOW_IDS;
        $this->assertCount(14, $registry);
        foreach ([
            RouterRuntimeCanon::FLOW_CONVERSATION,
            RouterRuntimeCanon::FLOW_RESEARCH,
            RouterRuntimeCanon::FLOW_FINANCE,
            RouterRuntimeCanon::FLOW_MARKETING,
            RouterRuntimeCanon::FLOW_STRATEGY,
            RouterRuntimeCanon::FLOW_CYBER,
            RouterRuntimeCanon::FLOW_PERSONAL_DEVELOPMENT,
            RouterRuntimeCanon::FLOW_AUTOMATION,
            RouterRuntimeCanon::FLOW_DEV,
            RouterRuntimeCanon::FLOW_DEBUG,
            RouterRuntimeCanon::FLOW_REVIEW,
            RouterRuntimeCanon::FLOW_PLAN,
            RouterRuntimeCanon::FLOW_FORGE,
            RouterRuntimeCanon::FLOW_EXPLAIN,
        ] as $flow) {
            $this->assertContains($flow, $registry, "registry missing {$flow}");
        }
    }

    public function test_non_programming_intents_never_map_to_programming_flows(): void
    {
        foreach ([
            RouterRuntimeCanon::INTENT_CONVERSATION,
            RouterRuntimeCanon::INTENT_RESEARCH,
            RouterRuntimeCanon::INTENT_EXPLAIN,
            RouterRuntimeCanon::INTENT_PLAN,
            RouterRuntimeCanon::INTENT_FINANCE,
            RouterRuntimeCanon::INTENT_MARKETING,
            RouterRuntimeCanon::INTENT_STRATEGY,
            RouterRuntimeCanon::INTENT_CYBER,
            RouterRuntimeCanon::INTENT_PERSONAL_DEVELOPMENT,
            RouterRuntimeCanon::INTENT_AUTOMATION,
        ] as $intent) {
            $mapped = RouterRuntimeCanon::INTENT_TO_FLOW[$intent] ?? null;
            $this->assertNotNull($mapped, "intent {$intent} missing INTENT_TO_FLOW mapping");
            $this->assertNotContains(
                $mapped,
                RouterRuntimeCanon::PROGRAMMING_FLOW_IDS,
                "intent {$intent} silently maps to programming flow {$mapped}",
            );
        }
    }

    public function test_artisan_command_emits_canonical_json_with_benchmark_not_run(): void
    {
        $exit = Artisan::call('atlas:ai:hyperflow-specialists', [
            'action' => 'readiness',
            '--json' => true,
        ]);
        $output = Artisan::output();
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded, "command must emit JSON; got: {$output}");
        $this->assertSame(
            AtlasHyperflowSpecialistFlowsReadinessService::SCHEMA_VERSION,
            $decoded['schema_version'],
        );
        $this->assertTrue($decoded['claim_policy']['benchmark_not_run']);
        $this->assertFalse($decoded['claim_policy']['allows_external_superiority_claim']);
        // 0 when passed, 1 when blocked — both honest.
        $this->assertContains($exit, [0, 1]);
    }

    public function test_artisan_command_rejects_unsupported_action(): void
    {
        $exit = Artisan::call('atlas:ai:hyperflow-specialists', [
            'action' => 'definitely-not-a-real-action',
        ]);
        $this->assertSame(1, $exit);
    }

    public function test_dev_forge_handoff_check_marks_workspace_debug_as_delegated(): void
    {
        $report = app(AtlasHyperflowSpecialistFlowsReadinessService::class)->report();
        $check = collect((array) $report['checks'])
            ->firstWhere('id', 'dev_forge_handoff_explicit');

        $this->assertNotNull($check);
        $evidence = (array) ($check['evidence'] ?? []);
        $this->assertTrue($evidence['debug_with_workspace_delegates_to_dev']);
        $this->assertTrue($evidence['review_with_workspace_delegates_to_dev']);
        $this->assertTrue($evidence['debug_without_workspace_stays_local']);
        $this->assertTrue($evidence['review_without_workspace_stays_local']);
    }
}
