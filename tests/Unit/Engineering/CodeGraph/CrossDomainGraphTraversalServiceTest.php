<?php

declare(strict_types=1);

namespace Tests\Unit\Engineering\CodeGraph;

use App\Services\Ai\CrossDomain\AtlasCrossDomainMeshService;
use App\Services\Engineering\CodeGraph\CrossDomainGraphIngestionService;
use App\Services\Engineering\CodeGraph\CrossDomainGraphTraversalService;
use App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap;
use PHPUnit\Framework\TestCase;

/**
 * Fase-2 traversal + ARPTL veto. Pure unit — the graph assembles from the real mesh
 * topology + the canonical map with NO DB (table reads fail-open to empty), so this
 * exercises the genuine mesh veto over the genuine cross-domain edges.
 */
final class CrossDomainGraphTraversalServiceTest extends TestCase
{
    /** @var list<string> */
    private const AUDIENCE = ['marketing', 'sales', 'design', 'personal', 'learning'];

    private function traversal(): CrossDomainGraphTraversalService
    {
        $taxonomy = new CrossDomainTaxonomyMap;
        $mesh = new AtlasCrossDomainMeshService;
        $ingestion = new CrossDomainGraphIngestionService($taxonomy, $mesh);

        return new CrossDomainGraphTraversalService($ingestion, $taxonomy, $mesh);
    }

    private function domainOf(string $nodeId): string
    {
        $rest = substr($nodeId, 7);
        $colon = strpos($rest, ':');

        return $colon === false ? $rest : substr($rest, 0, $colon);
    }

    public function test_unknown_seed_returns_not_found(): void
    {
        $r = $this->traversal()->traverse('domain:does_not_exist', 'normal', 3, 60);
        $this->assertFalse($r['ok']);
        $this->assertSame('seed_not_found', $r['error']);
    }

    public function test_normal_traversal_reaches_other_domains(): void
    {
        $r = $this->traversal()->traverse('domain:engineering', 'normal', 4, 60);

        $this->assertTrue($r['ok']);
        $this->assertGreaterThan(1, $r['stats']['reachable_domain_count']);
        $this->assertContains('domain:engineering', $r['visited']);
    }

    public function test_arptl_vetoes_sensitive_crossing_to_an_audience_domain(): void
    {
        // Under 'sensitive', the mesh blocks crossings to consumer-audience domains
        // (marketing/sales/design/personal/learning). The traversal must RECORD those
        // as vetoed, never traverse them.
        $r = $this->traversal()->traverse('domain:finance', 'sensitive', 2, 80);

        $this->assertTrue($r['ok']);
        $this->assertGreaterThan(0, $r['stats']['vetoed_count'], 'sensitive crossings to audiences must be vetoed');

        $vetoedToAudience = false;
        foreach ($r['vetoed_crossings'] as $v) {
            if (in_array($this->domainOf((string) $v['to']), self::AUDIENCE, true)) {
                $vetoedToAudience = true;
                break;
            }
        }
        $this->assertTrue($vetoedToAudience, 'at least one vetoed crossing must target an audience domain');

        // And no traversed edge may land on an audience domain under sensitive.
        foreach ($r['edges_traversed'] as $e) {
            $this->assertNotContains(
                $this->domainOf((string) $e['to']),
                self::AUDIENCE,
                'a sensitive payload reached an audience domain — ARPTL leak',
            );
        }
    }

    public function test_same_crossing_is_allowed_under_normal_but_vetoed_under_sensitive(): void
    {
        $normal = $this->traversal()->traverse('domain:finance', 'normal', 1, 80);
        $sensitive = $this->traversal()->traverse('domain:finance', 'sensitive', 1, 80);

        $reaches = static function (array $r, string $domain): bool {
            return in_array($domain, $r['reachable_domains'], true);
        };

        // finance -> marketing crosses under normal, is blocked under sensitive.
        $this->assertTrue($reaches($normal, 'marketing'), 'normal should reach marketing');
        $this->assertFalse($reaches($sensitive, 'marketing'), 'sensitive must NOT reach marketing');
    }

    public function test_killer_query_summarizes_reach_and_vetoes(): void
    {
        $r = $this->traversal()->killerQuery('domain:cyber', 'normal');

        $this->assertTrue($r['ok']);
        $this->assertArrayHasKey('reachable_domains', $r);
        $this->assertArrayHasKey('vetoed_crossings', $r);
        // cyber outbound is heavily restricted by the mesh — expect vetoes.
        $this->assertGreaterThan(0, $r['stats']['vetoed_count']);
    }

    public function test_is_deterministic(): void
    {
        $a = $this->traversal()->traverse('domain:engineering', 'normal', 3, 60);
        $b = $this->traversal()->traverse('domain:engineering', 'normal', 3, 60);

        $this->assertSame($a['reachable_domains'], $b['reachable_domains']);
        $this->assertSame($a['stats'], $b['stats']);
    }
}
