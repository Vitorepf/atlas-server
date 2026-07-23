<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Self-Construction Scope Validator — pure, deterministic file-scope classifier.
 *
 * Proves that an AI changed ONLY what its packet allowed. It runs AFTER
 * implementation and BEFORE any completion claim: it classifies every changed
 * and untracked path against a packet write contract, emits blocking
 * violations and produces machine-readable evidence for the handoff.
 *
 * Contract (from the doc Input/Output Schema + Classification Rules):
 *   Entrada: packet (allowed/forbidden/forbidden_actions/hot_scopes),
 *            changed_files, untracked_files, required_gates.
 *   Saida:   status (pass|fail|blocked), per-file classification, summary
 *            counts, blocking_violations, required_next_action.
 *
 * Documented invariants this code enforces:
 *   - "Forbidden files always override allowed files." (decision)
 *       => forbidden precedence beats an allowed match for the same path.
 *   - "Unknown writes are unsafe until classified." (decision)
 *       => a path that is neither allowed nor forbidden is `unknown` + blocking.
 *   - "Scope validation is blocking evidence for every packet." (decision)
 *       => completion may proceed only when status=pass.
 *   - Classification Rules table: allowed(no) | forbidden(yes) | unknown(yes) |
 *     hot_external(yes) | generated(yes unless packet allows) | evidence_only(no).
 *   - Blocking Files And Actions: migrations, route files, service providers,
 *     daemon entrypoints, provider/model policy, auth/payment/permission/tenant
 *     isolation, Voice realtime runtime, Kernel scanner, memory write policy,
 *     external tool / MCP write policy — forbidden unless explicitly allowed.
 *   - Validation Protocol step 5/6: fail on forbidden/unknown/hot_external and
 *     when generated files are outside packet scope.
 *
 * Non-goals honoured (the service is read-only):
 *   - It does NOT decide code correctness, does NOT approve execution, does NOT
 *     mutate or repair files, does NOT hide hot external changes and does NOT
 *     ignore untracked files. It only classifies and emits evidence.
 *
 * @see docs/engineering-knowledge-base/self-construction/scope-validator-contract.md
 */
final class AtlasScopeValidatorContractService
{
    /** Stable evidence schema id this validator emits. */
    public const SCHEMA = 'atlas.self_construction.scope_validator.v1';

    /** Output statuses (closed set, from Output Schema). */
    public const STATUS_PASS = 'pass';
    public const STATUS_FAIL = 'fail';
    public const STATUS_BLOCKED = 'blocked';

    /** Per-file classifications (closed set, from Classification Rules). */
    public const CLASS_ALLOWED = 'allowed';
    public const CLASS_FORBIDDEN = 'forbidden';
    public const CLASS_UNKNOWN = 'unknown';
    public const CLASS_HOT_EXTERNAL = 'hot_external';
    public const CLASS_GENERATED = 'generated';
    public const CLASS_EVIDENCE_ONLY = 'evidence_only';

    /** required_next_action values (closed set, from Output Schema). */
    public const NEXT_CONTINUE = 'continue';
    public const NEXT_STOP = 'stop';
    public const NEXT_REQUEST_REVIEW = 'request_review';
    public const NEXT_REFRESH_PACKET = 'refresh_packet';

    /** Packet scope source labels (from Input Schema). */
    public const SOURCE_IMPLEMENTATION = 'implementation_packet';
    public const SOURCE_WORK_SPLITTER = 'work_splitter_packet';

