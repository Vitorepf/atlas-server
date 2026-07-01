<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasExternalBrainCrossProjectPortabilityPlannerWiringWiredTest extends TestCase
{
    private string $tempBase = '';

    private string $inputFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBase = sys_get_temp_dir().'/atlas-regression-repair-portability-'.bin2hex(random_bytes(6));
        @mkdir($this->tempBase, 0o755, true);
        $this->inputFile = $this->tempBase.'/input.json';
    }

    protected function tearDown(): void
    {
        if ($this->tempBase !== '' && is_dir($this->tempBase)) {
            (new Process(['rm', '-rf', $this->tempBase]))->run();
        }
        parent::tearDown();
    }

    private function writeInput(array $data): void
    {
        file_put_contents($this->inputFile, json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    private function runCmd(array $args): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:external-brain:regression-repair', $args);

        return [$exit, $kernel->output()];
    }

    public function test_cross_project_portability_section_reports_portable_with_lane_contract(): void
    {
        $this->writeInput([
            'cross_project_portability' => [
                'project_name' => 'sibling-repo',
                'has_docs_context_sync' => true,
                'has_task_namespace' => true,
                'has_worker_routing' => true,
                'has_evidence_gates' => true,
                'has_workspace_isolation' => true,
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertTrue($decoded['cross_project_portability']['portable']);
        $this->assertSame('atlas.cross_project.sibling-repo', $decoded['cross_project_portability']['lane_contract']['queue_namespace']);
    }

    public function test_cross_project_portability_section_is_absent_when_not_supplied(): void
    {
        $this->writeInput([]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertArrayNotHasKey('cross_project_portability', $decoded);
    }
}
