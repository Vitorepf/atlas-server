<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\HardCaseBench\AtlasLoopHardCaseDatasetRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Proves the hard-case dataset registry: persists with case ids sorted; digest() is deterministic byte-true
 * across consecutive calls; mutating an already-registered case_id (same id, different payload) throws
 * InvalidArgumentException; identical re-register is a no-op; get/bySource/byScope filter correctly and an
 * unknown case_id returns null without throwing.
 */
final class AtlasLoopHardCaseDatasetRegistryTest extends TestCase
{
    private string $tmpDir;

    private AtlasLoopHardCaseDatasetRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/atlas_hardcase_registry_'.bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0775, true);
        $this->registry = new AtlasLoopHardCaseDatasetRegistry($this->tmpDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            @unlink($this->tmpDir.'/registry.json');
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    private function case(string $id, string $source = 'give_back', string $scope = 'app/Foo'): array
    {
        return [
            'case_id' => $id,
            'slug' => 'slug-'.$id,
            'captured_at' => '2026-06-24T12:00:00Z',
            'source' => $source,
            'scope_root' => $scope,
            'failure_signature' => 'sig-'.$id,
            'original_attempt_ledger_digest' => 'led-'.$id,
            'minimal_repro_seed' => ['seed' => $id],
            'expected_failure_mode' => 'mode-'.$id,
        ];
    }

    public function test_persisted_file_orders_cases_by_case_id_ascending(): void
    {
        $this->registry->register($this->case('zzz'));
        $this->registry->register($this->case('aaa'));
        $this->registry->register($this->case('mmm'));

        $decoded = (array) json_decode((string) file_get_contents($this->tmpDir.'/registry.json'), true);
        $ids = array_map(static fn (array $r): string => (string) $r['case_id'], $decoded);
        $this->assertSame(['aaa', 'mmm', 'zzz'], $ids);
    }

    public function test_digest_is_deterministic_byte_identical_across_two_calls(): void
    {
        $this->registry->register($this->case('a'));
        $this->registry->register($this->case('b'));

        $this->assertSame($this->registry->digest(), $this->registry->digest());
        $this->assertSame(64, strlen($this->registry->digest()));
    }

    public function test_register_with_existing_case_id_and_changed_payload_throws(): void
    {
        $this->registry->register($this->case('x'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/append-only/');
        $modified = $this->case('x');
        $modified['failure_signature'] = 'MUTATED';
        $this->registry->register($modified);
    }

    public function test_register_identical_payload_twice_is_a_no_op(): void
    {
        $this->registry->register($this->case('y'));
        $this->registry->register($this->case('y'));
        $this->assertCount(1, $this->registry->all());
    }

    public function test_get_by_source_by_scope_and_unknown_case_returns_null(): void
    {
        $this->registry->register($this->case('a', source: 'give_back', scope: 'app/A'));
        $this->registry->register($this->case('b', source: 'cancellation', scope: 'app/B'));
        $this->registry->register($this->case('c', source: 'give_back', scope: 'app/B'));

        $this->assertSame('a', $this->registry->get('a')['case_id']);
        $this->assertNull($this->registry->get('nope'));
        $this->assertCount(2, $this->registry->bySource('give_back'));
        $this->assertCount(1, $this->registry->bySource('cancellation'));
        $this->assertCount(2, $this->registry->byScope('app/B'));
    }

    public function test_unknown_source_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $bad = $this->case('z');
        $bad['source'] = 'totally_bogus';
        $this->registry->register($bad);
    }

    public function test_empty_case_id_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $bad = $this->case('');
        $this->registry->register($bad);
    }

    public function test_registry_reloads_from_disk_on_a_fresh_instance(): void
    {
        $this->registry->register($this->case('reload-1'));
        $fresh = new AtlasLoopHardCaseDatasetRegistry($this->tmpDir);
        $this->assertSame('reload-1', $fresh->get('reload-1')['case_id']);
    }
}
