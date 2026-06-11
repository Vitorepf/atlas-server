<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\AtlasAaeosStringListNormalizer;

/**
 * Programming Frontend — Impeccable Build / Test / Release Teardown runtime.
 *
 * Pure, deterministic decision logic that enforces the four documented
 * "Regras para IA", the build pipeline contract and the per-area test matrix
 * from the canonical doc. No I/O, no DB, no shell — every method takes a typed
 * state array and returns a typed verdict array, so the rules can be unit
 * tested in isolation.
 *
 * The doc dissects a distributable frontend skill whose build fans a single
 * source out into many provider bundles, a static site, a browser detector
 * bundle and a packaged extension, then gates distribution behind a release
 * preflight. This runtime turns that prose into enforced rules:
 *
 *   Regra 1 — "Alterar source, nao provider output gerado."
 *     -> sourceOfTruthGuard(): an edit that targets generated provider output
 *        (dist/ bundle, generated factory output) is rejected; only edits to the
 *        skill source + reference + scripts are allowed.
 *
 *   Regra 2 — "Depois de regra detector, rebuild browser/extension/site counts."
 *     -> rebuildPropagation(): when the antipattern detector rule set changes,
 *        the browser detector bundle, the extension bundle and the site counts
 *        all become stale and MUST be rebuilt before release.
 *
 *   Regra 3 — "Live scripts exigem live E2E focado."
 *     -> liveScriptRequirement(): touching a live script demands a focused live
 *        E2E run; a plain unit/build pass does not satisfy it.
 *
 *   Regra 4 — "Release recusa dirty tree, HEAD nao enviado, changelog ausente e
 *               build stale."
 *     -> releaseGate(): the release is refused while ANY of the four blockers
 *        holds — dirty working tree, unpushed HEAD, missing changelog entry, or
 *        a stale build — and is allowed only when all four are clear.
 *
 * The build pipeline contract ("skill source -> build.js -> provider transforms
 * -> dist/universal.zip -> site assets -> release scripts") is modelled by
 * buildPipeline(); the per-area evidence matrix by testMatrix().
 *
 * Documented invariants this code enforces (not merely documents):
 *   - Release stays blocked the instant any of the four refusal conditions
 *     holds, and the blocker list names exactly which ones fired.
 *   - A detector-rule change always marks browser + extension + site as
 *     requiring rebuild (Regra 2 propagation is total, not partial).
 *   - Editing generated provider output is never an allowed change (Regra 1).
 *   - The build pipeline stages are ordered and a downstream stage is never
 *     "fresh" while an upstream stage it depends on is stale.
 *
 * @see docs/engineering-knowledge-base/domains/programming-frontend-impeccable-build-test-release.md
 */
final class AtlasProgrammingFrontendImpeccableBuildTestReleaseService
{
    /** Stable evidence schema ids these surfaces emit. */
    public const SCHEMA_RELEASE_GATE = 'atlas.programming_frontend_impeccable_release_gate.v1';
    public const SCHEMA_REBUILD = 'atlas.programming_frontend_impeccable_rebuild_propagation.v1';
    public const SCHEMA_PIPELINE = 'atlas.programming_frontend_impeccable_build_pipeline.v1';

    /** The four release refusal conditions (Regra 4), in documented order. */
    public const RELEASE_BLOCKERS = [
        'dirty_tree',
        'head_not_pushed',
        'changelog_missing',
        'build_stale',
    ];

    /**
     * Artifacts that a detector-rule change invalidates (Regra 2). Source-of-
     * truth detector rules live upstream; these three outputs are derived and
     * therefore go stale together.
     */
    public const DETECTOR_DEPENDENTS = [
        'browser_detector_bundle',
        'extension_bundle',
        'site_counts',
    ];

    /**
     * Ordered build pipeline stages (Contratos / Fluxo). Each stage names the
     * upstream stage it consumes, so staleness propagates strictly downstream.
     *
     * @var array<int, array{stage: string, consumes: ?string, script: string}>
     */
    public const PIPELINE = [
        ['stage' => 'skill_source', 'consumes' => null, 'script' => 'source'],
        ['stage' => 'provider_transforms', 'consumes' => 'skill_source', 'script' => 'build:skills'],
        ['stage' => 'dist_universal_zip', 'consumes' => 'provider_transforms', 'script' => 'build'],
        ['stage' => 'site_assets', 'consumes' => 'dist_universal_zip', 'script' => 'build:site'],
        ['stage' => 'release', 'consumes' => 'site_assets', 'script' => 'release:skill'],
    ];

