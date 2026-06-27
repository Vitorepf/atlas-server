<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;

/**
 * ADVERSARIAL-CRITIQUE substrate — actively attacks the inspector with a FROZEN battery of canonical
 * bypass variants and reports any HOLE (an attack the inspector failed to catch with the expected
 * deficiency). Each variant is a known-bad packet shape the brain MUST never seed; the inspector is
 * supposed to catch it. The list of holes is exactly the list of regressions the brain should fix
 * next — a self-detected backlog item, originated by the brain critiquing its OWN gate.
 *
 * Pure + deterministic: no provider, no I/O. Takes an inspector, returns `{attacks_tried, holes}`.
 * Bounded: a small constant battery — N attacks, runs in microseconds, byte-stable output (sorted
 * by attack id) so any drift is a real signal, not noise. Holes empty ⇒ gate is healthy.
 *
 * Author≠judge intact: this auditor surfaces FACTS (the inspector returned X, expected Y); it
 * never originates a fix / never edits the inspector / never gates a packet. The brain reads the
 * holes list and decides whether to author a slice that fixes one. Pétreo (the réu never edits the
 * organ that grades its own gate's coverage — else it'd shrink the battery to fake "no holes").
 */
final class AtlasBrainGateAdversarialAuditor
{
    public const SCHEMA = 'atlas.brain.gate_adversarial_auditor.v1';

    /**
     * Audit the inspector against the canonical battery. Output is deterministic and bounded.
     *
     * @return array{schema:string, attacks_tried:int, holes:list<array{attack:string, expected:string, deficiencies:list<string>}>}
     */
    public function audit(AtlasTaskPacketQualityInspector $inspector): array
    {
        $holes = [];
        $attacks = self::attackBattery();
        // Stable ordering so the hole list is byte-identical on each call (no wall-clock, no randomness).
        ksort($attacks);

        foreach ($attacks as $name => $variant) {
            $report = $inspector->inspect($variant['packet']);
            $deficiencies = array_values(array_map('strval', (array) ($report['deficiencies'] ?? [])));
            if (! in_array($variant['expected'], $deficiencies, true)) {
                $holes[] = [
                    'attack' => (string) $name,
                    'expected' => (string) $variant['expected'],
                    'deficiencies' => $deficiencies,
                ];
            }
        }

        return [
            'schema' => self::SCHEMA,
            'attacks_tried' => count($attacks),
            'holes' => $holes,
        ];
    }

