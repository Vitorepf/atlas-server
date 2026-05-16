<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Gate;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopePreExistingChange;
use InvalidArgumentException;

/**
 * Snapshot of the worktree at the moment before the provider call.
 *
 * Atlas Dev ScopeGuard refuses to capture this synchronously — the upstream
 * pipeline is responsible for taking the snapshot and passing it in, so that
 * worktree state doesn't shift between baseline capture and diff
 * verification. Tests pass an explicit baseline; production wires a probe
 * that runs `git status --porcelain` before the call.
 *
 * `preExistingChanges` records every path that was already dirty BEFORE the
 * provider ran. The ScopeGuard then verifies each is still preserved in the
 * observed worktree after the provider call.
 */
final class WorktreeBaseline
{
    /**
     * @param  list<ScopePreExistingChange>  $preExistingChanges
     */
    public function __construct(
        public readonly string $gitStatusBefore,
        public readonly ?string $gitDiffBeforeHash,
        public readonly array $preExistingChanges = [],
    ) {
        foreach ($this->preExistingChanges as $i => $change) {
            if (! $change instanceof ScopePreExistingChange) {
                throw new InvalidArgumentException("WorktreeBaseline.preExistingChanges[{$i}] must be ScopePreExistingChange.");
            }
        }
    }

    public static function clean(): self
    {
        return new self(gitStatusBefore: '', gitDiffBeforeHash: null);
    }

    /**
     * @return list<string>
     */
    public function preExistingPaths(): array
    {
        return array_values(array_map(
            static fn (ScopePreExistingChange $c): string => $c->path,
            $this->preExistingChanges,
        ));
    }
}
