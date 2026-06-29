<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Throwable;

/**
 * Cross-model fact transport for disputed certification verdicts.
 *
 * It never computes a smoothed scalar. The output is only categorical agreement plus per-provider receipts
 * anchored to the same frozen acceptance bundle hash.
 */
final class AtlasLoopCrossModelTriangulator
{
    public const SCHEMA_VERSION = 'atlas.loop.cross_model_triangulation.v1';

    /** @var null|callable(array<string,mixed>,array<string,mixed>):array<string,mixed> */
    private $runner;

    public function __construct(?callable $runner = null)
    {
        $this->runner = $runner;
    }

    /**
     * @param  array<string,mixed>  $frozenBundle
     * @param  list<array<string,mixed>>  $providerVerdicts
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function triangulate(array $frozenBundle, array $providerVerdicts = [], array $options = []): array
    {
        $bundleHash = $this->stableHash($frozenBundle);
        if (! AtlasLoopMasterSwitch::enabled()) {
            return $this->insufficient($bundleHash, 'master_switch_off');
        }
        if (! $this->enabled()) {
            return $this->insufficient($bundleHash, 'cross_model_triangulation_disabled');
        }

        $receipts = $this->receipts($frozenBundle, $providerVerdicts, $options, $bundleHash);
        $providers = array_values(array_unique(array_filter(array_column($receipts, 'provider_id'))));
        if (count($providers) < 3) {
            return $this->insufficient($bundleHash, 'insufficient_witnesses:'.count($providers), $receipts);
        }

        $lineItems = array_map(static fn (array $receipt): array => [
            'lens' => 'correctness',
            'provider' => (string) $receipt['provider_id'],
            'passes' => (bool) $receipt['passes'],
            'reason' => (string) ($receipt['reason'] ?? 'verdict'),
        ], $receipts);
        $consensus = (new AtlasLoopJudgeConsensusGate)->evaluate($lineItems, [
            'policy' => 'unanimous',
            'min_distinct_providers' => 3,
        ]);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'verdict' => $this->triangulatedVerdict($receipts),
            'frozen_bundle_hash' => $bundleHash,
            'receipts' => $receipts,
            'consensus' => $this->stripGoodhartKeys($consensus),
        ];
    }

    public function enabled(): bool
    {
        try {
            return function_exists('config')
                && (bool) config('atlas.loop.cross_model_triangulation_enabled', false);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string,mixed>  $frozenBundle
     * @param  list<array<string,mixed>>  $providerVerdicts
     * @param  array<string,mixed>  $options
     * @return list<array<string,mixed>>
     */
    private function receipts(array $frozenBundle, array $providerVerdicts, array $options, string $bundleHash): array
    {
        $raw = $providerVerdicts;
        if ($raw === [] && is_callable($this->runner)) {
            foreach ($this->providerPlan($options) as $provider) {
                $raw[] = ($this->runner)($provider, $frozenBundle);
            }
        }

        $receipts = [];
        foreach ($raw as $i => $verdict) {
            if (! is_array($verdict)) {
                continue;
            }
            $providerId = trim((string) ($verdict['provider_id'] ?? $verdict['provider'] ?? $this->providerPlan($options)[$i]['provider_id'] ?? ''));
            if ($providerId === '') {
                continue;
            }
            $judgeId = trim((string) ($verdict['judge_id'] ?? 'frozen_acceptance_judge'));
            $rawVerdict = $this->stripGoodhartKeys($verdict['raw_verdict'] ?? $verdict);
            $receipts[] = [
                'provider_id' => $providerId,
                'judge_id' => $judgeId !== '' ? $judgeId : 'frozen_acceptance_judge',
                'frozen_bundle_hash' => $bundleHash,
                'passes' => $this->passes($rawVerdict),
                'reason' => (string) ($rawVerdict['reason'] ?? $rawVerdict['outcome'] ?? 'verdict'),
                'raw_verdict' => $rawVerdict,
            ];
        }

        usort($receipts, static function (array $a, array $b): int {
            return strcmp((string) $a['provider_id'], (string) $b['provider_id'])
                ?: strcmp((string) $a['judge_id'], (string) $b['judge_id']);
        });

        return $receipts;
    }

    /**
     * @param  array<string,mixed>  $options
     * @return list<array{provider_id:string,model:string,tier:string,effort:string}>
     */
    private function providerPlan(array $options): array
    {
        $providers = is_array($options['providers'] ?? null) ? $options['providers'] : [];
        if ($providers === []) {
            $providers = [
                ['provider_id' => 'minimax_m3', 'model' => 'MiniMax-M3', 'tier' => 'grind', 'effort' => 'standard'],
                ['provider_id' => 'codex', 'model' => 'gpt-5.5', 'tier' => 'hard_only', 'effort' => 'high'],
                ['provider_id' => 'glm', 'model' => 'GLM-5.2', 'tier' => 'fallback', 'effort' => 'standard'],
            ];
        }

        $out = [];
        foreach ($providers as $provider) {
            if (is_string($provider)) {
                $provider = ['provider_id' => $provider, 'model' => '', 'tier' => 'unspecified', 'effort' => 'standard'];
            }
            if (! is_array($provider)) {
                continue;
            }
            $id = trim((string) ($provider['provider_id'] ?? $provider['provider'] ?? ''));
            if ($id === '') {
                continue;
            }
            $out[] = [
                'provider_id' => $id,
                'model' => trim((string) ($provider['model'] ?? '')),
                'tier' => trim((string) ($provider['tier'] ?? 'unspecified')),
                'effort' => trim((string) ($provider['effort'] ?? 'standard')),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $receipts
     */
    private function triangulatedVerdict(array $receipts): string
    {
        $pass = 0;
        $fail = 0;
        foreach ($receipts as $receipt) {
            (bool) $receipt['passes'] ? $pass++ : $fail++;
        }
        if ($pass === count($receipts) || $fail === count($receipts)) {
            return 'agree';
        }

        return $pass > 0 && $fail > 0 ? 'dissent' : 'split';
    }

    /**
     * @param  array<string,mixed>  $verdict
     */
    private function passes(array $verdict): bool
    {
        foreach (['passes', 'passed', 'consensus'] as $key) {
            if (array_key_exists($key, $verdict)) {
                return (bool) $verdict[$key];
            }
        }

        $outcome = (string) ($verdict['outcome'] ?? $verdict['verdict'] ?? '');

        return in_array($outcome, ['pass', 'passed', 'verified', 'independently_verified', 'agree'], true);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function stableHash(array $data): string
    {
        $data = $this->sortKeys($data);

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function sortKeys(array $data): array
    {
        ksort($data);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sortKeys($value);
            }
        }

        return $data;
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function stripGoodhartKeys(array $data): array
    {
        foreach (['score', 'confidence', 'average'] as $key) {
            unset($data[$key]);
        }
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->stripGoodhartKeys($value);
            }
        }

        return $data;
    }

    /**
     * @param  list<array<string,mixed>>  $receipts
     * @return array<string,mixed>
     */
    private function insufficient(string $bundleHash, string $reason, array $receipts = []): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'verdict' => 'insufficient_witnesses',
            'reason' => $reason,
            'frozen_bundle_hash' => $bundleHash,
            'receipts' => $receipts,
            'consensus' => [
                'consensus' => false,
                'passes' => false,
                'total' => count($receipts),
                'passed' => 0,
                'distinct_providers' => count(array_unique(array_filter(array_column($receipts, 'provider_id')))),
                'covered_lenses' => [],
                'dissents' => [$reason],
                'reason' => $reason,
            ],
        ];
    }
}
