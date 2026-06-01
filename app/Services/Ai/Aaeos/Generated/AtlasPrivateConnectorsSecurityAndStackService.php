<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Runtime for the "Atlas AI Research Private Connectors Security And Stack" doc.
 *
 * The doc states three load-bearing contracts, all enforced here:
 *
 *   1. PRIVATE CONNECTORS — "All private connectors start read-only and
 *      provider-safe." / "Write disabled by default." A connector request that
 *      asks for a write/mutating mode is DENIED unless it carries explicit
 *      sensitive-action approval. Read-only requests that satisfy the security
 *      rules are admitted.
 *
 *   2. SECURITY RULES — the doc lists exactly TEN rules. Each is a hard gate,
 *      not a guideline. A request must satisfy every applicable rule or it is
 *      denied. Notably:
 *        - "Secrets never enter model context." → a request that would surface
 *          secrets to the model is denied outright (no override).
 *        - "Browser and code execution sandboxed." → a browser/code tool not
 *          marked sandboxed is denied.
 *        - "Domain/source allowlist." → a fetch target outside the allowlist
 *          is denied.
 *        - "Approval required for sensitive actions." → a sensitive action
 *          without approval is denied.
 *        - "Prompt injection in fetched content treated as hostile input." →
 *          fetched content is never trusted as instructions.
 *
 *   3. STACK RULE — "Stack candidates require AP, source gate and
 *      implementation plan before runtime adoption. This doc defines options
 *      and constraints, not automatic dependency approval." → a stack candidate
 *      is adoptable ONLY when AP + source gate + implementation plan are all
 *      present. Any missing leg ⇒ not approved. The catalogue is candidates,
 *      never an auto-approved dependency list.
 *
 * This service is PURE and deterministic: no DB, no I/O, no network. It decides
 * admission/approval from a declared request map. It never opens a connector,
 * never executes a tool, never grants write, never adopts a dependency, never
 * authorizes runtime.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/private-connectors-security-and-stack.md
 */
