<?php

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Runtime for the Resolver Corpus P0 Promotions governance contract.
 *
 * The doc records how P0 resolver material is promoted into the canonical Atlas AI
 * architecture docs. Its concrete contract has three parts that this service turns
 * into a deterministic decision engine — routing a P0 candidate to its owner doc
 * and deciding whether to patch, skip or reject it.
 *
 * 1. Source theme -> canonical destination map (doc table):
 *      Domain Profile Orchestration        -> atlas-ai-core-vs-domain.md
 *      Atlas Decide Final Architecture      -> atlas-ai-kernel-architecture.md
 *      Atlas Programming Product Architecture -> domains/programming.md
 *      Super Tool Runtime Core              -> super-tool-runtime-core.md
 *      Gaps/ideas source                    -> atlas-ai-governed-backlog.md
 *
 * 2. Current Owner Docs allowlist — promotions may ONLY target one of:
 *      atlas-ai-operating-system.md, atlas-ai-pipeline.md, atlas-ai-core-vs-domain.md,
 *      atlas-ai-kernel-architecture.md, domains/programming.md, super-tool-runtime-core.md
 *    A backlog destination (atlas-ai-governed-backlog.md) is a valid route but is
 *    NOT an owner doc, so it is governed as backlog, not promoted as canonical.
 *
 * 3. Promotion Rule (doc body):
 *      "If a resolver source appears to contain missing value, first check the
 *       canonical destination. Patch the owner doc only with the missing stable
 *       decision." Promoted "as compact canonical decisions, not copied wholesale."
 *      -> If the decision already exists in the destination  => SKIP (already covered).
 *      -> If it is a brand-new architecture path / copied wholesale / not a stable
 *         compact decision                                   => REJECT (forbidden).
 *      -> Only a stable, compact, missing decision           => PATCH the owner doc.
 *
 * @see docs/engineering-knowledge-base/resolver-corpus/p0-promotions.md
 */
class AtlasP0PromotionsService
{
    public const SCHEMA = 'atlas.aaeos.p0_promotions.v1';

    /**
     * Source theme (canonicalized) -> canonical destination doc, from the doc table.
     *
     * @var array<string,string>
     */
    private const THEME_DESTINATION = [
        'domain profile orchestration' => 'atlas-ai-core-vs-domain.md',
        'atlas decide final architecture' => 'atlas-ai-kernel-architecture.md',
        'atlas programming product architecture' => 'domains/programming.md',
        'super tool runtime core' => 'super-tool-runtime-core.md',
        'gaps/ideas source' => 'atlas-ai-governed-backlog.md',
    ];

    /**
     * Compact canonical result promoted for each theme (the doc's "Canonical result"
     * column) — surfaced in the receipt so the promotion stays traceable.
     *
     * @var array<string,string>
     */
    private const THEME_CANONICAL_RESULT = [
        'domain profile orchestration' => 'Domain != flow; Profile != model preset; surfaces enter domain/flow profiles.',
        'atlas decide final architecture' => 'Decide compiles intent, risk, context strategy, provider/model policy, gates, fallback and evidence into a receipt.',
        'atlas programming product architecture' => 'Programming is a domain with orchestrator and flows: dev, forge, fix, review, QA, security, refactor.',
        'super tool runtime core' => 'Tool Runtime is shared Core with registry, policy, executor, normalizer, evidence and gates.',
        'gaps/ideas source' => 'Future backlog is governed by atlas-ai-governed-backlog.md and domain docs.',
    ];

    /**
     * The "Current Owner Docs" allowlist — the only docs a P0 may be promoted into
     * as canonical authority.
     *
     * @var list<string>
     */
    private const OWNER_DOCS = [
        'atlas-ai-operating-system.md',
        'atlas-ai-pipeline.md',
        'atlas-ai-core-vs-domain.md',
        'atlas-ai-kernel-architecture.md',
        'domains/programming.md',
        'super-tool-runtime-core.md',
    ];

    /**
     * Resolve the canonical destination doc for a source theme.
     * Unknown themes return null (no documented owner — cannot be promoted).
     */
    public function destinationFor(string $theme): ?string
    {
        $key = $this->canonicalizeTheme($theme);

        return self::THEME_DESTINATION[$key] ?? null;
    }

    /**
     * Is the given doc one of the canonical Owner Docs (valid promotion target)?
     */
    public function isOwnerDoc(string $doc): bool
    {
        return in_array($this->canonicalizeDoc($doc), self::OWNER_DOCS, true);
    }

