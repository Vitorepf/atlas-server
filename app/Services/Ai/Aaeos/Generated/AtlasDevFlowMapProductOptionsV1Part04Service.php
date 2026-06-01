<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Dev Flow Map And Product Options v1 · Parte 4 — executable read-model
 * for the deterministic routing tables carved into this cartography recorte
 * ("Entrypoints ate Fair Claude").
 *
 * The doc is a focused excerpt that *describes* the already-shipped Atlas Dev
 * runtime ({@see \App\Services\Ai\Programming\AtlasDevRuntimeService} and
 * {@see \App\Services\Ai\Cli\AtlasCliProviderStrategyService}). Those classes
 * build payloads, hit workspace gates and call providers. This service does
 * NONE of that: it is a pure, deterministic mirror of the four routing tables
 * the recorte pins, so the documented contract can be asserted in CI without
 * standing up the full Dev runtime. Per the doc's "Regras para IA" it does not
 * promote any future product option / hypothesis into runtime — it only encodes
 * the tables the doc states as already-true.
 *
 * Documented rules this code enforces:
 *
 *   Task -> flow map ("Runtime De Payload" table):
 *     R1  — dev | plan | direct  => programming.dev
 *     R2  — review               => programming.review
 *     R3  — debug | repair       => programming.repair
 *     R4  — any other task       => unsupported (no silent default to dev)
 *
 *   Surface gating ("Aplica em payloads ... quando / Nao aplica quando"):
 *     R5  — applies only for atlas_app, atlas_desktop_ai,
 *           atlas_api_interaction, atlas_cli_dev, in mode=programming,
 *           with a resolved workspace.
 *     R6  — never applies for atlas_code (Forge owns that frontier), nor for
 *           non-programming modes, nor for unknown surfaces.
 *
 *   Provider alias normalization ("Provedores aceitos" table):
 *     R7  — claude|claude-cli => claude_cli; codex|codex-cli => codex_cli;
 *           gemini|gemini-cli => gemini_cli;
 *           conselho|council|ambos|claude-codex => claude_codex.
 *     R8  — in mode=dev, gemini_cli is blocked for write execution
 *           (read-only analysis only).
 *
 *   Fair Claude lock ("Fair Claude" block):
 *     R9  — any of --claude-only / --single-provider / --no-decide /
 *           --fallback-disabled locks provider=claude_cli, model=opus,
 *           disables Atlas Decide, forbids fallback/council, forbids
 *           codex/gemini, and requires the quality gate to be 'passed'
 *           (an 'unverified' gate does NOT count as pass).
 *
 * Non-goals: no DB, no provider calls, no payload mutation, no file writes.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-04.md
 */
final class AtlasDevFlowMapProductOptionsV1Part04Service
{
    /** Evidence schema id this read-model emits. */
    public const SCHEMA = 'atlas.aaeos.dev_flow_map_product_options_v1_part04.v1';

    public const FLOW_DEV = 'programming.dev';

    public const FLOW_REVIEW = 'programming.review';

    public const FLOW_REPAIR = 'programming.repair';

    /**
     * Task -> canonical flow, exactly as the recorte's payload-runtime table.
     *
     * @var array<string,string>
     */
    private const TASK_FLOW_MAP = [
        'dev' => self::FLOW_DEV,
        'plan' => self::FLOW_DEV,
        'direct' => self::FLOW_DEV,
        'review' => self::FLOW_REVIEW,
        'debug' => self::FLOW_REPAIR,
        'repair' => self::FLOW_REPAIR,
    ];

    /** Surfaces where the Dev runtime applies. */
    private const ATLAS_AI_SURFACES = [
        'atlas_app',
        'atlas_desktop_ai',
        'atlas_api_interaction',
        'atlas_cli_dev',
    ];

    /** Surface that is explicitly carved OUT (Forge owns its frontier). */
    private const FORGE_SURFACE = 'atlas_code';

    /**
     * Provider alias -> canonical provider, from the "Provedores aceitos" table.
     *
     * @var array<string,string>
     */
    private const PROVIDER_ALIASES = [
        'claude' => 'claude_cli',
        'claude-cli' => 'claude_cli',
        'codex' => 'codex_cli',
        'codex-cli' => 'codex_cli',
        'gemini' => 'gemini_cli',
        'gemini-cli' => 'gemini_cli',
        'conselho' => 'claude_codex',
        'council' => 'claude_codex',
        'ambos' => 'claude_codex',
        'claude-codex' => 'claude_codex',
    ];

    /** Flags that trigger the Fair Claude lock. */
    private const FAIR_CLAUDE_FLAGS = [
        '--claude-only',
        '--single-provider',
        '--no-decide',
        '--fallback-disabled',
    ];

    /**
     * Resolve a normalized task into its canonical flow.
     *
     * Returns supported=false for anything outside the table — the doc never
     * silently defaults an unknown task to programming.dev for this map.
     *
     * @return array{task:string,flow:?string,supported:bool}
     */
    public function resolveFlowForTask(string $task): array
    {
        $key = strtolower(trim($task));
        $flow = self::TASK_FLOW_MAP[$key] ?? null;

        return [
            'task' => $key,
            'flow' => $flow,
            'supported' => $flow !== null,
        ];
    }

