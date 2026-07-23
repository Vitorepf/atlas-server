<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Pipeline;


/**
 * E1 — Intent action-verb extractor.
 *
 * Extracts the recognized action verbs from a normalized intent so the verb
 * set can be PERSISTED (new field on LightTaskContract::intentVerbs) and
 * shared by every downstream stage instead of each one re-detecting and
 * discarding (the historical behavior — SpecComposer::extractIntentVerbs was
 * private and the verbs were thrown away after populating expectedBehavior[]).
 *
 * The recognized vocabulary reuses the {@see IntakeNormalizer::inferClarity}
 * verb list (add/fix/redirect/rename/extract/gate/remove/refactor and their
 * PT-BR/EN synonyms) so intake classification and E1 verb extraction agree
 * on one canonical set of labels. Every recognized surface form is normalized
 * to a single canonical Portuguese-infinitive label (corrigir, remover,
 * adicionar, renomear, refatorar, extrair, redirecionar, gatear) so a
 * multi-form intent ("rename X and remova Y") collapses to one label per
 * semantic verb.
 *
 * This is the shared basis for:
 *   - E2 per-verb behavioral acceptance criteria
 *     ({@see SpecComposer::buildBehavioralAcceptanceCriteria()} reads the
 *     persisted set via this extractor so spec-compose and contract-compose
 *     agree on the same verbs);
 *   - the E1 deterministic intent-falsification probe
 *     (VAL-E1-003..VAL-E1-015, VAL-CROSS-005 — the probe checks the diff
 *     against this verb set, never re-detecting).
 *
 * The extractor is PURE (no I/O, no container, no model). Detection is a
 * best-effort substring heuristic on the lowercased intent, mirroring the
 * established {@see IntakeNormalizer::inferClarity} approach. An intent
 * without any recognized verb yields an empty list (downstream stages treat
 * an empty verb set as "no intent verb to probe").
 */
final class IntentActionExtractor
{
    /**
     * Recognized action-verb surface forms -> canonical labels.
     *
     * The keys are the substrings matched (case-insensitive) against the
     * lowercased normalized intent; the values are the canonical verb labels
     * persisted on the contract and surfaced to downstream stages. Mirrors
     * the {@see IntakeNormalizer::inferClarity} verb list so intake and E1
     * classify on the same vocabulary. Kept in lock-step with
     * {@see SpecComposer::INTENT_VERB_BEHAVIORS} (the SpecComposer reuses
     * this same vocabulary to derive expectedBehavior[] / behavioral ACs).
     *
     * Order matters: longer/more-specific surface forms should appear before
     * shorter/ambiguous ones only when first-occurrence ordering of labels
     * would otherwise be surprising. The dedup-by-label step below collapses
     * synonyms to one entry per canonical verb, preserving first-occurrence
     * order.
     */
    public const RECOGNIZED_VERBS = [
        // corrigir / fix
        'corrija' => 'corrigir',
        'corrigir' => 'corrigir',
        'fix' => 'corrigir',
        // ajustar / adjust
        'ajuste' => 'ajustar',
        'ajustar' => 'ajustar',
        // remover / remove
        'remova' => 'remover',
        'remove' => 'remover',
        'remover' => 'remover',
        // adicionar / add
        'adicione' => 'adicionar',
        'adicionar' => 'adicionar',
        'add ' => 'adicionar',
        // criar / create
        'crie' => 'criar',
        'create' => 'criar',
        // renomear / rename
        'rename' => 'renomear',
        'renomeie' => 'renomear',
        // refatorar / refactor
        'refator' => 'refatorar',
        'refactor' => 'refatorar',
        // extrair / extract
        'extract' => 'extrair',
        'extraia' => 'extrair',
        // redirecionar / redirect
        'redirect' => 'redirecionar',
        'redirecione' => 'redirecionar',
        // gate (feature flag / gate a code path)
        'gate' => 'gatear',
    ];

    /**
     * The canonical verb labels the extractor recognizes. Useful for tests
     * and for downstream stages that need the full vocabulary (e.g. the E1
     * intent probe's "no recognized verb => no probe" gate).
     *
     * @return list<string>
     */
    public static function recognizedVerbLabels(): array
    {
        $labels = [];
        $seen = [];
        foreach (self::RECOGNIZED_VERBS as $label) {
            if (! isset($seen[$label])) {
                $labels[] = $label;
                $seen[$label] = true;
            }
        }

        return $labels;
    }

    /**
     * Extract the recognized action verbs present in the normalized intent.
     *
     * Returns the deduped set of canonical verb labels, preserving
     * first-occurrence order. Empty when no recognized verb matches or when
     * the intent is blank. Synonyms collapse to their canonical label so a
     * multi-form intent never yields duplicates.
     *
     * @return list<string>
     */
    public function extract(string $intentNormalized): array
    {
        $haystack = strtolower($intentNormalized);
        if ($haystack === '') {
            return [];
        }

        $labels = [];
        $seen = [];
        foreach (self::RECOGNIZED_VERBS as $needle => $label) {
            if (str_contains($haystack, $needle) && ! isset($seen[$label])) {
                $labels[] = $label;
                $seen[$label] = true;
            }
        }

        return $labels;
    }
}
