<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

use App\Services\Ai\Aaeos\Support\AtlasAaeosValueNormalizer;
use App\Services\Ai\Policy\PolicyCanon;

/**
 * Policy Profile (kernel pipeline step) — runtime.
 *
 * Turns the kernel `policy-profile` step into deterministic, pure decision
 * logic. It sits between `context-builder` and `atlas-decide` and resolves the
 * permission envelope BEFORE any provider runs. It enforces the doc's concrete
 * contract:
 *
 *  - Input: intent/action, risk, environment, requested tool (the context pack).
 *  - Output: a permission profile = sandbox mode + autonomy + cost ceiling +
 *    tool allow/deny + a single decision (allow | require_approval | blocked).
 *  - Invariant: "ferramenta perigosa exige policy explicita" — a dangerous tool
 *    is NEVER implicitly authorized. Without explicit policy authorization the
 *    step demands a signature or a reduced scope; it does not pass through.
 *  - Regra para IA: permission is never implicit. If policy does not authorize,
 *    the only legal next steps are `request_signature` or `reduce_scope`.
 *  - Escopo: sandbox / autonomy / cost are enforced; a prompt or a provider
 *    preference can NEVER widen the envelope (no bypass-by-prompt).
 *  - Exemplos: a code change may require `workspace-write`; deleting files
 *    requires a higher authorization (signature), never plain workspace-write.
 *
 * Stateless and DB-free: every method is a pure function of its arguments.
 * Canonical autonomy / risk / decision vocab is reused from {@see PolicyCanon}.
 *
 * @see docs/engineering-knowledge-base/system-graph/policy-profile.md
 */
final class AtlasPolicyProfileService
{
    public const SCHEMA_VERSION = 'atlas.kernel.policy_profile.v1';

    /** Sandbox modes, least-privilege first. Index = privilege depth. */
    public const SANDBOX_READ_ONLY = 'read-only';

    public const SANDBOX_WORKSPACE_WRITE = 'workspace-write';

    public const SANDBOX_DANGER_FULL_ACCESS = 'danger-full-access';

    /**
     * Sandbox privilege ladder. A request can only be granted a sandbox at or
     * below the privilege its authorization justifies.
     *
     * @var array<string,int>
     */
    private const SANDBOX_RANK = [
        self::SANDBOX_READ_ONLY => 0,
        self::SANDBOX_WORKSPACE_WRITE => 1,
        self::SANDBOX_DANGER_FULL_ACCESS => 2,
    ];

    /** Legal next steps when the envelope does not authorize the action. */
    public const NEXT_REQUEST_SIGNATURE = 'request_signature';

    public const NEXT_REDUCE_SCOPE = 'reduce_scope';

    public const NEXT_PROCEED = 'proceed';

    /**
     * Documented dangerous/destructive verbs. These ALWAYS require explicit
     * policy authorization (a signature); they are never implicitly allowed and
     * never run under plain workspace-write. The doc's worked example: deleting
     * files demands a higher authorization than a code edit.
     *
     * @var list<string>
     */
    private const DANGEROUS_VERBS = [
        'delete',
        'delete_files',
        'rm',
        'drop',
        'destroy',
        'force_push',
        'production_deploy',
        'deploy_production',
        'transfer',
        'transfer_funds',
        'live_trade',
        'execute_trade',
        'rotate_secret',
        'disable_security',
    ];

    /**
     * Verbs that only mutate the workspace (code edits). They need
     * `workspace-write`, never danger-full-access, and never a signature.
     *
     * @var list<string>
     */
    private const WORKSPACE_WRITE_VERBS = [
        'edit',
        'write',
        'patch',
        'apply_patch',
        'refactor',
        'format',
        'lint_fix',
        'code_change',
    ];

