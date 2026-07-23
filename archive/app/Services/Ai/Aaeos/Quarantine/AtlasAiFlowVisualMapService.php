<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas AI Flow Visual Map governance validator.
 *
 * Pure, deterministic enforcement of the canonical flow spec used to draw the
 * "Arquitetura de Fluxo do Atlas AI" image so a human and an AI read the same
 * flow before implementing. Given a PROPOSED diagram (an ordered list of stage
 * ids plus an optional classification of free nodes) it checks the proposal
 * against the canonical contract and returns a structured verdict — never
 * touching a provider, the filesystem or the database.
 *
 * Contract (from the doc body):
 *   - "Fluxo Canonico V3": the 18 stages must appear in the documented order
 *     (Surface .. Output Renderer). Wrong order, gaps or unknown stages fail.
 *   - "Checklist Da Imagem V3" #1: Atlas Decide comes AFTER Context Builder and
 *     Policy/Profile.
 *   - "Checklist" #2 / Principio #5: Business Context is NOT a Domain; it sits
 *     outside the Domain Plane and Domain/Profile/Flow is mandatory.
 *   - "Domain Plane": Programming/Finance/Personal Development/Self-Improvement
 *     are ready domains; Marketing/Research/Operations/Health/Writing are
 *     scaffold; Blackink, MiroFish, Frontend, Swift, Python, Go, AtlasVault,
 *     Super Tool Runtime and Scenario Simulation must NOT be drawn as domains.
 *   - "Principios De Rodape" (14 invariants) — encoded as ordering/precedence
 *     and gate checks (e.g. #7 Runtime nao executa sem Decision Receipt,
 *     #9 Repair retorna via policy/receipt/Decide, #11 Learning depois de
 *     Evidence).
 *
 * @see docs/engineering-knowledge-base/atlas-ai-flow-visual-map.md
 */
final class AtlasAiFlowVisualMapService
{
    /** Stable receipt schema id for the verdict this service emits. */
    public const SCHEMA = 'atlas.aaeos.ai_flow_visual_map.v3';

    /**
     * The canonical V3 flow order (doc "Fluxo Canonico V3"). Index == position.
     *
     * @var list<string>
     */
    public const CANONICAL_FLOW = [
        'surface',
        'atlas-input',
        'operation-envelope',
        'intent-routing',
        'business-context',
        'domain-plane',
        'domain-profile',
        'flow-profile',
        'context-builder',
        'policy-profile',
        'atlas-decide',
        'decision-receipt',
        'runtime-executor',
        'quality-gates',
        'repair-escalation',
        'evidence-ledger',
        'learning-proposals',
        'output-renderer',
    ];

    /** Domains drawn as ready/current (doc "Domain Plane"). */
    public const READY_DOMAINS = [
        'programming',
        'finance',
        'personal-development',
        'self-improvement',
    ];

    /** Domains drawn as scaffold/future with a different label. */
    public const SCAFFOLD_DOMAINS = [
        'marketing',
        'research',
        'operations',
        'strategic-decision',
        'health',
        'writing',
        'learning',
    ];

    /**
     * Things that must NEVER be drawn as a Domain (doc "Nao desenhar como
     * dominio"), mapped to what they actually are. These are the most common
     * diagram mistakes the doc exists to prevent.
     *
     * @var array<string,string>
     */
    public const NON_DOMAIN_NODES = [
        'blackink' => 'business-context',
        'mirofish' => 'business-context',
        'frontend' => 'specialist-profile',
        'swift' => 'runtime',
        'python' => 'runtime',
        'go' => 'runtime',
        'atlasvault' => 'human-surface',
        'super-tool-runtime' => 'tool-runtime',
        'scenario-simulation' => 'harness',
    ];

    /**
     * Hard precedence invariants distilled from the checklist + footer
     * principles: each pair means "before MUST appear earlier than after".
     *
     * @var list<array{before:string,after:string,principle:string}>
     */
    private const PRECEDENCE = [
        // Checklist #1: Atlas Decide vem depois de Context Builder e Policy.
        ['before' => 'context-builder', 'after' => 'atlas-decide', 'principle' => 'decide_after_context_builder'],
        ['before' => 'policy-profile', 'after' => 'atlas-decide', 'principle' => 'decide_after_policy'],
        // Principio #7: Runtime nao executa sem Decision Receipt.
        ['before' => 'decision-receipt', 'after' => 'runtime-executor', 'principle' => 'runtime_after_decision_receipt'],
        // Principio #2/#3: provider/tool nao decide -> runtime depois do decide.
        ['before' => 'atlas-decide', 'after' => 'runtime-executor', 'principle' => 'runtime_after_decide'],
        // Quality Gates avalia o que o runtime produziu.
        ['before' => 'runtime-executor', 'after' => 'quality-gates', 'principle' => 'gates_after_runtime'],
        // Principio #9: Repair retorna via policy/receipt/Decide (vem depois dos gates).
        ['before' => 'quality-gates', 'after' => 'repair-escalation', 'principle' => 'repair_after_gates'],
        // Principio #10/#11: tudo vira evidence; learning depois da evidence.
        ['before' => 'evidence-ledger', 'after' => 'learning-proposals', 'principle' => 'learning_after_evidence'],
        // Principio #5: Business Context nao e Domain (vem antes do Domain Plane).
        ['before' => 'business-context', 'after' => 'domain-plane', 'principle' => 'business_context_before_domain'],
    ];

