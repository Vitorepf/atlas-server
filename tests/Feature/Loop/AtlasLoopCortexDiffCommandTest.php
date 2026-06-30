<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionReadModel;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Diff\AtlasCortexSnapshotDiffReceiptLedger;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasLoopCortexDiffCommandTest extends TestCase
{
    private string $readModelRoot = '';

    private string $ledgerRoot = '';

    private string $left = 'snap-left';

    private string $right = 'snap-right';

    private string $scope = 'app/Services/Ai/AutonomousEvolution';

    protected function setUp(): void
    {
        parent::setUp();
        $this->readModelRoot = sys_get_temp_dir().'/atlas-cortex-readmodel-'.bin2hex(random_bytes(6));
        $this->ledgerRoot = sys_get_temp_dir().'/atlas-cortex-ledger-'.bin2hex(random_bytes(6));
        @mkdir($this->readModelRoot, 0o755, true);
        @mkdir($this->ledgerRoot, 0o755, true);

        $readModel = new AtlasLoopScopeComprehensionReadModel();
        $readModel->setRootForTesting($this->readModelRoot);
        $this->app->instance(AtlasLoopScopeComprehensionReadModel::class, $readModel);
        AtlasCortexSnapshotDiffReceiptLedger::setRootForTesting($this->ledgerRoot);

        // Seed two snapshots with structurally distinct inventories.
        $readModel->put($this->snapshotModel($this->left, ['app/A.php']), $this->scope, builtAtUnix: 1000);
        $readModel->put($this->snapshotModel($this->right, ['app/A.php', 'app/B.php']), $this->scope, builtAtUnix: 2000);
    }

    protected function tearDown(): void
    {
        AtlasCortexSnapshotDiffReceiptLedger::setRootForTesting(null);
        $this->rrmdir($this->readModelRoot);
        $this->rrmdir($this->ledgerRoot);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir.'/'.$entry;
            if (is_dir($full)) {
                $this->rrmdir($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($dir);
    }

    /**
     * @param  list<string>  $inventoryPaths
     */
    private function snapshotModel(string $snapshotId, array $inventoryPaths): AtlasLoopScopeComprehensionModel
    {
        $inventory = [];
        foreach ($inventoryPaths as $p) {
            $fqcn = 'App\\Demo\\'.basename($p, '.php');
            $inventory[] = ['fqcn' => $fqcn, 'path' => $p, 'kind' => 'class'];
        }

        return new AtlasLoopScopeComprehensionModel(
            inventory: $inventory,
            edges: [],
            orphans: [],
            cloneClusters: [],
            forbidden: [],
            docPurposes: [],
            docStatedGaps: [],
            snapshotId: $snapshotId,
        );
    }

    private function ledgerFile(): string
    {
        return $this->ledgerRoot.'/'.date('Y-m-d').'.jsonl';
    }

    public function test_inspect_returns_full_categorized_diff_array_with_schema(): void
    {
        $exit = Artisan::call('atlas:loop:cortex:diff', [
            'action' => 'inspect',
            '--left' => $this->left,
            '--right' => $this->right,
            '--scope' => $this->scope,
            '--json' => true,
        ]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.cortex.snapshot_diff.v1', $p['schema_version']);
        $this->assertSame($this->left, $p['left']['snapshot_id']);
        $this->assertSame($this->right, $p['right']['snapshot_id']);
        $this->assertContains('App\\Demo\\B', $p['inventory']['added']);
    }

    public function test_summary_emits_digest_with_no_aggregate_scalar_keys(): void
    {
        $exit = Artisan::call('atlas:loop:cortex:diff', [
            'action' => 'summary',
            '--left' => $this->left,
            '--right' => $this->right,
            '--scope' => $this->scope,
            '--json' => true,
        ]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        foreach (['total_changes', 'total', 'severity', 'score', 'weight', 'importance', 'improvement_score'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $p, "summary must NOT contain forbidden aggregate key {$forbidden}");
        }
        $this->assertSame(1, $p['inventory_added']);
        $this->assertContains('inventory_added', $p['nonEmptyCategories']);
    }

    public function test_export_writes_jsonl_and_records_one_receipt(): void
    {
        $out = $this->ledgerRoot.'/exported.jsonl';
        $exit = Artisan::call('atlas:loop:cortex:diff', [
            'action' => 'export',
            '--left' => $this->left,
            '--right' => $this->right,
            '--scope' => $this->scope,
            '--out' => $out,
            '--json' => true,
        ]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame($out, $p['path']);
        $this->assertGreaterThan(0, $p['exported']);
        $this->assertNotEmpty($p['sha256']);
        $this->assertFileExists($out);

        // ONE receipt line in the ledger for this (left,right) pair.
        $this->assertFileExists($this->ledgerFile());
        $contents = (string) file_get_contents($this->ledgerFile());
        $this->assertSame(1, substr_count($contents, "\n"));
    }

    public function test_missing_snapshot_id_exits_non_zero_and_records_no_receipt(): void
    {
        $ledgerExistedBefore = is_file($this->ledgerFile());

        $exit = Artisan::call('atlas:loop:cortex:diff', [
            'action' => 'inspect',
            '--left' => 'snap-does-not-exist',
            '--right' => $this->right,
            '--scope' => $this->scope,
            '--json' => true,
        ]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('left_snapshot_missing', $p['error']);
        if (! $ledgerExistedBefore) {
            $this->assertFileDoesNotExist($this->ledgerFile(), 'failure path must NOT record a receipt');
        }
    }

    public function test_default_invocation_diffs_previous_vs_latest_not_self(): void
    {
        // Omit --left: must resolve to previousLatestFor (snap-left, builtAtUnix=1000)
        // Omit --right: resolves to latestFor (snap-right, builtAtUnix=2000)
        // The diff must be non-empty (B added), not an empty self-diff.
        $exit = Artisan::call('atlas:loop:cortex:diff', [
            'action' => 'inspect',
            '--scope' => $this->scope,
            '--json' => true,
        ]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit, 'default invocation must succeed: '.json_encode($p));
        $this->assertNotEmpty($p['inventory']['added'] ?? [], 'default diff must be non-empty — previous vs latest, not self-diff');
        $this->assertContains('App\\Demo\\B', $p['inventory']['added']);
    }

    public function test_signature_is_registered(): void
    {
        $code = Artisan::call('list', []);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('atlas:loop:cortex:diff', Artisan::output());
    }
}
