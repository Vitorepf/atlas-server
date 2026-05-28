<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEvidenceBundleManifestService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunPathResolver;
use Tests\TestCase;

/**
 * Focused contract tests for {@see AtlasForgeRivalsEvidenceBundleManifestService}.
 */
final class AtlasForgeRivalsEvidenceBundleManifestServiceTest extends TestCase
{
    private string $tmpRoot;

    private AtlasForgeRivalsRunPathResolver $paths;

    private AtlasForgeRivalsEvidenceBundleManifestService $bundle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fr-bundle-manifest-'.bin2hex(random_bytes(6));
        @mkdir($this->tmpRoot, 0o755, true);
        config(['atlas_rivals.runs_root' => $this->tmpRoot]);

        $this->paths = new AtlasForgeRivalsRunPathResolver;
        $this->bundle = new AtlasForgeRivalsEvidenceBundleManifestService($this->paths);
    }

    protected function tearDown(): void
    {
        $this->purge($this->tmpRoot);
        parent::tearDown();
    }

    public function test_schema_version_is_stable(): void
    {
        $this->assertSame(
            'atlas.forge.rivals.evidence_bundle_manifest.v1',
            AtlasForgeRivalsEvidenceBundleManifestService::SCHEMA_VERSION,
        );
    }

    public function test_canonical_context_refs_include_focused_unit_test_evidence(): void
    {
        $paths = AtlasForgeRivalsEvidenceBundleManifestService::canonicalContextRefPaths();

        $this->assertContains(
            'tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsEvidenceBundleManifestServiceTest.php',
            $paths,
        );
        $this->assertContains(
            'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsEvidenceBundleManifestService.php',
            $paths,
        );
        $this->assertContains(
            'tests/Feature/Ai/Programming/AtlasForgeRivalsMatrixRunnerTest.php',
            $paths,
        );
    }

    public function test_manifest_blocks_when_run_id_missing(): void
    {
        $result = $this->bundle->manifest([]);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('run_id_required', $result['blockers']);
        $this->assertFalse($result['bundle_ready']);
        $this->assertFalse($result['claim_ready']);
    }

    public function test_manifest_blocks_when_run_not_found(): void
    {
        $result = $this->bundle->manifest(['run_id' => 'nonexistent-run']);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('run_not_found:nonexistent-run', $result['blockers']);
        $this->assertFalse($result['bundle_ready']);
    }

    public function test_manifest_response_never_calls_provider(): void
    {
        $result = $this->bundle->manifest(['run_id' => '   ']);

        $this->assertSame(AtlasForgeRivalsEvidenceBundleManifestService::SCHEMA_VERSION, $result['schema_version']);
        $this->assertFalse($result['external_provider_call']);
        $this->assertFalse($result['provider_tokens_spent']);
        $this->assertTrue($result['advisory_only']);
        $this->assertFalse($result['should_update_provider_topology']);
        $this->assertSame('none', $result['routing_effect']);
        $this->assertSame('atlas_decide', $result['owner_of_model_routing']);
    }

    public function test_manifest_ok_for_minimal_bundle(): void
    {
        $runId = $this->newRunId('ready');
        $paths = $this->paths->paths($runId);
        $this->seedMinimalBundle($runId);

        $result = $this->bundle->manifest(['run_id' => $runId]);

        $this->assertSame('ok', $result['status']);
        $this->assertTrue($result['bundle_ready']);
        $this->assertSame($runId, $result['run_id']);
        $this->assertSame($paths['base'], $result['run_dir']);
        $this->assertFalse($result['claim_ready']);
        $this->assertFalse($result['score_or_claim_allowed']);

        $relativePaths = array_column($result['files'], 'relative_path');
        $this->assertContains('events.jsonl', $relativePaths);
        $this->assertContains('evidence/manifest.json', $relativePaths);
        $this->assertContains('evidence/evidence_pack.json', $relativePaths);
        $this->assertContains('evidence/artifact_index.json', $relativePaths);
        $this->assertStringContainsString('replay --run-id='.$runId, $result['next_command']);
    }

    public function test_manifest_blocks_when_required_bundle_files_are_missing(): void
    {
        $runId = $this->newRunId('blocked');
        $paths = $this->paths->paths($runId);
        @mkdir($paths['evidence'], 0o755, true);
        file_put_contents($paths['manifest_json'], $this->jsonEncode(['run_id' => $runId]));
        file_put_contents($paths['evidence'].'/evidence_pack.json', $this->jsonEncode(['artifacts' => []]));

        $result = $this->bundle->manifest(['run_id' => $runId]);

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['bundle_ready']);
        $this->assertContains('bundle_required_file_missing:events.jsonl', $result['blockers']);
        $this->assertContains('bundle_required_file_missing:evidence/artifact_index.json', $result['blockers']);
    }

    public function test_verify_blocks_when_manifest_input_missing_or_invalid(): void
    {
        $result = $this->bundle->verify(['input' => '/tmp/missing-bundle-manifest.json']);

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['bundle_verified']);
        $this->assertContains('bundle_manifest_input_missing_or_invalid:/tmp/missing-bundle-manifest.json', $result['blockers']);
    }

    public function test_verify_accepts_intact_manifest(): void
    {
        $runId = $this->newRunId('verify');
        $this->seedMinimalBundle($runId);

        $outputPath = $this->tmpRoot.'/portable-'.$runId.'.json';
        $manifest = $this->bundle->manifest([
            'run_id' => $runId,
            'output_path' => $outputPath,
        ]);
        $this->assertSame('ok', $manifest['status']);

        $result = $this->bundle->verify(['input' => $outputPath]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame('atlas.forge.rivals.evidence_bundle_verification.v1', $result['schema_version']);
        $this->assertTrue($result['bundle_verified']);
        $this->assertFalse($result['claim_ready']);
        $this->assertFalse($result['external_provider_call']);
        $this->assertStringContainsString('replay --run-id='.$runId, $result['next_command']);
    }

    public function test_verify_blocks_tampered_file_hash(): void
    {
        $runId = $this->newRunId('tamper');
        $this->seedMinimalBundle($runId);
        $paths = $this->paths->paths($runId);

        $outputPath = $paths['evidence'].'/portable_bundle_manifest.json';
        $manifest = $this->bundle->manifest([
            'run_id' => $runId,
            'output_path' => $outputPath,
        ]);
        $this->assertSame('ok', $manifest['status']);

        file_put_contents($paths['events_jsonl'], json_encode(['kind' => 'tampered']).PHP_EOL);

        $result = $this->bundle->verify(['input' => $outputPath]);

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['bundle_verified']);
        $this->assertContains('bundle_verify_hash_mismatch:events.jsonl', $result['blockers']);
    }

    private function seedMinimalBundle(string $runId): void
    {
        $paths = $this->paths->paths($runId);
        @mkdir($paths['evidence'], 0o755, true);
        file_put_contents($paths['manifest_json'], $this->jsonEncode(['run_id' => $runId]));
        file_put_contents($paths['events_jsonl'], json_encode(['kind' => 'run_started']).PHP_EOL);
        file_put_contents($paths['evidence'].'/artifact_index.json', $this->jsonEncode(['schema_version' => 'test']));
        file_put_contents($paths['evidence'].'/evidence_pack.json', $this->jsonEncode([
            'schema_version' => 'test',
            'run_id' => $runId,
            'artifacts' => [],
        ]));
    }

    private function newRunId(string $suffix): string
    {
        return 'bundle-manifest-'.bin2hex(random_bytes(4)).'-'.$suffix;
    }

    private function jsonEncode(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function purge(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->purge($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
