<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;

/**
 * ADVERSARIAL audit of the SEED-QUALITY GATE — the gate the brain's seed pipeline runs on every packet
 * AFTER the inspector. It PROMOTES three inspector-advisory deficiencies (vague_objective,
 * acceptance_not_runnable, blind_orphan_wiring_proxy) to BLOCKING at the seed boundary. The existing
 * AtlasBrainGateAdversarialAuditor only checks the INSPECTOR; if the seed gate ever silently dropped one
 * of its promotions, the inspector audit stays green AND the muscle starts receiving weak packets again
 * (the exact regression the cert-chain pétreo block was created to prevent, here applied to the seed boundary).
 *
 * This organ runs a frozen battery of variants that the seed gate MUST refuse (admit=false). holes==[]
 * means the seed gate is currently airtight; non-empty means the promotion contract is broken.
 *
 * Pure + deterministic + bounded. Pétreo: réu never edits the audit that grades its OWN promote-set.
 */
final class AtlasBrainSeedGateAdversarialAuditor
{
    public const SCHEMA = 'atlas.brain.seed_gate_adversarial_auditor.v1';

    /**
     * @return array{schema:string, attacks_tried:int, holes:list<array{attack:string, expected:string, blocking:list<string>, admit:bool}>}
     */
    public function audit(AtlasBrainSeedQualityGate $gate): array
    {
        $attacks = self::attackBattery();
        ksort($attacks);

        $holes = [];
        foreach ($attacks as $name => $variant) {
            $report = $gate->evaluate($variant['packet']);
            $admit = (bool) ($report['admit'] ?? false);
            $blocking = array_values(array_map('strval', (array) ($report['blocking'] ?? [])));
            $expected = (string) $variant['expected'];

            // A hole: the seed gate ADMITTED what it should have refused, OR the blocking list missing the expected key.
            if ($admit === true || ! in_array($expected, $blocking, true)) {
                $holes[] = [
                    'attack' => (string) $name,
                    'expected' => $expected,
                    'blocking' => $blocking,
                    'admit' => $admit,
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
     * Each variant pairs a packet shape with the deficiency key the seed gate must list in `blocking[]`.
     * Covers:
     *   - the THREE advisory promotions the seed boundary owns (vague/runnable/blind-proxy);
     *   - a representative inspector-blocking key (proves the seed gate passes through universal blocking).
     *
     * @return array<string, array{packet: array<string,mixed>, expected: string}>
     */
    private static function attackBattery(): array
    {
        return [
            'vague_objective_promoted' => [
                'packet' => [
                    'objective' => 'do the thing', // < 40 chars, no concrete anchor
                    'allowed_files' => ['app/Models/AtlasNonHarnessTarget.php'],
                    'acceptance_criteria' => ['php artisan test --filter=AtlasNonHarnessTargetTest passes'],
                    'required_evidence' => ['tests_or_gates_result'],
                ],
                'expected' => 'vague_objective',
            ],
            'acceptance_not_runnable_promoted' => [
                'packet' => [
                    'objective' => 'a perfectly fine, concrete objective naming app/Models/AtlasNonHarnessTarget.php',
                    'allowed_files' => ['app/Models/AtlasNonHarnessTarget.php'],
                    'acceptance_criteria' => ['it looks better'],
                    'required_evidence' => ['tests_or_gates_result'],
                ],
                'expected' => 'acceptance_not_runnable',
            ],
            'blind_orphan_wiring_proxy_promoted' => [
                'packet' => [
                    'objective' => 'wire the confirmed orphan App\\Services\\AtlasFooBar into the live flow via php artisan boot',
                    'allowed_files' => ['app/Services/AtlasFooBar.php'],
                    'acceptance_criteria' => ['php artisan test --filter=AtlasFooBarTest passes'],
                    'required_evidence' => ['tests_or_gates_result'],
                ],
                'expected' => 'blind_orphan_wiring_proxy',
            ],
            'universal_blocking_passes_through' => [
                'packet' => [
                    'objective' => '   ',
                    'allowed_files' => ['app/Services/Foo.php'],
                    'acceptance_criteria' => ['php artisan test --filter=FooTest passes'],
                    'required_evidence' => ['tests_or_gates_result'],
                ],
                'expected' => 'missing_objective',
            ],
        ];
    }

    /**
     * Convenience constructor when callers don't already have a gate handy. Returns a fresh gate sharing the
     * default inspector — pure-functional w.r.t. inputs.
     */
    public function defaultGate(): AtlasBrainSeedQualityGate
    {
        return new AtlasBrainSeedQualityGate(new AtlasTaskPacketQualityInspector);
    }
}
