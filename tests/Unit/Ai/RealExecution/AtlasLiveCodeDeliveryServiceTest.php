<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\RealExecution;

use App\Models\AiJob;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderHealthCheck;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\RealExecution\AtlasLiveCodeDeliveryService;
use Mockery;
use Tests\TestCase;

class AtlasLiveCodeDeliveryServiceTest extends TestCase
{
    /** @var list<string> */
    private array $sandboxes = [];

    protected function tearDown(): void
    {
        foreach ($this->sandboxes as $d) {
            $this->rmrf($d);
        }
        Mockery::close();
        parent::tearDown();
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir.DIRECTORY_SEPARATOR.$f;
            is_dir($p) ? $this->rmrf($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function service(callable $runHandler): AtlasLiveCodeDeliveryService
    {
        $provider = new class($runHandler) implements AiProvider
        {
            public function __construct(private $runHandler) {}

            public function key(): string
            {
                return 'fake';
            }

            public function run(AiJob $job, string $prompt): AiProviderResult
            {
                return ($this->runHandler)($job, $prompt);
            }

            public function runStreaming(AiJob $job, string $prompt, ?callable $onEvent = null): AiProviderResult
            {
                return $this->run($job, $prompt);
            }

            public function health(): AiProviderHealthCheck
            {
                return new AiProviderHealthCheck(true, 'ok', null);
            }
        };

        $mgr = Mockery::mock(AiProviderManager::class);
        $mgr->shouldReceive('get')->andReturn($provider);

        $svc = new AtlasLiveCodeDeliveryService($mgr);
        $svc->setSandboxFactoryForTesting(function (): string {
            $d = sys_get_temp_dir().DIRECTORY_SEPARATOR.'atlas_lcd_test_'.uniqid('', true);
            @mkdir($d, 0775, true);
            $this->sandboxes[] = $d;

            return $d;
        });

        return $svc;
    }

    public function test_certifies_valid_php_generated_by_a_provider_in_an_isolated_sandbox(): void
    {
        $code = "<?php\n\nfunction atlas_fib(int \$n): int\n{\n    return \$n < 2 ? \$n : atlas_fib(\$n - 1) + atlas_fib(\$n - 2);\n}\n";
        $svc = $this->service(fn (): AiProviderResult => new AiProviderResult(true, $code, [], 0, 10, $code, ''));

        $env = $svc->deliver('a recursive fibonacci function', ['provider' => 'codex_cli', 'target_file' => 'AtlasFib.php']);

        $this->assertSame(AtlasLiveCodeDeliveryService::STATUS_CERTIFIED, $env['status']);
        $this->assertTrue($env['certified']);
        $this->assertTrue($env['syntax_check']['ok']);
        $this->assertGreaterThan(0, $env['line_count']);
        $this->assertFalse($env['merged_to_repo'], 'delivery must never auto-merge to the repo');
        $this->assertTrue($env['review_required']);
        // The artifact lands ONLY in the isolated sandbox, never the working repo.
        $this->assertStringContainsString('atlas_lcd_test_', (string) $env['sandbox_path']);
        $this->assertFileExists((string) $env['sandbox_path']);
    }

    public function test_strips_markdown_fences_from_provider_output(): void
    {
        $svc = $this->service(fn (): AiProviderResult => new AiProviderResult(true, "```php\n<?php\necho 'hi';\n```", [], 0, 5, '', ''));

        $env = $svc->deliver('print hi', ['target_file' => 'Hi.php']);

        $this->assertTrue($env['certified']);
        $this->assertStringNotContainsString('```', (string) $env['code_preview']);
    }

    public function test_blocks_invalid_php_via_the_syntax_gate(): void
    {
        $svc = $this->service(fn (): AiProviderResult => new AiProviderResult(true, '<?php function (((  broken', [], 0, 5, '', ''));

        $env = $svc->deliver('broken code', ['target_file' => 'Broken.php']);

        $this->assertSame(AtlasLiveCodeDeliveryService::STATUS_BLOCKED, $env['status']);
        $this->assertFalse($env['certified']);
        $this->assertFalse($env['syntax_check']['ok']);
    }

    public function test_rejects_unsafe_target_path_traversal(): void
    {
        $svc = $this->service(fn (): AiProviderResult => new AiProviderResult(true, '<?php', [], 0, 5, '', ''));

        $env = $svc->deliver('x', ['target_file' => '../escape.php']);

        $this->assertSame('unsafe_target_file', $env['blocked_reason']);
    }

    public function test_blocks_when_provider_returns_no_code(): void
    {
        $svc = $this->service(fn (): AiProviderResult => new AiProviderResult(true, '   ', [], 0, 5, '', ''));

        $env = $svc->deliver('x', ['target_file' => 'X.php']);

        $this->assertFalse($env['certified']);
        $this->assertSame('provider_returned_no_code', $env['blocked_reason']);
    }

    public function test_blocks_when_provider_fails(): void
    {
        $svc = $this->service(fn (): AiProviderResult => new AiProviderResult(false, '', [], 1, 5, '', 'boom', 'provider_error'));

        $env = $svc->deliver('x', ['target_file' => 'X.php']);

        $this->assertSame(AtlasLiveCodeDeliveryService::STATUS_BLOCKED, $env['status']);
        $this->assertStringContainsString('provider_returned_not_ok', (string) $env['blocked_reason']);
    }

    public function test_verify_run_certifies_only_when_the_self_tests_pass(): void
    {
        $code = "<?php\nfunction atlas_sq(int \$n): int { return \$n * \$n; }\nif (atlas_sq(3) !== 9) { exit(1); }\nif (atlas_sq(0) !== 0) { exit(1); }\n";
        $svc = $this->service(fn (): AiProviderResult => new AiProviderResult(true, $code, [], 0, 5, $code, ''));

        $env = $svc->deliver('a square function with self-tests', ['target_file' => 'AtlasSq.php', 'verify_run' => true]);

        $this->assertTrue($env['certified']);
        $this->assertIsArray($env['run_check']);
        $this->assertTrue($env['run_check']['ok']);
        $this->assertSame(0, $env['run_check']['exit_code']);
    }

    public function test_verify_run_blocks_when_the_self_tests_fail(): void
    {
        // Valid syntax, but the self-test detects a wrong result and exits 1.
        $code = "<?php\nfunction atlas_bad(): int { return 1; }\nif (atlas_bad() !== 2) { exit(1); }\n";
        $svc = $this->service(fn (): AiProviderResult => new AiProviderResult(true, $code, [], 0, 5, $code, ''));

        $env = $svc->deliver('intentionally wrong', ['target_file' => 'AtlasBad.php', 'verify_run' => true]);

        $this->assertSame(AtlasLiveCodeDeliveryService::STATUS_BLOCKED, $env['status']);
        $this->assertFalse($env['certified']);
        $this->assertFalse($env['run_check']['ok']);
    }

    public function test_multi_file_delivery_writes_all_files_and_verifies_via_the_entry(): void
    {
        // Two files: the entry requires the lib and self-tests it; exit 0 iff correct.
        $output = "=== FILE: entry.php ===\n<?php\nrequire __DIR__ . '/lib/math.php';\nif (atlas_add(2, 3) !== 5) { exit(1); }\n"
            ."=== FILE: lib/math.php ===\n<?php\nfunction atlas_add(int \$a, int \$b): int { return \$a + \$b; }\n";
        $svc = $this->service(fn (): AiProviderResult => new AiProviderResult(true, $output, [], 0, 5, $output, ''));

        $env = $svc->deliver('an add function split across an entry + a lib file', [
            'target_file' => 'entry.php', 'multi_file' => true, 'verify_run' => true,
        ]);

        $this->assertTrue($env['certified']);
        $this->assertSame(2, $env['file_count']);
        $this->assertSame('entry.php', $env['target_file']);
        $this->assertSame(['entry.php', 'lib/math.php'], array_map(static fn (array $f): string => $f['path'], $env['files']));
        $this->assertTrue($env['run_check']['ok'], 'entry requires lib + self-test passes across both files');
    }
}
