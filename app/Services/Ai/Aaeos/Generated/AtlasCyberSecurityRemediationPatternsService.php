<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas AI Cyber Remediation Patterns — pure, deterministic remediation router.
 *
 * Encodes the canonical "fix per vulnerability class" catalog the doc defines:
 * one pattern per primary CWE (sub-CWEs become variants), each carrying its
 * canonical fix prose, its anti-fixes, and its Negative-PoC variant taxonomy.
 * A cyber-* / developer skill consults this router in the remediation phase to
 * resolve, for a finding (given by its CWE or pattern id): which canonical
 * pattern owns the fix, what the canonical remediation is, which anti-fixes to
 * reject, and which exploit variants the Negative PoC MUST cover. The router
 * NEVER applies a fix; it only classifies and gates the Patch.
 *
 * Contract (from the frontmatter decisions, the per-pattern sections and the
 * cross-pattern anti-patterns):
 *   resolve(finding)          -> the owning pattern + canonical fix + anti-fixes
 *                                + required variant taxonomy.
 *   gatePatch(patch)          -> enforces the Patch kernel gate: Negative PoC +
 *                                Regression test obligatory, full variant coverage,
 *                                100% coverage on @security-critical paths, and
 *                                no documented anti-fix slipping through.
 *   missingVariants(...)      -> the taxonomy variants absent from a Patch's
 *                                Negative PoC (each variant MUST appear).
 *   standaloneScriptName(...) -> the canonical regression-script filename and its
 *                                binary exit-code contract.
 *
 * Documented invariants this code enforces:
 *   - Decision "Um pattern por CWE primario; sub-CWEs viram variantes": every
 *     listed CWE token resolves to exactly one owning pattern.
 *   - Decision "Negative PoC + Regression test sao obrigatorios em cada Patch
 *     (gate kernel)": a Patch missing either is BLOCKED.
 *   - "## Anti-padroes cross-pattern": a Patch with no Negative PoC is blocked;
 *     an applied anti-fix is blocked; coverage on a touched @security-critical
 *     path < 100% is blocked; a snippet adapted=false flags a copy-paste risk.
 *   - "## Variants taxonomy": every variant listed for the pattern DEVE appear in
 *     the Negative PoC — missingVariants() surfaces the gap and gatePatch() blocks
 *     while any remain.
 *   - Negative PoC standalone: the script is "check_F-<finding_id>.<ext>" with the
 *     documented exit contract (0 = fix valid, 1 = regression).
 *
 * @see docs/engineering-knowledge-base/cyber-security/remediation-patterns.md
 */
final class AtlasCyberSecurityRemediationPatternsService
{
    /** Stable schema id for a pattern resolution. */
    public const RESULT_KIND = 'cyber.remediation_resolution';

    /** Stable schema id for a Patch gate decision. */
    public const GATE_KIND = 'cyber.remediation_patch_gate';

    /** Patch-gate verdicts. */
    public const VERDICT_PASS = 'pass';
    public const VERDICT_BLOCKED = 'blocked';

