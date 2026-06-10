<?php

declare(strict_types=1);

namespace App\Services\Ai\Gateway;

use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Teos\AtlasTeosI4CounterfactualTreeService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use App\Services\Ai\Support\AppendOnlyJsonlStore;

/**
 * Atlas Gateway Preflight Service — Patamar 4 wiring.
 *
 * "Ensaio mental antes de gastar token". Sit between
 * AiGatewayService.enqueueInteraction and the real provider call: when the
 * decision is classified as "major" (high stakes / sensitive scope /
 * expensive route), this service spins up a TEOS-I4 counterfactual tree
 * BEFORE the job is enqueued, projects the best alternative path, and
 * attaches the projection envelope to the job metadata.
 *
 * Authority doc: docs/engineering-knowledge-base/atlas-gateway-preflight.md
 *
 * Schema: atlas.gateway.preflight_envelope.v1
 *
 * Invariants:
 *   - NEVER blocks a job by itself; only annotates (advisory).
 *   - Defensive degradation: any error → returns NOT_PROJECTED envelope.
 *   - Append-only JSONL receipt with sweep_hash.
 *   - claim_policy provider-safe enforced through TEOS-I4 + Kernel chain.
 */
final class AtlasGatewayPreflightService
{
    public const ENVELOPE_SCHEMA = 'atlas.gateway.preflight_envelope.v1';

    public const VERDICT_NOT_PROJECTED = 'not_projected';

    public const VERDICT_PROJECTED_OK = 'projected_ok';

    public const VERDICT_PROJECTED_LOW_GAIN = 'projected_low_gain';

    public const VERDICT_PROJECTED_KERNEL_BLOCK = 'projected_kernel_block';

    /** Minimum improvement delta to consider the projected branch worth following. */
    public const LOW_GAIN_THRESHOLD = 0.05;

    /** Privacy classes that mark a decision as "major" and trigger the preflight. */
    public const MAJOR_PRIVACY_CLASSES = ['sensitive', 'secret', 'cyber'];

    private ?string $logPathOverride = null;

    public function __construct(
        private readonly AtlasTeosI4CounterfactualTreeService $teosI4,
        private readonly AtlasConstitutionalKernelService $kernel,
    ) {}

    public function setLogPathForTesting(?string $path): void
    {
        $this->logPathOverride = $path;
    }

