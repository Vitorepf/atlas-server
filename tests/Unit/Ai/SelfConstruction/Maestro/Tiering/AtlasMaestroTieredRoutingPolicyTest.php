<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Tiering;

use App\Services\Ai\SelfConstruction\Maestro\Tiering\AtlasMaestroTaskTierClassifier;
use App\Services\Ai\SelfConstruction\Maestro\Tiering\AtlasMaestroTieredRoutingPolicy;
use App\Services\Ai\SelfConstruction\Maestro\Tiering\AtlasMaestroWorkerTierRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Proves the advisory tiered-routing policy: hardest packet to an easy worker is refused; easy packet to
 * a hardest worker is allowed (workers serve their tier and below); unknown worker yields
 * allow_unknown_worker so legacy un-tiered flows stay green; identical (clientId, packet) returns a
 * byte-identical verdict.
 */
final class AtlasMaestroTieredRoutingPolicyTest extends TestCase
{
    private string $snapshotPath;

    private AtlasMaestroWorkerTierRegistry $registry;

    private AtlasMaestroTieredRoutingPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotPath = sys_get_temp_dir().'/atlas_routing_'.bin2hex(random_bytes(6)).'.json';
        $this->registry = new AtlasMaestroWorkerTierRegistry($this->snapshotPath);
        $this->policy = new AtlasMaestroTieredRoutingPolicy(new AtlasMaestroTaskTierClassifier, $this->registry);
    }

    protected function tearDown(): void
    {
        @unlink($this->snapshotPath);
        parent::tearDown();
    }

    private function hardestPacket(): array
    {
        return [
            'packet_id' => 'p-hardest',
            'objective' => 'edit constitution',
            'allowed_files' => ['app/Services/Ai/AutonomousEvolution/Constitution/Bylaw.php'],
            'acceptance_criteria' => ['ok'],
        ];
    }

    private function easyPacket(): array
    {
        return [
            'packet_id' => 'p-easy',
            'objective' => 'add a one-line banner.',
            'allowed_files' => ['app/Console/Commands/AtlasFooCommand.php'],
            'acceptance_criteria' => ['banner prints'],
        ];
    }

    public function test_hardest_packet_to_easy_worker_returns_refuse_with_both_tiers_cited(): void
    {
        $this->registry->register('sonnet-1', 'easy');
        $v = $this->policy->evaluate('sonnet-1', $this->hardestPacket());

        $this->assertSame(AtlasMaestroTieredRoutingPolicy::VERDICT_REFUSE, $v['verdict']);
        $this->assertSame('hardest', $v['packet_tier']);
        $this->assertSame('easy', $v['worker_declared_max_tier']);
        $this->assertStringContainsString('hardest', $v['reason']);
        $this->assertStringContainsString('easy', $v['reason']);
        $this->assertNotEmpty($v['packet_fact_basis']);
    }

    public function test_easy_packet_to_hardest_worker_returns_allow(): void
    {
        $this->registry->register('opus-1', 'hardest');
        $v = $this->policy->evaluate('opus-1', $this->easyPacket());

        $this->assertSame(AtlasMaestroTieredRoutingPolicy::VERDICT_ALLOW, $v['verdict']);
        $this->assertSame('easy', $v['packet_tier']);
        $this->assertSame('hardest', $v['worker_declared_max_tier']);
    }

    public function test_unknown_worker_yields_allow_unknown_worker_preserving_legacy_flow(): void
    {
        $v = $this->policy->evaluate('mystery-cli', $this->hardestPacket());
        $this->assertSame(AtlasMaestroTieredRoutingPolicy::VERDICT_ALLOW_UNKNOWN, $v['verdict']);
        $this->assertNull($v['worker_declared_max_tier']);
    }

    public function test_two_invocations_with_same_input_return_byte_identical_json(): void
    {
        $this->registry->register('any-1', 'hard');
        $packet = $this->easyPacket();
        $a = json_encode($this->policy->evaluate('any-1', $packet), JSON_UNESCAPED_SLASHES);
        $b = json_encode($this->policy->evaluate('any-1', $packet), JSON_UNESCAPED_SLASHES);
        $this->assertSame($a, $b);
    }

    public function test_output_has_canonical_schema(): void
    {
        $v = $this->policy->evaluate('anyone', $this->easyPacket());
        $this->assertSame('atlas.maestro.tier_routing.v1', $v['schema']);
    }

    public function test_hard_packet_to_hard_worker_is_allowed(): void
    {
        $this->registry->register('codex-1', 'hard');
        $hardPacket = [
            'packet_id' => 'p-hard',
            'objective' => str_repeat('x', 1500),
            'allowed_files' => ['app/A.php'],
            'acceptance_criteria' => [],
        ];
        $v = $this->policy->evaluate('codex-1', $hardPacket);
        $this->assertSame(AtlasMaestroTieredRoutingPolicy::VERDICT_ALLOW, $v['verdict']);
    }
}
