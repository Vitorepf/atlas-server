<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Human Knowledge Surface (HKS) — pure, deterministic authority gate.
 *
 * HKS is the lateral plane where AtlasVault, Obsidian and the human workspace
 * enter as CURATED CONTEXT — never as raw operational truth. This service turns
 * the doc's invariants into runtime. It is read-only: it never reads a file,
 * walks the vault, opens a source, writes a doc or emits evidence. It only
 * DECIDES, for a knowledge item, what authority it carries and what it is
 * allowed to do.
 *
 * The doc's enforceable contract (Contratos / Regras para IA / Escopo /
 * forbidden_changes) reduces to:
 *
 *   - Invariante: "fonte humana nao substitui canon tecnico do repo" — a human
 *     source NEVER replaces repo technical canon.
 *   - Saida: "contexto curado com source" — every item that enters context MUST
 *     carry an explicit source/authority attribution.
 *   - Regras para IA: preserve the authority distinction even when the UI shows
 *     everything as a single map (the authority badge).
 *   - Escopo: Permitido = read / index / link. Proibido = overwrite official
 *     docs from a note WITHOUT a decision.
 *   - forbidden_changes: treat a human note as technical truth without canon.
 *
 * Five documented decision surfaces are implemented:
 *
 *   1. classifyAuthority(): map an item's `source` to one of the two authority
 *      tiers — repo canon (TECHNICAL_TRUTH) vs human/vault (CURATED_CONTEXT) —
 *      and produce the inspector badge. Repo is technical truth; vault/obsidian/
 *      book/note/human are curated context; anything else is UNKNOWN (untrusted).
 *
 *   2. admitToContextPack(): the Contract output rule — an item may enter the
 *      curated context pack ONLY if it declares an explicit source AND its tier
 *      is trusted (canon or curated). A missing source is rejected
 *      ("contexto curado com source"); an unknown source is rejected.
 *
 *   3. canOverwriteCanon(): the Escopo invariant — a human/curated item may
 *      overwrite official repo docs ONLY when an explicit governance decision
 *      (decision id + canon target) is attached. A note without a decision can
 *      never become technical truth; repo canon never needs a decision to govern
 *      itself.
 *
 *   4. detectDrift(): the Riscos rule — flag drift between a human note and the
 *      repo canon, and flag a reflection being read as an executable contract.
 *
 *   5. gate(): compose authority + admission + overwrite into one verdict
 *      envelope, with precise reasons, for a single knowledge item.
 *
 * @see docs/engineering-knowledge-base/system-graph/hks.md
 */
final class AtlasHumanKnowledgeSurfaceService
{
    /** Stable schema id for the verdict envelopes this gate emits. */
    public const SCHEMA = 'atlas.hks.authority.gate.v1';

    /**
     * Authority tiers. Repo canon is the only technical truth; the human surface
     * is curated context; anything outside the known sources is untrusted.
     */
    public const TIER_TECHNICAL_TRUTH = 'technical_truth';
    public const TIER_CURATED_CONTEXT = 'curated_context';
    public const TIER_UNKNOWN = 'unknown';

    /** The single canonical technical-truth source root (repo docs). */
    public const SOURCE_REPO = 'repo';

    /**
     * Human Knowledge Surface sources — books, philosophy, stories, notes and
     * human memory. All map to CURATED_CONTEXT, never to technical truth.
     */
    public const HUMAN_SOURCES = [
        'vault',
        'atlasvault',
        'obsidian',
        'book',
        'note',
        'human',
        'reflection',
    ];

    public const VERDICT_ALLOW = 'allow';
    public const VERDICT_DENY = 'deny';

    // ---------------------------------------------------------------------
    // 1. Authority classification + inspector badge
    // ---------------------------------------------------------------------

