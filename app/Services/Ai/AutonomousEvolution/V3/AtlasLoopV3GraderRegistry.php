<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\V3;

use App\Services\Ai\AutonomousEvolution\AtlasLoopWiringMaterialGrader;

/**
 * V3 GRADER REGISTRY — the enumeration of the OPERATOR-INSTALLED deterministic graders and the claim classes
 * they admit. Today exactly one ruler is installed (wiring_material → AtlasLoopWiringMaterialGrader); as the
 * Loop authors new graders (each first passing {@see AtlasLoopV3GraderSpec}), they are installed here.
 *
 * PURE LOOKUP: the registry maps claim-class → grader FQCN and NEVER instantiates a grader (no `new`, no
 * container resolution) — it only answers "what is installed for this claim class".
 */
final class AtlasLoopV3GraderRegistry
{
    /** @var array<string, class-string> claim class => grader FQCN */
    public const INSTALLED = [
        'wiring_material' => AtlasLoopWiringMaterialGrader::class,
    ];

    /** @return list<string> */
    public function claimClasses(): array
    {
        return array_keys(self::INSTALLED);
    }

    public function fqcnFor(string $claimClass): ?string
    {
        return self::INSTALLED[$claimClass] ?? null;
    }

    public function isInstalled(string $claimClass): bool
    {
        return array_key_exists($claimClass, self::INSTALLED);
    }
}