    /**
     * "Blocking Files And Actions" — substrings that mark a path as blocking
     * (classified `forbidden`) unless the packet explicitly allows it. Each
     * entry maps to the documented reason category it belongs to.
     *
     * @var array<string,string>
     */
    private const BLOCKING_NEEDLES = [
        '/migrations/' => 'migration',
        'routes/' => 'route_file',
        'serviceprovider' => 'service_provider',
        'app/providers/' => 'service_provider',
        'daemon' => 'daemon_entrypoint',
        'providermodelpolicy' => 'provider_model_policy',
        'provider_model_policy' => 'provider_model_policy',
        'auth/' => 'auth_code',
        'payment' => 'payment_code',
        'permission' => 'permission_code',
        'tenant' => 'tenant_isolation_code',
        'voice_realtime' => 'voice_realtime_runtime',
        'voice/realtime' => 'voice_realtime_runtime',
        'kernelscanner' => 'kernel_scanner',
        'kernel_scanner' => 'kernel_scanner',
        'memorywritepolicy' => 'memory_write_policy',
        'memory_write_policy' => 'memory_write_policy',
        'mcp_write_policy' => 'mcp_write_policy',
        'tool_write_policy' => 'external_tool_write_policy',
    ];

    /**
     * Default evidence-path needles: allowed logs/reports/receipts that carry
     * packet evidence and are non-blocking (`evidence_only`). Used only when the
     * packet does not list them explicitly as allowed.
     *
     * @var list<string>
     */
    private const EVIDENCE_NEEDLES = ['/reports/', '/logs/', '/evidence/', '/receipts/', '.report.json', '.evidence.json'];

    /**
     * Validate one packet's realized changes.
     *
     * @param array<string,mixed> $packet
     *        packet_id             : string
     *        requested_packet_id   : string (optional; the id the caller asked for)
     *        packet_scope_source   : string implementation_packet|work_splitter_packet
     *        allowed_files         : list<string>
     *        forbidden_files       : list<string>
     *        forbidden_actions     : list<string>
     *        hot_scopes            : list<string>  paths owned by another active front
     *        changed_files         : list<string>  from `git diff --name-only`
     *        untracked_files       : list<string>  from `git status --short` (??)
     *        generated_globs       : list<string>  paths produced by index/sync cmds
     *        required_gates        : list<string>
     *
     * @return array<string,mixed> the Output Schema evidence document
     */
    public function validate(array $packet): array
    {
        $packetId = $this->str($packet['packet_id'] ?? null) ?? 'AIP-UNKNOWN';
        $requestedPacketId = $this->str($packet['requested_packet_id'] ?? null) ?? $packetId;
        $scopeSource = $this->normalizeScopeSource($packet['packet_scope_source'] ?? null);

        $allowed = $this->normalizePaths($packet['allowed_files'] ?? []);
        $forbidden = $this->normalizePaths($packet['forbidden_files'] ?? []);
        $hotScopes = $this->normalizePaths($packet['hot_scopes'] ?? []);
        $generatedGlobs = $this->normalizePaths($packet['generated_globs'] ?? []);

        $changed = $this->normalizePaths($packet['changed_files'] ?? []);
        $untracked = $this->normalizePaths($packet['untracked_files'] ?? []);

        // Untracked files are NEVER ignored (Non-Goal: "Do not ignore untracked
        // files."). They are merged into the classification set, de-duplicated.
        $paths = array_values(array_unique(array_merge($changed, $untracked)));

        $files = [];
        $summary = [
            'allowed_count' => 0,
            'forbidden_count' => 0,
            'unknown_count' => 0,
            'hot_external_count' => 0,
            'generated_count' => 0,
            'evidence_only_count' => 0,
        ];
        $blockingViolations = [];

        foreach ($paths as $path) {
            $row = $this->classify($path, $allowed, $forbidden, $hotScopes, $generatedGlobs);
            $files[] = $row;

            $summary[$row['classification'] . '_count'] =
                ($summary[$row['classification'] . '_count'] ?? 0) + 1;

            if ($row['blocking'] === true) {
                $blockingViolations[] = [
                    'path' => $row['path'],
                    'classification' => $row['classification'],
                    'reason' => $row['reason'],
                ];
            }
        }

        $status = $this->resolveStatus($summary, $blockingViolations);
        $nextAction = $this->resolveNextAction($status, $summary);

        return [
            'schema' => self::SCHEMA,
            'status' => $status,
            'packet_id' => $packetId,
            'requested_packet_id' => $requestedPacketId,
            'packet_scope_source' => $scopeSource,
            'summary' => $summary,
            'files' => $files,
            'blocking_violations' => $blockingViolations,
            'required_next_action' => $nextAction,
            'evidence_ready' => true,
            'completion_allowed' => $status === self::STATUS_PASS,
        ];
    }

