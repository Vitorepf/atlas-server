<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\Support;

use App\Services\Ai\Programming\Console\ProgrammingConsoleCanon;
use App\Services\Ai\Programming\Support\ProgrammingConsoleEnvelopeSupport as Support;
use App\Services\Ai\ProgrammingRuntime\ControlPlane\ProgrammingRuntimeControlPlaneCanon;
use PHPUnit\Framework\TestCase;

/**
 * Pure Support peel for Programming Console envelope shaping — no I/O, no service, no DB.
 */
final class ProgrammingConsoleEnvelopeSupportTest extends TestCase
{
    public function test_map_status_maps_control_plane_canon_and_defaults_partial(): void
    {
        $this->assertSame(
            ProgrammingConsoleCanon::STATUS_GREEN,
            Support::mapStatus(ProgrammingRuntimeControlPlaneCanon::STATUS_GREEN),
        );
        $this->assertSame(
            ProgrammingConsoleCanon::STATUS_PARTIAL,
            Support::mapStatus(ProgrammingRuntimeControlPlaneCanon::STATUS_PARTIAL),
        );
        $this->assertSame(
            ProgrammingConsoleCanon::STATUS_BLOCKED,
            Support::mapStatus(ProgrammingRuntimeControlPlaneCanon::STATUS_BLOCKED),
        );
        $this->assertSame(ProgrammingConsoleCanon::STATUS_PARTIAL, Support::mapStatus('unknown'));
        $this->assertSame(ProgrammingConsoleCanon::STATUS_PARTIAL, Support::mapStatus(''));
    }

    public function test_normalize_blockers_accepts_items_wrapper_and_flat_list(): void
    {
        $wrapped = Support::normalizeBlockers([
            'items' => [
                'skip',
                [
                    'source' => 'cp',
                    'severity' => 'blocker',
                    'id' => 'b1',
                    'message' => 'msg',
                    'evidence_refs' => ['e1'],
                    'remediation' => 'fix',
                ],
            ],
        ]);

        $this->assertCount(1, $wrapped);
        $this->assertSame('cp', $wrapped[0]['source']);
        $this->assertSame('b1', $wrapped[0]['id']);
        $this->assertSame(['e1'], $wrapped[0]['evidence_refs']);
        $this->assertSame('fix', $wrapped[0]['remediation']);

        $flat = Support::normalizeBlockers([
            ['id' => 'only'],
        ]);
        $this->assertCount(1, $flat);
        $this->assertSame('unknown', $flat[0]['source']);
        $this->assertSame('warn', $flat[0]['severity']);
        $this->assertSame('only', $flat[0]['id']);
        $this->assertSame('', $flat[0]['message']);
        $this->assertSame([], $flat[0]['evidence_refs']);
        $this->assertNull($flat[0]['remediation']);
    }

    public function test_normalize_next_actions_skips_non_arrays_and_defaults(): void
    {
        $out = Support::normalizeNextActions([
            'nope',
            ['description' => 'do thing', 'priority' => 'high', 'source' => 'ops', 'evidence_refs' => ['a']],
            [],
        ]);

        $this->assertCount(2, $out);
        $this->assertSame('high', $out[0]['priority']);
        $this->assertSame('ops', $out[0]['source']);
        $this->assertSame('do thing', $out[0]['description']);
        $this->assertSame(['a'], $out[0]['evidence_refs']);
        $this->assertSame('normal', $out[1]['priority']);
        $this->assertSame('console', $out[1]['source']);
        $this->assertSame('', $out[1]['description']);
    }

    public function test_normalize_readiness_blockers_and_actions(): void
    {
        $blockers = Support::normalizeReadinessBlockers([
            'x',
            [
                'severity' => 'P1',
                'id' => 'r1',
                'detail' => 'detail text',
                'evidence' => ['doc'],
                'remediation' => 'patch',
            ],
        ]);
        $this->assertCount(1, $blockers);
        $this->assertSame('readiness', $blockers[0]['source']);
        $this->assertSame('P1', $blockers[0]['severity']);
        $this->assertSame('detail text', $blockers[0]['message']);
        $this->assertSame(['doc'], $blockers[0]['evidence_refs']);

        $actions = Support::normalizeReadinessNextActions([
            '  run smoke  ',
            '',
            12,
            '  ',
            'next',
        ]);
        $this->assertCount(2, $actions);
        $this->assertSame('run smoke', $actions[0]['description']);
        $this->assertSame('readiness', $actions[0]['source']);
        $this->assertSame('normal', $actions[0]['priority']);
        $this->assertSame([], $actions[0]['evidence_refs']);
        $this->assertSame('next', $actions[1]['description']);
    }

