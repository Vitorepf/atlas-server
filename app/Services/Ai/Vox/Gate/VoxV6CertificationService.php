<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Gate;

use App\Services\Ai\Vox\Dogfood\VoxDogfoodSummaryService;
use App\Services\Ai\Vox\Gate\Checks\BackendChecks;
use App\Services\Ai\Vox\Gate\Checks\DesktopChecks;
use App\Services\Ai\Vox\Gate\Checks\SafetyUxChecks;
use App\Services\Ai\Vox\Interlocutor\VoxInterlocutorPolicy;
use App\Services\Ai\Vox\Routing\VoxAutoModeRouter;
use Carbon\CarbonImmutable;

/**
 * Atlas Vox V6 · certificação final — read-only.
 *
 * Devolve um envelope `atlas.vox.v6_certification.v1` indicando se o Atlas
 * Vox V3→V6 está pronto para dogfood real. NUNCA grava, NUNCA executa,
 * NUNCA chama provider, NUNCA destrava V7.
 *
 * V6 fecha o ciclo: ambient Mac (Option+Space) + auto mode (V4) +
 * Symbiotic Interlocutor (V5) + Reply Surface (V6) + dogfood leve. A cert
 * confirma que tudo isso continua em pé e que V7 (memória longitudinal)
 * permanece bloqueada.
 *
 * Áreas verificadas:
 *   1. Backend  — endpoints, contratos, no-raw-audio, no-paid-api.
 *   2. Desktop  — manifests `vox:release-check` + `vox:visual-smoke`,
 *                 Tauri commands V6 (settings + speak), helpers ambient.
 *   3. macOS    — Info.plist (NSMicrophoneUsageDescription),
 *                 entitlements (audio-input=true), identifier=com.atlas.code.
 *   4. UX       — VoxOverlay PT-BR, sem strings legacy visíveis,
 *                 detalhes técnicos colapsados.
 *   5. Safety   — terminal sem auto-execute, R4 bloqueia, receipt obrigatório,
 *                 helper Ambient não grava áudio nem chama provider.
 *
 * Aggregação:
 *   - qualquer check.status='fail' → status='fail'
 *   - qualquer check.status='warn' (e nenhum fail) → status='warn'
 *   - caso contrário → 'pass'
 *
 * Output extra (canon V6):
 *   - `v6_ready_for_dogfood`: true se status != fail
 *   - `v7_unlock_allowed`: SEMPRE false (V7 segue congelada por doutrina)
 */
final class VoxV6CertificationService
{
    use VoxGateStatusHelper;

    public const SCHEMA = 'atlas.vox.v6_certification.v1';

    public const VERSION = '0.1.0';

    public const STATUS_PASS = 'pass';

    public const STATUS_WARN = 'warn';

    public const STATUS_FAIL = 'fail';

    public function __construct(
        private readonly VoxInterlocutorPolicy $policy,
        private readonly VoxAutoModeRouter $router,
        private readonly VoxV5CertificationService $v5Cert,
        // V6-H · summary humano + V7 unlock gate. Opcionais para não quebrar
        // testes que instanciam a service à mão (eles passam apenas os três
        // primeiros). Quando ausentes, o envelope simplesmente não carrega os
        // blocos extras — ainda válido pelo schema.
        private readonly ?VoxDogfoodSummaryService $summary = null,
        private readonly ?VoxV7UnlockGateService $v7Gate = null,
        // GOD-DEBULK · grupos de check extraídos. Opcionais/lazy: quando
        // ausentes (testes que instanciam à mão só com os 3 primeiros), são
        // resolvidos pelo container em runtime — mesmo padrão de summary/v7Gate.
        private readonly ?BackendChecks $backendChecks = null,
        private readonly ?DesktopChecks $desktopChecks = null,
        private readonly ?SafetyUxChecks $safetyUxChecks = null,
    ) {}