    /**
     * The FROZEN battery — each entry is `{packet, expected: <deficiency key the inspector must fire>}`.
     * Adding an attack is encouraged; removing one is the regression the next adversarial-critique cycle
     * is meant to catch (so a hand-pruned battery shows up as a hole on the very next audit).
     *
     * @return array<string, array{packet: array<string,mixed>, expected: string}>
     */
    private static function attackBattery(): array
    {
        return [
            // BASIC STRUCTURAL ATTACKS (the inspector's BLOCKING set).
            'empty_objective' => [
                'packet' => [
                    'objective' => '   ',
                    'allowed_files' => ['app/Services/Foo.php'],
                    'acceptance_criteria' => ['php artisan test --filter=FooTest passes'],
                    'required_evidence' => ['tests_or_gates_result'],
                ],
                'expected' => 'missing_objective',
            ],
            'empty_allowed_files' => [
                'packet' => [
                    'objective' => 'a perfectly fine, concrete objective that names php artisan',
                    'allowed_files' => [],
                    'acceptance_criteria' => ['php artisan test passes'],
                    'required_evidence' => ['tests_or_gates_result'],
                ],
                'expected' => 'empty_allowed_files',
            ],
            'missing_acceptance' => [
                'packet' => [
                    'objective' => 'a perfectly fine, concrete objective naming app/Services/Foo.php',
                    'allowed_files' => ['app/Services/Foo.php'],
                    'acceptance_criteria' => [],
                    'required_evidence' => ['tests_or_gates_result'],
                ],
                'expected' => 'missing_acceptance_criteria',
            ],
            'missing_evidence' => [
                'packet' => [
                    'objective' => 'a perfectly fine, concrete objective naming app/Services/Foo.php',
                    'allowed_files' => ['app/Services/Foo.php'],
                    'acceptance_criteria' => ['php artisan test --filter=FooTest passes'],
                    'required_evidence' => [],
                ],
                'expected' => 'missing_required_evidence',
            ],
            'bare_directory' => [
                'packet' => [
                    'objective' => 'a perfectly fine, concrete objective naming app/Services',
                    'allowed_files' => ['app/Services/'],
                    'acceptance_criteria' => ['php artisan test --filter=FooTest passes'],
                    'required_evidence' => ['tests_or_gates_result'],
                ],
                'expected' => 'bare_directory_in_allowed_files',
            ],
            'petreo_self_target' => [
                'packet' => [
                    'objective' => 'attempting to edit a pétreo file via the seed-quality bypass naming AtlasEvolutionFrozenJudge.php',
                    'allowed_files' => ['app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php'],
                    'acceptance_criteria' => ['php artisan test --filter=FooTest passes'],
                    'required_evidence' => ['tests_or_gates_result'],
                ],
                'expected' => 'forbidden_self_target_in_allowed_files',
            ],

            // QUALITY ATTACKS (the advisory layer the inspector must STILL surface).
            'vague_objective' => [
                'packet' => [
                    'objective' => 'do the thing', // < 40 chars + no concrete anchor
                    'allowed_files' => ['app/Models/AtlasNonHarnessTarget.php'],
                    'acceptance_criteria' => ['php artisan test --filter=AtlasNonHarnessTargetTest passes'],
                    'required_evidence' => ['tests_or_gates_result'],
                ],
                'expected' => 'vague_objective',
            ],
            'acceptance_not_runnable' => [
                'packet' => [
                    'objective' => 'a perfectly fine, concrete objective naming app/Models/AtlasNonHarnessTarget.php',
                    'allowed_files' => ['app/Models/AtlasNonHarnessTarget.php'],
                    'acceptance_criteria' => ['it is nicer'],
                    'required_evidence' => ['tests_or_gates_result'],
                ],
                'expected' => 'acceptance_not_runnable',
            ],
            // TEST-AUTHORING WITHOUT A TESTS PATH — acceptance demands test authoring AND evidence is
            // tests_or_gates_result, but allowed_files has NO tests/ path. The worker physically cannot
            // satisfy this; the inspector must BLOCK before serving.
            'test_evidence_without_test_in_allowed_files' => [
                'packet' => [
                    'objective' => 'a perfectly fine, concrete objective naming app/Services/AtlasFooBar.php and adding a test',
                    'allowed_files' => ['app/Services/AtlasFooBar.php'], // intentionally no tests/ path
                    'acceptance_criteria' => ['author a new test that asserts AtlasFooBar::run returns true and runs green via php artisan test'],
                    'required_evidence' => ['tests_or_gates_result'],
                ],
                'expected' => 'test_evidence_without_test_in_allowed_files',
            ],

            // PERMANENT human/external-provider dependency — a packet that bakes in a human-loop runtime
            // owner is structurally unable to autonomously evolve; the inspector must flag this BLOCKING.
            'permanent_human_dependency' => [
                'packet' => [
                    'objective' => 'operator approval required permanently for every task running php artisan AtlasFooService boot kernel',
                    'allowed_files' => ['app/Services/AtlasFooService.php'],
                    'acceptance_criteria' => ['php artisan test --filter=AtlasFooServiceTest passes'],
                    'required_evidence' => ['tests_or_gates_result'],
                ],
                'expected' => 'permanent_human_or_external_provider_dependency',
            ],

            // S5 attack — adequacy beyond presence (acceptance never names the changed file).
            'acceptance_coverage_mismatch' => [
                'packet' => [
                    'objective' => 'a perfectly fine, concrete objective naming app/Services/AtlasFooBar.php',
                    'allowed_files' => ['app/Services/AtlasFooBar.php'],
                    'acceptance_criteria' => ['phpunit passes'], // runnable but does NOT name AtlasFooBar
                    'required_evidence' => ['tests_or_gates_result'],
                ],
                'expected' => 'acceptance_coverage_mismatch',
            ],
        ];
    }
}