    /**
     * Classify the authority tier of a knowledge item from its `source`, and
     * produce the badge the Cartography inspector renders. Repo is technical
     * truth; any human-surface source is curated context; everything else is
     * unknown (untrusted).
     *
     * @return array{source:string, tier:string, badge:string, technical_truth:bool, trusted:bool}
     */
    public function classifyAuthority(string $source): array
    {
        $normalized = strtolower(trim($source));

        if ($normalized === self::SOURCE_REPO) {
            $tier = self::TIER_TECHNICAL_TRUTH;
        } elseif (in_array($normalized, self::HUMAN_SOURCES, true)) {
            $tier = self::TIER_CURATED_CONTEXT;
        } else {
            $tier = self::TIER_UNKNOWN;
        }

        return [
            'source' => $normalized,
            'tier' => $tier,
            'badge' => $this->badgeFor($tier),
            'technical_truth' => $tier === self::TIER_TECHNICAL_TRUTH,
            'trusted' => $tier === self::TIER_TECHNICAL_TRUTH || $tier === self::TIER_CURATED_CONTEXT,
        ];
    }

    // ---------------------------------------------------------------------
    // 2. Context-pack admission ("contexto curado com source")
    // ---------------------------------------------------------------------

    /**
     * Decide whether an item may enter the curated context pack. The Contract
     * output is "contexto curado com source": an item with NO declared source is
     * rejected, and an item from an unknown (untrusted) source is rejected. A
     * trusted item — repo canon or a human-surface source — is admitted, tagged
     * with its tier so authority is never lost downstream.
     *
     * @param array{source?:string} $item
     * @return array{admitted:bool, tier:string, reasons:list<string>}
     */
    public function admitToContextPack(array $item): array
    {
        $reasons = [];
        $rawSource = trim((string) ($item['source'] ?? ''));

        if ($rawSource === '') {
            // Saida invariant: curated context must carry a source.
            return [
                'admitted' => false,
                'tier' => self::TIER_UNKNOWN,
                'reasons' => ['item has no declared source — curated context must carry an explicit source (contexto curado com source)'],
            ];
        }

        $authority = $this->classifyAuthority($rawSource);

        if ($authority['tier'] === self::TIER_UNKNOWN) {
            $reasons[] = "unknown source '{$authority['source']}' is untrusted and cannot enter the context pack";
        }

        return [
            'admitted' => $reasons === [],
            'tier' => $authority['tier'],
            'reasons' => $reasons,
        ];
    }

    // ---------------------------------------------------------------------
    // 3. Canon-overwrite gate (the core invariant)
    // ---------------------------------------------------------------------

    /**
     * The HKS invariant: a human/curated item may overwrite official repo docs
     * ONLY when an explicit governance decision is attached (a non-empty
     * `decision` id pointing at a `canon_target`). Without that decision a human
     * note can NEVER become technical truth ("sobrescrever docs oficiais a partir
     * de nota sem decisao" is forbidden; "tratar nota humana como verdade tecnica
     * sem canon" is forbidden). Repo canon governs itself and needs no decision.
     * An unknown source can never overwrite canon under any circumstance.
     *
     * @param array{source?:string, decision?:string, canon_target?:string} $item
     * @return array{allowed:bool, requires_decision:bool, reasons:list<string>}
     */
    public function canOverwriteCanon(array $item): array
    {
        $authority = $this->classifyAuthority((string) ($item['source'] ?? ''));
        $decision = trim((string) ($item['decision'] ?? ''));
        $canonTarget = trim((string) ($item['canon_target'] ?? ''));

        // Repo canon is technical truth and governs itself.
        if ($authority['tier'] === self::TIER_TECHNICAL_TRUTH) {
            return ['allowed' => true, 'requires_decision' => false, 'reasons' => []];
        }

        // Unknown / untrusted source can never overwrite canon.
        if ($authority['tier'] === self::TIER_UNKNOWN) {
            return [
                'allowed' => false,
                'requires_decision' => true,
                'reasons' => ["unknown source '{$authority['source']}' can never overwrite repo canon"],
            ];
        }

        // Curated context: overwrite requires an explicit decision AND a target.
        $reasons = [];
        if ($decision === '') {
            $reasons[] = 'human note cannot overwrite official docs without an explicit governance decision (forbidden: sobrescrever docs oficiais a partir de nota sem decisao)';
        }
        if ($canonTarget === '') {
            $reasons[] = 'overwrite must name the canon_target doc it promotes the note into';
        }

        return [
            'allowed' => $reasons === [],
            'requires_decision' => true,
            'reasons' => $reasons,
        ];
    }

