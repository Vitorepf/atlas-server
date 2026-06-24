<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopOrphanWiringAuthoringEngine;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasLoopOrphanWiringAuthoringEngineMultiWiringTest extends TestCase
{
    private string $ws;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ws = sys_get_temp_dir().'/atlas-authoring-multi-'.bin2hex(random_bytes(4));
        mkdir($this->ws.'/app', 0o755, true);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->ws]))->run();
        parent::tearDown();
    }

    private function payload(): array
    {
        return ['orphan_fqcn' => 'Orphan', 'orphan_path' => 'app/Orphan.php', 'public_methods' => ['contribute'], 'sibling_test' => 'tests/Unit/OrphanTest.php'];
    }

    private function multiResponse(array $wirings): string
    {
        $parts = [
            '<<<TEST_REL>>>', 'tests/wiring_test.php',
            '<<<TEST_COMMAND>>>', 'php tests/wiring_test.php',
            '<<<TEST_CONTENT>>>', "<?php\nexit(1);\n",
        ];
        foreach ($wirings as $i => $wiring) {
            $n = $i + 1;
            $parts[] = '<<<WIRING_REL_'.$n.'>>>';
            $parts[] = $wiring['rel'];
            $parts[] = '<<<WIRING_CONTENT_'.$n.'>>>';
            $parts[] = $wiring['content'];
        }
        $parts[] = '<<<END>>>';

        return implode("\n", $parts);
    }

    private function legacyResponse(): string
    {
        return implode("\n", [
            '<<<TEST_REL>>>', 'tests/wiring_test.php',
            '<<<TEST_COMMAND>>>', 'php tests/wiring_test.php',
            '<<<TEST_CONTENT>>>', "<?php\nexit(1);\n",
            '<<<WIRING_REL>>>', 'app/Consumer.php',
            '<<<WIRING_CONTENT>>>', "<?php\nclass Consumer {}\n",
            '<<<END>>>',
        ]);
    }

    public function test_parse_accepts_two_indexed_wiring_pairs(): void
    {
        $parsed = (new AtlasLoopOrphanWiringAuthoringEngine)->parse($this->multiResponse([
            ['rel' => 'app/Consumer.php', 'content' => "<?php\nclass Consumer {}\n"],
            ['rel' => 'app/Providers/WiringProvider.php', 'content' => "<?php\nclass WiringProvider {}\n"],
        ]));

        $this->assertIsArray($parsed);
        $this->assertCount(2, $parsed['wirings']);
        $this->assertSame('app/Consumer.php', $parsed['wirings'][0]['rel']);
        $this->assertSame('app/Providers/WiringProvider.php', $parsed['wirings'][1]['rel']);
    }

    public function test_legacy_single_pair_parses_byte_compatibly(): void
    {
        $parsed = (new AtlasLoopOrphanWiringAuthoringEngine)->parse($this->legacyResponse());

        $this->assertIsArray($parsed);
        $this->assertCount(1, $parsed['wirings']);
        $this->assertSame('app/Consumer.php', $parsed['wiring_rel']);
        $this->assertSame($parsed['wirings'][0]['content'], $parsed['wiring_content']);
    }

    public function test_more_than_four_wirings_is_rejected(): void
    {
        $wirings = [];
        for ($i = 1; $i <= 5; $i++) {
            $wirings[] = ['rel' => 'app/Wiring'.$i.'.php', 'content' => "<?php\nclass Wiring{$i} {}\n"];
        }

        $this->assertNull((new AtlasLoopOrphanWiringAuthoringEngine)->parse($this->multiResponse($wirings)));
    }

    public function test_any_wiring_outside_app_rejects_the_whole_response(): void
    {
        $parsed = (new AtlasLoopOrphanWiringAuthoringEngine)->parse($this->multiResponse([
            ['rel' => 'app/Consumer.php', 'content' => "<?php\nclass Consumer {}\n"],
            ['rel' => 'config/bad.php', 'content' => "<?php\nreturn [];\n"],
        ]));

        $this->assertNull($parsed);
    }

    public function test_author_wiring_writes_all_files_to_the_workspace(): void
    {
        $engine = new AtlasLoopOrphanWiringAuthoringEngine(fn (): string => $this->multiResponse([
            ['rel' => 'app/Consumer.php', 'content' => "<?php\nclass Consumer {}\n"],
            ['rel' => 'app/Providers/WiringProvider.php', 'content' => "<?php\nclass WiringProvider {}\n"],
        ]));

        $authoring = $engine->author($this->payload(), $this->ws);
        $this->assertIsArray($authoring);

        $authoring['author_wiring']();

        $this->assertFileExists($this->ws.'/app/Consumer.php');
        $this->assertFileExists($this->ws.'/app/Providers/WiringProvider.php');
    }
}
