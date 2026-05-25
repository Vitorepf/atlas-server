<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\Arms\AtlasForgeRivalsArmContractService;
use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
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
        private readonly AtlasForgeRivalsProviderArenaCorpusService $corpus,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function snapshot(array $input = []): array
    {
        $caseSet = trim((string) ($input['case_set'] ?? AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_CEILING_360));
        if ($caseSet === '') {
            $caseSet = AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_CEILING_360;
        }
        try {
            $cases = $this->corpus->casesForCaseSet($caseSet);
            $caseCount = count($cases);
            $caseSetBlockers = [];
        } catch (\Throwable $e) {
            $cases = [];
            $caseCount = 0;
            $caseSetBlockers = [$e->getMessage() !== '' ? $e->getMessage() : 'unknown_case_set:'.$caseSet];
        }

        $evidenceDisk = $this->evidenceDiskGuard->check($this->paths->rootDirectory().'/readiness-probe');
        $pairs = array_map(
            fn (array $pair): array => $this->pairReadiness($pair, $evidenceDisk, $caseSet, $caseSetBlockers),
            $this->canonicalPairs(),
        );

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'case_set' => $caseSet,
            'case_count' => $caseCount,
            'ceiling_360_matrix' => $caseSet === AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_CEILING_360,
            'pair_count' => count($pairs),
            'dry_run_ready_count' => count(array_filter($pairs, static fn (array $pair): bool => (bool) $pair['dry_run_ready'])),
            'real_run_ready_count' => count(array_filter($pairs, static fn (array $pair): bool => (bool) $pair['real_run_ready'])),
            'blocked_count' => count(array_filter($pairs, static fn (array $pair): bool => $pair['status'] === 'blocked')),
            'driver_missing_count' => count(array_filter($pairs, static fn (array $pair): bool => $pair['status'] === 'plan_ready_driver_missing')),
            'evidence_disk_blocked_count' => count(array_filter($pairs, static fn (array $pair): bool => $pair['status'] === 'plan_ready_evidence_disk_blocked')),
            'case_set_blockers' => $caseSetBlockers,
            'evidence_disk_status' => $this->projectEvidenceDiskStatus($evidenceDisk),
            'pairs' => $pairs,
            'execution_ladder' => $this->executionLadder($caseSet, $cases, $pairs),
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
     * @param  list<array<string,mixed>>  $cases
     * @param  list<array<string,mixed>>  $pairs
     * @return array<string,mixed>
     */
    private function executionLadder(string $caseSet, array $cases, array $pairs): array
    {
        $stages = [];
        $observedRuns = $this->observedRunsByPairAndCase();
        foreach ([
            'canary_8' => ['count' => 8, 'purpose' => 'first real high-difficulty smoke across every 360 battle pair'],
            'floor_24' => ['count' => 24, 'purpose' => 'minimum practical separation floor before interpreting capability deltas'],
            'full_120' => ['count' => 120, 'purpose' => 'complete ceiling sweep for release-trusted 360 analysis'],
        ] as $stageId => $stage) {
            $selected = array_slice($cases, 0, min((int) $stage['count'], count($cases)));
            $caseIds = array_values(array_map(
                static fn (array $case): string => (string) ($case['case_id'] ?? ''),
                $selected,
            ));
            $caseIds = array_values(array_filter($caseIds, static fn (string $caseId): bool => $caseId !== ''));
            $firstCaseId = $caseIds[0] ?? null;
            $readyPairs = array_values(array_filter($pairs, static fn (array $pair): bool => (bool) ($pair['dry_run_ready'] ?? false)));
            $coverage = $this->stageCoverage($caseIds, $readyPairs, $observedRuns);

            $stages[] = [
                'stage' => $stageId,
                'purpose' => $stage['purpose'],
                'case_set' => $caseSet,
                'case_count' => count($caseIds),
                'pair_count' => count($readyPairs),
                'estimated_real_runs' => count($caseIds) * count($readyPairs),
                'estimated_provider_invocations' => count($caseIds) * count($readyPairs) * 2,
                'observed_real_runs' => $coverage['observed_real_runs'],
                'replay_verified_runs' => $coverage['replay_verified_runs'],
                'missing_real_runs' => $coverage['missing_real_runs'],
                'completion_ratio' => $coverage['completion_ratio'],
                'coverage_status' => $coverage['coverage_status'],
                'observed_runs' => $coverage['observed_runs'],
                'missing_first_case_commands' => $coverage['missing_first_case_commands'],
                'case_ids' => $caseIds,
                'first_case_id' => $firstCaseId,
                'first_case_dry_run_commands' => $firstCaseId === null
                    ? []
                    : array_values(array_map(
                        fn (array $pair): string => $this->caseDryRunCommand($pair, $firstCaseId),
                        $readyPairs,
                    )),
                'first_case_real_commands' => $firstCaseId === null
                    ? []
                    : array_values(array_map(
                        fn (array $pair): string => $this->caseRealCommand($pair, $firstCaseId),
                        $readyPairs,
                    )),
                'requires_confirmations_for_real_run' => ['runbook_reviewed', 'provider_cost', 'real_provider_call'],
                'external_provider_call' => false,
                'provider_tokens_spent' => false,
                'advisory_only' => true,
                'routing_effect' => 'none',
            ];
        }

        return [
            'schema_version' => 'atlas.forge.rivals.ceiling_360_execution_ladder.v1',
            'status' => $cases === [] ? 'blocked' : 'ready',
            'case_set' => $caseSet,
            'stages' => $stages,
            'note' => 'Dry-run commands are safe; real commands require confirmations and spend provider tokens.',
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'advisory_only' => true,
            'routing_effect' => 'none',
        ];
    }

    /**
     * @param  list<string>  $caseIds
     * @param  list<array<string,mixed>>  $readyPairs
     * @param  array<string,list<array<string,mixed>>>  $observedRuns
     * @return array<string,mixed>
     */
    private function stageCoverage(array $caseIds, array $readyPairs, array $observedRuns): array
    {
        $required = count($caseIds) * count($readyPairs);
        $observed = [];
        $missingFirstCaseCommands = [];
        $replayVerified = 0;

        foreach ($caseIds as $caseId) {
            foreach ($readyPairs as $pair) {
                $pairId = (string) ($pair['pair_id'] ?? '');
                if ($pairId === '') {
                    continue;
                }
                $key = $pairId.'|'.$caseId;
                $runs = $observedRuns[$key] ?? [];
                if ($runs !== []) {
                    $first = $runs[0];
                    $observed[] = $first;
                    if ((bool) ($first['replay_passes'] ?? false)) {
                        $replayVerified++;
                    }

                    continue;
                }

                if ($caseId === ($caseIds[0] ?? null)) {
                    $missingFirstCaseCommands[] = $this->caseRealCommand($pair, $caseId);
                }
            }
        }

        $observedCount = count($observed);
        $completionRatio = $required > 0 ? round($observedCount / $required, 4) : 0.0;

        return [
            'observed_real_runs' => $observedCount,
            'replay_verified_runs' => $replayVerified,
            'missing_real_runs' => max(0, $required - $observedCount),
            'completion_ratio' => $completionRatio,
            'coverage_status' => match (true) {
                $required <= 0 => 'blocked_no_required_runs',
                $observedCount <= 0 => 'no_real_evidence_yet',
                $observedCount >= $required && $replayVerified >= $required => 'complete_replay_verified',
                $observedCount >= $required => 'complete_needs_replay_review',
                default => 'partial',
            },
            'observed_runs' => array_slice($observed, 0, 20),
            'missing_first_case_commands' => array_slice($missingFirstCaseCommands, 0, 20),
        ];
    }

    /**
     * @return array<string,list<array<string,mixed>>>
     */
    private function observedRunsByPairAndCase(): array
    {
        $root = $this->paths->rootDirectory();
        $manifestPaths = glob($root.'/*/evidence/manifest.json') ?: [];
        $observed = [];

        foreach ($manifestPaths as $manifestPath) {
            if (! is_string($manifestPath) || ! is_file($manifestPath)) {
                continue;
            }

            $manifest = json_decode((string) @file_get_contents($manifestPath), true);
            if (! is_array($manifest)) {
                continue;
            }

            $caseId = (string) ($manifest['case_id'] ?? '');
            if ($caseId === '' || ! str_starts_with($caseId, 'ceiling-360-')) {
                continue;
            }

            $pairId = $this->pairIdForManifest($manifest);
            if ($pairId === null) {
                continue;
            }

            $scorecardPath = dirname($manifestPath).'/scorecard.json';
            $scorecard = is_file($scorecardPath)
                ? json_decode((string) @file_get_contents($scorecardPath), true)
                : [];
            $scorecard = is_array($scorecard) ? $scorecard : [];

            $providerTokensSpent = (bool) ($manifest['provider_tokens_spent'] ?? false);
            $externalProviderCall = (bool) ($manifest['external_provider_call'] ?? false);
            if (! $externalProviderCall || ! $providerTokensSpent) {
                continue;
            }
            $hardFailures = (array) ($scorecard['hard_failures'] ?? []);
            $validEvidence = (string) ($manifest['verdict'] ?? '') === 'comparable'
                && (bool) ($scorecard['replay_passes'] ?? false)
                && $hardFailures === [];
            if (! $validEvidence) {
                continue;
            }

            $key = $pairId.'|'.$caseId;
            $observed[$key][] = [
                'run_id' => (string) ($manifest['run_id'] ?? basename(dirname(dirname($manifestPath)))),
                'pair_id' => $pairId,
                'case_id' => $caseId,
                'mode' => (string) ($manifest['mode'] ?? ''),
                'verdict' => (string) ($manifest['verdict'] ?? ''),
                'winner' => $scorecard['winner'] ?? null,
                'atlas_score' => $scorecard['atlas_score'] ?? null,
                'rival_score' => $scorecard['rival_score'] ?? null,
                'replay_passes' => (bool) ($scorecard['replay_passes'] ?? false),
                'hard_failures' => $hardFailures,
                'valid_evidence' => true,
                'claim_ready' => (bool) ($scorecard['claim_ready'] ?? false),
                'external_provider_call' => true,
                'provider_tokens_spent' => true,
            ];
        }

        foreach ($observed as &$runs) {
            usort($runs, static fn (array $a, array $b): int => strcmp((string) ($b['run_id'] ?? ''), (string) ($a['run_id'] ?? '')));
        }
        unset($runs);

        return $observed;
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    private function pairIdForManifest(array $manifest): ?string
    {
        $armA = (array) data_get($manifest, 'arena_contracts.arm_a', []);
        $armB = (array) data_get($manifest, 'arena_contracts.arm_b', []);
        $actual = [
            'arm_a' => (string) ($armA['arm_id'] ?? ''),
            'arm_a_model' => (string) ($armA['model_alias'] ?? $armA['requested_model'] ?? ''),
            'arm_b' => (string) ($armB['arm_id'] ?? ''),
            'arm_b_model' => (string) ($armB['model_alias'] ?? $armB['requested_model'] ?? ''),
            'mode' => (string) ($manifest['mode'] ?? ''),
        ];

        foreach ($this->canonicalPairs() as $pair) {
            if ($actual['arm_a'] === $pair['arm_a']
                && $this->modelAliasMatches($actual['arm_a_model'], $pair['arm_a_model'])
                && $actual['arm_b'] === $pair['arm_b']
                && $this->modelAliasMatches($actual['arm_b_model'], $pair['arm_b_model'])
                && $actual['mode'] === $pair['mode']) {
                return $pair['pair_id'];
            }
        }

        return null;
    }

    private function modelAliasMatches(string $actual, string $expected): bool
    {
        $actual = strtolower(trim($actual));
        $expected = strtolower(trim($expected));

        return $actual === $expected
            || $actual === str_replace('-', '_', $expected)
            || $actual === 'claude_'.$expected
            || $expected === 'claude_'.$actual;
    }

    /**
     * @return list<array<string,string>>
     */
    private function canonicalPairs(): array
    {
        return [
            [
                'pair_id' => 'atlas_dev_architecture_escalated_vs_claude_sonnet',
                'arm_a' => 'atlas_dev',
                'arm_a_model' => 'sonnet',
                'arm_b' => 'claude_code',
                'arm_b_model' => 'sonnet',
                'mode' => 'provider_arena',
                'task_category' => 'architecture',
                'purpose' => 'Atlas Dev Sonnet against provider-pure Claude Code Sonnet on architecture pressure; Atlas Dev may apply its internal escalation policy without changing the Rivals arm.',
            ],
            [
                'pair_id' => 'atlas_dev_vs_claude_sonnet',
                'arm_a' => 'atlas_dev',
                'arm_a_model' => 'sonnet',
                'arm_b' => 'claude_code',
                'arm_b_model' => 'sonnet',
                'mode' => 'fair',
                'task_category' => 'refactor',
                'purpose' => 'Atlas Dev Sonnet runtime against provider-pure Claude Code Sonnet baseline without switching to Forge.',
            ],
            [
                'pair_id' => 'atlas_dev_architecture_pressure_vs_claude_sonnet',
                'arm_a' => 'atlas_dev',
                'arm_a_model' => 'sonnet',
                'arm_b' => 'claude_code',
                'arm_b_model' => 'sonnet',
                'mode' => 'provider_arena',
                'task_category' => 'bugfix',
                'purpose' => 'Atlas Dev under pressure against provider-pure Claude Code Sonnet, preserving Atlas Dev as the Rivals arm.',
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
    private function pairReadiness(array $pair, array $evidenceDisk, string $caseSet, array $caseSetBlockers): array
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
        $allBlockers = array_values(array_unique(array_merge($allBlockers, $caseSetBlockers)));
        $contractBlocked = $blockers !== [];
        $caseSetBlocked = $caseSetBlockers !== [];
        $driverBlocked = $driverBlockers !== [];
        $evidenceDiskBlocked = ($evidenceDisk['status'] ?? null) !== 'ok';

        $status = match (true) {
            $contractBlocked || $caseSetBlocked => 'blocked',
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
            'case_set' => $caseSet,
            'dry_run_ready' => ! $contractBlocked && ! $caseSetBlocked,
            'real_run_ready' => ! $contractBlocked && ! $caseSetBlocked && ! $driverBlocked && ! $evidenceDiskBlocked,
            'blockers' => array_values(array_unique(array_merge($allBlockers, $realRunBlockers))),
            'evidence_disk_status' => $this->projectEvidenceDiskStatus($evidenceDisk),
            'required_confirmations_for_real_run' => ['runbook_reviewed', 'provider_cost', 'real_provider_call'],
            'arm_a' => $this->armSummary($contractA),
            'arm_b' => $this->armSummary($contractB),
            'command_plan' => $commandPlan,
            'dry_run_command' => $this->dryRunCommand($pair, $caseSet),
            'next_command' => $this->nextCommand($pair, $caseSet),
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'advisory_only' => true,
            'routing_effect' => 'none',
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
        if ($command !== [] && ($built['prompt_transport'] ?? 'argv') === 'argv') {
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
    private function dryRunCommand(array $pair, string $caseSet): string
    {
        return 'php artisan atlas:forge:rivals run-arena'
            .' --arm-a='.$pair['arm_a']
            .' --arm-a-model='.$pair['arm_a_model']
            .' --arm-b='.$pair['arm_b']
            .' --arm-b-model='.$pair['arm_b_model']
            .' --task-category='.$pair['task_category']
            .' --mode='.$pair['mode']
            .' --case-set='.$caseSet
            .' --prompt-mode=enterprise-change'
            .' --dry-run --json';
    }

    /**
     * @param  array<string,string|mixed>  $pair
     */
    private function caseDryRunCommand(array $pair, string $caseId): string
    {
        return $this->baseCaseCommand($pair, $caseId).' --dry-run --json';
    }

    /**
     * @param  array<string,string|mixed>  $pair
     */
    private function caseRealCommand(array $pair, string $caseId): string
    {
        return $this->baseCaseCommand($pair, $caseId)
            .' --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call --json';
    }

    /**
     * @param  array<string,string|mixed>  $pair
     */
    private function baseCaseCommand(array $pair, string $caseId): string
    {
        return 'php artisan atlas:forge:rivals run-arena'
            .' --arm-a='.(string) $pair['arm_a']['arm_id']
            .' --arm-a-model='.(string) $pair['arm_a']['model_alias']
            .' --arm-b='.(string) $pair['arm_b']['arm_id']
            .' --arm-b-model='.(string) $pair['arm_b']['model_alias']
            .' --task-category='.(string) $pair['task_category']
            .' --mode='.(string) $pair['mode']
            .' --case='.$caseId
            .' --prompt-mode=enterprise-change';
    }

    /**
     * @param  array<string,string>  $pair
     */
    private function nextCommand(array $pair, string $caseSet): string
    {
        return 'php artisan atlas:forge:rivals run-arena'
            .' --arm-a='.$pair['arm_a']
            .' --arm-a-model='.$pair['arm_a_model']
            .' --arm-b='.$pair['arm_b']
            .' --arm-b-model='.$pair['arm_b_model']
            .' --task-category='.$pair['task_category']
            .' --mode='.$pair['mode']
            .' --case-set='.$caseSet
            .' --prompt-mode=enterprise-change'
            .' --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call --json';
    }
}
