<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas AI Cyber Existing Tools — pure, deterministic reuse-before-propose gate.
 *
 * This doc is NOT a recipe-admission contract (that is recipes-catalog, served by
 * {@see AtlasCyberRecipesCatalogService}). Its concrete contract is the upstream
 * decision a cyber skill must make BEFORE it ever drafts a recipe:
 *
 *   "Do not create a new cyber recipe when an existing registry tool can produce
 *    the same evidence. Add a new recipe only when the tool, mode or evidence
 *    shape is missing."
 *
 * The doc backs that rule with a fixed table of the defensive / quality tools that
 * are already in the canonical Atlas Super Tool Runtime, each mapped to the
 * evidence it produces:
 *
 *   gitleaks   -> secret scanning
 *   semgrep    -> SAST / pattern-based source review
 *   osv_scanner-> dependency CVE scan
 *   trivy      -> image, filesystem, repository and Kubernetes security scan
 *   syft       -> SBOM generation
 *   checkov    -> IaC scan
 *   phpstan    -> PHP static analysis (remediation flows)
 *   typescript -> TypeScript correctness (remediation flows)
 *   eslint     -> JavaScript/TypeScript lint (remediation flows)
 *   biome      -> JS/TS formatting and lint (remediation flows)
 *   hadolint   -> Dockerfile lint and remediation evidence
 *
 * Given a requested capability / evidence shape this service returns the single
 * controlled verdict — `reuse` (an existing tool already produces it, named) or
 * `propose_new` (genuinely missing: tool, mode or evidence shape absent) — never a
 * "maybe". It never executes a tool, connects to anything, queries a database or
 * mutates state; it only decides and points at the registered tool to reuse.
 *
 * @see docs/engineering-knowledge-base/cyber-security/recipes-existing-tools.md
 */
final class AtlasRecipesExistingToolsService
{
    /** Stable receipt schema id this decider emits. */
    public const SCHEMA = 'atlas.cyber.existing_tools_reuse_gate.v1';

    /** Reuse-gate verdicts (closed set). */
    public const VERDICT_REUSE = 'reuse';
    public const VERDICT_PROPOSE_NEW = 'propose_new';

    /**
     * The canonical registry table from the doc: each already-registered tool, the
     * evidence/use it covers, the capability keywords that map a request to it, and
     * whether it is a multi-mode tool (trivy covers image/fs/repo/k8s). Order matches
     * the doc table.
     *
     * Capability keywords are stored normalized (lowercased, '-'/'_'/space collapsed)
     * so "secret-scan", "secret_scan" and "Secret Scan" all match.
     *
     * @var array<string, array{use:string, capabilities:list<string>, modes?:list<string>}>
     */
    public const REGISTERED_TOOLS = [
        'gitleaks' => [
            'use' => 'Secret scanning.',
            'capabilities' => ['secretscan', 'secrets', 'secretdetection', 'credentialscan', 'leakscan'],
        ],
        'semgrep' => [
            'use' => 'SAST and pattern-based source review.',
            'capabilities' => ['sast', 'staticanalysis', 'patternreview', 'sourcereview', 'codescan'],
        ],
        'osv_scanner' => [
            'use' => 'Dependency CVE scan.',
            'capabilities' => ['dependencycve', 'cvescan', 'dependencyscan', 'vulnerabilityscan', 'osvscan', 'cve'],
        ],
        'trivy' => [
            'use' => 'Image, filesystem, repository and Kubernetes security scan.',
            'capabilities' => ['imagescan', 'containerscan', 'filesystemscan', 'fsscan', 'repositoryscan', 'kubernetesscan', 'k8sscan'],
            'modes' => ['image', 'filesystem', 'repository', 'kubernetes'],
        ],
        'syft' => [
            'use' => 'SBOM generation.',
            'capabilities' => ['sbom', 'sbomgeneration', 'bomgeneration', 'softwarebillofmaterials'],
        ],
        'checkov' => [
            'use' => 'IaC scan.',
            'capabilities' => ['iacscan', 'iac', 'infrastructureascode', 'terraformscan', 'cloudformationscan'],
        ],
        'phpstan' => [
            'use' => 'PHP static analysis in remediation flows.',
            'capabilities' => ['phpstaticanalysis', 'phpanalysis', 'phpstan', 'phplint'],
        ],
        'typescript' => [
            'use' => 'TypeScript correctness in remediation flows.',
            'capabilities' => ['typescriptcorrectness', 'typecheck', 'tscheck', 'typescriptcheck'],
        ],
        'eslint' => [
            'use' => 'JavaScript/TypeScript lint in remediation flows.',
            'capabilities' => ['jslint', 'javascriptlint', 'tslint', 'eslint'],
        ],
        'biome' => [
            'use' => 'JS/TS formatting and lint in remediation flows.',
            'capabilities' => ['jsformat', 'jstsformat', 'tsformat', 'jstslint', 'biome', 'formatting'],
        ],
        'hadolint' => [
            'use' => 'Dockerfile lint and remediation evidence.',
            'capabilities' => ['dockerfilelint', 'dockerlint', 'hadolint', 'containerfilelint'],
        ],
    ];

