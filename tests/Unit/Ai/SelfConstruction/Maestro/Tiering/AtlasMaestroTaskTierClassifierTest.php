<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Tiering;

use App\Services\Ai\SelfConstruction\Maestro\Tiering\AtlasMaestroTaskTierClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Proves the Maestro tier classifier: cross-cutting marker (Constitution/Frozen/Pipeline/Provider) ⇒
 * hardest; tiny surface (≤1 file + <400 chars objective) ⇒ easy; output schema is the canonical
 * 'atlas.maestro.tier_classification.v1' with tier/fact_basis/packet_id/content_hash; identical input ⇒
 * byte-identical JSON.
 */
final class AtlasMaestroTaskTierClassifierTest extends TestCase
{
    public function test_small_console_command_packet_is_easy_with_citing_fact_basis(): void
    {
        $packet = [
            'packet_id' => 'p-easy',
            'objective' => 'Add a tiny CLI helper that prints a static banner.',
            'allowed_files' => ['app/Console/Commands/AtlasFooCommand.php'],
            'acceptance_criteria' => ['banner prints', 'exit code 0'],
        ];

        $r = (new AtlasMaestroTaskTierClassifier)->classify($packet);

        $this->assertSame(AtlasMaestroTaskTierClassifier::TIER_EASY, $r['tier']);
        $this->assertNotEmpty($r['fact_basis']);
        $this->assertStringContainsString('<= 1', $r['fact_basis'][0]);
    }

    public function test_constitution_path_marker_forces_hardest_regardless_of_file_count(): void
    {
        $packet = [
            'packet_id' => 'p-constitution',
            'objective' => 'Tiny edit.',
            'allowed_files' => ['app/Services/Ai/AutonomousEvolution/Constitution/Bylaw.php'],
            'acceptance_criteria' => ['ok'],
        ];

        $r = (new AtlasMaestroTaskTierClassifier)->classify($packet);
        $this->assertSame(AtlasMaestroTaskTierClassifier::TIER_HARDEST, $r['tier']);
        $this->assertStringContainsString('Constitution', $r['fact_basis'][0]);
    }

    public function test_frozen_pipeline_and_provider_markers_each_force_hardest(): void
    {
        $classifier = new AtlasMaestroTaskTierClassifier;
        foreach ([
            ['app/Frozen/Sentinel.php', 'Frozen'],
            ['app/Pipeline/Stage.php', 'Pipeline'],
            ['app/Provider/Adapter.php', 'Provider'],
        ] as [$path, $marker]) {
            $r = $classifier->classify(['packet_id' => 'p', 'objective' => 'x', 'allowed_files' => [$path], 'acceptance_criteria' => []]);
            $this->assertSame(AtlasMaestroTaskTierClassifier::TIER_HARDEST, $r['tier'], "marker {$marker} must escalate to hardest");
            $this->assertStringContainsString($marker, $r['fact_basis'][0]);
        }
    }

    public function test_two_invocations_with_same_input_return_byte_identical_json(): void
    {
        $packet = [
            'packet_id' => 'p-det',
            'objective' => 'Some moderate objective text.',
            'allowed_files' => ['app/A.php', 'app/B.php'],
            'acceptance_criteria' => ['c1', 'c2'],
        ];

        $classifier = new AtlasMaestroTaskTierClassifier;
        $a = json_encode($classifier->classify($packet), JSON_UNESCAPED_SLASHES);
        $b = json_encode($classifier->classify($packet), JSON_UNESCAPED_SLASHES);
        $this->assertSame($a, $b);
    }

    public function test_output_carries_the_canonical_schema_and_required_keys(): void
    {
        $packet = ['packet_id' => 'p-schema', 'objective' => 'x', 'allowed_files' => ['app/X.php'], 'acceptance_criteria' => []];
        $r = (new AtlasMaestroTaskTierClassifier)->classify($packet);

        $this->assertSame('atlas.maestro.tier_classification.v1', $r['schema']);
        $this->assertSame('p-schema', $r['packet_id']);
        $this->assertArrayHasKey('tier', $r);
        $this->assertArrayHasKey('fact_basis', $r);
        $this->assertArrayHasKey('content_hash', $r);
        $this->assertSame(64, strlen($r['content_hash']));
    }

    public function test_large_blast_radius_escalates_to_hard(): void
    {
        $packet = [
            'packet_id' => 'p-hard',
            'objective' => str_repeat('a', 1500), // length >= 1200 trips the rule
            'allowed_files' => ['app/A.php'],
            'acceptance_criteria' => [],
        ];
        $r = (new AtlasMaestroTaskTierClassifier)->classify($packet);
        $this->assertSame(AtlasMaestroTaskTierClassifier::TIER_HARD, $r['tier']);
    }

