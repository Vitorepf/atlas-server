<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\NativeImplementation;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionHermeticSandboxApplyService;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionHermeticSandboxApplyServiceTest extends TestCase
{
    public function test_real_apply_and_command_run_only_inside_disposable_sandbox(): void
    {
        $main = sys_get_temp_dir().'/atlas-main-'.bin2hex(random_bytes(4));
        mkdir($main);
        file_put_contents($main.'/protected.php', 'main');

        $result = (new AtlasSelfConstructionHermeticSandboxApplyService)->execute([
            'idempotency_key' => 'delivery-1',
            'allowed_files' => ['protected.php'],
            'patch_plan' => [
                'allowed_files' => ['protected.php'],
                'patches' => [['path' => 'protected.php', 'mode' => 'create', 'next' => 'sandbox']],
            ],
            'command_plan' => [[
                'name' => 'sandbox-gate',
                'argv' => [PHP_BINARY, '-r', 'file_put_contents("gate-ran", "yes");'],
                'timeout_seconds' => 5,
            ]],
        ]);

        self::assertSame('main', file_get_contents($main.'/protected.php'));
        self::assertTrue($result['applied']);
        self::assertFalse($result['dry_run']);
        self::assertNotSame($main, $result['sandbox_root']);
        self::assertFileExists($result['sandbox_root'].'/gate-ran');
        self::assertFileDoesNotExist($result['sandbox_root'].'/vendor');
    }

    public function test_duplicate_delivery_reconciles_same_sandbox_without_second_mutation(): void
    {
        $input = [
            'idempotency_key' => 'delivery-restart-'.bin2hex(random_bytes(4)),
            'allowed_files' => ['app/Generated.php'],
            'patch_plan' => ['allowed_files' => ['app/Generated.php'], 'patches' => [[
                'path' => 'app/Generated.php', 'mode' => 'create', 'next' => 'stable',
            ]]],
            'command_plan' => [],
        ];
        $service = new AtlasSelfConstructionHermeticSandboxApplyService;
        $first = $service->execute($input);
        $path = $first['sandbox_root'].'/app/Generated.php';
        $mtime = filemtime($path);
        clearstatcache(true, $path);
        $second = $service->execute($input);

        self::assertTrue($first['applied']);
        self::assertTrue($second['applied']);
        self::assertTrue($second['replayed']);
        self::assertSame($mtime, filemtime($path));
        self::assertSame($first['idempotency_receipt'], $second['idempotency_receipt']);
    }
}