    public function logPath(): string
    {
        if ($this->logPathOverride !== null) {
            return $this->logPathOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/gateway')
            : sys_get_temp_dir().'/atlas/gateway';

        return $base.DIRECTORY_SEPARATOR.'preflight_envelopes.jsonl';
    }

    /**
     * Decide if this enqueue call qualifies as "major" — only majors are
     * preflighted, to keep token budget predictable.
     *
     * @param  array<string,mixed>  $options
     */
    public function isMajor(string $input, string $provider, array $options): bool
    {
        if ((bool) ($options['force_preflight'] ?? false)) {
            return true;
        }
        $privacy = (string) ($options['privacy_class'] ?? '');
        if (in_array($privacy, self::MAJOR_PRIVACY_CLASSES, true)) {
            return true;
        }
        $autonomy = (string) ($options['requested_autonomy'] ?? '');
        if ($autonomy === 'autonomous') {
            return true;
        }
        // Long composite prompts ("constrói o ecommerce dos sapatos com checkout") qualify.
        if (str_word_count($input) >= 8) {
            return true;
        }

        return false;
    }

    /**
     * Run preflight. Always returns an envelope (never throws). Caller
     * (AiGatewayService) attaches `preflight_envelope` to trace + job metadata.
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function preflight(string $input, string $provider, array $options): array
    {
        $startedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
        $major = $this->isMajor($input, $provider, $options);

        if (! $major) {
            return $this->persist($this->envelope(
                startedAt: $startedAt,
                verdict: self::VERDICT_NOT_PROJECTED,
                provider: $provider,
                input: $input,
                tree: null,
                improvement: 0.0,
                note: 'decision not classified as major; preflight skipped',
            ));
        }

        try {
            $anchor = 'gw_'.substr(hash('sha256', $input.'|'.$provider), 0, 12);
            $alternatives = $this->deriveAlternatives($provider, $options);
            $tree = $this->teosI4->expand([
                'anchor_decision_id' => $anchor,
                'alternatives' => $alternatives,
                'max_breadth' => 3,
                'max_depth' => 2,
                'scope' => [
                    'privacy_class' => (string) ($options['privacy_class'] ?? 'normal'),
                ],
                'factual_outcome_score' => 0.5,
                'projected_outcome_score' => 0.55,
            ]);

            $bestImprovement = (float) ($tree['best_improvement'] ?? 0.0);
            $kernelDecision = (string) ($tree['kernel_decision'] ?? '');

            $verdict = match (true) {
                $kernelDecision === AtlasConstitutionalKernelService::DECISION_BLOCK => self::VERDICT_PROJECTED_KERNEL_BLOCK,
                $bestImprovement < self::LOW_GAIN_THRESHOLD => self::VERDICT_PROJECTED_LOW_GAIN,
                default => self::VERDICT_PROJECTED_OK,
            };

            return $this->persist($this->envelope(
                startedAt: $startedAt,
                verdict: $verdict,
                provider: $provider,
                input: $input,
                tree: $tree,
                improvement: $bestImprovement,
                note: 'preflight tree expanded',
            ));
        } catch (\Throwable $e) {
            return $this->persist($this->envelope(
                startedAt: $startedAt,
                verdict: self::VERDICT_NOT_PROJECTED,
                provider: $provider,
                input: $input,
                tree: null,
                improvement: 0.0,
                note: 'preflight error: '.substr($e->getMessage(), 0, 160),
            ));
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listEnvelopes(): array
    {
        return AppendOnlyJsonlStore::read($this->logPath());
    }

    public function lastEnvelope(): ?array
    {
        $list = $this->listEnvelopes();

        return $list === [] ? null : $list[count($list) - 1];
    }

    // ---------- internals ----------

    /**
     * @param  array<string,mixed>  $options
     * @return list<array<string,mixed>>
     */
    private function deriveAlternatives(string $provider, array $options): array
    {
        // Canon alternatives: keep the proposed provider, plus 2 generic options
        // — "swap_to_runner_up" and "request_human_review". These are projection
        // intents, not actual routing decisions — TEOS-I4 only simulates outcome.
        // Use canon TEOS alternative kinds: provider_swap, escalation, replan.
        return [
            ['decision_kind' => 'provider_swap', 'value' => $provider.'_runner_up'],
            ['decision_kind' => 'escalation', 'value' => 'operator'],
            ['decision_kind' => 'replan', 'value' => 'narrow_scope_first'],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $tree
     * @return array<string,mixed>
     */
    private function envelope(
        string $startedAt,
        string $verdict,
        string $provider,
        string $input,
        ?array $tree,
        float $improvement,
        string $note,
    ): array {
        $env = [
            'schema_version' => self::ENVELOPE_SCHEMA,
            'started_at' => $startedAt,
            'verdict' => $verdict,
            'provider' => $provider,
            'input_hash' => 'sha256:'.hash('sha256', $input),
            'projected_improvement' => round($improvement, 4),
            'tree_id' => $tree['tree_id'] ?? null,
            'tree_hash' => $tree['tree_hash'] ?? null,
            'best_path' => $tree['best_path'] ?? null,
            'note' => $note,
        ];
        $env['envelope_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::ENVELOPE_SCHEMA,
            'started_at' => $startedAt,
            'verdict' => $verdict,
            'provider' => $provider,
            'input_hash' => $env['input_hash'],
            'tree_hash' => $env['tree_hash'],
        ], JSON_THROW_ON_ERROR));

        return $env;
    }

    /**
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>
     */
    private function persist(array $envelope): array
    {
        AppendOnlyJsonlStore::append($this->logPath(), $envelope);

        return $envelope;
    }
}
