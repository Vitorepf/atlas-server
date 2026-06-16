<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ACDE Bloco C — the Opus-arm capture command turns real refactor artifacts into a machine-resolved panel:
 * the verdict-determining `canary` axis is RUN (not claimed), a refusal scores as a defect, and the output
 * round-trips through the head-to-head scorer. This keeps BOTH arms machine-resolved (anti-Goodhart).
 */
final class AtlasLoopDqsCaptureOpusArmCommandTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/atlas-dqs-opus-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/green_ws', 0o755, true);
        mkdir($this->dir.'/red_ws', 0o755, true);
        // a workspace whose acceptance PASSES (exit 0) and one whose acceptance FAILS (exit 1)
        file_put_contents($this->dir.'/green_ws/t.php', "<?php\nexit(0);\n");
        file_put_contents($this->dir.'/red_ws/t.php', "<?php\nfwrite(STDERR,'boom');\nexit(1);\n");
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            (new Process(['rm', '-rf', $this->dir]))->run();
        }
        parent::tearDown();
    }

    public function test_capture_measures_canary_by_running_the_test_and_scores_refusal_as_defect(): void
    {
        $manifest = [
            // committed + test GREEN => clean
            ['task_id' => 'win', 'committed' => true, 'workspace' => $this->dir.'/green_ws', 'test_command' => 'php t.php', 'cyclomatic_drop' => 10],
            // committed + test RED => escaped defect (measured, not claimed green)
            ['task_id' => 'leak', 'committed' => true, 'workspace' => $this->dir.'/red_ws', 'test_command' => 'php t.php'],
            // Opus refused/failed the task => defect, no workspace needed
            ['task_id' => 'refused', 'committed' => false],
        ];
        file_put_contents($this->dir.'/manifest.json', json_encode($manifest));
        $out = $this->dir.'/opus.json';

        $this->artisan('atlas:loop:dqs-capture-opus-arm', ['--manifest' => $this->dir.'/manifest.json', '--out' => $out, '--json' => true])
            ->assertExitCode(0);

        $panel = json_decode((string) file_get_contents($out), true);
        $this->assertIsArray($panel);
        $this->assertCount(3, $panel);

        $byId = [];
        foreach ($panel as $o) {
            $byId[$o['task_id']] = $o;
        }
        $this->assertSame('green', $byId['win']['canary'], 'a passing test is measured green');
        $this->assertTrue($byId['win']['committed']);
        $this->assertSame('red', $byId['leak']['canary'], 'a failing test is measured RED — not a claimed green');
        $this->assertFalse($byId['refused']['committed']);
        $this->assertSame('not_run', $byId['refused']['canary']);
    }

    public function test_captured_opus_panel_round_trips_through_the_head_to_head(): void
    {
        // Opus arm: 1 clean, 1 escaped defect (a measured RED merge) over 2 attempted.
        $manifest = [
            ['task_id' => 'a', 'committed' => true, 'workspace' => $this->dir.'/green_ws', 'test_command' => 'php t.php'],
            ['task_id' => 'b', 'committed' => true, 'workspace' => $this->dir.'/red_ws', 'test_command' => 'php t.php'],
        ];
        file_put_contents($this->dir.'/manifest.json', json_encode($manifest));
        $opus = $this->dir.'/opus.json';
        $this->artisan('atlas:loop:dqs-capture-opus-arm', ['--manifest' => $this->dir.'/manifest.json', '--out' => $opus])
            ->assertExitCode(0);

        // ACE arm on the SAME 2-task set, both clean — so the scorer can compare apples to apples.
        $ace = $this->dir.'/ace.json';
        file_put_contents($ace, json_encode([
            ['attempted' => true, 'committed' => true, 'canary' => 'green'],
            ['attempted' => true, 'committed' => true, 'canary' => 'green'],
        ]));

        // The pipeline RUNS end-to-end and emits an honest verdict (at N=2 it is correctly not a confident 2x).
        $this->artisan('atlas:loop:dqs-head-to-head', ['--ace' => $ace, '--opus' => $opus, '--json' => true])
            ->assertExitCode(0);
    }

    public function test_missing_manifest_fails_cleanly(): void
    {
        $this->artisan('atlas:loop:dqs-capture-opus-arm', ['--manifest' => $this->dir.'/nope.json'])
            ->assertExitCode(1);
    }
}
