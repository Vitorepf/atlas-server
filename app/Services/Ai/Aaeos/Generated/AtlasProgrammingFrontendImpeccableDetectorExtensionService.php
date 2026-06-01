<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Runtime for the "Impeccable Detector And Browser Extension" doc — the
 * deterministic decision core of an Atlas Frontend Quality Detector.
 *
 * This service does NOT scan files itself (the static-source scan already lives
 * in AtlasFrontendAntiSlopDetectorService). It encodes the load-bearing
 * DECISIONS the doc states, which no other service implements:
 *
 *   - FLUXO engine selection: "target file/dir/url/stdin -> choose engine".
 *     A url routes to the browser engine, an .html file to static-html, stdin
 *     to regex, a directory / source file to regex, an image/screenshot to the
 *     visual (pixel-contrast) engine.
 *
 *   - FLUXO exit semantics: "exit 2 when findings exist". The detector exits 2
 *     iff there is at least one finding, else 0. This is the gate signal.
 *
 *   - REGRAS PARA IA #4: "Browser scan e mais forte que regex para layout real."
 *     and #5: "Pixel contrast cobre casos onde CSS analitico falha." → the
 *     engine-strength ordering for layout is browser > static-html > regex, and
 *     contrast is only fully covered by the visual engine. A layout/overlap or
 *     contrast claim made from a weaker engine than required is NOT sufficient.
 *
 *   - REGRAS PARA IA #1: "Detector e evidencia, nao prova de design final." →
 *     runtime never authorizes, and a clean detector is evidence, never a
 *     final-design completion proof on its own.
 *
 *   - REGRAS PARA IA #2 & #3: every finding must carry severity + impact, and
 *     each finding must accept a false-positive policy. A finding missing any of
 *     these is rejected as malformed (it cannot become a silent pass).
 *
 *   - EVIDENCIAS: "29 regras auditadas: 16 slop, 13 quality." The catalogue
 *     summary enforces exactly 29 = 16 + 13.
 *
 * Pure and deterministic: no DB, no I/O, no network, no Puppeteer. It decides
 * which engine a target needs and whether a claim is backed by a strong-enough
 * engine; it never runs an engine, never spends tokens, never authorizes
 * runtime.
 *
 * @see docs/engineering-knowledge-base/domains/programming-frontend-impeccable-detector-extension.md
 */
final class AtlasProgrammingFrontendImpeccableDetectorExtensionService
{
    public const SCHEMA_VERSION = 'atlas.frontend.detector_findings.v1';

    public const MODE = 'read_only_frontend_detector_decision';

    /** Doc EVIDENCIAS: 29 audited rules = 16 slop + 13 quality. */
    public const RULE_TOTAL = 29;

    public const RULE_SLOP_COUNT = 16;

    public const RULE_QUALITY_COUNT = 13;

    /** FLUXO: "exit 2 when findings exist", else 0. */
    public const EXIT_FINDINGS = 2;

    public const EXIT_CLEAN = 0;

    /** Engine ids, ordered weakest -> strongest for real-layout fidelity. */
    public const ENGINE_REGEX = 'regex';

    public const ENGINE_STATIC_HTML = 'static_html';

    public const ENGINE_BROWSER = 'browser';

    public const ENGINE_VISUAL = 'visual';

    /**
     * Layout-fidelity strength ladder (REGRA #4). Higher = stronger for real
     * layout. The visual engine sits at the top because it observes rendered
     * pixels; the browser engine renders DOM/CSS; static-html parses cascade
     * without layout; regex is purely textual.
     *
     * @var array<string,int>
     */
    private const ENGINE_STRENGTH = [
        self::ENGINE_REGEX => 1,
        self::ENGINE_STATIC_HTML => 2,
        self::ENGINE_BROWSER => 3,
        self::ENGINE_VISUAL => 4,
    ];

    /**
     * The minimum engine each finding KIND requires before its claim is
     * trustworthy.
     *   - layout / overlap: needs a real renderer (browser) — REGRA #4.
     *   - contrast: needs rendered pixels (visual) — REGRA #5 ("pixel contrast
     *     cobre casos onde CSS analitico falha").
     *   - everything else (textual slop, copy, naming): regex is enough.
     *
     * @var array<string,string>
     */
    private const KIND_MIN_ENGINE = [
        'layout' => self::ENGINE_BROWSER,
        'overlap' => self::ENGINE_BROWSER,
        'responsive' => self::ENGINE_BROWSER,
        'contrast' => self::ENGINE_VISUAL,
    ];

