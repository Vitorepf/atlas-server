<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals\Arms;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsProviderModelRegistryService;

/**
 * Atlas Forge Rivals · Arm Registry (Provider Arena Core v2).
 *
 * Single source of truth for which runners can participate as `arm_a` or
 * `arm_b` in an arena run. Every arm declares:
 *
 *   - arm_id                              canonical id
 *   - runner_type                         forge | atlas_dev | cli_provider | scripted | manual | placeholder
 *   - provider                            claude | codex | gemini | null
 *   - model_options                       allowed shorthand or canonical model names
 *   - execution_mode                      forge_real_provider | cli_provider_real | scripted | manual | placeholder
 *   - requires_external_provider_call     bool
 *   - requires_cost_confirmation          bool
 *   - supports_streaming                  bool
 *   - supports_replay                     bool
 *   - supports_patch_diff                 bool
 *   - supports_test_log                   bool
 *   - allowed_task_categories             '*' (all) or explicit list
 *   - safety_contract                     immutable safety promises
 *   - status                              available | not_yet_executable | placeholder
 *
 * Provider Arena Core v2 does NOT execute every runner type yet —
 * scripted/manual/future_runner are declared (so UIs and audits
 * can see them) but the real-run path returns an honest
 * `arm_runner_not_yet_executable:<arm_id>` blocker. `atlas_forge`,
 * `atlas_dev`, `claude_code`, `codex_cli` and `gemini_cli` are executable
 * when their provider binary/policy is configured and the operator passes
 * the real-provider confirmations.
 *
 * Schema: atlas.forge.rivals.runner_registry.v1
 */
