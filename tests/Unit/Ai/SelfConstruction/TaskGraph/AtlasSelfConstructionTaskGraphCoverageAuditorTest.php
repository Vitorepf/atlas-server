<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskGraph;

use App\Services\Ai\SelfConstruction\TaskGraph\AtlasSelfConstructionTaskGraphCoverageAuditor;
use Tests\TestCase;

class AtlasSelfConstructionTaskGraphCoverageAuditorTest extends TestCase
{
    private function organs(): array
    {
        return [
            ['organ_id' => 'cortex', 'required_task_tags' => ['self_construction', 'cortex']],
            ['organ_id' => 'verification_court', 'required_task_tags' => ['self_construction', 'verification_court']],
        ];
    }

    private function auditor(): AtlasSelfConstructionTaskGraphCoverageAuditor
    {
        return new AtlasSelfConstructionTaskGraphCoverageAuditor(organMap: null, organsOverride: $this->organs());
    }

    private function fullRecord(string $organId, string $status = 'claimable'): array
    {
        return [
            'status' => $status,
            'task_packet' => [
                'task_packet_id' => $organId.'-pkt',
                'tags' => ['self_construction', $organId],
                'acceptance_criteria' => ['noop'],
                'required_evidence' => ['tests_or_gates_result'],
                'evidence_classes' => ['implementation', 'gate', 'receipt', 'cli_or_readiness'],
            ],
        ];
    }

    public function test_full_coverage_passes_with_no_blockers(): void
    {
        $records = [
            $this->fullRecord('cortex'),
            $this->fullRecord('verification_court'),
        ];

        $verdict = $this->auditor()->audit($records);

        self::assertTrue($verdict['passed']);
        self::assertSame('covered', $verdict['status']);
        self::assertSame([], $verdict['blockers']);
        self::assertSame('covered', $verdict['organ_coverage']['cortex']);
        self::assertSame('covered', $verdict['organ_coverage']['verification_court']);
    }

    public function test_missing_organ_when_no_records_match(): void
    {
        $verdict = $this->auditor()->audit([$this->fullRecord('cortex')]);

        self::assertFalse($verdict['passed']);
        self::assertContains('verification_court', $verdict['missing_organs']);
        self::assertContains('missing_organ:verification_court', $verdict['blockers']);
    }

    public function test_thin_organ_when_evidence_classes_incomplete(): void
    {
        $thin = $this->fullRecord('verification_court');
        $thin['task_packet']['evidence_classes'] = ['implementation']; // missing gate/receipt/cli_or_readiness

        $records = [$this->fullRecord('cortex'), $thin];

        $verdict = $this->auditor()->audit($records);

        self::assertFalse($verdict['passed']);
        self::assertSame('thin', $verdict['organ_coverage']['verification_court']);
        self::assertContains('thin_organ:verification_court', $verdict['blockers']);
        $thinRow = $verdict['thin_organs'][0];
        self::assertContains('gate', $thinRow['missing_evidence_classes']);
    }

    public function test_stale_organ_when_only_legacy_records_match(): void
    {
        $legacy = $this->fullRecord('verification_court', 'completed_dry_run');

        $records = [$this->fullRecord('cortex'), $legacy];

        $verdict = $this->auditor()->audit($records);

        self::assertSame('stale', $verdict['organ_coverage']['verification_court']);
        self::assertContains('verification_court', $verdict['stale_organs']);
        self::assertContains('stale_organ:verification_court', $verdict['blockers']);
    }

    public function test_blocked_organ_when_only_blocked_records_match(): void
    {
        $blocked = $this->fullRecord('verification_court', 'blocked');

        $records = [$this->fullRecord('cortex'), $blocked];

        $verdict = $this->auditor()->audit($records);

        self::assertSame('blocked', $verdict['organ_coverage']['verification_court']);
        self::assertContains('verification_court', $verdict['blocked_organs']);
        self::assertContains('blocked_organ:verification_court', $verdict['blockers']);
    }

    public function test_mixed_statuses_only_self_sufficient_record_drives_coverage(): void
    {
        $blocked = $this->fullRecord('verification_court', 'blocked');
        $live = $this->fullRecord('verification_court', 'claimed');

        $records = [$this->fullRecord('cortex'), $blocked, $live];

        $verdict = $this->auditor()->audit($records);

        self::assertTrue($verdict['passed']);
        self::assertSame('covered', $verdict['organ_coverage']['verification_court']);
    }

    public function test_legacy_full_plus_live_empty_yields_thin_not_covered(): void
    {
        // legacy record has full evidence_classes; live record has none
        $legacy = $this->fullRecord('verification_court', 'completed_dry_run');
        $live = $this->fullRecord('verification_court'); // status=claimable (live)
        $live['task_packet']['evidence_classes'] = [];

        $records = [$this->fullRecord('cortex'), $legacy, $live];

        $verdict = $this->auditor()->audit($records);

        self::assertSame('thin', $verdict['organ_coverage']['verification_court'],
            'legacy evidence_classes must not mask a live record with no classes');
        self::assertContains('thin_organ:verification_court', $verdict['blockers']);
    }

    public function test_inspected_count_matches_record_count_and_proof_summary_is_present(): void
    {
        $records = [
            $this->fullRecord('cortex'),
            $this->fullRecord('verification_court'),
        ];

        $verdict = $this->auditor()->audit($records);

        self::assertSame(2, $verdict['inspected_count']);
        self::assertStringContainsString('organs=2', $verdict['proof_summary']);
        self::assertArrayNotHasKey('score', $verdict);
    }
}
