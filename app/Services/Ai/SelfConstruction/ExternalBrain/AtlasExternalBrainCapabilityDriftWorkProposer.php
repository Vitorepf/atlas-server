<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Converts read-only capability drift observations into one bounded, bridge-ready
 * Task Fabric proposal. It never writes claims/routes/queues and treats missing
 * comparison facts as unknown rather than as regression.
 */
final class AtlasExternalBrainCapabilityDriftWorkProposer
{
    public const SCHEMA = 'atlas.external_brain.capability_drift_work_proposer.v1';

    public function propose(array $input): array
    {
        $frozen = is_array($input['frozen_facts'] ?? null) ? $input['frozen_facts'] : [];
        $current = is_array($input['current_facts'] ?? null) ? $input['current_facts'] : [];
        $targets = is_array($input['target_paths'] ?? null) ? $input['target_paths'] : [];
        $existing = $this->fingerprints($input['existing_proposals'] ?? []);
        $claims = $this->areas($input['active_claims'] ?? []);
        $reservations = $this->areas($input['active_reservations'] ?? []);
        $findings = [];
        $proposals = [];

        foreach ((array) ($input['findings'] ?? []) as $finding) {
            if (! is_array($finding)) {
                continue;
            }
            $area = trim((string) ($finding['area_id'] ?? ''));
            $type = trim((string) ($finding['drift_type'] ?? ''));
            $fingerprint = $this->fingerprint($area, $type, $frozen, $current);
            $classification = $this->classify($finding, $frozen, $current);
            $row = $finding + ['classification' => $classification, 'fingerprint' => $fingerprint];

            if ($classification !== 'material_regression') {
                $row['blocked_reason'] = $classification === 'expected_drift' ? 'expected_drift' : 'unknown_facts';
                $findings[] = $row;
                continue;
            }
            if (isset($claims[$area])) {
                $row['blocked_reason'] = 'active_claim';
                $findings[] = $row;
                continue;
            }
            if (isset($reservations[$area])) {
                $row['blocked_reason'] = 'active_reservation';
                $findings[] = $row;
                continue;
            }
            if (isset($existing[$fingerprint])) {
                $row['blocked_reason'] = 'duplicate_fingerprint';
                $findings[] = $row;
                continue;
            }

            $target = trim((string) ($targets[$area] ?? ''));
            if ($target === '') {
                $row['blocked_reason'] = 'missing_repair_target';
                $findings[] = $row;
                continue;
            }

            $proposal = $this->proposal($finding, $target, $fingerprint, $frozen, $current);
            $proposals[] = $proposal;
            // Collapse an alert storm within the same observation batch as well as
            // against persisted proposals; only the first governed response survives.
            $existing[$fingerprint] = true;
            $findings[] = $row + ['destination' => 'task_fabric_proposal'];
        }

        return [
            'schema' => self::SCHEMA,
            'has_material_drift' => $proposals !== [],
            'findings' => $findings,
            'proposals' => $proposals,
            'active_claims' => $input['active_claims'] ?? [],
            'active_reservations' => $input['active_reservations'] ?? [],
        ];
    }

    private function classify(array $finding, array $frozen, array $current): string
    {
        if (($finding['expected_drift'] ?? false) === true) {
            return 'expected_drift';
        }
        if ($frozen === [] || $current === []) {
            return 'unknown';
        }
        if (($current['outcome'] ?? null) === 'failure' || ($finding['material_regression'] ?? false) === true) {
            return 'material_regression';
        }
        return 'unknown';
    }

    private function fingerprint(string $area, string $type, array $frozen, array $current): string
    {
        return implode(':', [$area, $type, $frozen['route_version'] ?? '', $current['route_version'] ?? '', $frozen['world_hash'] ?? '', $current['world_hash'] ?? '', $frozen['claim_version'] ?? '', $current['claim_version'] ?? '']);
    }

    private function proposal(array $finding, string $target, string $fingerprint, array $frozen, array $current): array
    {
        $area = (string) $finding['area_id'];
        $type = (string) $finding['drift_type'];
        $evidence = array_values(array_unique(array_merge((array) ($finding['evidence_needed'] ?? []), ['frozen_route_version', 'current_world_hash', 'observed_outcome'])));

        return [
            'destination' => 'task_fabric_proposal',
            'fingerprint' => $fingerprint,
            'objective' => "Repair material {$type} drift in {$area} and revalidate the frozen contract.",
            'allowed_files' => [$target],
            'acceptance_criteria' => ['Run the focused drift regression gate and assert the observed outcome returns to the registered baseline.'],
            'required_evidence' => $evidence,
            'evidence_refs' => $evidence,
            'leverage_reason' => "Material drift in {$area} invalidates a previously frozen route/world comparison.",
            'finding' => "{$type} detected for {$area} against frozen route/world/claim facts.",
            'baseline' => 'Frozen facts were accepted before the current material regression.',
            'expected_structural_delta' => 'Restore the governed contract and add evidence that prevents recurrence of this drift.',
            'red_behavior' => 'The current outcome contradicts the frozen route/world evidence.',
            'green_acceptance' => 'Focused gate passes and a new observation confirms the repaired contract.',
            'rollback' => 'Revert the scoped target and return the route to its last frozen version; claims remain untouched.',
            'outcome_metric' => 'Post-repair failure rate for the scoped area is no worse than the frozen baseline over the registered window.',
            'frozen_facts' => $frozen,
            'current_facts' => $current,
        ];
    }

    private function fingerprints(mixed $items): array
    {
        $set = [];
        foreach ((array) $items as $item) {
            if (is_array($item) && trim((string) ($item['fingerprint'] ?? '')) !== '') {
                $set[(string) $item['fingerprint']] = true;
            }
        }
        return $set;
    }

    private function areas(mixed $items): array
    {
        $set = [];
        foreach ((array) $items as $item) {
            if (is_array($item) && $this->claimOrReservationIsActive($item) && trim((string) ($item['area_id'] ?? '')) !== '') {
                $set[(string) $item['area_id']] = true;
            }
        }
        return $set;
    }

    private function claimOrReservationIsActive(array $item): bool
    {
        $expiresAt = trim((string) ($item['expires_at'] ?? $item['expiry'] ?? ''));
        if ($expiresAt === '') return true;
        $expires = date_create_immutable($expiresAt);
        return $expires === false || $expires > new \DateTimeImmutable('now');
    }
}
