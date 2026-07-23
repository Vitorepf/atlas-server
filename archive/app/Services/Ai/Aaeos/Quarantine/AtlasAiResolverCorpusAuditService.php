<?php

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Runtime for the Resolver Corpus Audit governance contract.
 *
 * The doc is the active compact index for the resolver-o-que-vale-a-pena corpus.
 * It states three invariants and a 4-level classification, plus an anti-pattern
 * for handling source material. This service turns those documented rules into a
 * deterministic decision engine: given one resolver corpus item, it classifies
 * the disposition (promote / archive / reject) and proves every invariant holds.
 *
 * Documented Rule (all three must be honoured):
 *   1. Nothing important stays lost in resolver.
 *      -> a P0/P1 item that is NOT promoted violates the rule (lost value).
 *   2. Nothing becomes canonical without classification.
 *      -> promotion requires a valid level; an unclassified item cannot promote.
 *   3. Nothing competes with the KB after promotion or archive.
 *      -> a promoted/archived item kept as a live competing authority violates it.
 *
 * Documented Classification (Level -> Meaning):
 *   P0      Must influence Mother Architecture now.        -> promote (canonical)
 *   P1      Implementation reference or near roadmap.      -> promote (canonical)
 *   P2      Future idea or immature domain.                -> archive (not active)
 *   Archive Useful history, not active authority.          -> archive (not active)
 *
 * Documented Anti-Pattern:
 *   Do not reopen resolver files to create a new architecture path. Diff them
 *   against the current owner docs, promote ONLY missing decisions, and preserve
 *   source links. So: an item that DUPLICATES an existing owner-doc decision is
 *   not promotable (promote only what is missing); and any promotion/archive that
 *   drops its source link breaks "preserve source links".
 *
 * @see docs/engineering-knowledge-base/atlas-ai-resolver-corpus-audit.md
 */
class AtlasAiResolverCorpusAuditService
{
    public const SCHEMA = 'atlas.aaeos.resolver_corpus_audit.v1';

    /**
     * Canonical levels and their documented dispositions.
     * P0/P1 = active authority candidates (promote). P2/Archive = history (archive).
     *
     * @var array<string,string>
     */
    private const LEVEL_DISPOSITION = [
        'P0' => 'promote',
        'P1' => 'promote',
        'P2' => 'archive',
        'ARCHIVE' => 'archive',
    ];

    /**
     * Documented meaning per level, surfaced in the receipt for auditability.
     *
     * @var array<string,string>
     */
    private const LEVEL_MEANING = [
        'P0' => 'Must influence Mother Architecture now.',
        'P1' => 'Implementation reference or near roadmap.',
        'P2' => 'Future idea or immature domain.',
        'ARCHIVE' => 'Useful history, not active authority.',
    ];

