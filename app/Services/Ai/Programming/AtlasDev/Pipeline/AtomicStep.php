<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Pipeline;


/**
 * Immutable, self-contained FIRST step emitted by {@see AtomicSemanticDecomposer}.
 *
 * A step represents one atomic move in the canonical contract -> skeleton ->
 * one behavior -> wiring -> test progression. By construction it touches a
 * single real layer (db/api/ui/service) plus optional tests/docs, with a
 * pre-resolved allowed_files scope sized so {@see RiskLevelScorer} keeps it
 * at or below R3 (under 6 files, under 3 real layers). Validation and
 * acceptance are pre-resolved so the step is executable without re-derivation.
 */
final class AtomicStep
{
    public const KIND_CONTRACT = 'contract';

    public const KIND_SKELETON = 'skeleton';

    public const KIND_BEHAVIOR = 'behavior';

    public const KIND_WIRING = 'wiring';

    public const KIND_TEST = 'test';

    public const KINDS = [
        self::KIND_CONTRACT,
        self::KIND_SKELETON,
        self::KIND_BEHAVIOR,
        self::KIND_WIRING,
        self::KIND_TEST,
    ];

    /**
     * @param  list<string>  $allowedFiles  pre-resolved file scope (workspace-relative)
     * @param  list<string>  $validation  commands/checks proving the step landed
     * @param  list<string>  $acceptance  human-readable acceptance criteria
     */
    public function __construct(
        public readonly int $order,
        public readonly string $kind,
        public readonly string $intent,
        public readonly array $allowedFiles,
        public readonly array $validation,
        public readonly array $acceptance,
    ) {}

    /**
     * The allowed_files constraint string consumed by RiskLevelScorer's
     * explicit-scope path. This is the load-bearing contract that keeps the
     * emitted step at or below R3.
     */
    public function allowedFilesConstraint(): string
    {
        return 'allowed_files='.implode(',', $this->allowedFiles);
    }

    /**
     * @return list<string>
     */
    public function userConstraints(): array
    {
        return [$this->allowedFilesConstraint()];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'order' => $this->order,
            'kind' => $this->kind,
            'intent' => $this->intent,
            'allowed_files' => array_values($this->allowedFiles),
            'validation' => array_values($this->validation),
            'acceptance' => array_values($this->acceptance),
        ];
    }
}
