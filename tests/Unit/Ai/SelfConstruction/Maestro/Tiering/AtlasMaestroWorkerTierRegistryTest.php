<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Tiering;

use App\Services\Ai\SelfConstruction\Maestro\Tiering\AtlasMaestroWorkerTierRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves the Maestro worker-tier registry: register/lookup roundtrip preserves declared_max_tier + meta;
 * snapshot is deterministically ordered by client_id; revoke is atomic (lookup returns null after); an
 * invalid declaredMaxTier throws and never persists.
 */
final class AtlasMaestroWorkerTierRegistryTest extends TestCase
{
    private string $snapshotPath;

    private AtlasMaestroWorkerTierRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotPath = sys_get_temp_dir().'/atlas_maestro_tier_'.bin2hex(random_bytes(6)).'.json';
        $this->registry = new AtlasMaestroWorkerTierRegistry($this->snapshotPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->snapshotPath);
        parent::tearDown();
    }

    public function test_register_then_lookup_returns_record_with_declared_tier_and_meta_verbatim(): void
    {
        $rec = $this->registry->register('sonnet-cli-1', 'easy', ['model' => 'sonnet-4', 'rps' => 5]);
        $this->assertSame('sonnet-cli-1', $rec->clientId);
        $this->assertSame('easy', $rec->declaredMaxTier);

        $hit = $this->registry->lookup('sonnet-cli-1');
        $this->assertNotNull($hit);
        $this->assertSame('easy', $hit->declaredMaxTier);
        $this->assertSame(['model' => 'sonnet-4', 'rps' => 5], $hit->meta);
    }

    public function test_two_clients_produce_snapshot_ordered_by_client_id(): void
    {
        $this->registry->register('zeta', 'hard');
        $this->registry->register('alpha', 'easy');
        $this->registry->register('mu', 'hardest');

        $raw = file_get_contents($this->snapshotPath);
        $decoded = json_decode($raw, true);
        $ids = array_column($decoded, 'client_id');
        $this->assertSame(['alpha', 'mu', 'zeta'], $ids, 'snapshot ordered ascending by client_id');
    }

    public function test_byte_identical_snapshot_for_same_logical_state_regardless_of_registration_order(): void
    {
        $this->registry->register('b', 'hard');
        $this->registry->register('a', 'easy');
        $snapA = file_get_contents($this->snapshotPath);
        @unlink($this->snapshotPath);

        $reg2 = new AtlasMaestroWorkerTierRegistry($this->snapshotPath);
        $reg2->register('a', 'easy');
        $reg2->register('b', 'hard');
        $snapB = file_get_contents($this->snapshotPath);

        $this->assertSame($snapA, $snapB, 'snapshot is order-independent for the same logical state');
    }

    public function test_revoke_removes_the_client_and_rewrites_atomically(): void
    {
        $this->registry->register('a', 'easy');
        $this->registry->register('b', 'hardest');
        $this->assertTrue($this->registry->revoke('a'));
        $this->assertNull($this->registry->lookup('a'));
        $this->assertNotNull($this->registry->lookup('b'));

        // Atomic: no leftover temp files in the snapshot dir.
        $glob = glob(dirname($this->snapshotPath).'/'.basename($this->snapshotPath).'.tmp.*');
        $this->assertSame([], $glob ?: [], 'no leftover .tmp.* file from atomic rename');
    }

    public function test_invalid_tier_throws_and_does_not_persist(): void
    {
        try {
            $this->registry->register('a', 'opus-supreme');
            $this->fail('expected exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('declaredMaxTier outside allowed set', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->snapshotPath, 'bad input must NOT write a snapshot');
    }

    public function test_list_returns_all_registered_clients(): void
    {
        $this->registry->register('a', 'easy');
        $this->registry->register('b', 'hard');
        $this->registry->register('c', 'hardest');
        $rows = $this->registry->list();
        $this->assertCount(3, $rows);
        $ids = array_map(static fn ($r): string => $r->clientId, $rows);
        sort($ids);
        $this->assertSame(['a', 'b', 'c'], $ids);
    }

    public function test_revoking_unknown_client_returns_false(): void
    {
        $this->assertFalse($this->registry->revoke('ghost'));
    }

    // ---------- tierFor() dispatch policy ----------

    public function test_single_file_low_risk_task_maps_to_easy_tier(): void
    {
        $result = $this->registry->tierFor([
            'risk_level'       => 'low',
            'allowed_files'    => ['app/Foo.php'],
            'required_evidence'=> ['tests_or_gates_result'],
        ]);

        $this->assertSame('easy', $result['assigned_tier']);
        $this->assertSame([], $result['escalation_reasons']);
        $this->assertSame(1, $result['local_facts']['allowed_files_count']);
        $this->assertSame('low', $result['local_facts']['risk_level']);
        $this->assertCount(3, $result['tier_candidates'], 'three signals produce three candidates');
    }

    public function test_multi_file_integration_task_maps_to_hard_tier(): void
    {
        $result = $this->registry->tierFor([
            'risk_level'       => 'low',
            'allowed_files'    => ['app/A.php', 'app/B.php', 'tests/ATest.php'],
            'required_evidence'=> ['tests_or_gates_result'],
        ]);

        $this->assertSame('hard', $result['assigned_tier']);
        $this->assertContains('allowed_files_count:3', $result['escalation_reasons']);
    }

    public function test_high_risk_runtime_task_maps_to_hardest_tier(): void
    {
        $result = $this->registry->tierFor([
            'risk_level'       => 'high',
            'allowed_files'    => ['app/Foo.php'],
            'required_evidence'=> ['tests_or_gates_result'],
        ]);

        $this->assertSame('hardest', $result['assigned_tier']);
        $this->assertContains('risk_level:high', $result['escalation_reasons']);
    }

    public function test_heavy_evidence_requirement_escalates_to_hard(): void
    {
        $result = $this->registry->tierFor([
            'risk_level'       => 'low',
            'allowed_files'    => ['app/Foo.php'],
            'required_evidence'=> ['tests_or_gates_result', 'implementation_notes', 'diff_review'],
        ]);

        $this->assertSame('hard', $result['assigned_tier']);
        $this->assertContains('evidence_count:3', $result['escalation_reasons']);
    }

    public function test_tier_for_is_deterministic_across_two_calls(): void
    {
        $packet = [
            'risk_level'       => 'medium',
            'allowed_files'    => ['app/A.php', 'app/B.php'],
            'required_evidence'=> ['tests_or_gates_result', 'implementation_notes'],
        ];
        $first  = $this->registry->tierFor($packet);
        $second = $this->registry->tierFor($packet);

        $this->assertSame(
            json_encode($first,  JSON_UNESCAPED_SLASHES),
            json_encode($second, JSON_UNESCAPED_SLASHES),
        );
    }

    // ---------- verifyIntegrity() ----------

    private function canonicalTiers(): array
    {
        return [
            ['tier_id' => 'atlas_native', 'max_concurrency' => 4, 'steady_state_external_provider_required' => false],
            ['tier_id' => 'minimax_m3',   'max_concurrency' => 8, 'steady_state_external_provider_required' => false],
        ];
    }

    public function test_canonical_tier_registry_passes_integrity_verification(): void
    {
        $result = $this->registry->verifyIntegrity($this->canonicalTiers());
        $this->assertTrue($result['passed']);
        $this->assertSame([], $result['blockers']);
    }

    public function test_duplicate_tier_id_blocks_integrity(): void
    {
        $tiers = [
            ['tier_id' => 'atlas_native', 'max_concurrency' => 4, 'steady_state_external_provider_required' => false],
            ['tier_id' => 'atlas_native', 'max_concurrency' => 2, 'steady_state_external_provider_required' => false],
        ];
        $result = $this->registry->verifyIntegrity($tiers);
        $this->assertFalse($result['passed']);
        $this->assertContains('duplicate_tier_id:atlas_native', $result['blockers']);
    }

    public function test_missing_atlas_native_tier_blocks_integrity(): void
    {
        $tiers = [
            ['tier_id' => 'minimax_m3', 'max_concurrency' => 8, 'steady_state_external_provider_required' => false],
        ];
        $result = $this->registry->verifyIntegrity($tiers);
        $this->assertFalse($result['passed']);
        $this->assertContains('missing_atlas_native_tier', $result['blockers']);
    }

    public function test_missing_max_concurrency_blocks_integrity(): void
    {
        $tiers = [
            ['tier_id' => 'atlas_native', 'steady_state_external_provider_required' => false],
        ];
        $result = $this->registry->verifyIntegrity($tiers);
        $this->assertFalse($result['passed']);
        $this->assertContains('missing_max_concurrency:atlas_native', $result['blockers']);
    }

    public function test_steady_state_external_provider_required_blocks_integrity(): void
    {
        $tiers = [
            ['tier_id' => 'atlas_native', 'max_concurrency' => 4, 'steady_state_external_provider_required' => false],
            ['tier_id' => 'cloud_only',   'max_concurrency' => 2, 'steady_state_external_provider_required' => true],
        ];
        $result = $this->registry->verifyIntegrity($tiers);
        $this->assertFalse($result['passed']);
        $this->assertContains('steady_state_external_provider_required:cloud_only', $result['blockers']);
    }

    public function test_tier_for_local_facts_never_contain_scalar_score_or_rank(): void
    {
        $result = $this->registry->tierFor([
            'risk_level'       => 'medium',
            'allowed_files'    => ['app/Foo.php', 'app/Bar.php'],
            'required_evidence'=> ['tests_or_gates_result'],
        ]);

        $json = (string) json_encode($result);
        $this->assertStringNotContainsString('"score"', $json);
        $this->assertStringNotContainsString('"rank"', $json);
        $this->assertStringNotContainsString('"percent"', $json);
    }
}
