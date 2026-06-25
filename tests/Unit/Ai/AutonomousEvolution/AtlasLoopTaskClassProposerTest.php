<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\TaskClassDiscovery\AtlasLoopTaskClassProposal;
use App\Services\Ai\AutonomousEvolution\TaskClassDiscovery\AtlasLoopTaskClassProposer;
use RuntimeException;
use Tests\TestCase;

final class AtlasLoopTaskClassProposerTest extends TestCase
{
    private function cluster(array $overrides = []): array
    {
        return array_replace([
            'cluster_id' => 'c-1',
            'class_id' => 'evidence-write-runner',
            'human_label' => 'Evidence Write Runner',
            'shape_rules' => ['signature like "writes evidence kind"'],
            'acceptance_criteria_template' => [
                'Test proves the evidence_write gate emits a JSONL receipt with declared kind.',
            ],
            'required_evidence_kinds' => ['evidence_jsonl'],
            'allowed_files_globs' => ['app/Services/**/Evidence*'],
            'cluster_size' => 12,
            'success_rate' => 0.85,
            'observed_packet_ids_sample' => ['pkt-1', 'pkt-2'],
        ], $overrides);
    }

    public function test_proposal_is_created_in_pending_status(): void
    {
        $proposer = new AtlasLoopTaskClassProposer;
        $proposal = $proposer->propose($this->cluster());

        $this->assertSame(AtlasLoopTaskClassProposal::STATUS_PENDING, $proposal->status);
        $this->assertSame('evidence-write-runner', $proposal->classId);
        $this->assertContains('Test proves the evidence_write gate emits a JSONL receipt with declared kind.', $proposal->defaultAcceptanceCriteriaTemplate);
    }

    public function test_proxy_only_template_is_refused(): void
    {
        $proposer = new AtlasLoopTaskClassProposer;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(AtlasLoopTaskClassProposer::PROXY_ONLY_TEMPLATE);

        $proposer->propose($this->cluster([
            'acceptance_criteria_template' => [
                'PHP file line-count <= 200',
                'presence-of-string "ok"',
                'file-count under 5',
            ],
        ]));
    }

    public function test_low_success_rate_cluster_is_refused(): void
    {
        $proposer = new AtlasLoopTaskClassProposer(successRateFloor: 0.6);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(AtlasLoopTaskClassProposer::SUCCESS_RATE_BELOW_FLOOR);

        $proposer->propose($this->cluster(['success_rate' => 0.40]));
    }

    public function test_proposal_carries_justification_facts_from_cluster(): void
    {
        $proposer = new AtlasLoopTaskClassProposer;
        $proposal = $proposer->propose($this->cluster([
            'cluster_size' => 22,
            'success_rate' => 0.91,
            'observed_packet_ids_sample' => ['a', 'b', 'c'],
        ]));

        $this->assertSame(22, $proposal->justificationFacts['cluster_size']);
        $this->assertEqualsWithDelta(0.91, $proposal->justificationFacts['success_rate'], 1e-9);
        $this->assertSame(['a', 'b', 'c'], $proposal->justificationFacts['observed_packet_ids_sample']);
    }

    public function test_proposal_toarray_carries_schema_and_pending_status_only(): void
    {
        $proposer = new AtlasLoopTaskClassProposer;
        $proposal = $proposer->propose($this->cluster());

        $array = $proposal->toArray();
        $this->assertSame(AtlasLoopTaskClassProposal::SCHEMA, $array['schema_version']);
        $this->assertSame('pending', $array['status']);
        $this->assertArrayNotHasKey('approved', $array, 'a pending proposal must not carry approved fields');
        $this->assertArrayNotHasKey('active', $array);
    }
}
