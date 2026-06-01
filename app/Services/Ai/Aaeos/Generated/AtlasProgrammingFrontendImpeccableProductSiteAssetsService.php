<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Runtime for the "Impeccable Product Site Assets And Distribution" doc — the
 * deterministic decision core for the product-proof / asset-distribution surface
 * (site, demos, before/after, download API, CLI installer), kept strictly
 * SEPARATE from internal certification runtime.
 *
 * This service does not build a site, copy bundles, or run an installer. It
 * encodes the load-bearing DECISIONS the doc states, which no other service
 * implements:
 *
 *   - CONTRATOS (area table): classify any product-site path into its role and
 *     its layer (site page / public content / public asset / installable demo /
 *     CLI installer / download API). An unknown path is reported as such, never
 *     silently accepted as proof.
 *
 *   - FLUXO (the 6-step distribution pipeline): "source skill/build -> dist
 *     bundles -> site copies dist into build data -> download API exposes
 *     provider bundles -> CLI installer checks/downloads/prefixes skills ->
 *     public demos prove visual claim." The runtime validates that an observed
 *     pipeline keeps this order (a later stage cannot run before an earlier one)
 *     and that demos-prove is the terminal stage.
 *
 *   - REGRAS PARA IA #1: "Produto visual precisa demonstrar visual quality." A
 *     product-proof artifact must carry a demonstrated visual-quality signal.
 *
 *   - REGRAS PARA IA #2: "Site e demos devem passar o proprio detector." A site
 *     or demo that has not passed its own detector is NOT product proof.
 *
 *   - REGRAS PARA IA #3: "Install/download path faz parte da experiencia." The
 *     install/download path is part of the proof surface, so a distributed
 *     bundle must have a verified hash and the installer must be dry-run +
 *     prefix + rollback safe (RISCOS mitigations) before it counts.
 *
 *   - REGRAS PARA IA #4 + ESCOPO: "Casos antes/depois sao evidence de mercado,
 *     nao runtime proof" and "readiness continua vindo de certification."
 *     Product proof (demos, before/after, screenshots) is market evidence and is
 *     NEVER readiness; readiness comes only from certification. The runtime
 *     refuses to mark anything ready from product proof alone.
 *
 * Pure and deterministic: no DB, no I/O, no network, no Astro build. It decides
 * what a path is, whether a proof artifact is admissible, and whether the
 * distribution pipeline is ordered; it never builds, never publishes, never
 * authorizes readiness.
 *
 * @see docs/engineering-knowledge-base/domains/programming-frontend-impeccable-product-site-assets.md
 */
final class AtlasProgrammingFrontendImpeccableProductSiteAssetsService
{
    public const SCHEMA_VERSION = 'atlas.frontend.product_site_assets.v1';

    public const MODE = 'read_only_product_proof_distribution_decision';

    /**
     * FLUXO: the ordered distribution pipeline. Index = required execution
     * order; a later stage must never run before an earlier one, and
     * "demos_prove" is always terminal.
     *
     * @var array<int,string>
     */
    private const PIPELINE = [
        'source_build',     // source skill/build
        'dist_bundles',     // -> dist bundles
        'site_copies_dist', // -> site copies dist into build data
        'download_exposes', // -> download API exposes provider bundles
        'cli_installer',    // -> CLI installer checks/downloads/prefixes skills
        'demos_prove',      // -> public demos prove visual claim
    ];

    /**
     * CONTRATOS area table: path-prefix (or glob stem) -> [role, layer]. The
     * order matters: more specific prefixes are listed first. "layer" separates
     * the product-proof surface from anything that could be mistaken for kernel
     * certification.
     *
     * @var array<string,array{role:string,layer:string}>
     */
    private const AREAS = [
        'site/pages/index.astro' => ['role' => 'landing principal', 'layer' => 'site_page'],
        'site/pages/designing' => ['role' => 'workflow de design', 'layer' => 'site_page'],
        'site/pages/live-mode' => ['role' => 'pagina de Live Mode', 'layer' => 'site_page'],
        'site/pages/detector' => ['role' => 'detector lab e fixtures', 'layer' => 'site_page'],
        'site/content/skills' => ['role' => 'docs publicas por comando', 'layer' => 'public_content'],
        'site/content/tutorials' => ['role' => 'getting started, live, overlay', 'layer' => 'public_content'],
        'site/public/assets' => ['role' => 'logos, before/after, OG, case assets', 'layer' => 'public_asset'],
        'demos/landing-demo' => ['role' => 'demo instalavel', 'layer' => 'installable_demo'],
        'cli/bin/commands/skills.mjs' => ['role' => 'check/install/prefix skills', 'layer' => 'cli_installer'],
        'functions/api/download' => ['role' => 'download de bundles', 'layer' => 'download_api'],
    ];

