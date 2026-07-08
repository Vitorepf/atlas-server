<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use App\Services\Ai\SelfConstruction\Maestro\PacketEvolution\AtlasMaestroPacketSchemaDeprecationGate;
use App\Services\Ai\SelfConstruction\Maestro\PacketEvolution\AtlasMaestroPacketSchemaVersioning;
use App\Services\Ai\SelfConstruction\Support\HashesKsortedPayloadCanonically;
use App\Services\Ai\SelfConstruction\TaskServing\AtlasRefactorProofGate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use App\Services\Ai\SelfConstruction\Support\WriteSetOverlap;

/**
 * Builds a deterministic, dry-run task packet for a single agent inside the
 * Agent Control Plane Runtime Pilot Simulator. The packet captures every
 * field needed by future runtime dispatch (objective, scope, evidence,
 * lease, cost) without ever writing the ledger, claiming a packet, calling
 * a provider, dispatching work, or enabling self-programming.
 *
 * Read-only: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming, never writes the ledger.
 */
final class AgentControlPlaneTaskPacketBuilder
{
    use HashesKsortedPayloadCanonically;
    use RecursivelyKsortsArrays;

    public const SCHEMA_VERSION = AtlasMaestroPacketSchemaVersioning::CANONICAL_V1;

    public const MODE = 'read_only_agent_control_plane_task_packet_builder';

    /**
     * Canonical Atlas-native ownership contract baked into every task packet.
     * Single source of truth — `build()` reads from here so future packets
     * never re-declare a parallel literal.
     *
     * @return array<string,mixed>
     */
    /**
     * K1 (Obra #18) — the kit-order (Ordem) fields, normalized, or [] when the input is
     * NOT a kit-order (no kit field present). Additive by design: a non-kit packet gets
     * no kit keys, so K3's $isKitOrder stays false and its fail-safe-pass is unchanged;
     * a real kit-order now travels its fields to storage instead of being stripped, so
     * K3 (source linter) and K4 (conformance gate) evaluate a real Ordem.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private static function normalizeKitOrder(array $input): array
    {
        $glossary = [];
        foreach ((array) ($input['glossary'] ?? []) as $sigla => $path) {
            $s = trim((string) $sigla);
            if ($s !== '') {
                // An empty path is a real K3 deficiency (glossary_sigla_without_path) —
                // carried on purpose so the linter can refuse it at the source.
                $glossary[$s] = trim((string) $path);
            }
        }

        $frozenCallers = [];
        foreach ((array) ($input['frozen_callers'] ?? []) as $fc) {
            if (! is_array($fc)) {
                continue;
            }
            $caller = trim((string) ($fc['caller'] ?? ''));
            if ($caller !== '') {
                $frozenCallers[] = ['caller' => $caller, 'destination' => trim((string) ($fc['destination'] ?? ''))];
            }
        }

        $acceptanceTestRef = [];
        $atr = $input['acceptance_test_ref'] ?? null;
        if (is_array($atr) && trim((string) ($atr['path'] ?? '')) !== '') {
            $acceptanceTestRef = ['path' => trim((string) $atr['path']), 'hash' => trim((string) ($atr['hash'] ?? ''))];
        }

        $stopAndReturn = array_values(array_filter(array_map(
            fn ($v): string => trim((string) $v),
            (array) ($input['stop_and_return'] ?? [])
        ), fn (string $v): bool => $v !== ''));

        $baselineArtifact = [];
        $ba = $input['baseline_artifact'] ?? null;
        if (is_array($ba) && $ba !== []) {
            $baselineArtifact = $ba;
        }

        $out = [];
        if ($glossary !== []) {
            $out['glossary'] = $glossary;
        }
        if ($frozenCallers !== []) {
            $out['frozen_callers'] = $frozenCallers;
        }
        if ($acceptanceTestRef !== []) {
            $out['acceptance_test_ref'] = $acceptanceTestRef;
        }
        if ($stopAndReturn !== []) {
            $out['stop_and_return'] = $stopAndReturn;
        }
        if ($baselineArtifact !== []) {
            $out['baseline_artifact'] = $baselineArtifact;
        }

        return $out;
    }

    /**
     * The seam decision a heavy refactor must carry: every field named, every field
     * substantive. Returns the normalized spec or null when absent/incomplete —
     * an incomplete spec is treated exactly like no spec (never half-trusted).
     *
     * @param  array<string,mixed>  $spec
     * @return array<string,mixed>|null
     */
    public static function normalizeRefactorDesignSpec(array $spec): ?array
    {
        if ($spec === []) {
            return null;
        }
        $out = [];
        foreach (['problem', 'proposed_abstraction', 'rejected_alternative', 'risk', 'expected_delta'] as $key) {
            $value = trim((string) ($spec[$key] ?? ''));
            if (mb_strlen($value) < 10) {
                return null;
            }
            $out[$key] = $value;
        }
        $callers = array_values(array_filter(array_map(
            static fn ($c): string => trim((string) $c),
            (array) ($spec['callers'] ?? []),
        ), static fn (string $c): bool => $c !== ''));
        if ($callers === []) {
            return null;
        }
        $out['callers'] = $callers;

        return $out;
    }

