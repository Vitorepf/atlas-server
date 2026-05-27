<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Software Company Stewardship Stack · Area Focus Loop ·
 * Evidence Pack Projection (Slice 5, AP-720).
 *
 * Atlas Software Company Stewardship Stack is a stack/capability family inside
 * the Atlas Autonomous Software Company Runtime, not a new OS. This is a pure,
 * read-only projection over a recorded Area Focus cycle
 * ({@see AreaFocusCycleRecorderService}). It produces a completeness-checked
 * evidence pack for the Morning Inbox without re-running the scan, opening a
 * branch, invoking a provider, mutating the repo or persisting anything.
 */
class AreaFocusEvidencePackService
{
    public const PACK_SCHEMA = 'atlas.software_company_stewardship.area_focus_evidence_pack.v1';

    /** Fields a cycle must carry for the evidence pack to be complete. */
    public const REQUIRED_CYCLE_FIELDS = [
        'cycle_id',
        'area_id',
        'report_hash',
        'findings_hash',
        'inbox_hash',
        'work_orders_hash',
        'input_hash',
        'validation_refs',
        'claim_policy',
        'generated_at',
    ];

    /**
     * Build an evidence pack from a recorded cycle.
     *
     * @param  array<string,mixed>  $cycle
     * @return array<string,mixed>
     */
    public function build(array $cycle): array
    {
        [$present, $missing] = $this->completenessFields($cycle);
        $complete = $missing === [];

        $cycleId = (string) ($cycle['cycle_id'] ?? '');

        $core = [
            'schema_version' => self::PACK_SCHEMA,
            'pack_id' => $cycleId !== '' ? 'afep_'.substr(hash('sha256', $cycleId), 0, 16) : 'afep_unanchored',
            'cycle_id' => $cycleId,
            'area_id' => (string) ($cycle['area_id'] ?? ''),
            'report_status' => (string) ($cycle['report_status'] ?? 'unknown'),
            'hashes' => [
                'report_hash' => (string) ($cycle['report_hash'] ?? ''),
                'findings_hash' => (string) ($cycle['findings_hash'] ?? ''),
                'inbox_hash' => (string) ($cycle['inbox_hash'] ?? ''),
                'work_orders_hash' => (string) ($cycle['work_orders_hash'] ?? ''),
                'input_hash' => (string) ($cycle['input_hash'] ?? ''),
                'cycle_hash' => (string) ($cycle['cycle_hash'] ?? ''),
            ],
            'counts' => [
                'finding_count' => (int) ($cycle['finding_count'] ?? 0),
                'work_order_count' => (int) ($cycle['work_order_count'] ?? 0),
                'inbox_decision_count' => (int) ($cycle['inbox_decision_count'] ?? 0),
            ],
            'routing_summary' => is_array($cycle['routing_summary'] ?? null) ? $cycle['routing_summary'] : [],
            'validation_refs' => is_array($cycle['validation_refs'] ?? null) ? $cycle['validation_refs'] : [],
            'completeness' => [
                'required_fields' => self::REQUIRED_CYCLE_FIELDS,
                'present_fields' => $present,
                'missing_fields' => $missing,
                'complete' => $complete,
            ],
            'claim_policy' => $this->claimPolicy(is_array($cycle['claim_policy'] ?? null) ? $cycle['claim_policy'] : []),
            'morning_inbox_ready' => $complete && (string) ($cycle['report_status'] ?? '') !== 'blocked',
        ];
        $core['pack_hash'] = 'sha256:'.MissionCanonicalHash::sha256($core);
        $core['generated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);

        return $core;
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @return array{0:list<string>,1:list<string>}
     */
    private function completenessFields(array $cycle): array
    {
        $present = [];
        $missing = [];
        foreach (self::REQUIRED_CYCLE_FIELDS as $field) {
            $value = $cycle[$field] ?? null;
            $ok = match (true) {
                is_array($value) => $value !== [],
                is_string($value) => $value !== '',
                default => $value !== null,
            };
            if ($ok) {
                $present[] = $field;
            } else {
                $missing[] = $field;
            }
        }

        return [$present, $missing];
    }

    /**
     * Merge the cycle's claim policy with the pack's own read-only guarantees.
     *
     * @param  array<string,mixed>  $cycleClaim
     * @return array<string,mixed>
     */
    private function claimPolicy(array $cycleClaim): array
    {
        return array_merge($cycleClaim, [
            'pack_is_projection_only' => true,
            'writes_state' => false,
            'provider_invoked' => false,
            'opens_branch' => false,
            'mutates_target_repo' => false,
            'touches_secrets' => false,
            'secrets_in_payload' => false,
            'operator_review_required' => true,
            'is_new_os' => false,
        ]);
    }
}
