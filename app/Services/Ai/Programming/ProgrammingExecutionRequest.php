<?php

namespace App\Services\Ai\Programming;

use App\Services\Ai\Kernel\Provider\AgentBehaviorContract;

class ProgrammingExecutionRequest
{
    /**
     * @param  array<string,mixed>  $data
     */
    public function __construct(
        private readonly array $data,
    ) {}

    /**
     * @param  array<string,mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function objective(): string
    {
        $objective = trim((string) ($this->data['objective'] ?? $this->data['task'] ?? ''));

        return $objective !== '' ? $objective : 'Executar tarefa tecnica Atlas.';
    }

    public function workspace(): string
    {
        return (string) ($this->data['workspace'] ?? getcwd() ?: base_path());
    }

    public function profile(): string
    {
        return ($this->data['profile'] ?? null) === 'forge' ? 'forge' : 'dev';
    }

    public function taskId(): ?string
    {
        $taskId = $this->data['task_id'] ?? null;

        return is_string($taskId) && trim($taskId) !== '' ? trim($taskId) : null;
    }

    public function provider(): ?string
    {
        $provider = $this->data['provider'] ?? null;

        return is_string($provider) && trim($provider) !== '' ? trim($provider) : null;
    }

    public function model(): ?string
    {
        $model = $this->data['model'] ?? null;

        return is_string($model) && trim($model) !== '' ? trim($model) : null;
    }

    /**
     * @return array<string,mixed>
     */
    public function contract(): array
    {
        return is_array($this->data['contract'] ?? null) ? $this->data['contract'] : [];
    }

    /**
     * @return array<string,mixed>
     */
    public function policyContracts(): array
    {
        $contracts = $this->data['policy_contracts'] ?? null;
        if (is_array($contracts)) {
            return $contracts;
        }

        $effective = data_get($this->data, 'policy_profile.effective_policy.operational_contracts')
            ?: data_get($this->data, 'programming_message_plan.policy_contracts')
            ?: data_get($this->data, 'programming_message_plan.policy_profile.policy_contracts');

        return is_array($effective) ? $effective : [];
    }

    /**
     * @return array<string,mixed>
     */
    public function agentBehaviorContract(): array
    {
        foreach ([
            'agent_behavior_contract',
            'programming_message_plan.agent_behavior_contract',
            'programming_session_plan.agent_behavior_contract',
            'dev_execution_plan.programming_session_plan.agent_behavior_contract',
        ] as $path) {
            $contract = data_get($this->data, $path);
            if (is_array($contract) && $contract !== []) {
                return $contract;
            }
        }

        return app(AgentBehaviorContract::class)->toArray();
    }

    /**
     * @return array<string,mixed>
     */
    public function harnessOptions(): array
    {
        $profile = $this->profile();
        $complete = (bool) ($this->data['complete'] ?? $profile === 'forge');

        return [
            'workspace' => $this->workspace(),
            'provider' => $this->provider(),
            'model' => $this->model(),
            'model_policy' => (string) ($this->data['model_policy'] ?? ($profile === 'forge' ? 'best-quality' : 'balanced')),
            'permission' => (string) ($this->data['permission'] ?? ($profile === 'forge' ? 'danger' : 'auto')),
            'sandbox' => (string) ($this->data['sandbox'] ?? ($profile === 'forge' ? 'worktree' : 'workspace')),
            'provider_runtime' => (string) ($this->data['provider_runtime'] ?? 'host'),
            'max_attempts' => ProgrammingIterationPolicy::forExecutionPolicy(
                $this->data['max_attempts'] ?? null,
                $complete,
                $profile === 'forge',
            ),
            'test_command' => is_string($this->data['test_command'] ?? null) ? $this->data['test_command'] : null,
            'visual_e2e' => (string) ($this->data['visual_e2e'] ?? ($profile === 'forge' ? 'auto' : 'off')),
            'quality_scan' => (string) ($this->data['quality_scan'] ?? ($profile === 'forge' ? 'auto' : 'off')),
            'quality_profile' => (string) ($this->data['quality_profile'] ?? 'auto'),
            'harness_policy' => (string) ($this->data['harness_policy'] ?? ($profile === 'forge' ? 'strict' : 'auto')),
            'complete' => $complete,
            'auto_test' => (bool) ($this->data['auto_test'] ?? $profile === 'forge'),
            'critical' => (bool) ($this->data['critical'] ?? $profile === 'forge'),
            'dry_run' => (bool) ($this->data['dry_run'] ?? false),
            'no_provider' => (bool) ($this->data['no_provider'] ?? false),
            'keep_workspace' => (bool) ($this->data['keep_workspace'] ?? false),
            'apply_isolated_patch' => (bool) ($this->data['apply_isolated_patch'] ?? true),
            'agent_behavior_contract' => $this->agentBehaviorContract(),
        ];
    }
}
