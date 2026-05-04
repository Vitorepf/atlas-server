<?php

namespace App\Services\Ai\Programming;

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
    public function harnessOptions(): array
    {
        $profile = $this->profile();

        return [
            'workspace' => $this->workspace(),
            'provider' => $this->provider(),
            'model' => $this->model(),
            'model_policy' => (string) ($this->data['model_policy'] ?? ($profile === 'forge' ? 'best-quality' : 'balanced')),
            'permission' => (string) ($this->data['permission'] ?? ($profile === 'forge' ? 'danger' : 'auto')),
            'sandbox' => (string) ($this->data['sandbox'] ?? ($profile === 'forge' ? 'worktree' : 'workspace')),
            'provider_runtime' => (string) ($this->data['provider_runtime'] ?? 'host'),
            'max_attempts' => max(1, min(10, (int) ($this->data['max_attempts'] ?? ($profile === 'forge' ? 5 : 1)))),
            'test_command' => is_string($this->data['test_command'] ?? null) ? $this->data['test_command'] : null,
            'visual_e2e' => (string) ($this->data['visual_e2e'] ?? ($profile === 'forge' ? 'auto' : 'off')),
            'quality_scan' => (string) ($this->data['quality_scan'] ?? ($profile === 'forge' ? 'auto' : 'off')),
            'quality_profile' => (string) ($this->data['quality_profile'] ?? 'auto'),
            'harness_policy' => (string) ($this->data['harness_policy'] ?? ($profile === 'forge' ? 'strict' : 'auto')),
            'complete' => (bool) ($this->data['complete'] ?? $profile === 'forge'),
            'auto_test' => (bool) ($this->data['auto_test'] ?? $profile === 'forge'),
            'critical' => (bool) ($this->data['critical'] ?? $profile === 'forge'),
            'dry_run' => (bool) ($this->data['dry_run'] ?? false),
            'no_provider' => (bool) ($this->data['no_provider'] ?? false),
            'keep_workspace' => (bool) ($this->data['keep_workspace'] ?? false),
            'apply_isolated_patch' => (bool) ($this->data['apply_isolated_patch'] ?? true),
        ];
    }
}
