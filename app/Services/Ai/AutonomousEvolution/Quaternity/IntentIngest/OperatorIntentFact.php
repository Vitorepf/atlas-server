<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest;

/**
 * Immutable, structured fact extracted from ONE operator message: a verb acting on a normalized object, plus
 * the ordered explicit constraints the operator attached ("sem mexer em X", "nunca Y", "até Z", "antes de W").
 */
final class OperatorIntentFact
{
    /**
     * @param  list<string>  $constraints
     */
    public function __construct(
        public readonly OperatorIntentVerb $verb,
        public readonly string $object,
        public readonly array $constraints,
    ) {}

    /**
     * @return array{verb:string, object:string, constraints:list<string>}
     */
    public function toArray(): array
    {
        return [
            'verb' => $this->verb->value,
            'object' => $this->object,
            'constraints' => $this->constraints,
        ];
    }
}
