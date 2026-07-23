<?php

namespace App\Services\Ai\Voice\VoiceRealtime;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\Voice\AtlasVoiceRealtimeService;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Leaf helpers for {@see AtlasVoiceRealtimeService}. Pure/stateless: no injected services,
 * depends only on the facade's public constants (GOD-DEBULK split).
 */
final class VoiceRealtimeSupport
{
    public function looksLikeSttGhostTranscript(string $transcript): bool
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $transcript) ?? $transcript));
        $stripped = trim($normalized, " \t\n\r\0\x0B.!?;,");
        $ascii = $this->asciiFold($stripped);

        if ($stripped === '') {
            return true;
        }

        $commonGhosts = [
            'www.tinyurl.com.br',
            'tinyurl.com.br',
            'www tinyurl com br',
            'tinyurl com br',
            'acesse o site www.tinyurl.com.br para mais informações',
            'acesse o site tinyurl.com.br para mais informações',
            'acesse o site www.tinyurl.com.br para mais informacoes',
            'acesse o site tinyurl.com.br para mais informacoes',
        ];

        if (in_array($stripped, $commonGhosts, true) || in_array($ascii, $commonGhosts, true)) {
            return true;
        }

        $wordCount = str_word_count(str_replace(['://', '/', '.', '-'], ' ', $stripped), 0, 'áàâãéêíóôõúç');
        $isUrlOnly = preg_match('/^(?:acesse\s+(?:o\s+site\s+)?)?(?:https?:\/\/)?(?:www\.)?[a-z0-9][a-z0-9.-]*\.[a-z]{2,}(?:\/\S*)?(?:\s+para\s+mais\s+informações)?$/u', $stripped) === 1;
        $isAsciiUrlOnly = preg_match('/^(?:acesse\s+(?:o\s+site\s+)?)?(?:https?:\/\/)?(?:www\.)?[a-z0-9][a-z0-9.-]*\.[a-z]{2,}(?:\/\S*)?(?:\s+para\s+mais\s+informacoes)?$/u', $ascii) === 1;
        $isGenericMarketingUrlGhost = preg_match('/^acesse\s+(?:o\s+site\s+)?(?:https?:\/\/)?(?:www\.)?[a-z0-9][a-z0-9.-]*\.[a-z]{2,}(?:\/\S*)?\s+para\s+mais\s+informacoes$/u', $ascii) === 1;

        return $isGenericMarketingUrlGhost || (($isUrlOnly || $isAsciiUrlOnly) && $wordCount <= 8);
    }

    public function asciiFold(string $value): string
    {
        return strtr($value, [
            'á' => 'a',
            'à' => 'a',
            'â' => 'a',
            'ã' => 'a',
            'ä' => 'a',
            'é' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'í' => 'i',
            'ï' => 'i',
            'ó' => 'o',
            'ô' => 'o',
            'õ' => 'o',
            'ö' => 'o',
            'ú' => 'u',
            'ü' => 'u',
            'ç' => 'c',
        ]);
    }

    /**
     * @param  array<string,mixed>  $dependencies
     * @return array<string,mixed>
     */
    public function runtimeDependencySummary(array $dependencies): array
    {
        return [
            'schema_version' => 'atlas.voice_realtime.runtime_dependency_summary.v1',
            'status' => $dependencies['status'] ?? 'unknown',
            'python_runtime_status' => data_get($dependencies, 'python_runtime.status'),
            'configured_python_binary' => data_get($dependencies, 'python_runtime.configured_binary'),
            'configured_python_version' => data_get($dependencies, 'python_runtime.configured_version'),
            'python_minimum_version' => data_get($dependencies, 'python_runtime.minimum_version'),
            'python_satisfies_minimum' => data_get($dependencies, 'python_runtime.configured_satisfies_minimum'),
            'operator_managed' => data_get($dependencies, 'python_runtime.operator_managed'),
            'auto_install_allowed' => data_get($dependencies, 'python_runtime.auto_install_allowed'),
            'next_action' => data_get($dependencies, 'python_runtime.next_action', $dependencies['next_action'] ?? null),
        ];
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    public function voicePythonRuntimePlan(array $manifest): array
    {
        $minimum = (string) data_get($manifest, 'python.minimum_version', '3.10');
        $recommended = (string) data_get($manifest, 'python.recommended_version', '3.11');
        $configured = $this->configuredVoicePythonBinary();
        $candidates = collect([
            $configured,
            'python3.13',
            'python3.12',
            'python3.11',
            'python3.10',
            'python3',
            '/opt/homebrew/bin/python3.13',
            '/opt/homebrew/bin/python3.12',
            '/opt/homebrew/bin/python3.11',
            '/opt/homebrew/bin/python3.10',
            '/usr/local/bin/python3.13',
            '/usr/local/bin/python3.12',
            '/usr/local/bin/python3.11',
            '/usr/local/bin/python3.10',
            '/usr/bin/python3',
        ])
            ->filter(fn (string $binary): bool => $binary !== '')
            ->unique()
            ->map(fn (string $binary): array => $this->inspectPythonBinary($binary, $minimum))
            ->values()
            ->all();
        $configuredReport = collect($candidates)
            ->first(fn (array $candidate): bool => $candidate['binary'] === $configured);
        $readyCandidate = collect($candidates)
            ->first(fn (array $candidate): bool => (bool) ($candidate['satisfies_minimum'] ?? false));

        return [
            'schema_version' => 'atlas.voice_realtime.python_runtime_plan.v1',
            'status' => (bool) data_get($configuredReport, 'satisfies_minimum')
                ? 'ready'
                : 'blocked',
            'binary_config' => data_get($manifest, 'python.binary_config', 'ATLAS_VOICE_PYTHON_BIN or config atlas_ai.voice_realtime.python_binary'),
            'configured_binary' => $configured,
            'configured_available' => (bool) data_get($configuredReport, 'available'),
            'configured_version' => data_get($configuredReport, 'version'),
            'minimum_version' => $minimum,
            'recommended_version' => $recommended,
            'configured_satisfies_minimum' => (bool) data_get($configuredReport, 'satisfies_minimum'),
            'best_available_binary' => data_get($readyCandidate, 'binary'),
            'best_available_version' => data_get($readyCandidate, 'version'),
            'candidates' => $candidates,
            'operator_managed' => true,
            'auto_install_allowed' => false,
            'configuration_examples' => [
                'env' => 'export ATLAS_VOICE_PYTHON_BIN=/opt/homebrew/bin/python3.11',
                'cli_override' => 'php artisan atlas:ai:voice sdk-check --python-bin=/opt/homebrew/bin/python3.11 --json',
                'install_hint_macos' => 'brew install python@3.11',
            ],
            'next_action' => (bool) data_get($configuredReport, 'satisfies_minimum')
                ? 'run_voice_sdk_check'
                : ((bool) data_get($readyCandidate, 'available')
                    ? 'set_ATLAS_VOICE_PYTHON_BIN_to_best_available_binary'
                    : 'install_python_3_11_then_set_ATLAS_VOICE_PYTHON_BIN'),
        ];
    }

    public function configuredVoicePythonBinary(): string
    {
        $configured = trim((string) config('atlas_ai.voice_realtime.python_binary', 'python3'));

        if ($configured === '' || str_contains($configured, "\0") || str_contains($configured, "\n") || str_contains($configured, "\r")) {
            return 'python3';
        }

        return $configured;
    }

    /**
     * @return array<string,mixed>
     */
    public function inspectPythonBinary(string $binary, string $minimum): array
    {
        $process = new Process([$binary, '--version'], base_path());
        $process->setTimeout(5);
        $process->run();

        $versionOutput = trim($process->getOutput().' '.$process->getErrorOutput());
        preg_match('/Python\s+([0-9]+(?:\.[0-9]+){1,2})/', $versionOutput, $matches);
        $version = $matches[1] ?? null;

        return [
            'binary' => $binary,
            'available' => $process->isSuccessful() && $version !== null,
            'version' => $version,
            'satisfies_minimum' => $version !== null && version_compare($version, $minimum, '>='),
            'exit_code' => $process->getExitCode(),
        ];
    }

    public function requirementsFilePath(string $requirementsRef): ?string
    {
        if ($requirementsRef === '') {
            return null;
        }

        return str_starts_with($requirementsRef, '/')
            ? $requirementsRef
            : base_path($requirementsRef);
    }

    /**
     * @return array<int,string>
     */
    public function requirementsLines(?string $requirementsPath): array
    {
        if ($requirementsPath === null || ! is_file($requirementsPath)) {
            return [];
        }

        return collect(explode("\n", (string) file_get_contents($requirementsPath)))
            ->map(fn (string $line): string => trim($line))
            ->filter(fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'))
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $package
     */
    public function expectedRequirement(array $package): string
    {
        $pip = trim((string) ($package['pip'] ?? ''));
        if ($pip === '') {
            return '';
        }

        return $pip.trim((string) ($package['version_specifier'] ?? ''));
    }

    public function unsafeRequirementLine(string $line): bool
    {
        return str_starts_with($line, '-')
            || str_contains($line, '://')
            || str_starts_with($line, 'git+')
            || str_contains($line, ';');
    }

    /**
     * @param  array<string,mixed>|null  $payload
     * @return array<string,mixed>|null
     */
    public function sanitizeRuntimeSmokePayload(?array $payload): ?array
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
            ->map(fn (mixed $value): mixed => is_array($value) ? $this->sanitizeRuntimeSmokePayload($value) : $value)
            ->all();
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function baseSession(array $payload): array
    {
        return [
            'session_id' => $this->string($payload['session_id'] ?? (string) Str::ulid(), 80),
            'surface_id' => 'voice_realtime',
            'client_surface' => $this->allowedValue($payload['client_surface'] ?? 'mobile', AtlasVoiceRealtimeService::ALLOWED_CLIENT_SURFACES, 'client_surface'),
            'transport' => $this->allowedValue($payload['transport'] ?? 'livekit_webrtc', AtlasVoiceRealtimeService::ALLOWED_TRANSPORTS, 'transport'),
            'runtime' => $this->allowedValue($payload['runtime'] ?? 'livekit_agents_sdk', AtlasVoiceRealtimeService::ALLOWED_RUNTIMES, 'runtime'),
            'rivals_arm' => $this->rivalsArm($payload['rivals_arm'] ?? null),
            'privacy_class' => $this->allowedValue($payload['privacy_class'] ?? data_get($payload, 'privacy.class', 'p3_audio'), AtlasVoiceRealtimeService::ALLOWED_PRIVACY_CLASSES, 'privacy_class'),
            'envelope_id' => $this->string($payload['envelope_id'] ?? 'voice_realtime:'.(string) Str::ulid(), 120),
            'receipt_id' => isset($payload['receipt_id']) ? $this->string($payload['receipt_id'], 120) : null,
            'operator' => [
                'tenant_id' => $this->string(data_get($payload, 'operator.tenant_id', $payload['tenant_id'] ?? 'default'), 120),
                'operator_id' => $this->string(data_get($payload, 'operator.operator_id', $payload['operator_id'] ?? 'voice_operator'), 120),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $lease
     */
    public function sessionLeaseReady(array $lease): bool
    {
        return ($lease['token_status'] ?? null) === 'issued'
            && is_string($lease['access_token'] ?? null)
            && trim((string) $lease['access_token']) !== ''
            && is_string($lease['livekit_url'] ?? null)
            && trim((string) $lease['livekit_url']) !== ''
            && is_string($lease['room_name'] ?? null)
            && trim((string) $lease['room_name']) !== ''
            && is_string($lease['participant_identity'] ?? null)
            && trim((string) $lease['participant_identity']) !== '';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function voiceRisk(array $payload): string
    {
        $domain = (string) ($payload['domain_hint'] ?? 'general');
        $privacy = (string) ($payload['privacy_class'] ?? data_get($payload, 'privacy.class', 'p3_audio'));

        if (in_array($privacy, ['p4_secret', 'secret'], true)) {
            return 'critical';
        }

        if (in_array($domain, ['finance', 'health', 'operations', 'security'], true)) {
            return 'high';
        }

        return 'medium';
    }

    public function callbackRequiresAcceptedTurn(LedgerEventType $type): bool
    {
        return in_array($type->value, AtlasVoiceRealtimeService::CALLBACKS_REQUIRING_ACCEPTED_TURN, true);
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseMobileLoopContract(): array
    {
        return [
            'schema_version' => 'atlas.voice_realtime.enterprise_mobile_loop.v1',
            'status' => 'contract_ready',
            'promotion_required_events' => [
                LedgerEventType::VoiceSessionStarted->value,
                LedgerEventType::VoiceTurnDecided->value,
                LedgerEventType::VoiceTurnSynthesized->value,
                LedgerEventType::VoiceTurnPlayed->value,
                LedgerEventType::VoiceTurnInterrupted->value,
                LedgerEventType::VoiceRuntimeFailed->value,
            ],
            'healthy_loop_events' => [
                LedgerEventType::VoiceSessionStarted->value,
                LedgerEventType::VoiceTurnDecided->value,
                LedgerEventType::VoiceTurnSynthesized->value,
                LedgerEventType::VoiceTurnPlayed->value,
            ],
            'required_drills_before_promotion' => [
                'mobile_push_to_talk_success_path',
                'barge_in_interruption_path',
                'runtime_failure_redaction_path',
                'app_background_session_end_path',
                'network_retry_without_raw_payload_path',
            ],
            'mobile_callbacks' => [
                'turn_synthesized' => '/v1/mobile/ai/voice/turn/synthesized',
                'turn_played' => '/v1/mobile/ai/voice/turn/played',
                'turn_interrupted' => '/v1/mobile/ai/voice/turn/interrupted',
                'runtime_failed' => '/v1/mobile/ai/voice/runtime/failed',
            ],
            'privacy_invariants' => [
                'raw_audio_persisted' => false,
                'raw_transcript_persisted' => false,
                'raw_response_text_persisted' => false,
                'runtime_errors_require_error_message_hash' => true,
                'hashes_normalized_lowercase' => true,
            ],
            'latency_slo_stages' => [
                'voice.wake_word_detect',
                'voice.turn_to_first_audio',
                'voice.interruption_stop_audio',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<int,string>
     */
    public function runtimeCallbackContractViolations(LedgerEventType $type, array $payload): array
    {
        $schemaKey = match ($type) {
            LedgerEventType::VoiceTurnSynthesized => 'tts_synthesized',
            LedgerEventType::VoiceTurnPlayed => 'audio_played',
            LedgerEventType::VoiceTurnInterrupted => 'barge_in',
            LedgerEventType::VoiceRuntimeFailed => 'runtime_failed',
            LedgerEventType::VoiceProviderHealthDegraded => 'provider_health_degraded',
            default => null,
        };

        if ($schemaKey === null) {
            return [];
        }

        $schema = AtlasVoiceRealtimeService::CALLBACK_PAYLOAD_SCHEMAS[$schemaKey] ?? [];
        $violations = [];

        foreach ((array) ($schema['required'] ?? []) as $field) {
            if (! array_key_exists($field, $payload) || trim((string) $payload[$field]) === '') {
                $violations[] = 'missing_required_field:'.$field;
            }
        }

        $prohibited = array_values(array_unique([
            ...$this->globalRuntimeCallbackProhibitedFields(),
            ...(array) ($schema['prohibited'] ?? []),
        ]));

        foreach ($this->findProhibitedRuntimeCallbackFields($payload, $prohibited) as $path) {
            $violations[] = 'prohibited_field_present:'.$path;
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    public function globalRuntimeCallbackProhibitedFields(): array
    {
        return [
            'access_token',
            'api_key',
            'api_secret',
            'audio',
            'audio_bytes',
            'audio_raw',
            'livekit_token',
            'pcm',
            'provider_api_key',
            'raw_audio',
            'raw_audio_bytes',
            'raw_response_text',
            'response_text',
            'token',
            'tool_args',
            'tool_call',
            'tts_text',
            'wav',
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<int,string>  $prohibited
     * @return array<int,string>
     */
    public function findProhibitedRuntimeCallbackFields(array $payload, array $prohibited, string $prefix = ''): array
    {
        $found = [];

        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (in_array((string) $key, $prohibited, true)) {
                $found[] = $path;
            }

            if (is_array($value)) {
                array_push($found, ...$this->findProhibitedRuntimeCallbackFields($value, $prohibited, $path));
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @param  array<string,mixed>  $session
     */
    public function hasAcceptedKernelTurn(array $session, string $turnId): bool
    {
        if (! DatabaseTableAvailability::has('atlas_ledger_events')) {
            return false;
        }

        return AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::VoiceTurnDecided->value)
            ->where('correlation_id', $session['session_id'])
            ->get()
            ->contains(fn (AtlasLedgerEvent $event): bool => data_get($event->payload, 'voice.turn_id') === $turnId);
    }

    /**
     * @param  array<string,mixed>  $session
     * @return array<string,mixed>
     */
    public function ledgerContext(array $session): array
    {
        return [
            'tenant_id' => data_get($session, 'operator.tenant_id', 'default'),
            'operator_id' => data_get($session, 'operator.operator_id', 'voice_operator'),
            'envelope_id' => $session['envelope_id'],
            'receipt_id' => $session['receipt_id'],
            'correlation_id' => $session['session_id'],
            'emitter_stage' => 'atlas.voice_realtime',
            'emitter_version' => AtlasVoiceRealtimeService::SCHEMA_VERSION,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function contract(): array
    {
        return [
            'surface_must_not_decide' => true,
            'provider_must_not_decide' => true,
            'runtime_requires_decision_receipt' => true,
            'raw_audio_persistence_allowed' => false,
            'livekit_agents_call_kernel_webhook_only' => true,
            'critical_behavior_changes_require_human_review' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function ledgerEventPayload(mixed $event): array
    {
        return [
            'recorded' => $event !== null,
            'event_id' => data_get($event, 'event_id'),
            'event_type' => data_get($event, 'event_type'),
            'emitter_stage' => data_get($event, 'emitter_stage'),
            'payload_hash' => data_get($event, 'payload_hash'),
        ];
    }

    public function string(mixed $value, int $limit): string
    {
        return Str::limit(trim((string) $value), $limit, '');
    }

    public function sha256Hex(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $hash = strtolower(trim((string) $value));

        return preg_match('/^[a-f0-9]{64}$/', $hash) === 1 ? $hash : null;
    }

    /**
     * @param  array<int,string>  $violations
     */
    public function runtimeFailureMessageHash(string $failureCode, string $eventType, array $violations = []): string
    {
        return hash('sha256', implode(':', [
            'atlas_voice_runtime_failed',
            $failureCode,
            $eventType,
            implode(',', $violations),
        ]));
    }

    /**
     * @param  array<int,string>  $allowed
     */
    public function allowedValue(mixed $value, array $allowed, string $field): string
    {
        $value = $this->string($value, 120);
        if (in_array($value, $allowed, true)) {
            return $value;
        }

        throw new \InvalidArgumentException("Invalid Atlas Voice {$field}: {$value}");
    }

    public function rivalsArm(mixed $value): string
    {
        return $value === 'direct_provider_baseline' ? 'direct_provider_baseline' : 'atlas_voice';
    }

    public function roomSlug(mixed $value): string
    {
        $slug = Str::slug((string) $value);

        return $slug !== '' ? Str::limit($slug, 80, '') : strtolower((string) Str::ulid());
    }

    public function voiceRoomName(mixed $requestedRoom, mixed $fallback): string
    {
        $slug = $this->roomSlug($requestedRoom ?: $fallback);
        $slug = str_starts_with($slug, 'atlas-voice-')
            ? substr($slug, strlen('atlas-voice-'))
            : $slug;

        return 'atlas-voice-'.Str::limit($slug, 80, '');
    }

    public function voiceParticipantIdentity(mixed $requestedIdentity, mixed $clientSurface, mixed $fallbackOperator): string
    {
        $surface = $this->roomSlug($clientSurface ?: 'mobile');
        $requested = $this->string($requestedIdentity ?? '', 120);

        if ($requested !== '' && str_starts_with($requested, $surface.':')) {
            $requested = substr($requested, strlen($surface) + 1);
        }

        $identity = $this->roomSlug($requested !== '' ? $requested : $fallbackOperator);

        return $surface.':'.Str::limit($identity, 80, '');
    }
}
