<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainModelCapabilityAmplifier;
use Tests\TestCase;

final class AtlasExternalBrainModelCapabilityAmplifierTest extends TestCase
{
    private function svc(): AtlasExternalBrainModelCapabilityAmplifier
    {
        return new AtlasExternalBrainModelCapabilityAmplifier;
    }

    private function amplify(string $modelSize): array
    {
        return $this->svc()->amplify(['model_size' => $modelSize]);
    }

    // ── model_profile ─────────────────────────────────────────────────────────

    public function test_model_profile_reflects_input_size(): void
    {
        foreach (['frontier', 'mid', 'small'] as $size) {
            $this->assertSame($size, $this->amplify($size)['model_profile']);
        }
    }

    public function test_unknown_model_size_defaults_to_mid_profile(): void
    {
        $r = $this->svc()->amplify(['model_size' => 'unknown_xyz']);

        $mid = $this->amplify('mid');
        $this->assertSame($mid['scaffold_steps'], $r['scaffold_steps']);
        $this->assertSame($mid['mandatory_evidence'], $r['mandatory_evidence']);
    }

    // ── strictness ordering ───────────────────────────────────────────────────

    public function test_small_has_more_scaffold_steps_than_frontier(): void
    {
        $small = $this->amplify('small');
        $frontier = $this->amplify('frontier');

        $this->assertGreaterThan(count($frontier['scaffold_steps']), count($small['scaffold_steps']));
    }

    public function test_small_has_more_mandatory_evidence_than_frontier(): void
    {
        $small = $this->amplify('small');
        $frontier = $this->amplify('frontier');

        $this->assertGreaterThan(count($frontier['mandatory_evidence']), count($small['mandatory_evidence']));
    }

    public function test_small_has_more_critique_passes_than_frontier(): void
    {
        $small = $this->amplify('small');
        $frontier = $this->amplify('frontier');

        $this->assertGreaterThan(count($frontier['critique_passes']), count($small['critique_passes']));
    }

    public function test_mid_strictness_between_frontier_and_small(): void
    {
        $frontier = count($this->amplify('frontier')['scaffold_steps']);
        $mid = count($this->amplify('mid')['scaffold_steps']);
        $small = count($this->amplify('small')['scaffold_steps']);

        $this->assertGreaterThan($frontier, $mid);
        $this->assertGreaterThanOrEqual($mid, $small);
    }

    // ── frontier hooks ────────────────────────────────────────────────────────

    public function test_small_model_has_no_frontier_hooks(): void
    {
        $r = $this->amplify('small');

        $this->assertSame([], $r['frontier_multiplier_hooks']);
    }

    public function test_frontier_model_has_frontier_expansion_hooks(): void
    {
        $r = $this->amplify('frontier');

        $this->assertNotEmpty($r['frontier_multiplier_hooks']);
    }

    public function test_mid_model_has_optional_hook_but_not_full_frontier_set(): void
    {
        $mid = $this->amplify('mid');
        $frontier = $this->amplify('frontier');

        $this->assertNotEmpty($mid['frontier_multiplier_hooks']);
        $this->assertLessThan(count($frontier['frontier_multiplier_hooks']), count($mid['frontier_multiplier_hooks']));
    }

    // ── required output keys ──────────────────────────────────────────────────

    public function test_all_required_output_keys_present(): void
    {
        $r = $this->amplify('mid');

        foreach (['model_profile', 'scaffold_steps', 'mandatory_evidence', 'critique_passes', 'frontier_multiplier_hooks'] as $key) {
            $this->assertArrayHasKey($key, $r);
        }
    }

    // ── critique passes content ───────────────────────────────────────────────

    public function test_small_critique_passes_include_all_five_lenses(): void
    {
        $r = $this->amplify('small');

        foreach (['proxy_risk', 'operator_dependency', 'duplicate_target', 'low_leverage', 'false_green_acceptance'] as $lens) {
            $this->assertContains($lens, $r['critique_passes'], "Missing lens: {$lens}");
        }
    }

    public function test_frontier_critique_passes_include_proxy_risk(): void
    {
        $r = $this->amplify('frontier');

        $this->assertContains('proxy_risk', $r['critique_passes']);
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->amplify([]);

        $this->assertSame(AtlasExternalBrainModelCapabilityAmplifier::SCHEMA, $r['schema_version']);
    }
}