    /**
     * Validate a proposed flow ordering against the canonical V3 spec.
     *
     * @param list<string> $proposedOrder ordered stage ids the diagram draws
     *
     * @return array<string,mixed> the structured verdict + audit receipt
     */
    public function validateFlow(array $proposedOrder): array
    {
        $proposed = $this->normalizeIds($proposedOrder);
        $canonical = self::CANONICAL_FLOW;
        $canonicalSet = array_flip($canonical);
        $position = array_flip($proposed);

        $violations = [];

        // Unknown stages (not part of the canonical flow).
        foreach ($proposed as $id) {
            if (! isset($canonicalSet[$id])) {
                $violations[] = ['rule' => 'unknown_stage', 'stage' => $id];
            }
        }

        // Missing canonical stages.
        $missing = [];
        foreach ($canonical as $id) {
            if (! isset($position[$id])) {
                $missing[] = $id;
                $violations[] = ['rule' => 'missing_stage', 'stage' => $id];
            }
        }

        // Exact-order check: the proposal must equal the canonical sequence.
        $orderOk = $proposed === $canonical;
        if (! $orderOk && $missing === [] && $this->onlyKnown($proposed, $canonicalSet)) {
            // Same set, wrong order — pinpoint the first divergence.
            foreach ($canonical as $idx => $id) {
                if (($proposed[$idx] ?? null) !== $id) {
                    $violations[] = [
                        'rule' => 'out_of_order',
                        'expected' => $id,
                        'found' => $proposed[$idx] ?? null,
                        'position' => $idx,
                    ];
                    break;
                }
            }
        }

        // Precedence invariants (only meaningful for stages actually present).
        foreach (self::PRECEDENCE as $pair) {
            $b = $position[$pair['before']] ?? null;
            $a = $position[$pair['after']] ?? null;
            if ($b !== null && $a !== null && $b >= $a) {
                $violations[] = [
                    'rule' => 'precedence_violation',
                    'principle' => $pair['principle'],
                    'before' => $pair['before'],
                    'after' => $pair['after'],
                ];
            }
        }

        $valid = $violations === [];

        return [
            'schema' => self::SCHEMA,
            'check' => 'flow_order',
            'valid' => $valid,
            'order_ok' => $orderOk,
            'stage_count' => count($proposed),
            'canonical_count' => count($canonical),
            'missing' => $missing,
            'violations' => $violations,
            'auditable' => true,
        ];
    }

    /**
     * Classify a single node and decide whether it is allowed to be drawn as a
     * Domain (doc "Domain Plane" + Principios #5/#6).
     *
     * @return array<string,mixed>
     */
    public function classifyNode(string $node): array
    {
        $id = $this->slug($node);

        if (in_array($id, self::READY_DOMAINS, true)) {
            return $this->classification($id, 'domain', 'ready', true);
        }
        if (in_array($id, self::SCAFFOLD_DOMAINS, true)) {
            return $this->classification($id, 'domain', 'scaffold', true);
        }
        if (isset(self::NON_DOMAIN_NODES[$id])) {
            return $this->classification($id, self::NON_DOMAIN_NODES[$id], 'n/a', false);
        }

        // Unknown node: not a recognised domain, so it may NOT be drawn as one
        // without being placed first (conservative default).
        return $this->classification($id, 'unknown', 'n/a', false);
    }

    /**
     * Audit a full proposed Domain Plane: every entry the diagram wants to draw
     * as a domain is checked. Anything in the "nao desenhar como dominio" list
     * (or unknown) is reported as a misplacement.
     *
     * @param list<string> $proposedDomains nodes the diagram draws as domains
     *
     * @return array<string,mixed>
     */
    public function auditDomainPlane(array $proposedDomains): array
    {
        $misplaced = [];
        $ready = [];
        $scaffold = [];

        foreach ($this->normalizeIds($proposedDomains) as $id) {
            $c = $this->classifyNode($id);
            if (! $c['may_be_domain']) {
                $misplaced[] = [
                    'node' => $id,
                    'actual_kind' => $c['kind'],
                    'reason' => 'not_a_domain',
                ];
            } elseif ($c['maturity'] === 'ready') {
                $ready[] = $id;
            } else {
                $scaffold[] = $id;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'check' => 'domain_plane',
            'valid' => $misplaced === [],
            'ready' => $ready,
            'scaffold' => $scaffold,
            'misplaced' => $misplaced,
            'auditable' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function classification(string $id, string $kind, string $maturity, bool $mayBeDomain): array
    {
        return [
            'node' => $id,
            'kind' => $kind,
            'maturity' => $maturity,
            'may_be_domain' => $mayBeDomain,
        ];
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    private function normalizeIds(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            if (is_string($id) && trim($id) !== '') {
                $clean[] = $this->slug($id);
            }
        }

        return array_values($clean);
    }

    /**
     * @param list<string> $ids
     * @param array<string,int> $canonicalSet
     */
    private function onlyKnown(array $ids, array $canonicalSet): bool
    {
        foreach ($ids as $id) {
            if (! isset($canonicalSet[$id])) {
                return false;
            }
        }

        return count($ids) === count($canonicalSet);
    }

    private function slug(string $value): string
    {
        $v = strtolower(trim($value));
        $v = (string) preg_replace('/[\s_]+/', '-', $v);
        $v = (string) preg_replace('/[^a-z0-9-]/', '', $v);
        $v = (string) preg_replace('/-+/', '-', $v);

        return trim($v, '-');
    }
}
