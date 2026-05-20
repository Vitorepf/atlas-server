<?php

namespace App\Services\Ai\Cli;

use App\Models\AiTrace;
use App\Services\Ai\AtlasDecideService;
use App\Services\Ai\FairClaudePolicy;
use App\Services\Ai\Programming\ProgrammingIterationPolicy;
use App\Support\AtlasPhpBinary;
use Illuminate\Support\Str;

class AtlasCliDevWorkflowService
{
    public function __construct(
        private readonly AtlasCliProviderStrategyService $providers,
        private readonly AtlasCliQualityService $quality,
        private readonly AtlasDecideService $decide,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function preflight(
        string $workspace,
        string $task,
        ?string $provider = null,
        bool $critical = false,
        string $programmingProfile = 'dev',
        bool $fairMode = false,
    ): array {
        $programmingProfile = $programmingProfile === 'forge' ? 'forge' : 'dev';
        $workspace = realpath($workspace) ?: $workspace;
        $strategy = $this->providers->recommend('dev', $critical);
        $selectedProvider = $fairMode ? FairClaudePolicy::PROVIDER_LOCK : $provider;
        $decisionOptions = $this->decide->normalizeOptions(array_filter([
            'provider' => $selectedProvider,
            'input_text' => $task,
            'source_type' => 'manual',
            'payload' => [
                'app_surface' => 'atlas_cli',
                'atlas_workflow_mode' => 'dev',
                'decision_mode' => $fairMode ? 'fair_mode_disabled' : ($selectedProvider ? 'manual_override' : 'atlas_decide'),
                'operator_requested_provider' => $selectedProvider ?: 'auto',
                'requested_provider' => $selectedProvider,
                'programming_profile' => $programmingProfile,
                'dev_execution_plan' => [
                    'programming_profile' => $programmingProfile,
                ],
            ],
        ], fn (mixed $value): bool => $value !== null));
        $decisionPayload = $fairMode
            ? $this->fairModeOperationalDecision($decisionOptions, $selectedProvider ?: FairClaudePolicy::PROVIDER_LOCK)
            : $this->decide->operationalDecision($decisionOptions)->toArray();
        $selectedProvider = $selectedProvider ?: (string) data_get($decisionPayload, 'selected_provider', FairClaudePolicy::PROVIDER_LOCK);
        $quality = $this->quality->evaluate($workspace);

        return [
            'workspace' => $workspace,
            'task' => $task,
            'selected_provider' => $selectedProvider,
            'programming_profile' => $programmingProfile,
            'provider_strategy' => $strategy,
            'operational_decision' => $decisionPayload,
            'policy_profile_id' => $fairMode ? null : data_get($decisionPayload, 'policy_profile_id'),
            'preflight_quality' => $this->quality->compact($quality),
            'can_execute_provider' => (bool) ($strategy['has_online_provider'] ?? false) || $provider !== null,
            'requires_override' => ! (bool) ($strategy['has_online_provider'] ?? false) && $provider === null,
        ];
    }

    /**
     * @param  array<string,mixed>  $decisionOptions
     * @return array<string,mixed>
     */
    private function fairModeOperationalDecision(array $decisionOptions, string $provider): array
    {
        return [
            'decision_id' => null,
            'policy_profile_id' => null,
            'policy_version' => 'fair-claude-v1',
            'decision_policy_version' => 'fair-claude-v1',
            'decision_mode' => 'fair_mode_disabled',
            'selected_provider' => FairClaudePolicy::PROVIDER_LOCK,
            'candidate_provider' => FairClaudePolicy::PROVIDER_LOCK,
            'fallback_provider' => null,
            'fallback_reason' => null,
            'operator_requested_provider' => FairClaudePolicy::PROVIDER_LOCK,
            'requested_provider' => FairClaudePolicy::PROVIDER_LOCK,
            'atlas_decide_disabled_by_fair_mode' => true,
            'planned_graph' => [
                'activation_status' => 'fair_mode_single_provider',
                'selected_provider' => $provider,
                'fallback_disabled' => true,
                'council_disabled' => true,
            ],
            'runtime_graph' => [
                'activation_status' => 'fair_mode_single_provider',
                'selected_provider' => $provider,
                'fallback_disabled' => true,
                'council_disabled' => true,
            ],
            'input_hash' => hash('sha256', (string) ($decisionOptions['input_text'] ?? '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function executionPlan(
        string $workspace,
        string $task,
        ?string $provider,
        int $maxIterations = 3,
        string $mode = 'multi_step',
    ): array {
        $workspace = realpath($workspace) ?: $workspace;

        return [
            'plan_id' => (string) Str::orderedUuid(),
            'objective' => $task,
            'workspace' => $workspace,
            'selected_provider' => $provider,
            'mode' => $mode,
            'phases' => ['inspect', 'plan', 'edit', 'test', 'repair', 'review', 'finish'],
            'current_phase' => 'inspect',
            'steps' => [],
            'iterations' => [
                'current' => 0,
                'max' => ProgrammingIterationPolicy::normalize($maxIterations),
                'reason_if_stopped' => null,
            ],
            'checkpoints' => [],
            'created_at' => now()->toJSON(),
            'updated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<int,string>  $requestedSkills
     * @return array<int,string>
     */
    public function qualityGateSkills(array $requestedSkills, bool $complete, int $maxIterations): array
    {
        $skills = collect($requestedSkills)
            ->filter(fn (mixed $skill): bool => is_scalar($skill) && trim((string) $skill) !== '')
            ->map(fn (mixed $skill): string => Str::of((string) $skill)->lower()->trim()->value())
            ->values();

        if ($complete || $maxIterations > 1) {
            $skills->push('dev-quality-gate');
        }

        return $skills->unique()->values()->all();
    }

    /**
     * @param  array<int,string>  $requestedSkills
     * @return array<int,string>
     */
    public function engineeringContractSkills(array $requestedSkills): array
    {
        return collect($requestedSkills)
            ->filter(fn (mixed $skill): bool => is_scalar($skill) && trim((string) $skill) !== '')
            ->map(fn (mixed $skill): string => Str::of((string) $skill)->lower()->trim()->value())
            ->push('engineering-blueprint')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int,string>  $skills
     * @return array<string,mixed>
     */
    public function qualityGatePolicy(bool $complete, int $maxIterations, array $skills, bool $fairMode = false): array
    {
        $policy = [
            'complete_mode' => $complete,
            'max_iterations' => ProgrammingIterationPolicy::normalize($maxIterations),
            'required_final_status' => ($complete || $fairMode) ? 'passed' : 'not_failed',
            'auto_skills' => array_values(array_intersect($skills, ['dev-quality-gate'])),
            'procedure' => 'plan_validate_execute',
        ];

        if ($fairMode) {
            $policy['fair_mode'] = true;
            $policy['deterministic_gate_required'] = true;
            $policy['unverified_counts_as_passed'] = false;
            $policy['pass_without_human_requires'] = [
                'provider_lock',
                'model_lock',
                'deterministic_gates_passed',
                'human_intervention_count_zero',
            ];
        }

        return $policy;
    }

    /**
     * @param  array<string,mixed>  $completion
     * @return array<string,mixed>
     */
    public function fairClaudeProtocolStatus(array $completion, bool $providerOk, int $humanInterventionCount = 0): array
    {
        $qualityStatus = (string) ($completion['status'] ?? data_get($completion, 'completion_packet.status', 'unknown'));
        $gates = collect((array) data_get($completion, 'quality_gates', data_get($completion, 'completion_packet.quality_gates', [])))
            ->filter(fn (mixed $gate): bool => is_array($gate))
            ->values();

        $gateStatuses = $gates
            ->map(fn (array $gate): string => (string) ($gate['status'] ?? 'unknown'))
            ->values();

        $deterministicGatesPassed = $gates->isNotEmpty()
            && $gateStatuses->every(fn (string $status): bool => $status === 'passed');

        $protocolStatus = 'unverified';
        if (! $providerOk || $qualityStatus === 'failed' || $gateStatuses->contains('failed')) {
            $protocolStatus = 'failed';
        } elseif ($qualityStatus === 'passed' && $deterministicGatesPassed) {
            $protocolStatus = 'valid';
        }

        $blockingReasons = [];
        if (! $providerOk) {
            $blockingReasons[] = 'provider_run_failed';
        }
        if ($qualityStatus !== 'passed') {
            $blockingReasons[] = 'quality_status_'.$qualityStatus;
        }
        if (! $deterministicGatesPassed) {
            $blockingReasons[] = $gates->isEmpty()
                ? 'deterministic_gates_missing'
                : 'deterministic_gates_not_passed';
        }
        if ($humanInterventionCount !== 0) {
            $blockingReasons[] = 'human_intervention_present';
        }

        return [
            'status' => $protocolStatus,
            'quality_status' => $qualityStatus,
            'deterministic_gates_passed' => $deterministicGatesPassed,
            'human_intervention_count' => $humanInterventionCount,
            'pass_without_human' => $protocolStatus === 'valid' && $humanInterventionCount === 0,
            'unverified_counts_as_passed' => false,
            'blocking_reasons' => array_values(array_unique($blockingReasons)),
            'evaluated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $completion
     * @param  array<string,mixed>  $fairProtocol
     * @param  array<string,mixed>  $devPlan
     * @param  array<int,array<string,mixed>>  $runs
     * @return array<string,mixed>
     */
    public function fairClaudeFinalPacket(
        array $completion,
        array $fairProtocol,
        array $devPlan,
        array $runs,
        bool $ok,
    ): array {
        $qualityStatus = (string) ($fairProtocol['quality_status'] ?? $completion['status'] ?? data_get($completion, 'completion_packet.status', 'unknown'));
        $protocolStatus = (string) ($fairProtocol['status'] ?? 'unverified');
        $status = match (true) {
            ! $ok || $protocolStatus === 'failed' => 'failed',
            $protocolStatus === 'valid' => 'passed',
            default => 'unverified',
        };
        $tests = (array) data_get($completion, 'completion_packet.tests', []);
        $failedTests = collect($tests)
            ->filter(fn (mixed $test): bool => is_array($test) && array_key_exists('ok', $test) && ! (bool) $test['ok'])
            ->values()
            ->all();
        $qualityGates = (array) data_get($completion, 'quality_gates', data_get($completion, 'completion_packet.quality_gates', []));
        $providerRuns = collect($runs);
        $traceIds = $providerRuns
            ->pluck('trace_id')
            ->filter(fn (mixed $traceId): bool => is_string($traceId) && trim($traceId) !== '')
            ->values()
            ->all();

        return [
            'schema_version' => 1,
            'kind' => 'atlas_cli_dev_fair_claude_final_packet',
            'status' => $status,
            'ok' => $ok,
            'fair_mode' => true,
            'provider_lock' => FairClaudePolicy::PROVIDER_LOCK,
            'model_lock' => FairClaudePolicy::MODEL_LOCK,
            'selected_model' => data_get($devPlan, 'selected_model.model'),
            'quality_status' => $qualityStatus,
            'protocol_status' => $protocolStatus,
            'protocol_valid' => $protocolStatus === 'valid',
            'pass_without_human' => (bool) ($fairProtocol['pass_without_human'] ?? false),
            'human_intervention_count' => (int) ($fairProtocol['human_intervention_count'] ?? 0),
            'deterministic_gates_passed' => (bool) ($fairProtocol['deterministic_gates_passed'] ?? false),
            'unverified_counts_as_passed' => false,
            'attempts' => $providerRuns->count(),
            'max_attempts' => (int) data_get($devPlan, 'iterations.max', 1),
            'files_changed_count' => count((array) data_get($completion, 'completion_packet.files_changed', $completion['changed_files'] ?? [])),
            'diff_hash' => $completion['diff_hash'] ?? null,
            'tests' => [
                'test_count' => count($tests),
                'failed_test_count' => count($failedTests),
                'failed_tests' => $failedTests,
            ],
            'gates' => [
                'quality_gates' => $qualityGates,
                'blocking_reasons' => array_values((array) ($fairProtocol['blocking_reasons'] ?? [])),
            ],
            'repair' => [
                'repair_attempt_count' => max(0, $providerRuns->count() - 1),
                'repair_used' => $providerRuns->count() > 1,
                'converted_to_green' => $providerRuns->count() > 1 && $status === 'passed',
            ],
            'provider_runs' => $providerRuns
                ->map(fn (array $run): array => [
                    'iteration' => $run['iteration'] ?? null,
                    'exit_code' => $run['exit_code'] ?? null,
                    'trace_id' => $run['trace_id'] ?? null,
                    'model' => $run['model'] ?? null,
                    'model_label' => $run['model_label'] ?? null,
                ])
                ->values()
                ->all(),
            'trace_id' => $traceIds[0] ?? null,
            'trace_ids' => $traceIds,
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    public function markStep(array $plan, string $phase, string $status, array $metadata = []): array
    {
        $steps = array_values((array) ($plan['steps'] ?? []));
        $steps[] = array_filter([
            'id' => 's'.(count($steps) + 1),
            'phase' => $phase,
            'status' => $status,
            'tool' => $metadata['tool'] ?? null,
            'output' => $metadata['output'] ?? null,
            'duration_ms' => $metadata['duration_ms'] ?? null,
            'files' => $metadata['files'] ?? null,
            'trace_id' => $metadata['trace_id'] ?? null,
            'model' => $metadata['model'] ?? null,
            'quality_status' => $metadata['quality_status'] ?? null,
            'error' => $metadata['error'] ?? null,
            'created_at' => now()->toJSON(),
        ], fn (mixed $value): bool => $value !== null && $value !== []);

        $plan['steps'] = $steps;
        $plan['current_phase'] = $phase;
        $plan['updated_at'] = now()->toJSON();

        if (isset($metadata['iteration'])) {
            $iterations = is_array($plan['iterations'] ?? null) ? $plan['iterations'] : [];
            $iterations['current'] = (int) $metadata['iteration'];
            $plan['iterations'] = $iterations;
        }

        if (isset($metadata['reason_if_stopped'])) {
            $iterations = is_array($plan['iterations'] ?? null) ? $plan['iterations'] : [];
            $iterations['reason_if_stopped'] = $metadata['reason_if_stopped'];
            $plan['iterations'] = $iterations;
        }

        return $plan;
    }

    /**
     * @param  array<string,mixed>|null  $contract
     */
    public function promptWithEngineeringContract(string $task, ?array $contract, ?array $blueprint = null): string
    {
        if ($contract === null || $contract === []) {
            return $task;
        }

        $lines = [
            '# Atlas Engineering Task Contract',
            '',
            'Use este contrato como fonte de escopo. Nao aumente o trabalho sem necessidade; entregue o menor diff que satisfaz os criterios.',
            '',
            'Tipo: '.$this->contractScalar($contract, 'type', 'feature'),
            'Tamanho estimado: '.$this->contractScalar($contract, 'estimated_size', 'M'),
            'Objetivo: '.$this->contractScalar($contract, 'goal', $task),
        ];

        $this->appendContractList($lines, 'Contexto', $contract['context'] ?? []);
        $this->appendContractList($lines, 'Dentro do escopo', $contract['in_scope'] ?? []);
        $this->appendContractList($lines, 'Fora do escopo', $contract['out_of_scope'] ?? []);
        $this->appendContractList($lines, 'Criterios de aceite', $contract['acceptance_criteria'] ?? []);
        $this->appendContractList($lines, 'Arquivos provaveis', $contract['likely_files'] ?? []);
        $this->appendContractList($lines, 'Padroes a seguir', $contract['patterns_to_follow'] ?? []);
        $this->appendContractList($lines, 'Padroes a evitar', $contract['patterns_to_avoid'] ?? []);
        $this->appendContractList($lines, 'Casos de borda', $contract['edge_cases'] ?? []);
        $this->appendContractList($lines, 'Validacao esperada', $contract['test_coverage'] ?? []);
        $this->appendContractList($lines, 'Definition of done', $contract['definition_of_done'] ?? []);

        $dependencies = is_array($contract['dependencies'] ?? null) ? $contract['dependencies'] : [];
        $this->appendContractList($lines, 'Bloqueado por', $dependencies['blocked_by'] ?? []);
        $this->appendContractList($lines, 'Bloqueia', $dependencies['blocks'] ?? []);
        $this->appendBlueprintSummary($lines, $blueprint);

        $refs = is_array($contract['refs'] ?? null) ? array_filter($contract['refs']) : [];
        if ($refs !== []) {
            $encodedRefs = json_encode($refs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($encodedRefs) && $encodedRefs !== '') {
                $lines[] = '';
                $lines[] = 'Refs: '.$encodedRefs;
            }
        }

        $lines[] = '';
        $lines[] = '# Pedido do operador';
        $lines[] = trim($task);

        return implode("\n", $lines);
    }

    /**
     * @param  array<string,mixed>|null  $contract
     */
    public function fairClaudePromptContract(string $prompt, ?array $contract = null): string
    {
        $metadata = $this->fairClaudePromptContractMetadata($contract);
        $encoded = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $jsonBlock = is_string($encoded) && $encoded !== '' ? $encoded : '{}';

        return implode("\n", [
            '# Atlas Fair Claude Mode',
            '',
            'This run is part of a fair benchmark: Atlas harness + Claude CLI + Claude Opus versus Claude Code CLI + the same Claude Opus.',
            'Use only the provided task/context. Do not suggest switching provider, model, council, external reviewer, Codex, Gemini, or fallback.',
            '',
            '## Execution Contract',
            '- Provider is locked to claude_cli.',
            '- Model is locked to the configured Claude Opus premium model.',
            '- Forbidden providers: codex_cli, gemini_cli.',
            '- Forbidden capabilities: provider fallback, council, atlas_decide, external reviewer.',
            '- Keep the diff scoped and minimal.',
            '- Preserve unrelated user changes.',
            '- Do not claim success without deterministic validation evidence.',
            '- If validation fails, explain the failure precisely so Atlas can build a repair prompt for the same Claude Opus.',
            '',
            '## Expected Response Contract',
            '- Summarize what changed.',
            '- List files changed.',
            '- List tests or checks run, or say they were not run.',
            '- List residual risks succinctly.',
            '',
            '## Machine-Readable Contract',
            '```json',
            $jsonBlock,
            '```',
            '',
            '# Task',
            '',
            trim($prompt),
        ]);
    }

    /**
     * @param  array<string,mixed>|null  $contract
     * @return array<string,mixed>
     */
    public function fairClaudePromptContractMetadata(?array $contract = null): array
    {
        $contract = is_array($contract) ? $contract : [];
        $acceptanceCriteria = $this->contractStringList($contract['acceptance_criteria'] ?? []);
        $definitionOfDone = $this->contractStringList($contract['definition_of_done'] ?? []);
        $testCoverage = $this->contractStringList($contract['test_coverage'] ?? []);
        $likelyFiles = $this->contractStringList($contract['likely_files'] ?? []);
        $allowedFiles = $this->contractStringList($contract['allowed_files'] ?? []);
        $allowedPaths = $this->contractStringList($contract['allowed_paths'] ?? []);
        $refs = is_array($contract['refs'] ?? null) ? array_filter($contract['refs']) : [];

        return [
            'schema_version' => 1,
            'kind' => 'fair_claude_prompt_contract',
            'mode_name' => FairClaudePolicy::MODE_NAME,
            'provider_lock' => FairClaudePolicy::PROVIDER_LOCK,
            'model_lock' => FairClaudePolicy::MODEL_LOCK,
            'model_alias' => FairClaudePolicy::MODEL_LOCK,
            'model_tier' => 'premium',
            'forbidden_providers' => ['codex_cli', 'gemini_cli'],
            'forbidden_capabilities' => ['fallback', 'council', 'atlas_decide', 'external_reviewer'],
            'acceptance_criteria' => $acceptanceCriteria,
            'definition_of_done' => $definitionOfDone,
            'file_scope' => [
                'likely_files' => $likelyFiles,
                'allowed_files' => $allowedFiles,
                'allowed_paths' => $allowedPaths,
                'strict' => (bool) ($contract['strict_file_scope'] ?? false),
            ],
            'deterministic_gates' => [
                'required' => true,
                'pass_without_human_requires_gate_pass' => true,
                'sources' => ['definition_of_done', 'test_coverage'],
                'validation_steps' => $testCoverage,
            ],
            'task_refs' => $refs,
        ];
    }

    /**
     * @param  mixed  $value
     * @return array<int,string>
     */
    private function contractStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $entry) {
            if (! is_scalar($entry)) {
                continue;
            }
            $text = trim((string) $entry);
            if ($text === '') {
                continue;
            }
            $items[] = $text;
        }

        return array_values(array_unique($items));
    }

    /**
     * @param  array<string,mixed>  $completion
     * @param  array<string,mixed>  $devPlan
     */
    public function fairClaudeRepairCapsule(string $task, array $completion, int $iteration, int $maxIterations, array $devPlan): string
    {
        $failureSignal = $this->extractRepairFailureSignal($completion);

        $gateSummary = [
            'status' => $completion['status'] ?? data_get($completion, 'completion_packet.status'),
            'changed_files' => $completion['changed_files'] ?? data_get($completion, 'completion_packet.files_changed', []),
            'tests' => data_get($completion, 'completion_packet.tests', []),
            'quality_gates' => data_get($completion, 'quality_gates', data_get($completion, 'completion_packet.quality_gates', [])),
            'risks' => data_get($completion, 'completion_packet.risks', []),
        ];

        return implode("\n\n", [
            '# Atlas Fair Claude Repair Capsule',
            "Repair iteration {$iteration}/{$maxIterations}. Use the same Claude CLI provider and the same configured Claude Opus model.",
            'Provider/model remain locked: claude_cli + Claude Opus. Do not switch provider, model, council, Codex, Gemini, external reviewer, or fallback.',
            'Fair benchmark rule: unverified, needs_review, missing gates, or self-assessment never count as passed.',
            'Original task:',
            trim($task),
            'Failure signal (command, exit code, primary error):',
            json_encode($failureSignal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            'Previous deterministic gate result:',
            json_encode($gateSummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            'Execution plan checkpoint:',
            json_encode([
                'plan_id' => $devPlan['plan_id'] ?? null,
                'selected_provider' => $devPlan['selected_provider'] ?? null,
                'selected_model' => $devPlan['selected_model'] ?? null,
                'fair_mode' => $devPlan['fair_mode'] ?? null,
                'iterations' => $devPlan['iterations'] ?? null,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            'Repair objective: apply the smallest safe correction that makes deterministic gates pass. Preserve unrelated user changes and explain any gate that still cannot be verified.',
        ]);
    }

    /**
     * @param  array<string,mixed>  $completion
     * @return array{command: ?string, exit_code: ?int, primary_error: ?string, source: string}
     */
    private function extractRepairFailureSignal(array $completion): array
    {
        $tests = (array) data_get($completion, 'completion_packet.tests', []);
        foreach ($tests as $test) {
            if (! is_array($test)) {
                continue;
            }
            if (($test['ok'] ?? null) === true) {
                continue;
            }
            $command = $test['command'] ?? null;
            $exit = $test['exit_code'] ?? null;
            $error = $test['error'] ?? null;
            if ($command !== null || $exit !== null || $error !== null) {
                return [
                    'command' => is_string($command) && $command !== '' ? $command : null,
                    'exit_code' => is_int($exit) ? $exit : (is_numeric($exit) ? (int) $exit : null),
                    'primary_error' => $this->trimErrorExcerpt($error),
                    'source' => 'completion_packet.tests',
                ];
            }
        }

        $gates = (array) data_get(
            $completion,
            'quality_gates',
            data_get($completion, 'completion_packet.quality_gates', [])
        );
        foreach ($gates as $gate) {
            if (! is_array($gate)) {
                continue;
            }
            $status = (string) ($gate['status'] ?? '');
            if ($status !== 'failed' && $status !== 'needs_review') {
                continue;
            }
            $detail = $gate['detail'] ?? null;
            $name = $gate['name'] ?? 'quality_gate';

            return [
                'command' => is_string($name) && $name !== '' ? "atlas:cli:quality:{$name}" : null,
                'exit_code' => null,
                'primary_error' => $this->trimErrorExcerpt($detail),
                'source' => 'quality_gates',
            ];
        }

        $risks = (array) data_get($completion, 'completion_packet.risks', []);
        $firstRisk = null;
        foreach ($risks as $risk) {
            if (is_string($risk) && trim($risk) !== '') {
                $firstRisk = trim($risk);
                break;
            }
        }

        return [
            'command' => null,
            'exit_code' => null,
            'primary_error' => $firstRisk,
            'source' => 'completion_packet.risks',
        ];
    }

    private function trimErrorExcerpt(mixed $value, int $limit = 1500): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $text = trim($value);
        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit).'…' : $text;
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    public function persistPlan(?string $traceId, array $plan): void
    {
        if (! $traceId) {
            return;
        }

        $trace = AiTrace::query()->find($traceId);
        if (! $trace) {
            return;
        }

        $metadata = $trace->metadata ?? [];
        $metadata['dev_execution_plan'] = $plan;

        $trace->update(['metadata' => $metadata]);
    }

    /**
     * @return array<int,string>
     */
    public function chatCommand(
        string $task,
        string $workspace,
        ?string $provider,
        ?string $model,
        string $permission,
        bool $allowWrite,
        bool $autoTest,
        int $timeout,
        bool $stream,
        bool $noRun,
        ?string $effort = null,
        ?array $devExecutionPlan = null,
        array $skills = [],
        bool $json = false,
        bool $allowDanger = false,
        bool $allowUnsandboxed = false,
        array $imagePaths = [],
        bool $clipboardImage = false,
        bool $noAutoImage = false,
        array $openBrain = [],
    ): array {
        $command = [
            AtlasPhpBinary::path(),
            base_path('artisan'),
            'atlas:ai:chat',
            $task,
            '--dev',
            '--new-thread',
            '--workspace='.$workspace,
            '--permission='.$permission,
            '--timeout='.(string) $timeout,
            '--no-quality-gate',
        ];

        if ($provider) {
            $command[] = '--provider='.$provider;
        }

        if ($model !== null && trim($model) !== '') {
            $command[] = '--model='.trim($model);
        }

        if ($effort !== null && trim($effort) !== '') {
            $command[] = '--effort='.trim($effort);
        }

        if ($allowWrite) {
            $command[] = '--allow-write';
        }

        if ($allowDanger) {
            $command[] = '--dangerously-allow-all';
        }

        if ($allowUnsandboxed) {
            $command[] = '--allow-unsandboxed';
        }

        if ($autoTest) {
            $command[] = '--auto-test';
        }

        if ($stream) {
            $command[] = '--stream';
        }

        if ($noRun) {
            $command[] = '--no-run';
        }

        if ($json) {
            $command[] = '--json';
        }

        $openBrainMode = is_string($openBrain['mode'] ?? null) ? (string) $openBrain['mode'] : 'auto';
        if ($openBrainMode === 'off') {
            $command[] = '--no-open-brain';
        } elseif ($openBrainMode === 'required') {
            $command[] = '--require-open-brain';
        }
        if (! empty($openBrain['refresh'])) {
            $command[] = '--open-brain-refresh';
        }
        if (isset($openBrain['budget_chars']) && (int) $openBrain['budget_chars'] > 0) {
            $command[] = '--open-brain-budget='.(int) $openBrain['budget_chars'];
        }

        if ($devExecutionPlan !== null) {
            $encoded = json_encode($devExecutionPlan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($encoded) && $encoded !== '') {
                $command[] = '--dev-plan='.$encoded;
            }
        }

        foreach ($skills as $skill) {
            if (is_scalar($skill) && trim((string) $skill) !== '') {
                $command[] = '--skill='.trim((string) $skill);
            }
        }

        foreach ($imagePaths as $imagePath) {
            if (is_scalar($imagePath) && trim((string) $imagePath) !== '') {
                $command[] = '--image='.trim((string) $imagePath);
            }
        }

        if ($clipboardImage) {
            $command[] = '--clipboard-image';
        }

        if ($noAutoImage) {
            $command[] = '--no-auto-image';
        }

        return $command;
    }

    /**
     * @param  array<int,string>  $lines
     */
    private function appendContractList(array &$lines, string $title, mixed $items): void
    {
        $items = $this->contractList($items);
        if ($items === []) {
            return;
        }

        $lines[] = '';
        $lines[] = '## '.$title;

        foreach (array_slice($items, 0, 12) as $item) {
            $lines[] = '- '.$item;
        }
    }

    /**
     * @param  array<int,string>  $lines
     * @param  array<string,mixed>|null  $blueprint
     */
    private function appendBlueprintSummary(array &$lines, ?array $blueprint): void
    {
        if ($blueprint === null || $blueprint === []) {
            return;
        }

        $phases = collect((array) ($blueprint['phases'] ?? []))
            ->filter(fn (mixed $phase): bool => is_array($phase))
            ->map(fn (array $phase): string => trim((string) ($phase['id'] ?? '').': '.(string) ($phase['gate'] ?? '')))
            ->filter()
            ->values()
            ->all();
        $this->appendContractList($lines, 'Blueprint phases', $phases);

        $scenarios = collect((array) ($blueprint['scenario_inventory'] ?? []))
            ->filter(fn (mixed $scenario): bool => is_array($scenario))
            ->map(fn (array $scenario): string => trim((string) ($scenario['id'] ?? '').': '.(string) ($scenario['name'] ?? '')))
            ->filter()
            ->values()
            ->all();
        $this->appendContractList($lines, 'Scenario inventory', $scenarios);

        $gates = collect((array) ($blueprint['review_gates'] ?? []))
            ->filter(fn (mixed $gate): bool => is_array($gate))
            ->map(fn (array $gate): string => trim((string) ($gate['id'] ?? '').': '.(string) ($gate['title'] ?? '')))
            ->filter()
            ->values()
            ->all();
        $this->appendContractList($lines, 'Review gates', $gates);
    }

    /**
     * @return array<int,string>
     */
    private function contractList(mixed $items): array
    {
        if (! is_array($items)) {
            $items = [$items];
        }

        return collect($items)
            ->filter(fn (mixed $item): bool => is_scalar($item) && trim((string) $item) !== '')
            ->map(fn (mixed $item): string => trim((string) $item))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $contract
     */
    private function contractScalar(array $contract, string $key, string $fallback): string
    {
        $value = $contract[$key] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : $fallback;
    }
}
