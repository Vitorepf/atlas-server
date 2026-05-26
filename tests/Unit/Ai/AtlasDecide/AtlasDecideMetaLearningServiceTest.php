<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AtlasDecide;

use App\Services\Ai\AtlasDecide\AtlasDecideMetaLearningService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsDecideSignalProjectionService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsProviderPerformanceLedgerService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunPathResolver;
use Tests\TestCase;

/**
 * Unit tests for Atlas Decide · Meta-Learning Loop Closure.
 *
 * Uses the REAL projection + ledger services. The ledger is pointed at a
 * temporary directory so no real provider data is touched. No mocks.
 */
class AtlasDecideMetaLearningServiceTest extends TestCase
{
    private string $tmpRoot;

    private string $activationLog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().'/atlas_meta_learning_'.uniqid('', true);
        @mkdir($this->tmpRoot.'/runs', 0775, true);
        // Point the rivals runs root at our temp dir so the ledger reads from there.
        config(['atlas_rivals.runs_root' => $this->tmpRoot.'/runs']);
        $this->activationLog = $this->tmpRoot.'/routing_activations.jsonl';
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpRoot);
        parent::tearDown();
    }

    private function rmdirRecursive(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $full = $path.'/'.$f;
            is_dir($full) ? $this->rmdirRecursive($full) : @unlink($full);
        }
        @rmdir($path);
    }

    private function buildService(): AtlasDecideMetaLearningService
    {
        $paths = new AtlasForgeRivalsRunPathResolver;
        $ledger = new AtlasForgeRivalsProviderPerformanceLedgerService($paths);
        $projection = new AtlasForgeRivalsDecideSignalProjectionService($ledger);
        $svc = new AtlasDecideMetaLearningService($projection, $ledger);
        $svc->setActivationLogPathForTesting($this->activationLog);

        return $svc;
    }

    public function test_recommend_with_no_ledger_returns_insufficient_evidence(): void
    {
        $svc = $this->buildService();
        $rec = $svc->recommend(['task_category' => 'frontend', 'role' => 'builder']);

        $this->assertSame(AtlasDecideMetaLearningService::RECOMMENDATION_SCHEMA, $rec['schema_version']);
        $this->assertSame('insufficient_evidence', $rec['signal']);
        $this->assertFalse($rec['actionable']);
        $this->assertSame(AtlasDecideMetaLearningService::MODE_SHADOW, $rec['mode']);
        $this->assertContains('insufficient_evidence', $rec['reason']);
        $this->assertStringStartsWith('sha256:', $rec['recommendation_hash']);
    }

    public function test_recommend_all_returns_well_shaped_list(): void
    {
        $recs = $this->buildService()->recommendAll();
        $this->assertIsArray($recs);
        // Production ledger may have entries; we only validate shape, not count.
        foreach ($recs as $r) {
            $this->assertSame(AtlasDecideMetaLearningService::RECOMMENDATION_SCHEMA, $r['schema_version']);
            $this->assertArrayHasKey('scope', $r);
            $this->assertArrayHasKey('signal', $r);
            $this->assertArrayHasKey('recommendation_hash', $r);
        }
    }

    public function test_routing_table_with_no_receipts_is_empty(): void
    {
        $table = $this->buildService()->routingTable();
        $this->assertSame(AtlasDecideMetaLearningService::TABLE_SCHEMA, $table['schema_version']);
        $this->assertSame(0, $table['active_entries']);
        $this->assertSame(0, $table['shadow_entries']);
        $this->assertSame([], $table['entries']);
    }

    public function test_activate_blocks_when_recommendation_not_actionable(): void
    {
        $svc = $this->buildService();
        $this->expectException(\InvalidArgumentException::class);
        $svc->applyAction([
            'action' => AtlasDecideMetaLearningService::ACTION_ACTIVATE,
            'task_category' => 'frontend',
            'role' => 'builder',
        ]);
    }

    public function test_deactivate_appends_receipt_without_actionability_check(): void
    {
        $svc = $this->buildService();
        $r = $svc->applyAction([
            'action' => AtlasDecideMetaLearningService::ACTION_DEACTIVATE,
            'task_category' => 'frontend',
            'role' => 'builder',
        ]);
        $this->assertSame(AtlasDecideMetaLearningService::ACTIVATION_SCHEMA, $r['schema_version']);
        $this->assertSame('deactivate', $r['action']);
        $this->assertSame('shadow', $r['new_mode']);
        $this->assertFileExists($this->activationLog);
    }

    public function test_reset_clears_table_state(): void
    {
        $svc = $this->buildService();
        $svc->applyAction([
            'action' => AtlasDecideMetaLearningService::ACTION_DEACTIVATE,
            'task_category' => 'frontend',
            'role' => 'builder',
        ]);
        $r = $svc->applyAction(['action' => AtlasDecideMetaLearningService::ACTION_RESET]);
        $this->assertSame('reset', $r['action']);
        $table = $svc->routingTable();
        $this->assertSame(0, $table['active_entries']);
        $this->assertNotNull($table['last_reset_at']);
    }

    public function test_invalid_action_is_rejected(): void
    {
        $svc = $this->buildService();
        $this->expectException(\InvalidArgumentException::class);
        $svc->applyAction(['action' => 'not_a_real_action']);
    }

    public function test_recommendation_hash_is_deterministic_for_same_state(): void
    {
        $svc = $this->buildService();
        $a = $svc->recommend(['task_category' => 'frontend', 'role' => 'builder']);
        $b = $svc->recommend(['task_category' => 'frontend', 'role' => 'builder']);
        $this->assertSame($a['recommendation_hash'], $b['recommendation_hash']);
    }

    public function test_active_route_for_returns_null_when_no_activation(): void
    {
        $svc = $this->buildService();
        $this->assertNull($svc->activeRouteFor('frontend', 'builder'));
    }

    public function test_activate_requires_task_and_role(): void
    {
        $svc = $this->buildService();
        $this->expectException(\InvalidArgumentException::class);
        $svc->applyAction(['action' => AtlasDecideMetaLearningService::ACTION_ACTIVATE]);
    }

    public function test_table_hash_is_deterministic_across_invocations(): void
    {
        $svc = $this->buildService();
        $a = $svc->routingTable()['table_hash'];
        $b = $svc->routingTable()['table_hash'];
        // Hash spans canonical fields only — timestamps excluded by design.
        $this->assertSame($a, $b);
        $this->assertStringStartsWith('sha256:', $a);
    }

    public function test_recommendation_has_all_required_fields(): void
    {
        $rec = $this->buildService()->recommend([
            'task_category' => 'frontend',
            'role' => 'builder',
            'framework' => 'react',
        ]);
        foreach ([
            'schema_version', 'generated_at', 'scope', 'signal', 'confidence',
            'evidence_count', 'stale_evidence', 'recommended_provider',
            'recommended_model', 'mode', 'actionable', 'requires_human_review',
            'reason', 'rationale', 'recommendation_hash',
        ] as $k) {
            $this->assertArrayHasKey($k, $rec);
        }
        $this->assertSame('react', $rec['scope']['framework']);
    }
}
