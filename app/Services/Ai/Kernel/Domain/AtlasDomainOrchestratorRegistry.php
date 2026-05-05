<?php

namespace App\Services\Ai\Kernel\Domain;

use Illuminate\Support\Collection;

class AtlasDomainOrchestratorRegistry
{
    /**
     * @return Collection<int,array<string,mixed>>
     */
    public function all(): Collection
    {
        return collect((array) config('atlas_ai.domain_orchestrators', []))
            ->map(fn (array $definition, string $id): array => $this->normalize($id, $definition))
            ->values();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get(string $orchestratorId): ?array
    {
        $definition = config('atlas_ai.domain_orchestrators.'.$orchestratorId);
        if (! is_array($definition)) {
            return null;
        }

        return $this->normalize($orchestratorId, $definition);
    }

    /**
     * @return array{ok:bool,errors:array<int,string>,warnings:array<int,string>,orchestrators:int}
     */
    public function complianceReport(): array
    {
        $errors = [];
        $warnings = [];

        foreach ($this->all() as $definition) {
            $id = (string) $definition['id'];
            $class = (string) $definition['class'];
            $maturity = (string) $definition['maturity'];

            if (! class_exists($class)) {
                $errors[] = "domain orchestrator {$id} class {$class} does not exist";

                continue;
            }

            if (! is_subclass_of($class, AtlasDomainOrchestrator::class)) {
                $errors[] = "domain orchestrator {$id} class {$class} must implement ".AtlasDomainOrchestrator::class;

                continue;
            }

            if (! in_array($maturity, ['implemented', 'scaffold', 'planned'], true)) {
                $errors[] = "domain orchestrator {$id} maturity must be implemented, scaffold, or planned";
            }

            /** @var AtlasDomainOrchestrator $instance */
            $instance = app($class);
            if ($instance->orchestratorId() !== $id) {
                $errors[] = "domain orchestrator {$id} class reports id {$instance->orchestratorId()}";
            }

            if ($maturity !== $instance->maturity()) {
                $warnings[] = "domain orchestrator {$id} config maturity {$maturity} differs from class maturity {$instance->maturity()}";
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'orchestrators' => $this->all()->count(),
        ];
    }

    /**
     * @param  array<string,mixed>  $definition
     * @return array<string,mixed>
     */
    private function normalize(string $id, array $definition): array
    {
        return [
            'id' => $id,
            'class' => (string) ($definition['class'] ?? ''),
            'maturity' => (string) ($definition['maturity'] ?? 'planned'),
            'domains' => array_values(array_filter((array) ($definition['domains'] ?? []), 'is_string')),
            'flows' => array_values(array_filter((array) ($definition['flows'] ?? []), 'is_string')),
        ];
    }
}
