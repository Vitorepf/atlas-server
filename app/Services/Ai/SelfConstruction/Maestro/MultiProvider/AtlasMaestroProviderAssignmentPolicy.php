<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\MultiProvider;

use DomainException;

final class AtlasMaestroProviderAssignmentPolicy
{
    // Built-in logical name for local Atlas runtime; exempt from external registry validation.
    public const ATLAS_NATIVE = 'atlas_native';

    /**
     * @var array<string,array{primary:string,fallback:list<string>}>
     */
    private array $assignments;

    public function __construct(private readonly object $registry = new AtlasMaestroProviderClassRegistry)
    {
        $execRuntime = config('atlas.provider_defaults.execution_runtime');
        if (! is_string($execRuntime) || trim($execRuntime) === '') {
            throw new DomainException('atlas_provider_defaults_execution_runtime_missing');
        }

        $this->assignments = [
            AtlasMaestroPacketClassifier::GRIND => [
                'primary' => $execRuntime,
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
                'primary' => $execRuntime,
                'fallback' => ['glm-5-2'],
            ],
            AtlasMaestroPacketClassifier::QUEUE_REPAIR => [
                'primary' => self::ATLAS_NATIVE,
                'fallback' => [$execRuntime, 'glm-5-2'],
            ],
            AtlasMaestroPacketClassifier::LEARNING_LOOP => [
                'primary' => self::ATLAS_NATIVE,
                'fallback' => [$execRuntime, 'glm-5-2'],
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

    /**
     * Pick the first available provider for the given packet class.
     * Returns an auditable decision with reason code and failover flag.
     *
     * @param  list<string>  $unavailable  provider ids known to be down/busy right now
     * @return array{provider:string,reason:string,failover:bool,tried:list<string>}
     */
    public function decide(string $class, array $unavailable = []): array
    {
        $assignment = $this->assignmentFor($class);
        $ordered = [$assignment['primary'], ...$assignment['fallback']];
        $skip = array_fill_keys($unavailable, true);
        $tried = [];

        foreach ($ordered as $provider) {
            if (isset($skip[$provider])) {
                $tried[] = $provider;
                continue;
            }

            return [
                'provider' => $provider,
                'reason' => $tried === [] ? 'native-first' : 'failover:preferred-unavailable',
                'failover' => $tried !== [],
                'tried' => $tried,
            ];
        }

        // All options exhausted — return last resort with explicit reason.
        $last = (string) end($ordered);

        return [
            'provider' => $last,
            'reason' => 'all-unavailable:last-resort',
            'failover' => true,
            'tried' => array_slice($ordered, 0, count($ordered) - 1),
        ];
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
                // atlas_native is a built-in logical name, not an external registered provider.
                if ($providerId === self::ATLAS_NATIVE) {
                    continue;
                }
                if (! isset($known[$providerId])) {
                    throw new DomainException(sprintf('atlas_maestro_assignment_unknown_provider:%s:%s', $class, $providerId));
                }
            }
        }
    }
}