    /**
     * Primary entry point. Given a request from the context pack, resolve the
     * full permission profile that bounds Atlas Decide.
     *
     * Expected request shape (all optional, with safe least-privilege defaults):
     *   [
     *     'intent'      => 'programming.edit',   // dotted action / intent
     *     'risk'        => 'low'|'medium'|'high'|'critical',
     *     'environment' => 'workspace'|'production',
     *     'tool'        => 'broker_api',         // requested tool id (optional)
     *     'signature_present' => bool,           // operator signature attached?
     *   ]
     *
     * @param  array<string,mixed>  $request
     * @return array{
     *   schema_version:string,
     *   intent:string,
     *   verb:string,
     *   environment:string,
     *   risk:string,
     *   dangerous:bool,
     *   signature_present:bool,
     *   sandbox:string,
     *   autonomy:string,
     *   cost_ceiling:float,
     *   tools:array{allow:list<string>,deny:list<string>},
     *   decision:string,
     *   next_step:string,
     *   required_approvals:list<string>,
     *   reasons:list<string>
     * }
     */
    public function resolveProfile(array $request): array
    {
        $intent = strtolower(trim((string) ($request['intent'] ?? '')));
        $verb = $this->verbOf($intent);
        $environment = strtolower(trim((string) ($request['environment'] ?? 'workspace')));
        $risk = $this->normalizeRisk((string) ($request['risk'] ?? PolicyCanon::RISK_LOW));
        $signaturePresent = (bool) ($request['signature_present'] ?? false);
        $tool = strtolower(trim((string) ($request['tool'] ?? '')));

        $dangerous = $this->isDangerous($verb) || $this->isDangerous($tool);

        $reasons = [];
        $requiredApprovals = [];

        // --- Sandbox: least-privilege by default, raised only as justified. ---
        $sandbox = self::SANDBOX_READ_ONLY;
        if ($this->isWorkspaceWrite($verb) && ! $dangerous) {
            $sandbox = self::SANDBOX_WORKSPACE_WRITE;
            $reasons[] = 'sandbox_workspace_write_for_code_change';
        }
        // Production environment can never run under plain workspace-write
        // without explicit authorization; it is treated as elevated.
        $elevatedEnv = $environment === 'production';

        // --- The invariant: dangerous tool/action requires explicit policy. ---
        $decision = PolicyCanon::DECISION_ALLOW;
        $nextStep = self::NEXT_PROCEED;

        if ($dangerous || $elevatedEnv) {
            // Never implicit. Demand a signature; only then grant full access.
            $requiredApprovals[] = $dangerous ? 'destructive_action' : 'production_environment';
            if ($signaturePresent) {
                $sandbox = self::SANDBOX_DANGER_FULL_ACCESS;
                $decision = PolicyCanon::DECISION_REQUIRE_APPROVAL;
                $nextStep = self::NEXT_PROCEED;
                $reasons[] = $dangerous
                    ? 'dangerous_action_authorized_by_explicit_signature'
                    : 'production_authorized_by_explicit_signature';
            } else {
                // No explicit authorization -> the step blocks the implicit path
                // and forces signature or scope reduction. No bypass-by-prompt.
                $sandbox = self::SANDBOX_READ_ONLY;
                $decision = PolicyCanon::DECISION_BLOCKED;
                $nextStep = $dangerous ? self::NEXT_REQUEST_SIGNATURE : self::NEXT_REDUCE_SCOPE;
                $reasons[] = $dangerous
                    ? 'dangerous_action_requires_explicit_policy'
                    : 'production_requires_explicit_authorization';
            }
        }

        // --- Risk gate: high/critical risk can never run autonomously. ---
        if ($decision === PolicyCanon::DECISION_ALLOW
            && PolicyCanon::riskExceeds($risk, PolicyCanon::RISK_MEDIUM)) {
            $decision = PolicyCanon::DECISION_REQUIRE_APPROVAL;
            $requiredApprovals[] = 'risk_review';
            $reasons[] = "risk_exceeds_autonomous_threshold:{$risk}";
            if ($nextStep === self::NEXT_PROCEED) {
                $nextStep = self::NEXT_REQUEST_SIGNATURE;
            }
        }

        if ($reasons === []) {
            $reasons[] = 'within_default_envelope';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'intent' => $intent,
            'verb' => $verb,
            'environment' => $environment,
            'risk' => $risk,
            'dangerous' => $dangerous,
            'signature_present' => $signaturePresent,
            'sandbox' => $sandbox,
            'autonomy' => $this->autonomyFor($decision),
            'cost_ceiling' => $this->costCeilingFor($risk, $dangerous),
            'tools' => $this->toolEnvelope($sandbox, $dangerous),
            'decision' => $decision,
            'next_step' => $nextStep,
            'required_approvals' => array_values(array_unique($requiredApprovals)),
            'reasons' => $reasons,
        ];
    }