    /**
     * Canonical pattern catalog: pattern_id => {
     *   cwes        : the primary + sub CWEs (and LLM ref) this pattern owns,
     *   title       : human label,
     *   fix         : the canonical-fix one-liner (prosa headline from the doc),
     *   anti_fixes  : documented anti-fixes to reject,
     *   variants    : the Negative-PoC variant taxonomy (every one MUST be covered).
     * }
     * One entry per "## cyber-rem-*" section. Sub-CWEs are folded into the owning
     * pattern's `cwes` (decision: "sub-CWEs viram variantes dentro do pattern").
     *
     * @var array<string, array{
     *     cwes:list<string>,
     *     title:string,
     *     fix:string,
     *     anti_fixes:list<string>,
     *     variants:list<string>
     * }>
     */
    private const PATTERNS = [
        'cyber-rem-idor' => [
            'cwes' => ['CWE-639', 'CWE-862', 'CWE-863'],
            'title' => 'IDOR / BOLA',
            'fix' => 'Validate resource ownership against the authenticated user before responding; enforce in middleware/guard, never the frontend.',
            'anti_fixes' => [
                'filter the response client-side (server still sends it)',
                'hide the id behind a predictable hash',
                'trust the Referer header (spoofable)',
                'switch GET to POST',
                'accept X-User-Id header as trusted',
            ],
            'variants' => [
                'direct_id_swap',
                'encoded_id',
                'uuid_sequential_guess',
                'sub_resource',
                'graphql_field_level',
                'bulk_endpoint',
            ],
        ],
        'cyber-rem-sqli' => [
            'cwes' => ['CWE-89'],
            'title' => 'SQL Injection',
            'fix' => 'Use parameterized queries (prepared statements); never concatenate input into SQL.',
            'anti_fixes' => [
                'manually escape quotes',
                'character whitelist',
                'WAF keyword blocking',
                'stored procedure that still concatenates',
            ],
            'variants' => [
                'union',
                'boolean_blind',
                'time_based_blind',
                'error_based',
                'second_order',
                'stacked_queries',
            ],
        ],
        'cyber-rem-xss' => [
            'cwes' => ['CWE-79'],
            'title' => 'XSS',
            'fix' => 'Contextual output encoding (HTML, attribute, JS, URL, CSS); never treat input as markup; defense-in-depth with restrictive CSP.',
            'anti_fixes' => [
                'strip <script> with regex',
                'character whitelist',
                'WAF keyword blocking',
                'hide in a sandbox iframe',
            ],
            'variants' => [
                'reflected',
                'stored',
                'dom',
                'mutation_xss',
                'svg_mathml_payload',
                'polyglot',
            ],
        ],
        'cyber-rem-ssrf' => [
            'cwes' => ['CWE-918'],
            'title' => 'SSRF',
            'fix' => 'Allowlist permitted URLs; block internal IPs, metadata endpoints and non-HTTP(S) schemes; resolve DNS before the request and validate the result.',
            'anti_fixes' => [
                'block only the string "localhost" (bypassed by 127.1 / [::1])',
                'validate URL before redirect (TOCTOU)',
                'block only private IPs (DNS rebind passes)',
            ],
            'variants' => [
                'cloud_metadata',
                'internal_services',
                'redirect_chain',
                'dns_rebind',
                'scheme_smuggling',
            ],
        ],
        'cyber-rem-path-traversal' => [
            'cwes' => ['CWE-22'],
            'title' => 'Path Traversal / LFI',
            'fix' => 'Never concatenate input into a path; resolve the absolute path and validate the expected prefix using canonical APIs (path.resolve, os.path.normpath).',
            'anti_fixes' => [
                'regex stripping of ../',
                'string blacklist',
            ],
            'variants' => [
                'dotdot_slash',
                'encoded',
                'double_encoded',
                'unicode',
            ],
        ],
        'cyber-rem-auth' => [
            'cwes' => ['CWE-287'],
            'title' => 'Authentication Bypass',
            'fix' => 'Mandatory authn middleware on all protected routes (public-route allowlist); rate-limit auth endpoints; explicit JWT alg whitelist; short-lived tokens with refresh rotation; Argon2id/bcrypt cost >= 12.',
            'anti_fixes' => [
                'rely on the frontend only',
                'JWT without alg validation',
                'JWT secret shared between apps',
            ],
            'variants' => [
                'expired_token_accepted',
                'jwt_alg_none',
                'header_injection',
                'mfa_race_condition',
                'predictable_reset_token',
            ],
        ],
        'cyber-rem-authz' => [
            'cwes' => ['CWE-862', 'CWE-863'],
            'title' => 'Missing/Incorrect Authorization',
            'fix' => 'Explicit authz check on EVERY endpoint; documented role x action matrix; default-deny; test per role.',
            'anti_fixes' => [
                'trust obscurity of the admin URL',
                'role check on the frontend only',
                'X-Role header (spoofable)',
            ],
            'variants' => [
                'vertical_privesc',
                'horizontal_cross_tenant',
                'http_method_tampering',
                'forced_browsing',
            ],
        ],
        'cyber-rem-csrf' => [
            'cwes' => ['CWE-352'],
            'title' => 'CSRF',
            'fix' => 'Synchronizer CSRF token per session on state-changing endpoints; SameSite=Lax cookie; double-submit cookie or custom header on XHR/fetch.',
            'anti_fixes' => [
                'Referer validation only (spoofable in some cases)',
                'predictable token',
            ],
            'variants' => [
                'form_without_token',
                'token_reusable_cross_session',
                'get_state_changing_endpoint',
            ],
        ],
        'cyber-rem-crypto-weak' => [
            'cwes' => ['CWE-327'],
            'title' => 'Weak Crypto',
            'fix' => 'Algorithm whitelist (AES-GCM/ChaCha20-Poly1305, SHA-256+, HMAC-SHA-256+, Ed25519/X25519/ECDSA P-256+, RSA-3072+, Argon2id/bcrypt cost >= 12, PBKDF2-SHA256 iter >= 600k); use tested libraries.',
            'anti_fixes' => [
                'implement custom AES "because it is faster"',
                'bump version only without changing config',
            ],
            'variants' => [
                'md5_in_integrity',
                'sha1_in_sign',
                'des_3des',
                'rc4',
                'ecb_mode',
                'iv_reuse_in_gcm',
            ],
        ],
        'cyber-rem-crypto-key' => [
            'cwes' => ['CWE-321', 'CWE-916'],
            'title' => 'Weak Key Management',
            'fix' => 'Keys via KMS/HSM/Vault, never hardcoded; explicit rotation policy; per-tenant scoping; KDF (Argon2id/PBKDF2) with a random salt.',
            'anti_fixes' => [
                'env var committed to git',
                'key never rotated',
                'key shared between tenants',
            ],
            'variants' => [],
        ],
        'cyber-rem-secret-leak' => [
            'cwes' => ['CWE-798'],
            'title' => 'Hardcoded Secret',
            'fix' => 'Vault (Atlas vault, AWS Secrets Manager, HashiCorp); secret scan in pre-commit (gitleaks); rotate a leaked secret immediately.',
            'anti_fixes' => [
                'committed env file',
                'comment the secret in code',
                '"we will rotate later"',
            ],
            'variants' => [],
        ],
        'cyber-rem-deserialization' => [
            'cwes' => ['CWE-502'],
            'title' => 'Unsafe Deserialization',
            'fix' => 'Never deserialize untrusted input with Pickle/Java native/PHP unserialize; use JSON/Protobuf with schema validation; sign the payload (HMAC) if needed.',
            'anti_fixes' => [
                'try to sanitize before unserialize',
            ],
            'variants' => [],
        ],
        'cyber-rem-open-redirect' => [
            'cwes' => ['CWE-601'],
            'title' => 'Open Redirect',
            'fix' => 'Allowlist permitted destinations; internal redirect via relative path only; validate scheme + host.',
            'anti_fixes' => [],
            'variants' => [
                'absolute_attacker_url',
                'protocol_relative',
                'backslash_bypass',
                'encoded',
                'javascript_scheme',
            ],
        ],
        'cyber-rem-mass-assignment' => [
            'cwes' => ['CWE-915'],
            'title' => 'Mass Assignment',
            'fix' => 'Explicit allowlist of accepted fields on update endpoints; ORM fillable/safe_attributes; DTO with schema.',
            'anti_fixes' => [
                'blacklist of sensitive fields (bypassed by case or similar name)',
            ],
            'variants' => [
                'is_admin_true',
                'email_verified_true',
                'arbitrary_account_balance',
                'role_admin',
            ],
        ],
        'cyber-rem-prompt-injection' => [
            'cwes' => ['LLM01'],
            'title' => 'Prompt Injection',
            'fix' => 'Tokenized delimiters between system prompt and user input; output sanitization (LLM output never reaches eval/SQL/HTML unescaped); detection layer; least-privilege agent tools; human-in-the-loop on irreversible actions.',
            'anti_fixes' => [
                'ask the LLM to "ignore previous instructions" as a defense',
                'ad-hoc prompt patching',
            ],
            'variants' => [
                'direct_injection',
                'indirect_injection',
                'multi_turn_degradation',
                'cross_language',
                'encoded',
                'excessive_agency',
            ],
        ],
        'cyber-rem-rce' => [
            'cwes' => ['CWE-78', 'CWE-94'],
            'title' => 'RCE / Command Injection',
            'fix' => 'Never pass untrusted input to exec/spawn/eval; use argv-array APIs (no shell); validate input against a strict schema.',
            'anti_fixes' => [],
            'variants' => [
                'semicolon_chain',
                'pipe_chain',
                'backtick',
                'dollar_paren',
                'newline_injection',
                'template_eval',
            ],
        ],
        'cyber-rem-hardening-headers' => [
            'cwes' => ['CWE-693'],
            'title' => 'Missing Security Headers',
            'fix' => 'Middleware injecting HSTS, CSP, X-Content-Type-Options, X-Frame-Options/CSP frame-ancestors, Referrer-Policy, Permissions-Policy; Cache-Control no-store on PII endpoints.',
            'anti_fixes' => [],
            'variants' => [],
        ],
        'cyber-rem-user-enum' => [
            'cwes' => ['CWE-204'],
            'title' => 'User Enumeration',
            'fix' => 'Generic error messages on login/forgot/register; constant-time response timing; rate-limit per-IP + per-account.',
            'anti_fixes' => [],
            'variants' => [
                'timing_diff',
                'distinct_message_existing_vs_missing',
            ],
        ],
    ];

