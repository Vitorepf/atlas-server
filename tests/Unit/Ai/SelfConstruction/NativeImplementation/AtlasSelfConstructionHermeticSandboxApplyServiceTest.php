<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\NativeImplementation;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionHermeticSandboxApplyService;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionHermeticSandboxApplyServiceTest extends TestCase
{
    public function test_apply_is_uncertain_when_applied_manifest_cannot_be_persisted(): void
    {
        $service = new AtlasSelfConstructionHermeticSandboxApplyService(manifestWriter: fn (): bool => false);
        $result = $service->execute(['idempotency_key' => 'write-fail-'.bin2hex(random_bytes(4)), 'allowed_files' => ['X.php'],
            'patch_plan' => ['allowed_files' => ['X.php'], 'patches' => [['path' => 'X.php', 'mode' => 'create', 'next' => 'x']]]]);
        self::assertFalse($result['applied']);
        self::assertSame('reconciliation_uncertain', $result['reason']);
    }

    public function test_provider_supplied_commands_are_never_executed(): void
    {
        $main = sys_get_temp_dir().'/atlas-main-'.bin2hex(random_bytes(4));
        mkdir($main);
        file_put_contents($main.'/protected.php', 'main');

        $result = (new AtlasSelfConstructionHermeticSandboxApplyService)->execute([
            'idempotency_key' => 'delivery-'.bin2hex(random_bytes(4)),
            'allowed_files' => ['protected.php'],
            'patch_plan' => [
                'allowed_files' => ['protected.php'],
                'patches' => [['path' => 'protected.php', 'mode' => 'create', 'next' => 'sandbox']],
            ],
            'command_plan' => [[
                'name' => 'sandbox-gate',
                'argv' => [PHP_BINARY, '-r', 'file_put_contents('.var_export($main.'/escaped', true).', "owned");'],
                'labels' => ['network', 'shell'],
                'timeout_seconds' => 5,
            ]],
        ]);

        self::assertSame('main', file_get_contents($main.'/protected.php'));
        self::assertTrue($result['applied']);
        self::assertFalse($result['dry_run']);
        self::assertNotSame($main, $result['sandbox_root']);
        self::assertFileDoesNotExist($result['sandbox_root'].'/gate-ran');
        self::assertFileDoesNotExist($main.'/escaped');
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

    public function test_planted_symlink_component_cannot_escape_sandbox(): void
    {
        $key = 'symlink-'.bin2hex(random_bytes(4));
        $sandbox = sys_get_temp_dir().'/atlas-native-sandbox-'.substr(hash('sha256', $key), 0, 24);
        $outside = sys_get_temp_dir().'/atlas-outside-'.bin2hex(random_bytes(4));
        mkdir($sandbox, 0o700, true);
        mkdir($outside, 0o700, true);
        symlink($outside, $sandbox.'/app');

        $result = (new AtlasSelfConstructionHermeticSandboxApplyService)->execute([
            'idempotency_key' => $key,
            'allowed_files' => ['app/Escape.php'],
            'patch_plan' => ['allowed_files' => ['app/Escape.php'], 'patches' => [[
                'path' => 'app/Escape.php', 'mode' => 'create', 'next' => 'escaped',
            ]]],
        ]);

        self::assertFalse($result['applied']);
        self::assertSame('sandbox_symlink_detected', $result['reason']);
        self::assertFileDoesNotExist($outside.'/Escape.php');
    }

    public function test_restart_reconciles_staged_provider_receipt_and_refuses_changed_output(): void
    {
        $key = 'kill-window-'.bin2hex(random_bytes(4));
        $first = ['status' => 'ok', 'patch_plan' => ['allowed_files' => ['app/X.php'], 'patches' => []]];
        $changed = ['status' => 'ok', 'patch_plan' => ['allowed_files' => ['app/X.php'], 'patches' => [['path' => 'app/X.php']]]];
        $service = new AtlasSelfConstructionHermeticSandboxApplyService;

        self::assertTrue($service->stageProvider($key, $first));
        $restarted = (new AtlasSelfConstructionHermeticSandboxApplyService)->reconcile($key);
        self::assertSame('provider_staged', $restarted['state']);
        self::assertSame($first, $restarted['provider_receipt']);
        self::assertFalse($service->stageProvider($key, $changed));
    }
}
