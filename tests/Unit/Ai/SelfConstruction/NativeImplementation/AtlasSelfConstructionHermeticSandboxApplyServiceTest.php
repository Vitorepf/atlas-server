<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\NativeImplementation;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionHermeticSandboxApplyService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AtlasSelfConstructionHermeticSandboxApplyServiceTest extends TestCase
{
    public function test_planted_git_directory_is_quarantined_and_recloned_from_bound_source(): void
    {
        [$source, $base] = $this->gitSource('planted');
        $key = 'planted-git-'.bin2hex(random_bytes(4));
        $sandbox = sys_get_temp_dir().'/atlas-native-sandbox-'.substr(hash('sha256', $key), 0, 24);
        mkdir($sandbox.'/.git', 0o700, true);
        file_put_contents($sandbox.'/owned', 'attacker');

        $result = (new AtlasSelfConstructionHermeticSandboxApplyService)->execute($this->gitInput($key, $source, $base));

        self::assertTrue($result['applied']);
        self::assertFileDoesNotExist($sandbox.'/owned');
        self::assertSame($base, trim((new Process(['git', 'rev-parse', 'HEAD'], $sandbox))->mustRun()->getOutput()));
    }

    public function test_same_key_with_different_source_or_base_never_reuses_candidate(): void
    {
        [$sourceA, $baseA] = $this->gitSource('a');
        [$sourceB, $baseB] = $this->gitSource('b');
        $key = 'source-swap-'.bin2hex(random_bytes(4));
        $service = new AtlasSelfConstructionHermeticSandboxApplyService;
        self::assertTrue($service->execute($this->gitInput($key, $sourceA, $baseA))['applied']);

        $swapped = $service->execute($this->gitInput($key, $sourceB, $baseB));

        self::assertTrue($swapped['applied']);
        self::assertFalse($swapped['replayed']);
        self::assertSame($baseB, trim((new Process(['git', 'rev-parse', 'HEAD'], $swapped['sandbox_root']))->mustRun()->getOutput()));
    }

    public function test_source_vendor_symlink_is_refused_and_never_shared(): void
    {
        [$source, $base] = $this->gitSource('vendor-link');
        $outside = sys_get_temp_dir().'/atlas-vendor-outside-'.bin2hex(random_bytes(3));
        mkdir($outside, 0o700, true);
        symlink($outside, $source.'/vendor');

        $result = (new AtlasSelfConstructionHermeticSandboxApplyService)->execute(
            $this->gitInput('vendor-link-'.bin2hex(random_bytes(3)), $source, $base),
        );

        self::assertFalse($result['applied']);
        self::assertSame('source_git_baseline_invalid', $result['reason']);
    }

    public function test_apply_is_uncertain_when_applied_manifest_cannot_be_persisted(): void
    {
        $service = new AtlasSelfConstructionHermeticSandboxApplyService(manifestWriter: fn (): bool => false);
        $result = $service->execute(['idempotency_key' => 'write-fail-'.bin2hex(random_bytes(4)), 'allowed_files' => ['X.php'],
            'patch_plan' => ['allowed_files' => ['X.php'], 'patches' => [['path' => 'X.php', 'mode' => 'create', 'next' => 'x']]]]);
        self::assertFalse($result['applied']);
        self::assertSame('reconciliation_uncertain', $result['reason']);
    }

    /** @return array{string,string} */
    private function gitSource(string $suffix): array
    {
        $repo = sys_get_temp_dir().'/atlas-hermetic-source-'.$suffix.'-'.bin2hex(random_bytes(3));
        mkdir($repo.'/app', 0o700, true);
        file_put_contents($repo.'/app/X.php', "<?php\nreturn 'before';\n");
        foreach ([['init', '-b', 'main'], ['config', 'user.email', 'atlas@test.local'], ['config', 'user.name', 'Atlas Test'], ['add', '.'], ['commit', '-m', 'base']] as $args) {
            (new Process(['git', ...$args], $repo))->mustRun();
        }

        return [$repo, trim((new Process(['git', 'rev-parse', 'HEAD'], $repo))->mustRun()->getOutput())];
    }

    /** @return array<string,mixed> */
    private function gitInput(string $key, string $source, string $base): array
    {
        return [
            'idempotency_key' => $key, 'source_repo' => $source, 'base_commit' => $base,
            'allowed_files' => ['app/X.php'],
            'patch_plan' => ['allowed_files' => ['app/X.php'], 'patches' => [[
                'path' => 'app/X.php', 'mode' => 'modify', 'previous' => "<?php\nreturn 'before';\n", 'next' => "<?php\nreturn 'after';\n",
            ]]],
        ];
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

    public function test_restart_promotes_provider_staged_manifest_after_postimage_was_applied(): void
    {
        $key = 'promote-staged-'.bin2hex(random_bytes(4));
        $receipt = ['status' => 'ok', 'patch_plan' => ['allowed_files' => ['app/X.php'], 'patches' => [[
            'path' => 'app/X.php', 'mode' => 'create', 'next' => 'stable',
        ]]]];
        $service = new AtlasSelfConstructionHermeticSandboxApplyService;
        self::assertTrue($service->stageProvider($key, $receipt, ['task_packet_id' => 'task-1', 'lease_id' => 'lease-1']));

        $first = $service->execute([
            'idempotency_key' => $key,
            'task_packet_id' => 'task-1',
            'lease_id' => 'lease-1',
            'allowed_files' => ['app/X.php'],
            'patch_plan' => $receipt['patch_plan'],
            'provider_receipt' => $receipt,
        ]);
        self::assertTrue($first['applied']);

        $manifest = (new AtlasSelfConstructionHermeticSandboxApplyService)->reconcile($key);
        self::assertSame('applied', $manifest['state']);
        self::assertSame('task-1', $manifest['task_packet_id']);
        self::assertSame('lease-1', $manifest['lease_id']);
        self::assertNotEmpty($manifest['postimage_hashes']);
    }

    public function test_forged_applied_manifest_is_rejected_for_every_bound_hash(): void
    {
        $key = 'forged-manifest-'.bin2hex(random_bytes(4));
        $receipt = ['status' => 'ok', 'patch_plan' => ['allowed_files' => ['X.php'], 'patches' => [[
            'path' => 'X.php', 'mode' => 'create', 'next' => 'truth',
        ]]]];
        $service = new AtlasSelfConstructionHermeticSandboxApplyService;
        self::assertTrue($service->stageProvider($key, $receipt, ['task_packet_id' => 'task-f', 'lease_id' => 'lease-f']));
        self::assertTrue($service->execute([
            'idempotency_key' => $key, 'task_packet_id' => 'task-f', 'lease_id' => 'lease-f',
            'allowed_files' => ['X.php'], 'patch_plan' => $receipt['patch_plan'], 'provider_receipt' => $receipt,
        ])['applied']);

        $sandbox = sys_get_temp_dir().'/atlas-native-sandbox-'.substr(hash('sha256', $key), 0, 24);
        $manifestPath = $sandbox.'/.atlas-native-manifest.json';
        $original = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        foreach (['identity_hash', 'provider_hash', 'apply_hash', 'evidence_hash'] as $field) {
            $forged = $original;
            $forged[$field] = str_repeat('0', 64);
            file_put_contents($manifestPath, json_encode($forged, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            self::assertNull((new AtlasSelfConstructionHermeticSandboxApplyService)->reconcile($key), $field.' forgery accepted');
        }

        file_put_contents($manifestPath, json_encode($original, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        file_put_contents($sandbox.'/X.php', 'tampered');
        self::assertNull((new AtlasSelfConstructionHermeticSandboxApplyService)->reconcile($key), 'postimage forgery accepted');
    }

    public function test_reconcile_rejects_postimage_when_parent_directory_is_swapped_to_symlink(): void
    {
        $key = 'parent-symlink-'.bin2hex(random_bytes(4));
        $service = new AtlasSelfConstructionHermeticSandboxApplyService;
        $result = $service->execute([
            'idempotency_key' => $key, 'allowed_files' => ['app/X.php'],
            'patch_plan' => ['allowed_files' => ['app/X.php'], 'patches' => [[
                'path' => 'app/X.php', 'mode' => 'create', 'next' => 'truth',
            ]]],
        ]);
        self::assertTrue($result['applied']);

        $sandbox = $result['sandbox_root'];
        $outside = sys_get_temp_dir().'/atlas-native-outside-'.bin2hex(random_bytes(4));
        mkdir($outside, 0o700, true);
        file_put_contents($outside.'/X.php', 'truth');
        rename($sandbox.'/app', $sandbox.'/app-original');
        symlink($outside, $sandbox.'/app');

        self::assertNull((new AtlasSelfConstructionHermeticSandboxApplyService)->reconcile($key));
    }
}
