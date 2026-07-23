<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

use App\Services\Ai\AgenticEngineeringOs\DeliveryPackCompletenessScorer;

/**
 * TRI-HYGIENE — 15 universal gates catalogue (extracted from façade).
 */
final class UniversalGatesCatalogue
{
    public const FIELD_DESCRIPTION = 'description';

    public const FIELD_CANONICAL_SOURCE = 'canonical_source';

    public const UNIVERSAL_GATES = [
        'lint_green' => [
            self::FIELD_DESCRIPTION => 'Linter passes on all changed files.',
            self::FIELD_CANONICAL_SOURCE => 'tools.lint_runner',
        ],
        'typecheck_green' => [
            self::FIELD_DESCRIPTION => 'Static type check passes (PHPStan/tsc/mypy).',
            self::FIELD_CANONICAL_SOURCE => 'tools.typecheck_runner',
        ],
        'tests_green' => [
            self::FIELD_DESCRIPTION => 'Selected + regression test suite is green.',
            self::FIELD_CANONICAL_SOURCE => 'tools.test_runner',
        ],
        'coverage_min_threshold' => [
            self::FIELD_DESCRIPTION => 'Coverage meets the project floor for the touched scope.',
            self::FIELD_CANONICAL_SOURCE => 'tools.coverage_reporter',
        ],
        'scope_guard_ok' => [
            self::FIELD_DESCRIPTION => 'No file outside declared scope was modified.',
            self::FIELD_CANONICAL_SOURCE => 'governance.scope_guard',
        ],
        'security_scan_clean' => [
            self::FIELD_DESCRIPTION => 'Security scanner (SAST / OWASP) reports no findings above threshold.',
            self::FIELD_CANONICAL_SOURCE => 'security.scan_runner',
        ],
        'dependency_audit_clean' => [
            self::FIELD_DESCRIPTION => 'Dependency CVE audit reports no unacknowledged vulns.',
            self::FIELD_CANONICAL_SOURCE => 'security.dependency_audit',
        ],
        'secret_scan_clean' => [
            self::FIELD_DESCRIPTION => 'No new secrets committed; existing secrets remain quarantined.',
            self::FIELD_CANONICAL_SOURCE => 'security.secret_scan',
        ],
        'sovereignty_boundary_respected' => [
            self::FIELD_DESCRIPTION => 'No sensitive/secret/cyber payload crossed local-first boundary.',
            self::FIELD_CANONICAL_SOURCE => 'security.sovereignty_gate',
        ],
        'decision_receipt_v2_signed' => [
            self::FIELD_DESCRIPTION => 'Decision Receipt v2 is signed and persisted before execution.',
            self::FIELD_CANONICAL_SOURCE => 'governance.decision_receipt_v2',
        ],
        'evidence_traceable' => [
            self::FIELD_DESCRIPTION => 'Every claim links to a hash-addressable evidence artifact.',
            self::FIELD_CANONICAL_SOURCE => 'evidence.ledger',
        ],
        'rollback_plan_present' => [
            self::FIELD_DESCRIPTION => 'Rollback plan exists for every breaking change.',
            self::FIELD_CANONICAL_SOURCE => 'delivery.rollback_planner',
        ],
        'review_packet_signed' => [
            self::FIELD_DESCRIPTION => 'Review department signed the review packet for this delivery.',
            self::FIELD_CANONICAL_SOURCE => 'review.packet_signer',
        ],
        'delivery_pack_completeness_min_0_95' => [
            self::FIELD_DESCRIPTION => 'Delivery pack completeness score >= 0.95.',
            self::FIELD_CANONICAL_SOURCE => DeliveryPackCompletenessScorer::class,
        ],
        'learning_capsule_registered' => [
            self::FIELD_DESCRIPTION => 'A learning_capsule was registered with ACOS for compounding.',
            self::FIELD_CANONICAL_SOURCE => 'memory.learning_capsule_registry',
        ],
    ];

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::UNIVERSAL_GATES);
    }
}
