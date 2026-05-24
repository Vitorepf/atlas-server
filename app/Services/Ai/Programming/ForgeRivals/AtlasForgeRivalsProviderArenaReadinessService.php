<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\Arms\AtlasForgeRivalsArmContractService;
use Symfony\Component\Process\Process;

/**
 * Atlas Forge Rivals · Provider Arena Readiness v1.
 *
 * Local-only readiness matrix for the canonical enterprise arena pairings.
 * It resolves arms/models, builds redacted command plans and reports driver
 * blockers without invoking providers or spending tokens.
 */
final class AtlasForgeRivalsProviderArenaReadinessService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.provider_arena_readiness.v1';

    public function __construct(
        private readonly AtlasForgeRivalsArmContractService $contracts,
        private readonly AtlasForgeRivalsProviderModelRegistryService $models,
        private readonly AtlasForgeRivalsArmCommandBuilderService $commands,
        private readonly AtlasForgeRivalsProviderEvidenceDiskGuardService $evidenceDiskGuard,
        private readonly AtlasForgeRivalsRunPathResolver $paths,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function snapshot(array $input = []): array
    {
        $evidenceDisk = $this->evidenceDiskGuard->check($this->paths->rootDirectory().'/readiness-probe');
        $pairs = array_map(
            fn (array $pair): array => $this->pairReadiness($pair, $evidenceDisk),
            $this->canonicalPairs(),
        );

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'pair_count' => count($pairs),
            'dry_run_ready_count' => count(array_filter($pairs, static fn (array $pair): bool => (bool) $pair['dry_run_ready'])),
            'real_run_ready_count' => count(array_filter($pairs, static fn (array $pair): bool => (bool) $pair['real_run_ready'])),
            'blocked_count' => count(array_filter($pairs, static fn (array $pair): bool => $pair['status'] === 'blocked')),
            'driver_missing_count' => count(array_filter($pairs, static fn (array $pair): bool => $pair['status'] === 'plan_ready_driver_missing')),
            'evidence_disk_blocked_count' => count(array_filter($pairs, static fn (array $pair): bool => $pair['status'] === 'plan_ready_evidence_disk_blocked')),
            'evidence_disk_status' => $this->projectEvidenceDiskStatus($evidenceDisk),
            'pairs' => $pairs,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'required_confirmations_for_real_run' => ['runbook_reviewed', 'provider_cost', 'real_provider_call'],
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'note' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
            'next_command' => 'php artisan atlas:forge:rivals arena-readiness --json',
        ];
    }

    /**
     * @return list<array<string,string>>
     */
    private function canonicalPairs(): array
    {
        return [
            [
                'pair_id' => 'atlas_dev_vs_atlas_forge',
                'arm_a' => 'atlas_dev',
                'arm_a_model' => 'sonnet',
                'arm_b' => 'atlas_forge',
                'arm_b_model' => 'sonnet',
                'mode' => 'provider_arena',
                'task_category' => 'bugfix',
                'purpose' => 'Atlas lightweight dev loop against full Forge system.',
            ],
            [
                'pair_id' => 'claude_opus_vs_codex_gpt55',
                'arm_a' => 'claude_code',
                'arm_a_model' => 'opus',
                'arm_b' => 'codex_cli',
                'arm_b_model' => 'gpt-5.5',
                'mode' => 'provider_arena',
                'task_category' => 'bugfix',
                'purpose' => 'Claude Code Opus against Codex CLI premium model.',
            ],
            [
                'pair_id' => 'codex_gpt55_vs_gemini_pro',
                'arm_a' => 'codex_cli',
                'arm_a_model' => 'gpt-5.5',
                'arm_b' => 'gemini_cli',
                'arm_b_model' => 'gemini-pro',
                'mode' => 'provider_arena',
                'task_category' => 'architecture',
                'purpose' => 'Cross-provider Codex against Gemini once Gemini driver is configured.',
            ],
            [
                'pair_id' => 'cursor_default_vs_claude_sonnet',
                'arm_a' => 'cursor_cli',
                'arm_a_model' => 'default',
                'arm_b' => 'claude_code',
                'arm_b_model' => 'sonnet',
                'mode' => 'provider_arena',
                'task_category' => 'bugfix',
                'purpose' => 'Cursor CLI configured default against Claude Code Sonnet.',
            ],
            [
                'pair_id' => 'composer_2_5_vs_codex_gpt55',
                'arm_a' => 'composer_2_5',
                'arm_a_model' => 'default',
                'arm_b' => 'codex_cli',
                'arm_b_model' => 'gpt-5.5',
                'mode' => 'provider_arena',
                'task_category' => 'bugfix',
                'purpose' => 'Composer 2.5 runner surface against Codex CLI premium model.',
            ],
            [
                'pair_id' => 'claude_sonnet_vs_claude_opus',
                'arm_a' => 'claude_code',
                'arm_a_model' => 'sonnet',
                'arm_b' => 'claude_code',
                'arm_b_model' => 'opus',
                'mode' => 'provider_arena',
                'task_category' => 'tests',
                'purpose' => 'Same provider model arena for Sonnet versus Opus.',
            ],
            [
                'pair_id' => 'atlas_forge_full_power_vs_claude_opus',
                'arm_a' => 'atlas_forge',
                'arm_a_model' => 'opus',
                'arm_b' => 'claude_code',
                'arm_b_model' => 'opus',
                'mode' => 'full_power',
                'task_category' => 'architecture',
                'purpose' => 'Atlas full system against provider-pure Claude Opus baseline.',
            ],
        ];
    }

    /**
     * @param  array<string,string>  $pair
     * @return array<string,mixed>
     */
    private function pairReadiness(array $pair, array $evidenceDisk): array
    {
        $contractA = $this->contract('arm_a', $pair);
        $contractB = $this->contract('arm_b', $pair);
        $blockers = array_values(array_unique(array_merge(
            (array) $contractA['blockers'],
            (array) $contractB['blockers'],
            $this->fairnessBlockers($pair, $contractA, $contractB),
        )));

        $commandPlan = [];
        foreach (['arm_a' => $contractA, 'arm_b' => $contractB] as $role => $contract) {
            if ((array) $contract['blockers'] !== []) {
                continue;
            }

            $built = $this->commands->build(
                $contract,
                'Provider Arena readiness command preview. Do not execute from readiness output.',
                '<'.$role.'_worktree>'
            );
            $commandPlan[$role] = $this->redactedCommand($built);
            foreach ((array) ($built['blockers'] ?? []) as $blocker) {
                $blockers[] = $role.'_command_'.$blocker;
            }
        }

        $driverBlockers = array_merge(
            $this->driverBlockers('arm_a', $contractA),
            $this->driverBlockers('arm_b', $contractB),
        );
        $allBlockers = array_values(array_unique(array_merge($blockers, $driverBlockers)));
        $contractBlocked = $blockers !== [];
        $driverBlocked = $driverBlockers !== [];
        $evidenceDiskBlocked = ($evidenceDisk['status'] ?? null) !== 'ok';

        $status = match (true) {
            $contractBlocked => 'blocked',
            $driverBlocked => 'plan_ready_driver_missing',
            $evidenceDiskBlocked => 'plan_ready_evidence_disk_blocked',
            default => 'real_run_ready_after_confirmations',
        };
        $realRunBlockers = $evidenceDiskBlocked ? $this->stringList($evidenceDisk['blockers'] ?? []) : [];

        return [
            'pair_id' => $pair['pair_id'],
            'purpose' => $pair['purpose'],
            'status' => $status,
            'mode' => $pair['mode'],
            'task_category' => $pair['task_category'],
            'dry_run_ready' => ! $contractBlocked,
            'real_run_ready' => ! $contractBlocked && ! $driverBlocked && ! $evidenceDiskBlocked,
            'blockers' => array_values(array_unique(array_merge($allBlockers, $realRunBlockers))),
            'evidence_disk_status' => $this->projectEvidenceDiskStatus($evidenceDisk),
            'required_confirmations_for_real_run' => ['runbook_reviewed', 'provider_cost', 'real_provider_call'],
            'arm_a' => $this->armSummary($contractA),
            'arm_b' => $this->armSummary($contractB),
            'command_plan' => $commandPlan,
            'next_command' => $this->nextCommand($pair),
        ];
    }

    /**
     * @param  array<string,mixed>  $evidenceDisk
     * @return array<string,mixed>
     */
    private function projectEvidenceDiskStatus(array $evidenceDisk): array
    {
        return [
            'status' => $evidenceDisk['status'] ?? 'unknown',
            'path' => $evidenceDisk['path'] ?? null,
            'required_free_bytes' => $evidenceDisk['required_free_bytes'] ?? null,
            'free_bytes' => $evidenceDisk['free_bytes'] ?? null,
            'blockers' => $this->stringList($evidenceDisk['blockers'] ?? []),
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ];
    }

    /**
     * @param  array<string,string>  $pair
     * @return array<string,mixed>
     */
    private function contract(string $role, array $pair): array
    {
        return $this->contracts->contract($role, [
            'arm_id' => $pair[$role],
            'model' => $pair[$role.'_model'],
            'task_category' => $pair['task_category'],
            'mode' => $pair['mode'],
            'dry_run' => true,
        ]);
    }

    /**
     * @param  array<string,mixed>  $contractA
     * @param  array<string,mixed>  $contractB
     * @return list<string>
     */
    private function fairnessBlockers(array $pair, array $contractA, array $contractB): array
    {
        if ($pair['mode'] !== 'fair') {
            return [];
        }

        $blockers = [];
        $providerA = (string) ($contractA['provider'] ?? '');
        $providerB = (string) ($contractB['provider'] ?? '');
        if ($providerA !== $providerB) {
            $blockers[] = 'fair_mode_requires_same_provider_on_both_arms:'.$providerA.'_vs_'.$providerB;
        }
        $modelA = (string) ($contractA['resolved_model'] ?? '');
        $modelB = (string) ($contractB['resolved_model'] ?? '');
        if ($modelA !== $modelB) {
            $blockers[] = 'fair_mode_requires_same_model_on_both_arms:'.$modelA.'_vs_'.$modelB;
        }

        return $blockers;
    }

    /**
     * @param  array<string,mixed>  $contract
     * @return list<string>
     */
    private function driverBlockers(string $role, array $contract): array
    {
        if ((array) ($contract['blockers'] ?? []) !== []) {
            return [];
        }

        $provider = (string) ($contract['provider'] ?? '');
        if (in_array($provider, ['cursor', 'composer'], true)
            && (bool) config('atlas.ai.providers.cursor_cli.enabled', false) !== true
        ) {
            return ['provider_disabled_by_policy:'.$role.':'.$provider.':atlas.ai.providers.cursor_cli.enabled'];
        }

        $binary = $this->models->binaryForProvider($provider);
        if (! (bool) ($binary['ok'] ?? false)) {
            return array_values(array_map(
                static fn (string $blocker): string => $role.'_driver_'.$blocker,
                (array) ($binary['blockers'] ?? [])
            ));
        }

        $path = (string) ($binary['binary'] ?? '');
        if ($path === '' || ! $this->binaryExists($path)) {
            return ['provider_binary_not_available:'.$role.':'.$provider];
        }

        return [];
    }

    private function binaryExists(string $binary): bool
    {
        if (str_starts_with($binary, '/')) {
            return is_file($binary) && is_executable($binary);
        }

        $proc = Process::fromShellCommandline('which '.escapeshellarg($binary));
        $proc->setTimeout(5);
        $proc->run();

        return $proc->isSuccessful() && trim((string) $proc->getOutput()) !== '';
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $value,
        ), static fn (string $item): bool => $item !== ''));
    }

    /**
     * @param  array<string,mixed>  $contract
     * @return array<string,mixed>
     */
    private function armSummary(array $contract): array
    {
        return [
            'arm_id' => (string) data_get($contract, 'arm.arm_id', ''),
            'resolved_arm' => (string) data_get($contract, 'arm.arm_id', ''),
            'label' => (string) data_get($contract, 'arm.label', data_get($contract, 'arm.arm_id', '')),
            'provider' => (string) ($contract['provider'] ?? ''),
            'provider_kind' => $contract['provider_kind'] ?? null,
            'meta_provider' => (bool) ($contract['meta_provider'] ?? false),
            'meta_provider_parent' => $contract['meta_provider_parent'] ?? null,
            'provider_metadata' => (array) ($contract['provider_metadata'] ?? []),
            'model_alias' => $contract['requested_model'] ?? null,
            'resolved_model' => $contract['resolved_model'] ?? null,
            'resolved_model_id' => $contract['resolved_model_id'] ?? null,
            'resolved_model_label' => $contract['resolved_model_label'] ?? null,
            'runner_type' => (string) data_get($contract, 'arm.runner_type', ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $built
     * @return array<string,mixed>
     */
    private function redactedCommand(array $built): array
    {
        $command = (array) ($built['command'] ?? []);
        if ($command !== [] && ($built['prompt_transport'] ?? 'argv') !== 'stdin') {
            $command[count($command) - 1] = '<prompt>';
        }

        return [
            'ok' => (bool) ($built['ok'] ?? false),
            'provider' => $built['provider'] ?? null,
            'model' => $built['model'] ?? null,
            'model_id' => $built['model_id'] ?? null,
            'command_family' => $built['command_family'] ?? null,
            'prompt_transport' => $built['prompt_transport'] ?? 'argv',
            'stdin_prompt_hash' => $built['stdin_prompt_hash'] ?? null,
            'stdin_prompt_bytes' => $built['stdin_prompt_bytes'] ?? null,
            'command_shape_summary' => is_array($built['command_shape_summary'] ?? null)
                ? (array) $built['command_shape_summary']
                : null,
            'command' => array_values(array_map(static fn (mixed $part): string => (string) $part, $command)),
            'blockers' => (array) ($built['blockers'] ?? []),
        ];
    }

    /**
     * @param  array<string,string>  $pair
     */
    private function nextCommand(array $pair): string
    {
        return 'php artisan atlas:forge:rivals run-arena'
            .' --arm-a='.$pair['arm_a']
            .' --arm-a-model='.$pair['arm_a_model']
            .' --arm-b='.$pair['arm_b']
            .' --arm-b-model='.$pair['arm_b_model']
            .' --task-category='.$pair['task_category']
            .' --mode='.$pair['mode']
            .' --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call --json';
    }
}
