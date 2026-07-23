<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Spine;

/**
 * Fail-closed gate for shared N9/N11 spine on executor intakes.
 */
final class AaeosSpineGate
{
    public const SCHEMA = 'atlas.aaeos.spine_gate.v1';

    public function __construct(
        private readonly AaeosEngineeringSpine $spine = new AaeosEngineeringSpine,
    ) {}

    /**
     * @param  array<string,mixed>  $declared
     * @return array{schema:string,ok:bool,status:string,violations:list<string>,contract:array<string,mixed>}
     */
    public function evaluate(string $mode, array $declared = []): array
    {
        $assert = $this->spine->assertShared($mode, $declared);

        return [
            'schema' => self::SCHEMA,
            'ok' => (bool) $assert['ok'],
            'status' => $assert['ok'] ? 'passed' : 'blocked',
            'violations' => $assert['violations'],
            'contract' => $assert['contract'],
            'elite_same_bar' => true,
        ];
    }

    /**
     * Attach spine gate result into a programming/forge contract payload.
     *
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $declared
     * @return array<string,mixed>
     */
    public function stamp(array $contract, string $mode, array $declared = []): array
    {
        $gate = $this->evaluate($mode, $declared);
        $contract['aaeos_spine_gate'] = $gate;
        $contract['engineering_spine'] = $gate['contract'];
        if (! $gate['ok']) {
            $contract['spine_blocked'] = true;
            $contract['blocked'] = true;
            $contract['block_reason'] = 'aaeos_spine_violation';
        }

        return $contract;
    }
}
