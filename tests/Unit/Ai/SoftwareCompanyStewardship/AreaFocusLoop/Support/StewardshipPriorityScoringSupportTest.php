<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Support;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusScopeProfileNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Support\StewardshipPriorityScoringSupport as Support;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pure Support peel for AP-785 priority scoring — no I/O, no host service, no DB.
 *
 * Explicit path proof: StewardshipPriorityEngineService imports Support and no
 * longer declares the peeled private scoring methods.
 */
final class StewardshipPriorityScoringSupportTest extends TestCase
{
    private const SUPPORT_PATH = 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Support/StewardshipPriorityScoringSupport.php';

    private const HOST_PATH = 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipPriorityEngineService.php';

    /** @var list<string> */
    private const PEELED = [
        'scoreItem',
        'factoryExecutionScores',
        'rejectedFactoryRankedItem',
        'inferFactoryLeverageScore',
        'inferExecutionReadinessScore',
        'inferFactoryRiskPenalty',
        'isForgeAuthorityReadinessUnlock',
        'type',
        'scoreWithOverrides',
        'riskPenalty',
        'isBlocked',
        'files',
        'listValue',
        'allDocsOrTests',
        'recommendedOrder',
        'band',
        'lane',
        'reasonMachine',
        'humanReason',
        'apFromId',
        'clampScore',
    ];

    #[Test]
    public function explicit_path_proof_support_and_host_files_exist_and_host_calls_support(): void
    {
        $root = dirname(__DIR__, 6);
        $supportAbs = $root.'/'.self::SUPPORT_PATH;
        $hostAbs = $root.'/'.self::HOST_PATH;

        $this->assertFileExists($supportAbs, 'Support peel must live at '.self::SUPPORT_PATH);
        $this->assertFileExists($hostAbs, 'Host must remain at '.self::HOST_PATH);

        $hostSrc = (string) file_get_contents($hostAbs);
        $this->assertStringContainsString(
            'use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Support\StewardshipPriorityScoringSupport;',
            $hostSrc,
            'Host must import StewardshipPriorityScoringSupport',
        );

        foreach (['scoreItem', 'band', 'listValue', 'files'] as $method) {
            $this->assertStringContainsString(
                'StewardshipPriorityScoringSupport::'.$method,
                $hostSrc,
                "Host must call Support::{$method}",
            );
        }

        foreach (self::PEELED as $method) {
            $this->assertStringNotContainsString(
                'private function '.$method,
                $hostSrc,
                "Peeled method residual on host: {$method}",
            );
            $this->assertStringNotContainsString(
                'private static function '.$method,
                $hostSrc,
                "Peeled static residual on host: {$method}",
            );
        }
    }

    #[Test]
    public function pure_support_is_static_and_final_with_no_instance_state(): void
    {
        $ref = new ReflectionClass(Support::class);
        $this->assertTrue($ref->isFinal());
        $this->assertTrue($ref->getConstructor()?->isPrivate() ?? false);

        foreach (self::PEELED as $method) {
            $m = new ReflectionMethod(Support::class, $method);
            $this->assertTrue($m->isPublic() && $m->isStatic(), "{$method} must be public static");
        }
    }

    #[Test]
    public function type_band_lane_and_clamp_are_deterministic(): void
    {
        $this->assertSame('integration_lane_promotion', Support::type([
            'ap_contract' => 'AP-783',
            'type' => 'gap',
        ]));
        $this->assertSame('ui_cosmetic', Support::type(['type' => 'ui_polish_cosmetic']));
        $this->assertSame('gap', Support::type([]));

        $this->assertSame('P0_maximum_advancement', Support::band(90.0));
        $this->assertSame('P1_high_advancement', Support::band(70.0));
        $this->assertSame('P2_standard', Support::band(50.0));
        $this->assertSame('P3_defer', Support::band(10.0));

        $this->assertSame('blocked', Support::lane(90.0, 80, false, []));
        $this->assertSame('now', Support::lane(80.0, 10, false, []));
        $this->assertSame('next', Support::lane(55.0, 10, false, []));
        $this->assertSame('later', Support::lane(20.0, 10, false, []));
        $this->assertSame('blocked', Support::lane(90.0, 0, true, []));

        $this->assertSame(0, Support::clampScore(-5));
        $this->assertSame(100, Support::clampScore(150));
        $this->assertSame(42, Support::clampScore(42));
        $this->assertSame('AP-785', Support::apFromId('seed-AP-785-extra'));
        $this->assertSame('', Support::apFromId('no-ap-here'));
    }

    #[Test]
    public function files_list_value_and_docs_or_tests_helpers_are_pure(): void
    {
        $this->assertSame(
            ['app/Foo.php', 'tests/FooTest.php'],
            Support::files(['affected_files' => ['app/Foo.php', '', 'tests/FooTest.php']]),
        );
        $this->assertSame(
            ['a', 'b'],
            Support::listValue(['dependency_unlocks' => ['a', '', 'b']], 'dependency_unlocks'),
        );
        $this->assertTrue(Support::allDocsOrTests(['docs/x.md', 'tests/YTest.php']));
        $this->assertFalse(Support::allDocsOrTests(['app/Services/X.php', 'tests/YTest.php']));
        $this->assertFalse(Support::allDocsOrTests([]));
    }