    /**
     * Sandbox authorization check used by the executor. Confirms a requested
     * sandbox mode is within what the resolved profile granted. A provider
     * asking for MORE than the profile granted is always denied (no
     * bypass-by-provider-preference).
     *
     * @param  array<string,mixed>  $request
     * @return array{requested:string,granted:string,authorized:bool,reason:?string}
     */
    public function authorizeSandbox(string $requestedSandbox, array $request): array
    {
        $requested = strtolower(trim($requestedSandbox));
        $profile = $this->resolveProfile($request);
        $granted = $profile['sandbox'];

        $requestedRank = self::SANDBOX_RANK[$requested] ?? PHP_INT_MAX;
        $grantedRank = self::SANDBOX_RANK[$granted] ?? -1;

        $authorized = $requestedRank <= $grantedRank;

        return [
            'requested' => $requested,
            'granted' => $granted,
            'authorized' => $authorized,
            'reason' => $authorized
                ? null
                : 'requested_sandbox_exceeds_policy_grant',
        ];
    }

    /**
     * Whether a verb/tool is on the dangerous list (case-insensitive).
     */
    public function isDangerous(string $verbOrTool): bool
    {
        $needle = strtolower(trim($verbOrTool));
        if ($needle === '') {
            return false;
        }

        return in_array($needle, self::DANGEROUS_VERBS, true);
    }

    private function isWorkspaceWrite(string $verb): bool
    {
        return in_array($verb, self::WORKSPACE_WRITE_VERBS, true);
    }

    /**
     * Extract the operative verb from a dotted intent (e.g. "finance.live_trade"
     * -> "live_trade"; bare "edit" -> "edit").
     */
    private function verbOf(string $intent): string
    {
        if ($intent === '') {
            return '';
        }
        $parts = explode('.', $intent);

        return $parts[count($parts) - 1] ?? $intent;
    }

    private function normalizeRisk(string $risk): string
    {
        return AtlasAaeosValueNormalizer::lowercaseAllowed($risk, PolicyCanon::RISK_LEVELS, PolicyCanon::RISK_LOW);
    }

    /**
     * Map a decision to the autonomy level the executor may use. A blocked or
     * approval-gated decision can never be autonomous.
     */
    private function autonomyFor(string $decision): string
    {
        return match ($decision) {
            PolicyCanon::DECISION_ALLOW => PolicyCanon::AUTONOMY_EXECUTE_WITH_APPROVAL,
            PolicyCanon::DECISION_REQUIRE_APPROVAL => PolicyCanon::AUTONOMY_DRAFT,
            default => PolicyCanon::AUTONOMY_SUGGEST,
        };
    }

    /**
     * Cost ceiling shrinks as risk grows and is zero for an unauthorized
     * dangerous action (the step must not let cost leak before a signature).
     */
    private function costCeilingFor(string $risk, bool $dangerous): float
    {
        $base = match ($risk) {
            PolicyCanon::RISK_LOW => 10.0,
            PolicyCanon::RISK_MEDIUM => 5.0,
            PolicyCanon::RISK_HIGH => 2.0,
            PolicyCanon::RISK_CRITICAL => 1.0,
            default => 5.0,
        };

        return $dangerous ? min($base, 1.0) : $base;
    }

    /**
     * Resolve the tool allow/deny envelope for a sandbox. Destructive tools are
     * always on the deny list unless full access was explicitly granted.
     *
     * @return array{allow:list<string>,deny:list<string>}
     */
    private function toolEnvelope(string $sandbox, bool $dangerous): array
    {
        return match ($sandbox) {
            self::SANDBOX_DANGER_FULL_ACCESS => [
                'allow' => ['read', 'analysis', 'edit', 'write', 'destructive'],
                'deny' => [],
            ],
            self::SANDBOX_WORKSPACE_WRITE => [
                'allow' => ['read', 'analysis', 'edit', 'write', 'test', 'lint'],
                'deny' => ['destructive', 'production_deploy', 'transfer'],
            ],
            default => [
                'allow' => ['read', 'analysis', 'doc'],
                'deny' => $dangerous
                    ? ['destructive', 'edit', 'write', 'production_deploy', 'transfer']
                    : ['destructive', 'edit', 'write'],
            ],
        };
    }
}
