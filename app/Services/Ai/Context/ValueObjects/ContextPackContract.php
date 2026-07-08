<?php

declare(strict_types=1);

namespace App\Services\Ai\Context\ValueObjects;

/**
 * Provider-bound context pack contract — single typed surface for executors.
 */
final readonly class ContextPackContract
{
    public function __construct(
        public string $schemaVersion,
        public string $task,
        public string $workspace,
        public array $pack,
        public string $privacyClass,
    ) {}

    /**
     * @param  array<string, mixed>  $pack
     */
    public static function fromArray(array $pack, string $task, string $workspace, string $privacyClass = 'provider_safe'): self
    {
        return new self(
            schemaVersion: (string) ($pack['schema'] ?? 'atlas.context_pack.v1'),
            task: $task,
            workspace: $workspace,
            pack: $pack,
            privacyClass: $privacyClass,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'task' => $this->task,
            'workspace' => $this->workspace,
            'privacy_class' => $this->privacyClass,
            'pack' => $this->pack,
        ];
    }
}
