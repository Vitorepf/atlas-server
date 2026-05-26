<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Patamar 4 · C5 · Flag Activation + Receipt.
 *
 * Activates the 3 Patamar 4 flags that ship default-OFF (production
 * resolver F2, parallel dispatch F6, auto-failover A4) by writing them
 * into the operator's `.env` file. Records the activation as an append-
 * only JSONL receipt with sha256 + before/after values so an audit can
 * prove who switched what on which day.
 *
 * Defaults: dry-run. Operator must pass --apply to actually mutate .env.
 *
 * Provider-safe. Local-first. The flags only affect Atlas's own runtime;
 * no external benchmark / rivals / superiority claims are emitted.
 *
 * Authority doc: docs/engineering-knowledge-base/atlas-patamar4-flag-activation.md
 */
class AtlasPatamar4ActivateFlagsCommand extends Command
{
    protected $signature = 'atlas:patamar4:activate-flags
        {--apply : Persist the changes to .env (default is dry-run)}
        {--off : Set the flags to false instead of true}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Activate the Patamar 4 production flags (F2 resolver + F6 parallel + A4 failover) with audit receipt.';

    private const FLAGS = [
        'ATLAS_PATAMAR4_SWARM_PRODUCTION_RESOLVER_ENABLED' => 'atlas.patamar4.swarm_production_resolver_enabled',
        'ATLAS_PATAMAR4_SWARM_PARALLEL_ENABLED' => 'atlas.patamar4.swarm_parallel_enabled',
        'ATLAS_PATAMAR4_SWARM_AUTO_FAILOVER_ENABLED' => 'atlas.patamar4.swarm_auto_failover_enabled',
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $value = (bool) $this->option('off') ? 'false' : 'true';

        $envPath = base_path('.env');
        $envExists = is_file($envPath);
        $contents = $envExists ? (string) file_get_contents($envPath) : '';

        $diff = [];
        $newContents = $contents;
        foreach (self::FLAGS as $envKey => $configKey) {
            $current = $this->extractEnvValue($contents, $envKey);
            $diff[] = [
                'env_key' => $envKey,
                'config_key' => $configKey,
                'previous' => $current,
                'next' => $value,
                'changed' => $current !== $value,
            ];
            $newContents = $this->upsertEnvLine($newContents, $envKey, $value);
        }

        $receipt = [
            'schema_version' => 'atlas.patamar4.flag_activation.v1',
            'mode' => $apply ? 'apply' : 'dry_run',
            'env_path' => $envPath,
            'env_existed' => $envExists,
            'target_value' => $value,
            'diff' => $diff,
        ];

        if ($apply) {
            // Ensure storage dir exists for the receipt JSONL.
            $receiptDir = storage_path('atlas/patamar4');
            if (! is_dir($receiptDir)) {
                File::ensureDirectoryExists($receiptDir);
            }
            file_put_contents($envPath, $newContents);
            $receipt['written'] = true;
        } else {
            $receipt['written'] = false;
        }

        $receipt['receipt_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => 'atlas.patamar4.flag_activation.v1',
            'mode' => $receipt['mode'],
            'diff' => $diff,
        ], JSON_THROW_ON_ERROR));

        // Append-only JSONL receipt regardless of mode (dry_run included).
        $jsonl = storage_path('atlas/patamar4/flag_activations.jsonl');
        if (! is_dir(dirname($jsonl))) {
            File::ensureDirectoryExists(dirname($jsonl));
        }
        file_put_contents(
            $jsonl,
            json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL,
            FILE_APPEND
        );

        if ((bool) $this->option('json')) {
            $this->line(json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Mode</>', $receipt['mode']);
        foreach ($diff as $d) {
            $this->components->twoColumnDetail($d['env_key'], $d['previous'].' → '.$d['next'].($d['changed'] ? ' (changed)' : ''));
        }
        $this->components->twoColumnDetail('Receipt', $receipt['receipt_hash']);
        if (! $apply) {
            $this->line('  <fg=yellow>(dry-run — pass --apply to write .env)</>');
        }

        return self::SUCCESS;
    }

    private function extractEnvValue(string $contents, string $key): ?string
    {
        if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $contents, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function upsertEnvLine(string $contents, string $key, string $value): string
    {
        $line = $key.'='.$value;
        if (preg_match('/^'.preg_quote($key, '/').'=.*$/m', $contents)) {
            return preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $contents) ?? $contents;
        }

        return (rtrim($contents)."\n".$line."\n");
    }
}
