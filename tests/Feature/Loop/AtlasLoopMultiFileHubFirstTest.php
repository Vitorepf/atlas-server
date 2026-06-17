<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopMultiFileRefactorSynthesizer;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopObraClusterCandidate;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ACDE MF2 — hub-first conversion. Default OFF keeps the all-or-nothing N-file conjunction (byte-identical); ON
 * routes the anchored cluster HUB through the proven single-file refactor shape (its own complexity proof), so
 * conversion no longer requires the whole cluster to certify together — the ~17% conversion win.
 */
final class AtlasLoopMultiFileHubFirstTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            File::deleteDirectory($d);
        }
        parent::tearDown();
    }

    /** @param list<string> $appFiles @param list<string> $withSiblings */
    private function repo(array $appFiles, array $withSiblings): string
    {
        $d = sys_get_temp_dir().'/atlas-mfhf-'.bin2hex(random_bytes(4));
        $this->dirs[] = $d;
        foreach ($appFiles as $rel) {
            File::ensureDirectoryExists($d.'/'.dirname($rel));
            $class = pathinfo($rel, PATHINFO_FILENAME);
            File::put($d.'/'.$rel, "<?php\nnamespace App\\X;\nfinal class {$class} { public function go(int \$n): int { return \$n > 0 ? \$n : 0; } }\n");
            if (in_array($rel, $withSiblings, true)) {
                $sibDir = $d.'/tests/Unit/'.dirname(substr($rel, strlen('app/')));
                File::ensureDirectoryExists($sibDir);
                File::put($sibDir.'/'.$class.'Test.php', "<?php\nnamespace Tests\\Unit;\nfinal class {$class}Test { public function t(): void {} }\n");
            }
        }

        return $d;
    }

    private function candidate(string $hub, array $callers): AtlasLoopObraClusterCandidate
    {
        return AtlasLoopObraClusterCandidate::fromHub(
            $hub, $callers,
            ['cyclomatic' => 14, 'cyclomatic_total' => 40, 'refactor_leverage' => 0.8, 'measured_caller_count' => count($callers)],
            ['measured_impact_callers' => count($callers)],
        );
    }

    public function test_off_is_the_multi_file_conjunction_byte_identical(): void
    {
        config(['atlas.loop.multi_file_hub_first_enabled' => false]);
        $repo = $this->repo(
            ['app/Services/Hub.php', 'app/Services/CallerA.php'],
            ['app/Services/Hub.php', 'app/Services/CallerA.php'],
        );
        $out = (new AtlasLoopMultiFileRefactorSynthesizer())->synthesizeMultiFileRefactor($this->candidate('app/Services/Hub.php', ['app/Services/CallerA.php']), $repo);

        $this->assertTrue((bool) $out['payload']['multi_file']);
        $this->assertSame(['app/Services/CallerA.php', 'app/Services/Hub.php'], $out['payload']['allowed_files']);
        $this->assertCount(2, $out['payload']['frozen_tests']);
    }

    public function test_armed_routes_the_hub_through_the_single_file_lane(): void
    {
        config(['atlas.loop.multi_file_hub_first_enabled' => true]);
        $repo = $this->repo(
            ['app/Services/Hub.php', 'app/Services/CallerA.php'],
            ['app/Services/Hub.php', 'app/Services/CallerA.php'],
        );
        $out = (new AtlasLoopMultiFileRefactorSynthesizer())->synthesizeMultiFileRefactor($this->candidate('app/Services/Hub.php', ['app/Services/CallerA.php']), $repo);

        $this->assertIsArray($out);
        $payload = $out['payload'];
        $this->assertFalse((bool) $payload['multi_file'], 'hub-first is a single-file task');
        $this->assertTrue((bool) $payload['hub_first']);
        $this->assertSame('app/Services/Hub.php', $payload['target_relative_path']);
        $this->assertSame(['app/Services/Hub.php'], $payload['allowed_files'], 'only the hub is in scope');
        $this->assertCount(1, $payload['frozen_tests'], 'only the hub sibling is frozen');
        $this->assertStringContainsString('HubTest.php', $payload['validation_commands'][0]);
        $this->assertStringNotContainsString('CallerATest.php', $payload['validation_commands'][0], 'the conjunction over callers is gone');
        $this->assertStringContainsString('HUB-FIRST', $out['objective']);
        $this->assertTrue((bool) $payload['acceptance']['complexity_proof']);
    }

    public function test_armed_still_fail_closed_when_the_hub_is_unanchored(): void
    {
        config(['atlas.loop.multi_file_hub_first_enabled' => true]);
        $repo = $this->repo(
            ['app/Services/Hub.php', 'app/Services/CallerA.php'],
            ['app/Services/CallerA.php'], // hub has NO sibling
        );
        $out = (new AtlasLoopMultiFileRefactorSynthesizer())->synthesizeMultiFileRefactor($this->candidate('app/Services/Hub.php', ['app/Services/CallerA.php']), $repo);

        $this->assertNull($out, 'no hub anchor => fail-closed even with hub-first armed');
    }
}
