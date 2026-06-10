<?php

namespace App\Services\Ai\Voice;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Collection;
use App\Services\Ai\Support\DatabaseTableAvailability;

final class AtlasVoiceRivalsRunner
{
    public const SCHEMA_VERSION = 'atlas.voice.rivals.v1';

    public function __construct(
        private readonly AtlasVoiceRealtimeService $voice,
        private readonly AtlasVoiceRuntimeCertificationService $certification,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function report(array $payload = []): array
    {
        $hours = max(1, min(8760, (int) ($payload['hours'] ?? 24)));
        $readiness = $this->voice->readiness(['hours' => $hours]);
        $certification = $this->runtimeCertificationSummary($payload);
        $since = now()->subHours($hours);
        $until = now();

        if (! DatabaseTableAvailability::has('atlas_ledger_events')) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'available' => false,
                'status' => 'ledger_unavailable',
                'hours' => $hours,
                'window' => ['since' => $since->toJSON(), 'until' => $until->toJSON()],
                'readiness' => $readiness,
                'runtime_certification' => $certification,
                'production_promotion_gate' => $certification['production_promotion_gate'] ?? [],
                'review_signal' => [
                    'status' => 'blocked',
                    'severity' => 'high',
                    'recommended_action' => 'run_ledger_migrations_before_rivals_voice',
                ],
            ];
        }

        $events = AtlasLedgerEvent::query()
            ->where('occurred_at', '>=', $since)
            ->whereIn('event_type', $this->voiceEventTypes())
            ->get();
        $sloEvents = AtlasLedgerEvent::query()
            ->where('occurred_at', '>=', $since)
            ->where('event_type', LedgerEventType::SloObserved->value)
            ->get()
            ->filter(fn (AtlasLedgerEvent $event): bool => str_starts_with((string) data_get($event->payload, 'stage'), 'voice.'));

