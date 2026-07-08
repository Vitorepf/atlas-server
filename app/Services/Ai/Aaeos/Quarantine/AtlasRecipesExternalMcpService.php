<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas AI Cyber External MCP Tooling — pure, deterministic admission decider.
 *
 * Enforces the governance contract for integrating offensive external MCP
 * servers (e.g. HexStrike) as WRAPPED recipes instead of raw `mcpServers`
 * entries exposed to Atlas Decide or providers. Given a candidate registration
 * it returns the single controlled verdict — `admit` or `reject` — plus the
 * documented obligations; given a per-call request against an admitted wrapper
 * it returns `proceed`, `refuse` or `kill_switch`.
 *
 * Contract (from the doc):
 *   - "External MCP servers with offensive capability are integrated as wrapped
 *     recipes, not as raw `mcpServers` entries." (Rule + Anti-Pattern)
 *       => integration must be `mcp_server` through a wrapper skill; a raw
 *          provider-config integration is a hard reject (bypasses governance).
 *   - General External MCP Pattern, steps 1-6:
 *       1. wrapper skill named `cyber-<tool>-runner`;
 *       2. recipe registered with `integration: mcp_server`;
 *       3. VM sandbox required (dedicated);
 *       4. ADR recorded in cyber extension docs;
 *       5. enforce receipt, scope proof, refusal matrix and evidence;
 *       6. limit simultaneous MCP connections.
 *   - Candidate HexStrike constraints (each call):
 *       apply Decision Receipt before every call; enforce `scope.in`; apply
 *       refusal matrix; write Evidence Ledger events; rate-limit MCP calls;
 *       kill-switch if the tool leaves declared scope; no reuse of VM between
 *       engagements.
 *   - HexStrike fields: execution tier T2, "Extra approval always required",
 *     dedicated VM, wrapper `cyber-hexstrike-runner`.
 *   - recipes-catalog Hard Safety Rules: external offensive MCP requires extra
 *     approval; scope proof and refusal matrix run before execution.
 *   - refusal-matrix cyber-ref-010: action against a target NOT in `scope.in`
 *     is refused with NO exception (here: out-of-scope target trips kill-switch).
 *
 * The service NEVER connects to an MCP server, calls a provider, spawns a VM,
 * queries a database or mutates state. It only decides and emits obligations;
 * callers enforce.
 *
 * @see docs/engineering-knowledge-base/cyber-security/recipes-external-mcp.md
 */
final class AtlasRecipesExternalMcpService
{
    /** Stable receipt schema id this decider emits. */
    public const SCHEMA = 'atlas.cyber.external_mcp_admission.v1';

    /** Registration verdicts (closed set). */
    public const VERDICT_ADMIT = 'admit';
    public const VERDICT_REJECT = 'reject';

    /** Per-call verdicts (closed set). */
    public const CALL_PROCEED = 'proceed';
    public const CALL_REFUSE = 'refuse';
    public const CALL_KILL_SWITCH = 'kill_switch';

    /** The only integration mode allowed for an external offensive MCP server. */
    public const INTEGRATION_MCP_SERVER = 'mcp_server';

    /** The execution tier the doc assigns to external offensive MCP tooling. */
    public const EXECUTION_TIER = 'T2';

    /** Sandbox the doc mandates: a dedicated VM, never reused between engagements. */
    public const SANDBOX_DEDICATED_VM = 'dedicated_vm';

    /**
     * The mandatory wrapped-recipe controls (General External MCP Pattern step 5
     * + HexStrike Constraints). A registration is admitted only when every one of
     * these is declared enforced.
     *
     * @var list<string>
     */
    public const REQUIRED_CONTROLS = [
        'decision_receipt',     // apply Decision Receipt before every call
        'scope_proof',          // enforce scope.in
        'refusal_matrix',       // apply refusal matrix
        'evidence_ledger',      // write Evidence Ledger events
        'rate_limit',           // rate-limit MCP calls
        'kill_switch',          // kill-switch if the tool leaves declared scope
        'dedicated_vm',         // VM sandbox required
        'no_vm_reuse',          // no reuse of VM between engagements
        'connection_limit',     // limit simultaneous MCP connections
    ];