    /**
     * Decide whether the Dev payload runtime applies, per the surface/mode/
     * workspace gate. Mirrors the doc's "Aplica quando / Nao aplica quando".
     *
     * @return array{surface:string,mode:string,workspace_resolved:bool,applies:bool,reason:string}
     */
    public function appliesToSurface(string $surface, string $mode, bool $workspaceResolved): array
    {
        $surfaceKey = strtolower(trim($surface));
        $modeKey = strtolower(trim($mode));

        $reason = 'applies';
        $applies = true;

        if ($surfaceKey === self::FORGE_SURFACE) {
            $applies = false;
            $reason = 'atlas_code_owned_by_forge';
        } elseif (! in_array($surfaceKey, self::ATLAS_AI_SURFACES, true)) {
            $applies = false;
            $reason = 'unknown_surface';
        } elseif ($modeKey !== 'programming') {
            $applies = false;
            $reason = 'mode_not_programming';
        } elseif (! $workspaceResolved) {
            $applies = false;
            $reason = 'workspace_missing';
        }

        return [
            'surface' => $surfaceKey,
            'mode' => $modeKey,
            'workspace_resolved' => $workspaceResolved,
            'applies' => $applies,
            'reason' => $reason,
        ];
    }

    /**
     * Normalize a provider alias to its canonical form and report whether the
     * canonical provider may perform write execution in the given mode.
     *
     * Per R8, gemini_cli is read-only in mode=dev.
     *
     * @return array{input:string,provider:?string,recognized:bool,write_allowed:bool,write_block_reason:?string}
     */
    public function normalizeProvider(string $input, string $mode = 'dev'): array
    {
        $key = strtolower(trim($input));
        $provider = self::PROVIDER_ALIASES[$key] ?? null;
        $modeKey = strtolower(trim($mode));

        $writeAllowed = $provider !== null;
        $writeBlockReason = null;

        if ($provider === 'gemini_cli' && $modeKey === 'dev') {
            $writeAllowed = false;
            $writeBlockReason = 'gemini_read_only_in_dev';
        }

        return [
            'input' => $key,
            'provider' => $provider,
            'recognized' => $provider !== null,
            'write_allowed' => $writeAllowed,
            'write_block_reason' => $writeBlockReason,
        ];
    }

    /**
     * Apply the Fair Claude lock for a given set of CLI flags.
     *
     * When any Fair Claude flag is present the lock engages: provider/model are
     * pinned, Atlas Decide is off, fallback/council and the other providers are
     * forbidden, and the quality gate must be exactly 'passed'.
     *
     * @param  list<string>  $flags
     * @return array{
     *     locked:bool,
     *     triggered_by:list<string>,
     *     provider_lock:?string,
     *     model_lock:?string,
     *     atlas_decide:bool,
     *     fallback_allowed:bool,
     *     council_allowed:bool,
     *     forbidden_providers:list<string>,
     *     quality_gate_required:string
     * }
     */
    public function fairClaudeLock(array $flags): array
    {
        $normalized = array_values(array_unique(array_map(
            static fn ($flag): string => strtolower(trim((string) $flag)),
            $flags
        )));

        $triggeredBy = array_values(array_intersect($normalized, self::FAIR_CLAUDE_FLAGS));
        $locked = $triggeredBy !== [];

        return [
            'locked' => $locked,
            'triggered_by' => $triggeredBy,
            'provider_lock' => $locked ? 'claude_cli' : null,
            'model_lock' => $locked ? 'opus' : null,
            'atlas_decide' => ! $locked,
            'fallback_allowed' => ! $locked,
            'council_allowed' => ! $locked,
            'forbidden_providers' => $locked ? ['codex_cli', 'gemini_cli', 'claude_codex'] : [],
            'quality_gate_required' => $locked ? 'passed' : 'any',
        ];
    }

    /**
     * Decide whether a quality-gate status satisfies the Fair Claude pass bar.
     * Only an exact 'passed' counts; 'unverified' explicitly does not.
     */
    public function fairClaudeGateSatisfied(string $gateStatus): bool
    {
        return strtolower(trim($gateStatus)) === 'passed';
    }

    /**
     * Full machine-readable snapshot of the recorte's tables, for evidence /
     * the --json command path.
     *
     * @return array<string,mixed>
     */
    public function describe(): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'doc' => 'atlas-dev-flow-map-and-product-options-v1-part-04',
            'task_flow_map' => self::TASK_FLOW_MAP,
            'atlas_ai_surfaces' => self::ATLAS_AI_SURFACES,
            'forge_surface' => self::FORGE_SURFACE,
            'provider_aliases' => self::PROVIDER_ALIASES,
            'gemini_write_block_mode' => 'dev',
            'fair_claude_flags' => self::FAIR_CLAUDE_FLAGS,
            'fair_claude_pass_status' => 'passed',
        ];
    }
}