    /** Every layer here is product-proof surface, never kernel/certification. */
    private const PROOF_LAYERS = [
        'site_page',
        'public_content',
        'public_asset',
        'installable_demo',
        'cli_installer',
        'download_api',
    ];

    /**
     * REGRAS PARA IA — the four numbered rules, surfaced verbatim-in-spirit so
     * callers and tests can pin them.
     *
     * @return array<int,array{n:int,rule:string,enforced_by:string}>
     */
    public function rules(): array
    {
        return [
            ['n' => 1, 'rule' => 'Produto visual precisa demonstrar visual quality.', 'enforced_by' => 'evaluateProofArtifact'],
            ['n' => 2, 'rule' => 'Site e demos devem passar o proprio detector.', 'enforced_by' => 'evaluateProofArtifact'],
            ['n' => 3, 'rule' => 'Install/download path faz parte da experiencia.', 'enforced_by' => 'evaluateDistribution'],
            ['n' => 4, 'rule' => 'Casos antes/depois sao evidence de mercado, nao runtime proof.', 'enforced_by' => 'evaluateProofArtifact'],
        ];
    }

    /**
     * FLUXO surfaced as an ordered list.
     *
     * @return array<int,string>
     */
    public function flow(): array
    {
        return self::PIPELINE;
    }

