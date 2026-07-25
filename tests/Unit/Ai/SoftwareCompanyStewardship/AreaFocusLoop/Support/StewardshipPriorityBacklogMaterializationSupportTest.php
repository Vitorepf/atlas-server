<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Support;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusScopeProfileNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Support\StewardshipPriorityBacklogMaterializationSupport as Support;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Support\StewardshipPriorityScoringSupport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pure Support peel for AP-785 priority backlog materialization — no I/O, no host service, no DB.
 *
 * Explicit path proof: StewardshipPriorityEngineService imports Support and no
 * longer declares the peeled private materialization methods.
 */
final class StewardshipPriorityBacklogMaterializationSupportTest extends TestCase
{
    private const SUPPORT_PATH = 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Support/StewardshipPriorityBacklogMaterializationSupport.php';

    private const HOST_PATH = 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipPriorityEngineService.php';

    /** @var list<string> */
    private const PEELED = [
        'apply',
        'terminalBacklogRebalanceActive',
        'terminalStarvationExhaustionActive',
        'appendTerminalStarvationReplenishmentCandidates',
        'terminalReplenishmentSeedIdForCategory',
        'terminalReplenishmentSeedForCategory',
        'priorityBacklogUnlockCategory',
        'enrichMaterializableBacklogItem',
        'rebalanceTerminalStarvationItem',
        'materializationPathsFromSource',
        'report',
    ];

    /** Host private method names before peel (must be gone). */
    /** @var list<string> */
    private const PEELED_HOST_PRIVATES = [
        'applyPriorityBacklogMaterialization',
        'terminalBacklogRebalanceActive',
        'terminalStarvationExhaustionActive',
        'appendTerminalStarvationReplenishmentCandidates',
        'terminalReplenishmentSeedIdForCategory',
        'terminalReplenishmentSeedForCategory',
        'priorityBacklogUnlockCategory',
        'enrichMaterializableBacklogItem',
        'rebalanceTerminalStarvationItem',
        'materializationPathsFromSource',
        'priorityBacklogMaterializationReport',
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
            'use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Support\StewardshipPriorityBacklogMaterializationSupport;',
            $hostSrc,
            'Host must import StewardshipPriorityBacklogMaterializationSupport',
        );
        $this->assertStringContainsString(
            'StewardshipPriorityBacklogMaterializationSupport::apply',
            $hostSrc,
            'Host must call Support::apply',
        );
        $this->assertStringContainsString(
            'StewardshipPriorityBacklogMaterializationSupport::report',
            $hostSrc,
            'Host must call Support::report',
        );

