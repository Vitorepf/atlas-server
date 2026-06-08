<?php

declare(strict_types=1);

namespace Tests\Unit\Engineering\CodeGraph;

use App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap;
use PHPUnit\Framework\TestCase;

/**
 * The canonical reconciliation of the two divergent 15-domain taxonomies into one
 * superset (AP-814 §8.1, operator decision). Pure unit — no DB/container.
 */
final class CrossDomainTaxonomyMapTest extends TestCase
{
    private function map(): CrossDomainTaxonomyMap
    {
        return new CrossDomainTaxonomyMap;
    }

    public function test_superset_is_the_reconciled_union(): void
    {
        // 9 shared + 6 mesh-only + 6 registry-only = 21 canonical domains.
        $this->assertCount(21, $this->map()->canonicalIds());
    }

    public function test_resolves_mesh_ids_to_canonical(): void
    {
        $m = $this->map();
        $this->assertSame('engineering', $m->canonical('engineering'));
        $this->assertSame('trading', $m->canonical('trading'));
        $this->assertSame('cyber', $m->canonical('cyber'));
        $this->assertSame('ops', $m->canonical('ops'));
    }

    public function test_resolves_registry_ids_to_the_same_canonical(): void
    {
        $m = $this->map();
        // The crux: a registry id and its mesh synonym collapse to ONE canonical id.
        $this->assertSame('engineering', $m->canonical('programming'));
        $this->assertSame('cyber', $m->canonical('security'));
        $this->assertSame('ops', $m->canonical('operations'));
        $this->assertSame('personal', $m->canonical('personal_development'));
        $this->assertSame('engineering', $m->canonical('engineering')); // and the mesh side agrees
    }

    public function test_unknown_id_is_null_never_invented(): void
    {
        $this->assertNull($this->map()->canonical('not_a_domain'));
        $this->assertNull($this->map()->canonical(''));
    }

    public function test_sensitive_defaults_are_conservative(): void
    {
        $m = $this->map();
        foreach (['finance', 'health', 'cyber', 'personal', 'trading', 'legal'] as $d) {
            $this->assertTrue($m->isSensitive($d), "{$d} must be sensitive");
        }
        foreach (['engineering', 'general', 'qa', 'marketing'] as $d) {
            $this->assertFalse($m->isSensitive($d), "{$d} must not be sensitive");
        }
    }

    public function test_shared_domains_carry_both_aliases(): void
    {
        $all = $this->map()->all();
        // engineering is the canonical for both mesh:engineering and registry:programming.
        $this->assertSame('engineering', $all['engineering']['mesh']);
        $this->assertSame('programming', $all['engineering']['registry']);
        // mesh-only has no registry alias; registry-only has no mesh alias.
        $this->assertNull($all['trading']['registry']);
        $this->assertNull($all['qa']['mesh']);
    }
}
