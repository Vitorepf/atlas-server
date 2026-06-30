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

    /** Max allowed_files per packet before overwide_allowed_files fires. */
    private const MAX_ALLOWED_FILES = 6;

    /** Minimum packets needed before template_farm_shape check runs. */
    private const MIN_TEMPLATE_FARM_PACKETS = 3;

    /** Fraction of packets sharing the same suffix fingerprint that triggers template_farm_shape. */
    private const TEMPLATE_FARM_THRESHOLD = 0.50;

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

        // Lens 6: proxy_work (high) — mirrors proxy_risk with repair-oriented label
        foreach ($packets as $i => $p) {
            $obj = strtolower((string) ($p['objective'] ?? ''));
            foreach (self::PROXY_KEYWORDS as $kw) {
                if (str_contains($obj, $kw)) {
                    $blockingFindings[] = [
                        'lens'         => 'proxy_work',
                        'severity'     => 'high',
                        'evidence'     => "objective contains proxy-work keyword: '{$kw}'",
                        'packet_index' => $i,
                    ];
                    break;
                }
            }
        }

        // Lens 7: weak_runnable_proof (high) — no acceptance criteria means no runnable proof
        foreach ($packets as $i => $p) {
            $criteria = is_array($p['acceptance_criteria'] ?? null) ? $p['acceptance_criteria'] : [];
            if ($criteria === []) {
                $blockingFindings[] = [
                    'lens'         => 'weak_runnable_proof',
                    'severity'     => 'high',
                    'evidence'     => 'no acceptance criteria defined — cannot verify runnable proof',
                    'packet_index' => $i,
                ];
            }
        }

        // Lens 8: template_farm_shape (high) — ≥3 packets share the same file-suffix fingerprint
        $total = count($packets);
        if ($total >= self::MIN_TEMPLATE_FARM_PACKETS) {
            $suffixBuckets = [];
            foreach ($packets as $p) {
                $fp = $this->fileSuffixFingerprint($p);
                $suffixBuckets[$fp] = ($suffixBuckets[$fp] ?? 0) + 1;
            }
            arsort($suffixBuckets);
            $topFp    = (string) array_key_first($suffixBuckets);
            $topCount = $suffixBuckets[$topFp];
            if ($topCount >= self::MIN_TEMPLATE_FARM_PACKETS && ($topCount / $total) > self::TEMPLATE_FARM_THRESHOLD) {
                $blockingFindings[] = [
                    'lens'     => 'template_farm_shape',
                    'severity' => 'high',
                    'evidence' => "{$topCount} of {$total} packets share suffix fingerprint '{$topFp}' — structural template farm",
                ];
            }
        }

        // Lens 9: hidden_human_dependency (high) — mirrors operator_dependency
        foreach ($packets as $i => $p) {
            $criteria = is_array($p['acceptance_criteria'] ?? null) ? $p['acceptance_criteria'] : [];
            $text     = strtolower(implode(' ', array_map('strval', $criteria)));
            foreach (self::OPERATOR_KEYWORDS as $kw) {
                if (str_contains($text, $kw)) {
                    $blockingFindings[] = [
                        'lens'         => 'hidden_human_dependency',
                        'severity'     => 'high',
                        'evidence'     => "acceptance criteria contains hidden human dependency: '{$kw}'",
                        'packet_index' => $i,
                    ];
                    break;
                }
            }
        }

        // Lens 10: overwide_allowed_files (high) — too many files in one packet
        foreach ($packets as $i => $p) {
            $files = is_array($p['allowed_files'] ?? null) ? $p['allowed_files'] : [];
            if (count($files) > self::MAX_ALLOWED_FILES) {
                $blockingFindings[] = [
                    'lens'         => 'overwide_allowed_files',
                    'severity'     => 'high',
                    'evidence'     => count($files).' allowed_files exceeds maximum '.self::MAX_ALLOWED_FILES,
                    'packet_index' => $i,
                ];
            }
        }

        // Lens 11: duplicate_scope (high) — two or more packets share the same objective text
        $objectiveMap = [];
        foreach ($packets as $i => $p) {
            $norm = strtolower(trim((string) ($p['objective'] ?? '')));
            if ($norm !== '') {
                $objectiveMap[$norm][] = $i;
            }
        }
        foreach ($objectiveMap as $norm => $indices) {
            if (count($indices) >= 2) {
                $blockingFindings[] = [
                    'lens'           => 'duplicate_scope',
                    'severity'       => 'high',
                    'evidence'       => 'identical objective in '.count($indices).' packets: "'.substr($norm, 0, 60).'"',
                    'packet_indices' => $indices,
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
            'schema_version'           => self::SCHEMA,
            'blocking'                 => $blockingFindings !== [],
            'winning_attack'           => $winningAttack,
            'blocking_findings'        => $blockingFindings,
            'allowed_tradeoffs'        => $allowedTradeoffs,
            'revised_batch_constraints' => $constraints,
        ];
    }

    private function fileSuffixFingerprint(array $packet): string
    {
        $files    = (array) ($packet['allowed_files'] ?? []);
        $suffixes = [];
        foreach ($files as $f) {
            $base = basename((string) $f);
            if (preg_match('/([A-Z][a-z]+\.php)$/', $base, $m)) {
                $suffixes[] = $m[1];
            } else {
                $suffixes[] = pathinfo($base, PATHINFO_EXTENSION) ?: '?';
            }
        }
        sort($suffixes);

        return implode(',', array_unique($suffixes));
    }
}