    /**
     * Classify a single path. Precedence is load-bearing and mirrors the doc:
     *   1. hot_external  — belongs to another active front (checked before
     *      forbidden so cross-front leakage is reported as a hard stop).
     *   2. forbidden     — explicit forbidden match OR a blocking-needle match
     *      that the packet did not explicitly allow. "Forbidden files always
     *      override allowed files."
     *   3. generated     — produced by a required index/sync command.
     *   4. allowed       — explicitly allowed by the packet.
     *   5. evidence_only — allowed logs/reports/receipts for packet evidence.
     *   6. unknown       — neither allowed nor forbidden (unsafe until classified).
     *
     * @param list<string> $allowed
     * @param list<string> $forbidden
     * @param list<string> $hotScopes
     * @param list<string> $generatedGlobs
     * @return array{path:string,classification:string,blocking:bool,reason:string}
     */
    public function classify(
        string $path,
        array $allowed,
        array $forbidden,
        array $hotScopes,
        array $generatedGlobs,
    ): array {
        $needle = strtolower($path);
        $explicitlyAllowed = $this->matchesAny($path, $allowed);

        // 1. hot_external — another active front owns it (blocking for THIS packet).
        if ($this->matchesAny($path, $hotScopes)) {
            return $this->row($path, self::CLASS_HOT_EXTERNAL, true,
                'Path belongs to another active front and is outside this packet ownership.');
        }

        // 2a. explicit forbidden_files always wins over allowed.
        if ($this->matchesAny($path, $forbidden)) {
            return $this->row($path, self::CLASS_FORBIDDEN, true,
                'Path matches packet forbidden scope (forbidden overrides allowed).');
        }

        // 2b. blocking-needle files (migrations/routes/providers/auth/payment/
        // tenant/voice/kernel/memory/mcp) are forbidden UNLESS explicitly allowed.
        foreach (self::BLOCKING_NEEDLES as $blockNeedle => $category) {
            if (str_contains($needle, $blockNeedle) && ! $explicitlyAllowed) {
                return $this->row($path, self::CLASS_FORBIDDEN, true,
                    "Blocking file category '{$category}' requires explicit allow via critical AP + signed receipt.");
            }
        }

        // 3. generated — produced by a required index/sync command. Blocking
        // unless the packet explicitly allows the path (Classification Rules).
        if ($this->matchesAny($path, $generatedGlobs)) {
            if ($explicitlyAllowed) {
                return $this->row($path, self::CLASS_GENERATED, false,
                    'Generated path is explicitly allowed by packet.');
            }

            return $this->row($path, self::CLASS_GENERATED, true,
                'Generated path is outside packet scope.');
        }

        // 4. explicitly allowed.
        if ($explicitlyAllowed) {
            return $this->row($path, self::CLASS_ALLOWED, false,
                'Path is explicitly allowed by packet.');
        }

        // 5. evidence-only logs/reports/receipts carrying packet evidence.
        if ($this->matchesAnyNeedle($needle, self::EVIDENCE_NEEDLES)) {
            return $this->row($path, self::CLASS_EVIDENCE_ONLY, false,
                'Path contains allowed logs/reports for packet evidence.');
        }

        // 6. unknown — neither allowed nor forbidden; unsafe until classified.
        return $this->row($path, self::CLASS_UNKNOWN, true,
            'Path is neither allowed nor forbidden and is unsafe until classified.');
    }

    /**
     * Convenience predicate for the handoff gate: may completion proceed?
     * (Completion Criteria / "Completion may proceed only when status=pass.")
     */
    public function mayCompletionProceed(array $packet): bool
    {
        return $this->validate($packet)['status'] === self::STATUS_PASS;
    }