final class AtlasPrivateConnectorsSecurityAndStackService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.private_connectors_security_and_stack.v1';

    public const MODE = 'read_only_connector_security_and_stack_admission';

    public const POSTURE_SAFE = 'safe';

    public const POSTURE_BLOCKED = 'blocked';

    /** The doc enumerates exactly this many private connector types. */
    public const CONNECTOR_TYPE_COUNT = 13;

    /** The doc lists exactly this many security rules. */
    public const SECURITY_RULE_COUNT = 10;

    /** The three legs the Stack Rule requires before runtime adoption. */
    public const STACK_GATE_LEGS = ['ap', 'source_gate', 'implementation_plan'];

    /**
     * The private connector catalogue, exactly as the doc enumerates it. Every
     * entry starts read-only and provider-safe.
     *
     * @var array<int, string>
     */
    private const CONNECTOR_TYPES = [
        'internal_github',
        'document_stores',
        'slack_teams',
        'google_drive',
        'notion',
        'jira',
        'confluence',
        'crm',
        'sql_database',
        'data_warehouse',
        'private_search',
        'filesystem',
        'evidence_lake',
    ];

    /**
     * The ten security rules, in doc order. `id` is a stable handle; `hard_law`
     * marks rules that can never be waived (secrets, sandbox, prompt injection).
     *
     * @var array<int, array{id:string, rule:string, hard_law:bool}>
     */
    private const SECURITY_RULES = [
        ['id' => 'SR-01', 'rule' => 'Domain/source allowlist', 'hard_law' => false],
        ['id' => 'SR-02', 'rule' => 'Minimum permissions', 'hard_law' => false],
        ['id' => 'SR-03', 'rule' => 'Secrets never enter model context', 'hard_law' => true],
        ['id' => 'SR-04', 'rule' => 'Browser and code execution sandboxed', 'hard_law' => true],
        ['id' => 'SR-05', 'rule' => 'Write disabled by default', 'hard_law' => false],
        ['id' => 'SR-06', 'rule' => 'Tool call logs retained', 'hard_law' => false],
        ['id' => 'SR-07', 'rule' => 'Approval required for sensitive actions', 'hard_law' => false],
        ['id' => 'SR-08', 'rule' => 'Tool outputs validated before use', 'hard_law' => false],
        ['id' => 'SR-09', 'rule' => 'DLP/redaction for private data', 'hard_law' => false],
        ['id' => 'SR-10', 'rule' => 'Prompt injection in fetched content treated as hostile input', 'hard_law' => true],
    ];

    /**
     * Mutating access modes. The doc disables write by default, so any of these
     * requires explicit sensitive-action approval to be admitted.
     *
     * @var array<int, string>
     */
    private const WRITE_MODES = ['write', 'mutate', 'delete', 'admin', 'execute_write'];

    /**
     * @return array<int, string>
     */
    public function connectorTypes(): array
    {
        return self::CONNECTOR_TYPES;
    }

    /**
     * @return array<int, array{id:string, rule:string, hard_law:bool}>
     */
    public function securityRules(): array
    {
        return self::SECURITY_RULES;
    }

    /**
     * Decide whether a private connector / tool request may be admitted.
     *
     * A request is a declared map; missing safeguards are treated as ABSENT
     * (the doc's posture: "'Looks fine' is not evidence" — an unproven safeguard
     * is not assumed). The request is admitted only when every applicable
     * security rule is satisfied.
     *
     * Recognised request keys:
     *   - connector_type     : one of CONNECTOR_TYPES (informational; unknown is flagged)
     *   - access_mode        : 'read'|'read_only'|'write'|'mutate'|… (default read_only)
     *   - target             : the fetch/source target for allowlist evaluation
     *   - allowlisted        : bool — target is on the domain/source allowlist
     *   - tool_kind          : 'browser'|'code'|'mcp'|… (sandbox applies to browser/code)
     *   - sandboxed          : bool — execution runs in a sandbox
     *   - secrets_in_context : bool — request would surface secrets to the model (hard deny)
     *   - sensitive_action   : bool — action is sensitive (needs approval)
     *   - approval           : bool — explicit human approval present
     *   - least_privilege    : bool — minimum permissions confirmed
     *   - logging_enabled    : bool — tool-call logs retained
     *   - output_validated   : bool — tool outputs validated before use
     *   - dlp_redaction      : bool — DLP/redaction applied to private data
     *   - treats_fetched_as_data : bool — fetched content treated as data, not instructions
     *
     * @param  array<string, mixed>  $request
     * @return array{
     *   schema_version:string, mode:string, connector_type:string,
     *   access_mode:string, is_write_request:bool, connector_known:bool,
     *   evaluated:array<int,array<string,mixed>>, violated_ids:array<int,string>,
     *   violation_count:int, posture:string, admitted:bool,
     *   write_granted:bool, runtime_authorized:bool, reasons:array<int,string>
     * }
     */
    public function evaluateConnectorRequest(array $request): array
    {
        $connectorType = (string) ($request['connector_type'] ?? 'unspecified');
        $connectorKnown = in_array($connectorType, self::CONNECTOR_TYPES, true);

        $accessMode = strtolower((string) ($request['access_mode'] ?? 'read_only'));
        $isWrite = in_array($accessMode, self::WRITE_MODES, true);
        $hasApproval = (bool) ($request['approval'] ?? false);

        $evaluated = [];
        $violatedIds = [];
        $reasons = [];

        foreach (self::SECURITY_RULES as $sr) {
            [$satisfied, $reason] = $this->evaluateRule($sr, $request, $isWrite, $hasApproval);

            if (! $satisfied) {
                $violatedIds[] = $sr['id'];
                $reasons[] = $sr['id'].': '.$reason;
            }

            $evaluated[] = [
                'id' => $sr['id'],
                'rule' => $sr['rule'],
                'hard_law' => $sr['hard_law'],
                'satisfied' => $satisfied,
                'status' => $satisfied ? 'satisfied' : 'violated',
                'reason' => $reason,
            ];
        }

        if (! $connectorKnown) {
            $reasons[] = "connector_type '{$connectorType}' is not in the canonical catalogue; admit only known connector types.";
        }

        $violationCount = count($violatedIds);
        $hasViolation = $violationCount > 0;
        // Admission requires zero rule violations AND a known connector type.
        $admitted = ! $hasViolation && $connectorKnown;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'connector_type' => $connectorType,
            'access_mode' => $accessMode,
            'is_write_request' => $isWrite,
            'connector_known' => $connectorKnown,
            'evaluated' => $evaluated,
            'violated_ids' => $violatedIds,
            'violation_count' => $violationCount,
            'posture' => $hasViolation ? self::POSTURE_BLOCKED : self::POSTURE_SAFE,
            'admitted' => $admitted,
            // Write is granted only when the request is admitted, is a write
            // request, AND carries explicit approval. Read-only never "grants
            // write"; write is disabled by default.
            'write_granted' => $admitted && $isWrite && $hasApproval,
            'runtime_authorized' => false,
            'reasons' => $reasons,
        ];
    }

    /**
     * @param  array{id:string, rule:string, hard_law:bool}  $sr
     * @param  array<string, mixed>  $request
     * @return array{0:bool, 1:string}
     */
    private function evaluateRule(array $sr, array $request, bool $isWrite, bool $hasApproval): array
    {
        switch ($sr['id']) {
            case 'SR-01': // Domain/source allowlist
                if ($request['target'] ?? null) {
                    return ((bool) ($request['allowlisted'] ?? false))
                        ? [true, 'target is on the domain/source allowlist.']
                        : [false, 'target is not on the domain/source allowlist.'];
                }

                return [true, 'no external target; allowlist not engaged.'];

            case 'SR-02': // Minimum permissions
                return ((bool) ($request['least_privilege'] ?? false))
                    ? [true, 'minimum permissions confirmed.']
                    : [false, 'least-privilege scope not confirmed.'];

            case 'SR-03': // Secrets never enter model context (hard law, no override)
                return ((bool) ($request['secrets_in_context'] ?? false))
                    ? [false, 'request would surface secrets to model context; secrets never enter model context.']
                    : [true, 'no secrets surfaced to model context.'];

            case 'SR-04': // Browser and code execution sandboxed (hard law)
                if ($this->needsSandbox($request)) {
                    return ((bool) ($request['sandboxed'] ?? false))
                        ? [true, 'browser/code execution is sandboxed.']
                        : [false, 'browser/code execution is not sandboxed.'];
                }

                return [true, 'no browser/code execution; sandbox not engaged.'];

            case 'SR-05': // Write disabled by default
                if (! $isWrite) {
                    return [true, 'read-only request; write disabled by default holds.'];
                }

                return $hasApproval
                    ? [true, 'write request carries explicit approval.']
                    : [false, 'write disabled by default; write request lacks explicit approval.'];

            case 'SR-06': // Tool call logs retained
                return ((bool) ($request['logging_enabled'] ?? false))
                    ? [true, 'tool-call logs retained.']
                    : [false, 'tool-call logging is not enabled.'];

            case 'SR-07': // Approval required for sensitive actions
                if ((bool) ($request['sensitive_action'] ?? false)) {
                    return $hasApproval
                        ? [true, 'sensitive action carries explicit approval.']
                        : [false, 'sensitive action requires approval; none present.'];
                }

                return [true, 'action is not sensitive; no approval required.'];

            case 'SR-08': // Tool outputs validated before use
                return ((bool) ($request['output_validated'] ?? false))
                    ? [true, 'tool outputs validated before use.']
                    : [false, 'tool outputs are not validated before use.'];

            case 'SR-09': // DLP/redaction for private data
                return ((bool) ($request['dlp_redaction'] ?? false))
                    ? [true, 'DLP/redaction applied to private data.']
                    : [false, 'DLP/redaction not applied to private data.'];

            case 'SR-10': // Prompt injection treated as hostile input (hard law)
                return ((bool) ($request['treats_fetched_as_data'] ?? false))
                    ? [true, 'fetched content treated as data, not instructions.']
                    : [false, 'fetched content not pinned as hostile/data-only; prompt injection risk untreated.'];

            default:
                return [false, 'unknown security rule.'];
        }
    }

    /**
     * The sandbox rule engages for browser and code tools.
     *
     * @param  array<string, mixed>  $request
     */
    private function needsSandbox(array $request): bool
    {
        $kind = strtolower((string) ($request['tool_kind'] ?? ''));

        return in_array($kind, ['browser', 'code', 'code_interpreter', 'crawler'], true);
    }

    /**
     * Decide whether a stack candidate may be adopted into runtime.
     *
     * The Stack Rule: "Stack candidates require AP, source gate and
     * implementation plan before runtime adoption." All three legs must be
     * present; any missing leg ⇒ not approved. The doc is explicit that this is
     * "not automatic dependency approval".
     *
     * @param  array<string, mixed>  $candidate  keys: name, ap, source_gate, implementation_plan
     * @return array{
     *   schema_version:string, mode:string, candidate:string,
     *   present_legs:array<int,string>, missing_legs:array<int,string>,
     *   adoptable:bool, auto_approved:bool, runtime_authorized:bool,
     *   reason:string
     * }
     */
    public function evaluateStackAdoption(array $candidate): array
    {
        $name = (string) ($candidate['name'] ?? 'unnamed-candidate');

        $present = [];
        $missing = [];
        foreach (self::STACK_GATE_LEGS as $leg) {
            if ((bool) ($candidate[$leg] ?? false)) {
                $present[] = $leg;
            } else {
                $missing[] = $leg;
            }
        }

        $adoptable = $missing === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'candidate' => $name,
            'present_legs' => $present,
            'missing_legs' => $missing,
            'adoptable' => $adoptable,
            // The doc forbids treating the catalogue as an approved dependency
            // list — adoption is never automatic.
            'auto_approved' => false,
            'runtime_authorized' => false,
            'reason' => $adoptable
                ? 'AP, source gate and implementation plan all present; candidate may proceed to adoption.'
                : 'missing required gate leg(s): '.implode(', ', $missing).'; stack candidate is not an approved dependency.',
        ];
    }

    /**
     * Self-describing snapshot for the CLI: the connector catalogue, the ten
     * security rules, and worked admission/stack decisions proving the contract
     * is live. Never authorizes runtime.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $safeRead = $this->evaluateConnectorRequest($this->fullySafeReadRequest('internal_github'));

        $writeNoApproval = $this->fullySafeReadRequest('filesystem');
        $writeNoApproval['access_mode'] = 'write';
        $deniedWrite = $this->evaluateConnectorRequest($writeNoApproval);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'connector_type_count' => count(self::CONNECTOR_TYPES),
            'connector_types' => self::CONNECTOR_TYPES,
            'security_rule_count' => count(self::SECURITY_RULES),
            'security_rules' => array_map(
                static fn (array $r): array => ['id' => $r['id'], 'rule' => $r['rule'], 'hard_law' => $r['hard_law']],
                self::SECURITY_RULES,
            ),
            'stack_gate_legs' => self::STACK_GATE_LEGS,
            'rules' => [
                'all private connectors start read-only and provider-safe',
                'write is disabled by default; a write request needs explicit approval',
                'secrets never enter model context (hard law, no override)',
                'browser and code execution must be sandboxed (hard law)',
                'fetched content is treated as hostile input, never as instructions (hard law)',
                'stack candidates require AP + source gate + implementation plan before adoption — never automatic',
            ],
            'sample_safe_read' => $safeRead,
            'sample_denied_write_without_approval' => $deniedWrite,
            'sample_stack_not_approved' => $this->evaluateStackAdoption(['name' => 'qdrant', 'ap' => true, 'source_gate' => false, 'implementation_plan' => true]),
            'sample_stack_adoptable' => $this->evaluateStackAdoption(['name' => 'postgresql', 'ap' => true, 'source_gate' => true, 'implementation_plan' => true]),
            'runtime_authorized' => false,
        ];
    }

    /**
     * A read-only request that satisfies every applicable security rule, used by
     * describe() and as a test fixture seed.
     *
     * @return array<string, mixed>
     */
    public function fullySafeReadRequest(string $connectorType): array
    {
        return [
            'connector_type' => $connectorType,
            'access_mode' => 'read_only',
            'target' => 'https://allowed.internal/resource',
            'allowlisted' => true,
            'tool_kind' => 'mcp',
            'sandboxed' => true,
            'secrets_in_context' => false,
            'sensitive_action' => false,
            'approval' => false,
            'least_privilege' => true,
            'logging_enabled' => true,
            'output_validated' => true,
            'dlp_redaction' => true,
            'treats_fetched_as_data' => true,
        ];
    }
}