    public static function defaultSimplicityContract(): array
    {
        return [
            'default_execution_topology' => 'shared_local_main_with_allowed_files',
            'default_worktree_or_sandbox' => false,
            'human_or_external_provider_dependency_allowed' => false,
            'operator_dependency_allowed' => false,
            'human_dependency_allowed' => false,
            'external_provider_dependency_allowed' => false,
            'final_runtime_owner' => 'atlas_native',
            'steady_state_runtime_owner' => 'atlas_server',
            'steady_state_requires_operator' => false,
            'steady_state_requires_human' => false,
            'steady_state_requires_external_provider' => false,
            'external_worker_role' => 'bootstrap_or_replaceable_muscle_only',
        ];
    }

    public const FORBIDDEN_AXES = [
        'app/Services/Ai/SelfImprovement/',
        'app/Services/Ai/Programming/',
        'app/Http/Controllers/AtlasCode',
        'routes/api.php',
        'atlas-desktop/',
        'forge/',
        'rivals/',
        'cartografia/',
        'voice/',
    ];

    private const RUNNABLE_PROOF_MARKERS = ['phpunit', 'artisan test', 'pytest', 'jest', 'rspec'];

    /**
     * Hard value contract (AC1/AC2) — opt-in via input['require_hard_value_contract'] = true.
     * The simulator's default dry-run packets stay backward compatible (AC3); only callers that
     * explicitly request the hard contract get the four new blocking checks below.
     */
    private const REQUIRE_HARD_VALUE_CONTRACT_KEY = 'require_hard_value_contract';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function build(array $input = []): array
    {
        $blockingReasons = [];
        $warnings = [];

        $objective = trim((string) ($input['objective'] ?? ''));
        if ($objective === '') {
            $blockingReasons[] = 'objective_missing';
        }

        $source = trim((string) ($input['source'] ?? 'operator_intake'));
        $operatorId = trim((string) ($input['operator_id'] ?? 'operator-unknown'));
        $parentRunId = trim((string) ($input['parent_run_id'] ?? ''));
        $continuationContext = (array) ($input['continuation_context'] ?? []);

        $rawScopeIn = (array) ($input['scope_in'] ?? []);
        $rawScopeOut = (array) ($input['scope_out'] ?? []);
        $rawAllowed = (array) ($input['allowed_files'] ?? []);
        $rawForbidden = (array) ($input['forbidden_files'] ?? []);
        $acceptance = array_values(array_filter(array_map(
            fn ($value): string => trim((string) $value),
            (array) ($input['acceptance_criteria'] ?? [])
        )));
        $requiredEvidence = array_values(array_filter(array_map(
            fn ($value): string => trim((string) $value),
            (array) ($input['required_evidence'] ?? [])
        )));

        $scopeIn = $this->normalizePaths($rawScopeIn);
        $scopeOut = $this->normalizePaths($rawScopeOut);
        $allowed = $this->normalizePaths($rawAllowed);
        $forbidden = $this->normalizePaths($rawForbidden);

        if ($scopeIn === [] && $allowed === []) {
            $blockingReasons[] = 'scope_empty';
        }

        $forbiddenInAllowed = WriteSetOverlap::collidingPaths($allowed, $forbidden); // A5/MF-12: prefix-aware dir-vs-file
        if ($forbiddenInAllowed !== []) {
            $blockingReasons[] = 'forbidden_files_inside_allowed_files';
        }

        // Reviewed-axis exception: a lead-reviewed spec entering through the autonomous-gov-
        // bootstrap source (stamped ONLY by AtlasTaskSeedGovLanesCommand) may name exact
        // FORBIDDEN_AXES prefixes to exempt. Any other source ignores this input entirely — the
        // autonomous originator, replenisher and brain stay byte-identically fenced. An entry
        // that is not an exact known axis prefix is silently ignored (fail-closed).
        $reviewedAxisExceptions = $source === 'autonomous-gov-bootstrap'
            ? array_values(array_intersect(
                array_map('strval', (array) ($input['reviewed_axis_exceptions'] ?? [])),
                self::FORBIDDEN_AXES,
            ))
            : [];

        $axisHits = [];
        $axisExceptionsGrantedFor = [];
        foreach ($allowed as $path) {
            foreach (self::FORBIDDEN_AXES as $axis) {
                if (! str_starts_with($path, $axis)) {
                    continue;
                }
                if (in_array($axis, $reviewedAxisExceptions, true)) {
                    $axisExceptionsGrantedFor[] = $axis;

                    continue;
                }
                $axisHits[] = ['path' => $path, 'forbidden_axis' => $axis];
            }
        }
        if ($axisHits !== []) {
            $blockingReasons[] = 'forbidden_axis_in_allowed_files';
        }
        $axisExceptionsGrantedFor = array_values(array_unique($axisExceptionsGrantedFor));
        sort($axisExceptionsGrantedFor);

        $requireHardValueContract = (bool) ($input[self::REQUIRE_HARD_VALUE_CONTRACT_KEY] ?? false);
        if ($requireHardValueContract) {
            $blockingReasons = array_merge($blockingReasons, $this->hardValueContractBlockingReasons(
                $allowed,
                $acceptance,
                $requiredEvidence,
                $input,
            ));
        }

        $riskLevel = strtolower(trim((string) ($input['risk_level'] ?? 'low')));
        if (! in_array($riskLevel, ['low', 'medium', 'high', 'critical'], true)) {
            $warnings[] = 'risk_level_unknown_defaulting_low';
            $riskLevel = 'low';
        }

        // REFACTOR DESIGN SPEC GATE — a HEAVY refactor (refactor-shaped objective over 3+
        // files) is design work first: the brain must have decided the seam BEFORE a worker
        // burns muscle (problem, real callers, proposed abstraction, rejected alternative,
        // risk, expected measurable delta). Warning by default; the operator flips
        // atlas_task_governance.refactor_design_spec_required to make it blocking. The spec
        // travels ON the packet so every stage worker sees the same seam decision.
        $refactorDesignSpec = self::normalizeRefactorDesignSpec((array) ($input['refactor_design_spec'] ?? []));
        // K1 (Obra #18) — carry the kit-order (Ordem) fields THROUGH the builder instead
        // of stripping them, so K3/K4 evaluate a real Ordem. [] for a non-kit packet.
        $kitOrder = self::normalizeKitOrder($input);
        $isHeavyRefactor = count($allowed) >= 3
            && AtlasRefactorProofGate::appliesTo($objective);
        if ($isHeavyRefactor && $refactorDesignSpec === null) {
            $required = function_exists('config')
                && (bool) config('atlas_task_governance.refactor_design_spec_required', false);
            if ($required) {
                $blockingReasons[] = 'heavy_refactor_requires_design_spec';
            } else {
                $warnings[] = 'heavy_refactor_without_design_spec';
            }
        }

        $maxRuntime = max(0, (int) ($input['max_runtime_seconds'] ?? 3600));
        $maxTokenBudget = max(0, (int) ($input['max_token_budget'] ?? 0));
        $workspacePolicy = (array) ($input['workspace_policy'] ?? []);
        $workspacePolicy = $this->normalizeWorkspacePolicy($workspacePolicy);

        $packetId = (string) ($input['task_packet_id'] ?? Str::uuid()->toString());

        $normalizedScope = [
            'scope_in' => $scopeIn,
            'scope_out' => $scopeOut,
            'allowed_files' => $allowed,
            'forbidden_files' => $forbidden,
            'forbidden_axes' => self::FORBIDDEN_AXES,
            'forbidden_in_allowed' => $forbiddenInAllowed,
            'forbidden_axis_hits' => $axisHits,
        ];

        $evidenceRequirements = [
            'required' => $requiredEvidence === [] ? [
                'task_packet_created',
                'claim_lease_simulated',
                'scope_lock_planned',
                'continuation_summary_planned',
                'cost_import_planned',
                'work_product_manifest_planned',
                'runtime_pilot_completed',
            ] : $requiredEvidence,
            'persist_allowed' => false,
            'persist_runtime_enabled' => false,
        ];

        $leaseRequirements = [
            'lease_required' => true,
            'lease_ttl_seconds' => (int) ($input['lease_ttl_seconds'] ?? 1800),
            'renewable' => true,
            'simulated_only' => true,
            'persistence_allowed' => false,
        ];

        $claimRequirements = [
            'claim_required' => true,
            'claim_persistence_allowed' => false,
            'simulated_only' => true,
        ];

        $rollbackRequirements = [
            'rollback_strategy' => (string) ($input['rollback_strategy'] ?? 'plan_only'),
            'rollback_persistence_allowed' => false,
            'rollback_runtime_enabled' => false,
        ];

        $killSwitchRequirements = [
            'kill_switch_required' => true,
            'kill_switch_arming_runtime_enabled' => false,
            'kill_switch_simulated_only' => true,
        ];

        $continuationRequirements = [
            'continuation_required' => true,
            'continuation_context_keys' => array_keys($continuationContext),
            'context_compaction_required' => true,
            'continuation_runtime_enabled' => false,
        ];

        $costBudgetRequirements = [
            'max_token_budget' => $maxTokenBudget,
            'max_runtime_seconds' => $maxRuntime,
            'token_spend_allowed' => false,
            'budget_runtime_enabled' => false,
        ];

        $status = $blockingReasons === [] ? 'planned' : 'blocked';

        $scopeHash = $this->stableHash($normalizedScope);
        $acceptanceHash = $this->stableHash([
            'acceptance' => $acceptance,
            'evidence' => $evidenceRequirements['required'],
        ]);

        // Semantic dedup keys: the anti-farm duplicate index (AtlasTaskFabricSemanticDuplicateIndex)
        // compares capability_key/target_family/acceptance_intent, but almost no caller supplies
        // them, so it runs blind on empty strings for most real packets. Derive all three
        // deterministically from what the packet already carries whenever the caller omits them;
        // a caller-supplied value always wins over derivation. Pure string work, no provider call.
        $metadata = [
            'capability_key' => $this->resolveSemanticKey(
                (string) ($input['capability_key'] ?? ''),
                fn (): string => $this->deriveCapabilityKey($objective, $allowed),
            ),
            'target_family' => $this->resolveSemanticKey(
                (string) ($input['target_family'] ?? ''),
                fn (): string => $this->deriveTargetFamily($allowed),
            ),
            'acceptance_intent' => $this->resolveSemanticKey(
                (string) ($input['acceptance_intent'] ?? ''),
                fn (): string => $this->deriveAcceptanceIntent($acceptance, $this->primaryTargetClassBasename($allowed)),
            ),
        ];

        $hardValueContract = null;
        if ($requireHardValueContract) {
            $hardValueContract = [
                'required' => true,
                'blocking_reasons' => $this->hardValueContractBlockingReasons($allowed, $acceptance, $requiredEvidence, $input),
                'has_implementation_file' => $this->hasImplementationFile($allowed),
                'has_test_file' => $this->hasTestFile($allowed),
                'has_runnable_proof' => $this->hasRunnableProof($acceptance, $requiredEvidence),
                'has_required_evidence' => $requiredEvidence !== [],
            ];
            $hardValueContract['satisfied'] = $hardValueContract['blocking_reasons'] === [];
        }

        $packet = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'task_packet_id' => $packetId,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'status' => $status,
            'objective' => $objective,
            'source' => $source,
            'operator_id' => $operatorId,
            'parent_run_id' => $parentRunId,
            'normalized_scope' => $normalizedScope,
            'metadata' => $metadata,
            'axis_exception_granted' => $axisExceptionsGrantedFor !== [] ? [
                'axes' => $axisExceptionsGrantedFor,
                'source' => $source,
            ] : null,
            'scope_hash' => $scopeHash,
            'acceptance_criteria' => $acceptance,
            'acceptance_hash' => $acceptanceHash,
            'evidence_requirements' => $evidenceRequirements,
            'hard_value_contract' => $hardValueContract,
            'risk_classification' => [
                'risk_level' => $riskLevel,
                'risk_axis_hits' => count($axisHits),
                'risk_forbidden_overlaps' => count($forbiddenInAllowed),
            ],
            'workspace_policy' => array_merge([
                'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
                'isolation' => 'shared_local_main_with_scope_lock',
                'auto_apply' => false,
            ], $workspacePolicy),
            'simplicity_contract' => self::defaultSimplicityContract(),
            'lease_requirements' => $leaseRequirements,
            'claim_requirements' => $claimRequirements,
            'rollback_requirements' => $rollbackRequirements,
            'kill_switch_requirements' => $killSwitchRequirements,
            'continuation_requirements' => $continuationRequirements,
            'cost_budget_requirements' => $costBudgetRequirements,
            'continuation_context' => $continuationContext,
            'dedup_target' => (string) ($input['dedup_target'] ?? $scopeHash),
            'expected_structural_leverage' => (float) ($input['expected_structural_leverage'] ?? 0.0),
            'refactor_design_spec' => $refactorDesignSpec,
            ...$kitOrder,
            'blocking_reasons' => $blockingReasons,
            'warnings' => $warnings,
            'read_only' => true,
            'runtime_disabled' => true,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'completion_claim_allowed' => false,
            'non_execution_guarantees' => [
                'task_packet_builder_does_not_start_codex',
                'task_packet_builder_does_not_call_codex_cli_or_app',
                'task_packet_builder_does_not_spawn_subprocess',
                'task_packet_builder_does_not_invoke_adapter',
                'task_packet_builder_does_not_call_provider',
                'task_packet_builder_does_not_dispatch_work',
                'task_packet_builder_does_not_spend_tokens',
                'task_packet_builder_does_not_enable_self_programming',
                'task_packet_builder_does_not_write_ledger',
                'task_packet_builder_does_not_mutate_pointer',
                'task_packet_builder_does_not_promote_completion_claim',
            ],
            'human_summary' => $status === 'planned'
                ? sprintf('Task packet %s planned for objective "%s" (%d allowed paths).', $packetId, Str::limit($objective, 80), count($allowed))
                : sprintf('Task packet %s blocked: %s.', $packetId, implode(', ', $blockingReasons)),
        ];

