<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Migration;

use InvalidArgumentException;

final class AtlasLoopSchemaMigrationRegistry
{
    /**
     * @var array<string, array<int, AtlasLoopSchemaMigrationStep>>
     */
    private array $steps = [];

    public function register(
        string $artifactKind,
        int $fromVersion,
        int $toVersion,
        callable $transform,
        callable $verifier,
        bool $reversible = false,
    ): void {
        $artifactKind = trim($artifactKind);
        if ($artifactKind === '') {
            throw new InvalidArgumentException('artifact_kind must not be empty.');
        }
        if ($fromVersion < 1 || $toVersion < 1 || $toVersion <= $fromVersion) {
            throw new InvalidArgumentException('Migration versions must be positive and forward-only.');
        }

        if (isset($this->steps[$artifactKind][$fromVersion])) {
            $existing = $this->steps[$artifactKind][$fromVersion];
            if ($existing->toVersion === $toVersion) {
                throw new AtlasLoopSchemaMigrationDuplicateStepException($artifactKind, $fromVersion, $toVersion);
            }
        }

        $this->steps[$artifactKind][$fromVersion] = new AtlasLoopSchemaMigrationStep(
            artifactKind: $artifactKind,
            fromVersion: $fromVersion,
            toVersion: $toVersion,
            transform: $transform,
            verifier: $verifier,
            reversible: $reversible,
        );
        ksort($this->steps[$artifactKind], SORT_NUMERIC);
    }

    /**
     * @return list<AtlasLoopSchemaMigrationStep>
     */
    public function resolveChain(string $artifactKind, int $currentVersion, int $targetVersion): array
    {
        if ($targetVersion < $currentVersion) {
            throw new InvalidArgumentException('resolveChain only supports forward-only upgrades.');
        }
        if ($targetVersion === $currentVersion) {
            return [];
        }

        $chain = [];
        $cursor = $currentVersion;
        while ($cursor < $targetVersion) {
            $step = $this->steps[$artifactKind][$cursor] ?? null;
            if (! $step instanceof AtlasLoopSchemaMigrationStep) {
                throw new AtlasLoopSchemaMigrationGapException($artifactKind, $cursor, $targetVersion);
            }
            if ($step->toVersion !== $cursor + 1) {
                throw new AtlasLoopSchemaMigrationGapException($artifactKind, $cursor, $targetVersion);
            }

            $chain[] = $step;
            $cursor = $step->toVersion;
        }

        if ($cursor !== $targetVersion) {
            throw new AtlasLoopSchemaMigrationGapException($artifactKind, $currentVersion, $targetVersion);
        }

        return $chain;
    }

    /**
     * @return list<int>
     */
    public function knownVersions(string $artifactKind): array
    {
        $versions = [];
        foreach ($this->steps[$artifactKind] ?? [] as $step) {
            $versions[] = $step->fromVersion;
            $versions[] = $step->toVersion;
        }

        $versions = array_values(array_unique($versions));
        sort($versions, SORT_NUMERIC);

        return $versions;
    }
}

final readonly class AtlasLoopSchemaMigrationStep
{
    public function __construct(
        public string $artifactKind,
        public int $fromVersion,
        public int $toVersion,
        private mixed $transform,
        private mixed $verifier,
        public bool $reversible = false,
    ) {}

    public function transform(string $snapshotBytes): string
    {
        return ($this->transform)($snapshotBytes);
    }

    public function verify(string $snapshotBytes): bool
    {
        return (bool) ($this->verifier)($snapshotBytes);
    }
}

final class AtlasLoopSchemaMigrationDuplicateStepException extends InvalidArgumentException
{
    public function __construct(string $artifactKind, int $fromVersion, int $toVersion)
    {
        parent::__construct(sprintf(
            'Duplicate migration step for artifact "%s": %d -> %d.',
            $artifactKind,
            $fromVersion,
            $toVersion,
        ));
    }
}

final class AtlasLoopSchemaMigrationGapException extends InvalidArgumentException
{
    public function __construct(string $artifactKind, int $currentVersion, int $targetVersion)
    {
        parent::__construct(sprintf(
            'No complete migration chain for artifact "%s" from %d to %d.',
            $artifactKind,
            $currentVersion,
            $targetVersion,
        ));
    }
}
