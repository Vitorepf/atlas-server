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

    public function test_high_risk_packet_escalated_to_hardest_worker_is_allowed(): void
    {
        $this->registry->register('hardest-worker', 'hardest');
        $hardPacket = [
            'packet_id' => 'p-hard-esc',
            'objective' => str_repeat('x', 1500), // >= 1200 → hard
            'allowed_files' => ['app/A.php'],
            'acceptance_criteria' => [],
        ];
        $v = $this->policy->evaluate('hardest-worker', $hardPacket);

        $this->assertSame(AtlasMaestroTieredRoutingPolicy::VERDICT_ALLOW, $v['verdict']);
        $this->assertSame('hard', $v['packet_tier']);
        $this->assertSame('hardest', $v['worker_declared_max_tier']);
    }

    public function test_evidence_burden_escalation_six_acceptance_criteria_escalates_to_hard_tier(): void
    {
        $this->registry->register('easy-worker', 'easy');
        $heavyBurdenPacket = [
            'packet_id' => 'p-burden',
            'objective' => 'do something',
            'allowed_files' => ['app/A.php'],
            'acceptance_criteria' => array_fill(0, 6, 'a criterion'), // 6 >= threshold → hard
        ];
        $v = $this->policy->evaluate('easy-worker', $heavyBurdenPacket);

        $this->assertSame(AtlasMaestroTieredRoutingPolicy::VERDICT_REFUSE, $v['verdict']);
        $this->assertSame('hard', $v['packet_tier']);
        $this->assertContains('acceptance_criteria count >= 6', $v['packet_fact_basis']);
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

    // ── governed override on tier mismatch ──────────────────────────────────────

    public function test_hardest_packet_to_easy_worker_without_override_is_refused(): void
    {
        $this->registry->register('plain-1', 'easy');
        $v = $this->policy->evaluate('plain-1', $this->hardestPacket());

        $this->assertSame(AtlasMaestroTieredRoutingPolicy::VERDICT_REFUSE, $v['verdict']);
        $this->assertFalse($v['override_applied']);
    }

    public function test_hardest_packet_to_easy_worker_with_explicit_override_is_allow_with_review(): void
    {
        $this->registry->register('override-1', 'easy', ['override_tier_mismatch' => true]);
        $v = $this->policy->evaluate('override-1', $this->hardestPacket());

        $this->assertSame(AtlasMaestroTieredRoutingPolicy::VERDICT_ALLOW_WITH_REVIEW, $v['verdict']);
        $this->assertTrue($v['override_applied']);
        $this->assertStringContainsString('override', $v['reason']);
    }

    // ── low confidence downgrades a hard/hardest in-tier allow ─────────────────

    public function test_low_confidence_worker_on_hard_packet_is_allow_with_review_not_silent_allow(): void
    {
        $this->registry->register('shaky-1', 'hard', ['outcome_confidence' => 0.2]);
        $hardPacket = [
            'packet_id' => 'p-hard-conf',
            'objective' => str_repeat('x', 1500),
            'allowed_files' => ['app/A.php'],
            'acceptance_criteria' => [],
        ];
        $v = $this->policy->evaluate('shaky-1', $hardPacket);

        $this->assertSame(AtlasMaestroTieredRoutingPolicy::VERDICT_ALLOW_WITH_REVIEW, $v['verdict']);
        $this->assertSame(0.2, $v['worker_outcome_confidence']);
        $this->assertFalse($v['override_applied']);
    }

    public function test_high_confidence_worker_on_hard_packet_is_silently_allowed(): void
    {
        $this->registry->register('reliable-1', 'hard', ['outcome_confidence' => 0.9]);
        $hardPacket = [
            'packet_id' => 'p-hard-conf-ok',
            'objective' => str_repeat('x', 1500),
            'allowed_files' => ['app/A.php'],
            'acceptance_criteria' => [],
        ];
        $v = $this->policy->evaluate('reliable-1', $hardPacket);

        $this->assertSame(AtlasMaestroTieredRoutingPolicy::VERDICT_ALLOW, $v['verdict']);
        $this->assertSame(0.9, $v['worker_outcome_confidence']);
    }

    public function test_low_confidence_worker_on_easy_packet_is_still_silently_allowed(): void
    {
        // Confidence floor only applies to hard/hardest packets — easy packets stay silent allow.
        $this->registry->register('shaky-2', 'hard', ['outcome_confidence' => 0.1]);
        $v = $this->policy->evaluate('shaky-2', $this->easyPacket());

        $this->assertSame(AtlasMaestroTieredRoutingPolicy::VERDICT_ALLOW, $v['verdict']);
    }

    public function test_no_confidence_meta_does_not_trigger_review_downgrade(): void
    {
        // Absent confidence means no signal either way — must not be treated as "low".
        $this->registry->register('no-conf-1', 'hard');
        $hardPacket = [
            'packet_id' => 'p-hard-no-conf',
            'objective' => str_repeat('x', 1500),
            'allowed_files' => ['app/A.php'],
            'acceptance_criteria' => [],
        ];
        $v = $this->policy->evaluate('no-conf-1', $hardPacket);

        $this->assertSame(AtlasMaestroTieredRoutingPolicy::VERDICT_ALLOW, $v['verdict']);
        $this->assertNull($v['worker_outcome_confidence']);
    }

    // ── unknown worker keeps legacy behavior with explicit reason ───────────────

    public function test_unknown_worker_reason_explicitly_cites_legacy_un_tiered_flow(): void
    {
        $v = $this->policy->evaluate('totally-unknown', $this->hardestPacket());

        $this->assertSame(AtlasMaestroTieredRoutingPolicy::VERDICT_ALLOW_UNKNOWN, $v['verdict']);
        $this->assertStringContainsString('not registered', $v['reason']);
        $this->assertStringContainsString('legacy', $v['reason']);
        $this->assertNull($v['worker_outcome_confidence']);
        $this->assertFalse($v['override_applied']);
    }

    // ── advisory only ────────────────────────────────────────────────────────

    public function test_evaluate_does_not_mutate_the_registry(): void
    {
        $this->registry->register('stable-1', 'easy');
        $before = $this->registry->lookup('stable-1');

        $this->policy->evaluate('stable-1', $this->hardestPacket());

        $after = $this->registry->lookup('stable-1');
        $this->assertSame($before->declaredMaxTier, $after->declaredMaxTier);
        $this->assertSame($before->meta, $after->meta);
    }
}
