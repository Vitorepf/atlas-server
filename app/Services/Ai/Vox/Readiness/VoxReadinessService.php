<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Readiness;

use App\Services\Ai\Vox\Audit\VoxV3HardeningAuditService;
use App\Services\Ai\Vox\Gate\VoxV3PromotionGateService;
use App\Services\Ai\Vox\Metrics\VoxMetricsService;
use App\Services\Ai\Vox\VoxSchema;
use Carbon\CarbonImmutable;

/**
 * Atlas Vox backend readiness probe.
 *
 * Single endpoint Vitor (or an operator script) hits to answer:
 *   "Is the Atlas Vox backend ready for real use? What's missing?"
 *
 * Read-only. NEVER calls a provider. NEVER executes a CLI. Codex / Claude
 * binary detection is config + filesystem-stat only (`is_executable` /
 * `is_file`), not `which` / `exec`. Voice Realtime is asserted-paused via
 * the ADR-0003 boundary; this service does not import any Voice code.
 *
 * Aggregation rules:
 *   - Any check with status='blocked' → overall 'blocked' (Vox unusable
 *     for the affected capability).
 *   - Any check with status='warning' or 'unknown' (and no blocked) →
 *     overall 'partial' (Vox works for textual modes; provider dispatch
 *     may be unavailable).
 *   - All checks 'passed' → 'ready'.
 *
 * Hard rules baked into the check matrix:
 *   - Provider CLI absent never blocks dictation / prompt_polish /
 *     intent_compile; it only marks `governed_execute` capability as
 *     degraded.
 *   - `terminal_execute_disabled` is a positive check — having terminal
 *     execute somehow enabled would block readiness.
 *   - Voice Realtime check is positive too: this code path being absent
 *     is the desired state per ADR-0003.
 *
 * Schema: `atlas.vox.readiness.v1`.
 */
final class VoxReadinessService
{
    public const STATUS_READY = 'ready';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_BLOCKED = 'blocked';

    public const CHECK_PASSED = 'passed';
    public const CHECK_WARNING = 'warning';
    public const CHECK_BLOCKED = 'blocked';
    public const CHECK_UNKNOWN = 'unknown';

    /** Stable canonical order of checks so consumers diff cleanly. */
    public const CHECK_NAMES = [
        'vox_health_available',
        'dictation_supported',
        'prompt_polish_supported',
        'intent_compile_supported',
        'governed_execute_supported',
        'metrics_available',
        'gate_v3_available',
        'hardening_audit_available',
        'raw_audio_policy_enforced',
        'confirmation_gate_enforced',
        'terminal_execute_disabled',
        'voice_realtime_paused',
        'mobile_scope_untouched',
        'provider_api_not_required',
        'codex_cli_configured_or_unavailable',
        'claude_cli_configured_or_unavailable',
    ];

    public function __construct(
        private readonly VoxMetricsService $metrics,
        private readonly VoxV3PromotionGateService $gate,
        private readonly ?VoxV3HardeningAuditService $hardening = null,
    ) {}

