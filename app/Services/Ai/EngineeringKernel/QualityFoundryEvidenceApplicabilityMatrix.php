<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

/**
 * Constitutional applicability/evidence floor for the Verification Court.
 * The same risk and delivery facts produce the same required dimensions for
 * every mode; a mode is never a valid reason to waive evidence.
 */
final class QualityFoundryEvidenceApplicabilityMatrix
{
    /** @var list<string> */
    public const DIMENSIONS = [
        'unit', 'integration', 'contract', 'e2e', 'security', 'privacy',
        'performance', 'accessibility', 'chaos', 'recovery', 'replay', 'static',
        'compatibility', 'migration', 'rollback', 'outcome', 'mutation',
        'property', 'metamorphic',
    ];

    /** @param array<string,mixed> $facts @return array<string,mixed> */
    public function evaluate(array $facts): array
    {
        $risk = (string) ($facts['risk_class'] ?? 'R0');
        $delivery = is_array($facts['delivery_facts'] ?? null) ? $facts['delivery_facts'] : [];
        $required = $this->requiredDimensions($risk, $delivery);
        $evidence = is_array($facts['evidence'] ?? null) ? $facts['evidence'] : [];
        $results = [];
        $blockers = [];

        foreach ($required as $dimension) {
            $row = is_array($evidence[$dimension] ?? null) ? $evidence[$dimension] : null;
            if ($row === null) {
                $results[$dimension] = 'missing';
                $blockers[] = 'evidence_missing:'.$dimension;

                continue;
            }
            $status = (string) ($row['status'] ?? '');
            if ($status === 'pass' && trim((string) ($row['receipt_hash'] ?? '')) !== '') {
                $results[$dimension] = 'pass';

                continue;
            }
            if ($status === 'not_applicable'
                && ($row['na_proof'] ?? false) === true
                && trim((string) ($row['rule'] ?? '')) !== ''
                && trim((string) ($row['justification'] ?? '')) !== ''
                && trim((string) ($row['evidence_hash'] ?? '')) !== '') {
                $results[$dimension] = 'not_applicable';

                continue;
            }
            $results[$dimension] = 'invalid';
            $blockers[] = 'evidence_invalid:'.$dimension;
        }

        return [
            'schema' => 'atlas.quality_foundry.evidence_applicability.v1',
            'risk_class' => $risk,
            'required' => $required,
            'results' => $results,
            'blockers' => array_values(array_unique($blockers)),
            'accepted' => $blockers === [],
        ];
    }

    /** @param array<string,mixed> $delivery @return list<string> */
    private function requiredDimensions(string $risk, array $delivery): array
    {
        $level = (int) ltrim(strtoupper($risk), 'R');
        $required = ['unit'];
        if ($level >= 2 || ($delivery['integration'] ?? false) === true) {
            $required[] = 'integration';
        }
        if ($level >= 2 || ($delivery['public_contract'] ?? false) === true) {
            $required[] = 'contract';
        }
        if ($level >= 3 || ($delivery['runtime_boundary'] ?? false) === true) {
            $required[] = 'e2e';
            $required[] = 'compatibility';
            $required[] = 'replay';
        }
        if ($level >= 2) {
            $required[] = 'static';
        }
        if (($delivery['security_sensitive'] ?? false) === true || $level >= 4) {
            $required[] = 'security';
            $required[] = 'privacy';
        }
        if (($delivery['performance_sensitive'] ?? false) === true) {
            $required[] = 'performance';
        }
        if (($delivery['user_facing'] ?? false) === true) {
            $required[] = 'accessibility';
        }
        if ($level >= 4) {
            $required = array_merge($required, ['chaos', 'recovery', 'mutation', 'property', 'metamorphic']);
        }
        if (($delivery['migration'] ?? false) === true) {
            $required[] = 'migration';
        }
        if (($delivery['mutative'] ?? false) === true) {
            $required[] = 'rollback';
        }
        if (($delivery['outcome_claimed'] ?? false) === true) {
            $required[] = 'outcome';
        }

        $required = array_values(array_unique(array_filter($required, static fn (string $dimension): bool => in_array($dimension, self::DIMENSIONS, true))));
        sort($required, SORT_STRING);

        return $required;
    }
}
