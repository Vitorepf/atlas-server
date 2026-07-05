<?php

namespace App\Services\Ai\Mission;

use RuntimeException;

class MissionLifecycleException extends RuntimeException
{
    public static function invalidTransition(string $from, string $to): self
    {
        return new self("Invalid mission lifecycle transition: [{$from}] -> [{$to}]");
    }

    public static function missingEvidence(): self
    {
        return new self('Mission cannot transition to [completed] without at least one evidence ref.');
    }

    public static function missingCertification(): self
    {
        return new self('Mission cannot transition to [completed] without a [passed] certification.');
    }

    public static function unknownMission(string $missionUuid): self
    {
        return new self("Mission with uuid [{$missionUuid}] not found.");
    }

    public static function invalidEvidenceType(string $type): self
    {
        return new self("Unknown evidence_type [{$type}].");
    }

    public static function missingEvidencePackHash(): self
    {
        return new self('Mission evidence_pack_hash is empty — certification has no recomputable evidence receipt.');
    }

    public static function certificationHashMismatch(): self
    {
        return new self('Mission evidence_pack_hash does not match recomputed hash over current evidence refs — evidence may have been tampered after certification.');
    }
}
