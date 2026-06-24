<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\V4;

final class AtlasLoopV4SelfArchitectureProposer
{
    private const KINDS = ['add_seam', 'tighten_gate', 'add_lane'];

    /** @var (callable(list<string>, list<string>): array<string,mixed>|null)|null */
    private $architect;

    /** @param (callable(list<string>, list<string>): array<string,mixed>|null)|null $architect */
    public function __construct(?callable $architect = null)
    {
        $this->architect = $architect;
    }

    /**
     * @param  list<mixed>  $brainTopology
     * @param  list<mixed>  $forbiddenSelfTargets
     * @return array{proposed:bool,kind:?string,target_path:?string,rationale:?string,refuse_reason:?string}
     */
    public function propose(array $brainTopology, array $forbiddenSelfTargets): array
    {
        if ($this->architect === null) {
            return $this->refuse('no_architect');
        }

        $topology = $this->normalizedList($brainTopology);
        $forbidden = $this->normalizedList($forbiddenSelfTargets);
        $proposal = ($this->architect)($topology, $forbidden);
        if (! is_array($proposal)) {
            return $this->refuse('no_architect');
        }

        $kind = trim((string) ($proposal['kind'] ?? ''));
        if (! in_array($kind, self::KINDS, true)) {
            return $this->refuse('kind_out_of_vocabulary');
        }

        $targetPath = ltrim(trim((string) ($proposal['target_path'] ?? '')), '/');
        if (! in_array($targetPath, $topology, true)) {
            return $this->refuse('target_not_in_topology');
        }

        if ($this->constitutionForbids($targetPath, $forbidden)) {
            return $this->refuse('constitution_forbids_target');
        }

        $rationale = trim((string) ($proposal['rationale'] ?? ''));
        if (strlen($rationale) < 40) {
            return $this->refuse('rationale_too_short');
        }

        return [
            'proposed' => true,
            'kind' => $kind,
            'target_path' => $targetPath,
            'rationale' => $rationale,
            'refuse_reason' => null,
        ];
    }

    /**
     * @return array{proposed:false,kind:null,target_path:null,rationale:null,refuse_reason:string}
     */
    private function refuse(string $reason): array
    {
        return [
            'proposed' => false,
            'kind' => null,
            'target_path' => null,
            'rationale' => null,
            'refuse_reason' => $reason,
        ];
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function normalizedList(array $values): array
    {
        $normalized = array_values(array_filter(array_map(
            static fn (mixed $value): string => ltrim(trim((string) $value), '/'),
            $values,
        ), static fn (string $value): bool => $value !== ''));
        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    /** @param list<string> $forbiddenSelfTargets */
    private function constitutionForbids(string $targetPath, array $forbiddenSelfTargets): bool
    {
        foreach ($forbiddenSelfTargets as $forbidden) {
            if ($forbidden !== '' && str_contains($targetPath, $forbidden)) {
                return true;
            }
        }

        return false;
    }
}