    /**
     * Decide whether a requested cyber capability must REUSE an already-registered
     * tool or may be PROPOSED as a new recipe.
     *
     * The doc's "tool, mode or evidence shape is missing" escape is honored: even
     * when a tool family matches, the request can force `propose_new` by declaring a
     * mode the matched tool does not support (`requested_mode`) or by declaring that
     * the needed evidence shape is genuinely absent (`evidence_shape_missing: true`).
     *
     * @param array<string,mixed> $request
     *        capability             : string  e.g. "secret_scan", "dependency_cve",
     *                                          "Kubernetes scan", "binary diffing"
     *        requested_mode         : string|null  optional sub-mode (only meaningful
     *                                               for multi-mode tools, e.g. trivy)
     *        evidence_shape_missing : bool    operator asserts the evidence shape this
     *                                          capability needs is not produced today
     *
     * @return array<string,mixed> the reuse-gate receipt
     */
    public function evaluate(array $request): array
    {
        $capabilityRaw = $this->str($request['capability'] ?? null);
        $capability = $this->normalizeToken($request['capability'] ?? null);
        $requestedMode = $this->normalizeToken($request['requested_mode'] ?? null);
        $evidenceShapeMissing = (bool) ($request['evidence_shape_missing'] ?? false);

        // No capability declared at all -> nothing to match against; treat as a new
        // proposal that must justify itself (cannot silently claim reuse).
        if ($capability === null) {
            return $this->receipt(
                self::VERDICT_PROPOSE_NEW,
                null,
                null,
                'tool_missing',
                'No capability was declared, so no existing tool can be matched; a concrete capability is required before reuse can be claimed.',
                null,
                null,
            );
        }

        $match = $this->matchTool($capability);

        // Rule escape #1: tool is missing — nothing in the registry covers this
        // capability. A new recipe is justified.
        if ($match === null) {
            return $this->receipt(
                self::VERDICT_PROPOSE_NEW,
                null,
                null,
                'tool_missing',
                "No registered tool produces evidence for '{$capabilityRaw}'; a new recipe is justified.",
                null,
                null,
            );
        }

        [$slug, $spec] = $match;
        $modes = $spec['modes'] ?? null;

        // Rule escape #2: mode is missing — a multi-mode tool matched, but the
        // requested sub-mode is not one it supports.
        if ($requestedMode !== null && $modes !== null) {
            $supported = array_map(fn (string $m): string => $this->normalizeToken($m) ?? $m, $modes);
            if (! in_array($requestedMode, $supported, true)) {
                return $this->receipt(
                    self::VERDICT_PROPOSE_NEW,
                    $slug,
                    $spec['use'],
                    'mode_missing',
                    "Tool '{$slug}' is registered but does not support the requested mode; a new recipe is justified for the missing mode.",
                    $modes,
                    $requestedMode,
                );
            }
        }

        // Rule escape #3: evidence shape is missing — the operator asserts the
        // evidence shape this capability needs is not produced today, even though a
        // tool family nominally matches.
        if ($evidenceShapeMissing) {
            return $this->receipt(
                self::VERDICT_PROPOSE_NEW,
                $slug,
                $spec['use'],
                'evidence_shape_missing',
                "Tool '{$slug}' matches the family but the required evidence shape is missing; a new recipe is justified.",
                $modes,
                $requestedMode,
            );
        }

        // Default and dominant path: an existing tool already produces this evidence
        // -> REUSE it; do not propose a new recipe.
        return $this->receipt(
            self::VERDICT_REUSE,
            $slug,
            $spec['use'],
            null,
            "Existing registry tool '{$slug}' already produces this evidence ({$spec['use']}); reuse it instead of creating a new recipe.",
            $modes,
            $requestedMode,
        );
    }