    /**
     * Resolve a finding to its owning remediation pattern.
     *
     * @param array<string,mixed> $finding
     *        cwe        : string  primary CWE token (e.g. "CWE-89") or LLM ref ("LLM01").
     *        pattern_id : string  explicit "cyber-rem-*" id (takes precedence over cwe).
     *
     * @return array<string,mixed>
     */
    public function resolve(array $finding): array
    {
        $patternId = $this->normalizePatternId($finding['pattern_id'] ?? null);
        $cwe = $this->normalizeCwe($finding['cwe'] ?? null);

        $resolvedId = null;

        if ($patternId !== null && isset(self::PATTERNS[$patternId])) {
            $resolvedId = $patternId;
        } elseif ($cwe !== null) {
            $resolvedId = $this->patternForCwe($cwe);
        }

        if ($resolvedId === null) {
            // No canonical pattern owns this finding. The doc forbids shipping a
            // Patch without an owning pattern (a pattern carries the mandatory
            // variant taxonomy), so an unmapped finding is NOT remediable yet.
            return [
                'result_kind' => self::RESULT_KIND,
                'matched' => false,
                'pattern_id' => null,
                'cwe' => $cwe,
                'title' => null,
                'canonical_fix' => null,
                'anti_fixes' => [],
                'required_variants' => [],
                'remediable' => false,
                'rationale' => 'No canonical remediation pattern owns this CWE/id; add a pattern before shipping a Patch.',
            ];
        }

        $p = self::PATTERNS[$resolvedId];

        return [
            'result_kind' => self::RESULT_KIND,
            'matched' => true,
            'pattern_id' => $resolvedId,
            'cwe' => $cwe,
            'covers_cwes' => $p['cwes'],
            'title' => $p['title'],
            'canonical_fix' => $p['fix'],
            'anti_fixes' => $p['anti_fixes'],
            'required_variants' => $p['variants'],
            'remediable' => true,
            'rationale' => sprintf('Pattern "%s" owns the canonical fix for this finding.', $resolvedId),
        ];
    }