    /**
     * Audit one resolver corpus item against the documented contract and return a
     * decision receipt. Pure: no IO, fully deterministic from the input shape.
     *
     * @param  array{
     *     id?:string,
     *     level?:string,
     *     duplicates_owner_decision?:bool,
     *     source_link_preserved?:bool,
     *     kept_as_competing_authority?:bool
     * }  $item
     * @return array<string,mixed>
     */
    public function audit(array $item): array
    {
        $id = trim((string) ($item['id'] ?? ''));
        $rawLevel = trim((string) ($item['level'] ?? ''));
        $level = $this->normalizeLevel($rawLevel);
        $classified = $level !== null;

        // Anti-pattern signals.
        $duplicatesOwnerDecision = (bool) ($item['duplicates_owner_decision'] ?? false);
        $sourceLinkPreserved = (bool) ($item['source_link_preserved'] ?? false);
        $keptAsCompetingAuthority = (bool) ($item['kept_as_competing_authority'] ?? false);

        $intended = $classified ? self::LEVEL_DISPOSITION[$level] : 'reject';

        $violations = [];

        // Rule 2: Nothing becomes canonical without classification.
        if (! $classified) {
            $violations[] = 'unclassified: cannot become canonical without a valid level (P0/P1/P2/Archive)';
        }

        // Anti-pattern: promote ONLY missing decisions — a duplicate of an existing
        // owner-doc decision is not promotable (it would compete with the KB).
        if ($intended === 'promote' && $duplicatesOwnerDecision) {
            $violations[] = 'duplicate: decision already exists in an owner doc; promote only missing decisions';
        }

        // Anti-pattern: preserve source links on any promotion/archive.
        if (in_array($intended, ['promote', 'archive'], true) && ! $sourceLinkPreserved) {
            $violations[] = 'lost_source_link: promotion/archive must preserve the resolver source link';
        }

        // Rule 3: Nothing competes with the KB after promotion or archive.
        if (in_array($intended, ['promote', 'archive'], true) && $keptAsCompetingAuthority) {
            $violations[] = 'competes_with_kb: a promoted/archived item must not remain a live competing authority';
        }

        // Final disposition: the documented disposition only stands when no
        // invariant is broken; otherwise the safe outcome is reject.
        $disposition = $violations === [] ? $intended : 'reject';

        // Rule 1: Nothing important stays lost in resolver. An important item
        // (P0/P1) that does not end up promoted is "lost value" — a warning the
        // operator must resolve, NOT a hard failure of the input itself.
        $importantButNotPromoted = in_array($level, ['P0', 'P1'], true) && $disposition !== 'promote';

        return [
            'schema_version' => self::SCHEMA,
            'id' => $id,
            'level' => $level,
            'level_raw' => $rawLevel,
            'level_meaning' => $level !== null ? self::LEVEL_MEANING[$level] : null,
            'classified' => $classified,
            'intended_disposition' => $intended,
            'disposition' => $disposition,
            'promote' => $disposition === 'promote',
            'rules' => [
                'not_lost' => ! $importantButNotPromoted,
                'classified_before_canonical' => $classified || $intended !== 'promote',
                'no_kb_competition' => ! $keptAsCompetingAuthority || $disposition === 'reject',
            ],
            'violations' => $violations,
            'lost_value_warning' => $importantButNotPromoted,
            'compliant' => $violations === [] && ! $importantButNotPromoted,
        ];
    }

    /**
     * Audit a whole corpus batch and roll up a summary that mirrors the doc's
     * outcome: how many items promote, archive, reject, and how many P0/P1 items
     * would be "lost" (the thing the Rule forbids).
     *
     * @param  array<int,array<string,mixed>>  $items
     * @return array<string,mixed>
     */
    public function auditCorpus(array $items): array
    {
        $rows = [];
        $byDisposition = ['promote' => 0, 'archive' => 0, 'reject' => 0];
        $lostValue = 0;
        $violationCount = 0;

        foreach ($items as $item) {
            $receipt = $this->audit(is_array($item) ? $item : []);
            $byDisposition[$receipt['disposition']] = ($byDisposition[$receipt['disposition']] ?? 0) + 1;
            if ($receipt['lost_value_warning'] === true) {
                $lostValue++;
            }
            $violationCount += count($receipt['violations']);
            $rows[] = $receipt;
        }

        return [
            'schema_version' => self::SCHEMA,
            'summary' => [
                'evaluated' => count($rows),
                'by_disposition' => $byDisposition,
                'lost_value_warnings' => $lostValue,
                'violation_count' => $violationCount,
                // The corpus is clean only when nothing important is lost and no
                // invariant is broken anywhere.
                'clean' => $lostValue === 0 && $violationCount === 0,
            ],
            'items' => $rows,
        ];
    }

    /**
     * Normalize a free-text level to the canonical vocabulary. Accepts the four
     * documented levels case-insensitively; anything else is unclassified (null).
     */
    public function normalizeLevel(string $level): ?string
    {
        $normalized = strtoupper(trim($level));

        return array_key_exists($normalized, self::LEVEL_DISPOSITION) ? $normalized : null;
    }
}
