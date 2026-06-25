<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Replenisher;

use App\Services\Ai\SelfConstruction\Replenisher\AtlasSelfConstructionFrontierToPacketDrafter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves AtlasSelfConstructionFrontierToPacketDrafter: a normalized frontier produces a packet with
 * allowed_files == frontier.allowed_file_candidates (no invention); duplicate suppression_key drops a
 * second identical entry; empty evidence_obligations ⇒ throws; bare directory in allowed_file_candidates
 * ⇒ throws; suppression_key is byte-stable across calls; depends_on + wave threaded through.
 */
final class AtlasSelfConstructionFrontierToPacketDrafterTest extends TestCase
{
    private function frontier(string $id = 'f-1', array $overrides = []): array
    {
        return array_merge([
            'frontier_id' => $id,
            'owner_organ' => 'TF',
            'target_scope' => 'app/Demo',
            'capability_gap' => 'add Foo',
            'allowed_file_candidates' => ['app/Demo/Foo.php', 'tests/Unit/Demo/FooTest.php'],
            'acceptance_obligations' => ['phpunit green'],
            'evidence_obligations' => ['test_run_id'],
            'risk_class' => 'standard',
        ], $overrides);
    }

    public function test_normalized_frontier_yields_packet_with_allowed_files_unchanged(): void
    {
        $packets = (new AtlasSelfConstructionFrontierToPacketDrafter)->draft([$this->frontier()]);
        $this->assertCount(1, $packets);
        $this->assertSame(['app/Demo/Foo.php', 'tests/Unit/Demo/FooTest.php'], $packets[0]['allowed_files']);
        $this->assertSame(['app/Demo/Foo.php'], $packets[0]['scope_in']);
    }

    public function test_duplicate_suppression_key_drops_second_entry(): void
    {
        $packets = (new AtlasSelfConstructionFrontierToPacketDrafter)->draft([
            $this->frontier('f-1'),
            $this->frontier('f-1'), // identical ⇒ same suppression_key
        ]);
        $this->assertCount(1, $packets);
    }

    public function test_empty_evidence_obligations_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/empty_evidence_obligations/');
        (new AtlasSelfConstructionFrontierToPacketDrafter)->draft([$this->frontier('f-empty', ['evidence_obligations' => []])]);
    }

    public function test_bare_directory_in_allowed_files_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/bare_directory_or_empty_path/');
        (new AtlasSelfConstructionFrontierToPacketDrafter)->draft([
            $this->frontier('f-bare', ['allowed_file_candidates' => ['app/Demo/']]),
        ]);
    }

    public function test_suppression_key_is_byte_stable_across_calls(): void
    {
        $d = new AtlasSelfConstructionFrontierToPacketDrafter;
        $a = $d->draft([$this->frontier()]);
        $b = $d->draft([$this->frontier()]);
        $this->assertSame($a[0]['suppression_key'], $b[0]['suppression_key']);
    }

    public function test_depends_on_and_wave_are_threaded_through(): void
    {
        $packets = (new AtlasSelfConstructionFrontierToPacketDrafter)->draft(
            [$this->frontier('f-1')],
            waveByFrontierId: ['f-1' => 3],
            dependsByFrontierId: ['f-1' => ['f-prev']],
        );
        $this->assertSame(3, $packets[0]['wave']);
        $this->assertSame(['f-prev'], $packets[0]['depends_on']);
    }

    public function test_autonomy_contract_is_attached_with_atlas_native_owner(): void
    {
        $packets = (new AtlasSelfConstructionFrontierToPacketDrafter)->draft([$this->frontier()]);
        $this->assertSame('atlas_native', $packets[0]['autonomy_contract']['runtime_owner']);
        $this->assertNull($packets[0]['autonomy_contract']['provider_prompt']);
    }
}
