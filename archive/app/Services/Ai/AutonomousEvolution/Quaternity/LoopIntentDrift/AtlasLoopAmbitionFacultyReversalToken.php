<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift;

use JsonSerializable;

final class AtlasLoopAmbitionFacultyReversalToken implements JsonSerializable
{
    public const MODE_NOOP = 'noop';
    public const MODE_RECALIBRATION = 'recalibration';

    public function __construct(
        public readonly string $mode,
        public readonly string $priorTargetBytes,
    ) {
    }

    public static function noop(AtlasLoopAmbitionFacultyTarget $prior): self
    {
        return new self(self::MODE_NOOP, $prior->canonicalBytes());
    }

    public static function recalibration(AtlasLoopAmbitionFacultyTarget $prior): self
    {
        return new self(self::MODE_RECALIBRATION, $prior->canonicalBytes());
    }

    public function priorTarget(): AtlasLoopAmbitionFacultyTarget
    {
        $payload = json_decode($this->priorTargetBytes, true, 512, JSON_THROW_ON_ERROR);

        return AtlasLoopAmbitionFacultyTarget::fromArray(is_array($payload) ? $payload : []);
    }

    /**
     * @return array{mode:string,prior_target_bytes:string}
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'prior_target_bytes' => $this->priorTargetBytes,
        ];
    }

    /**
     * @return array{mode:string,prior_target_bytes:string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