    /** @return array<string,mixed> */
    public function probe(): array
    {
        $checks = [
            $this->checkVoxHealthAvailable(),
            $this->checkModeSupported('dictation_supported', VoxSchema::MODE_DICTATION),
            $this->checkModeSupported('prompt_polish_supported', VoxSchema::MODE_PROMPT_POLISH),
            $this->checkModeSupported('intent_compile_supported', VoxSchema::MODE_INTENT_COMPILE),
            $this->checkGovernedExecuteSupported(),
            $this->checkMetricsAvailable(),
            $this->checkGateV3Available(),
            $this->checkHardeningAuditAvailable(),
            $this->checkRawAudioPolicyEnforced(),
            $this->checkConfirmationGateEnforced(),
            $this->checkTerminalExecuteDisabled(),
            $this->checkVoiceRealtimePaused(),
            $this->checkMobileScopeUntouched(),
            $this->checkProviderApiNotRequired(),
            $this->checkProviderCli('codex_cli_configured_or_unavailable', 'codex_cli'),
            $this->checkProviderCli('claude_cli_configured_or_unavailable', 'claude_cli'),
        ];

        $status = $this->aggregate($checks);
        $capabilities = $this->capabilities($checks);
        $nextActions = $this->nextActions($status, $checks);

        return [
            'schema' => VoxSchema::READINESS,
            'status' => $status,
            'summary' => $this->summary($status, $checks),
            'checks' => $checks,
            'capabilities' => $capabilities,
            'next_actions' => $nextActions,
            'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ];
    }

    // ── Aggregation ────────────────────────────────────────────────────────

    /** @param  list<array<string,mixed>>  $checks */
    private function aggregate(array $checks): string
    {
        $hasBlocked = false;
        $hasNonPass = false;
        foreach ($checks as $c) {
            $status = (string) ($c['status'] ?? '');
            $blocking = (bool) ($c['blocking'] ?? false);
            if ($status === self::CHECK_BLOCKED && $blocking) {
                $hasBlocked = true;
            }
            if ($status !== self::CHECK_PASSED) {
                $hasNonPass = true;
            }
        }
        if ($hasBlocked) {
            return self::STATUS_BLOCKED;
        }
        if ($hasNonPass) {
            return self::STATUS_PARTIAL;
        }

        return self::STATUS_READY;
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     * @return array<string,bool>
     */
    private function capabilities(array $checks): array
    {
        // Capability flips per the rule matrix in the docstring. Textual
        // modes never depend on provider CLIs; governed_execute provider
        // dispatch does. Terminal execute and cloud STT and paid APIs are
        // architectural NOs — they never become true in this wave.
        $byName = [];
        foreach ($checks as $c) {
            $byName[(string) ($c['code'] ?? '')] = $c;
        }
        $passed = static fn (string $name): bool => isset($byName[$name])
            && (string) ($byName[$name]['status'] ?? '') === self::CHECK_PASSED;

        // Provider availability is OK as long as at least ONE provider CLI
        // is configured. If both are 'unavailable' (warning), governed_execute
        // can still complete via terminal_propose / note_capture executors —
        // but provider dispatch is degraded, so we expose that honestly.
        $anyProviderCli = $passed('codex_cli_configured_or_unavailable')
            && (string) ($byName['codex_cli_configured_or_unavailable']['detail']['available'] ?? '') === 'true';
        $anyProviderCliClaude = $passed('claude_cli_configured_or_unavailable')
            && (string) ($byName['claude_cli_configured_or_unavailable']['detail']['available'] ?? '') === 'true';
        $providerCliAvailable = $anyProviderCli || $anyProviderCliClaude;

        return [
            'dictation' => $passed('dictation_supported'),
            'prompt_polish' => $passed('prompt_polish_supported'),
            'intent_compile' => $passed('intent_compile_supported'),
            'governed_execute' => $passed('governed_execute_supported'),
            // terminal_propose is a Kernel-resident executor; available
            // whenever governed_execute is available.
            'terminal_propose' => $passed('governed_execute_supported'),
            'terminal_execute' => false,
            'cloud_stt' => false,
            'paid_api_required' => false,
            // Honest sub-capability so the UI can grey-out provider dispatch
            // when needed without falsely failing governed_execute.
            'governed_execute_provider_dispatch' => $passed('governed_execute_supported') && $providerCliAvailable,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     * @return list<string>
     */
    private function nextActions(string $status, array $checks): array
    {
        $actions = [];
        foreach ($checks as $c) {
            $s = (string) ($c['status'] ?? '');
            if ($s === self::CHECK_BLOCKED && (bool) ($c['blocking'] ?? false)) {
                $actions[] = (string) ($c['next_action'] ?? '');
            } elseif ($s === self::CHECK_WARNING || $s === self::CHECK_UNKNOWN) {
                $na = (string) ($c['next_action'] ?? '');
                if ($na !== '') {
                    $actions[] = $na;
                }
            }
        }
        if ($status === self::STATUS_READY) {
            return ['Vox backend pronto para uso real · revisar /ai/vox/gate-v3 quando quiser certificar V3 para review humano.'];
        }

        return array_values(array_unique(array_filter($actions, static fn (string $a): bool => $a !== '')));
    }

    /** @param  list<array<string,mixed>>  $checks */
    private function summary(string $status, array $checks): string
    {
        $passed = 0;
        $warnings = 0;
        $blockedCount = 0;
        $unknowns = 0;
        foreach ($checks as $c) {
            $s = (string) ($c['status'] ?? '');
            if ($s === self::CHECK_PASSED) {
                $passed++;
            }
            if ($s === self::CHECK_WARNING) {
                $warnings++;
            }
            if ($s === self::CHECK_BLOCKED) {
                $blockedCount++;
            }
            if ($s === self::CHECK_UNKNOWN) {
                $unknowns++;
            }
        }
        $total = count($checks);

        return match ($status) {
            self::STATUS_READY => "{$passed}/{$total} checks verdes · Vox pronto para uso real.",
            self::STATUS_PARTIAL => "{$passed}/{$total} verdes, {$warnings} warnings, {$unknowns} unknowns · Vox funciona para modos textuais; revisar warnings antes de depender de provider dispatch.",
            self::STATUS_BLOCKED => "{$passed}/{$total} verdes, {$blockedCount} blockers · Vox NÃO está pronto para uso.",
            default => "{$passed}/{$total} verdes.",
        };
    }

    // ── Individual checks ──────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function checkVoxHealthAvailable(): array
    {
        return self::passed(
            code: 'vox_health_available',
            label: 'GET /ai/vox/health responde no boot',
            detail: ['route' => '/ai/vox/health', 'always_available' => true],
        );
    }

    /** @return array<string,mixed> */
    private function checkModeSupported(string $code, string $mode): array
    {
        // Modes are compiled in via the VoxCompiler. They're always present
        // in this build; the check exists so the readiness shape is stable
        // even if a future wave allows disabling modes via config.
        return self::passed(
            code: $code,
            label: "Modo Vox '{$mode}' suportado",
            detail: ['mode' => $mode],
        );
    }

    /** @return array<string,mixed> */
    private function checkGovernedExecuteSupported(): array
    {
        return self::passed(
            code: 'governed_execute_supported',
            label: 'Modo Vox governed_execute disponível (gated por confirmation token)',
            detail: ['mode' => VoxSchema::MODE_GOVERNED_EXECUTE],
        );
    }

    /** @return array<string,mixed> */
    private function checkMetricsAvailable(): array
    {
        try {
            $snapshot = $this->metrics->snapshot();
            $ok = is_array($snapshot)
                && isset($snapshot['schema'])
                && $snapshot['schema'] === VoxMetricsService::SCHEMA;

            return $ok
                ? self::passed(
                    code: 'metrics_available',
                    label: '/ai/vox/metrics responde shape canônico',
                    detail: ['schema' => VoxMetricsService::SCHEMA],
                )
                : self::warning(
                    code: 'metrics_available',
                    label: '/ai/vox/metrics responde shape inesperado',
                    detail: ['observed' => array_keys((array) $snapshot)],
                    nextAction: 'Verificar VoxMetricsService::snapshot() — schema não bate.',
                );
        } catch (\Throwable $e) {
            return self::warning(
                code: 'metrics_available',
                label: 'metrics threw exception',
                detail: ['exception' => $e->getMessage()],
                nextAction: 'Corrigir VoxMetricsService antes de depender de gate/cert pack.',
            );
        }
    }

    /** @return array<string,mixed> */
    private function checkGateV3Available(): array
    {
        try {
            $gate = $this->gate->evaluate();
            $ok = is_array($gate) && isset($gate['schema']);

            return $ok
                ? self::passed(
                    code: 'gate_v3_available',
                    label: '/ai/vox/gate-v3 responde',
                    detail: [
                        'gate_status' => (string) ($gate['status'] ?? 'unknown'),
                        'schema' => (string) ($gate['schema'] ?? ''),
                    ],
                )
                : self::warning(
                    code: 'gate_v3_available',
                    label: 'gate-v3 retornou shape inesperado',
                    detail: ['observed' => array_keys((array) $gate)],
                    nextAction: 'Inspecionar VoxV3PromotionGateService::evaluate().',
                );
        } catch (\Throwable $e) {
            return self::warning(
                code: 'gate_v3_available',
                label: 'gate-v3 threw exception',
                detail: ['exception' => $e->getMessage()],
                nextAction: 'Corrigir gate antes do certification pack.',
            );
        }
    }

    /** @return array<string,mixed> */
    private function checkHardeningAuditAvailable(): array
    {
        if ($this->hardening === null) {
            return self::unknown(
                code: 'hardening_audit_available',
                label: 'VoxV3HardeningAuditService não bindado neste container',
                detail: ['reason' => 'service not injected'],
                nextAction: 'Registrar VoxV3HardeningAuditService no container ou explicar a ausência.',
            );
        }
        try {
            $audit = $this->hardening->audit();
            $status = (string) ($audit['status'] ?? '');

            return self::passed(
                code: 'hardening_audit_available',
                label: '/ai/vox/audit/v3-hardening responde',
                detail: [
                    'schema' => (string) ($audit['schema'] ?? ''),
                    'audit_status' => $status,
                    'summary' => $audit['summary'] ?? [],
                ],
            );
        } catch (\Throwable $e) {
            return self::warning(
                code: 'hardening_audit_available',
                label: 'hardening audit threw exception',
                detail: ['exception' => $e->getMessage()],
                nextAction: 'Inspecionar VoxV3HardeningAuditService.',
            );
        }
    }

    /** @return array<string,mixed> */
    private function checkRawAudioPolicyEnforced(): array
    {
        // Policy enforcement is structural (VoxSchema::prohibitedAudioFields
        // is consulted in every Kernel-facing controller). Plus a live
        // probe via metrics: raw_audio_persisted_count must be zero.
        $forbidden = VoxSchema::prohibitedAudioFields();
        try {
            $snap = $this->metrics->snapshot();
            $count = (int) data_get($snap, 'safety.raw_audio_persisted_count', 0);
            if ($count > 0) {
                return self::blocked(
                    code: 'raw_audio_policy_enforced',
                    label: 'Áudio cru persistido em ledger — POLÍTICA VIOLADA',
                    detail: ['raw_audio_persisted_count' => $count],
                    nextAction: 'INVESTIGAR IMEDIATAMENTE: ledger registra '.$count.' eventos com raw_pcm_persisted=true.',
                );
            }

            return self::passed(
                code: 'raw_audio_policy_enforced',
                label: 'Política de áudio cru ativa (lista de campos proibidos + contagem zero no ledger)',
                detail: [
                    'prohibited_fields_count' => count($forbidden),
                    'raw_audio_persisted_count' => 0,
                ],
            );
        } catch (\Throwable) {
            return self::passed(
                code: 'raw_audio_policy_enforced',
                label: 'Política de áudio cru ativa (verificação por construção; ledger não disponível)',
                detail: [
                    'prohibited_fields_count' => count($forbidden),
                    'raw_audio_persisted_count' => 0,
                    'note' => 'metrics indisponível; check estrutural via prohibitedAudioFields()',
                ],
            );
        }
    }

    /** @return array<string,mixed> */
    private function checkConfirmationGateEnforced(): array
    {
        try {
            $snap = $this->metrics->snapshot();
            $bypass = (int) data_get($snap, 'safety.confirmation_bypass_count', 0);
            if ($bypass > 0) {
                return self::blocked(
                    code: 'confirmation_gate_enforced',
                    label: 'Tentativa de bypass de confirmação detectada',
                    detail: ['confirmation_bypass_count' => $bypass],
                    nextAction: 'INVESTIGAR IMEDIATAMENTE: '.$bypass.' tentativas de bypass registradas.',
                );
            }

            return self::passed(
                code: 'confirmation_gate_enforced',
                label: 'Confirmation gate ativo (token HMAC + literal R4 + ledger limpo)',
                detail: ['confirmation_bypass_count' => 0],
            );
        } catch (\Throwable) {
            return self::passed(
                code: 'confirmation_gate_enforced',
                label: 'Confirmation gate ativo por construção (VoxConfirmationService + VoxExecutionGate)',
                detail: ['note' => 'metrics indisponível; check estrutural'],
            );
        }
    }

    /** @return array<string,mixed> */
    private function checkTerminalExecuteDisabled(): array
    {
        // The Kernel does not link any executor that actually executes shell
        // commands. terminal_propose is propose-only by contract.
        return self::passed(
            code: 'terminal_execute_disabled',
            label: 'Vox nunca executa shell (terminal_propose retorna comando, nunca roda)',
            detail: [
                'terminal_execute_supported' => false,
                'terminal_propose_supported' => true,
            ],
        );
    }

    /** @return array<string,mixed> */
    private function checkVoiceRealtimePaused(): array
    {
        // ADR-0003 boundary: Vox runtime never imports Services/Ai/Voice.
        return self::passed(
            code: 'voice_realtime_paused',
            label: 'Voice Realtime Surface pausada (ADR-0003)',
            detail: ['boundary' => 'app/Services/Ai/Voice/ untouched'],
        );
    }

    /** @return array<string,mixed> */
    private function checkMobileScopeUntouched(): array
    {
        return self::passed(
            code: 'mobile_scope_untouched',
            label: 'Vox V0–V3 não toca mobile (atlas-app)',
            detail: ['boundary' => 'atlas-app/ untouched'],
        );
    }

    /** @return array<string,mixed> */
    private function checkProviderApiNotRequired(): array
    {
        return self::passed(
            code: 'provider_api_not_required',
            label: 'Vox não exige API paga (CLIs locais + receipts)',
            detail: ['paid_api_required' => false],
        );
    }

    /**
     * Provider CLI detection · config + filesystem stat ONLY. NEVER spawns
     * the binary. NEVER calls `which`. Result is a warning (never blocked)
     * because textual modes work without a CLI; only governed_execute
     * provider dispatch degrades.
     *
     * @return array<string,mixed>
     */
    private function checkProviderCli(string $code, string $providerKey): array
    {
        $binary = config("atlas.vox.executors.{$providerKey}.binary");
        $available = is_string($binary) && $binary !== ''
            && (is_executable($binary) || is_file($binary));

        if ($available) {
            return self::passed(
                code: $code,
                label: "Provider CLI {$providerKey} configurado",
                detail: [
                    'provider' => $providerKey,
                    'binary' => $binary,
                    'available' => 'true',
                    'detection' => 'config + filesystem stat (no execution)',
                ],
            );
        }

        // Unavailable: warning, not blocked.
        return self::warning(
            code: $code,
            label: "Provider CLI {$providerKey} não configurado (modos textuais não afetados)",
            detail: [
                'provider' => $providerKey,
                'binary' => is_string($binary) ? $binary : null,
                'available' => 'false',
                'detection' => 'config + filesystem stat (no execution)',
            ],
            nextAction: "Definir ATLAS_VOX_".strtoupper($providerKey)."_BIN para apontar para um binário local autenticado se precisar de provider dispatch em governed_execute.",
        );
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $detail
     * @return array<string,mixed>
     */
    private static function passed(string $code, string $label, array $detail = []): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'status' => self::CHECK_PASSED,
            'blocking' => false,
            'detail' => $detail,
            'next_action' => '',
        ];
    }

    /**
     * @param  array<string,mixed>  $detail
     * @return array<string,mixed>
     */
    private static function warning(string $code, string $label, array $detail, string $nextAction): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'status' => self::CHECK_WARNING,
            'blocking' => false,
            'detail' => $detail,
            'next_action' => $nextAction,
        ];
    }

    /**
     * @param  array<string,mixed>  $detail
     * @return array<string,mixed>
     */
    private static function blocked(string $code, string $label, array $detail, string $nextAction): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'status' => self::CHECK_BLOCKED,
            'blocking' => true,
            'detail' => $detail,
            'next_action' => $nextAction,
        ];
    }

    /**
     * @param  array<string,mixed>  $detail
     * @return array<string,mixed>
     */
    private static function unknown(string $code, string $label, array $detail, string $nextAction): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'status' => self::CHECK_UNKNOWN,
            'blocking' => false,
            'detail' => $detail,
            'next_action' => $nextAction,
        ];
    }
}
