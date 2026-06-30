<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure tournament: runs five deterministic critique lenses over candidate packets and
 * aggregates findings into a blocking verdict. No providers, no DB, no I/O.
 *
 * Lenses (high-severity = blocking, low-severity = allowed tradeoff):
 *   proxy_risk           → objective contains proxy/cleanup keywords          [high]
 *   operator_dependency  → acceptance criteria require human confirmation      [high]
 *   duplicate_target     → two or more packets target the same allowed_file    [high]
 *   false_green_acceptance → ALL acceptance criteria are exit-code-only         [low]
 *   low_leverage         → short objective (<50 chars) or single-file scope    [low]
 *
 * winning_attack = first high-severity lens that fired (null if clean).
 * revised_batch_constraints = deduplicated constraint types from blocking findings.
 */
final class AtlasExternalBrainAdversarialCritiqueTournament
{
    public const SCHEMA = 'atlas.external_brain.adversarial_critique_tournament.v1';

    private const PROXY_KEYWORDS = ['cleanup', 'reformat', 'remove unused', 'rename', 'whitespace'];

    private const OPERATOR_KEYWORDS = ['ask operator', 'human confirms', 'manually', 'operator confirms'];

    private const SHORT_OBJECTIVE_CHARS = 50;

    /**
     * @param  array<string,mixed>  $input  packets list
     * @return array<string,mixed>
     */
    public function run(array $input): array
    {
        $packets = is_array($input['packets'] ?? null) ? $input['packets'] : [];

        $blockingFindings = [];
        $allowedTradeoffs = [];

        // Lens 1: proxy_risk
        foreach ($packets as $i => $p) {
            $obj = strtolower((string) ($p['objective'] ?? ''));
            foreach (self::PROXY_KEYWORDS as $kw) {
                if (str_contains($obj, $kw)) {
                    $blockingFindings[] = [
                        'lens' => 'proxy_risk',
                        'severity' => 'high',
                        'evidence' => "objective contains proxy keyword: '{$kw}'",
                        'packet_index' => $i,
                    ];
                    break;
                }
            }
        }

        // Lens 2: operator_dependency
        foreach ($packets as $i => $p) {
            $criteria = is_array($p['acceptance_criteria'] ?? null) ? $p['acceptance_criteria'] : [];
            $text = strtolower(implode(' ', array_map('strval', $criteria)));
            foreach (self::OPERATOR_KEYWORDS as $kw) {
                if (str_contains($text, $kw)) {
                    $blockingFindings[] = [
                        'lens' => 'operator_dependency',
                        'severity' => 'high',
                        'evidence' => "acceptance criteria contains operator dependency: '{$kw}'",
                        'packet_index' => $i,
                    ];
                    break;
                }
            }
        }

        // Lens 3: duplicate_target
        $filePacketMap = [];
        foreach ($packets as $i => $p) {
            foreach (is_array($p['allowed_files'] ?? null) ? $p['allowed_files'] : [] as $f) {
                $filePacketMap[(string) $f][] = $i;
            }
        }
        foreach ($filePacketMap as $file => $indices) {
            if (count($indices) >= 2) {
                $blockingFindings[] = [
                    'lens' => 'duplicate_target',
                    'severity' => 'high',
                    'evidence' => "file '{$file}' targeted by ".count($indices).' packets',
                    'packet_indices' => $indices,
                ];
            }
        }

        // Lens 4: false_green_acceptance (low severity)
        foreach ($packets as $i => $p) {
            $criteria = is_array($p['acceptance_criteria'] ?? null) ? $p['acceptance_criteria'] : [];
            if ($criteria === []) {
                continue;
            }
            $allExitOnly = array_filter(
                $criteria,
                static fn (mixed $c): bool => ! str_contains(strtolower((string) $c), 'exits 0'),
            ) === [];
            if ($allExitOnly) {
                $allowedTradeoffs[] = [
                    'lens' => 'false_green_acceptance',
                    'severity' => 'low',
                    'evidence' => 'all acceptance criteria are exit-code-only without functional proof',
                    'packet_index' => $i,
                ];
            }
        }

        // Lens 5: low_leverage (low severity)
        foreach ($packets as $i => $p) {
            $obj = (string) ($p['objective'] ?? '');
            $files = is_array($p['allowed_files'] ?? null) ? $p['allowed_files'] : [];
            if (strlen($obj) < self::SHORT_OBJECTIVE_CHARS) {
                $allowedTradeoffs[] = [
                    'lens' => 'low_leverage',
                    'severity' => 'low',
                    'evidence' => 'short objective suggests narrow scope',
                    'packet_index' => $i,
                ];
            } elseif (count($files) === 1) {
                $allowedTradeoffs[] = [
                    'lens' => 'low_leverage',
                    'severity' => 'low',
                    'evidence' => 'single-file scope',
                    'packet_index' => $i,
                ];
            }
        }

        $winningAttack = $blockingFindings !== [] ? $blockingFindings[0]['lens'] : null;

        $constraints = [];
        $seen = [];
        foreach ($blockingFindings as $f) {
            $type = (string) $f['lens'];
            if (! isset($seen[$type])) {
                $seen[$type] = true;
                $constraints[] = ['constraint_type' => $type, 'resolution_required' => true];
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'blocking' => $blockingFindings !== [],
            'winning_attack' => $winningAttack,
            'blocking_findings' => $blockingFindings,
            'allowed_tradeoffs' => $allowedTradeoffs,
            'revised_batch_constraints' => $constraints,
        ];
    }
}