    /**
     * Decide whether an external offensive MCP server may be admitted as a
     * wrapped recipe.
     *
     * @param array<string,mixed> $candidate
     *        tool_slug              : string  e.g. "hexstrike-mcp"
     *        recipe_name            : string  e.g. "pentest-orchestrate"
     *        integration            : string  must be "mcp_server"
     *        wrapper_skill          : string  must be "cyber-<tool>-runner"
     *        sandbox                : string  must be "dedicated_vm"
     *        adr_recorded           : bool    ADR in cyber extension docs (step 4)
     *        extra_approval         : bool    external offensive MCP => always true
     *        enforced_controls      : list<string>  controls the wrapper enforces
     *
     * @return array<string,mixed> the admission receipt
     */
    public function evaluateRegistration(array $candidate): array
    {
        $toolSlug = $this->str($candidate['tool_slug'] ?? null);
        $recipeName = $this->str($candidate['recipe_name'] ?? null);
        $integration = strtolower((string) ($this->str($candidate['integration'] ?? null) ?? ''));
        $wrapper = strtolower((string) ($this->str($candidate['wrapper_skill'] ?? null) ?? ''));
        $sandbox = strtolower((string) ($this->str($candidate['sandbox'] ?? null) ?? ''));
        $adrRecorded = $this->boolGate($candidate['adr_recorded'] ?? false);
        $extraApproval = $this->boolGate($candidate['extra_approval'] ?? false);
        $enforced = $this->normalizeList($candidate['enforced_controls'] ?? []);

        $violations = [];

        if ($toolSlug === null) {
            $violations[] = $this->violation('missing_tool_slug',
                'A wrapped external MCP recipe must declare a tool_slug.');
        } elseif (! $this->isSlug($toolSlug)) {
            $violations[] = $this->violation('tool_slug_malformed',
                'Tool slug must contain only slug characters: letters, numbers, and hyphens.');
        }

        if ($recipeName === null) {
            $violations[] = $this->violation('missing_recipe_name',
                'A wrapped external MCP recipe must declare a recipe_name.');
        }

        // Rule + Anti-Pattern: raw provider/mcpServers integration bypasses Atlas
        // governance and is the documented anti-pattern. Only `mcp_server` through
        // a wrapper skill is allowed.
        if ($integration !== self::INTEGRATION_MCP_SERVER) {
            $violations[] = $this->violation('raw_integration_bypasses_governance',
                'External offensive MCP must be integration=mcp_server through a wrapper skill; a raw mcpServers/provider-config entry bypasses Atlas governance.');
        }

        // General External MCP Pattern step 1: wrapper skill named cyber-<tool>-runner.
        $expectedWrapper = $toolSlug !== null
            ? 'cyber-' . $this->wrapperRoot($toolSlug) . '-runner'
            : null;

        if (! $this->isWrapperWellFormed($wrapper)) {
            $violations[] = $this->violation('wrapper_skill_malformed',
                'Wrapper skill must follow the cyber-<tool>-runner naming pattern.');
        } elseif ($expectedWrapper !== null && $wrapper !== $expectedWrapper) {
            $violations[] = $this->violation('wrapper_skill_mismatch',
                "Wrapper skill must be '{$expectedWrapper}' for tool '{$toolSlug}'.");
        }

        // Step 3 + HexStrike: dedicated VM sandbox required.
        if ($sandbox !== self::SANDBOX_DEDICATED_VM) {
            $violations[] = $this->violation('sandbox_not_dedicated_vm',
                'External offensive MCP requires a dedicated VM sandbox.');
        }

        // Step 4: ADR recorded in cyber extension docs.
        if ($adrRecorded !== true) {
            $violations[] = $this->violation('adr_not_recorded',
                'An ADR must be recorded in cyber extension docs before promotion.');
        }

        // recipes-catalog Hard Safety Rules + HexStrike "Extra approval always
        // required": external offensive MCP always needs extra approval.
        if ($extraApproval !== true) {
            $violations[] = $this->violation('extra_approval_required',
                'External offensive MCP always requires extra approval.');
        }

        // Step 5 + HexStrike Constraints: every mandatory control must be enforced.
        $missingControls = [];
        foreach (self::REQUIRED_CONTROLS as $control) {
            if (! in_array($control, $enforced, true)) {
                $missingControls[] = $control;
            }
        }
        if ($missingControls !== []) {
            $violations[] = $this->violation('missing_required_controls',
                'Wrapped recipe must enforce all mandatory controls; missing: ' . implode(', ', $missingControls),
                ['missing' => $missingControls],
            );
        }

        $verdict = $violations === [] ? self::VERDICT_ADMIT : self::VERDICT_REJECT;

        return [
            'schema' => self::SCHEMA,
            'verdict' => $verdict,
            'tool_slug' => $toolSlug,
            'recipe_name' => $recipeName,
            'expected_wrapper_skill' => $expectedWrapper,
            'execution_tier' => self::EXECUTION_TIER,
            'extra_approval_required' => true,
            'sandbox_required' => self::SANDBOX_DEDICATED_VM,
            'required_controls' => self::REQUIRED_CONTROLS,
            'missing_controls' => $missingControls,
            'violations' => $violations,
            'admitted' => $verdict === self::VERDICT_ADMIT,
        ];
    }