    public function test_extract_evidence_from_snapshot_sorts_unique_refs(): void
    {
        $refs = Support::extractEvidenceFromSnapshot([
            'blockers' => [
                'items' => [
                    ['evidence_refs' => ['z-ref', '', 3, 'a-ref', 'z-ref']],
                    'skip',
                    ['evidence_refs' => 'not-array'],
                ],
            ],
            'certification_summary' => [
                'temporal_certification' => ['id' => 'tc-9'],
            ],
        ]);

        $this->assertSame(['a-ref', 'temporal_certification:tc-9', 'z-ref'], $refs);
    }

    public function test_derive_certification_status_from_by_status_and_temporal(): void
    {
        $this->assertSame(
            ProgrammingConsoleCanon::CERTIFICATION_STATUS_PASSED,
            Support::deriveCertificationStatus([
                'mission_certifications_by_status' => ['passed' => 2],
            ]),
        );
        $this->assertSame(
            ProgrammingConsoleCanon::CERTIFICATION_STATUS_BLOCKED,
            Support::deriveCertificationStatus([
                'mission_certifications_by_status' => ['passed' => 1, 'blocked' => 1],
            ]),
        );
        $this->assertSame(
            ProgrammingConsoleCanon::CERTIFICATION_STATUS_FAILED,
            Support::deriveCertificationStatus([
                'mission_certifications_by_status' => ['passed' => 1, 'failed' => 1],
            ]),
        );
        $this->assertSame(
            ProgrammingConsoleCanon::CERTIFICATION_STATUS_PARTIAL,
            Support::deriveCertificationStatus([
                'mission_certifications_by_status' => ['passed' => 1, 'pending' => 1],
            ]),
        );
        $this->assertSame(
            ProgrammingConsoleCanon::CERTIFICATION_STATUS_NOT_EVALUATED,
            Support::deriveCertificationStatus([]),
        );
        $this->assertSame(
            ProgrammingConsoleCanon::CERTIFICATION_STATUS_PASSED,
            Support::deriveCertificationStatus([
                'temporal_certification' => ['status' => 'passed'],
            ]),
        );
        $this->assertSame(
            ProgrammingConsoleCanon::CERTIFICATION_STATUS_BLOCKED,
            Support::deriveCertificationStatus([
                'temporal_certification' => ['status' => 'failed'],
            ]),
        );
    }

    public function test_summary_and_evidence_status_thresholds(): void
    {
        $this->assertSame(
            ProgrammingConsoleCanon::STATUS_PARTIAL,
            Support::summaryStatus(['available' => false, 'count' => 9]),
        );
        $this->assertSame(
            ProgrammingConsoleCanon::STATUS_GREEN,
            Support::summaryStatus(['available' => true, 'count' => 1]),
        );
        $this->assertSame(
            ProgrammingConsoleCanon::STATUS_PARTIAL,
            Support::summaryStatus(['available' => true, 'count' => 0]),
        );

        $this->assertSame(
            ProgrammingConsoleCanon::STATUS_PARTIAL,
            Support::evidenceStatus([]),
        );
        $this->assertSame(
            ProgrammingConsoleCanon::STATUS_GREEN,
            Support::evidenceStatus(['evidence_present_ratio' => 0.85]),
        );
        $this->assertSame(
            ProgrammingConsoleCanon::STATUS_PARTIAL,
            Support::evidenceStatus(['evidence_present_ratio' => 0.4]),
        );
        $this->assertSame(
            ProgrammingConsoleCanon::STATUS_BLOCKED,
            Support::evidenceStatus(['evidence_present_ratio' => 0.0]),
        );
    }

    public function test_normalize_intent_collapses_whitespace_and_lowercases(): void
    {
        $this->assertSame('hello world', Support::normalizeIntent("  Hello   \n World  "));
    }

    public function test_pack_evidence_refs_prefers_uuid_then_id_and_dedupes(): void
    {
        $refs = Support::packEvidenceRefs([
            ['uuid' => 'u-1'],
            ['id' => 42],
            ['uuid' => 'u-1'],
            [],
            ['id' => ''],
        ]);

        $this->assertSame(['continuation_pack:u-1', 'continuation_pack:42'], $refs);
    }

    public function test_certify_check_shape(): void
    {
        $ok = Support::certifyCheck('gate.a', true, 'ok detail');
        $this->assertSame('gate.a', $ok['id']);
        $this->assertSame('passed', $ok['status']);
        $this->assertSame('high', $ok['severity']);
        $this->assertSame('ok detail', $ok['detail']);
        $this->assertSame([], $ok['evidence_refs']);

        $blocked = Support::certifyCheck('gate.b', false, 'no', 'medium');
        $this->assertSame('blocked', $blocked['status']);
        $this->assertSame('medium', $blocked['severity']);
    }
}
