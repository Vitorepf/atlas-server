<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Quality;

use App\Services\Ai\AutonomousEvolution\Quality\AtlasImplementationQualityScorer;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\FinalDeliveryQualityGateService;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Provas REAIS do quality scorer (AP-820): sem mock do scorer, phpstan/pint canônicos
 * de verdade contra workspaces git temporários. O workspace candidato é sempre DADO —
 * nada dele é executado dentro do processo de teste.
 */
final class AtlasImplementationQualityScorerTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (is_dir($dir)) {
                (new Process(['rm', '-rf', $dir]))->run();
            }
        }
        $this->tempDirs = [];

        parent::tearDown();
    }

    public function test_no_change_scores_exact_neutral(): void
    {
        $workspace = $this->gitWorkspace($this->cleanSample());

        $result = $this->scorer()->score($workspace);

        $this->assertSame(AtlasImplementationQualityScorer::SCHEMA_VERSION, $result['schema_version']);
        $this->assertSame(0.5, $result['score']);
        $this->assertNull($result['hard_zero_reason']);
        $this->assertFalse($result['gates']['has_changes']);
        $this->assertTrue($result['gates']['workspace_is_git']);
        $this->assertSame([], $result['changed_files']);
    }

    public function test_fixing_a_static_error_climbs_above_neutral(): void
    {
        $workspace = $this->gitWorkspace($this->brokenSample());
        file_put_contents($workspace.'/src/Sample.php', $this->cleanSample());

        $result = $this->scorer()->score($workspace);

        $this->assertNull($result['hard_zero_reason']);
        $this->assertGreaterThan(0.5, $result['score']);
        $this->assertLessThan(0, $result['components']['phpstan_delta']);
        $this->assertSame(0, $result['components']['phpstan_workspace_errors']);
        $this->assertGreaterThan(0, $result['components']['phpstan_baseline_errors']);
    }

    public function test_adding_a_static_error_drops_below_neutral(): void
    {
        $workspace = $this->gitWorkspace($this->cleanSample());
        file_put_contents($workspace.'/src/Sample.php', $this->brokenSample());

        $result = $this->scorer()->score($workspace);

        $this->assertNull($result['hard_zero_reason']);
        $this->assertLessThan(0.5, $result['score']);
        $this->assertGreaterThan(0, $result['components']['phpstan_delta']);
    }

    public function test_added_ignore_suppression_is_counted_and_penalized(): void
    {
        $workspace = $this->gitWorkspace($this->cleanSample());
        file_put_contents($workspace.'/src/Sample.php', $this->cleanSample()."\n// @phpstan-ignore-next-line\n");

        $result = $this->scorer()->score($workspace);

        $this->assertNull($result['hard_zero_reason']);
        $this->assertSame(1, $result['components']['ignore_suppressions_added']);
        $this->assertLessThan(0.5, $result['score']);
    }

    public function test_new_non_final_marker_in_product_code_hard_zeroes(): void
    {
        $workspace = $this->gitWorkspace($this->cleanSample());
        file_put_contents($workspace.'/src/Sample.php', $this->cleanSample()."\n// TODO: terminar depois\n");

        $result = $this->scorer()->score($workspace);

        $this->assertSame(0.0, $result['score']);
        $this->assertSame(FinalDeliveryQualityGateService::BLOCKER, $result['hard_zero_reason']);
        $this->assertFalse($result['gates']['markers_clean']);
        $this->assertTrue($result['gates']['vocab_clean']);
    }

    public function test_prohibited_vocabulary_in_added_lines_hard_zeroes(): void
    {
        // A palavra vem da CONSTANTE do kernel constitucional — nunca literal aqui.
        $word = AtlasConstitutionalKernelService::PROHIBITED_CLAIMS[0];
        $workspace = $this->gitWorkspace($this->cleanSample());
        file_put_contents($workspace.'/src/Sample.php', $this->cleanSample()."\n// nota: modo {$word} interno\n");

        $result = $this->scorer()->score($workspace);

        $this->assertSame(0.0, $result['score']);
        $this->assertSame(AtlasImplementationQualityScorer::REASON_PROHIBITED_VOCABULARY, $result['hard_zero_reason']);
        $this->assertFalse($result['gates']['vocab_clean']);
        $this->assertTrue($result['gates']['markers_clean']);
    }

    public function test_workspace_without_git_hard_zeroes(): void
    {
        $dir = sys_get_temp_dir().'/atlas-quality-nogit-'.bin2hex(random_bytes(4));
        mkdir($dir, 0o755, true);
        $this->tempDirs[] = $dir;
        file_put_contents($dir.'/Sample.php', $this->cleanSample());

        $result = $this->scorer()->score($dir);

        $this->assertSame(0.0, $result['score']);
        $this->assertSame(AtlasImplementationQualityScorer::REASON_WORKSPACE_NOT_GIT, $result['hard_zero_reason']);
        $this->assertFalse($result['gates']['workspace_is_git']);
    }

    public function test_command_prints_exactly_one_line_and_writes_report(): void
    {
        $workspace = $this->gitWorkspace($this->cleanSample());
        $reportDir = sys_get_temp_dir().'/atlas-quality-report-'.bin2hex(random_bytes(4));
        $this->tempDirs[] = $reportDir;
        $reportPath = $reportDir.'/quality.json';

        $exit = Artisan::call('atlas:loop:quality-score', [
            '--workspace' => $workspace,
            '--report' => $reportPath,
        ]);

        $this->assertSame(0, $exit);
        // Contrato de stdout: EXATAMENTE uma linha, extraível por /QUALITY_SCORE=([0-9.]+)/.
        $this->assertSame("QUALITY_SCORE=0.5000\n", Artisan::output());

        $this->assertFileExists($reportPath);
        $report = json_decode((string) file_get_contents($reportPath), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame(AtlasImplementationQualityScorer::SCHEMA_VERSION, $report['schema_version']);
        $this->assertSame(0.5, (float) $report['score']);
    }

    public function test_command_rejects_missing_workspace_as_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:quality-score', [
            '--workspace' => sys_get_temp_dir().'/atlas-quality-missing-'.bin2hex(random_bytes(4)),
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringNotContainsString('QUALITY_SCORE=', Artisan::output());
    }

    private function scorer(): AtlasImplementationQualityScorer
    {
        return app(AtlasImplementationQualityScorer::class);
    }

    /** Workspace git temporário com src/Sample.php commitado como baseline. */
    private function gitWorkspace(string $samplePhp): string
    {
        $dir = sys_get_temp_dir().'/atlas-quality-scorer-'.bin2hex(random_bytes(4));
        mkdir($dir.'/src', 0o755, true);
        $this->tempDirs[] = $dir;
        file_put_contents($dir.'/src/Sample.php', $samplePhp);
        $this->runGit(['git', 'init', '-q'], $dir);
        $this->runGit(['git', 'config', 'user.email', 'atlas-loop@local'], $dir);
        $this->runGit(['git', 'config', 'user.name', 'Atlas Loop'], $dir);
        $this->runGit(['git', 'add', '-A'], $dir);
        $this->runGit(['git', 'commit', '-q', '-m', 'baseline'], $dir);

        return $dir;
    }

    private function cleanSample(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

final class Sample
{
    public function run(): string
    {
        $when = new DateTimeImmutable('2026-01-01');

        return $when->format('Y-m-d');
    }
}
PHP;
    }

    /** Um erro detectável pelo phpstan nível 5: método inexistente em tipo conhecido. */
    private function brokenSample(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

final class Sample
{
    public function run(): string
    {
        $when = new DateTimeImmutable('2026-01-01');

        return $when->missingMethod();
    }
}
PHP;
    }

    /**
     * @param  list<string>  $argv
     */
    private function runGit(array $argv, string $cwd): void
    {
        $process = new Process($argv, $cwd, null, null, 30.0);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput() ?: $process->getOutput());
    }
}