    /**
     * Generated / derived path fragments that Regra 1 forbids editing directly.
     * Anything matching one of these is "provider output", not source.
     *
     * @var array<int, string>
     */
    private const GENERATED_MARKERS = [
        'dist/',
        '/generated/',
        '.generated.',
        'universal.zip',
    ];

    /**
     * Regra 4 — release preflight gate.
     *
     * Refuses the release while any of the four documented blockers holds and
     * allows it only when all four are clear. `build_stale` is also implied
     * whenever a detector-rule change left any dependent artifact unbuilt
     * (ties Regra 2 into the gate).
     *
     * @param array{
     *     dirty_tree?: bool,
     *     head_pushed?: bool,
     *     changelog_present?: bool,
     *     build_fresh?: bool,
     *     detector_rules_changed?: bool,
     *     rebuilt?: array<int, string>
     * } $state
     * @return array{
     *     schema: string,
     *     release_allowed: bool,
     *     blockers: array<int, string>,
     *     cleared: array<int, string>,
     *     reason: string
     * }
     */
    public function releaseGate(array $state): array
    {
        $dirty = (bool) ($state['dirty_tree'] ?? false);
        $headPushed = (bool) ($state['head_pushed'] ?? false);
        $changelog = (bool) ($state['changelog_present'] ?? false);
        $buildFresh = (bool) ($state['build_fresh'] ?? false);

        // A detector-rule change that has not been fully propagated leaves the
        // build stale regardless of the caller's build_fresh claim (Regra 2 feeds
        // Regra 4): if any dependent was not rebuilt, the build is not fresh.
        if ((bool) ($state['detector_rules_changed'] ?? false)) {
            $rebuilt = array_values(array_intersect(
                self::DETECTOR_DEPENDENTS,
                AtlasAaeosStringListNormalizer::uniqueNonEmptyStrings($state['rebuilt'] ?? []),
            ));
            if (count($rebuilt) < count(self::DETECTOR_DEPENDENTS)) {
                $buildFresh = false;
            }
        }

        $fired = [];
        if ($dirty) {
            $fired[] = 'dirty_tree';
        }
        if (! $headPushed) {
            $fired[] = 'head_not_pushed';
        }
        if (! $changelog) {
            $fired[] = 'changelog_missing';
        }
        if (! $buildFresh) {
            $fired[] = 'build_stale';
        }

        $allowed = $fired === [];
        $cleared = array_values(array_diff(self::RELEASE_BLOCKERS, $fired));

        return [
            'schema' => self::SCHEMA_RELEASE_GATE,
            'release_allowed' => $allowed,
            'blockers' => $fired,
            'cleared' => $cleared,
            'reason' => $allowed
                ? 'all four release conditions clear'
                : 'release refused: '.implode(', ', $fired),
        ];
    }

    /**
     * Regra 2 — detector-rule change propagation.
     *
     * When the antipattern detector rule set changes, the browser detector
     * bundle, the extension bundle and the site counts all become stale and
     * must be rebuilt. Returns which dependents still need a rebuild given what
     * the caller has already rebuilt.
     *
     * @param array{detector_rules_changed?: bool, rebuilt?: array<int, string>} $state
     * @return array{
     *     schema: string,
     *     detector_rules_changed: bool,
     *     must_rebuild: array<int, string>,
     *     pending_rebuild: array<int, string>,
     *     rebuild_complete: bool
     * }
     */
    public function rebuildPropagation(array $state): array
    {
        $changed = (bool) ($state['detector_rules_changed'] ?? false);
        $mustRebuild = $changed ? self::DETECTOR_DEPENDENTS : [];
        $alreadyRebuilt = AtlasAaeosStringListNormalizer::uniqueNonEmptyStrings($state['rebuilt'] ?? []);
        $pending = array_values(array_diff($mustRebuild, $alreadyRebuilt));

        return [
            'schema' => self::SCHEMA_REBUILD,
            'detector_rules_changed' => $changed,
            'must_rebuild' => $mustRebuild,
            'pending_rebuild' => $pending,
            'rebuild_complete' => $pending === [],
        ];
    }

    /**
     * Regra 3 — live script change requires a focused live E2E run.
     *
     * A plain unit/build pass does not satisfy a touched live script.
     *
     * @param array{live_script_touched?: bool, live_e2e_ran?: bool} $state
     * @return array{
     *     requires_live_e2e: bool,
     *     satisfied: bool,
     *     reason: string
     * }
     */
    public function liveScriptRequirement(array $state): array
    {
        $touched = (bool) ($state['live_script_touched'] ?? false);
        $ran = (bool) ($state['live_e2e_ran'] ?? false);
        $requires = $touched;
        $satisfied = ! $requires || $ran;

        return [
            'requires_live_e2e' => $requires,
            'satisfied' => $satisfied,
            'reason' => match (true) {
                ! $requires => 'no live script touched; focused live E2E not required',
                $satisfied => 'live script touched and focused live E2E ran',
                default => 'live script touched but focused live E2E missing',
            },
        ];
    }