        foreach (self::PEELED_HOST_PRIVATES as $method) {
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

        $this->assertStringNotContainsString(
            'TERMINAL_STARVATION_REPLENISHMENT_UNLOCK_CATEGORIES',
            $hostSrc,
            'Terminal starvation constants must leave host',
        );
        $this->assertStringNotContainsString(
            'TERMINAL_STARVATION_REPLENISHMENT_LADDER',
            $hostSrc,
            'Terminal starvation ladder must leave host',
        );
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
    public function terminal_rebalance_and_starvation_flags_are_pure(): void
    {
        $this->assertFalse(Support::terminalBacklogRebalanceActive([]));
        $this->assertTrue(Support::terminalBacklogRebalanceActive([
            'terminal_backlog_state_hash' => 'abc',
        ]));
        $this->assertTrue(Support::terminalBacklogRebalanceActive([
            'terminal_backlog_rejection_reasons' => ['review_locked_existing_branch'],
        ]));

        $this->assertFalse(Support::terminalStarvationExhaustionActive([]));
        $this->assertFalse(Support::terminalStarvationExhaustionActive([
            'terminal_backlog_rejection_reasons' => ['review_locked_existing_branch'],
        ]));
        $this->assertTrue(Support::terminalStarvationExhaustionActive([
            'terminal_backlog_rejection_reasons' => [
                'review_locked_existing_branch',
                'no_executable_candidates_after_selection_pass',
            ],
        ]));
    }

    #[Test]
    public function unlock_category_classification_is_deterministic(): void
    {
        $this->assertSame(
            'owner_runtime',
            Support::priorityBacklogUnlockCategory('owner_runtime_bridge', [], []),
        );
        $this->assertSame(
            'scheduler',
            Support::priorityBacklogUnlockCategory('continuous_24h_scheduler', [], []),
        );
        $this->assertSame(
            'product_mode',
            Support::priorityBacklogUnlockCategory('x', ['title' => 'Product Mode controls_receipt'], []),
        );
        $this->assertSame(
            'forge_authority',
            Support::priorityBacklogUnlockCategory('provider_routing_after_owner_boundaries', [], [
                'title' => 'Real forge authority bootstrap AP-789',
            ]),
        );
        $this->assertSame(
            'merge',
            Support::priorityBacklogUnlockCategory('x', ['dependency_unlocks' => ['merge_queue']], []),
        );
        $this->assertSame(
            'deep_scan',
            Support::priorityBacklogUnlockCategory('x', ['type' => 'candidate_discovery'], []),
        );
        $this->assertSame(
            'priority_backlog',
            Support::priorityBacklogUnlockCategory('priority_engine_seed', [], []),
        );
        $this->assertSame(
            '',
            Support::priorityBacklogUnlockCategory('cosmetic_ui_only', ['type' => 'ui_cosmetic'], []),
        );
    }

    #[Test]
    public function materialization_paths_and_enrichment_are_pure(): void
    {
        $fromFiles = Support::materializationPathsFromSource([
            'affected_files' => ['app/Services/Foo.php'],
        ]);
        $this->assertSame('app/Services/Foo.php', $fromFiles['runtime']);
        $this->assertSame(
            'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/FooTest.php',
            $fromFiles['test'],
        );

        $fromEvidence = Support::materializationPathsFromSource([
            'completion_evidence' => [
                'service' => 'StewardshipMergeQueueService',
                'test' => 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipMergeQueueServiceTest.php',
            ],
        ]);
        $this->assertSame(
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipMergeQueueService.php',
            $fromEvidence['runtime'],
        );
        $this->assertSame(
            'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipMergeQueueServiceTest.php',
            $fromEvidence['test'],
        );

        $enriched = Support::enrichMaterializableBacklogItem(
            [
                'item_id' => 'merge_seed',
                'final_priority_score' => 10.0,
                'execution_readiness_score' => 1,
                'factory_leverage_score' => 1,
                'roi_score' => 1,
            ],
            [
                'completion_evidence' => [
                    'service' => 'StewardshipMergeQueueService',
                    'test' => 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipMergeQueueServiceTest.php',
                ],
                'owner_candidate' => 'forge',
            ],
            'merge',
            AreaFocusScopeProfileNormalizer::FACTORY_MAX,
        );

        $this->assertTrue($enriched['priority_backlog_materializable']);
        $this->assertSame('merge', $enriched['priority_backlog_unlock_category']);
        $this->assertTrue($enriched['factory_execution_ready']);
        $this->assertSame('forge', $enriched['owner_candidate']);
        $this->assertGreaterThanOrEqual(92, $enriched['execution_readiness_score']);
        $this->assertGreaterThanOrEqual(90, $enriched['factory_leverage_score']);
        $this->assertGreaterThanOrEqual(88, $enriched['roi_score']);
        $this->assertContains(
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipMergeQueueService.php',
            $enriched['affected_files'],
        );
    }

    #[Test]
    public function rebalance_terminal_starvation_promotes_to_now_lane(): void
    {
        $item = Support::rebalanceTerminalStarvationItem([
            'item_id' => 'x',
            'lane' => 'later',
            'final_priority_score' => 12.0,
            'risk_penalty' => 80,
            'rejection_reason' => 'something',
            'reason_machine' => ['prior_reason'],
        ], 'scheduler');

        $this->assertSame('now', $item['lane']);
        $this->assertGreaterThanOrEqual(78.0, (float) $item['final_priority_score']);
        $this->assertSame($item['final_priority_score'], $item['priority_score']);
        $this->assertLessThanOrEqual(24, $item['risk_penalty']);
        $this->assertSame('', $item['rejection_reason']);
        $this->assertContains('terminal_backlog_rebalance', $item['reason_machine']);
        $this->assertContains('unlock:scheduler', $item['reason_machine']);
        $this->assertSame(StewardshipPriorityScoringSupport::band(12.0), $item['priority_band']);
    }

    #[Test]
    public function replenishment_seeds_and_ids_are_stable(): void
    {
        $this->assertSame(
            'terminal_backlog_replenish_merge_queue',
            Support::terminalReplenishmentSeedIdForCategory('merge'),
        );
        $this->assertSame(
            'terminal_backlog_replenish_deep_scan',
            Support::terminalReplenishmentSeedIdForCategory('deep_scan'),
        );
        $this->assertSame(
            'terminal_backlog_replenish_priority_backlog',
            Support::terminalReplenishmentSeedIdForCategory('priority_backlog'),
        );
        $this->assertSame(
            'terminal_backlog_replenish_custom',
            Support::terminalReplenishmentSeedIdForCategory('custom'),
        );

        $merge = Support::terminalReplenishmentSeedForCategory('merge');
        $this->assertSame('terminal_backlog_replenish_merge_queue', $merge['id']);
        $this->assertSame('AP-772', $merge['ap_contract']);
        $this->assertSame('safety_robustness_unlock', $merge['type']);
        $this->assertContains('merge_queue', $merge['dependency_unlocks']);

        $deep = Support::terminalReplenishmentSeedForCategory('deep_scan');
        $this->assertSame('AP-748', $deep['ap_contract']);
        $this->assertSame('gap', $deep['type']);

        $priority = Support::terminalReplenishmentSeedForCategory('priority_backlog');
        $this->assertSame('AP-785', $priority['ap_contract']);
        $this->assertSame('StewardshipPriorityEngineService', $priority['completion_evidence']['service']);
    }

    #[Test]
    public function apply_with_starvation_appends_replenishment_seeds_and_report_summarizes(): void
    {
        $scored = StewardshipPriorityScoringSupport::scoreItem([
            'id' => 'continuous_24h_scheduler',
            'title' => '24h scheduler continuous loop',
            'type' => 'continuous_scheduler',
            'completion_status' => 'completed',
            'completion_evidence' => [
                'service' => 'Reliable24hLoopRunnerService',
                'test' => 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerServiceTest.php',
            ],
            'dependency_unlocks' => ['scheduler'],
        ], 0);

        $input = [
            'terminal_backlog_state_hash' => 'ec7740946157',
            'terminal_backlog_rejection_reasons' => [
                'no_executable_candidates_after_selection_pass',
            ],
            'has_live_forge_authority' => false,
        ];

        $candidates = [[
            'id' => 'continuous_24h_scheduler',
            'title' => '24h scheduler continuous loop',
            'type' => 'continuous_scheduler',
            'completion_status' => 'completed',
            'completion_evidence' => [
                'service' => 'Reliable24hLoopRunnerService',
                'test' => 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerServiceTest.php',
            ],
            'dependency_unlocks' => ['scheduler'],
        ]];

        $ranked = Support::apply(
            [$scored],
            $candidates,
            AreaFocusScopeProfileNormalizer::FACTORY_MAX,
            $input,
        );

        $ids = array_map(static fn (array $item): string => (string) ($item['item_id'] ?? ''), $ranked);
        $this->assertContains('continuous_24h_scheduler', $ids);
        $this->assertContains('terminal_backlog_replenish_merge_queue', $ids);
        $this->assertContains('terminal_backlog_replenish_deep_scan', $ids);
        $this->assertContains('terminal_backlog_replenish_priority_backlog', $ids);

        $scheduler = null;
        foreach ($ranked as $item) {
            if (($item['item_id'] ?? '') === 'continuous_24h_scheduler') {
                $scheduler = $item;
                break;
            }
        }
        $this->assertNotNull($scheduler);
        $this->assertSame('pending', $scheduler['completion_status']);
        $this->assertTrue($scheduler['priority_backlog_replenishment_anchor'] ?? false);
        $this->assertSame('scheduler', $scheduler['priority_backlog_unlock_category']);
        $this->assertSame('now', $scheduler['lane']);

        $report = Support::report($ranked, $input);
        $this->assertSame(
            'atlas.software_company_stewardship.priority_backlog_materialization.v1',
            $report['schema_version'],
        );
        $this->assertTrue($report['terminal_backlog_rebalance']);
        $this->assertSame('ec7740946157', $report['terminal_backlog_state_hash']);
        $this->assertSame(1, $report['terminal_backlog_rejection_reason_count']);
        $this->assertGreaterThanOrEqual(3, $report['replenishment_generated_count']);
        $this->assertContains('terminal_backlog_replenish_priority_backlog', $report['replenishment_generated_ids']);
        $this->assertContains('scheduler', $report['executable_unlock_categories']);
        $this->assertContains('merge', $report['executable_unlock_categories']);
    }

    #[Test]
    public function apply_empty_ranked_is_noop_and_report_zeroes(): void
    {
        $this->assertSame([], Support::apply([], [], 'balanced', []));

        $report = Support::report([], []);
        $this->assertFalse($report['terminal_backlog_rebalance']);
        $this->assertSame(0, $report['executable_unlock_count']);
        $this->assertSame([], $report['executable_unlock_categories']);
        $this->assertSame(0, $report['replenishment_generated_count']);
        $this->assertSame([], $report['replenishment_generated_ids']);
    }

    #[Test]
    public function append_skips_existing_merge_category_and_seed_ids(): void
    {
        $existingMerge = StewardshipPriorityScoringSupport::scoreItem([
            'id' => 'existing_merge_work',
            'title' => 'Merge queue unlock',
            'type' => 'safety_robustness_unlock',
            'dependency_unlocks' => ['merge_queue'],
        ], 0);
        $existingMerge = Support::enrichMaterializableBacklogItem(
            $existingMerge,
            ['dependency_unlocks' => ['merge_queue']],
            'merge',
            'balanced',
        );

        $appended = Support::appendTerminalStarvationReplenishmentCandidates(
            [$existingMerge],
            'balanced',
            [],
        );

        $ids = array_map(static fn (array $item): string => (string) ($item['item_id'] ?? ''), $appended);
        $this->assertNotContains('terminal_backlog_replenish_merge_queue', $ids);
        $this->assertContains('terminal_backlog_replenish_deep_scan', $ids);
        $this->assertContains('terminal_backlog_replenish_priority_backlog', $ids);
    }
}
