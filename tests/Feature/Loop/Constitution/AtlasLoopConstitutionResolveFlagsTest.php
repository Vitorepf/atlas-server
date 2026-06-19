<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Constitution;

use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopConstitutionResolveFlags;
use Tests\TestCase;

/**
 * LOOP-OS · Fase 3 · Slice 4 — the config monotonicity leg REJECTS a candidate that disables a safety gate
 * (true→false) or raises a gate-disabling threshold, by pure data comparison over gate-SHAPED keys (so a new
 * gate is protected automatically), and resolves the candidate config by literal require.
 */
final class AtlasLoopConstitutionResolveFlagsTest extends TestCase
{
    private AtlasLoopConstitutionResolveFlags $r;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->r = new AtlasLoopConstitutionResolveFlags();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    public function test_disabling_a_safety_gate_is_a_violation(): void
    {
        $v = $this->r->monotonicityCheck(
            ['atlas.loop.boot_smoke_guard' => true, 'atlas.loop.value_gate_enabled' => true],
            ['atlas.loop.boot_smoke_guard' => false, 'atlas.loop.value_gate_enabled' => true],
        );
        $this->assertFalse($v['ok']);
        $this->assertCount(1, $v['violations']);
        $this->assertStringContainsString('boot_smoke_guard', $v['violations'][0]);
    }

    public function test_raising_a_min_samples_threshold_is_a_violation(): void
    {
        $v = $this->r->monotonicityCheck(
            ['atlas.loop.calibrated_confidence.min_samples' => 5],
            ['atlas.loop.calibrated_confidence.min_samples' => 5000],
        );
        $this->assertFalse($v['ok']);
        $this->assertStringContainsString('threshold raised', $v['violations'][0]);
    }

    public function test_enabling_a_gate_or_lowering_a_threshold_is_fine(): void
    {
        $v = $this->r->monotonicityCheck(
            ['atlas.loop.value_gate_enabled' => false, 'atlas.loop.calibrated_confidence.min_samples' => 50],
            ['atlas.loop.value_gate_enabled' => true, 'atlas.loop.calibrated_confidence.min_samples' => 10],
        );
        $this->assertTrue($v['ok'], 'strengthening is allowed: '.json_encode($v['violations']));
    }

    public function test_a_non_gate_flag_flip_is_not_a_violation(): void
    {
        $v = $this->r->monotonicityCheck(
            ['atlas.loop.morning_digest_verbose' => true],
            ['atlas.loop.morning_digest_verbose' => false],
        );
        $this->assertTrue($v['ok'], 'a cosmetic non-gate flag is not protected');
    }

    public function test_resolve_reads_the_candidate_config_by_literal_require(): void
    {
        $path = sys_get_temp_dir().'/atlas-cfg-'.bin2hex(random_bytes(4)).'.php';
        $this->files[] = $path;
        file_put_contents($path, "<?php\nreturn ['ai' => ['loop' => ['boot_smoke_guard' => true]], 'loop' => ['value_gate_enabled' => false, 'calibrated_confidence' => ['min_samples' => 10]]];\n");

        $flat = $this->r->resolve($path);
        $this->assertTrue($flat['atlas.ai.loop.boot_smoke_guard']);
        $this->assertFalse($flat['atlas.loop.value_gate_enabled']);
        $this->assertSame(10, $flat['atlas.loop.calibrated_confidence.min_samples']);

        // A missing/unreadable candidate config resolves to [] (fail-closed upstream).
        $this->assertSame([], $this->r->resolve('/no/such/config.php'));
    }
}
