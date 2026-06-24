<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Throwable;

/**
 * P5 mitigation: adversarial re-read pool for proposals that already passed the deterministic verifier.
 *
 * The pool is flag-gated default-OFF. When armed, every configured verifier must pass; any dissent or
 * missing independent verifier parks the proposal for human review instead of promoting it.
 */
final class AtlasLoopAdversarialVerifierPool
{
    public const SCHEMA_VERSION = 'atlas.loop.adversarial_verifier_pool.v1';

    /** @var null|callable(array<string,mixed>,array<string,mixed>):array<string,mixed> */
    private $verifier;

    public function __construct(
        private readonly ?AtlasLoopProviderRouter $providerRouter = null,
        ?callable $verifier = null,
    ) {
        $this->verifier = $verifier;
    }

    /**
     * @param  array<string,mixed>|object  $proposal
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function verify(array|object $proposal, array $options = []): array
    {
        $proposal = $this->proposalArray($proposal);
        $configs = $this->verifierConfigurations($proposal, $options);
        $provided = $this->providedResults($proposal, $options);
        $lineItems = [];

        foreach ($configs as $i => $config) {
            $lineItems[] = $this->lineItem($proposal, $config, $provided[$i] ?? null);
        }

        $consensus = (new AtlasLoopJudgeConsensusGate)->evaluate($lineItems, [
            'policy' => 'unanimous',
            'required_lenses' => array_values(array_unique(array_column($configs, 'lens'))),
            'min_distinct_providers' => min(2, max(1, count(array_unique(array_filter(array_column($configs, 'provider')))))),
        ]);
        $passes = (bool) ($consensus['consensus'] ?? false);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'enabled' => true,
            'verdict' => $passes ? 'independently_verified' : 'parked_for_review',
            'passes' => $passes,
            'line_items' => $lineItems,
            'consensus' => $consensus,
            'reason' => $passes ? 'adversarial_pool_unanimous' : 'adversarial_pool_dissent',
        ];
    }

    /**
     * OFF returns the primary verifier verdict byte-for-byte. ON can only keep it verified or park it.
     *
     * @param  array<string,mixed>|object  $proposal
     * @param  array<string,mixed>  $primaryVerdict
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function reviewVerdict(array|object $proposal, array $primaryVerdict, array $options = []): array
    {
        if (! $this->enabled() || (string) ($primaryVerdict['outcome'] ?? '') !== 'independently_verified') {
            return $primaryVerdict;
        }

        $pool = $this->verify($proposal, $options);
        if ((string) ($pool['verdict'] ?? '') !== 'parked_for_review') {
            return $primaryVerdict + ['adversarial_verifier_pool' => $pool];
        }

        $reasons = array_values(array_unique(array_merge(
            array_map('strval', (array) ($primaryVerdict['reasons'] ?? [])),
            ['adversarial_verifier_pool_dissent'],
        )));

        return array_replace($primaryVerdict, [
            'outcome' => 'parked_for_review',
            'reasons' => $reasons,
            'merged_to_main' => false,
            'adversarial_verifier_pool' => $pool,
        ]);
    }

    public function enabled(): bool
    {
        try {
            return function_exists('config')
                && (bool) config('atlas.loop.adversarial_verifier_pool_enabled', false);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string,mixed>  $proposal
     * @param  array<string,mixed>  $options
     * @return list<array<string,mixed>>
     */
    public function verifierConfigurations(array $proposal, array $options = []): array
    {
        $providers = $this->providerCandidates($proposal, $options);
        $lenses = [
            ['lens' => 'correctness', 'effort' => 'medium'],
            ['lens' => 'security', 'effort' => 'high'],
            ['lens' => 'maintainability', 'effort' => 'low'],
        ];
        $count = max(2, count($lenses), count($providers));
        $configs = [];
        for ($i = 0; $i < $count; $i++) {
            $lens = $lenses[$i % count($lenses)];
            $provider = $providers !== [] ? $providers[$i % count($providers)] : '';
            $configs[] = [
                'index' => $i,
                'provider' => $provider,
                'lens' => $lens['lens'],
                'effort' => $lens['effort'],
                'prompt_frame' => 'adversarial_find_the_hole:'.$lens['lens'],
            ];
        }

        return $configs;
    }

