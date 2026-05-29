<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\Arms\AtlasForgeRivalsArmRegistryService;

/**
 * Atlas Forge Rivals · External Execution Preflight v1.
 *
 * Local-only preflight for the paid external benchmark step. It proves that
 * configured provider binaries are resolvable and that Provider Arena can
 * still produce execution plans. It never invokes a provider process.
 */
final class AtlasForgeRivalsExternalExecutionPreflightService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.external_execution_preflight.v1';

    public function __construct(
        private readonly AtlasForgeRivalsProviderModelRegistryService $models,
        private readonly AtlasForgeRivalsArmRegistryService $arms,
        private readonly AtlasForgeRivalsProviderArenaReadinessService $arenaReadiness,
        private readonly AtlasForgeRivalsRunPathResolver $paths,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function snapshot(array $input): array
    {
        $providers = $this->requiredProviders($input);
        $binaryChecks = [];
        $blockers = [];

        foreach ($providers as $provider) {
            $check = $this->providerBinaryCheck($provider);
            $binaryChecks[$provider] = $check;
            foreach ((array) ($check['blockers'] ?? []) as $blocker) {
                $blockers[] = (string) $blocker;
            }
        }

        $caseSet = trim((string) ($input['case_set'] ?? ''));
        $arena = $this->arenaReadiness->snapshot([
            'case_set' => $caseSet !== '' ? $caseSet : null,
        ]);
        foreach ((array) ($arena['case_set_blockers'] ?? []) as $blocker) {
            $blockers[] = 'arena_case_set:'.(string) $blocker;
        }

        $status = $blockers === [] ? 'ok' : 'blocked';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'readiness_status' => $status === 'ok'
                ? 'ready_for_human_cost_review_before_real_provider_execution'
                : 'blocked_before_real_provider_execution',
            'case_set' => $arena['case_set'] ?? null,
            'required_providers' => $providers,
            'provider_count' => count($providers),
            'provider_binary_checks' => $binaryChecks,
            'runner_bridge_contract' => $this->runnerBridgeContract($providers, $binaryChecks),
            'provider_binary_ready_count' => count(array_filter(
                $binaryChecks,
                static fn (array $check): bool => (bool) ($check['ok'] ?? false),
            )),
            'arena_readiness_summary' => [
                'schema_version' => $arena['schema_version'] ?? null,
                'status' => $arena['status'] ?? null,
                'case_set' => $arena['case_set'] ?? null,
                'case_count' => $arena['case_count'] ?? null,
                'pair_count' => $arena['pair_count'] ?? null,
                'dry_run_ready_count' => $arena['dry_run_ready_count'] ?? null,
                'real_run_ready_count' => $arena['real_run_ready_count'] ?? null,
                'blocked_count' => $arena['blocked_count'] ?? null,
                'driver_missing_count' => $arena['driver_missing_count'] ?? null,
                'evidence_disk_status' => $arena['evidence_disk_status'] ?? null,
            ],
            'blockers' => array_values(array_unique($blockers)),
            'ready_for_real_execution_with_confirmations' => $status === 'ok',
            'required_confirmations_before_any_real_command' => [
                'confirm_runbook_reviewed',
                'confirm_provider_cost',
                'confirm_real_provider_call',
            ],
            'runbook_commands' => [
                'arena_readiness' => 'php artisan atlas:forge:rivals arena-readiness --json',
                'statistical_repeat_dry_run' => 'php artisan atlas:forge:rivals statistical-repeat-dry-run --json',
                'external_evidence_readiness' => 'php artisan atlas:forge:rivals external-evidence-readiness --json',
            ],
            'real_execution_allowed_by_this_preflight' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'claim_ready' => false,
            'external_claim_allowed' => false,
            'score_or_claim_allowed' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'canonical_phrase' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
            'next_command' => $status === 'ok'
                ? 'review cost/runbook confirmations before running any real provider command'
                : 'fix provider binary or arena readiness blockers before any real provider command',
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return list<string>
     */
    private function requiredProviders(array $input): array
    {
        $raw = $input['provider'] ?? null;
        if (is_string($raw) && trim($raw) !== '') {
            return array_values(array_unique(array_filter(array_map(
                static fn (string $provider): string => strtolower(trim($provider)),
                preg_split('/[,|]/', $raw) ?: [],
            ))));
        }

        return ['claude', 'codex', 'gemini', 'cursor', 'composer'];
    }

    /**
     * @return array<string,mixed>
     */
    private function providerBinaryCheck(string $provider): array
    {
        $binary = $this->models->binaryForProvider($provider);
        if (! (bool) ($binary['ok'] ?? false)) {
            return [
                'ok' => false,
                'provider' => $provider,
                'binary' => $binary['binary'] ?? null,
                'binary_config_key' => $binary['binary_config_key'] ?? null,
                'resolved_binary_path' => null,
                'blockers' => (array) ($binary['blockers'] ?? ['provider_binary_missing:'.$provider]),
            ];
        }

        $configured = (string) ($binary['binary'] ?? '');
        $resolved = $this->resolveBinary($configured);
        $blockers = [];
        if ($resolved === null) {
            $blockers[] = 'provider_binary_not_found:'.$provider.':'.$configured;
        } elseif (! is_executable($resolved)) {
            $blockers[] = 'provider_binary_not_executable:'.$provider.':'.$resolved;
        }

        return [
            'ok' => $blockers === [],
            'provider' => $provider,
            'binary' => $configured,
            'binary_config_key' => $binary['binary_config_key'] ?? null,
            'resolved_binary_path' => $resolved,
            'search_strategy' => str_contains($configured, '/') ? 'configured_path' : 'PATH',
            'workspace_root' => $this->paths->rootDirectory(),
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  list<string>  $providers
     * @param  array<string,array<string,mixed>>  $binaryChecks
     * @return array<string,mixed>
     */
    private function runnerBridgeContract(array $providers, array $binaryChecks): array
    {
        $armsByProvider = [];
        foreach ($this->arms->all() as $armId => $arm) {
            if (! is_array($arm)) {
                continue;
            }
            $provider = strtolower(trim((string) ($arm['provider'] ?? '')));
            if ($provider === '') {
                continue;
            }
            $armsByProvider[$provider] ??= [];
            $armsByProvider[$provider][] = $this->armBridgeSummary((string) $armId, $arm);
        }

        $providerRows = [];
        foreach ($providers as $provider) {
            $provider = strtolower(trim($provider));
            $providerRows[$provider] = [
                'provider' => $provider,
                'execution_interface' => $this->executionInterface($provider),
                'binary_ready' => (bool) ($binaryChecks[$provider]['ok'] ?? false),
                'resolved_binary_path' => $binaryChecks[$provider]['resolved_binary_path'] ?? null,
                'command_builder_owner' => $this->commandBuilderOwner($provider),
                'deep_swe_compatibility_mode' => 'external_runner_plan_or_result_ingest',
                'rivals_spawns_provider_in_preflight' => false,
                'supported_arms' => $armsByProvider[$provider] ?? [],
                'supported_arm_count' => count($armsByProvider[$provider] ?? []),
            ];
        }

        return [
            'schema_version' => 'atlas.forge.rivals.external_runner_bridge_contract.v1',
            'status' => 'plan_only_no_provider_execution',
            'bridge_goal' => 'normalize CLI/API/runtime runners into the same evidence/replay/ledger contract before any paid run',
            'provider_interfaces' => $providerRows,
            'deep_swe_api_only_assumption' => 'not_required_by_rivals',
            'deep_swe_bridge_position' => 'DeepSWE/Harbor/Pier can stay external; Rivals can ingest results or plan CLI/API runners without pretending local dry-run is a benchmark claim.',
            'evidence_required_before_score' => [
                'provider_receipt',
                'stdout_stderr_tail',
                'patch_or_trajectory',
                'scorecard',
                'evidence_pack',
                'replay_green',
                'matrix_lock_green',
            ],
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
        ];
    }

    /**
     * @param  array<string,mixed>  $arm
     * @return array<string,mixed>
     */
    private function armBridgeSummary(string $armId, array $arm): array
    {
        return [
            'arm_id' => $armId,
            'runner_type' => $arm['runner_type'] ?? null,
            'execution_mode' => $arm['execution_mode'] ?? null,
            'status' => $arm['status'] ?? null,
            'requires_external_provider_call' => (bool) ($arm['requires_external_provider_call'] ?? false),
            'requires_cost_confirmation' => (bool) ($arm['requires_cost_confirmation'] ?? false),
            'supports_streaming' => (bool) ($arm['supports_streaming'] ?? false),
            'supports_replay' => (bool) ($arm['supports_replay'] ?? false),
            'supports_patch_diff' => (bool) ($arm['supports_patch_diff'] ?? false),
            'supports_test_log' => (bool) ($arm['supports_test_log'] ?? false),
        ];
    }

    private function executionInterface(string $provider): string
    {
        return match (strtolower(trim($provider))) {
            'claude', 'codex', 'gemini', 'cursor', 'composer' => 'cli_runner',
            default => 'unknown_runner_interface',
        };
    }

    private function commandBuilderOwner(string $provider): string
    {
        return match (strtolower(trim($provider))) {
            'claude' => 'AtlasForgeRivalsArmCommandBuilderService::claudeCommand',
            'codex' => 'AtlasForgeRivalsArmCommandBuilderService::codexCommand',
            'gemini' => 'AtlasForgeRivalsArmCommandBuilderService::geminiCommand',
            'cursor', 'composer' => 'AtlasForgeRivalsArmCommandBuilderService::cursorCommand',
            default => 'no_command_builder_registered',
        };
    }

    private function resolveBinary(string $binary): ?string
    {
        $binary = trim($binary);
        if ($binary === '') {
            return null;
        }

        if (str_contains($binary, '/')) {
            return file_exists($binary) ? $binary : null;
        }

        $path = (string) getenv('PATH');
        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            $dir = rtrim($dir);
            if ($dir === '') {
                continue;
            }
            $candidate = rtrim($dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$binary;
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
