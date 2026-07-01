<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Replenisher;

/**
 * Pure contract gate that ensures accepted frontiers include implementation AND
 * test candidates plus runnable acceptance, preventing the native replenisher
 * from creating test-only or vague packets.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasSelfConstructionNativeReplenisherFrontierContract
{
    public const SCHEMA = 'atlas.self_construction.native_replenisher_frontier_contract.v1';

    /**
     * @param  array{
     *   implementation_files?:list<string>,
     *   test_files?:list<string>,
     *   acceptance_criteria?:list<string>,
     *   runnable_gate?:?string,
     * }  $frontier
     * @return array{
     *   schema:string,
     *   accepted:bool,
     *   blockers:list<string>,
     * }
     */
    public function validate(array $frontier): array
    {
        $blockers = [];

        $implFiles = array_values(array_filter((array) ($frontier['implementation_files'] ?? [])));
        $testFiles = array_values(array_filter((array) ($frontier['test_files'] ?? [])));
        $acceptance = array_values(array_filter((array) ($frontier['acceptance_criteria'] ?? [])));
        $runnableGate = (string) ($frontier['runnable_gate'] ?? '');

        if ($implFiles === []) {
            $blockers[] = 'missing:implementation_files';
        }

        if ($testFiles === []) {
            $blockers[] = 'missing:test_files';
        }

        if ($acceptance === []) {
            $blockers[] = 'missing:acceptance_criteria';
        }

        if ($runnableGate === '') {
            $blockers[] = 'missing:runnable_gate';
        }

        if (count($blockers) > 0) {
            return $this->envelope(false, $blockers);
        }

        return $this->envelope(true, []);
    }

    /** @param  list<string>  $blockers */
    private function envelope(bool $accepted, array $blockers): array
    {
        sort($blockers, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'accepted' => $accepted,
            'blockers' => $blockers,
        ];
    }
}
