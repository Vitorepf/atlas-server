<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ArchitectureCouncil;

use RuntimeException;

/**
 * Pure designer. Turns ACCEPTED architecture contracts + invariants + boundary map into ATOMIC
 * IMPLEMENTATION SLICE BRIEFS for Task Fabric. NEVER enqueues tasks. NEVER writes files.
 *
 * INPUT:
 *   { critique:{accepted:bool}, invariants:list<string>, boundary_map:{forbidden_edges:list<...>},
 *     capability_gap:{
 *       organ:string, capability:string,
 *       target_files:list<{kind:'service'|'cli'|'test', path:string}>,
 *       acceptance_seed:list<string>, evidence_seed:list<string>
 *     } }
 *
 * OUTPUT:
 *   { schema, slice_briefs:list<{slice_id, target_class, test_class, allowed_files_hint:list<string>,
 *                                acceptance_seed:list<string>, evidence_seed:list<string>}> }
 *
 * THROWS RuntimeException when:
 *   - critique.accepted !== true
 *   - invariants is empty
 *   - boundary_map.forbidden_edges has any entry (hard boundary failure)
 *   - capability_gap.target_files lacks any non-test file
 *
 * INVARIANTS:
 *   - DETERMINISTIC ordering: one slice brief per non-test target file; brief_id =
 *     'slice:'+organ+':'+capability+':'+basename.
 *   - PURE — no I/O.
 */
final class AtlasArchitectureCouncilImplementationSliceDesigner
{
    public const SCHEMA = 'atlas.architecturecouncil.slice_designer.v1';

    /**
     * @param  array{
     *     critique?:array{accepted?:bool},
     *     invariants?:list<string>,
     *     boundary_map?:array{forbidden_edges?:list<array<string,mixed>>},
     *     capability_gap?:array{
     *         organ:string,
     *         capability:string,
     *         target_files:list<array{kind:string, path:string}>,
     *         acceptance_seed:list<string>,
     *         evidence_seed:list<string>
     *     }
     * }  $facts
     * @return array{schema:string, slice_briefs:list<array<string,mixed>>}
     */
    public function design(array $facts): array
    {
        if (! (bool) ($facts['critique']['accepted'] ?? false)) {
            throw new RuntimeException('slice_designer: critique not accepted');
        }
        $invariants = is_array($facts['invariants'] ?? null) ? array_values(array_map('strval', $facts['invariants'])) : [];
        if ($invariants === []) {
            throw new RuntimeException('slice_designer: empty invariants');
        }
        $forbidden = is_array($facts['boundary_map']['forbidden_edges'] ?? null) ? $facts['boundary_map']['forbidden_edges'] : [];
        if ($forbidden !== []) {
            throw new RuntimeException('slice_designer: boundary_map has '.count($forbidden).' forbidden_edges');
        }
        $gap = is_array($facts['capability_gap'] ?? null) ? $facts['capability_gap'] : [];
        $organ = trim((string) ($gap['organ'] ?? ''));
        $capability = trim((string) ($gap['capability'] ?? ''));
        if ($organ === '' || $capability === '') {
            throw new RuntimeException('slice_designer: capability_gap missing organ or capability');
        }
        $files = is_array($gap['target_files'] ?? null) ? array_values($gap['target_files']) : [];
        $services = array_values(array_filter($files, static fn ($f): bool => is_array($f) && in_array((string) ($f['kind'] ?? ''), ['service', 'cli'], true)));
        if ($services === []) {
            throw new RuntimeException('slice_designer: capability_gap target_files lacks any service/cli file');
        }
        $tests = array_values(array_filter($files, static fn ($f): bool => is_array($f) && (string) ($f['kind'] ?? '') === 'test'));
        $acceptance = is_array($gap['acceptance_seed'] ?? null) ? array_values(array_map('strval', $gap['acceptance_seed'])) : [];
        $evidence = is_array($gap['evidence_seed'] ?? null) ? array_values(array_map('strval', $gap['evidence_seed'])) : [];

        $briefs = [];
        foreach ($services as $svc) {
            $svcPath = (string) ($svc['path'] ?? '');
            $base = pathinfo($svcPath, PATHINFO_FILENAME);
            $testPath = $this->matchingTest($base, $tests);
            $allowed = $testPath === null ? [$svcPath] : [$svcPath, $testPath];
            $briefs[] = [
                'slice_id' => 'slice:'.$organ.':'.$capability.':'.$base,
                'target_class' => $base,
                'test_class' => $testPath === null ? null : pathinfo($testPath, PATHINFO_FILENAME),
                'allowed_files_hint' => $allowed,
                'acceptance_seed' => $acceptance,
                'evidence_seed' => $evidence,
            ];
        }

        usort($briefs, static fn (array $a, array $b): int => strcmp($a['slice_id'], $b['slice_id']));

        return ['schema' => self::SCHEMA, 'slice_briefs' => $briefs];
    }

    /**
     * @param  list<array<string,mixed>>  $tests
     */
    private function matchingTest(string $base, array $tests): ?string
    {
        $expected = $base.'Test.php';
        foreach ($tests as $t) {
            $p = (string) ($t['path'] ?? '');
            if (basename($p) === $expected) {
                return $p;
            }
        }
        foreach ($tests as $t) {
            $p = (string) ($t['path'] ?? '');
            if (str_contains(basename($p), $base)) {
                return $p;
            }
        }

        return null;
    }
}