        $packet['task_packet_hash'] = $this->stableHash($this->normalizeForHash($packet));

        AtlasMaestroPacketSchemaDeprecationGate::assertServeable($packet);

        return $packet;
    }

    /**
     * AC1: hard value contract blocking reasons — only evaluated when
     * require_hard_value_contract = true was explicitly requested.
     *
     * @param  list<string>  $allowed
     * @param  list<string>  $acceptance
     * @param  list<string>  $requiredEvidence
     * @param  array<string,mixed>  $input
     * @return list<string>
     */
    private function hardValueContractBlockingReasons(
        array $allowed,
        array $acceptance,
        array $requiredEvidence,
        array $input,
    ): array {
        $reasons = [];

        $hasImplementationFile = $this->hasImplementationFile($allowed);
        $hasTestFile = $this->hasTestFile($allowed);

        if (! $hasImplementationFile) {
            $reasons[] = 'missing_implementation_file';
        }

        $hasRunnableProof = $this->hasRunnableProof($acceptance, $requiredEvidence);
        if (! $hasTestFile && ! $hasRunnableProof) {
            $reasons[] = 'missing_runnable_test_file';
        }

        // AC: runnable acceptance must name the concrete test path or filter, not a generic suite command.
        if ($hasRunnableProof) {
            $hasConcreteProof = false;
            foreach (array_merge($acceptance, $requiredEvidence) as $text) {
                $lower = strtolower($text);
                // Check for concrete test path or filter (not just "php artisan test" alone)
                if (str_contains($lower, 'tests/') || str_contains($lower, '--filter=')) {
                    $hasConcreteProof = true;
                    break;
                }
            }
            if (! $hasConcreteProof && ! $hasTestFile) {
                $reasons[] = 'runnable_proof_not_concrete';
            }
        }

        if ($requiredEvidence === []) {
            $reasons[] = 'missing_required_evidence';
        }

        $structuralValueRationale = trim((string) ($input['structural_value_rationale'] ?? ''));
        if ($structuralValueRationale === '') {
            $reasons[] = 'missing_structural_value_rationale';
        }

        return $reasons;
    }

    private function hasImplementationFile(array $allowed): bool
    {
        $isTestPath = static fn (string $path): bool => str_contains(strtolower($path), '/tests/')
            || str_ends_with(strtolower($path), 'test.php');

        foreach ($allowed as $path) {
            if (! $isTestPath($path)) {
                return true;
            }
        }

        return false;
    }

    private function hasTestFile(array $allowed): bool
    {
        $isTestPath = static fn (string $path): bool => str_contains(strtolower($path), '/tests/')
            || str_ends_with(strtolower($path), 'test.php');

        foreach ($allowed as $path) {
            if ($isTestPath($path)) {
                return true;
            }
        }

        return false;
    }

    private function hasRunnableProof(array $acceptance, array $requiredEvidence): bool
    {
        foreach (array_merge($acceptance, $requiredEvidence) as $text) {
            foreach (self::RUNNABLE_PROOF_MARKERS as $marker) {
                if (str_contains(strtolower($text), $marker)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Caller-supplied value always wins over derivation; derivation only runs (lazily, via the
     * closure) when the caller omitted the key.
     */
    private function resolveSemanticKey(string $callerValue, \Closure $derive): string
    {
        $trimmed = trim($callerValue);

        return $trimmed !== '' ? $trimmed : $derive();
    }

    private function isSemanticTestPath(string $path): bool
    {
        $normalized = strtolower(str_replace('\\', '/', $path));

        return str_contains($normalized, '/tests/')
            || str_starts_with($normalized, 'tests/')
            || str_ends_with($normalized, 'test.php');
    }

    /**
     * The primary target class basename: the first NON-test allowed file's basename (without
     * extension), falling back to the first allowed file at all when every entry is a test path.
     *
     * @param  list<string>  $allowed
     */
    private function primaryTargetClassBasename(array $allowed): string
    {
        foreach ($allowed as $path) {
            if (! $this->isSemanticTestPath($path)) {
                return $this->basenameWithoutExtension($path);
            }
        }

        return $allowed !== [] ? $this->basenameWithoutExtension($allowed[0]) : '';
    }

    private function basenameWithoutExtension(string $path): string
    {
        $base = basename($path);

        return preg_replace('/\.php$/i', '', $base) ?? $base;
    }

    /**
     * capability_key = the objective's leading verb + the primary target class basename,
     * lowercased and joined — e.g. "Fix AtlasFooBar.php" + allowed_files=[".../AtlasFooBar.php"]
     * → "fix_atlasfoobar".
     *
     * @param  list<string>  $allowed
     */
    private function deriveCapabilityKey(string $objective, array $allowed): string
    {
        $verb = '';
        if (preg_match('/^\s*([A-Za-z]+)/', $objective, $matches) === 1) {
            $verb = strtolower($matches[1]);
        }
        $target = strtolower($this->primaryTargetClassBasename($allowed));

        return implode('_', array_filter([$verb, $target], static fn (string $part): bool => $part !== ''));
    }

    /**
     * target_family = the deepest shared directory of the non-test allowed_files (falling back
     * to ALL allowed_files when every entry is a test path).
     *
     * @param  list<string>  $allowed
     */
    private function deriveTargetFamily(array $allowed): string
    {
        $nonTest = array_values(array_filter($allowed, fn (string $p): bool => ! $this->isSemanticTestPath($p)));
        $candidates = $nonTest !== [] ? $nonTest : $allowed;
        if ($candidates === []) {
            return '';
        }

        $segmentLists = array_map(static function (string $path): array {
            $dir = trim(dirname(str_replace('\\', '/', $path)), '/');

            return $dir === '.' ? [] : explode('/', $dir);
        }, $candidates);

        $common = $segmentLists[0];
        foreach (array_slice($segmentLists, 1) as $segments) {
            $max = min(count($common), count($segments));
            $i = 0;
            while ($i < $max && $common[$i] === $segments[$i]) {
                $i++;
            }
            $common = array_slice($common, 0, $i);
        }

        return implode('/', $common);
    }

    /** Boilerplate phrases stripped from an acceptance criterion to isolate its actual intent. */
    private const ACCEPTANCE_INTENT_BOILERPLATE_PATTERNS = [
        '/php artisan test\s*(--filter=)?/i',
        '/\.\/vendor\/bin\/phpunit\s*(--filter=)?/i',
        '/composer test\s*(--\s*)?(--filter=)?/i',
        '/exits?\s*0/i',
        '/exit_code\s*=\s*0/i',
        '/passes?\s*green/i',
    ];

    /**
     * acceptance_intent = the first acceptance criterion with the primary target's class tokens
     * (e.g. "AtlasFooBar" and "AtlasFooBarTest") and the canonical runnable-proof phrases
     * (e.g. "php artisan test --filter=", "exits 0") stripped out.
     *
     * @param  list<string>  $acceptance
     */
    private function deriveAcceptanceIntent(array $acceptance, string $primaryTarget): string
    {
        if ($acceptance === []) {
            return '';
        }

        $stripped = (string) $acceptance[0];
        if ($primaryTarget !== '') {
            $stripped = preg_replace('/\b'.preg_quote($primaryTarget, '/').'(Test)?\b/i', '', $stripped) ?? $stripped;
        }
        foreach (self::ACCEPTANCE_INTENT_BOILERPLATE_PATTERNS as $pattern) {
            $stripped = preg_replace($pattern, '', $stripped) ?? $stripped;
        }

        $stripped = trim(preg_replace('/\s+/', ' ', $stripped) ?? $stripped);

        // A placeholder criterion (e.g. "ok", "pass", "done") carries no real semantic signal —
        // treating it as a meaningful intent would make the duplicate index match packets that
        // merely share a generic placeholder and a directory, never actual duplicate work. Below
        // this floor, no intent is derived (empty never matches in the duplicate index).
        $wordCount = $stripped === '' ? 0 : count(preg_split('/\s+/', $stripped) ?: []);
        if ($wordCount < 2 || strlen($stripped) < 8) {
            return '';
        }

        return $stripped;
    }

    /**
     * @param  array<int|string, mixed>  $paths
     * @return list<string>
     */
    private function normalizePaths(array $paths): array
    {
        $normalized = [];
        foreach ($paths as $path) {
            $value = trim((string) $path);
            if ($value === '') {
                continue;
            }
            $value = str_replace('\\', '/', $value);
            $value = preg_replace('#/{2,}#', '/', $value) ?? $value;
            $value = ltrim($value, '/');
            $normalized[] = $value;
        }
        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $packet
     * @return array<string, mixed>
     */
    private function normalizeForHash(array $packet): array
    {
        $clone = $packet;
        unset($clone['task_packet_id'], $clone['generated_at'], $clone['task_packet_hash'], $clone['human_summary']);

        return $this->recursivelyKsort($clone);
    }

    /**
     * @param  array<string,mixed>  $workspacePolicy
     * @return array<string,mixed>
     */
    private function normalizeWorkspacePolicy(array $workspacePolicy): array
    {
        $isolation = strtolower(trim((string) ($workspacePolicy['isolation'] ?? '')));
        $exceptional = (bool) ($workspacePolicy['exceptional_isolation_required'] ?? false);
        $legacyOrDefaultWorktree = in_array($isolation, [
            'simulated_worktree',
            'simulated_worktree_per_packet',
            'isolated',
            'isolated_worktree',
            'isolated_worktree_required',
            'worktree',
            'worktree_required',
            'sandbox',
            'sandbox_required',
        ], true);

        if ($legacyOrDefaultWorktree && ! $exceptional) {
            $workspacePolicy['isolation'] = 'shared_local_main_with_scope_lock';
            $workspacePolicy['legacy_isolation_normalized_from'] = $isolation;
        }

        return $workspacePolicy;
    }
}