    /**
     * Decide what to do with a single P0 promotion candidate, applying the doc's
     * Promotion Rule. Pure: no IO, fully deterministic from the input shape.
     *
     * action is one of:
     *   - reject : unknown theme, non-owner destination, wholesale copy, new
     *              architecture path, or not a stable decision (Promotion Rule violated).
     *   - skip   : the decision already exists in the destination (nothing missing).
     *   - patch  : a stable, compact, missing decision -> patch the owner doc.
     *
     * @param  array{
     *     source_theme?:string,
     *     decision?:string,
     *     already_in_destination?:bool,
     *     stable_decision?:bool,
     *     copied_wholesale?:bool,
     *     new_architecture_path?:bool
     * }  $candidate
     * @return array<string,mixed>
     */
    public function promote(array $candidate): array
    {
        $theme = trim((string) ($candidate['source_theme'] ?? ''));
        $decision = trim((string) ($candidate['decision'] ?? ''));
        $destination = $this->destinationFor($theme);

        $alreadyInDestination = (bool) ($candidate['already_in_destination'] ?? false);
        // A promotion only stands if it is a stable, compact decision. Default false:
        // the rule promotes ONLY stable decisions, so an unspecified candidate is not.
        $stableDecision = (bool) ($candidate['stable_decision'] ?? false);
        $copiedWholesale = (bool) ($candidate['copied_wholesale'] ?? false);
        $newArchitecturePath = (bool) ($candidate['new_architecture_path'] ?? false);

        $reasons = [];
        $action = 'patch';

        // No documented destination for this theme — cannot route a promotion.
        if ($destination === null) {
            $reasons[] = 'unknown_theme: no canonical destination is documented for this source theme';
            $action = 'reject';
        }

        // Backlog destinations are governed as backlog, not promoted as canonical
        // owner-doc authority (only Owner Docs may receive a canonical patch).
        $destinationIsOwnerDoc = $destination !== null && $this->isOwnerDoc($destination);
        if ($destination !== null && ! $destinationIsOwnerDoc) {
            $reasons[] = 'not_owner_doc: destination is governed backlog, not a canonical owner doc — route to backlog, do not promote';
            $action = 'reject';
        }

        // Promotion Rule forbids copying wholesale and creating new architecture paths.
        if ($copiedWholesale) {
            $reasons[] = 'copied_wholesale: promote compact canonical decisions, not wholesale copies';
            $action = 'reject';
        }
        if ($newArchitecturePath) {
            $reasons[] = 'new_architecture_path: do not reopen resolver files to create a new architecture path';
            $action = 'reject';
        }

        // Only stable decisions are promotable.
        if ($action !== 'reject' && ! $stableDecision) {
            $reasons[] = 'not_stable: only a stable decision may patch the owner doc';
            $action = 'reject';
        }

        // "Patch the owner doc only with the MISSING decision." If it already exists,
        // there is nothing to promote -> skip (but this is not a violation).
        if ($action === 'patch' && $alreadyInDestination) {
            $reasons[] = 'already_present: the decision already exists in the destination — patch only what is missing';
            $action = 'skip';
        }

        return [
            'schema_version' => self::SCHEMA,
            'source_theme' => $theme,
            'theme_recognized' => $destination !== null,
            'destination' => $destination,
            'destination_is_owner_doc' => $destinationIsOwnerDoc,
            'canonical_result' => self::THEME_CANONICAL_RESULT[$this->canonicalizeTheme($theme)] ?? null,
            'decision' => $decision,
            'action' => $action,
            'will_patch' => $action === 'patch',
            'reasons' => $reasons,
            // A candidate is rule-compliant when it is not rejected: either it
            // patches a missing stable decision, or it correctly skips an existing one.
            'rule_compliant' => $action !== 'reject',
        ];
    }

    /**
     * Run the documented promotion table end to end: for each source theme in the
     * doc, confirm it routes to its canonical destination and roll up a summary.
     *
     * @param  array<int,array<string,mixed>>  $candidates
     * @return array<string,mixed>
     */
    public function promoteBatch(array $candidates): array
    {
        $rows = [];
        $byAction = ['patch' => 0, 'skip' => 0, 'reject' => 0];

        foreach ($candidates as $candidate) {
            $receipt = $this->promote(is_array($candidate) ? $candidate : []);
            $byAction[$receipt['action']] = ($byAction[$receipt['action']] ?? 0) + 1;
            $rows[] = $receipt;
        }

        return [
            'schema_version' => self::SCHEMA,
            'owner_docs' => self::OWNER_DOCS,
            'summary' => [
                'evaluated' => count($rows),
                'by_action' => $byAction,
                // Clean when every candidate is rule-compliant (none rejected).
                'clean' => $byAction['reject'] === 0,
            ],
            'items' => $rows,
        ];
    }

    /**
     * The full documented promotion table as canonical reference data.
     *
     * @return list<array{source_theme:string,destination:string,is_owner_doc:bool,canonical_result:string}>
     */
    public function promotionTable(): array
    {
        $table = [];
        foreach (self::THEME_DESTINATION as $themeKey => $destination) {
            $table[] = [
                'source_theme' => $themeKey,
                'destination' => $destination,
                'is_owner_doc' => $this->isOwnerDoc($destination),
                'canonical_result' => self::THEME_CANONICAL_RESULT[$themeKey] ?? '',
            ];
        }

        return $table;
    }

    private function canonicalizeTheme(string $theme): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $theme) ?? $theme));
    }

    private function canonicalizeDoc(string $doc): string
    {
        return strtolower(trim($doc));
    }
}