    /**
     * The taxonomy variants that a Patch's Negative PoC has NOT yet covered.
     * Every variant listed for the owning pattern MUST appear in the Negative PoC
     * (doc: "Cada variant listada DEVE aparecer em Negative PoC do Patch").
     *
     * @param string        $patternId
     * @param list<string>  $coveredVariants variant ids the Negative PoC exercises.
     *
     * @return list<string> required variants still missing (subset of the taxonomy).
     */
    public function missingVariants(string $patternId, array $coveredVariants): array
    {
        $patternId = $this->normalizePatternId($patternId) ?? '';
        if (! isset(self::PATTERNS[$patternId])) {
            return [];
        }

        $required = self::PATTERNS[$patternId]['variants'];
        $covered = $this->normalizeList($coveredVariants);

        return array_values(array_filter(
            $required,
            static fn (string $v): bool => ! in_array($v, $covered, true),
        ));
    }

    /**
     * Gate a Patch against the documented kernel + cross-pattern anti-patterns.
     *
     * Blocking rules (each maps to a documented line):
     *   - no owning pattern                -> "no_canonical_pattern".
     *   - Negative PoC absent              -> "missing_negative_poc"        (kernel gate / anti-pattern).
     *   - Regression test absent           -> "missing_regression_test"     (kernel gate).
     *   - taxonomy variants uncovered      -> "uncovered_variants"          ("cada variant DEVE aparecer").
     *   - touched @security-critical path  -> "security_critical_coverage_below_100" when coverage < 100.
     *   - a documented anti-fix applied    -> "anti_fix_applied".
     *   - snippet not adapted to stack     -> "snippet_not_adapted_to_stack".
     *
     * @param array<string,mixed> $patch
     *        pattern_id              : string        owning pattern.
     *        has_negative_poc        : bool          Negative PoC present.
     *        has_regression_test     : bool          perpetual regression test present.
     *        covered_variants        : list<string>  variants the Negative PoC exercises.
     *        touches_security_critical : bool        the Patch touches @security-critical code.
     *        security_critical_coverage_pct : int|float  coverage on the touched path (0..100).
     *        applied_anti_fixes      : list<string>  any documented anti-fix the Patch used.
     *        snippet_adapted         : bool          snippet adapted to the client stack (default true).
     *
     * @return array<string,mixed> { gate_kind, verdict, blocked, blockers[], pattern_id }
     */
    public function gatePatch(array $patch): array
    {
        $patternId = $this->normalizePatternId($patch['pattern_id'] ?? null);
        $blockers = [];

        if ($patternId === null || ! isset(self::PATTERNS[$patternId])) {
            return [
                'gate_kind' => self::GATE_KIND,
                'verdict' => self::VERDICT_BLOCKED,
                'blocked' => true,
                'pattern_id' => $patternId,
                'blockers' => [$this->blocker(
                    'no_canonical_pattern',
                    'Patch has no owning canonical remediation pattern.'
                )],
            ];
        }

        // Kernel gate: Negative PoC + Regression test obligatory in every Patch.
        if (($patch['has_negative_poc'] ?? false) !== true) {
            $blockers[] = $this->blocker(
                'missing_negative_poc',
                'Negative PoC is mandatory in every Patch (kernel gate); a Patch without one is blocked.'
            );
        }

        if (($patch['has_regression_test'] ?? false) !== true) {
            $blockers[] = $this->blocker(
                'missing_regression_test',
                'Perpetual regression test is mandatory in every Patch (kernel gate).'
            );
        }

        // Every taxonomy variant must appear in the Negative PoC.
        $missing = $this->missingVariants($patternId, $this->normalizeList($patch['covered_variants'] ?? []));
        if ($missing !== []) {
            $blockers[] = $this->blocker(
                'uncovered_variants',
                'Negative PoC must cover every variant in the pattern taxonomy; missing: '.implode(', ', $missing),
                $missing,
            );
        }

        // Coverage on a touched @security-critical path must be 100%.
        if (($patch['touches_security_critical'] ?? false) === true) {
            $coverage = $this->coveragePct($patch['security_critical_coverage_pct'] ?? null);
            if ($coverage < 100.0) {
                $blockers[] = $this->blocker(
                    'security_critical_coverage_below_100',
                    sprintf('Coverage on a touched @security-critical path is %.1f%%; the doc requires 100%%.', $coverage),
                );
            }
        }

        // No documented anti-fix may be applied.
        $appliedAntiFixes = $this->normalizeList($patch['applied_anti_fixes'] ?? []);
        if ($appliedAntiFixes !== []) {
            $blockers[] = $this->blocker(
                'anti_fix_applied',
                'Patch applies a documented anti-fix: '.implode(' | ', $appliedAntiFixes),
                $appliedAntiFixes,
            );
        }

        // A snippet copied without adapting to the client stack introduces a new bug.
        if (array_key_exists('snippet_adapted', $patch) && $patch['snippet_adapted'] !== true) {
            $blockers[] = $this->blocker(
                'snippet_not_adapted_to_stack',
                'Snippet was copied without adapting to the client stack (introduces a new bug).'
            );
        }

        return [
            'gate_kind' => self::GATE_KIND,
            'verdict' => $blockers === [] ? self::VERDICT_PASS : self::VERDICT_BLOCKED,
            'blocked' => $blockers !== [],
            'pattern_id' => $patternId,
            'blockers' => $blockers,
        ];
    }

