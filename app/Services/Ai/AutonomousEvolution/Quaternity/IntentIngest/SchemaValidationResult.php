<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest;

/** Outcome of a schema validation: ok=true (zero violations) OR ok=false + the typed violation list. */
final class SchemaValidationResult
{
    /**
     * @param  list<SchemaViolation>  $violations
     */
    public function __construct(
        public readonly bool $ok,
        public readonly array $violations,
        public readonly string $version,
    ) {}

    /**
     * @return array{ok:bool, version:string, violations:list<array{kind:string, field:string, detail:?string}>}
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'version' => $this->version,
            'violations' => array_map(static fn (SchemaViolation $v): array => $v->toArray(), $this->violations),
        ];
    }
}