    /**
     * Regra 1 — edit the source, never the generated provider output.
     *
     * Classifies an edited path as 'source' (allowed) or 'generated' (rejected).
     *
     * @return array{path: string, classification: string, allowed: bool, reason: string}
     */
    public function sourceOfTruthGuard(string $path): array
    {
        $needle = strtolower($path);
        $isGenerated = false;
        foreach (self::GENERATED_MARKERS as $marker) {
            if (str_contains($needle, $marker)) {
                $isGenerated = true;
                break;
            }
        }

        return [
            'path' => $path,
            'classification' => $isGenerated ? 'generated' : 'source',
            'allowed' => ! $isGenerated,
            'reason' => $isGenerated
                ? 'edit targets generated provider output; change the skill source instead'
                : 'edit targets the skill source / reference / scripts',
        ];
    }

    /**
     * Build pipeline contract (Contratos / Fluxo).
     *
     * Given the set of stages currently fresh, returns each stage with a
     * resolved freshness that respects upstream dependency: a stage can never be
     * reported fresh while the stage it consumes is stale.
     *
     * @param array<int, string> $freshStages
     * @return array{
     *     schema: string,
     *     stages: array<int, array{stage: string, consumes: ?string, script: string, fresh: bool}>,
     *     release_ready: bool
     * }
     */
    public function buildPipeline(array $freshStages = []): array
    {
        $claimed = array_fill_keys(AtlasAaeosStringListNormalizer::uniqueNonEmptyStrings($freshStages), true);
        $resolved = [];
        $upstreamFresh = true;

        foreach (self::PIPELINE as $stage) {
            $name = $stage['stage'];
            $consumes = $stage['consumes'];
            $consumesFresh = $consumes === null ? true : ($resolved[$consumes] ?? false);
            $fresh = ($claimed[$name] ?? false) && $consumesFresh && $upstreamFresh;
            $resolved[$name] = $fresh;
            $upstreamFresh = $fresh; // strict chain: a stale stage taints all downstream

            $rows[] = [
                'stage' => $name,
                'consumes' => $consumes,
                'script' => $stage['script'],
                'fresh' => $fresh,
            ];
        }

        return [
            'schema' => self::SCHEMA_PIPELINE,
            'stages' => $rows ?? [],
            'release_ready' => ($resolved['release'] ?? false),
        ];
    }

    /**
     * Per-area test/evidence matrix (Evidencias table).
     *
     * @return array{count: int, areas: array<int, array{area: string, kind: string}>}
     */
    public function testMatrix(): array
    {
        $areas = [
            ['area' => 'build/provider', 'kind' => 'unit'],
            ['area' => 'detector', 'kind' => 'fixture'],
            ['area' => 'live_mode', 'kind' => 'live_e2e'],
            ['area' => 'frameworks', 'kind' => 'fixture'],
            ['area' => 'context/design', 'kind' => 'unit'],
            ['area' => 'cli/install', 'kind' => 'unit'],
        ];

        return [
            'count' => count($areas),
            'areas' => $areas,
        ];
    }

    /**
     * Convenience aggregate used by the CLI: evaluates all four rules over one
     * shared state and folds them into a single ship/hold decision.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function evaluate(array $state = []): array
    {
        $release = $this->releaseGate($state);
        $rebuild = $this->rebuildPropagation($state);
        $live = $this->liveScriptRequirement($state);
        $editedPath = (string) ($state['edited_path'] ?? 'src/skill.md');
        $source = $this->sourceOfTruthGuard($editedPath);

        $shippable = $release['release_allowed']
            && $rebuild['rebuild_complete']
            && $live['satisfied']
            && $source['allowed'];

        return [
            'decision' => $shippable ? 'ship' : 'hold',
            'release_gate' => $release,
            'rebuild_propagation' => $rebuild,
            'live_script' => $live,
            'source_guard' => $source,
            'pipeline' => $this->buildPipeline(AtlasAaeosStringListNormalizer::uniqueNonEmptyStrings($state['fresh_stages'] ?? [])),
            'test_matrix' => $this->testMatrix(),
        ];
    }

}