    /**
     * Convenience predicate: would this request be forced to REUSE an existing tool
     * (i.e. creating a new recipe for it is forbidden by the doc Rule)?
     *
     * @param array<string,mixed> $request
     */
    public function mustReuse(array $request): bool
    {
        return $this->evaluate($request)['verdict'] === self::VERDICT_REUSE;
    }

    /**
     * The registered tool whose registry entry covers a capability, or null when no
     * tool covers it. Public so callers can resolve "what tool gives me X" directly.
     *
     * @return string|null the tool slug (e.g. "gitleaks") or null
     */
    public function toolForCapability(string $capability): ?string
    {
        $normalized = $this->normalizeToken($capability);
        if ($normalized === null) {
            return null;
        }

        $match = $this->matchTool($normalized);

        return $match === null ? null : $match[0];
    }

    /**
     * Whether a tool slug is already in the canonical registry (and therefore must
     * be reused, not re-registered). Accepts the "osv-scanner" / "osv_scanner" forms.
     */
    public function isRegisteredTool(string $toolSlug): bool
    {
        $normalized = $this->normalizeToken($toolSlug);
        if ($normalized === null) {
            return false;
        }

        foreach (array_keys(self::REGISTERED_TOOLS) as $slug) {
            if ($this->normalizeToken($slug) === $normalized) {
                return true;
            }
        }

        return false;
    }

    /**
     * The flat registry projection: slug => documented use. Mirrors the doc table.
     *
     * @return array<string,string>
     */
    public function registry(): array
    {
        $out = [];
        foreach (self::REGISTERED_TOOLS as $slug => $spec) {
            $out[$slug] = $spec['use'];
        }

        return $out;
    }

    /**
     * Find the registered tool whose capability keywords (or its own slug) match the
     * normalized capability token.
     *
     * @return array{0:string,1:array{use:string,capabilities:list<string>,modes?:list<string>}}|null
     */
    private function matchTool(string $normalizedCapability): ?array
    {
        foreach (self::REGISTERED_TOOLS as $slug => $spec) {
            if ($this->normalizeToken($slug) === $normalizedCapability) {
                return [$slug, $spec];
            }
            if (in_array($normalizedCapability, $spec['capabilities'], true)) {
                return [$slug, $spec];
            }
        }

        return null;
    }

    /**
     * @param list<string>|null $modes
     * @return array<string,mixed>
     */
    private function receipt(
        string $verdict,
        ?string $tool,
        ?string $use,
        ?string $proposalReasonCode,
        string $reason,
        ?array $modes,
        ?string $requestedMode,
    ): array {
        return [
            'schema' => self::SCHEMA,
            'verdict' => $verdict,
            'must_reuse' => $verdict === self::VERDICT_REUSE,
            'reuse_tool' => $verdict === self::VERDICT_REUSE ? $tool : null,
            'matched_tool' => $tool,
            'tool_use' => $use,
            'supported_modes' => $modes,
            'requested_mode' => $requestedMode,
            'proposal_reason' => $proposalReasonCode,
            'reason' => $reason,
        ];
    }

    /**
     * Normalize a token for matching: lowercase and collapse '-', '_' and spaces so
     * "osv-scanner", "osv_scanner", "Secret Scan" compare equal to canonical form.
     */
    private function normalizeToken(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return str_replace(['-', '_', ' '], '', strtolower(trim($value)));
    }

    private function str(mixed $v): ?string
    {
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }

        return null;
    }
}
