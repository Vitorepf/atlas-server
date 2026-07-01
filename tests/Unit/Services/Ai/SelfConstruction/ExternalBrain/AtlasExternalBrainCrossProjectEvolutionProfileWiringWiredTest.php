<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCrossProjectEvolutionProfile;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasExternalBrainCrossProjectEvolutionProfileWiringWiredTest extends TestCase
{
    private string $tempBase = '';

    private string $inputFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBase = sys_get_temp_dir().'/atlas-originator-quality-cpep-'.bin2hex(random_bytes(6));
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
        $exit = $kernel->call('atlas:external-brain:originator-quality', $args);

        return [$exit, $kernel->output()];
    }

    public function test_canonical_atlas_profile_is_returned_full_autonomous(): void
    {
        $this->writeInput([
            'opportunities' => [],
            'cross_project_evolution_profile' => ['is_canonical_atlas' => true],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('atlas-server', $decoded['cross_project_evolution_profile']['project_id']);
        $this->assertSame(
            AtlasExternalBrainCrossProjectEvolutionProfile::AUTONOMY_FULL_AUTONOMOUS,
            $decoded['cross_project_evolution_profile']['autonomy_level'],
        );
    }

    public function test_external_project_without_docs_or_targets_is_capped_to_readonly_planning(): void
    {
        $this->writeInput([
            'opportunities' => [],
            'cross_project_evolution_profile' => [
                'project_id' => 'external-repo',
                'autonomy_level' => 'full_autonomous',
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('external-repo', $decoded['cross_project_evolution_profile']['project_id']);
        $this->assertSame(
            AtlasExternalBrainCrossProjectEvolutionProfile::AUTONOMY_READONLY_PLANNING,
            $decoded['cross_project_evolution_profile']['autonomy_level'],
        );
    }

    public function test_cross_project_evolution_profile_section_is_absent_when_not_supplied(): void
    {
        $this->writeInput(['opportunities' => []]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertArrayNotHasKey('cross_project_evolution_profile', $decoded);
    }
}