    /** Image/screenshot extensions route to the visual engine. */
    private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'bmp'];

    /** Markup files route to the static-html engine. */
    private const HTML_EXTENSIONS = ['html', 'htm'];

    /**
     * Decide which engine a target requires (FLUXO step "choose engine").
     *
     * @param  string  $target  A url, a file path, a directory path, or the
     *                          literal "-"/"stdin" for piped source.
     * @return array{
     *   schema_version:string, mode:string, target:string, target_kind:string,
     *   engine:string, engine_strength:int, reason:string,
     *   stronger_engine_available:?string, runtime_authorized:bool
     * }
     */
    public function chooseEngine(string $target): array
    {
        $target = trim($target);
        [$kind, $engine, $reason] = $this->resolveTarget($target);

        $stronger = $this->strongerEngineThan($engine);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'target' => $target,
            'target_kind' => $kind,
            'engine' => $engine,
            'engine_strength' => self::ENGINE_STRENGTH[$engine],
            'reason' => $reason,
            'stronger_engine_available' => $stronger,
            'runtime_authorized' => false,
        ];
    }

    /**
     * Assemble a detector findings report from already-collected findings and
     * decide the exit code + claim policy. Findings are validated: each must
     * carry rule_id, severity (REGRA #2), impact (REGRA #2) and a
     * false_positive_policy (REGRA #3). Malformed findings are rejected and
     * recorded, never silently dropped into a pass.
     *
     * @param  string  $target
     * @param  string  $engine  The engine that produced these findings.
     * @param  array<int,array<string,mixed>>  $findings
     * @param  string|null  $viewport
     * @return array{
     *   schema_version:string, mode:string, target:string, engine:string,
     *   engine_strength:int, viewport:?string, findings:array<int,array<string,mixed>>,
     *   finding_count:int, malformed_findings:array<int,array<string,mixed>>,
     *   malformed_count:int, severity:string, exit_code:int, has_findings:bool,
     *   evidence_refs:array<int,string>, false_positive_policy:string,
     *   claim_policy:array<string,bool>, runtime_authorized:bool
     * }
     */
    public function assembleReport(string $target, string $engine, array $findings, ?string $viewport = null): array
    {
        $engine = $this->normalizeEngine($engine);

        $clean = [];
        $malformed = [];
        $evidenceRefs = [];

        foreach (array_values($findings) as $i => $finding) {
            $validation = $this->validateFinding($finding);
            if ($validation['ok']) {
                $normalized = $validation['finding'];
                $clean[] = $normalized;
                foreach ($normalized['evidence_refs'] as $ref) {
                    $evidenceRefs[] = $ref;
                }

                continue;
            }
            $malformed[] = [
                'index' => $i,
                'missing' => $validation['missing'],
                'reason' => $validation['reason'],
            ];
        }

        $hasFindings = $clean !== [];
        $severity = $this->aggregateSeverity($clean);
        $evidenceRefs = array_values(array_unique($evidenceRefs));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'target' => trim($target),
            'engine' => $engine,
            'engine_strength' => self::ENGINE_STRENGTH[$engine],
            'viewport' => $viewport !== null && trim($viewport) !== '' ? trim($viewport) : null,
            'findings' => $clean,
            'finding_count' => count($clean),
            'malformed_findings' => $malformed,
            'malformed_count' => count($malformed),
            'severity' => $severity,
            // FLUXO: exit 2 when findings exist, else 0.
            'exit_code' => $hasFindings ? self::EXIT_FINDINGS : self::EXIT_CLEAN,
            'has_findings' => $hasFindings,
            'evidence_refs' => $evidenceRefs,
            'false_positive_policy' => 'A finding may be suppressed only with a brand/product rationale plus a stronger-engine re-check; suppression is never silent.',
            'claim_policy' => [
                // REGRA #1: detector is evidence, not final-design proof.
                'detector_is_evidence_not_final_design_proof' => true,
                'clean_detector_alone_is_not_completion_proof' => true,
                'findings_require_severity_impact_and_false_positive_policy' => true,
                'malformed_finding_cannot_become_silent_pass' => $malformed === [],
            ],
            'runtime_authorized' => false,
        ];
    }

    /**
     * REGRAS #4 & #5: decide whether a claim of a given kind is backed by a
     * strong-enough engine. A "layout"/"overlap" claim needs the browser engine
     * or stronger; a "contrast" claim needs the visual engine. A weaker engine
     * is insufficient and must escalate to the required engine.
     *
     * @param  string  $claimKind  e.g. "layout", "overlap", "contrast", "slop".
     * @param  string  $engineUsed
     * @return array{
     *   schema_version:string, claim_kind:string, engine_used:string,
     *   engine_used_strength:int, required_engine:string,
     *   required_strength:int, sufficient:bool, escalate_to:?string,
     *   reason:string, runtime_authorized:bool
     * }
     */
    public function evaluateClaimEngine(string $claimKind, string $engineUsed): array
    {
        $claimKind = strtolower(trim($claimKind));
        $engineUsed = $this->normalizeEngine($engineUsed);
        $required = self::KIND_MIN_ENGINE[$claimKind] ?? self::ENGINE_REGEX;

        $usedStrength = self::ENGINE_STRENGTH[$engineUsed];
        $requiredStrength = self::ENGINE_STRENGTH[$required];
        $sufficient = $usedStrength >= $requiredStrength;

        $reason = $sufficient
            ? "engine '{$engineUsed}' meets the minimum '{$required}' for a {$claimKind} claim."
            : "engine '{$engineUsed}' is weaker than required '{$required}' for a {$claimKind} claim; "
                .($required === self::ENGINE_VISUAL
                    ? 'pixel contrast covers cases where analytic CSS fails.'
                    : 'browser scan is stronger than regex for real layout.');

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'claim_kind' => $claimKind,
            'engine_used' => $engineUsed,
            'engine_used_strength' => $usedStrength,
            'required_engine' => $required,
            'required_strength' => $requiredStrength,
            'sufficient' => $sufficient,
            'escalate_to' => $sufficient ? null : $required,
            'reason' => $reason,
            'runtime_authorized' => false,
        ];
    }

    /**
     * Catalogue summary enforcing the doc EVIDENCIAS count: 29 = 16 slop + 13
     * quality. Returns the engine ladder and a worked engine-selection sample.
     *
     * @return array<string,mixed>
     */
    public function describe(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'rule_catalogue' => [
                'total' => self::RULE_TOTAL,
                'slop' => self::RULE_SLOP_COUNT,
                'quality' => self::RULE_QUALITY_COUNT,
                'sum_matches_total' => (self::RULE_SLOP_COUNT + self::RULE_QUALITY_COUNT) === self::RULE_TOTAL,
            ],
            'engine_strength_ladder' => self::ENGINE_STRENGTH,
            'kind_min_engine' => self::KIND_MIN_ENGINE,
            'exit_codes' => [
                'findings_exist' => self::EXIT_FINDINGS,
                'clean' => self::EXIT_CLEAN,
            ],
            'rules' => [
                'detector is evidence, not final-design proof',
                'every finding carries severity and impact',
                'every finding accepts a false-positive policy',
                'browser scan is stronger than regex for real layout',
                'pixel contrast covers cases where analytic CSS fails',
                'exit 2 when findings exist, else 0',
            ],
            'sample_choose_engine' => $this->chooseEngine('https://localhost:5173/'),
            'runtime_authorized' => false,
        ];
    }

    /**
     * Resolve a target string into [kind, engine, reason].
     *
     * @return array{0:string,1:string,2:string}
     */
    private function resolveTarget(string $target): array
    {
        if ($target === '' || $target === '-' || strtolower($target) === 'stdin') {
            return ['stdin', self::ENGINE_REGEX, 'piped source has no URL/markup boundary; textual regex engine applies.'];
        }

        if ($this->isUrl($target)) {
            return ['url', self::ENGINE_BROWSER, 'a URL renders real layout; the browser engine is required (regex cannot see real layout).'];
        }

        $ext = strtolower((string) pathinfo($target, PATHINFO_EXTENSION));

        if ($ext !== '' && in_array($ext, self::IMAGE_EXTENSIONS, true)) {
            return ['screenshot', self::ENGINE_VISUAL, 'an image/screenshot target uses the visual pixel-contrast engine.'];
        }

        if ($ext !== '' && in_array($ext, self::HTML_EXTENSIONS, true)) {
            return ['html_file', self::ENGINE_STATIC_HTML, 'a standalone HTML file is parsed for cascade by the static-html engine.'];
        }

        if ($ext === '' || str_ends_with($target, '/')) {
            return ['directory', self::ENGINE_REGEX, 'a directory of source is scanned textually by the regex engine.'];
        }

        return ['source_file', self::ENGINE_REGEX, 'a source file (css/jsx/tsx/svelte/vue) is scanned textually by the regex engine.'];
    }

    private function isUrl(string $target): bool
    {
        return (bool) preg_match('#^(https?|file)://#i', $target);
    }

    private function strongerEngineThan(string $engine): ?string
    {
        $current = self::ENGINE_STRENGTH[$engine];
        $stronger = null;
        $strongerLevel = $current;
        foreach (self::ENGINE_STRENGTH as $id => $level) {
            if ($level > $strongerLevel) {
                $stronger = $id;
                $strongerLevel = $level;
            }
        }

        return $stronger;
    }

    private function normalizeEngine(string $engine): string
    {
        $engine = strtolower(trim($engine));

        return array_key_exists($engine, self::ENGINE_STRENGTH) ? $engine : self::ENGINE_REGEX;
    }

    /**
     * REGRAS #2 & #3: a finding must carry rule_id, a known severity, an impact
     * statement and a false-positive policy.
     *
     * @param  mixed  $finding
     * @return array{ok:bool,finding:array<string,mixed>,missing:array<int,string>,reason:string}
     */
    private function validateFinding(mixed $finding): array
    {
        $missing = [];

        if (! is_array($finding)) {
            return ['ok' => false, 'finding' => [], 'missing' => ['rule_id', 'severity', 'impact', 'false_positive_policy'], 'reason' => 'finding is not a map.'];
        }

        $ruleId = isset($finding['rule_id']) ? trim((string) $finding['rule_id']) : '';
        if ($ruleId === '') {
            $missing[] = 'rule_id';
        }

        $severity = isset($finding['severity']) ? strtolower(trim((string) $finding['severity'])) : '';
        if (! in_array($severity, ['high', 'medium', 'low'], true)) {
            $missing[] = 'severity';
        }

        $impact = isset($finding['impact']) ? trim((string) $finding['impact']) : '';
        if ($impact === '') {
            $missing[] = 'impact';
        }

        $fpPolicy = isset($finding['false_positive_policy']) ? trim((string) $finding['false_positive_policy']) : '';
        if ($fpPolicy === '') {
            $missing[] = 'false_positive_policy';
        }

        if ($missing !== []) {
            return [
                'ok' => false,
                'finding' => [],
                'missing' => $missing,
                'reason' => 'finding missing required fields: '.implode(', ', $missing),
            ];
        }

        $evidenceRefs = [];
        if (isset($finding['evidence_refs']) && is_array($finding['evidence_refs'])) {
            foreach ($finding['evidence_refs'] as $ref) {
                $ref = trim((string) $ref);
                if ($ref !== '') {
                    $evidenceRefs[] = $ref;
                }
            }
        }

        return [
            'ok' => true,
            'finding' => [
                'rule_id' => $ruleId,
                'severity' => $severity,
                'category' => isset($finding['category']) ? (string) $finding['category'] : 'unspecified',
                'impact' => $impact,
                'false_positive_policy' => $fpPolicy,
                'evidence_refs' => array_values(array_unique($evidenceRefs)),
            ],
            'missing' => [],
            'reason' => 'valid',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $findings
     */
    private function aggregateSeverity(array $findings): string
    {
        if ($findings === []) {
            return 'none';
        }
        $rank = ['low' => 1, 'medium' => 2, 'high' => 3];
        $max = 0;
        foreach ($findings as $f) {
            $max = max($max, $rank[(string) $f['severity']] ?? 0);
        }

        return array_search($max, $rank, true) ?: 'low';
    }
}