    /**
     * Canonical standalone regression-script descriptor for a finding.
     * Doc: "check_F-<finding_id>.py | .sh | .ts | .go" with a binary exit contract
     * (0 = fix remains valid, 1 = regression — a variant passes again).
     *
     * @param string $findingId raw finding id (with or without an "F-" prefix).
     * @param string $stack     target stack hint (python|node|ts|go|shell|...).
     *
     * @return array<string,mixed>
     */
    public function standaloneScriptName(string $findingId, string $stack = 'python'): array
    {
        $id = $this->normalizeFindingId($findingId);
        $ext = $this->extensionForStack($stack);

        return [
            'filename' => sprintf('check_F-%s.%s', $id, $ext),
            'finding_id' => 'F-'.$id,
            'self_contained' => true,
            'configurable' => true,
            'deterministic' => true,
            'exit_codes' => [
                'fix_valid' => 0,       // all variants blocked.
                'regression' => 1,      // a variant passes again.
            ],
            'ci_friendly' => true,
        ];
    }

    /** @return list<string> every catalogued pattern id. */
    public function patternIds(): array
    {
        return array_keys(self::PATTERNS);
    }

    /**
     * @return array<string,string> CWE/LLM token => owning pattern id (sub-CWEs
     *         resolve to the same pattern as the primary).
     */
    public function cweIndex(): array
    {
        $index = [];
        foreach (self::PATTERNS as $id => $p) {
            foreach ($p['cwes'] as $cwe) {
                // First owner wins so a primary CWE keeps ownership over a shared one.
                $index[$cwe] ??= $id;
            }
        }

        return $index;
    }