    /**
     * Decide a single MCP call against an admitted wrapper. Mirrors the per-call
     * Constraints: Decision Receipt before the call, scope proof (`scope.in`),
     * refusal matrix, then kill-switch the moment the tool leaves declared scope.
     *
     * Precedence (load-bearing):
     *   1. out-of-scope target  => kill_switch  (tool left declared scope;
     *      refusal-matrix cyber-ref-010 has NO exception).
     *   2. missing Decision Receipt => refuse (receipt required before every call).
     *   3. refusal-matrix match  => refuse (refusal-with-receipt).
     *   4. rate limit exceeded   => refuse.
     *   5. otherwise             => proceed.
     *
     * @param array<string,mixed> $call
     *        target            : string  the engagement target of this call
     *        scope_in          : list<string>  declared in-scope targets (scope.in)
     *        has_decision_receipt : bool
     *        refusal_matrix_hit   : bool  a refusal-matrix rule matched
     *        rate_limited         : bool  call would exceed the MCP rate limit
     *
     * @return array<string,mixed> the per-call receipt
     */
    public function evaluateCall(array $call): array
    {
        $target = $this->str($call['target'] ?? null);
        $scopeIn = $this->normalizeList($call['scope_in'] ?? []);
        $hasReceipt = $this->boolGate($call['has_decision_receipt'] ?? false);
        $refusalHit = $this->boolGate($call['refusal_matrix_hit'] ?? false);
        $rateLimited = $this->boolGate($call['rate_limited'] ?? false);

        $inScope = $target !== null
            && $scopeIn !== []
            && in_array(strtolower($target), array_map('strtolower', $scopeIn), true);

        // 1. Tool left declared scope => kill-switch (hard stop, no exception).
        if (! $inScope) {
            return $this->callReceipt(self::CALL_KILL_SWITCH, $target, false,
                'Target is outside declared scope.in; kill-switch engaged (refusal-matrix cyber-ref-010, no exception).',
                ['evidence_ledger' => true, 'notify_operator' => true]);
        }

        // 2. Decision Receipt is mandatory BEFORE every call.
        if (! $hasReceipt) {
            return $this->callReceipt(self::CALL_REFUSE, $target, true,
                'No Decision Receipt for this call; receipt required before every MCP call.',
                ['evidence_ledger' => true]);
        }

        // 3. Refusal matrix match => refusal-with-receipt.
        if ($refusalHit) {
            return $this->callReceipt(self::CALL_REFUSE, $target, true,
                'Refusal-matrix rule matched; refusal-with-receipt.',
                ['evidence_ledger' => true, 'notify_operator' => true]);
        }

        // 4. Rate limit.
        if ($rateLimited) {
            return $this->callReceipt(self::CALL_REFUSE, $target, true,
                'MCP call would exceed the configured rate limit.',
                ['evidence_ledger' => true]);
        }

        // 5. All gates clear.
        return $this->callReceipt(self::CALL_PROCEED, $target, true,
            'Decision Receipt present, target in scope, no refusal match, within rate limit.',
            ['evidence_ledger' => true]);
    }

    /**
     * Convenience predicate: is this candidate admissible as a wrapped recipe?
     */
    public function isAdmissible(array $candidate): bool
    {
        return $this->evaluateRegistration($candidate)['verdict'] === self::VERDICT_ADMIT;
    }

    /**
     * @return array{code:string,message:string,detail?:array<string,mixed>}
     */
    private function violation(string $code, string $message, ?array $detail = null): array
    {
        $row = ['code' => $code, 'message' => $message];
        if ($detail !== null) {
            $row['detail'] = $detail;
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $obligations
     * @return array<string,mixed>
     */
    private function callReceipt(string $verdict, ?string $target, bool $inScope, string $reason, array $obligations): array
    {
        return [
            'schema' => self::SCHEMA,
            'call_verdict' => $verdict,
            'target' => $target,
            'in_scope' => $inScope,
            'reason' => $reason,
            'obligations' => $obligations,
            'may_proceed' => $verdict === self::CALL_PROCEED,
        ];
    }

    /**
     * A wrapper skill is well-formed when it is `cyber-<something>-runner` with a
     * slug-safe middle segment.
     */
    private function isWrapperWellFormed(string $wrapper): bool
    {
        if (! str_starts_with($wrapper, 'cyber-') || ! str_ends_with($wrapper, '-runner')) {
            return false;
        }

        $middle = substr($wrapper, strlen('cyber-'), -strlen('-runner'));

        return $this->isSlug($middle);
    }

    private function isSlug(string $value): bool
    {
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', strtolower(trim($value))) === 1;
    }

    /**
     * Derive the wrapper root from a tool slug: a trailing "-mcp" is dropped so
     * "hexstrike-mcp" maps to wrapper "cyber-hexstrike-runner".
     */
    private function wrapperRoot(string $toolSlug): string
    {
        $root = strtolower(trim($toolSlug));
        if (str_ends_with($root, '-mcp')) {
            $root = substr($root, 0, -strlen('-mcp'));
        }

        return $root;
    }

    /**
     * @param mixed $values
     * @return list<string>
     */
    private function normalizeList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $clean = [];
        foreach ($values as $v) {
            if (is_string($v) && trim($v) !== '') {
                $clean[] = trim($v);
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

    private function boolGate(mixed $v): bool
    {
        if (is_string($v)) {
            return filter_var($v, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) $v;
    }
}