    /**
     * Status resolution (Validation Protocol step 5/6 + Output Schema):
     *   - blocked : any hot_external write (cross-front leakage is a hard stop).
     *   - fail    : any other blocking violation (forbidden / unknown / generated).
     *   - pass    : no blocking violations.
     *
     * @param array<string,int> $summary
     * @param list<array<string,string>> $blockingViolations
     */
    private function resolveStatus(array $summary, array $blockingViolations): string
    {
        if (($summary['hot_external_count'] ?? 0) > 0) {
            return self::STATUS_BLOCKED;
        }

        if ($blockingViolations !== []) {
            return self::STATUS_FAIL;
        }

        return self::STATUS_PASS;
    }

    /**
     * required_next_action resolution (Output Schema + Blocked Example):
     *   - stop           : status=blocked (hot_external).
     *   - request_review : forbidden or generated-out-of-scope violations.
     *   - refresh_packet : only unknown violations remain (packet is stale).
     *   - continue       : status=pass.
     *
     * @param array<string,int> $summary
     */
    private function resolveNextAction(string $status, array $summary): string
    {
        if ($status === self::STATUS_PASS) {
            return self::NEXT_CONTINUE;
        }

        if ($status === self::STATUS_BLOCKED) {
            return self::NEXT_STOP;
        }

        // status === fail
        if (($summary['forbidden_count'] ?? 0) > 0 || ($summary['generated_count'] ?? 0) > 0) {
            return self::NEXT_REQUEST_REVIEW;
        }

        // Only unknown writes remain — the packet contract is stale/incomplete.
        if (($summary['unknown_count'] ?? 0) > 0) {
            return self::NEXT_REFRESH_PACKET;
        }

        return self::NEXT_REQUEST_REVIEW;
    }

    private function normalizeScopeSource(mixed $source): string
    {
        if (is_string($source)) {
            $key = strtolower(trim($source));
            if ($key === self::SOURCE_WORK_SPLITTER || $key === self::SOURCE_IMPLEMENTATION) {
                return $key;
            }
        }

        return self::SOURCE_IMPLEMENTATION;
    }

    /**
     * Does $path match any scope entry? A scope entry matches when it equals the
     * path or is a directory/prefix of it (so `routes/` matches `routes/api.php`).
     *
     * @param list<string> $scope
     */
    private function matchesAny(string $path, array $scope): bool
    {
        $needle = strtolower($path);
        foreach ($scope as $entry) {
            $rule = strtolower($entry);
            if ($rule === '') {
                continue;
            }
            if ($needle === $rule) {
                return true;
            }
            // Directory prefix: "app/Services/Foo/" matches anything beneath it.
            if (str_ends_with($rule, '/') && str_starts_with($needle, $rule)) {
                return true;
            }
            // Glob-style "dir/*" prefix.
            if (str_ends_with($rule, '*') && str_starts_with($needle, rtrim($rule, '*'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $needles
     */
    private function matchesAnyNeedle(string $loweredPath, array $needles): bool
    {
        foreach ($needles as $n) {
            if ($n !== '' && str_contains($loweredPath, $n)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{path:string,classification:string,blocking:bool,reason:string}
     */
    private function row(string $path, string $classification, bool $blocking, string $reason): array
    {
        return [
            'path' => $path,
            'classification' => $classification,
            'blocking' => $blocking,
            'reason' => $reason,
        ];
    }

    /**
     * @param mixed $paths
     * @return list<string>
     */
    private function normalizePaths(mixed $paths): array
    {
        if (! is_array($paths)) {
            return [];
        }

        $clean = [];
        foreach ($paths as $p) {
            if (is_string($p) && trim($p) !== '') {
                $clean[] = trim($p);
            }
        }

        return array_values(array_unique($clean));
    }

    private function str(mixed $v): ?string
    {
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }

        return null;
    }
}
