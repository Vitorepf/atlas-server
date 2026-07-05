<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition;

use App\Services\Ai\Cognition\AtlasCognitiveMemoryFabricSchemaEvolutionService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use Tests\TestCase;

class AtlasCognitiveMemoryFabricSchemaEvolutionServiceTest extends TestCase
{
    private string $kernelLog;

    private string $admissionLog;

    private string $proposalsLog;

    private AtlasCognitiveMemoryFabricSchemaEvolutionService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $u = uniqid('', true);
        $this->kernelLog = sys_get_temp_dir()."/atlas_acmf_kernel_{$u}.jsonl";
        $this->admissionLog = sys_get_temp_dir()."/atlas_acmf_admission_{$u}.jsonl";
        $this->proposalsLog = sys_get_temp_dir()."/atlas_acmf_proposals_{$u}.jsonl";

        $kernel = new AtlasConstitutionalKernelService;
        $kernel->setViolationsLogPathForTesting($this->kernelLog);
        $admission = new AtlasAutonomyAdmissionService($kernel);
        $admission->setTicketsLogPathForTesting($this->admissionLog);

        $this->svc = new AtlasCognitiveMemoryFabricSchemaEvolutionService($kernel, $admission);
        $this->svc->setProposalsLogPathForTesting($this->proposalsLog);
    }

    protected function tearDown(): void
    {
        @unlink($this->kernelLog);
        @unlink($this->admissionLog);
        @unlink($this->proposalsLog);
        parent::tearDown();
    }

    public function test_proposal_envelope_shape(): void
    {
        $p = $this->svc->propose([
            'current_schema' => 'atlas.example.subsystem.v1',
            'trigger' => 'operator_request',
            'added_fields' => ['retry_count' => 'int'],
        ]);
        $this->assertSame(AtlasCognitiveMemoryFabricSchemaEvolutionService::PROPOSAL_SCHEMA, $p['schema_version']);
        $this->assertSame('atlas.example.subsystem.v1', $p['current_schema']);
        $this->assertSame('atlas.example.subsystem.v2', $p['proposed_next_schema']);
        $this->assertTrue($p['requires_human_approval']);
        $this->assertTrue($p['is_proposal']);
        $this->assertStringStartsWith('sha256:', $p['proposal_hash']);
    }

    public function test_unknown_trigger_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->propose([
            'current_schema' => 'atlas.example.v1',
            'trigger' => 'martian',
        ]);
    }

    public function test_invalid_schema_format_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->propose([
            'current_schema' => 'not_a_schema',
        ]);
    }

    public function test_v9_bumps_to_v10(): void
    {
        $p = $this->svc->propose([
            'current_schema' => 'atlas.acme.runtime.v9',
        ]);
        $this->assertSame('atlas.acme.runtime.v10', $p['proposed_next_schema']);
    }

    public function test_proposal_carries_doc_skeleton(): void
    {
        $p = $this->svc->propose([
            'current_schema' => 'atlas.example.v1',
            'added_fields' => ['field_a', 'field_b'],
            'deprecated_fields' => ['old_field'],
        ]);
        $this->assertStringContainsString('field_a', $p['doc_skeleton']);
        $this->assertStringContainsString('old_field', $p['doc_skeleton']);
        $this->assertStringContainsString('Schema Evolution Proposal', $p['doc_skeleton']);
    }

    public function test_proposals_persisted_append_only(): void
    {
        $this->svc->propose(['current_schema' => 'atlas.x.v1']);
        $this->svc->propose(['current_schema' => 'atlas.y.v1']);
        $this->assertCount(2, $this->svc->listProposals());
    }

    public function test_kernel_and_admission_carried_in_envelope(): void
    {
        $p = $this->svc->propose([
            'current_schema' => 'atlas.x.v1',
            'trigger' => 'operator_request',
        ]);
        $this->assertContains($p['kernel_decision'], ['allow', 'block', 'allow_with_human_approval']);
        $this->assertContains($p['admission_decision'], ['allow_autonomous', 'allow_with_approval', 'deny']);
    }

    // ── detectExtensionPressure ──────────────────────────────────────────────

    public function test_detect_extension_pressure_detects_when_above_threshold(): void
    {
        $root = sys_get_temp_dir().'/acmf_ext_'.uniqid('', true);
        @mkdir($root, 0777, true);

        $schema = 'atlas.acmf.schema_proposal.v1';

        // 5 files with the schema string
        for ($i = 1; $i <= 5; $i++) {
            file_put_contents($root."/match_{$i}.php", "<?php\n// {$schema}\n");
        }
        // 3 files without
        for ($i = 1; $i <= 3; $i++) {
            file_put_contents($root."/nomatch_{$i}.php", "<?php\n// unrelated\n");
        }

        $result = $this->svc->detectExtensionPressure($schema, $root);

        $this->assertSame($schema, $result['schema']);
        $this->assertSame(5, $result['reference_count']);
        $this->assertSame(8, $result['files_scanned']);
        $this->assertSame(4, $result['threshold']);
        $this->assertTrue($result['pressure_detected']);

        $expectedMatched = ['match_1.php', 'match_2.php', 'match_3.php', 'match_4.php', 'match_5.php'];
        sort($expectedMatched, SORT_STRING);
        $this->assertSame($expectedMatched, $result['files_matching']);

        $expectedHash = hash('sha256', implode("\n", $expectedMatched));
        $this->assertSame($expectedHash, $result['scan_hash']);

        $this->cleanTempDir($root);
    }

    public function test_detect_extension_pressure_no_pressure_when_below_threshold(): void
    {
        $root = sys_get_temp_dir().'/acmf_ext_'.uniqid('', true);
        @mkdir($root, 0777, true);

        $schema = 'atlas.acmf.schema_proposal.v1';

        // Only 3 matching files — below threshold (4)
        for ($i = 1; $i <= 3; $i++) {
            file_put_contents($root."/match_{$i}.php", "<?php\n// {$schema}\n");
        }
        // 2 non-matching
        for ($i = 1; $i <= 2; $i++) {
            file_put_contents($root."/nomatch_{$i}.php", "<?php\n// unrelated\n");
        }

        $result = $this->svc->detectExtensionPressure($schema, $root);

        $this->assertSame(3, $result['reference_count']);
        $this->assertSame(5, $result['files_scanned']);
        $this->assertSame(4, $result['threshold']);
        $this->assertFalse($result['pressure_detected']);

        $this->cleanTempDir($root);
    }

    private function cleanTempDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}