    /**
     * CONTRATOS: classify a product-site path into role + layer. Matches against
     * the documented area prefixes; an unmatched path is flagged unknown and is
     * NOT treated as proof surface.
     *
     * @return array{
     *   schema_version:string, mode:string, path:string, matched:bool,
     *   area:?string, role:?string, layer:?string, is_proof_surface:bool,
     *   reason:string, readiness_authorized:bool
     * }
     */
    public function classifyPath(string $path): array
    {
        $normalized = $this->normalizePath($path);
        $matchedArea = null;
        $role = null;
        $layer = null;

        foreach (self::AREAS as $prefix => $meta) {
            if ($normalized === $prefix || str_starts_with($normalized, $prefix.'/')) {
                $matchedArea = $prefix;
                $role = $meta['role'];
                $layer = $meta['layer'];
                break;
            }
        }

        $matched = $matchedArea !== null;
        $isProofSurface = $matched && in_array((string) $layer, self::PROOF_LAYERS, true);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'path' => $normalized,
            'matched' => $matched,
            'area' => $matchedArea,
            'role' => $role,
            'layer' => $layer,
            'is_proof_surface' => $isProofSurface,
            'reason' => $matched
                ? "path maps to area '{$matchedArea}' ({$role}) on the {$layer} layer."
                : 'path does not map to any documented product-site area; it is not product-proof surface.',
            // Even a perfectly-matched proof surface never confers readiness.
            'readiness_authorized' => false,
        ];
    }

    /**
     * REGRAS #1, #2, #4 + ESCOPO. Decide whether a product-proof artifact is
     * admissible AS proof, and (separately) whether it can confer readiness.
     *
     * A proof artifact is admissible only when it BOTH demonstrates visual
     * quality (#1) AND has passed its own detector (#2). A before/after-only case
     * is market evidence (#4): it is admissible as market evidence but can never
     * be runtime proof or readiness.
     *
     * Readiness is ALWAYS withheld here — it comes only from certification.
     *
     * @param  array{
     *   kind?:string, demonstrates_visual_quality?:bool, detector_passed?:bool,
     *   has_runtime?:bool
     * }  $artifact
     * @return array{
     *   schema_version:string, mode:string, kind:string,
     *   demonstrates_visual_quality:bool, detector_passed:bool,
     *   is_market_evidence_only:bool, admissible_as_proof:bool,
     *   admissible_as_market_evidence:bool, is_runtime_proof:bool,
     *   confers_readiness:bool, blocking_reasons:array<int,string>,
     *   readiness_authorized:bool
     * }
     */
    public function evaluateProofArtifact(array $artifact): array
    {
        $kind = isset($artifact['kind']) ? strtolower(trim((string) $artifact['kind'])) : 'unspecified';
        $visualQuality = (bool) ($artifact['demonstrates_visual_quality'] ?? false);
        $detectorPassed = (bool) ($artifact['detector_passed'] ?? false);
        $hasRuntime = (bool) ($artifact['has_runtime'] ?? false);

        // REGRA #4: a before/after case is market evidence, not runtime proof,
        // regardless of how good it looks.
        $marketEvidenceOnly = in_array($kind, ['before_after', 'before-after', 'case_study', 'screenshot'], true);

        $blocking = [];
        if (! $visualQuality) {
            $blocking[] = 'rule_1: product visual does not demonstrate visual quality.';
        }
        if (! $detectorPassed) {
            $blocking[] = 'rule_2: site/demo has not passed its own detector.';
        }

        // Admissible as PROOF only if it clears #1 and #2 and is not merely a
        // before/after case (#4).
        $admissibleAsProof = $visualQuality && $detectorPassed && ! $marketEvidenceOnly;

        if ($marketEvidenceOnly) {
            $blocking[] = 'rule_4: before/after case is market evidence, not runtime proof.';
        }

        // A demo backed by the real runtime that also clears #1/#2 is runtime
        // proof; a before/after case never is.
        $isRuntimeProof = $admissibleAsProof && $hasRuntime;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'kind' => $kind,
            'demonstrates_visual_quality' => $visualQuality,
            'detector_passed' => $detectorPassed,
            'is_market_evidence_only' => $marketEvidenceOnly,
            'admissible_as_proof' => $admissibleAsProof,
            // A clean before/after still counts as market evidence (#4).
            'admissible_as_market_evidence' => $marketEvidenceOnly ? $visualQuality : $admissibleAsProof,
            'is_runtime_proof' => $isRuntimeProof,
            // ESCOPO: readiness comes only from certification — never here.
            'confers_readiness' => false,
            'blocking_reasons' => $blocking,
            'readiness_authorized' => false,
        ];
    }

    /**
     * REGRA #3 + RISCOS. Decide whether a distributed bundle / installer step is
     * safe to count as part of the experience.
     *
     * RISCOS mitigations made executable:
     *   - "Download driftado -> build/certify valida bundle hash": the bundle's
     *     declared hash must equal its computed hash, else it is drift.
     *   - "Installer altera skill errada -> dry-run, prefix, rollback": the
     *     installer must be dry-run capable, prefix the skill, and support
     *     rollback, else it is unsafe.
     *
     * @param  array{
     *   declared_hash?:string, computed_hash?:string, dry_run?:bool,
     *   prefixed?:bool, rollback_supported?:bool
     * }  $bundle
     * @return array{
     *   schema_version:string, mode:string, hash_verified:bool, drift:bool,
     *   installer_safe:bool, dry_run:bool, prefixed:bool,
     *   rollback_supported:bool, distributable:bool,
     *   blocking_reasons:array<int,string>, readiness_authorized:bool
     * }
     */
    public function evaluateDistribution(array $bundle): array
    {
        $declared = isset($bundle['declared_hash']) ? trim((string) $bundle['declared_hash']) : '';
        $computed = isset($bundle['computed_hash']) ? trim((string) $bundle['computed_hash']) : '';
        $dryRun = (bool) ($bundle['dry_run'] ?? false);
        $prefixed = (bool) ($bundle['prefixed'] ?? false);
        $rollback = (bool) ($bundle['rollback_supported'] ?? false);

        $hashVerified = $declared !== '' && $computed !== '' && hash_equals($declared, $computed);
        $drift = ! $hashVerified;

        // Installer is safe only with all three mitigations: dry-run + prefix +
        // rollback.
        $installerSafe = $dryRun && $prefixed && $rollback;

        $blocking = [];
        if ($declared === '' || $computed === '') {
            $blocking[] = 'risk_download_drift: bundle hash missing; build/certify must validate bundle hash.';
        } elseif ($drift) {
            $blocking[] = 'risk_download_drift: declared hash does not match computed hash.';
        }
        if (! $dryRun) {
            $blocking[] = 'risk_installer_wrong_skill: installer is not dry-run capable.';
        }
        if (! $prefixed) {
            $blocking[] = 'risk_installer_wrong_skill: installer does not prefix the skill.';
        }
        if (! $rollback) {
            $blocking[] = 'risk_installer_wrong_skill: installer has no rollback.';
        }

        $distributable = $hashVerified && $installerSafe;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'hash_verified' => $hashVerified,
            'drift' => $drift,
            'installer_safe' => $installerSafe,
            'dry_run' => $dryRun,
            'prefixed' => $prefixed,
            'rollback_supported' => $rollback,
            'distributable' => $distributable,
            'blocking_reasons' => $blocking,
            'readiness_authorized' => false,
        ];
    }

    /**
     * FLUXO ordering check. Given an observed sequence of pipeline stage ids,
     * decide whether it respects the documented order (a stage may be skipped,
     * but must not appear before a stage that the doc places earlier) and whether
     * it terminates at "demos_prove".
     *
     * @param  array<int,string>  $observedStages
     * @return array{
     *   schema_version:string, mode:string, canonical_order:array<int,string>,
     *   observed:array<int,string>, in_order:bool, terminates_at_demos:bool,
     *   first_violation:?array{stage:string,expected_after:string},
     *   reason:string, readiness_authorized:bool
     * }
     */
    public function evaluatePipelineOrder(array $observedStages): array
    {
        $rank = array_flip(self::PIPELINE);
        $observed = [];
        foreach ($observedStages as $stage) {
            $stage = strtolower(trim((string) $stage));
            if ($stage !== '') {
                $observed[] = $stage;
            }
        }

        $inOrder = true;
        $firstViolation = null;
        $lastRank = -1;
        $lastKnownStage = '';

        foreach ($observed as $stage) {
            if (! array_key_exists($stage, $rank)) {
                // Unknown stages are ignored for ordering but do not satisfy
                // termination.
                continue;
            }
            $r = $rank[$stage];
            if ($r < $lastRank) {
                $inOrder = false;
                $firstViolation = [
                    'stage' => $stage,
                    'expected_after' => $lastKnownStage,
                ];
                break;
            }
            $lastRank = $r;
            $lastKnownStage = $stage;
        }

        $terminatesAtDemos = $observed !== [] && end($observed) === 'demos_prove';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'canonical_order' => self::PIPELINE,
            'observed' => $observed,
            'in_order' => $inOrder,
            'terminates_at_demos' => $terminatesAtDemos,
            'first_violation' => $firstViolation,
            'reason' => $inOrder
                ? ($terminatesAtDemos
                    ? 'pipeline respects the documented order and ends at public demos.'
                    : 'pipeline order is valid but does not terminate at demos_prove.')
                : "stage '{$firstViolation['stage']}' runs before '{$firstViolation['expected_after']}', violating the documented FLUXO order.",
            'readiness_authorized' => false,
        ];
    }

    /**
     * Catalogue summary: the areas, the pipeline, the rules and a worked sample.
     * Used by the command as a safe-default render.
     *
     * @return array<string,mixed>
     */
    public function describe(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'pipeline' => self::PIPELINE,
            'pipeline_terminal_stage' => self::PIPELINE[count(self::PIPELINE) - 1],
            'area_count' => count(self::AREAS),
            'proof_layers' => self::PROOF_LAYERS,
            'rules' => $this->rules(),
            'sample_classify' => $this->classifyPath('demos/landing-demo/src/index.html'),
            'sample_proof' => $this->evaluateProofArtifact([
                'kind' => 'demo',
                'demonstrates_visual_quality' => true,
                'detector_passed' => true,
                'has_runtime' => true,
            ]),
            'sample_distribution' => $this->evaluateDistribution([
                'declared_hash' => 'abc123',
                'computed_hash' => 'abc123',
                'dry_run' => true,
                'prefixed' => true,
                'rollback_supported' => true,
            ]),
            // Product proof never confers readiness — certification does.
            'readiness_authorized' => false,
        ];
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, './');

        return rtrim($path, '/');
    }
}
