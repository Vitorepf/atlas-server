<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas SDD Agents-and-MCP contract decider.
 *
 * Pure, deterministic implementation of the authorization gate documented in
 * the "MCP Tools" and "MCP Safety Rules" sections. Given one MCP tool
 * invocation request it returns the single controlled verdict —
 * `allow`, `block` or `requires_decision_receipt` — so a surface can decide
 * whether an MCP call may proceed. It also exposes the canonical agent roster
 * and the read-only resource / prompt allowlists declared by the doc.
 *
 * Contract (from the doc):
 *   - "MCP is a surface, not authority." Resources are read models; they do
 *     not authorize execution.
 *   - Allowed tools "must be narrow and auditable" — a closed allowlist of 7.
 *   - Write-capable tools (patch application, PR creation, sensitive DB query,
 *     Figma mutation, external repo write) "require a dedicated Decision
 *     Receipt and policy" and "remain blocked until a dedicated AP, receipt
 *     model and human gate exist".
 *   - "Secrets never enter model context."
 *   - "External MCP servers must be wrapped by Atlas Tool Runtime; never
 *     connected raw to Atlas Decide."
 *   - "Tool outputs must be validated before becoming spec evidence."
 *
 * The service NEVER executes a tool, calls a provider, queries a database or
 * mutates state. It emits the verdict plus an audit receipt; callers enforce.
 *
 * @see docs/engineering-knowledge-base/spec-operating-system/agents-and-mcp-contract.md
 */
final class AtlasAgentsAndMcpContractService
{
    /** Stable receipt schema id for the verdict this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.sdd.mcp_contract_gate.v1';

    /** Canonical verdicts (closed set). */
    public const VERDICT_ALLOW = 'allow';
    public const VERDICT_BLOCK = 'block';
    public const VERDICT_REQUIRES_RECEIPT = 'requires_decision_receipt';

    /**
     * The 14 canonical internal SDD agent roles (doc "Internal Agents").
     * Names are the stable keys; order matches the doc table.
     *
     * @var array<string,string>
     */
    private const AGENT_ROLES = [
        'context_scout' => 'Finds relevant files, docs, specs, decisions, routes, tests and history.',
        'product_analyst' => 'Interprets user intent as product/business outcome.',
        'business_rule_miner' => 'Extracts confirmed rules, constraints and hypotheses.',
        'spec_compiler' => 'Creates operational spec, requirements and acceptance criteria.',
        'spec_critic' => 'Attacks ambiguity, missing rules, design conflicts and risk.',
        'architecture_agent' => 'Chooses approach compatible with existing stack and patterns.',
        'plan_compiler' => 'Converts spec into technical implementation plan.',
        'task_compiler' => 'Produces small, ordered, traceable tasks.',
        'execution_agent' => 'Implements only what the Decision Receipt allows.',
        'qa_agent' => 'Generates/runs tests and validates acceptance criteria.',
        'security_agent' => 'Checks auth, permissions, sensitive data and abuse cases.',
        'drift_detector' => 'Compares spec, code, tests, docs and receipt boundaries.',
        'evidence_agent' => 'Appends proof for diffs, tests, gates and traceability.',
        'learning_curator' => 'Proposes template, policy or context improvements.',
    ];

    /**
     * Closed read-only resource allowlist (doc "MCP Resources").
     * "Resources are read models. They do not authorize execution."
     *
     * @var list<string>
     */
    private const RESOURCES = [
        'project_code_context',
        'existing_specs',
        'canonical_documentation',
        'design_system_rules',
        'database_schema_summaries',
        'api_contracts',
        'logs_and_telemetry_summaries',
        'issues_and_pr_summaries',
        'evidence_events',
        'decision_receipt_summaries',
    ];

    /**
     * Closed governed-prompt allowlist (doc "MCP Prompts"). Prompts produce
     * candidate artifacts only; kernel/receipts/gates decide runtime effect.
     *
     * @var list<string>
     */
    private const PROMPTS = [
        'generate_spec',
        'critique_spec',
        'create_plan',
        'create_tasks',
        'review_security',
        'validate_drift',
        'summarize_evidence',
        'propose_learning',
    ];

    /**
     * Closed allowlist of narrow, auditable, read/inspect-only tools
     * (doc "MCP Tools"). These carry no runtime-write authority.
     *
     * @var list<string>
     */
    private const ALLOWED_TOOLS = [
        'read_file_context',
        'search_code_context',
        'run_allowed_test',
        'create_sdd_report',
        'inspect_traceability',
        'inspect_drift',
        'open_proposal',
    ];

    /**
     * Write-capable tools the doc enumerates that "require a dedicated Decision
     * Receipt and policy" and stay blocked until an AP + receipt model + human
     * gate exist.
     *
     * @var list<string>
     */
    private const WRITE_CAPABLE_TOOLS = [
        'apply_patch',
        'create_pull_request',
        'query_sensitive_database',
        'mutate_figma',
        'write_external_repo',
    ];