final class AtlasForgeRivalsArmRegistryService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.runner_registry.v1';

    public const ARM_ATLAS_FORGE = 'atlas_forge';

    public const ARM_ATLAS_DEV = 'atlas_dev';

    public const ARM_ATLAS_DEV_LIGHT = 'atlas_dev_light';

    public const ARM_CLAUDE_CODE = 'claude_code';

    public const ARM_CODEX_CLI = 'codex_cli';

    public const ARM_GEMINI_CLI = 'gemini_cli';

    public const ARM_SCRIPTED_RUNNER = 'scripted_runner';

    public const ARM_MANUAL_RUNNER = 'manual_runner';

    public const ARM_FUTURE_RUNNER = 'future_runner';

    /** @var list<string> */
    public const ARMS = [
        self::ARM_ATLAS_FORGE,
        self::ARM_ATLAS_DEV,
        self::ARM_CLAUDE_CODE,
        self::ARM_CODEX_CLI,
        self::ARM_GEMINI_CLI,
        self::ARM_SCRIPTED_RUNNER,
        self::ARM_MANUAL_RUNNER,
        self::ARM_FUTURE_RUNNER,
    ];

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_NOT_YET_EXECUTABLE = 'not_yet_executable';

    public const STATUS_PLACEHOLDER = 'placeholder';

    public const RUNNER_FORGE = 'forge';

    public const RUNNER_ATLAS_DEV = 'atlas_dev';

    public const RUNNER_CLI_PROVIDER = 'cli_provider';

    public const RUNNER_SCRIPTED = 'scripted';

    public const RUNNER_MANUAL = 'manual';

    public const RUNNER_PLACEHOLDER = 'placeholder';

    public function __construct(
        private readonly AtlasForgeRivalsProviderModelRegistryService $models,
    ) {}

    /** @var list<string> */
    public const TASK_CATEGORIES = [
        'frontend',
        'backend',
        'bugfix',
        'tests',
        'refactor',
        'architecture',
        'docs',
        'performance',
        'security',
    ];

    /**
     * @return list<string>
     */
    public function arms(): array
    {
        return self::ARMS;
    }

    /**
     * @return list<string>
     */
    public function taskCategories(): array
    {
        return self::TASK_CATEGORIES;
    }

    /**
     * @return array{
     *   arm_id:string,
     *   runner_type:string,
     *   provider:?string,
     *   model_options:list<string>,
     *   execution_mode:string,
     *   requires_external_provider_call:bool,
     *   requires_cost_confirmation:bool,
     *   supports_streaming:bool,
     *   supports_replay:bool,
     *   supports_patch_diff:bool,
     *   supports_test_log:bool,
     *   allowed_task_categories:list<string>,
     *   safety_contract:array<string,mixed>,
     *   status:string,
     *   not_executable_reason:?string,
     *   human_label:string,
     *   human_description:string
     * }
     */
    public function arm(string $armId): array
    {
        $id = strtolower(trim($armId));
        if ($id === self::ARM_ATLAS_DEV_LIGHT) {
            $id = self::ARM_ATLAS_DEV;
        }
        if (! in_array($id, self::ARMS, true)) {
            throw new \InvalidArgumentException(
                "Unknown arm_id: '{$armId}'. Supported: ".implode(', ', self::ARMS).'.'
            );
        }

        return match ($id) {
            self::ARM_ATLAS_FORGE => $this->atlasForge(),
            self::ARM_ATLAS_DEV => $this->atlasDev(),
            self::ARM_CLAUDE_CODE => $this->claudeCode(),
            self::ARM_CODEX_CLI => $this->codexCli(),
            self::ARM_GEMINI_CLI => $this->geminiCli(),
            self::ARM_SCRIPTED_RUNNER => $this->scriptedRunner(),
            self::ARM_MANUAL_RUNNER => $this->manualRunner(),
            self::ARM_FUTURE_RUNNER => $this->futureRunner(),
        };
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function all(): array
    {
        $out = [];
        foreach (self::ARMS as $armId) {
            $out[$armId] = $this->arm($armId);
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'arms' => $this->all(),
            'arm_count' => count(self::ARMS),
            'task_categories' => self::TASK_CATEGORIES,
            'task_category_count' => count(self::TASK_CATEGORIES),
            'generated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'model_registry_schema_version' => AtlasForgeRivalsProviderModelRegistryService::SCHEMA_VERSION,
            'note' => 'Provider Arena Core v2 — declared arms. Real execution is gated by per-arm status, model registry, command builder and the three operator confirmations.',
            'separated_from_external_rivals_certification' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function atlasForge(): array
    {
        return [
            'arm_id' => self::ARM_ATLAS_FORGE,
            'runner_type' => self::RUNNER_FORGE,
            'provider' => 'claude',
            'model_options' => $this->models->aliasesForProvider('claude'),
            'execution_mode' => 'forge_real_provider',
            'requires_external_provider_call' => true,
            'requires_cost_confirmation' => true,
            'supports_streaming' => true,
            'supports_replay' => true,
            'supports_patch_diff' => true,
            'supports_test_log' => true,
            'allowed_task_categories' => self::TASK_CATEGORIES,
            'safety_contract' => $this->safety(realProvider: true),
            'status' => self::STATUS_AVAILABLE,
            'not_executable_reason' => null,
            'human_label' => 'Atlas Forge',
            'human_description' => 'Atlas Forge wrapper around Claude (sonnet/opus). Honors scope, evidence, replay.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function atlasDev(): array
    {
        return [
            'arm_id' => self::ARM_ATLAS_DEV,
            'legacy_aliases' => [self::ARM_ATLAS_DEV_LIGHT],
            'runner_type' => self::RUNNER_ATLAS_DEV,
            'provider' => 'claude',
            'model_options' => ['sonnet', 'claude_sonnet', 'claude-sonnet'],
            'execution_mode' => 'atlas_dev_real_provider',
            'requires_external_provider_call' => true,
            'requires_cost_confirmation' => true,
            'supports_streaming' => true,
            'supports_replay' => true,
            'supports_patch_diff' => true,
            'supports_test_log' => true,
            'allowed_task_categories' => ['frontend', 'backend', 'bugfix', 'tests', 'refactor', 'docs'],
            'safety_contract' => $this->safety(realProvider: true) + [
                'escalates_to_forge_on_high_risk' => true,
                'forbids_enterprise_claim_without_forge_escalation' => true,
                'keeps_call_budget_low' => true,
            ],
            'status' => self::STATUS_AVAILABLE,
            'not_executable_reason' => null,
            'human_label' => 'Atlas Dev',
            'human_description' => 'Atlas Dev lightweight runner around Claude Sonnet: scoped context, short plan, patch, focused tests, simple verification, then escalation to Forge on risk/failure.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function claudeCode(): array
    {
        return [
            'arm_id' => self::ARM_CLAUDE_CODE,
            'runner_type' => self::RUNNER_CLI_PROVIDER,
            'provider' => 'claude',
            'model_options' => $this->models->aliasesForProvider('claude'),
            'execution_mode' => 'cli_provider_real',
            'requires_external_provider_call' => true,
            'requires_cost_confirmation' => true,
            'supports_streaming' => true,
            'supports_replay' => true,
            'supports_patch_diff' => true,
            'supports_test_log' => true,
            'allowed_task_categories' => self::TASK_CATEGORIES,
            'safety_contract' => $this->safety(realProvider: true),
            'status' => self::STATUS_AVAILABLE,
            'not_executable_reason' => null,
            'human_label' => 'Claude Code',
            'human_description' => 'Raw Claude Code CLI baseline (no Forge).',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function codexCli(): array
    {
        return [
            'arm_id' => self::ARM_CODEX_CLI,
            'runner_type' => self::RUNNER_CLI_PROVIDER,
            'provider' => 'codex',
            'model_options' => $this->models->aliasesForProvider('codex'),
            'execution_mode' => 'cli_provider_real',
            'requires_external_provider_call' => true,
            'requires_cost_confirmation' => true,
            'supports_streaming' => true,
            'supports_replay' => true,
            'supports_patch_diff' => true,
            'supports_test_log' => true,
            'allowed_task_categories' => self::TASK_CATEGORIES,
            'safety_contract' => $this->safety(realProvider: true),
            'status' => self::STATUS_AVAILABLE,
            'not_executable_reason' => null,
            'human_label' => 'Codex CLI',
            'human_description' => 'Raw codex CLI baseline. Honest blocker when binary missing.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function geminiCli(): array
    {
        return [
            'arm_id' => self::ARM_GEMINI_CLI,
            'runner_type' => self::RUNNER_CLI_PROVIDER,
            'provider' => 'gemini',
            'model_options' => $this->models->aliasesForProvider('gemini'),
            'execution_mode' => 'cli_provider_real',
            'requires_external_provider_call' => true,
            'requires_cost_confirmation' => true,
            'supports_streaming' => true,
            'supports_replay' => true,
            'supports_patch_diff' => true,
            'supports_test_log' => true,
            'allowed_task_categories' => self::TASK_CATEGORIES,
            'safety_contract' => $this->safety(realProvider: true),
            'status' => self::STATUS_AVAILABLE,
            'not_executable_reason' => null,
            'human_label' => 'Gemini CLI',
            'human_description' => 'Gemini baseline — declared. Real execution arrives in a future slice; blocks honestly today.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function scriptedRunner(): array
    {
        return [
            'arm_id' => self::ARM_SCRIPTED_RUNNER,
            'runner_type' => self::RUNNER_SCRIPTED,
            'provider' => null,
            'model_options' => [],
            'execution_mode' => 'scripted',
            'requires_external_provider_call' => false,
            'requires_cost_confirmation' => false,
            'supports_streaming' => false,
            'supports_replay' => true,
            'supports_patch_diff' => true,
            'supports_test_log' => true,
            'allowed_task_categories' => ['tests', 'refactor', 'bugfix', 'docs'],
            'safety_contract' => $this->safety(realProvider: false, scriptedOrManual: true),
            'status' => self::STATUS_NOT_YET_EXECUTABLE,
            'not_executable_reason' => 'scripted_runner_protocol_v1_pending',
            'human_label' => 'Scripted runner',
            'human_description' => 'Deterministic local script as a rival. Score still requires evidence pack — cannot forge a claim.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function manualRunner(): array
    {
        return [
            'arm_id' => self::ARM_MANUAL_RUNNER,
            'runner_type' => self::RUNNER_MANUAL,
            'provider' => null,
            'model_options' => [],
            'execution_mode' => 'manual',
            'requires_external_provider_call' => false,
            'requires_cost_confirmation' => false,
            'supports_streaming' => false,
            'supports_replay' => true,
            'supports_patch_diff' => true,
            'supports_test_log' => true,
            'allowed_task_categories' => self::TASK_CATEGORIES,
            'safety_contract' => $this->safety(realProvider: false, scriptedOrManual: true),
            'status' => self::STATUS_NOT_YET_EXECUTABLE,
            'not_executable_reason' => 'manual_runner_protocol_v1_pending',
            'human_label' => 'Manual runner',
            'human_description' => 'Human-in-the-loop arm. Operator submits the patch and evidence; adjudicator still owns the verdict.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function futureRunner(): array
    {
        return [
            'arm_id' => self::ARM_FUTURE_RUNNER,
            'runner_type' => self::RUNNER_PLACEHOLDER,
            'provider' => null,
            'model_options' => [],
            'execution_mode' => 'placeholder',
            'requires_external_provider_call' => false,
            'requires_cost_confirmation' => false,
            'supports_streaming' => false,
            'supports_replay' => false,
            'supports_patch_diff' => false,
            'supports_test_log' => false,
            'allowed_task_categories' => [],
            'safety_contract' => $this->safety(realProvider: false, scriptedOrManual: false, placeholder: true),
            'status' => self::STATUS_PLACEHOLDER,
            'not_executable_reason' => 'future_runner_is_placeholder_only',
            'human_label' => 'Future runner',
            'human_description' => 'Reserved id so registries/UIs can render a slot for upcoming runners without forging support.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function safety(bool $realProvider, bool $scriptedOrManual = false, bool $placeholder = false): array
    {
        return [
            'never_promotes_completion_claim' => true,
            'never_unlocks_external_rivals_certification' => true,
            'requires_three_confirmations_for_real_provider' => $realProvider,
            'max_score_without_evidence' => 0,
            'fails_closed_on_missing_driver' => true,
            'audit_trail_required' => ! $placeholder,
            'replay_required_before_winner' => true,
            'evidence_required_before_winner' => true,
            'scripted_or_manual_cannot_forge_score' => $scriptedOrManual,
            'placeholder_blocks_real_run' => $placeholder,
        ];
    }
}
