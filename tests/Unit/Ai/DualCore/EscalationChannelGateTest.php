<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\DualCore;

use App\Services\Ai\DualCore\EscalationChannelGate;
use PHPUnit\Framework\TestCase;

final class EscalationChannelGateTest extends TestCase
{
    private string $sandbox;

    private EscalationChannelGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir().'/atlas-escalation-gate-'.bin2hex(random_bytes(4));
        mkdir($this->sandbox, 0775, true);
        $this->gate = new EscalationChannelGate($this->sandbox);
    }

    protected function tearDown(): void
    {
        $this->purge($this->sandbox);
        parent::tearDown();
    }

    public function test_emits_canonical_schema_version(): void
    {
        $result = $this->gate->evaluate([]);

        $this->assertSame('atlas.dual_core.escalation_channel_gate.v1', $result['schema_version']);
        $this->assertSame('DualCoreRouteDecisionService', $result['policy']['canonical_service']);
        $this->assertSame('ForgeIntakeRouteDecisionRecorder', $result['policy']['canonical_recorder']);
        $this->assertSame('atlas.dual_core.route_decision.v1', $result['policy']['canonical_schema']);
    }

    public function test_wired_controller_passes(): void
    {
        $this->write('AtlasCodeWiredController.php', '<?php use App\Services\Ai\DualCore\DualCoreRouteDecisionService;');

        $result = $this->gate->evaluate(['app/Http/Controllers/AtlasCodeWiredController.php']);

        $this->assertSame('ok', $result['status']);
        $this->assertSame([], $result['new_violations']);
    }

    public function test_unwired_programming_controller_fails(): void
    {
        $this->write('AtlasCodeUnwiredController.php', '<?php // no canonical channel here');

        $result = $this->gate->evaluate(['app/Http/Controllers/AtlasCodeUnwiredController.php']);

        $this->assertSame('failed', $result['status']);
        $this->assertCount(1, $result['new_violations']);
        $this->assertSame('no_canonical_channel_emission', $result['new_violations'][0]['reason']);
    }

    public function test_recorder_alone_is_acceptable_wiring(): void
    {
        $this->write('AtlasCodeRecorderOnlyController.php', '<?php use App\Services\Ai\DualCore\ForgeIntakeRouteDecisionRecorder;');

        $result = $this->gate->evaluate(['app/Http/Controllers/AtlasCodeRecorderOnlyController.php']);

        $this->assertSame('ok', $result['status']);
    }

    public function test_schema_literal_alone_is_acceptable_wiring(): void
    {
        $this->write('AtlasCodeSchemaOnlyController.php', "<?php // emits atlas.dual_core.route_decision.v1");

        $result = $this->gate->evaluate(['app/Http/Controllers/AtlasCodeSchemaOnlyController.php']);

        $this->assertSame('ok', $result['status']);
    }

    public function test_out_of_scope_controller_is_ignored(): void
    {
        $this->write('AuthController.php', '<?php // nothing canonical');

        $result = $this->gate->evaluate(['app/Http/Controllers/AuthController.php']);

        $this->assertSame('ok', $result['status']);
        $this->assertSame([], $result['new_violations']);
    }

    public function test_scope_substrings_match_atlas_programming(): void
    {
        $this->write('AtlasProgrammingFooController.php', '<?php // no wiring');
        $this->write('AtlasSddBarController.php', '<?php // no wiring');
        $this->write('AtlasForgeBazController.php', '<?php // no wiring');
        $this->write('DevToForgeQuxController.php', '<?php // no wiring');

        $result = $this->gate->evaluate([
            'app/Http/Controllers/AtlasProgrammingFooController.php',
            'app/Http/Controllers/AtlasSddBarController.php',
            'app/Http/Controllers/AtlasForgeBazController.php',
            'app/Http/Controllers/DevToForgeQuxController.php',
        ]);

        $this->assertSame('failed', $result['status']);
        $this->assertCount(4, $result['new_violations']);
    }

    public function test_scans_existing_files_and_computes_coverage(): void
    {
        $this->write('AtlasCodeWired.php', '<?php use App\Services\Ai\DualCore\DualCoreRouteDecisionService;');
        $this->write('AtlasCodeUnwiredA.php', '<?php // no');
        $this->write('AtlasCodeUnwiredB.php', '<?php // no');
        $this->write('AuthController.php', '<?php // out of scope');

        $result = $this->gate->evaluate([]);

        $this->assertSame(3, $result['summary']['scanned_existing'], 'only Programming-adjacent files count');
        $this->assertSame(1, $result['summary']['existing_wired']);
        $this->assertSame(2, $result['summary']['existing_unwired']);
        $this->assertEqualsWithDelta(33.3, $result['summary']['coverage_pct'], 0.1);
    }

    public function test_unwired_sample_caps_at_10(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->write("AtlasCodeUnwired{$i}.php", '<?php');
        }

        $result = $this->gate->evaluate([]);

        $this->assertSame(15, $result['summary']['existing_unwired']);
        $this->assertCount(10, $result['existing_unwired']['sample']);
    }

    public function test_check_path_returns_null_for_wired_file(): void
    {
        $this->write('AtlasCodeOkController.php', '<?php // atlas.dual_core.route_decision.v1');

        $result = $this->gate->checkPath('app/Http/Controllers/AtlasCodeOkController.php');

        $this->assertNull($result);
    }

    public function test_check_path_returns_null_for_out_of_scope_file(): void
    {
        $result = $this->gate->checkPath('app/Http/Controllers/AuthController.php');

        $this->assertNull($result);
    }

    public function test_missing_file_in_scope_is_flagged(): void
    {
        $result = $this->gate->checkPath('app/Http/Controllers/AtlasCodeMissingController.php');

        $this->assertIsArray($result);
        $this->assertSame('file_not_found', $result['reason']);
    }

    public function test_empty_new_list_yields_ok_when_no_violations(): void
    {
        $result = $this->gate->evaluate([]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(0, $result['summary']['new_checked']);
    }

    private function write(string $filename, string $contents): void
    {
        file_put_contents($this->sandbox.'/'.$filename, $contents);
    }

    private function purge(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path.'/'.$item;
            if (is_dir($full)) {
                $this->purge($full);
                rmdir($full);
            } else {
                unlink($full);
            }
        }
        @rmdir($path);
    }
}