    // ---------------------------------------------------------------------
    // 4. Drift detection (Riscos)
    // ---------------------------------------------------------------------

    /**
     * Flag the two documented risks: (a) drift between a human note and the repo
     * canon (the note asserts something the canon contradicts, with no decision
     * reconciling them); (b) a reflection being treated as an executable
     * contract.
     *
     * @param array{source?:string, contradicts_canon?:bool, decision?:string, treated_as_contract?:bool} $item
     * @return array{has_drift:bool, signals:list<string>}
     */
    public function detectDrift(array $item): array
    {
        $signals = [];
        $authority = $this->classifyAuthority((string) ($item['source'] ?? ''));
        $isHuman = $authority['tier'] === self::TIER_CURATED_CONTEXT;
        $decision = trim((string) ($item['decision'] ?? ''));

        if ($isHuman && ($item['contradicts_canon'] ?? false) === true && $decision === '') {
            $signals[] = 'human note contradicts repo canon with no reconciling decision — drift between note and technical canon';
        }

        if ($isHuman && ($item['treated_as_contract'] ?? false) === true) {
            $signals[] = 'reflection is being treated as an executable contract — curated context is not a runtime contract';
        }

        return ['has_drift' => $signals !== [], 'signals' => $signals];
    }

    // ---------------------------------------------------------------------
    // 5. Composition
    // ---------------------------------------------------------------------

    /**
     * Compose authority + admission + overwrite + drift into a single verdict
     * for one knowledge item. The verdict is ALLOW only when the item is
     * admissible AND no overwrite it requests is denied AND no drift is present.
     *
     * @param array{source?:string, decision?:string, canon_target?:string, requests_overwrite?:bool, contradicts_canon?:bool, treated_as_contract?:bool} $item
     * @return array{schema:string, verdict:string, authority:array<string,mixed>, admission:array<string,mixed>, overwrite:array<string,mixed>, drift:array<string,mixed>, reasons:list<string>}
     */
    public function gate(array $item): array
    {
        $authority = $this->classifyAuthority((string) ($item['source'] ?? ''));
        $admission = $this->admitToContextPack($item);
        $drift = $this->detectDrift($item);

        $requestsOverwrite = ($item['requests_overwrite'] ?? false) === true;
        $overwrite = $requestsOverwrite
            ? $this->canOverwriteCanon($item)
            : ['allowed' => true, 'requires_decision' => false, 'reasons' => []];

        $reasons = [];
        foreach ($admission['reasons'] as $r) {
            $reasons[] = $r;
        }
        if ($requestsOverwrite) {
            foreach ($overwrite['reasons'] as $r) {
                $reasons[] = $r;
            }
        }
        foreach ($drift['signals'] as $s) {
            $reasons[] = $s;
        }
        $reasons = array_values(array_unique($reasons));

        $allow = $admission['admitted']
            && ($overwrite['allowed'] ?? true)
            && ! $drift['has_drift'];

        return [
            'schema' => self::SCHEMA,
            'verdict' => $allow ? self::VERDICT_ALLOW : self::VERDICT_DENY,
            'authority' => $authority,
            'admission' => $admission,
            'overwrite' => $overwrite,
            'drift' => $drift,
            'reasons' => $reasons,
        ];
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /** The inspector badge string for a tier (Regras para IA: preserve authority in the unified map). */
    private function badgeFor(string $tier): string
    {
        return match ($tier) {
            self::TIER_TECHNICAL_TRUTH => 'Repo Canon · Technical Truth',
            self::TIER_CURATED_CONTEXT => 'Human Surface · Curated Context',
            default => 'Unknown · Untrusted',
        };
    }
}
