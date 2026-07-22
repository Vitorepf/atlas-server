<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition\AcosProgram;

use App\Services\Ai\Cognition\AcosProgram\EvidenceVisionThesisComposer;
use App\Services\Ai\Cognition\AcosProgram\EvidenceVisionThesisLifecycle;
use App\Services\Ai\Cognition\AcosProgram\PredictedImpactBand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Multn1706EvidenceVisionThesisTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        EvidenceVisionThesisLifecycle::reset();
    }

    #[Test]
    public function four_regressing_series_windows_derive_thesis_with_cited_evidence(): void
    {
        $out = EvidenceVisionThesisComposer::compose([
            'enabled' => true,
            'series_windows' => [
                ['series' => 'atlas.m.funnel.v1', 'stage' => 'prove_real', 'yield' => 0.58, 'window' => 0],
                ['series' => 'atlas.m.funnel.v1', 'stage' => 'prove_real', 'yield' => 0.47, 'window' => 1],
                ['series' => 'atlas.m.funnel.v1', 'stage' => 'prove_real', 'yield' => 0.36, 'window' => 2],
                ['series' => 'atlas.m.funnel.v1', 'stage' => 'prove_real', 'yield' => 0.25, 'window' => 3],
            ],
        ]);

        $this->assertTrue($out['composed']);
        $this->assertGreaterThanOrEqual(1, $out['thesis_count']);
        $thesis = $out['theses'][0];
        $this->assertStringContainsString('series:atlas.m.funnel.v1', (string) $thesis['claim']);
        $this->assertNotEmpty($thesis['death_criterion']['described_at_birth']);
        $this->assertNotEmpty($thesis['expires_at']);
        foreach ((array) $thesis['evidence'] as $row) {
            $this->assertContains($row['source'], EvidenceVisionThesisComposer::ALLOWED_EVIDENCE_SOURCES);
            $this->assertNotSame('', trim((string) ($row['ref'] ?? '')));
        }
    }

    #[Test]
    public function aligned_candidate_rises_over_non_aligned_with_equal_leverage_and_yield(): void
    {
        $composed = EvidenceVisionThesisComposer::compose([
            'enabled' => true,
            'series_windows' => [
                ['series' => 'atlas.m.funnel.v1', 'stage' => 'prove_real', 'yield' => 0.58, 'window' => 0],
                ['series' => 'atlas.m.funnel.v1', 'stage' => 'prove_real', 'yield' => 0.47, 'window' => 1],
                ['series' => 'atlas.m.funnel.v1', 'stage' => 'prove_real', 'yield' => 0.36, 'window' => 2],
                ['series' => 'atlas.m.funnel.v1', 'stage' => 'prove_real', 'yield' => 0.25, 'window' => 3],
            ],
        ]);
        $valid = [
            ['Wire unrelated', 'app/Unrelated.php', 'comprehension-deepening'],
            ['Wire funnel stage', 'app/Services/Ai/Cognition/AcosProgram/AtlasFlywheelFunnelService.php', 'pattern-design'],
        ];

        $reordered = EvidenceVisionThesisComposer::thesisAwareReorder($valid, $composed['theses'], true);

        $this->assertSame('app/Services/Ai/Cognition/AcosProgram/AtlasFlywheelFunnelService.php', $reordered[0][1]);
    }

    #[Test]
    public function death_criterion_archives_thesis_and_reorder_becomes_byte_identical(): void
    {
        $windows = [
            ['series' => 'atlas.m.funnel.v1', 'stage' => 'prove_real', 'yield' => 0.58, 'window' => 0],
            ['series' => 'atlas.m.funnel.v1', 'stage' => 'prove_real', 'yield' => 0.47, 'window' => 1],
            ['series' => 'atlas.m.funnel.v1', 'stage' => 'prove_real', 'yield' => 0.36, 'window' => 2],
            ['series' => 'atlas.m.funnel.v1', 'stage' => 'prove_real', 'yield' => 0.25, 'window' => 3],
        ];
        $composed = EvidenceVisionThesisComposer::compose(['enabled' => true, 'series_windows' => $windows]);
        EvidenceVisionThesisLifecycle::ingestComposed($composed);
        $valid = [
            ['Wire unrelated', 'app/Unrelated.php', 'comprehension-deepening'],
            ['Wire funnel stage', 'app/Services/Ai/Cognition/AcosProgram/AtlasFlywheelFunnelService.php', 'pattern-design'],
        ];
        $beforeDeath = EvidenceVisionThesisComposer::thesisAwareReorder($valid, EvidenceVisionThesisLifecycle::activeTheses(), true);
        $this->assertNotSame($valid[0][1], $beforeDeath[0][1]);

        $recovered = [
            ['series' => 'atlas.m.funnel.v1', 'stage' => 'prove_real', 'yield' => 0.50, 'window' => 4],
            ['series' => 'atlas.m.funnel.v1', 'stage' => 'prove_real', 'yield' => 0.52, 'window' => 5],
        ];
        $archived = EvidenceVisionThesisLifecycle::evaluateAndArchive(array_merge($windows, $recovered));

        $this->assertNotEmpty($archived);
        $this->assertSame('archived', $archived[0]['status']);
        $this->assertNotNull($archived[0]['archive_receipt']);
        $afterDeath = EvidenceVisionThesisComposer::thesisAwareReorder($valid, EvidenceVisionThesisLifecycle::activeTheses(), true);
        $this->assertSame($valid, $afterDeath);
    }

    #[Test]
    public function operator_string_in_thesis_fields_fails_petreo_fence(): void
    {
        $out = EvidenceVisionThesisComposer::compose([
            'enabled' => true,
            'series_windows' => [
                ['series' => 'atlas.m.funnel.v1', 'stage' => 'prove_real', 'yield' => 0.58, 'window' => 0],
                ['series' => 'atlas.m.funnel.v1', 'stage' => 'prove_real', 'yield' => 0.47, 'window' => 1],
                ['series' => 'atlas.m.funnel.v1', 'stage' => 'prove_real', 'yield' => 0.36, 'window' => 2],
                ['series' => 'atlas.m.funnel.v1', 'stage' => 'prove_real', 'yield' => 0.25, 'window' => 3],
            ],
            'forbidden_strings' => ['operator-private-note-xyzzy'],
        ]);

        $thesis = $out['theses'][0] ?? [];
        $poisoned = $thesis;
        $poisoned['claim'] = (string) ($thesis['claim'] ?? '').' operator-private-note-xyzzy';

        $this->assertFalse(EvidenceVisionThesisComposer::thesisPassesOperatorFence($poisoned, ['operator-private-note-xyzzy']));
    }

    #[Test]
    public function compose_never_emits_more_than_three_theses(): void
    {
        $windows = [];
        for ($i = 0; $i < 4; $i++) {
            $windows[] = ['series' => 'series-a', 'stage' => 's1', 'yield' => 0.9 - ($i * 0.1), 'window' => $i];
            $windows[] = ['series' => 'series-b', 'stage' => 's2', 'yield' => 0.8 - ($i * 0.1), 'window' => $i];
            $windows[] = ['series' => 'series-c', 'stage' => 's3', 'yield' => 0.7 - ($i * 0.1), 'window' => $i];
            $windows[] = ['series' => 'series-d', 'stage' => 's4', 'yield' => 0.6 - ($i * 0.1), 'window' => $i];
        }

        $calibration = PredictedImpactBand::calibration([
            ['band' => 'high', 'realized' => false],
            ['band' => 'high', 'realized' => false],
            ['band' => 'high', 'realized' => false],
            ['band' => 'sweet', 'realized' => true],
            ['band' => 'sweet', 'realized' => true],
            ['band' => 'sweet', 'realized' => true],
        ]);

        $out = EvidenceVisionThesisComposer::compose([
            'enabled' => true,
            'series_windows' => $windows,
            'calibration' => $calibration,
            'leads' => [
                ['target_path' => 'app/A.php', 'evidence' => ['file' => 'docs/a.md', 'line' => 1, 'source' => 'open_gap_ledger']],
                ['target_path' => 'app/A.php', 'evidence' => ['file' => 'docs/a.md', 'line' => 2, 'source' => 'open_gap_ledger']],
                ['target_path' => 'app/B.php', 'evidence' => ['file' => 'docs/b.md', 'line' => 3, 'source' => 'ponytail_debt']],
                ['target_path' => 'app/B.php', 'evidence' => ['file' => 'docs/b.md', 'line' => 4, 'source' => 'ponytail_debt']],
            ],
        ]);

        $this->assertLessThanOrEqual(3, $out['thesis_count']);
        $this->assertCount($out['thesis_count'], $out['theses']);
    }

    #[Test]
    public function flag_off_compose_is_byte_identical_empty(): void
    {
        $out = EvidenceVisionThesisComposer::compose(['enabled' => false]);

        $this->assertFalse($out['composed']);
        $this->assertSame([], $out['theses']);
        $this->assertSame('flag_disabled', $out['status']);
    }
}
