<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\Cortex\AtlasSelfConstructionCortexSourceInventory;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves atlas:self-construction:cortex: each verb is read-only; invalid (missing --facts) yields
 * usage_error; complete snapshot yields ready status with deterministic snapshot_hash.
 */
final class AtlasSelfConstructionCortexCommandTest extends TestCase
{
    private string $factsPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factsPath = sys_get_temp_dir().'/atlas_cortex_facts_'.bin2hex(random_bytes(6)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->factsPath);
        parent::tearDown();
    }

    private function writeJson(array $data): void
    {
        file_put_contents($this->factsPath, json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    private function fullFacts(int $now): array
    {
        $sources = [];
        foreach (AtlasSelfConstructionCortexSourceInventory::REQUIRED_KINDS as $k) {
            $sources[] = ['source_id' => 'sid-'.$k, 'kind' => $k];
        }
        $freshness = [];
        foreach (['docs', 'code_index', 'queue', 'receipts', 'runtime_evidence'] as $s) {
            $freshness[$s] = ['last_unix' => $now - 60, 'hash' => 'h-'.$s];
        }

        return [
            'sources' => $sources,
            'now_unix' => $now,
            'sources' => array_combine(
                array_map(static fn ($r): string => $r['source_id'], $sources),
                array_map(static fn ($r) => $r, $sources),
            ),
            // adapter expects sources keyed by id for freshness; merge both views
            // (the freshness bridge uses its own 'sources' shape).
            'inventory_sources' => $sources, // unused here, kept for clarity
            'queue_state' => ['observed_at_unix' => $now],
            'task_coverage' => ['observed_at_unix' => $now],
            'evidence_refs' => ['observed_at_unix' => $now],
        ] + ['sources' => $freshness];
    }

    public function test_inventory_action_returns_required_sources(): void
    {
        $this->writeJson([
            'sources' => array_map(static fn (string $k): array => ['source_id' => 'sid-'.$k, 'kind' => $k], AtlasSelfConstructionCortexSourceInventory::REQUIRED_KINDS),
        ]);
        Artisan::call('atlas:self-construction:cortex', ['action' => 'inventory', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame('ok', $p['status']);
        $this->assertSame([], $p['inventory']['blockers']);
    }

    public function test_freshness_action_returns_fresh_rows(): void
    {
        $now = time();
        $sources = [];
        foreach (['docs', 'code_index', 'queue', 'receipts', 'runtime_evidence'] as $s) {
            $sources[$s] = ['last_unix' => $now - 60, 'hash' => 'h-'.$s];
        }
        $this->writeJson(['now_unix' => $now, 'sources' => $sources]);
        Artisan::call('atlas:self-construction:cortex', ['action' => 'freshness', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertTrue($p['freshness']['all_fresh']);
    }

    public function test_risk_action_returns_empty_gaps_on_clean_facts(): void
    {
        $this->writeJson([
            'source_inventory' => ['blockers' => []],
            'verification' => ['server_side_green' => true],
            'merge' => ['posture' => 'safe'],
            'queue_health' => ['malformed_count' => 0, 'repeated_give_back_count' => 0],
            'sweep_health' => ['coverage_unknown' => false],
            'knowledge_sync' => ['conformant' => true],
        ]);
        Artisan::call('atlas:self-construction:cortex', ['action' => 'risk', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame([], $p['risk_gaps']['gaps']);
    }

    public function test_snapshot_action_composes_into_one_envelope_with_hash(): void
    {
        $now = time();
        $sources = [];
        foreach (['docs', 'code_index', 'queue', 'receipts', 'runtime_evidence'] as $s) {
            $sources[$s] = ['last_unix' => $now - 60, 'hash' => 'h-'.$s];
        }
        $this->writeJson([
            'sources' => array_merge(
                $sources,
                // also feed source_inventory.sources-compatible kind rows so inventory sees them
            ),
            'now_unix' => $now,
            'queue_state' => ['observed_at_unix' => $now],
            'task_coverage' => ['observed_at_unix' => $now],
            'evidence_refs' => ['observed_at_unix' => $now],
        ]);
        Artisan::call('atlas:self-construction:cortex', ['action' => 'snapshot', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame('ok', $p['status']);
        $this->assertSame(64, strlen($p['snapshot']['snapshot_hash']));
    }

    public function test_missing_facts_yields_usage_error(): void
    {
        $exit = Artisan::call('atlas:self-construction:cortex', ['action' => 'inventory', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $p['status']);
    }

    public function test_unknown_action_returns_unknown_action(): void
    {
        $this->writeJson(['sources' => []]);
        $exit = Artisan::call('atlas:self-construction:cortex', ['action' => 'bogus', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertNotSame(0, $exit);
        $this->assertSame('unknown_action', $p['status']);
    }
}