    #[Test]
    public function score_item_ranks_integration_above_cosmetic_and_blocks_main_dirty(): void
    {
        $integration = Support::scoreItem([
            'id' => 'AP-783',
            'title' => 'AP-783 integration lane promotion',
            'ap_contract' => 'AP-783',
            'type' => 'integration_lane_promotion',
            'evidence_refs' => ['ap782_receipt', 'ap780_packet'],
            'dependency_unlocks' => ['merge_review', 'owner_runtime', '24h_loop_truth'],
            'operator_touchpoints_reduced' => 4,
            'requires_main_dirty' => false,
            'requires_provider_without_sandbox' => false,
        ], 0);

        $cosmetic = Support::scoreItem([
            'id' => 'cosmetic_ui',
            'title' => 'Polish cockpit button spacing',
            'type' => 'ui_cosmetic',
            'changed_files' => ['resources/js/Components/Button.vue'],
            'operator_touchpoints_reduced' => 0,
            'dependency_unlocks' => [],
        ], 1);

        $this->assertSame('AP-783', $integration['item_id']);
        $this->assertSame('now', $integration['lane']);
        $this->assertGreaterThan(
            (float) $cosmetic['final_priority_score'],
            (float) $integration['final_priority_score'],
        );

        $risky = Support::scoreItem([
            'id' => 'provider_no_sandbox',
            'title' => 'Provider patch directly on main',
            'type' => 'provider_execution',
            'requires_main_dirty' => true,
            'requires_provider_without_sandbox' => true,
            'sensitive_data' => false,
        ], 2);

        $this->assertSame('blocked', $risky['lane']);
        $this->assertGreaterThanOrEqual(60, $risky['risk_penalty']);
        $this->assertContains('main_dirty_required', $risky['reason_machine']);
        $this->assertContains('provider_without_sandbox', $risky['reason_machine']);
    }

    #[Test]
    public function factory_max_rejects_low_leverage_doc_work(): void
    {
        $scored = Support::scoreItem([
            'id' => 'docs_only',
            'title' => 'Write more docs',
            'kind' => 'doc',
            'type' => 'doc',
            'changed_files' => ['docs/only.md'],
            'factory_execution_ready' => false,
        ], 0, AreaFocusScopeProfileNormalizer::FACTORY_MAX, false);

        $this->assertSame('blocked', $scored['lane']);
        $this->assertSame(
            'factory_max_rejects_low_leverage_doc_or_evidence_work',
            $scored['rejection_reason'],
        );
        $this->assertSame(0.0, (float) $scored['final_priority_score']);
    }

    #[Test]
    public function completed_candidates_collapse_to_completed_lane(): void
    {
        $scored = Support::scoreItem([
            'id' => 'done_item',
            'title' => 'Already shipped',
            'type' => 'integration_lane_promotion',
            'completion_status' => 'completed',
            'dependency_unlocks' => ['x'],
            'evidence_refs' => ['y'],
        ], 0);

        $this->assertSame('completed', $scored['lane']);
        $this->assertSame(0.0, (float) $scored['final_priority_score']);
        $this->assertContains('completed_current_state', $scored['reason_machine']);
        $this->assertSame('completed', $scored['completion_status']);
    }

    #[Test]
    public function forge_authority_unlock_and_leverage_inference_are_pure(): void
    {
        $this->assertTrue(Support::isForgeAuthorityReadinessUnlock([
            'title' => 'Real forge authority bootstrap',
            'ap_contract' => 'AP-789',
        ]));
        $this->assertFalse(Support::isForgeAuthorityReadinessUnlock([
            'title' => 'Unrelated cosmetic',
        ]));

        $leverage = Support::inferFactoryLeverageScore([
            'title' => 'AP786 autonomous evolution sandbox merge governor',
            'origin' => 'factory_max_seed',
        ], ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/X.php']);
        $this->assertGreaterThanOrEqual(60, $leverage);
        $this->assertLessThanOrEqual(100, $leverage);

        $penalty = Support::inferFactoryRiskPenalty([
            'owner_candidate' => 'forge',
            'risk_penalty' => 10,
        ], false);
        $this->assertSame(80, $penalty);
    }

    #[Test]
    public function recommended_order_and_human_reason_are_lane_aware(): void
    {
        $this->assertSame(
            ['implement_now_after_owner_gate', 'keep_operator_review_for_irreversible_actions'],
            Support::recommendedOrder('now', 'gap'),
        );
        $this->assertSame(
            ['do_not_execute', 'clear_machine_readable_blockers_first'],
            Support::recommendedOrder('blocked', 'gap'),
        );
        $this->assertSame(
            ['defer_until_owner_runtime_boundaries_are_real'],
            Support::recommendedOrder('later', 'provider_routing'),
        );

        $reason = Support::humanReason(
            'integration_lane_promotion',
            'now',
            ['type:integration_lane_promotion', 'lane:now'],
        );
        $this->assertStringContainsString('integration', strtolower($reason));
    }
}
