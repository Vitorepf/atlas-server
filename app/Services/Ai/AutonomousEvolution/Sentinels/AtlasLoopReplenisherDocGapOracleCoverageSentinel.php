<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Sentinels;

use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;

/**
 * REGRESSION SENTINEL — re-proves the test-coverage oracle that {@see \App\Services\Ai\SelfConstruction\AtlasTaskBrainReplenisher}
 * runs over every minted packet (via its `validateAgainstInspector` chokepoint, which delegates to
 * {@see AtlasTaskPacketQualityInspector}) continues to cover BOTH the `app/` and the mirrored `tests/` half.
 *
 * Today's bug class: the oracle was edited and silently stopped checking the test-path side, so a packet that
 * DID grant a `tests/` path was still flagged (false positive), and — worse — an app-only packet that needs
 * test evidence could slip through (false negative / no-op oracle). This sentinel drives two fabricated minimal
 * packets through the SAME oracle and asserts the FACTS:
 *   - allowed_files = [app/Foo.php, tests/Unit/FooTest.php] ⇒ doc-gap RESOLVED (no `doc_gap_unresolved`).
 *   - allowed_files = [app/Foo.php] only                    ⇒ doc-gap UNRESOLVED (`doc_gap_unresolved` once).
 *
 * `doc_gap_unresolved` is this sentinel's name for the oracle's `test_evidence_without_test_in_allowed_files`
 * deficiency — the precise signal that the minted packet asks for a test but grants no test half.
 */
final class AtlasLoopReplenisherDocGapOracleCoverageSentinel
{
    public const SCHEMA = 'atlas.loop.replenisher_docgap_oracle_coverage.v1';

    /** The inspector deficiency that means "asks for a test but grants no tests/ path". */
    private const ORACLE_DEFICIENCY = 'test_evidence_without_test_in_allowed_files';

    public function __construct(private readonly ?AtlasTaskPacketQualityInspector $inspector = null) {}

    /**
     * @return array{schema:string, conformant:bool, both_halves:array<string,mixed>, app_only:array<string,mixed>}
     */
    public function check(): array
    {
        $bothHalves = $this->probe(['app/Foo.php', 'tests/Unit/FooTest.php']);
        $appOnly = $this->probe(['app/Foo.php']);

        // The oracle is healthy ONLY if it RESOLVES the both-halves packet AND still FIRES on the app-only one
        // (i.e. it has not turned into a no-op that passes everything).
        $conformant = $bothHalves['doc_gap_unresolved'] === false && $appOnly['doc_gap_unresolved'] === true;

        return [
            'schema' => self::SCHEMA,
            'conformant' => $conformant,
            'both_halves' => $bothHalves,
            'app_only' => $appOnly,
        ];
    }

    /**
     * @param  list<string>  $allowedFiles
     * @return array{allowed_files:list<string>, deficiencies:list<string>, doc_gap_unresolved:bool}
     */
    private function probe(array $allowedFiles): array
    {
        $inspector = $this->inspector ?? new AtlasTaskPacketQualityInspector;

        // A resolvable-orphan-shaped packet: it asks the worker to CREATE a class AND a passing PHPUnit test
        // (so the oracle's test-authoring + test-evidence branch applies) — exactly what the replenisher mints.
        $packet = [
            'task_packet_id' => 'sentinel-docgap-probe',
            'objective' => 'Create the class App\\Foo and a passing PHPUnit test that covers it.',
            'allowed_files' => $allowedFiles,
            'acceptance_criteria' => [
                'Create the class Foo at app/Foo.php.',
                'Add a passing PHPUnit test that covers Foo.',
            ],
            'required_evidence' => ['tests_or_gates_result'],
        ];

        $deficiencies = array_values((array) ($inspector->inspect($packet)['deficiencies'] ?? []));
        $unresolved = in_array(self::ORACLE_DEFICIENCY, $deficiencies, true);

        return [
            'allowed_files' => array_values($allowedFiles),
            // Surface the gap under this sentinel's stable name, once per real oracle hit.
            'deficiencies' => $unresolved ? ['doc_gap_unresolved'] : [],
            'doc_gap_unresolved' => $unresolved,
        ];
    }
}
