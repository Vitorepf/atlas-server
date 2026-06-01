<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Dev Efficient Programming Flow Runbook v1 · Parte 8 — pure, deterministic
 * decider for the "Conteudo Extraido" slice (§12.2 PRs Sugeridos through §15.1.9
 * Identidade do fluxo): the Fatia 5 escalation + the GO operational intake
 * invariants.
 *
 * This service does NOT execute the pipeline and touches no I/O. The richer
 * runtime lives under App\Services\Ai\Programming\AtlasDev\Escalation
 * (EscalationSignalScorer, EscalationDecisionEngine, EscalationDecision) and
 * App\Http\Controllers\AtlasDev\RunController. Here we encode the doc-as-law
 * contract for part-08 as a closed set of typed decisions so an agent (or any
 * caller) can ask, without re-reading prose:
 *   - given an escalation_score + risk_level, which target does §12.2 PR 5.1
 *     prescribe (forge / obra_candidate / none), and is human action required?
 *     (§12.2 PR 5.1 algoritmo step 3-4, §12.3 "EscalationDecision aparece em
 *     receipts R4+", §13 "Duplicar service" mitigated by reuse map)
 *   - given a Run-intake body, which canonical error code does §15.1.5 prescribe,
 *     following the documented precedence (operator_confirmed > task_contract_hash
 *     > confirmation_token), and what HTTP status? (§15.1.5 "Erros canonicos")
 *   - given the requested gate transition, is it allowed in the §15.1.3 safe
 *     ordering (Plan before Run; flag=false yields the disabled envelope)?
 *   - is a confirmation token valid given age/contract-binding/consumption per
 *     §15.1.4 (DB+HMAC, single-use, TTL default 300s)?
 *   - which HTTP response fields are path-redacted per §15.1.7 (F-04), and which
 *     absolute-path leak is forbidden?
 *   - is a flow-identity intake payload canonical per §15.1.9 (flow_id always
 *     atlas_dev; flow_origin in {atlas_ai_router, direct})?
 *
 * Documented rules this code actually enforces (one-to-one with the doc):
 *   §12.2 PR 5.1 escalation target:
 *     score >= 7 OR risk_level >= R4 -> forge (human_action_required = true);
 *     4 <= score < 7                 -> obra_candidate (human_action_required = false);
 *     score < 4 (and risk < R4)      -> none (no decision).
 *     forge NEVER auto-creates an Obra — preview requires human action
 *     (§12.2 PR 5.4a "Preview exige acao humana; nenhuma Obra criada
 *     automaticamente").
 *   §15.1.5 Run intake error precedence (first failure wins):
 *     operator_confirmed missing / falsy / truthy-string / 1 -> 400 OPERATOR_NOT_CONFIRMED;
 *     task_contract_hash mismatch                            -> 422 TASK_CONTRACT_HASH_MISMATCH;
 *     confirmation_token missing/invalid/expired/consumed/contract-mismatch
 *                                                            -> 403 CONFIRMATION_TOKEN_*.
 *   §15.1.3 enable ordering: Plan (zero-provider) may be enabled before Run;
 *     Run requires Plan already green; a disabled flag yields the canonical
 *     disabled envelope (Plan -> 503 ATLAS_DEV_PLAN_DISABLED;
 *     Run -> 503 ATLAS_DEV_RUN_DISABLED) with no side effects.
 *   §15.1.2 / §15.1.4 fail-closed: missing/short APP_KEY -> 500 ATLAS_DEV_KEY_MISSING.
 *   §15.1.4 confirmation token: valid only if age <= ttl (default 300s),
 *     same (run_id, task_contract_hash) binding, and not yet consumed.
 *   §15.1.7 path redaction (F-04): responses carry workspace_label (basename),
 *     workspace_hash, persisted_artifact_refs / persisted_receipt_refs as
 *     RELATIVE refs (receipts/<run_id>/<file>); absolute /Users/... paths are
 *     forbidden in any HTTP response field.
 *   §15.1.9 flow identity: flow_id is always 'atlas_dev'; flow_origin must be
 *     'atlas_ai_router' or 'direct'; command_intent is optional.
 */
final class AtlasDevEffProgFlowRunbookV1Part08Service
{
    // ---- §12.2 PR 5.1 escalation targets ----
    public const TARGET_FORGE = 'forge';
    public const TARGET_OBRA_CANDIDATE = 'obra_candidate';
    public const TARGET_NONE = 'none';

    public const ESCALATION_SCORE_MIN = 0;
    public const ESCALATION_SCORE_MAX = 10;
    public const THRESHOLD_FORGE = 7;
    public const THRESHOLD_OBRA = 4;

    public const ALLOWED_RISK_LEVELS = ['R0', 'R1', 'R2', 'R3', 'R4', 'R5'];
    /** R4 is the documented force-forge floor (PR 5.1 step 3 "risk_level >= R4"). */
    public const FORCE_FORGE_RISK_INDEX = 4;

    // ---- §15.1.5 Run-intake canonical error codes ----
    public const ERR_OPERATOR_NOT_CONFIRMED = 'OPERATOR_NOT_CONFIRMED';
    public const ERR_TASK_CONTRACT_HASH_MISMATCH = 'TASK_CONTRACT_HASH_MISMATCH';
    public const ERR_CONFIRMATION_TOKEN_MISSING = 'CONFIRMATION_TOKEN_MISSING';
    public const ERR_CONFIRMATION_TOKEN_INVALID = 'CONFIRMATION_TOKEN_INVALID';
    public const ERR_CONFIRMATION_TOKEN_EXPIRED = 'CONFIRMATION_TOKEN_EXPIRED';
    public const ERR_CONFIRMATION_TOKEN_ALREADY_CONSUMED = 'CONFIRMATION_TOKEN_ALREADY_CONSUMED';
    public const ERR_CONFIRMATION_TOKEN_CONTRACT_MISMATCH = 'CONFIRMATION_TOKEN_CONTRACT_MISMATCH';

    // ---- §15.1.2 / §15.1.3 gate envelopes ----
    public const ERR_KEY_MISSING = 'ATLAS_DEV_KEY_MISSING';
    public const ERR_PLAN_DISABLED = 'ATLAS_DEV_PLAN_DISABLED';
    public const ERR_RUN_DISABLED = 'ATLAS_DEV_RUN_DISABLED';

    public const STAGE_PLAN = 'plan';
    public const STAGE_RUN = 'run';

    /** §15.1.4 default confirmation-token TTL. */
    public const CONFIRMATION_TOKEN_TTL_SECONDS_DEFAULT = 300;

    /** §15.1.2 APP_KEY decoded-byte floor. */
    public const APP_KEY_MIN_DECODED_BYTES = 32;

    // ---- §15.1.9 flow identity ----
    public const FLOW_ID = 'atlas_dev';
    public const FLOW_ORIGIN_ROUTER = 'atlas_ai_router';
    public const FLOW_ORIGIN_DIRECT = 'direct';
    public const ALLOWED_FLOW_ORIGINS = [
        self::FLOW_ORIGIN_ROUTER,
        self::FLOW_ORIGIN_DIRECT,
    ];

    /**
     * §12.2 PR 5.1 step 3-4 — map a deterministic escalation score (0..10) and
     * risk level onto the documented escalation target.
     *
     *   score >= 7 OR risk_level >= R4 -> forge        (human_action_required)
     *   4 <= score < 7                 -> obra_candidate
     *   score < 4 (and risk < R4)      -> none          (escalated = false)
     *
     * @return array{
     *   target:string,
     *   escalated:bool,
     *   human_action_required:bool,
     *   auto_creates_obra:bool,
     *   score:int,
     *   risk_level:string,
     *   risk_index:int,
     *   force_forge:bool,
     *   reasons:list<string>
     * }
     */
    public function decideEscalationTarget(int $score, string $riskLevel): array
    {
        $clamped = max(self::ESCALATION_SCORE_MIN, min(self::ESCALATION_SCORE_MAX, $score));
        $riskIndex = $this->riskIndex($riskLevel);
        $forceForge = $riskIndex >= self::FORCE_FORGE_RISK_INDEX;

        if ($clamped < self::THRESHOLD_OBRA && ! $forceForge) {
            return [
                'target' => self::TARGET_NONE,
                'escalated' => false,
                'human_action_required' => false,
                'auto_creates_obra' => false,
                'score' => $clamped,
                'risk_level' => $riskLevel,
                'risk_index' => $riskIndex,
                'force_forge' => false,
                'reasons' => ['below_obra_threshold'],
            ];
        }

        $isForge = $clamped >= self::THRESHOLD_FORGE || $forceForge;
        $target = $isForge ? self::TARGET_FORGE : self::TARGET_OBRA_CANDIDATE;

        $reasons = [];
        if ($forceForge) {
            $reasons[] = 'risk_level_'.strtolower($riskLevel).'_forces_forge';
        }
        if ($clamped >= self::THRESHOLD_FORGE) {
            $reasons[] = 'score_gte_forge_threshold';
        } elseif ($clamped >= self::THRESHOLD_OBRA) {
            $reasons[] = 'score_in_obra_band';
        }

        return [
            'target' => $target,
            'escalated' => true,
            // §12.2 PR 5.1: forge always requires human action; obra_candidate
            // is a softer signal. §12.2 PR 5.4a: nenhuma Obra criada automaticamente.
            'human_action_required' => $isForge,
            'auto_creates_obra' => false,
            'score' => $clamped,
            'risk_level' => $riskLevel,
            'risk_index' => $riskIndex,
            'force_forge' => $forceForge,
            'reasons' => $reasons,
        ];
    }

    /**
     * §15.1.5 — validate a Run-intake body following the documented error
     * precedence (first failure wins). Returns the canonical error code +
     * HTTP status, or accepted=true with no error.
     *
     * The body keys mirror §15.1.5: run_id, task_contract_hash,
     * confirmation_token, operator_confirmed.
     *
     * Precedence:
     *   1. operator_confirmed must be the strict boolean true. Anything else
     *      (missing, false, "true" string, 1) -> 400 OPERATOR_NOT_CONFIRMED.
     *   2. task_contract_hash must equal the plan's hash -> 422 on mismatch.
     *   3. confirmation_token must be present + valid -> 403 CONFIRMATION_TOKEN_*.
     *
     * @param  array<string,mixed>  $body
     * @param  array{
     *     task_contract_hash?:string,
     *     token_present?:bool,
     *     token_status?:string
     * }  $context  expected hash + token verification facts (from §15.1.4)
     * @return array{accepted:bool, error:?string, http_status:int, stage:string}
     */
    public function validateRunIntake(array $body, array $context = []): array
    {
        // 1) operator_confirmed must be strict boolean true.
        if (! array_key_exists('operator_confirmed', $body)
            || $body['operator_confirmed'] !== true) {
            return $this->intakeError(self::ERR_OPERATOR_NOT_CONFIRMED, 400);
        }

        // 2) task_contract_hash binding.
        $expectedHash = $context['task_contract_hash'] ?? null;
        $providedHash = $body['task_contract_hash'] ?? null;
        if ($expectedHash !== null
            && (! is_string($providedHash) || ! hash_equals($expectedHash, $providedHash))) {
            return $this->intakeError(self::ERR_TASK_CONTRACT_HASH_MISMATCH, 422);
        }

        // 3) confirmation_token presence + verification status.
        $tokenPresent = ($context['token_present'] ?? null);
        if ($tokenPresent === null) {
            // Fall back to the body value when the caller did not pre-resolve it.
            $raw = $body['confirmation_token'] ?? null;
            $tokenPresent = is_string($raw) && $raw !== '';
        }
        if (! $tokenPresent) {
            return $this->intakeError(self::ERR_CONFIRMATION_TOKEN_MISSING, 403);
        }

        $tokenStatus = $context['token_status'] ?? 'valid';
        $errorByStatus = [
            'expired' => self::ERR_CONFIRMATION_TOKEN_EXPIRED,
            'consumed' => self::ERR_CONFIRMATION_TOKEN_ALREADY_CONSUMED,
            'contract_mismatch' => self::ERR_CONFIRMATION_TOKEN_CONTRACT_MISMATCH,
            'invalid' => self::ERR_CONFIRMATION_TOKEN_INVALID,
        ];
        if ($tokenStatus !== 'valid') {
            $code = $errorByStatus[$tokenStatus] ?? self::ERR_CONFIRMATION_TOKEN_INVALID;

            return $this->intakeError($code, 403);
        }

        return [
            'accepted' => true,
            'error' => null,
            'http_status' => 200,
            'stage' => self::STAGE_RUN,
        ];
    }

    /**
     * §15.1.4 — is a confirmation token valid for consumption?
     *
     *   valid only when: age_seconds <= ttl, the (run_id, task_contract_hash)
     *   binding matches the plan that issued it, and it has not been consumed.
     *
     * Returns a resolved token_status string consumable by validateRunIntake().
     *
     * @return array{valid:bool, token_status:string, reason:string, ttl_seconds:int}
     */
    public function evaluateConfirmationToken(
        int $ageSeconds,
        bool $bindingMatches,
        bool $alreadyConsumed,
        ?int $ttlSeconds = null,
    ): array {
        $ttl = $ttlSeconds ?? self::CONFIRMATION_TOKEN_TTL_SECONDS_DEFAULT;

        // Consumption is checked before expiry so a replayed-but-stale token is
        // reported as already_consumed (the single-use atomic mark wins).
        if ($alreadyConsumed) {
            return $this->tokenVerdict(false, 'consumed', 'already_consumed', $ttl);
        }
        if (! $bindingMatches) {
            return $this->tokenVerdict(false, 'contract_mismatch', 'binding_mismatch', $ttl);
        }
        if ($ageSeconds > $ttl) {
            return $this->tokenVerdict(false, 'expired', 'ttl_exceeded', $ttl);
        }

        return $this->tokenVerdict(true, 'valid', 'ok', $ttl);
    }

    /**
     * §15.1.2 — APP_KEY must decode to at least 32 bytes, else Plan/Run fail
     * closed with 500 ATLAS_DEV_KEY_MISSING.
     *
     * @return array{ok:bool, error:?string, http_status:int, decoded_bytes:int}
     */
    public function evaluateAppKey(?string $base64Key): array
    {
        $decoded = is_string($base64Key) && $base64Key !== ''
            ? (base64_decode($this->stripBase64Prefix($base64Key), true) ?: '')
            : '';
        $bytes = strlen($decoded);

        if ($bytes < self::APP_KEY_MIN_DECODED_BYTES) {
            return [
                'ok' => false,
                'error' => self::ERR_KEY_MISSING,
                'http_status' => 500,
                'decoded_bytes' => $bytes,
            ];
        }

        return [
            'ok' => true,
            'error' => null,
            'http_status' => 200,
            'decoded_bytes' => $bytes,
        ];
    }

    /**
     * §15.1.3 — resolve a stage gate. Plan is the zero-provider stage and may be
     * enabled first; Run additionally requires Plan to already be green. When a
     * flag is false the stage returns the canonical disabled envelope (503) with
     * no side effects.
     *
     * @return array{
     *   stage:string,
     *   enabled:bool,
     *   error:?string,
     *   http_status:int,
     *   side_effects:bool,
     *   reason:string
     * }
     */
    public function resolveStageGate(string $stage, bool $planEnabled, bool $runEnabled): array
    {
        if ($stage === self::STAGE_PLAN) {
            if (! $planEnabled) {
                return $this->gateEnvelope(self::STAGE_PLAN, false, self::ERR_PLAN_DISABLED, 503, 'plan_flag_off');
            }

            return $this->gateEnvelope(self::STAGE_PLAN, true, null, 200, 'plan_enabled');
        }

        if ($stage === self::STAGE_RUN) {
            if (! $runEnabled) {
                return $this->gateEnvelope(self::STAGE_RUN, false, self::ERR_RUN_DISABLED, 503, 'run_flag_off');
            }
            if (! $planEnabled) {
                // §15.1.3 safe ordering: Run only after Plan is green.
                return $this->gateEnvelope(self::STAGE_RUN, false, self::ERR_RUN_DISABLED, 503, 'run_requires_plan_first');
            }

            return $this->gateEnvelope(self::STAGE_RUN, true, null, 200, 'run_enabled');
        }

        return $this->gateEnvelope($stage, false, self::ERR_RUN_DISABLED, 503, 'unknown_stage');
    }

    /**
     * §15.1.7 (F-04) — redact a response for the wire: derive workspace_label
     * (basename), keep the provider-safe workspace_hash, and rewrite artifact
     * paths to relative receipts/<run_id>/<file> refs. Reports any absolute
     * /Users-style leak as a violation so callers can fail the contract.
     *
     * @param  list<string>  $artifactPaths  absolute or already-relative paths
     * @return array{
     *   workspace_label:string,
     *   workspace_hash:string,
     *   persisted_artifact_refs:list<string>,
     *   absolute_path_leak:bool,
     *   leaked:list<string>
     * }
     */
    public function redactHttpResponse(string $absoluteWorkspace, string $runId, array $artifactPaths, string $workspaceHash): array
    {
        $label = basename($absoluteWorkspace);

        $refs = [];
        foreach ($artifactPaths as $path) {
            $refs[] = $this->toRelativeReceiptRef($path, $runId);
        }

        $leaked = [];
        foreach ($refs as $ref) {
            if ($this->looksAbsolute($ref)) {
                $leaked[] = $ref;
            }
        }
        if ($this->looksAbsolute($label)) {
            $leaked[] = $label;
        }

        return [
            'workspace_label' => $label,
            'workspace_hash' => $workspaceHash,
            'persisted_artifact_refs' => $refs,
            'absolute_path_leak' => $leaked !== [],
            'leaked' => array_values($leaked),
        ];
    }

    /**
     * §15.1.9 — is an intake payload canonical for the flow identity?
     *   flow_id must be exactly 'atlas_dev'; flow_origin must be one of the two
     *   accepted origins; command_intent is optional.
     *
     * @param  array<string,mixed>  $payload
     * @return array{
     *   canonical:bool,
     *   flow_id:string,
     *   flow_origin:?string,
     *   command_intent:?string,
     *   violations:list<string>
     * }
     */
    public function validateFlowIdentity(array $payload): array
    {
        $violations = [];

        $flowId = isset($payload['flow_id']) && is_string($payload['flow_id'])
            ? $payload['flow_id']
            : '';
        if ($flowId !== self::FLOW_ID) {
            $violations[] = 'flow_id_must_be_atlas_dev';
        }

        $flowOrigin = isset($payload['flow_origin']) && is_string($payload['flow_origin'])
            ? $payload['flow_origin']
            : null;
        if ($flowOrigin === null || ! in_array($flowOrigin, self::ALLOWED_FLOW_ORIGINS, true)) {
            $violations[] = 'flow_origin_not_allowed';
        }

        $commandIntent = isset($payload['command_intent']) && is_string($payload['command_intent']) && $payload['command_intent'] !== ''
            ? $payload['command_intent']
            : null;

        return [
            'canonical' => $violations === [],
            'flow_id' => self::FLOW_ID,
            'flow_origin' => $flowOrigin,
            'command_intent' => $commandIntent,
            'violations' => $violations,
        ];
    }

    /**
     * Stable manifest of every documented invariant this decider enforces,
     * for the CLI surface and for cartography backlinks.
     *
     * @return array<string,mixed>
     */
    public function manifest(): array
    {
        return [
            'doc' => 'atlas-dev-efficient-programming-flow-runbook-v1-part-08',
            'slice' => '§12.2 PRs Sugeridos .. §15.1.9 Identidade do fluxo',
            'escalation_targets' => [self::TARGET_FORGE, self::TARGET_OBRA_CANDIDATE, self::TARGET_NONE],
            'escalation_thresholds' => [
                'forge' => self::THRESHOLD_FORGE,
                'obra_candidate' => self::THRESHOLD_OBRA,
                'force_forge_risk' => 'R'.self::FORCE_FORGE_RISK_INDEX,
            ],
            'run_intake_error_precedence' => [
                self::ERR_OPERATOR_NOT_CONFIRMED.'(400)',
                self::ERR_TASK_CONTRACT_HASH_MISMATCH.'(422)',
                'CONFIRMATION_TOKEN_*(403)',
            ],
            'gate_stages' => [self::STAGE_PLAN, self::STAGE_RUN],
            'confirmation_token_ttl_default_seconds' => self::CONFIRMATION_TOKEN_TTL_SECONDS_DEFAULT,
            'app_key_min_decoded_bytes' => self::APP_KEY_MIN_DECODED_BYTES,
            'flow_id' => self::FLOW_ID,
            'flow_origins' => self::ALLOWED_FLOW_ORIGINS,
            'path_redaction' => 'workspace_label(basename)+workspace_hash+relative receipts refs (F-04)',
            'backing_runtime' => [
                'App\\Services\\Ai\\Programming\\AtlasDev\\Escalation\\EscalationDecisionEngine',
                'App\\Services\\Ai\\Programming\\AtlasDev\\Escalation\\EscalationSignalScorer',
                'App\\Http\\Controllers\\AtlasDev\\RunController',
            ],
        ];
    }

    /** R-level string ("R0".."R5") -> numeric index, unknown -> 0. */
    private function riskIndex(string $riskLevel): int
    {
        if (! in_array($riskLevel, self::ALLOWED_RISK_LEVELS, true)) {
            return 0;
        }

        return (int) substr($riskLevel, 1);
    }

    /**
     * @return array{accepted:bool, error:?string, http_status:int, stage:string}
     */
    private function intakeError(string $code, int $status): array
    {
        return [
            'accepted' => false,
            'error' => $code,
            'http_status' => $status,
            'stage' => self::STAGE_RUN,
        ];
    }

    /**
     * @return array{valid:bool, token_status:string, reason:string, ttl_seconds:int}
     */
    private function tokenVerdict(bool $valid, string $status, string $reason, int $ttl): array
    {
        return [
            'valid' => $valid,
            'token_status' => $status,
            'reason' => $reason,
            'ttl_seconds' => $ttl,
        ];
    }

    /**
     * @return array{stage:string, enabled:bool, error:?string, http_status:int, side_effects:bool, reason:string}
     */
    private function gateEnvelope(string $stage, bool $enabled, ?string $error, int $status, string $reason): array
    {
        return [
            'stage' => $stage,
            'enabled' => $enabled,
            'error' => $error,
            'http_status' => $status,
            // A disabled gate has no side effects per §15.1.3.
            'side_effects' => $enabled,
            'reason' => $reason,
        ];
    }

    private function stripBase64Prefix(string $key): string
    {
        return str_starts_with($key, 'base64:') ? substr($key, 7) : $key;
    }

    private function toRelativeReceiptRef(string $path, string $runId): string
    {
        $normalized = str_replace('\\', '/', $path);

        // Already a relative receipts ref -> keep as-is.
        if (! $this->looksAbsolute($normalized) && str_starts_with($normalized, 'receipts/')) {
            return $normalized;
        }

        $file = basename($normalized);

        return 'receipts/'.$runId.'/'.$file;
    }

    private function looksAbsolute(string $value): bool
    {
        return str_starts_with($value, '/')
            || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $value);
    }
}