    /**
     * Build the V6 certification envelope.
     *
     * @return array{
     *   schema: string,
     *   version: string,
     *   status: string,
     *   v6_ready_for_dogfood: bool,
     *   v7_unlock_allowed: bool,
     *   checks: list<array<string,mixed>>,
     *   summary: array<string,mixed>,
     *   next_actions: list<string>,
     *   generated_at: string
     * }
     */
    public function build(): array
    {
        $checks = [];
        foreach ($this->orderedChecks() as $checkId => $closure) {
            $checks[] = $this->safe($checkId, $closure);
        }
        $status = $this->aggregateStatus($checks);
        $summary = $this->summarise($status, $checks);
        $nextActions = $this->collectNextActions($checks, $status);

        // V6-H · `ready_for_daily_use` mistura check técnico (status != fail) +
        // sinais reais de uso quando disponíveis. Sem dogfood ainda, ficamos
        // honestos e devolvemos `false` — Vitor precisa usar o overlay e
        // medir antes de afirmar "pronto pro dia a dia".
        $summaryHuman = $this->buildSummaryHuman();
        $v7Block = $this->buildV7Block();
        $readyForDailyUse = $status !== self::STATUS_FAIL
            && $summaryHuman !== null
            && ($summaryHuman['ready_for_daily_use'] ?? false) === true;

        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'status' => $status,
            'v6_ready_for_dogfood' => $status !== self::STATUS_FAIL,
            'ready_for_daily_use' => $readyForDailyUse,
            // V7 (memória longitudinal) está congelada por doutrina V6-F.
            // Nem mesmo um pass destrava — exige nova ADR + decisão humana.
            'v7_unlock_allowed' => false,
            // V6-H · expõe a medição rica: bloqueios atuais + critérios. Esse
            // bloco JAMAIS altera `v7_unlock_allowed` no topo, só descreve o
            // estado para que o operador saiba quando vale abrir nova ADR.
            'v7_unlock_status' => $v7Block,
            'dogfood_summary' => $summaryHuman,
            'checks' => $checks,
            'summary' => $summary,
            'next_actions' => $nextActions,
            'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function buildSummaryHuman(): ?array
    {
        // V6-H · resolução tardia para que testes que instanciam a classe à mão
        // (sem o container Laravel) continuem funcionando, e o runtime do
        // artisan ainda ganhe o bloco rico vindo do summary service.
        $summary = $this->summary;
        if ($summary === null) {
            try {
                $summary = app()->make(VoxDogfoodSummaryService::class);
            } catch (\Throwable) {
                return null;
            }
        }
        try {
            return $summary->build();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function buildV7Block(): ?array
    {
        $gate = $this->v7Gate;
        if ($gate === null) {
            try {
                $gate = app()->make(VoxV7UnlockGateService::class);
            } catch (\Throwable) {
                return null;
            }
        }
        try {
            return $gate->evaluate();
        } catch (\Throwable) {
            return null;
        }
    }

    private function backendChecks(): BackendChecks
    {
        // Lazy/container-resolved: quando o façade é instanciado à mão (testes),
        // o grupo é montado pelo container em runtime; suas deps (router/policy)
        // são stateless, então a resolução por container é comportamento-idêntica.
        return $this->backendChecks ?? app()->make(BackendChecks::class);
    }

    private function desktopChecks(): DesktopChecks
    {
        // Scanners puros read-only; sem deps injetadas. Lazy/container-resolved.
        return $this->desktopChecks ?? app()->make(DesktopChecks::class);
    }

    private function safetyUxChecks(): SafetyUxChecks
    {
        // Depende só de VoxInterlocutorPolicy (stateless). Lazy/container-resolved.
        return $this->safetyUxChecks ?? app()->make(SafetyUxChecks::class);
    }

    /**
     * @return array<string, callable(): array<string,mixed>>
     */
    private function orderedChecks(): array
    {
        return [
            // ── Backend ────────────────────────────────────────────────
            ...$this->backendChecks()->checks(),

            // ── Desktop + macOS + Regression Wall ──────────────────────
            // Grupo canonizado na Onda V6-REGRESSION-WALL-FINAL: manifests,
            // Tauri commands, empacotamento macOS e as invariantes que já
            // quebraram em dogfood (payload legado, kebab-case signing,
            // token em manifest, raw_pcm sumindo do Rust).
            ...$this->desktopChecks()->checks(),

            // ── UX + Safety ────────────────────────────────────────────
            ...$this->safetyUxChecks()->checks(),
        ];
    }

    /**
     * @return array<string,mixed>
     */

    /**
     * @param  list<array<string,mixed>>  $checks
     */

    /**
     * @param  list<array<string,mixed>>  $checks
     * @return array<string,mixed>
     */
    private function summarise(string $status, array $checks): array
    {
        $byStatus = [
            self::STATUS_PASS => 0,
            self::STATUS_WARN => 0,
            self::STATUS_FAIL => 0,
        ];
        $warnings = [];
        $failures = [];
        $byArea = [
            'backend' => ['pass' => 0, 'warn' => 0, 'fail' => 0],
            'desktop' => ['pass' => 0, 'warn' => 0, 'fail' => 0],
            'macos' => ['pass' => 0, 'warn' => 0, 'fail' => 0],
            'ux' => ['pass' => 0, 'warn' => 0, 'fail' => 0],
            'safety' => ['pass' => 0, 'warn' => 0, 'fail' => 0],
        ];
        foreach ($checks as $check) {
            $s = (string) ($check['status'] ?? self::STATUS_FAIL);
            $byStatus[$s] = ($byStatus[$s] ?? 0) + 1;
            $area = $this->areaOf((string) $check['check']);
            if (isset($byArea[$area][$s])) {
                $byArea[$area][$s]++;
            }
            if ($s === self::STATUS_WARN) {
                $warnings[] = $check['check'];
            } elseif ($s === self::STATUS_FAIL) {
                $failures[] = $check['check'];
            }
        }
        $verdict = match ($status) {
            self::STATUS_PASS => 'Atlas Vox V6 pronto para dogfood real. Use bastante antes de pensar em V7.',
            self::STATUS_WARN => 'Atlas Vox V6 utilizável; revise pontos de alerta antes de uso público intenso.',
            default => 'Atlas Vox V6 NÃO está pronto — corrija as falhas listadas antes de seguir.',
        };

        return [
            'verdict_pt_br' => $verdict,
            'totals' => $byStatus,
            'by_area' => $byArea,
            'warnings' => $warnings,
            'failures' => $failures,
            'checks_count' => count($checks),
        ];
    }

    private function areaOf(string $checkId): string
    {
        if (str_starts_with($checkId, 'backend_')) {
            return 'backend';
        }
        if (str_starts_with($checkId, 'desktop_')) {
            return 'desktop';
        }
        if (str_starts_with($checkId, 'macos_')) {
            return 'macos';
        }
        if (str_starts_with($checkId, 'ux_')) {
            return 'ux';
        }
        if (str_starts_with($checkId, 'safety_')) {
            return 'safety';
        }

        return 'other';
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     * @return list<string>
     */
    private function collectNextActions(array $checks, string $status): array
    {
        $actions = [];
        if ($status === self::STATUS_PASS) {
            $actions[] = 'Use o Atlas Vox V6 em uso real (dogfood) por algumas semanas antes de cogitar V7.';
            $actions[] = 'Capture sessões reais com `/ai/vox/dogfood/session` para alimentar o relatório.';
            $actions[] = 'Re-rode `php artisan atlas:vox:v6-certify --json` semanalmente para garantir não-regressão.';

            return $actions;
        }
        foreach ($checks as $check) {
            $s = (string) ($check['status'] ?? '');
            if ($s !== self::STATUS_WARN && $s !== self::STATUS_FAIL) {
                continue;
            }
            $checkId = (string) $check['check'];
            $msg = (string) ($check['message'] ?? '');
            $prefix = $s === self::STATUS_FAIL ? '[FAIL] ' : '[WARN] ';
            $actions[] = $prefix.$checkId.' — '.$msg;
        }
        if ($actions === []) {
            $actions[] = 'Sem ação adicional necessária.';
        }

        return $actions;
    }
}
