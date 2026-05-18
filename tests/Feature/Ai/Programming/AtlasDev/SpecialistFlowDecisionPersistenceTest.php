<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev;

use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Pipeline\AtlasDevFastPathOrchestrator;
use App\Services\Ai\Programming\AtlasDev\Pipeline\SpecialistFlowDecision;
use Tests\TestCase;

final class SpecialistFlowDecisionPersistenceTest extends TestCase
{
    private string $tmpWorkspace = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-flow-router-test-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace.'/app', recursive: true);
        mkdir($this->tmpWorkspace.'/tests', recursive: true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpWorkspace)) {
            $this->rrmdir($this->tmpWorkspace);
        }
        parent::tearDown();
    }

    public function test_orchestrator_persists_specialist_flow_decision_artifact(): void
    {
        $orchestrator = app(AtlasDevFastPathOrchestrator::class);
        $result = $orchestrator->planOnly(
            surfaceId: 'atlas_cli_dev',
            workspace: $this->tmpWorkspace,
            rawIntent: 'corrija typo em docs/README.md',
        );

        $this->assertNotNull($result->specialistFlow);
        $this->assertContains($result->specialistFlow->specialistFlow, SpecialistFlowDecision::FLOWS);
        $this->assertContains($result->specialistFlow->path, SpecialistFlowDecision::PATHS);
        $this->assertNotEmpty($result->specialistFlow->decisionHash);
        $this->assertSame(64, strlen($result->specialistFlow->decisionHash));

        $this->assertArrayHasKey(
            ArtifactNames::SPECIALIST_FLOW_DECISION,
            $result->persistedArtifactPaths,
        );
        $path = $result->persistedArtifactPaths[ArtifactNames::SPECIALIST_FLOW_DECISION];
        $this->assertFileExists($path);

        $persisted = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(
            SpecialistFlowDecision::SCHEMA_VERSION,
            $persisted['schema_version'] ?? null,
        );
        foreach (['specialist_flow', 'path', 'atlas_ai_flow_id', 'decision_hash', 'task_kind', 'risk_level'] as $field) {
            $this->assertArrayHasKey($field, $persisted);
        }
    }

    public function test_summary_array_exposes_specialist_flow_block(): void
    {
        $orchestrator = app(AtlasDevFastPathOrchestrator::class);
        $result = $orchestrator->planOnly(
            surfaceId: 'atlas_cli_dev',
            workspace: $this->tmpWorkspace,
            rawIntent: 'corrija typo em docs/README.md',
        );

        $summary = $result->toSummaryArray();
        $this->assertArrayHasKey('specialist_flow', $summary);
        $this->assertIsArray($summary['specialist_flow']);
        $this->assertSame(
            SpecialistFlowDecision::SCHEMA_VERSION,
            $summary['specialist_flow']['schema_version'] ?? null,
        );
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
