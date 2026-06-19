<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternRegistry;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternSpec;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * LOOP-PATTERN-REGISTRY · Slice 1 — the deterministic registry distinguishes governance lanes and
 * never auto-promotes external material. (Required test from the slice spec.)
 */
final class AtlasLoopPatternRegistryTest extends TestCase
{
    public function test_seed_set_builds_and_exposes_the_named_patterns(): void
    {
        $r = new AtlasLoopPatternRegistry();

        foreach (['docs_sweep', 'loop_harness_verification', 'ticket_to_pr_ready', 'self_improving_champion', 'fresh_clone'] as $id) {
            $this->assertTrue($r->has($id), "seed must include {$id}");
        }
        $this->assertGreaterThanOrEqual(8, count($r->selectable()));
    }

    public function test_registry_is_deterministic(): void
    {
        $a = array_map(static fn (AtlasLoopPatternSpec $s) => $s->id.'@'.$s->version, (new AtlasLoopPatternRegistry())->all());
        $b = array_map(static fn (AtlasLoopPatternSpec $s) => $s->id.'@'.$s->version, (new AtlasLoopPatternRegistry())->all());

        $this->assertSame($a, $b, 'same seeds → identical ordering every time');
    }

    public function test_selectable_excludes_source_material_candidate_and_deprecated(): void
    {
        $r = new AtlasLoopPatternRegistry();

        foreach ($r->selectable() as $s) {
            $this->assertContains($s->status, ['ready', 'default'], "{$s->id} leaked into selectable with status {$s->status}");
        }
        // The non-selectable lanes are genuinely populated (the distinction is not vacuous).
        $this->assertNotEmpty($r->byStatus('source_material'));
        $this->assertNotEmpty($r->byStatus('deprecated'));
        foreach (array_merge($r->byStatus('source_material'), $r->byStatus('deprecated')) as $s) {
            $this->assertFalse($s->isSelectable());
        }
    }

    public function test_external_catalog_seed_is_quarantined_not_active(): void
    {
        $ext = (new AtlasLoopPatternRegistry())->find('loop_library_full_product_eval');

        $this->assertNotNull($ext);
        $this->assertSame('source_material', $ext->status);
        $this->assertTrue($ext->isExternalSource());
        $this->assertFalse($ext->isSelectable(), 'external catalog material must never be selectable');
    }

    public function test_find_by_id_and_by_id_version(): void
    {
        $r = new AtlasLoopPatternRegistry();

        $this->assertSame('docs_sweep', $r->find('docs_sweep')?->id);
        $this->assertSame('1.0.0', $r->find('docs_sweep', '1.0.0')?->version);
        $this->assertNull($r->find('docs_sweep', '9.9.9'), 'exact version miss returns null');
        $this->assertNull($r->find('no_such_pattern'));
    }

    public function test_registering_an_external_pattern_as_selectable_is_refused(): void
    {
        // Force-construct a Spec whose status survived as selectable would be impossible via fromArray;
        // we prove the registry's defence-in-depth by handing it a (hypothetical) selectable external.
        // The Spec already quarantines, so we assert the quarantine end-to-end instead.
        $spec = AtlasLoopPatternSpec::fromArray([
            'id' => 'sneaky_external',
            'version' => '1.0.0',
            'intent' => 'refactor',
            'success_gates' => ['g'],
            'terminal_states' => ['success'],
            'durability_mode' => 'single_cycle',
            'sandbox_profile' => ['allowed' => ['read_only']],
            'source' => 'external_skill',
            'status' => 'default', // requested selectable…
        ]);

        $this->assertSame('candidate', $spec->status, 'external is quarantined at construction');

        $r = new AtlasLoopPatternRegistry([]);
        $r->register($spec); // registering the quarantined (candidate) external is fine
        $this->assertFalse($r->find('sneaky_external')?->isSelectable());
    }

    public function test_invalid_pattern_cannot_enter_the_registry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        // A gateless definition throws at Spec construction — there is no registry path to an invalid spec.
        new AtlasLoopPatternRegistry([
            AtlasLoopPatternSpec::fromArray([
                'id' => 'broken', 'version' => '1', 'intent' => 'docs',
                'success_gates' => [], 'terminal_states' => ['success'],
                'durability_mode' => 'single_cycle', 'sandbox_profile' => ['allowed' => ['read_only']],
                'source' => 'atlas_native', 'status' => 'ready',
            ]),
        ]);
    }
}