    private function patternForCwe(string $cwe): ?string
    {
        foreach (self::PATTERNS as $id => $p) {
            if (in_array($cwe, $p['cwes'], true)) {
                return $id;
            }
        }

        return null;
    }

    /** @return array{id:string,reason:string,detail?:list<string>} */
    private function blocker(string $id, string $reason, array $detail = []): array
    {
        $out = ['id' => $id, 'reason' => $reason];
        if ($detail !== []) {
            $out['detail'] = array_values($detail);
        }

        return $out;
    }

    private function normalizePatternId(mixed $v): ?string
    {
        if (! is_string($v) || trim($v) === '') {
            return null;
        }

        return strtolower(trim($v));
    }

    private function normalizeCwe(mixed $v): ?string
    {
        if (! is_string($v) || trim($v) === '') {
            return null;
        }

        $raw = strtoupper(trim($v));

        // LLM refs (e.g. "LLM01") pass through unchanged.
        if (str_starts_with($raw, 'LLM')) {
            return $raw;
        }

        // Accept "639", "cwe-639", "CWE_639" → "CWE-639".
        if (preg_match('/(\d+)/', $raw, $m) === 1) {
            return 'CWE-'.$m[1];
        }

        return $raw;
    }

    private function coveragePct(mixed $v): float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (is_string($v) && is_numeric(trim($v))) {
            return (float) trim($v);
        }

        // Unknown coverage on a security-critical path is conservatively 0%.
        return 0.0;
    }

    private function normalizeFindingId(string $findingId): string
    {
        $id = trim($findingId);
        if (str_starts_with(strtoupper($id), 'F-')) {
            $id = substr($id, 2);
        }

        $id = preg_replace('/[^A-Za-z0-9_-]/', '', $id) ?? '';

        return $id === '' ? 'unknown' : $id;
    }

    private function extensionForStack(string $stack): string
    {
        return match (strtolower(trim($stack))) {
            'node', 'js', 'javascript' => 'js',
            'ts', 'typescript' => 'ts',
            'go', 'golang' => 'go',
            'sh', 'shell', 'bash' => 'sh',
            default => 'py',
        };
    }

    /**
     * @param mixed $list
     * @return list<string>
     */
    private function normalizeList(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }

        $clean = [];
        foreach ($list as $item) {
            if (is_string($item) && trim($item) !== '') {
                $clean[] = strtolower(trim($item));
            }
        }

        return array_values(array_unique($clean));
    }
}