        $atlasArm = $this->armReport($events, $sloEvents, 'atlas_voice');
        $baselineArm = $this->armReport($events, $sloEvents, 'direct_provider_baseline');
        $comparable = min((int) $atlasArm['completed_turn_count'], (int) $baselineArm['completed_turn_count']);
        $runtimeCertified = ($certification['status'] ?? null) === 'certified_scaffold';
        $productionPromotionGate = (array) ($certification['production_promotion_gate'] ?? []);
        $productionPromotionReviewReady = ($productionPromotionGate['status'] ?? null) === 'review_required';
        $ready = ($readiness['status'] ?? null) === 'ready'
            && $runtimeCertified
            && $productionPromotionReviewReady
            && $comparable >= 3;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'available' => true,
            'status' => $ready ? 'ready' : 'not_ready',
            'hours' => $hours,
            'window' => ['since' => $since->toJSON(), 'until' => $until->toJSON()],
            'readiness' => [
                'status' => $readiness['status'] ?? 'unknown',
                'score' => $readiness['score'] ?? null,
                'missing_events' => $readiness['missing_events'] ?? [],
            ],
            'runtime_certification' => $certification,
            'production_promotion_gate' => $productionPromotionGate,
            'arms' => [
                'atlas_voice' => $atlasArm,
                'direct_provider_baseline' => $baselineArm,
            ],
            'comparison' => [
                'comparable_turn_count' => $comparable,
                'minimum_required' => 3,
                'voice_multiplier_score' => $ready ? $this->multiplierScore($atlasArm, $baselineArm) : null,
                'winner' => $ready ? $this->winner($atlasArm, $baselineArm) : null,
            ],
            'review_signal' => $this->reviewSignal($readiness, $certification, $atlasArm, $baselineArm, $comparable),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function runtimeCertificationSummary(array $payload): array
    {
        $certification = $this->certification->certify([
            'runtime' => $payload['runtime'] ?? 'livekit_agents_sdk',
            'base_url' => $payload['base_url'] ?? config('app.url', 'http://atlas.test'),
            'require_sdk' => (bool) ($payload['require_sdk'] ?? false),
            'callback_loop_wired' => (bool) ($payload['callback_loop_wired'] ?? false),
            'production_sdk_loop_wired' => (bool) ($payload['production_sdk_loop_wired'] ?? false),
        ]);

        return [
            'schema_version' => $certification['schema_version'] ?? 'atlas.voice_realtime.runtime_certification.v1',
            'status' => $certification['status'] ?? 'unknown',
            'runtime_id' => $certification['runtime_id'] ?? 'livekit_agents_sdk',
            'kernel_only' => (bool) ($certification['kernel_only'] ?? true),
            'mobile_first' => (bool) ($certification['mobile_first'] ?? true),
            'daemon_started' => (bool) ($certification['daemon_started'] ?? false),
            'sdk_required_for_certification' => (bool) ($certification['sdk_required_for_certification'] ?? false),
            'summary' => $certification['summary'] ?? [
                'gate_count' => 0,
                'passed_gates' => 0,
                'failed_gates' => 1,
                'failed_keys' => ['runtime_certification_unavailable'],
            ],
            'artifact_sanitization' => $certification['gates']['certification_artifacts_sanitized'] ?? [
                'passed' => false,
                'forbidden_key_count' => 1,
                'forbidden_keys' => ['runtime_certification_unavailable'],
            ],
            'product_loop_check' => $certification['artifacts']['product_loop_check'] ?? [
                'schema_version' => 'atlas.voice_realtime.product_loop_check.v1',
                'status' => 'unavailable',
                'daemon_started' => false,
                'next_action' => 'run_voice_product_loop_check',
            ],
            'product_loop_gate' => $certification['gates']['product_loop_check_available'] ?? [
                'passed' => false,
                'schema_version' => 'atlas.voice_realtime.product_loop_check.v1',
                'daemon_started' => false,
                'reason' => 'product_loop_check_unavailable',
            ],
            'production_promotion_gate' => $certification['production_promotion_gate'] ?? [
                'schema_version' => 'atlas.voice_realtime.production_promotion_gate.v1',
                'status' => 'blocked',
                'human_review_required' => true,
                'promotion_allowed' => false,
                'auto_promotion_allowed' => false,
                'decision_receipt_required' => true,
                'rollback_plan_required' => true,
                'review_packet' => [
                    'schema_version' => 'atlas.voice_realtime.production_promotion_review_packet.v1',
                    'status' => 'blocked_until_machine_gates_pass',
                    'required_human_decision' => 'approve_or_reject_voice_production_promotion',
                    'required_decision_receipt' => true,
                    'required_rollback_plan' => ['disable_livekit_token_issuer'],
                    'required_evidence' => ['runtime_certification'],
                    'forbidden_actions' => ['auto_promote_voice_runtime'],
                ],
                'summary' => [
                    'failed_keys' => ['runtime_certification_unavailable'],
                ],
                'next_action' => 'fix_failed_runtime_certification_gates',
            ],
            'next_action' => $certification['next_action'] ?? 'fix_failed_runtime_certification_gates',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function voiceEventTypes(): array
    {
        return collect(LedgerEventType::cases())
            ->map(fn (LedgerEventType $type): string => $type->value)
            ->filter(fn (string $type): bool => str_starts_with($type, 'VOICE_'))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,AtlasLedgerEvent>  $events
     * @param  Collection<int,AtlasLedgerEvent>  $sloEvents
     * @return array<string,mixed>
     */
    private function armReport(Collection $events, Collection $sloEvents, string $arm): array
    {
        $armEvents = $events->filter(fn (AtlasLedgerEvent $event): bool => $this->eventArm($event) === $arm);
        $armSloEvents = $sloEvents->filter(fn (AtlasLedgerEvent $event): bool => $this->eventArm($event) === $arm);
        $latencies = $armSloEvents
            ->filter(fn (AtlasLedgerEvent $event): bool => (string) data_get($event->payload, 'stage') === 'voice.turn_to_first_audio')
            ->map(fn (AtlasLedgerEvent $event): int => (int) data_get($event->payload, 'slo.duration_ms', 0))
            ->filter(fn (int $value): bool => $value > 0)
            ->values();

        return [
            'arm' => $arm,
            'session_count' => $armEvents->where('event_type', LedgerEventType::VoiceSessionStarted->value)->pluck('correlation_id')->filter()->unique()->count(),
            'turn_count' => $armEvents->map(fn (AtlasLedgerEvent $event): ?string => data_get($event->payload, 'voice.turn_id'))->filter()->unique()->count(),
            'completed_turn_count' => $armEvents->where('event_type', LedgerEventType::VoiceTurnPlayed->value)->count(),
            'interruption_count' => $armEvents->where('event_type', LedgerEventType::VoiceTurnInterrupted->value)->count(),
            'failure_count' => $armEvents->where('event_type', LedgerEventType::VoiceRuntimeFailed->value)->count(),
            'slo_observation_count' => $armSloEvents->count(),
            'slo_breach_count' => $armSloEvents->filter(fn (AtlasLedgerEvent $event): bool => (string) data_get($event->payload, 'status') !== 'ok')->count(),
            'turn_to_first_audio_avg_ms' => $latencies->isNotEmpty() ? round($latencies->avg(), 1) : null,
        ];
    }

    private function eventArm(AtlasLedgerEvent $event): string
    {
        $arm = (string) (data_get($event->payload, 'voice.rivals_arm') ?: data_get($event->payload, 'dimensions.rivals_arm') ?: 'atlas_voice');

        return $arm === 'direct_provider_baseline' ? 'direct_provider_baseline' : 'atlas_voice';
    }

    /**
     * @param  array<string,mixed>  $atlas
     * @param  array<string,mixed>  $baseline
     */
    private function multiplierScore(array $atlas, array $baseline): float
    {
        $baselineLatency = max(1, (float) ($baseline['turn_to_first_audio_avg_ms'] ?? 1));
        $atlasLatency = max(1, (float) ($atlas['turn_to_first_audio_avg_ms'] ?? $baselineLatency));
        $latencyScore = min(2.0, $baselineLatency / $atlasLatency);
        $failurePenalty = ((int) $atlas['failure_count'] + (int) $atlas['slo_breach_count']) * 0.1;

        return round(max(0.0, $latencyScore - $failurePenalty), 2);
    }

    /**
     * @param  array<string,mixed>  $atlas
     * @param  array<string,mixed>  $baseline
     */
    private function winner(array $atlas, array $baseline): string
    {
        $atlasScore = $this->multiplierScore($atlas, $baseline);

        return $atlasScore >= 1.0 ? 'atlas_voice' : 'direct_provider_baseline';
    }

    /**
     * @param  array<string,mixed>  $readiness
     * @param  array<string,mixed>  $atlas
     * @param  array<string,mixed>  $baseline
     * @return array<string,mixed>
     */
    private function reviewSignal(array $readiness, array $certification, array $atlas, array $baseline, int $comparable): array
    {
        if (($readiness['status'] ?? null) !== 'ready') {
            return [
                'status' => 'blocked',
                'severity' => 'medium',
                'recommended_action' => 'complete_voice_readiness_before_rivals_voice',
                'reasons' => ['voice_readiness_not_ready'],
            ];
        }

        if (($certification['status'] ?? null) !== 'certified_scaffold') {
            return [
                'status' => 'blocked',
                'severity' => 'high',
                'recommended_action' => 'fix_voice_runtime_certification_before_rivals_voice',
                'reasons' => ['voice_runtime_not_certified'],
                'failed_certification_gates' => data_get($certification, 'summary.failed_keys', []),
            ];
        }

        $productionPromotionGate = (array) ($certification['production_promotion_gate'] ?? []);
        $promotionGateStatus = (string) ($productionPromotionGate['status'] ?? 'blocked');
        if ($promotionGateStatus === 'blocked') {
            return [
                'status' => 'blocked',
                'severity' => 'high',
                'recommended_action' => (string) ($productionPromotionGate['next_action'] ?? 'fix_voice_production_promotion_gate'),
                'reasons' => ['voice_production_promotion_gate_blocked'],
                'failed_production_promotion_gates' => data_get($productionPromotionGate, 'summary.failed_keys', []),
                'human_review_required' => (bool) ($productionPromotionGate['human_review_required'] ?? true),
                'promotion_allowed' => (bool) ($productionPromotionGate['promotion_allowed'] ?? false),
                'auto_promotion_allowed' => (bool) ($productionPromotionGate['auto_promotion_allowed'] ?? false),
                'review_packet' => $productionPromotionGate['review_packet'] ?? null,
            ];
        }

        if ((int) $baseline['completed_turn_count'] === 0) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'recommended_action' => 'collect_direct_provider_baseline_turns',
                'reasons' => ['direct_provider_baseline_missing'],
            ];
        }

        if ($comparable < 3) {
            return [
                'status' => 'warning',
                'severity' => 'low',
                'recommended_action' => 'collect_more_comparable_voice_turns',
                'reasons' => ['comparable_turn_count_below_minimum'],
            ];
        }

        return [
            'status' => $promotionGateStatus === 'review_required' ? 'review_required' : 'ok',
            'severity' => $promotionGateStatus === 'review_required' ? 'high' : 'none',
            'recommended_action' => $promotionGateStatus === 'review_required'
                ? 'submit_voice_production_promotion_for_human_review'
                : 'use_rivals_voice_signal_for_voice_surface_maturity',
            'reasons' => $promotionGateStatus === 'review_required'
                ? ['voice_production_promotion_requires_human_review']
                : ['rivals_voice_comparable'],
            'atlas_completed_turns' => $atlas['completed_turn_count'],
            'human_review_required' => (bool) ($productionPromotionGate['human_review_required'] ?? false),
            'promotion_allowed' => (bool) ($productionPromotionGate['promotion_allowed'] ?? false),
            'auto_promotion_allowed' => (bool) ($productionPromotionGate['auto_promotion_allowed'] ?? false),
            'review_packet' => $productionPromotionGate['review_packet'] ?? null,
        ];
    }
}
