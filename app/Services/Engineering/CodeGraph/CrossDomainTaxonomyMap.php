<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;

/**
 * Canonical cross-domain taxonomy — the single source of truth that reconciles the
 * TWO divergent 15-domain lists Atlas had grown (AP-814 §8.1, operator decision =
 * "superset único"):
 *   - the mesh/ARPTL list (engineering/trading/cyber/legal/sales/design/governance/infra…)
 *   - the profile-registry/orchestrator list (programming/qa/security/operations/
 *     strategic_decision/writing/self_improvement/background/general…)
 *
 * 9 domains are the SAME concept under different names (engineering=programming,
 * cyber=security, ops=operations, personal=personal_development, + finance/marketing/
 * research/learning/health); 6 are mesh-only and 6 are registry-only. The reconciled
 * canonical set is their union (21), each carrying its alias on BOTH sides so any
 * caller can resolve a mesh id OR a registry id to ONE canonical id — which is what
 * lets the cross-domain graph merge mesh edges with registry/handoff edges without
 * the id mismatch that blocked Fase-2.
 *
 * Pure & deterministic: a constant table in, resolution out. No DB, no IO. This map
 * does NOT rewrite the mesh or the registry (that would be churny + risky) — it
 * RECONCILES them, additively.
 */
final class CrossDomainTaxonomyMap
{
    public const SCHEMA = 'atlas.cross_domain.taxonomy.v1';

    /**
     * canonical_id => [label, mesh_id|null, registry_id|null, sensitive].
     * sensitive = ARPTL-conservative default (over-tag is safe): finance/health/
     * cyber/personal/trading/legal never freely cross to audience domains.
     *
     * @var array<string, array{label:string, mesh:?string, registry:?string, sensitive:bool}>
     */
    private const CANONICAL = [
        // --- 9 shared (synonyms merged) ---
        'engineering' => ['label' => 'Software Engineering', 'mesh' => 'engineering', 'registry' => 'programming', 'sensitive' => false],
        'finance' => ['label' => 'Finance', 'mesh' => 'finance', 'registry' => 'finance', 'sensitive' => true],
        'marketing' => ['label' => 'Marketing', 'mesh' => 'marketing', 'registry' => 'marketing', 'sensitive' => false],
        'research' => ['label' => 'Research', 'mesh' => 'research', 'registry' => 'research', 'sensitive' => false],
        'learning' => ['label' => 'Learning', 'mesh' => 'learning', 'registry' => 'learning', 'sensitive' => false],
        'health' => ['label' => 'Health', 'mesh' => 'health', 'registry' => 'health', 'sensitive' => true],
        'cyber' => ['label' => 'Cyber Security', 'mesh' => 'cyber', 'registry' => 'security', 'sensitive' => true],
        'ops' => ['label' => 'Operations', 'mesh' => 'ops', 'registry' => 'operations', 'sensitive' => false],
        'personal' => ['label' => 'Personal', 'mesh' => 'personal', 'registry' => 'personal_development', 'sensitive' => true],
        // --- 6 mesh-only ---
        'trading' => ['label' => 'Trading', 'mesh' => 'trading', 'registry' => null, 'sensitive' => true],
        'legal' => ['label' => 'Legal', 'mesh' => 'legal', 'registry' => null, 'sensitive' => true],
        'sales' => ['label' => 'Sales', 'mesh' => 'sales', 'registry' => null, 'sensitive' => false],
        'design' => ['label' => 'Design', 'mesh' => 'design', 'registry' => null, 'sensitive' => false],
        'infra' => ['label' => 'Infrastructure', 'mesh' => 'infra', 'registry' => null, 'sensitive' => false],
        'governance' => ['label' => 'Governance', 'mesh' => 'governance', 'registry' => null, 'sensitive' => false],
        // --- 6 registry-only ---
        'general' => ['label' => 'General', 'mesh' => null, 'registry' => 'general', 'sensitive' => false],
        'qa' => ['label' => 'QA', 'mesh' => null, 'registry' => 'qa', 'sensitive' => false],
        'strategic_decision' => ['label' => 'Strategic Decision', 'mesh' => null, 'registry' => 'strategic_decision', 'sensitive' => false],
        'writing' => ['label' => 'Writing', 'mesh' => null, 'registry' => 'writing', 'sensitive' => false],
        'self_improvement' => ['label' => 'Self Improvement', 'mesh' => null, 'registry' => 'self_improvement', 'sensitive' => false],
        'background' => ['label' => 'Background', 'mesh' => null, 'registry' => 'background', 'sensitive' => false],
    ];

    /** @var array<string,string>|null lazy alias index: any id (canonical|mesh|registry) => canonical */
    private static ?array $aliasIndex = null;

    /**
     * Resolve ANY domain id (canonical, mesh, or registry) to its canonical id.
     * Returns null for an unknown id (caller decides — never invent a domain).
     */
    public function canonical(string $domainId): ?string
    {
        $id = trim($domainId);
        if ($id === '') {
            return null;
        }

        return self::aliasIndex()[$id] ?? null;
    }

    /**
     * The full canonical superset with metadata.
     *
     * @return array<string, array{label:string, mesh:?string, registry:?string, sensitive:bool}>
     */
    public function all(): array
    {
        return self::CANONICAL;
    }

    /** @return list<string> canonical ids, stable order */
    public function canonicalIds(): array
    {
        return array_keys(self::CANONICAL);
    }

    public function label(string $canonicalId): ?string
    {
        return self::CANONICAL[$canonicalId]['label'] ?? null;
    }

    public function isSensitive(string $canonicalId): bool
    {
        return (bool) (self::CANONICAL[$canonicalId]['sensitive'] ?? false);
    }

    /**
     * @return array<string,string>
     */
    private static function aliasIndex(): array
    {
        if (self::$aliasIndex !== null) {
            return self::$aliasIndex;
        }

        $index = [];
        foreach (self::CANONICAL as $canonical => $meta) {
            $index[$canonical] = $canonical;
            if (is_string($meta['mesh']) && $meta['mesh'] !== '') {
                $index[$meta['mesh']] = $canonical;
            }
            if (is_string($meta['registry']) && $meta['registry'] !== '') {
                $index[$meta['registry']] = $canonical;
            }
        }

        return self::$aliasIndex = $index;
    }
}
