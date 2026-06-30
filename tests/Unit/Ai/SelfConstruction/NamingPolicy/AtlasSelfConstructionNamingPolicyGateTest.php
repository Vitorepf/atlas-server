<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\NamingPolicy;

use App\Services\Ai\SelfConstruction\NamingPolicy\AtlasSelfConstructionNamingPolicyGate;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionNamingPolicyGateTest extends TestCase
{
    private string $sandbox;

    private AtlasSelfConstructionNamingPolicyGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir().'/atlas-naming-gate-'.bin2hex(random_bytes(4));
        mkdir($this->sandbox, 0775, true);
        $this->gate = new AtlasSelfConstructionNamingPolicyGate($this->sandbox);
    }

    protected function tearDown(): void
    {
        $this->purge($this->sandbox);
        parent::tearDown();
    }

    public function test_emits_canonical_schema_version(): void
    {
        $result = $this->gate->evaluate([]);

        $this->assertSame('atlas.self_construction.naming_policy_gate.v1', $result['schema_version']);
        $this->assertSame('ok', $result['status']);
        $this->assertSame(50, $result['policy']['max_class_name_length']);
    }

    public function test_accepts_short_class_name(): void
    {
        $result = $this->gate->evaluate([
            'app/Services/Ai/SelfConstruction/ShortName.php',
        ]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame([], $result['new_violations']);
        $this->assertSame(1, $result['summary']['new_checked']);
        $this->assertSame(0, $result['summary']['new_failed']);
    }

    public function test_rejects_class_name_over_50_chars(): void
    {
        $longName = str_repeat('A', 60); // 60 chars
        $result = $this->gate->evaluate([
            "app/Services/Ai/SelfConstruction/{$longName}.php",
        ]);

        $this->assertSame('failed', $result['status']);
        $this->assertCount(1, $result['new_violations']);
        $violation = $result['new_violations'][0];
        $this->assertSame('class_name_too_long', $violation['violation']);
        $this->assertSame(60, $violation['length']);
        $this->assertStringContainsString('policy is <=50', $violation['detail']);
    }

    public function test_ignores_paths_outside_self_construction_root(): void
    {
        $longName = str_repeat('A', 60);
        $result = $this->gate->evaluate([
            "app/Services/Ai/Programming/{$longName}.php",
        ]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame([], $result['new_violations']);
    }

    public function test_ignores_non_php_files(): void
    {
        $longName = str_repeat('A', 60);
        $result = $this->gate->evaluate([
            "app/Services/Ai/SelfConstruction/{$longName}.txt",
        ]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame([], $result['new_violations']);
    }

    public function test_exactly_50_chars_is_allowed(): void
    {
        $exactly50 = str_repeat('A', 50);
        $result = $this->gate->evaluate([
            "app/Services/Ai/SelfConstruction/{$exactly50}.php",
        ]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame([], $result['new_violations']);
    }

    public function test_51_chars_is_rejected(): void
    {
        $just51 = str_repeat('A', 51);
        $result = $this->gate->evaluate([
            "app/Services/Ai/SelfConstruction/{$just51}.php",
        ]);

        $this->assertSame('failed', $result['status']);
        $this->assertSame(51, $result['new_violations'][0]['length']);
    }

    public function test_rejects_apxxx_suffix_when_schema_not_emitted(): void
    {
        $filename = 'Ap374HandoffPacket.php'; // 22 chars — passes length but fails AP rule
        $absolute = $this->sandbox.'/'.$filename;
        file_put_contents($absolute, "<?php\nclass Ap374HandoffPacket {}\n");

        $result = $this->gate->evaluate([
            'app/Services/Ai/SelfConstruction/'.$filename,
        ]);

        // File on disk lives in sandbox but check uses base_path which points
        // to real app — this test exercises the conservative "fail when file
        // doesn't exist in base_path" branch since the sandboxed file isn't
        // resolvable from base_path().
        $this->assertSame('failed', $result['status']);
        $this->assertSame('forbidden_ap_suffix_without_handoff_packet_schema', $result['new_violations'][0]['violation']);
    }

    public function test_check_path_returns_null_for_compliant_file(): void
    {
        $this->assertNull(
            $this->gate->checkPath('app/Services/Ai/SelfConstruction/Foo.php')
        );
    }

    public function test_check_path_returns_null_for_out_of_scope_file(): void
    {
        $this->assertNull(
            $this->gate->checkPath('app/Models/'.str_repeat('A', 80).'.php')
        );
    }

    public function test_scans_existing_files_in_root(): void
    {
        file_put_contents($this->sandbox.'/ShortFile.php', '<?php');
        file_put_contents($this->sandbox.'/'.str_repeat('A', 60).'.php', '<?php');

        $result = $this->gate->evaluate([]);

        $this->assertSame(2, $result['summary']['scanned_existing']);
        $this->assertSame(1, $result['summary']['existing_violation_count']);
        $this->assertCount(1, $result['existing_violations']['sample']);
    }

    public function test_skips_quarantine_directory(): void
    {
        mkdir($this->sandbox.'/_quarantine');
        file_put_contents($this->sandbox.'/_quarantine/'.str_repeat('A', 80).'.php', '<?php');
        file_put_contents($this->sandbox.'/ShortFile.php', '<?php');

        $result = $this->gate->evaluate([]);

        $this->assertSame(1, $result['summary']['scanned_existing']);
        $this->assertSame(0, $result['summary']['existing_violation_count']);
    }

    public function test_existing_violations_sample_caps_at_10(): void
    {
        for ($i = 0; $i < 15; $i++) {
            file_put_contents(
                $this->sandbox.'/'.str_repeat('A', 60).'X'.$i.'.php',
                '<?php',
            );
        }
        $result = $this->gate->evaluate([]);

        $this->assertSame(15, $result['summary']['existing_violation_count']);
        $this->assertCount(10, $result['existing_violations']['sample']);
    }

    public function test_subdirectory_long_name_is_counted_in_existing_violations(): void
    {
        $subDir = $this->sandbox.'/SubDir';
        mkdir($subDir, 0775, true);
        file_put_contents($this->sandbox.'/ShortFile.php', '<?php');
        file_put_contents($subDir.'/'.str_repeat('B', 60).'.php', '<?php'); // >50 chars, in a subdir

        $result = $this->gate->evaluate([]);

        $this->assertSame(2, $result['summary']['scanned_existing'], 'recursive scan must count files in subdirectories');
        $this->assertSame(1, $result['summary']['existing_violation_count'], 'long-named class in subdir must be counted as a violation');
    }

    public function test_empty_input_yields_ok_status(): void
    {
        $result = $this->gate->evaluate([]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(0, $result['summary']['new_checked']);
    }

    public function test_batch_with_same_family_suffix_repeated_three_times_is_refused(): void
    {
        $batch = [
            'app/Services/Ai/SelfConstruction/FooBarService.php',
            'app/Services/Ai/SelfConstruction/BazQuxService.php',
            'app/Services/Ai/SelfConstruction/QuxZapService.php',
        ];
        $result = $this->gate->evaluate($batch);
        $this->assertSame('failed', $result['status']);
        $densityViolations = array_values(array_filter(
            $result['new_violations'],
            static fn (array $v): bool => $v['violation'] === AtlasSelfConstructionNamingPolicyGate::VIOLATION_TEMPLATE_FARM_DENSITY,
        ));
        $this->assertNotEmpty($densityViolations, 'batch with 3 Service files must produce a template-farm density violation');
        $this->assertSame(1, $result['summary']['families_failed']);
    }

    public function test_diverse_batch_under_density_limit_remains_ok(): void
    {
        $batch = [
            'app/Services/Ai/SelfConstruction/FooService.php',
            'app/Services/Ai/SelfConstruction/BarGate.php',
            'app/Services/Ai/SelfConstruction/BazPolicy.php',
        ];
        $result = $this->gate->evaluate($batch);
        $this->assertSame('ok', $result['status']);
        $this->assertSame(0, $result['summary']['families_failed']);
    }

    public function test_single_new_file_remains_ok(): void
    {
        $result = $this->gate->evaluate(['app/Services/Ai/SelfConstruction/FooService.php']);
        $this->assertSame('ok', $result['status']);
    }

    public function test_density_check_does_not_apply_to_existing_files(): void
    {
        // Existing files in sandbox — density check must NOT fire on them.
        for ($i = 0; $i < 5; $i++) {
            file_put_contents($this->sandbox."/Existing{$i}Service.php", '<?php');
        }
        // New batch has only 2 Service files — under density limit.
        $batch = [
            'app/Services/Ai/SelfConstruction/FooService.php',
            'app/Services/Ai/SelfConstruction/BarService.php',
        ];
        $result = $this->gate->evaluate($batch);
        $this->assertSame('ok', $result['status']);
        $this->assertSame(0, $result['summary']['families_failed']);
    }

    public function test_summary_exposes_families_inspected_and_failed(): void
    {
        $batch = [
            'app/Services/Ai/SelfConstruction/AService.php',
            'app/Services/Ai/SelfConstruction/BService.php',
            'app/Services/Ai/SelfConstruction/CService.php',
            'app/Services/Ai/SelfConstruction/DGate.php',
        ];
        $result = $this->gate->evaluate($batch);
        $this->assertArrayHasKey('families_inspected', $result['summary']);
        $this->assertArrayHasKey('families_failed', $result['summary']);
        $this->assertSame(2, $result['summary']['families_inspected']); // Service + Gate
        $this->assertSame(1, $result['summary']['families_failed']); // only Service hits the limit
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