    /**
     * Decide the single controlled verdict for one MCP tool invocation.
     *
     * @param array<string,mixed> $request
     *        tool                 : string  the requested MCP tool name (required)
     *        secrets_in_context   : bool    request carries secrets/credentials (default false)
     *        external_server      : bool    tool is served by an external MCP server (default false)
     *        wrapped_by_tool_runtime : bool  external server is wrapped by Atlas Tool Runtime (default false)
     *        decision_receipt     : string|null  receipt id authorizing a write tool (default null)
     *        human_gate           : bool    a human gate approved the write receipt (default false)
     *
     * @return array<string,mixed> the verdict + audit receipt
     */
    public function decide(array $request): array
    {
        $tool = $this->normalizeTool($request['tool'] ?? null);
        $secretsInContext = (bool) ($request['secrets_in_context'] ?? false);
        $external = (bool) ($request['external_server'] ?? false);
        $wrapped = (bool) ($request['wrapped_by_tool_runtime'] ?? false);
        $receipt = $this->normalizeReceipt($request['decision_receipt'] ?? null);
        $humanGate = (bool) ($request['human_gate'] ?? false);

        $isAllowedTool = in_array($tool, self::ALLOWED_TOOLS, true);
        $isWriteTool = in_array($tool, self::WRITE_CAPABLE_TOOLS, true);

        $reasons = [];
        $verdict = null;

        // Rule 1 — "Secrets never enter model context." Hard, unconditional stop
        // that overrides every allowlist: no tool may run carrying secrets.
        if ($secretsInContext) {
            $verdict = self::VERDICT_BLOCK;
            $reasons[] = 'secrets_never_enter_model_context';
        }

        // Rule 2 — "External MCP servers must be wrapped by Atlas Tool Runtime;
        // never connected raw to Atlas Decide." A raw external server is blocked
        // before tool identity even matters.
        if ($verdict === null && $external && ! $wrapped) {
            $verdict = self::VERDICT_BLOCK;
            $reasons[] = 'external_server_not_wrapped_by_tool_runtime';
        }

        // Rule 3 — write-capable tools require a dedicated Decision Receipt AND
        // a human gate. Absent either, they stay blocked
        // ("remain blocked until a dedicated AP, receipt model and human gate
        // exist"); present both, they are explicitly receipt-governed.
        if ($verdict === null && $isWriteTool) {
            if ($receipt !== null && $humanGate) {
                $verdict = self::VERDICT_REQUIRES_RECEIPT;
                $reasons[] = 'write_tool_authorized_by_receipt_and_human_gate';
            } else {
                $verdict = self::VERDICT_BLOCK;
                $reasons[] = $receipt === null
                    ? 'write_tool_blocked_missing_dedicated_receipt'
                    : 'write_tool_blocked_missing_human_gate';
            }
        }

        // Rule 4 — narrow, auditable, read/inspect-only tools on the closed
        // allowlist may proceed. They carry no runtime-write authority.
        if ($verdict === null && $isAllowedTool) {
            $verdict = self::VERDICT_ALLOW;
            $reasons[] = 'narrow_auditable_read_tool_allowed';
        }

        // Rule 5 — closed allowlist: anything not explicitly allowed and not a
        // known write tool is blocked by default ("Allowed future tools must be
        // narrow and auditable").
        if ($verdict === null) {
            $verdict = self::VERDICT_BLOCK;
            $reasons[] = 'tool_not_on_closed_allowlist';
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'tool' => $tool,
            'verdict' => $verdict,
            'is_allowed_tool' => $isAllowedTool,
            'is_write_capable' => $isWriteTool,
            'secrets_in_context' => $secretsInContext,
            'external_server' => $external,
            'wrapped_by_tool_runtime' => $wrapped,
            'has_decision_receipt' => $receipt !== null,
            'human_gate' => $humanGate,
            // "MCP is a surface, not authority." A verdict never grants runtime
            // authority by itself; the kernel/receipt still gates execution.
            'grants_runtime_authority' => false,
            'auditable' => true,
            'reasons' => $reasons,
        ];
    }

    /**
     * Convenience predicate: may this MCP tool invocation proceed outright?
     * (false => caller must block or route through a Decision Receipt.)
     */
    public function isPermitted(array $request): bool
    {
        return $this->decide($request)['verdict'] === self::VERDICT_ALLOW;
    }

    /**
     * "Tool outputs must be validated before becoming spec evidence."
     * A tool output may be promoted to spec evidence only when it is explicitly
     * marked validated and is not flagged as carrying secrets.
     *
     * @param array<string,mixed> $output
     *        validated : bool  the output passed validation (default false)
     *        contains_secrets : bool  output still carries secrets (default false)
     */
    public function mayBecomeSpecEvidence(array $output): bool
    {
        return (bool) ($output['validated'] ?? false)
            && ! (bool) ($output['contains_secrets'] ?? false);
    }

    /**
     * Canonical contract manifest: the agent roster plus the closed
     * resource / prompt / tool allowlists and the governing invariants.
     *
     * @return array<string,mixed>
     */
    public function manifest(): array
    {
        return [
            'schema_version' => self::RECEIPT_SCHEMA,
            'authority' => 'mcp_is_a_surface_not_authority',
            'agent_count' => count(self::AGENT_ROLES),
            'agents' => self::AGENT_ROLES,
            'resources' => self::RESOURCES,
            'prompts' => self::PROMPTS,
            'allowed_tools' => self::ALLOWED_TOOLS,
            'write_capable_tools' => self::WRITE_CAPABLE_TOOLS,
            'invariants' => [
                'resources_are_read_models_not_execution_authority',
                'prompts_produce_candidate_artifacts_only',
                'write_tools_require_dedicated_receipt_and_human_gate',
                'secrets_never_enter_model_context',
                'external_servers_wrapped_by_tool_runtime',
                'tool_outputs_validated_before_spec_evidence',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function agentRoles(): array
    {
        return array_keys(self::AGENT_ROLES);
    }

    private function normalizeTool(mixed $tool): string
    {
        if (! is_string($tool) || trim($tool) === '') {
            return 'unknown';
        }

        return strtolower(trim($tool));
    }

    private function normalizeReceipt(mixed $receipt): ?string
    {
        if (is_string($receipt) && trim($receipt) !== '') {
            return trim($receipt);
        }

        return null;
    }
}