    /**
     * @param  array<string,mixed>  $proposal
     * @param  array<string,mixed>  $options
     * @return list<string>
     */
    private function providerCandidates(array $proposal, array $options): array
    {
        $routing = is_array($options['provider_routing'] ?? null)
            ? $options['provider_routing']
            : (array) config('atlas.loop.provider_routing', []);
        $defaultProvider = trim((string) ($options['default_provider'] ?? $proposal['provider'] ?? ''));
        $configured = $this->cleanList($options['configured_providers'] ?? []);
        $isConfigured = static fn (string $provider): bool => $configured === [] || in_array($provider, $configured, true);
        $routed = ($this->providerRouter ?? new AtlasLoopProviderRouter)->route(
            (string) ($proposal['objective_kind'] ?? $proposal['mode'] ?? 'verification'),
            $defaultProvider,
            null,
            $routing,
            $isConfigured,
        );

        return $this->cleanList(array_merge(
            [(string) ($routed['provider'] ?? '')],
            $options['providers'] ?? [],
            (array) config('atlas.loop.adversarial_verifier_pool.providers', []),
        ));
    }

    /**
     * @param  array<string,mixed>  $proposal
     * @param  array<string,mixed>  $config
     * @param  array<string,mixed>|null  $provided
     * @return array{lens:string,provider:string,passes:bool,reason:string,effort:string,prompt_frame:string}
     */
    private function lineItem(array $proposal, array $config, ?array $provided): array
    {
        if ($provided !== null) {
            return $this->normalizeLineItem($provided, $config);
        }
        if (is_callable($this->verifier)) {
            try {
                return $this->normalizeLineItem(($this->verifier)($proposal, $config), $config);
            } catch (Throwable $e) {
                return $this->normalizeLineItem([
                    'passes' => false,
                    'reason' => 'verifier_exception:'.mb_substr($e->getMessage(), 0, 80),
                ], $config);
            }
        }

        return $this->normalizeLineItem([
            'passes' => false,
            'reason' => 'adversarial_verifier_not_configured',
        ], $config);
    }

    /**
     * @param  array<string,mixed>  $item
     * @param  array<string,mixed>  $config
     * @return array{lens:string,provider:string,passes:bool,reason:string,effort:string,prompt_frame:string}
     */
    private function normalizeLineItem(array $item, array $config): array
    {
        return [
            'lens' => trim((string) ($item['lens'] ?? $config['lens'] ?? 'correctness')),
            'provider' => trim((string) ($item['provider'] ?? $config['provider'] ?? '')),
            'passes' => (bool) ($item['passes'] ?? false),
            'reason' => mb_substr(trim((string) ($item['reason'] ?? (($item['passes'] ?? false) ? 'pass' : 'failed'))), 0, 180),
            'effort' => trim((string) ($item['effort'] ?? $config['effort'] ?? 'medium')),
            'prompt_frame' => trim((string) ($item['prompt_frame'] ?? $config['prompt_frame'] ?? 'adversarial_find_the_hole')),
        ];
    }

    /**
     * @param  array<string,mixed>  $proposal
     * @param  array<string,mixed>  $options
     * @return list<array<string,mixed>>
     */
    private function providedResults(array $proposal, array $options): array
    {
        $raw = $options['verifier_results'] ?? $proposal['adversarial_verifier_results'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, static fn (mixed $item): bool => is_array($item)));
    }

    /**
     * @param  array<string,mixed>|object  $proposal
     * @return array<string,mixed>
     */
    private function proposalArray(array|object $proposal): array
    {
        if (is_array($proposal)) {
            return $proposal;
        }
        if (method_exists($proposal, 'toArray')) {
            $array = $proposal->toArray();

            return is_array($array) ? $array : [];
        }

        return get_object_vars($proposal);
    }

    /**
     * @return list<string>
     */
    private function cleanList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $entry) {
            $entry = is_string($entry) ? trim($entry) : '';
            if ($entry !== '') {
                $out[] = $entry;
            }
        }

        return array_values(array_unique($out));
    }
}
