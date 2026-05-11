<?php

namespace App\Services\Ai\Voice;

final class AtlasVoiceProductionPromotionReviewBundleService
{
    public function __construct(
        private readonly AtlasVoiceRuntimeCertificationService $certification,
        private readonly AtlasVoiceRivalsRunner $rivals,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function bundle(array $payload = []): array
    {
        $runtime = (string) ($payload['runtime'] ?? 'livekit_agents_sdk');
        $baseUrl = (string) ($payload['base_url'] ?? 'http://atlas.test');
        $hours = max(1, min(8760, (int) ($payload['hours'] ?? 24)));
        $callbackLoopWired = (bool) ($payload['callback_loop_wired'] ?? false);
        $productionSdkLoopWired = (bool) ($payload['production_sdk_loop_wired'] ?? false);

        if ($runtime !== 'livekit_agents_sdk') {
            return [
                'schema_version' => 'atlas.voice_realtime.production_promotion_review_bundle.v1',
                'status' => 'invalid_runtime',
                'surface_id' => 'voice_realtime',
                'runtime_id' => $runtime,
                'allowed_runtimes' => ['livekit_agents_sdk'],
                'kernel_only' => true,
                'mobile_first' => true,
                'promotion_allowed' => false,
                'auto_promotion_allowed' => false,
                'daemon_started' => false,
                'human_review_required' => true,
                'next_action' => 'use_allowed_voice_runtime',
            ];
        }

        $certificationPayload = $this->certification->certify([
            'runtime' => $runtime,
            'base_url' => $baseUrl,
            'require_sdk' => true,
            'callback_loop_wired' => $callbackLoopWired,
            'production_sdk_loop_wired' => $productionSdkLoopWired,
        ]);
        $rivalsPayload = $this->rivals->report([
            'hours' => $hours,
            'runtime' => $runtime,
            'base_url' => $baseUrl,
            'require_sdk' => true,
            'callback_loop_wired' => $callbackLoopWired,
            'production_sdk_loop_wired' => $productionSdkLoopWired,
        ]);

        $productionGate = (array) ($certificationPayload['production_promotion_gate'] ?? []);
        $reviewPacket = (array) ($productionGate['review_packet'] ?? []);
        $productLoopCheck = (array) data_get($certificationPayload, 'artifacts.product_loop_check', []);
        $preStartHealthChecksSmoke = (array) data_get($certificationPayload, 'artifacts.pre_start_health_checks_smoke', []);
        $evidence = [
            'runtime_certification' => $this->evidenceSummary($certificationPayload, 'runtime_certification'),
            'product_loop_check' => $this->evidenceSummary($productLoopCheck, 'product_loop_check'),
            'pre_start_health_checks_smoke' => $this->evidenceSummary($preStartHealthChecksSmoke, 'pre_start_health_checks_smoke'),
            'rivals_voice_comparison' => $this->evidenceSummary($rivalsPayload, 'rivals_voice_comparison'),
        ];
        $bundleSeed = [
            'schema_version' => 'atlas.voice_realtime.production_promotion_review_bundle.v1',
            'surface_id' => 'voice_realtime',
            'runtime_id' => $runtime,
            'base_url_hash' => hash('sha256', $baseUrl),
            'callback_loop_wired' => $callbackLoopWired,
            'production_sdk_loop_wired' => $productionSdkLoopWired,
            'production_promotion_gate_status' => (string) ($productionGate['status'] ?? 'unknown'),
            'review_packet_status' => (string) ($reviewPacket['status'] ?? 'unknown'),
            'evidence_hashes' => collect($evidence)
                ->map(fn (array $item): string => $this->canonicalHash($item))
                ->all(),
        ];
        $readyForReview = ($productionGate['status'] ?? null) === 'review_required'
            && ($reviewPacket['status'] ?? null) === 'ready_for_human_review';

        return [
            'schema_version' => 'atlas.voice_realtime.production_promotion_review_bundle.v1',
            'status' => $readyForReview ? 'ready_for_human_review' : 'blocked_until_machine_gates_pass',
            'surface_id' => 'voice_realtime',
            'runtime_id' => $runtime,
            'kernel_only' => true,
            'mobile_first' => true,
            'callback_loop_wired' => $callbackLoopWired,
            'production_sdk_loop_wired' => $productionSdkLoopWired,
            'promotion_allowed' => false,
            'auto_promotion_allowed' => false,
            'daemon_started' => false,
            'human_review_required' => true,
            'decision_receipt_required' => true,
            'rollback_plan_required' => true,
            'production_promotion_gate' => [
                'schema_version' => $productionGate['schema_version'] ?? null,
                'status' => $productionGate['status'] ?? 'unknown',
                'next_action' => $productionGate['next_action'] ?? 'fix_voice_production_promotion_gates',
                'failed_keys' => data_get($productionGate, 'summary.failed_keys', []),
                'passed_gates' => data_get($productionGate, 'summary.passed_gates'),
                'failed_gates' => data_get($productionGate, 'summary.failed_gates'),
            ],
            'review_packet' => $reviewPacket,
            'evidence' => $evidence,
            'guardrails' => [
                'raw_audio_persistence_allowed' => false,
                'direct_provider_call_allowed' => false,
                'direct_tool_execution_allowed' => false,
                'memory_write_allowed' => false,
                'start_daemon_allowed' => false,
                'boolean_approval_is_sufficient' => false,
            ],
            'summary' => [
                'evidence_count' => count($evidence),
                'failed_machine_gates' => data_get($productionGate, 'summary.failed_keys', []),
                'review_ready' => $readyForReview,
            ],
            'bundle_hash' => $this->canonicalHash($bundleSeed),
            'next_action' => $readyForReview
                ? 'attach_decision_receipt_and_operator_review_file'
                : (string) ($productionGate['next_action'] ?? 'fix_voice_production_promotion_gates'),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function evidenceSummary(array $payload, string $name): array
    {
        $summary = [
            'name' => $name,
            'schema_version' => $payload['schema_version'] ?? null,
            'status' => $payload['status'] ?? 'unknown',
            'daemon_started' => $payload['daemon_started'] ?? false,
            'promotion_allowed' => $payload['promotion_allowed'] ?? data_get($payload, 'production_promotion_gate.promotion_allowed', false),
            'auto_promotion_allowed' => $payload['auto_promotion_allowed'] ?? data_get($payload, 'production_promotion_gate.auto_promotion_allowed', false),
            'next_action' => $payload['next_action'] ?? data_get($payload, 'production_promotion_gate.next_action'),
        ];

        $summary['payload_hash'] = $this->canonicalHash($this->sanitizeSmokePayload($payload) ?? []);

        return $summary;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function canonicalHash(array $payload): string
    {
        $canonicalize = function (mixed $value) use (&$canonicalize): mixed {
            if (! is_array($value)) {
                return $value;
            }

            if (! array_is_list($value)) {
                ksort($value);
            }

            $canonical = [];
            foreach ($value as $key => $item) {
                $canonical[$key] = $canonicalize($item);
            }

            return $canonical;
        };

        return hash('sha256', json_encode($canonicalize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string,mixed>|null  $payload
     * @return array<string,mixed>|null
     */
    private function sanitizeSmokePayload(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $forbidden = [
            'access_token',
            'token',
            'livekit_token',
            'api_key',
            'api_secret',
            'raw_audio',
            'audio_bytes',
            'pcm',
            'wav',
            'response_text',
            'raw_response_text',
            'tts_text',
            'tool_call',
            'tool_args',
            'provider_api_key',
        ];

        return collect($payload)
            ->reject(fn (mixed $_, string|int $key): bool => in_array((string) $key, $forbidden, true))
            ->map(fn (mixed $value): mixed => is_array($value) ? $this->sanitizeSmokePayload($value) : $value)
            ->all();
    }
}