    public function test_content_hash_changes_when_objective_changes_but_packet_id_stays(): void
    {
        $a = (new AtlasMaestroTaskTierClassifier)->classify(['packet_id' => 'p', 'objective' => 'a', 'allowed_files' => ['app/A.php'], 'acceptance_criteria' => []]);
        $b = (new AtlasMaestroTaskTierClassifier)->classify(['packet_id' => 'p', 'objective' => 'b', 'allowed_files' => ['app/A.php'], 'acceptance_criteria' => []]);
        $this->assertNotSame($a['content_hash'], $b['content_hash']);
    }

    // --- evidence-backed tiering ------------------------------------------

    public function test_repeated_give_back_escalates_to_hardest(): void
    {
        $r = (new AtlasMaestroTaskTierClassifier)->classify([
            'packet_id' => 'p-poison',
            'objective' => 'Normal two-file implementation.',
            'allowed_files' => ['app/Foo.php', 'tests/FooTest.php'],
            'acceptance_criteria' => ['tests green'],
            'give_back_count' => 3,
        ]);
        $this->assertSame(AtlasMaestroTaskTierClassifier::TIER_HARDEST, $r['tier']);
        $foundPoisonFact = false;
        foreach ($r['fact_basis'] as $fact) {
            if (str_contains($fact, 'poison_prone')) {
                $foundPoisonFact = true;
            }
        }
        $this->assertTrue($foundPoisonFact, 'fact_basis must mention poison_prone');
    }

    public function test_broad_scope_file_count_escalates_to_hardest(): void
    {
        $files = array_map(fn ($i) => "app/File{$i}.php", range(1, 10));
        $r = (new AtlasMaestroTaskTierClassifier)->classify([
            'packet_id' => 'p-broad',
            'objective' => 'Touch many files.',
            'allowed_files' => $files,
            'acceptance_criteria' => ['tests green'],
        ]);
        $this->assertSame(AtlasMaestroTaskTierClassifier::TIER_HARDEST, $r['tier']);
        $foundBroadFact = false;
        foreach ($r['fact_basis'] as $fact) {
            if (str_contains($fact, 'broad_scope')) {
                $foundBroadFact = true;
            }
        }
        $this->assertTrue($foundBroadFact, 'fact_basis must mention broad_scope');
    }

    public function test_missing_required_evidence_escalates_easy_to_hard(): void
    {
        // 1 file + short objective = normally easy; missing evidence bumps to hard.
        $r = (new AtlasMaestroTaskTierClassifier)->classify([
            'packet_id' => 'p-evidence',
            'objective' => 'Tiny fix.',
            'allowed_files' => ['app/Foo.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['tests_or_gates_result', 'implementation_notes'],
            'provided_evidence' => ['tests_or_gates_result'], // implementation_notes missing
        ]);
        $this->assertSame(AtlasMaestroTaskTierClassifier::TIER_HARD, $r['tier']);
        $foundEvidenceFact = false;
        foreach ($r['fact_basis'] as $fact) {
            if (str_contains($fact, 'missing_evidence')) {
                $foundEvidenceFact = true;
            }
        }
        $this->assertTrue($foundEvidenceFact, 'fact_basis must mention missing_evidence');
    }

    public function test_final_certification_keyword_escalates_to_hardest(): void
    {
        $r = (new AtlasMaestroTaskTierClassifier)->classify([
            'packet_id' => 'p-cert',
            'objective' => 'Run the final certification suite and sign off.',
            'allowed_files' => ['app/Foo.php'],
            'acceptance_criteria' => ['ok'],
        ]);
        $this->assertSame(AtlasMaestroTaskTierClassifier::TIER_HARDEST, $r['tier']);
        $foundCertFact = false;
        foreach ($r['fact_basis'] as $fact) {
            if (str_contains($fact, 'final-certification')) {
                $foundCertFact = true;
            }
        }
        $this->assertTrue($foundCertFact, 'fact_basis must mention final-certification keyword');
    }

    public function test_narrow_app_plus_test_packet_stays_in_normal_hard_tier(): void
    {
        // 2 files, short objective, no evidence issues, no give_back → normal `hard`.
        $r = (new AtlasMaestroTaskTierClassifier)->classify([
            'packet_id' => 'p-normal',
            'objective' => 'Add a small helper method.',
            'allowed_files' => ['app/Services/Foo.php', 'tests/Unit/FooTest.php'],
            'acceptance_criteria' => ['tests green'],
        ]);
        $this->assertSame(AtlasMaestroTaskTierClassifier::TIER_HARD, $r['tier']);
    }
}
