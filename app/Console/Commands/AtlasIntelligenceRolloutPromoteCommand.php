<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\AtlasIntelligenceRolloutMode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Promote ACOS intelligence features along offline → shadow → canary → default
 * after certifier gates pass. Kill switch: --off sets mode=offline (+ enabled=false
 * for retrieval/fusion). Defaults to dry-run; --apply writes .env + JSONL receipt.
 */
class AtlasIntelligenceRolloutPromoteCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:intelligence:rollout-promote
        {feature : unified_retrieval|fusion|gateway_consultation|engineering_outcome|all}
        {--to=shadow : Target mode (offline|shadow|canary|default)}
        {--canary-percent=10 : Canary bucket percent when --to=canary}
        {--skip-gates : Skip certifier gates (operator override)}
        {--apply : Persist changes to .env (default is dry-run)}
        {--off : Kill switch — force offline and disable kill switches}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Promote ACOS intelligence rollout modes with certifier gates and audit receipt.';

    /** @var array<string, array{env_mode:string, env_enabled:?string, env_canary:?string, config_mode:string, gates:list<string>}> */
    private const FEATURES = [
        'unified_retrieval' => [
            'env_mode' => 'ATLAS_CONTEXT_RUNTIME_UNIFIED_RETRIEVAL_MODE',
            'env_enabled' => 'ATLAS_CONTEXT_RUNTIME_UNIFIED_RETRIEVAL_ENABLED',
            'env_canary' => 'ATLAS_CONTEXT_RUNTIME_UNIFIED_RETRIEVAL_CANARY_PERCENT',
            'config_mode' => 'atlas.context_runtime.unified_retrieval_mode',
            'gates' => ['context_quality', 'slo'],
        ],
        'fusion' => [
            'env_mode' => 'ATLAS_AOBG_FUSION_MODE',
            'env_enabled' => 'ATLAS_AOBG_FUSION_ENABLED',
            'env_canary' => 'ATLAS_AOBG_FUSION_CANARY_PERCENT',
            'config_mode' => 'atlas.aobg.fusion_mode',
            'gates' => ['context_quality'],
        ],
        'gateway_consultation' => [
            'env_mode' => 'ATLAS_DECIDE_GATEWAY_CONSULTATION_MODE',
            'env_enabled' => 'ATLAS_DECIDE_GATEWAY_CONSULTATION_ENABLED',
            'env_canary' => null,
            'config_mode' => 'atlas.atlas_decide.gateway_consultation_mode',
            'gates' => ['slo'],
        ],
        'engineering_outcome' => [
            'env_mode' => 'ATLAS_AEMOR_ENGINEERING_OUTCOME_MODE',
            'env_enabled' => 'ATLAS_AEMOR_ENGINEERING_OUTCOME_ENABLED',
            'env_canary' => 'ATLAS_AEMOR_ENGINEERING_OUTCOME_CANARY_PERCENT',
            'config_mode' => 'atlas.aemor.engineering_outcome_mode',
            'gates' => ['aemor'],
        ],
    ];

    public function handle(): int
    {
        $featureArg = strtolower(trim((string) $this->argument('feature')));
        $off = (bool) $this->option('off');
        $apply = (bool) $this->option('apply');
        $skipGates = (bool) $this->option('skip-gates');
        $to = $off ? AtlasIntelligenceRolloutMode::OFFLINE : strtolower(trim((string) $this->option('to')));
        if ($to === 'active') {
            $to = AtlasIntelligenceRolloutMode::DEFAULT;
        }
        if (! in_array($to, AtlasIntelligenceRolloutMode::MODES, true)) {
            $this->error('Invalid --to mode. Use offline|shadow|canary|default.');

            return self::FAILURE;
        }

        $features = $featureArg === 'all'
            ? array_keys(self::FEATURES)
            : [$featureArg];
        foreach ($features as $feature) {
            if (! isset(self::FEATURES[$feature])) {
                $this->error("Unknown feature: {$feature}");

                return self::FAILURE;
            }
        }

        $gates = [];
        if (! $off && ! $skipGates && $to !== AtlasIntelligenceRolloutMode::OFFLINE) {
            $required = [];
            foreach ($features as $feature) {
                foreach (self::FEATURES[$feature]['gates'] as $gate) {
                    $required[$gate] = true;
                }
            }
            $gates = $this->runGates(array_keys($required));
            $failed = array_filter($gates, static fn (array $g): bool => ($g['status'] ?? '') !== 'pass');
            if ($failed !== []) {
                $receipt = $this->writeReceipt([
                    'mode' => $apply ? 'apply_blocked' : 'dry_run_blocked',
                    'target_mode' => $to,
                    'features' => $features,
                    'gates' => $gates,
                    'diff' => [],
                    'written' => false,
                    'reason' => 'certifier_gates_failed',
                ]);

                return $this->emit($receipt, self::FAILURE);
            }
        } elseif ($skipGates) {
            $gates = [['gate' => 'skipped', 'status' => 'skipped', 'reason' => 'operator_override']];
        }

        $envPath = base_path('.env');
        $contents = is_file($envPath) ? (string) file_get_contents($envPath) : '';
        $newContents = $contents;
        $diff = [];
        $canaryPercent = max(0, min(100, (int) $this->option('canary-percent')));

        foreach ($features as $feature) {
            $spec = self::FEATURES[$feature];
            $modeValue = $feature === 'gateway_consultation' && $to === AtlasIntelligenceRolloutMode::DEFAULT
                ? 'active'
                : $to;
            $enabledValue = ($off || $to === AtlasIntelligenceRolloutMode::OFFLINE) ? 'false' : 'true';

            $prevMode = $this->extractEnvValue($contents, $spec['env_mode']);
            $diff[] = [
                'feature' => $feature,
                'env_key' => $spec['env_mode'],
                'previous' => $prevMode,
                'next' => $modeValue,
                'changed' => $prevMode !== $modeValue,
            ];
            $newContents = $this->upsertEnvLine($newContents, $spec['env_mode'], $modeValue);

            if ($spec['env_enabled'] !== null) {
                $prevEnabled = $this->extractEnvValue($contents, $spec['env_enabled']);
                $diff[] = [
                    'feature' => $feature,
                    'env_key' => $spec['env_enabled'],
                    'previous' => $prevEnabled,
                    'next' => $enabledValue,
                    'changed' => $prevEnabled !== $enabledValue,
                ];
                $newContents = $this->upsertEnvLine($newContents, $spec['env_enabled'], $enabledValue);
            }

            if ($spec['env_canary'] !== null && $to === AtlasIntelligenceRolloutMode::CANARY) {
                $prevCanary = $this->extractEnvValue($contents, $spec['env_canary']);
                $canaryValue = (string) $canaryPercent;
                $diff[] = [
                    'feature' => $feature,
                    'env_key' => $spec['env_canary'],
                    'previous' => $prevCanary,
                    'next' => $canaryValue,
                    'changed' => $prevCanary !== $canaryValue,
                ];
                $newContents = $this->upsertEnvLine($newContents, $spec['env_canary'], $canaryValue);
            }
        }

        $written = false;
        if ($apply) {
            file_put_contents($envPath, $newContents);
            $written = true;
        }

        $receipt = $this->writeReceipt([
            'mode' => $apply ? 'apply' : 'dry_run',
            'target_mode' => $to,
            'features' => $features,
            'gates' => $gates,
            'diff' => $diff,
            'written' => $written,
            'kill_switch' => $off,
        ]);

        return $this->emit($receipt, self::SUCCESS);
    }

    /**
     * @param  list<string>  $gates
     * @return list<array<string,mixed>>
     */
    private function runGates(array $gates): array
    {
        $results = [];
        foreach ($gates as $gate) {
            $results[] = match ($gate) {
                'context_quality' => $this->runArtisanGate('atlas:context:quality-certify', ['--json' => true], 'context_quality'),
                'slo' => $this->runArtisanGate('atlas:ai:slo', ['--hours' => 24, '--json' => true], 'slo'),
                'aemor' => $this->runArtisanGate('atlas:aemor:readiness', ['--json' => true], 'aemor'),
                default => ['gate' => $gate, 'status' => 'unknown', 'exit_code' => null],
            };
        }

        return $results;
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function runArtisanGate(string $command, array $options, string $gate): array
    {
        try {
            $exit = Artisan::call($command, $options);
            $output = Artisan::output();

            return [
                'gate' => $gate,
                'command' => $command,
                'status' => $exit === 0 ? 'pass' : 'fail',
                'exit_code' => $exit,
                'output_hash' => hash('sha256', $output),
            ];
        } catch (\Throwable $exception) {
            return [
                'gate' => $gate,
                'command' => $command,
                'status' => 'fail',
                'exit_code' => 1,
                'reason' => 'gate_exception',
                'error_hash' => hash('sha256', $exception->getMessage()),
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function writeReceipt(array $payload): array
    {
        $receipt = array_merge([
            'schema_version' => 'atlas.intelligence.rollout_promotion.v1',
            'env_path' => base_path('.env'),
            'recorded_at' => now()->toIso8601String(),
        ], $payload);
        $receipt['receipt_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => $receipt['schema_version'],
            'mode' => $receipt['mode'] ?? null,
            'target_mode' => $receipt['target_mode'] ?? null,
            'features' => $receipt['features'] ?? [],
            'diff' => $receipt['diff'] ?? [],
            'gates' => $receipt['gates'] ?? [],
        ], JSON_THROW_ON_ERROR));

        $jsonl = storage_path('atlas/intelligence/rollout_promotions.jsonl');
        if (! is_dir(dirname($jsonl))) {
            File::ensureDirectoryExists(dirname($jsonl));
        }
        file_put_contents(
            $jsonl,
            json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL,
            FILE_APPEND,
        );

        return $receipt;
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function emit(array $receipt, int $exit): int
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode($receipt));

            return $exit;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Mode</>', (string) ($receipt['mode'] ?? ''));
        $this->components->twoColumnDetail('Target', (string) ($receipt['target_mode'] ?? ''));
        foreach ((array) ($receipt['diff'] ?? []) as $d) {
            $this->components->twoColumnDetail(
                (string) ($d['env_key'] ?? ''),
                ($d['previous'] ?? 'null').' → '.($d['next'] ?? '').(! empty($d['changed']) ? ' (changed)' : ''),
            );
        }
        foreach ((array) ($receipt['gates'] ?? []) as $g) {
            $this->components->twoColumnDetail('gate:'.($g['gate'] ?? '?'), (string) ($g['status'] ?? ''));
        }
        $this->components->twoColumnDetail('Receipt', (string) ($receipt['receipt_hash'] ?? ''));
        if (! ($receipt['written'] ?? false)) {
            $this->line('  <fg=yellow>(dry-run — pass --apply to write .env)</>');
        }

        return $exit;
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

        return rtrim($contents)."\n".$line."\n";
    }
}
