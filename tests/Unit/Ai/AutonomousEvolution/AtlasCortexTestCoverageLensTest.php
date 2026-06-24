<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council\AtlasCortexTestCoverageLens;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council\CortexSubject;
use ReflectionClass;
use Tests\TestCase;

/**
 * Proves the testcoverage lens emits covered_method / uncovered_method facts against a clover.xml fixture,
 * marks the artifact as stale when its mtime is older than the subject file, and ships ZERO score-like
 * substrings in its source (anti-Goodhart string grep).
 */
final class AtlasCortexTestCoverageLensTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/atlas_cortex_cov_'.bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            shell_exec('rm -rf '.escapeshellarg($this->tmpDir));
        }
        parent::tearDown();
    }

    private function writeCloverFixture(string $sourcePath, array $methods): string
    {
        $linesXml = '';
        foreach ($methods as $method) {
            $linesXml .= sprintf(
                '<line num="%d" type="method" name="%s" count="%d"/>'."\n",
                (int) $method['line'],
                htmlspecialchars((string) $method['name']),
                (int) $method['count'],
            );
            foreach ((array) ($method['stmts'] ?? []) as $stmt) {
                $linesXml .= sprintf('<line num="%d" type="stmt" count="%d"/>'."\n", (int) $stmt['line'], (int) $stmt['count']);
            }
        }
        $clover = sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><coverage><project><file name="%s">%s</file></project></coverage>',
            htmlspecialchars($sourcePath),
            $linesXml,
        );
        $path = $this->tmpDir.'/clover.xml';
        file_put_contents($path, $clover);

        return $path;
    }

    public function test_emits_covered_and_uncovered_method_facts(): void
    {
        $sourcePath = $this->tmpDir.'/Sample.php';
        file_put_contents($sourcePath, "<?php\nclass Sample {}\n");

        $artifactPath = $this->writeCloverFixture($sourcePath, [
            ['name' => 'doIt', 'line' => 5, 'count' => 3, 'stmts' => [['line' => 6, 'count' => 3], ['line' => 7, 'count' => 0]]],
            ['name' => 'never', 'line' => 12, 'count' => 0, 'stmts' => [['line' => 13, 'count' => 0]]],
        ]);
        // Make the artifact NEWER than the source so it is not marked stale.
        touch($artifactPath, time() + 60);
        config(['cortex.council.coverage_artifact' => $artifactPath]);

        $obs = (new AtlasCortexTestCoverageLens)->observe(new CortexSubject('subj-1', 'php_source', ['file_path' => $sourcePath]));

        $entries = (array) $obs->facts['entries'];
        $kinds = array_column($entries, 'kind');
        $this->assertContains('covered_method', $kinds);
        $this->assertContains('uncovered_method', $kinds);
        $this->assertContains('covered_line', $kinds);
        $this->assertContains('uncovered_line', $kinds);

        $byMethod = [];
        foreach ($entries as $e) {
            if (str_ends_with((string) $e['kind'], 'method')) {
                $byMethod[$e['method']] = $e['kind'];
            }
        }
        $this->assertSame('covered_method', $byMethod['doIt']);
        $this->assertSame('uncovered_method', $byMethod['never']);
    }

    public function test_emits_stale_disagreement_signal_when_artifact_older_than_subject(): void
    {
        $sourcePath = $this->tmpDir.'/Stale.php';
        file_put_contents($sourcePath, "<?php\nclass Stale {}\n");

        $artifactPath = $this->writeCloverFixture($sourcePath, [['name' => 'x', 'line' => 1, 'count' => 1]]);
        // Make artifact OLDER than source.
        touch($artifactPath, time() - 3600);
        touch($sourcePath, time());
        config(['cortex.council.coverage_artifact' => $artifactPath]);

        $obs = (new AtlasCortexTestCoverageLens)->observe(new CortexSubject('subj-stale', 'php_source', ['file_path' => $sourcePath]));

        $this->assertContains('coverage_artifact_stale', $obs->disagreementSignals);
    }

    public function test_missing_artifact_yields_missing_disagreement_and_empty_entries(): void
    {
        config(['cortex.council.coverage_artifact' => '/nonexistent/clover.xml']);

        $obs = (new AtlasCortexTestCoverageLens)->observe(new CortexSubject('subj-x', 'php_source', ['file_path' => '/tmp/whatever.php']));

        $this->assertContains('coverage_artifact_missing', $obs->disagreementSignals);
        $this->assertSame([], $obs->facts['entries']);
    }

    public function test_unparseable_artifact_yields_unparseable_disagreement(): void
    {
        $bad = $this->tmpDir.'/bad.xml';
        file_put_contents($bad, 'not xml');
        config(['cortex.council.coverage_artifact' => $bad]);

        $obs = (new AtlasCortexTestCoverageLens)->observe(new CortexSubject('subj-x', 'php_source', ['file_path' => $this->tmpDir.'/whatever.php']));

        $this->assertContains('coverage_artifact_unparseable', $obs->disagreementSignals);
    }

    public function test_subject_not_in_artifact_yields_disagreement(): void
    {
        $otherFile = $this->tmpDir.'/Other.php';
        file_put_contents($otherFile, "<?php\n");
        $artifactPath = $this->writeCloverFixture($otherFile, [['name' => 'x', 'line' => 1, 'count' => 1]]);
        config(['cortex.council.coverage_artifact' => $artifactPath]);

        $missingPath = $this->tmpDir.'/NotPresent.php';
        file_put_contents($missingPath, "<?php\n");
        $obs = (new AtlasCortexTestCoverageLens)->observe(new CortexSubject('subj-m', 'php_source', ['file_path' => $missingPath]));

        $this->assertContains('subject_not_in_artifact', $obs->disagreementSignals);
    }

    public function test_source_contains_no_forbidden_score_substrings(): void
    {
        $reflection = new ReflectionClass(AtlasCortexTestCoverageLens::class);
        $source = (string) file_get_contents($reflection->getFileName());

        foreach (['percent', 'ratio', 'rate', 'score', 'rank'] as $banned) {
            $this->assertStringNotContainsStringIgnoringCase(
                $banned,
                $source,
                "lens source must not contain forbidden substring '{$banned}' (anti-Goodhart contract)",
            );
        }
    }
}
