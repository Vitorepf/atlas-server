<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NativeImplementation;

use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerCommandPlanRunner;

/** Executes a proposal only inside an isolated disposable filesystem root. */
final class AtlasSelfConstructionHermeticSandboxApplyService
{
    public function __construct(
        private readonly ?AtlasSelfConstructionNativePatchMaterializer $materializer = null,
        private readonly ?AtlasNativeWorkerCommandPlanRunner $commands = null,
    ) {}

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function execute(array $input): array
    {
        $key = trim((string) ($input['idempotency_key'] ?? ''));
        if ($key === '') {
            return ['applied' => false, 'dry_run' => false, 'reason' => 'idempotency_key_missing'];
        }
        $sandbox = sys_get_temp_dir().'/atlas-native-sandbox-'.substr(hash('sha256', $key), 0, 24);
        if (! is_dir($sandbox) && ! mkdir($sandbox, 0o700, true) && ! is_dir($sandbox)) {
            return ['applied' => false, 'dry_run' => false, 'reason' => 'sandbox_create_failed'];
        }

        $proposal = ($this->materializer ?? new AtlasSelfConstructionNativePatchMaterializer)
            ->materialize((array) ($input['patch_plan'] ?? []));
        if (($proposal['accepted'] ?? false) !== true) {
            return ['applied' => false, 'dry_run' => false, 'reason' => 'materialization_refused', 'sandbox_root' => $sandbox];
        }

        $idempotencyReceipt = hash('sha256', $key.'|'.(string) json_encode($proposal, JSON_UNESCAPED_SLASHES));
        $alreadyApplied = ($proposal['files'] ?? []) !== [];
        foreach ((array) ($proposal['files'] ?? []) as $file) {
            if (! is_array($file)) {
                $alreadyApplied = false;
                break;
            }
            $path = $sandbox.'/'.(string) ($file['path'] ?? '');
            if (! is_file($path) || hash_file('sha256', $path) !== hash('sha256', (string) ($file['contents'] ?? ''))) {
                $alreadyApplied = false;
                break;
            }
        }
        if ($alreadyApplied) {
            return [
                'applied' => true,
                'replayed' => true,
                'dry_run' => false,
                'sandbox_root' => $sandbox,
                'idempotency_receipt' => $idempotencyReceipt,
                'diffs' => $proposal['diffs'] ?? [],
            ];
        }

        $files = [];
        foreach ((array) ($proposal['files'] ?? []) as $file) {
            if (! is_array($file)) {
                continue;
            }
            $contents = (string) ($file['contents'] ?? '');
            $files[] = $file + [
                'mode' => 'create',
                'patch_artifact_hash' => hash('sha256', $contents),
            ];
        }
        $apply = (new AtlasSelfConstructionNativeScopedPatchApplyRunner($sandbox))->apply(
            ['decision' => 'allow'],
            ['allowed_files' => (array) ($input['allowed_files'] ?? []), 'files' => $files],
        );
        if (($apply['refused'] ?? true) === true || ($apply['applied_files'] ?? []) === []) {
            return ['applied' => false, 'dry_run' => false, 'reason' => 'sandbox_apply_failed', 'sandbox_root' => $sandbox, 'apply_receipt' => $apply];
        }

        $commandPlan = [];
        foreach ((array) ($input['command_plan'] ?? []) as $command) {
            if (is_array($command)) {
                $command['cwd'] = $sandbox;
                $commandPlan[] = $command;
            }
        }
        $names = array_values(array_filter(array_map(static fn (array $row): string => (string) ($row['name'] ?? ''), $commandPlan)));
        $commandReceipt = ($this->commands ?? new AtlasNativeWorkerCommandPlanRunner)
            ->execute(['command_allowlist' => $names], $commandPlan, dryRun: false);
        foreach ((array) ($commandReceipt['results'] ?? []) as $row) {
            if (! is_array($row) || ($row['status'] ?? '') !== AtlasNativeWorkerCommandPlanRunner::STATUS_OK || ($row['exit_code'] ?? 1) !== 0) {
                return ['applied' => false, 'dry_run' => false, 'reason' => 'sandbox_command_failed', 'sandbox_root' => $sandbox, 'command_receipt' => $commandReceipt];
            }
        }

        return [
            'applied' => true,
            'replayed' => false,
            'dry_run' => false,
            'sandbox_root' => $sandbox,
            'apply_receipt' => $apply,
            'command_receipt' => $commandReceipt,
            'diffs' => $proposal['diffs'] ?? [],
            'idempotency_receipt' => $idempotencyReceipt,
        ];
    }
}
