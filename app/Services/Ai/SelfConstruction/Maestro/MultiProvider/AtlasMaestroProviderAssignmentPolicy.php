<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\MultiProvider;

use DomainException;

final class AtlasMaestroProviderAssignmentPolicy
{
    /**
     * @var array<string,array{primary:string,fallback:list<string>}>
     */
    private array $assignments;

    public function __construct(private readonly object $registry = new AtlasMaestroProviderClassRegistry)
    {
        $this->assignments = [
            AtlasMaestroPacketClassifier::GRIND => [
                'primary' => 'minimax-m3',
                'fallback' => ['glm-5-2'],
            ],
            AtlasMaestroPacketClassifier::ARCHITECTURE => [
                'primary' => 'codex-gpt-5-5',
                'fallback' => ['claude-opus'],
            ],
            AtlasMaestroPacketClassifier::MULTI_FILE => [
                'primary' => 'codex-gpt-5-5',
                'fallback' => ['claude-opus'],
            ],
            AtlasMaestroPacketClassifier::DOC => [
                'primary' => 'minimax-m3',
                'fallback' => ['glm-5-2'],
            ],
        ];

        $this->validateProviderRefs();
    }

    /**
     * @return array{primary:string,fallback:list<string>}
     */
    public function assignmentFor(string $class): array
    {
        return $this->assignments[$class] ?? $this->assignments[AtlasMaestroPacketClassifier::GRIND];
    }

    private function validateProviderRefs(): void
    {
        if (! method_exists($this->registry, 'providers')) {
            throw new DomainException('atlas_maestro_provider_registry_missing_providers_method');
        }

        $providers = $this->registry->providers();
        $known = array_fill_keys(array_keys((array) $providers), true);
        foreach ($this->assignments as $class => $assignment) {
            foreach ([$assignment['primary'], ...$assignment['fallback']] as $providerId) {
                if (! isset($known[$providerId])) {
                    throw new DomainException(sprintf('atlas_maestro_assignment_unknown_provider:%s:%s', $class, $providerId));
                }
            }
        }
    }
}
