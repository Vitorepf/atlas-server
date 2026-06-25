<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael\Execution\Redaction;

/**
 * An ordered list of {@see AtlasAaelEvidenceRedactionRule}s bound to one evidence kind. Pure
 * value object — no execution, no I/O, no mutation.
 */
final class AtlasAaelEvidenceRedactionPolicy
{
    /**
     * @param  list<AtlasAaelEvidenceRedactionRule>  $rules
     */
    public function __construct(
        public readonly string $evidenceKind,
        public readonly array $rules,
        public readonly bool $isFailClosedDefault = false,
    ) {}

    /**
     * @return array{evidence_kind:string,rules:list<array{kind:string,pattern:string,replacement:string,scope:string}>,is_fail_closed_default:bool}
     */
    public function toArray(): array
    {
        return [
            'evidence_kind' => $this->evidenceKind,
            'rules' => array_map(
                static fn (AtlasAaelEvidenceRedactionRule $r): array => $r->toArray(),
                $this->rules,
            ),
            'is_fail_closed_default' => $this->isFailClosedDefault,
        ];
    }
}
