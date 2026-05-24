<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Gate;

use App\Services\Ai\Vox\Dogfood\VoxDogfoodSummaryService;
use App\Services\Ai\Vox\Interlocutor\VoxInterlocutorPolicy;
use App\Services\Ai\Vox\Routing\VoxAutoModeRouter;
use App\Services\Ai\Vox\VoxSchema;
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
    public const SCHEMA = 'atlas.vox.v6_certification.v1';
    public const VERSION = '0.1.0';

    public const STATUS_PASS = 'pass';
    public const STATUS_WARN = 'warn';
    public const STATUS_FAIL = 'fail';

    /**
     * Strings legacy (em inglês) que NÃO podem aparecer no HTML
     * user-visible das telas Vox V6. Scan é feito nos snapshots SSR de
     * `vox-visual-smoke/` (.html), não na source TS — assim regex/comentários
     * não disparam falso positivo.
     *
     * @var list<string>
     */
    private const FORBIDDEN_LEGACY_UI_STRINGS = [
        'Dictation',
        'Prompt Polish',
        'Intent Compile',
        'Governed Execute',
        'READINESS',
        'V3 GATE',
        'AudioInputInvalid',
        'rms=',
        'peak=',
    ];

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

    /**
     * @return array<string, callable(): array<string,mixed>>
     */
    private function orderedChecks(): array
    {
        return [
            // ── Backend ────────────────────────────────────────────────
            'backend_vox_endpoints_registered' => fn () => $this->checkBackendEndpointsRegistered(),
            'backend_v4_auto_mode_available' => fn () => $this->checkV4AutoModeAvailable(),
            'backend_v5_interlocutor_available' => fn () => $this->checkV5InterlocutorAvailable(),
            'backend_dogfood_endpoint_ok' => fn () => $this->checkDogfoodEndpointOk(),
            'backend_no_raw_audio_persisted' => fn () => $this->checkNoRawAudioPersisted(),
            'backend_no_paid_api_dependency' => fn () => $this->checkNoPaidApiDependency(),
            'backend_no_voice_realtime_touched' => fn () => $this->checkNoVoiceRealtimeTouched(),
            'backend_no_mobile_touched' => fn () => $this->checkNoMobileTouched(),
            // V6-ES-C · qualidade mínima do compiled_prompt (estrutura + vetos).
            'backend_prompt_quality_baseline' => fn () => $this->checkPromptQualityBaseline(),
            // V6-FPG-B · score do quality self-check em 4 vozes canônicas.
            'backend_prompt_self_check_score' => fn () => $this->checkPromptSelfCheckScore(),

            // ── Desktop ────────────────────────────────────────────────
            'desktop_release_check_manifest' => fn () => $this->checkDesktopReleaseCheck(),
            'desktop_visual_smoke_manifest' => fn () => $this->checkDesktopVisualSmoke(),
            'desktop_v6_tauri_commands_present' => fn () => $this->checkDesktopV6TauriCommands(),
            'desktop_ambient_helpers_present' => fn () => $this->checkDesktopAmbientHelpers(),
            'desktop_voice_reply_default_off' => fn () => $this->checkDesktopVoiceReplyDefaultOff(),
            'desktop_settings_persistence_available' => fn () => $this->checkDesktopSettingsPersistence(),
            'desktop_option_space_in_process' => fn () => $this->checkDesktopOptionSpaceInProcess(),

            // ── macOS ──────────────────────────────────────────────────
            'macos_identifier_canonical' => fn () => $this->checkMacOsIdentifier(),
            'macos_microphone_usage_description' => fn () => $this->checkMacOsMicrophoneUsageDescription(),
            'macos_audio_input_entitlement' => fn () => $this->checkMacOsAudioInputEntitlement(),
            'macos_signing_identity_documented' => fn () => $this->checkMacOsSigningIdentity(),

            // ── UX ─────────────────────────────────────────────────────
            'ux_overlay_pt_br_no_legacy_strings' => fn () => $this->checkUxNoLegacyStrings(),
            'ux_advanced_details_collapsed' => fn () => $this->checkUxAdvancedDetailsCollapsed(),
            // V6 Regression Wall · UI principal jamais expõe erro cru.
            'ux_overlay_humanizes_raw_errors' => fn () => $this->checkUxOverlayHumanizesRawErrors(),

            // ── Safety ─────────────────────────────────────────────────
            'safety_no_terminal_auto_execute' => fn () => $this->checkSafetyNoTerminalAutoExecute(),
            'safety_r4_blocks' => fn () => $this->checkSafetyR4Blocks(),
            'safety_receipt_required' => fn () => $this->checkSafetyReceiptRequired(),
            'safety_reply_helper_no_audio_no_provider' => fn () => $this->checkSafetyReplyHelper(),

            // ── Regression Wall · prova que invariants críticas continuam vivas.
            // Esses checks foram canonizados na Onda V6-REGRESSION-WALL-FINAL
            // depois de já termos visto cada um quebrar em produção/dogfood:
            //   - desktop emitir payload `{ transcript: {...} }` legado
            //   - tauri.conf.json migrar para `signing-identity` (kebab-case)
            //   - manifests test-results gravarem ATLAS_TOKEN/Bearer
            //   - Lei 0.9 (raw_pcm_persisted=false) sumindo do Rust
            'regression_wall_payload_session_id_root' => fn () => $this->checkRegressionPayloadSessionIdRoot(),
            'regression_wall_no_tokens_in_manifests' => fn () => $this->checkRegressionNoTokensInManifests(),
            'regression_wall_raw_pcm_invariant_in_rust' => fn () => $this->checkRegressionRawPcmInvariantInRust(),
            'regression_wall_signing_identity_camel_case' => fn () => $this->checkRegressionSigningIdentityCamelCase(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function safe(string $checkId, callable $closure): array
    {
        try {
            $body = $closure();
        } catch (\Throwable $e) {
            return [
                'check' => $checkId,
                'status' => self::STATUS_FAIL,
                'message' => 'Check explodiu: '.$e->getMessage(),
                'details' => ['exception_class' => $e::class],
            ];
        }
        $body['check'] = $checkId;
        $body['status'] ??= self::STATUS_WARN;
        $body['message'] ??= '';
        $body['details'] ??= [];

        return $body;
    }

    // ── Backend checks ──────────────────────────────────────────────────

    /**
     * @return array<string,mixed>
     */
    private function checkBackendEndpointsRegistered(): array
    {
        $routes = $this->readFile(base_path('routes/api.php'));
        if ($routes === null) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'routes/api.php não encontrado.',
                'details' => ['expected' => 'atlas-server/routes/api.php'],
            ];
        }
        $required = [
            '/ai/vox/health',
            '/ai/vox/intent',
            '/ai/vox/execute',
        ];
        $missing = [];
        foreach ($required as $path) {
            if (! str_contains($routes, $path)) {
                $missing[] = $path;
            }
        }
        if ($missing !== []) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'Rotas Vox V3 essenciais ausentes em routes/api.php.',
                'details' => ['missing' => $missing],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'Rotas Vox V3 (health/intent/execute) registradas.',
            'details' => ['required' => $required],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkV4AutoModeAvailable(): array
    {
        if (! class_exists(VoxAutoModeRouter::class)) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'VoxAutoModeRouter (V4) ausente — regressão.',
                'details' => ['expected_class' => VoxAutoModeRouter::class],
            ];
        }
        $decision = $this->router->decide(
            transcript: ['text' => 'manda pro codex investigar o módulo Vox'],
            context: [],
        );
        $schemaOk = ($decision['schema'] ?? null) === VoxSchema::AUTO_MODE_DECISION;
        $modeOk = ($decision['selected_mode'] ?? null) === VoxSchema::MODE_INTENT_COMPILE;
        if (! $schemaOk || ! $modeOk) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'V4 Auto Mode Router não devolveu shape canônico.',
                'details' => [
                    'expected_schema' => VoxSchema::AUTO_MODE_DECISION,
                    'actual_schema' => $decision['schema'] ?? null,
                    'actual_mode' => $decision['selected_mode'] ?? null,
                ],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'V4 Auto Mode Router responde com schema canônico.',
            'details' => [
                'router_version' => $decision['router_version'] ?? null,
                'sample_mode' => $decision['selected_mode'],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkV5InterlocutorAvailable(): array
    {
        if (! class_exists(VoxInterlocutorPolicy::class)) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'VoxInterlocutorPolicy (V5) ausente — regressão.',
                'details' => ['expected_class' => VoxInterlocutorPolicy::class],
            ];
        }
        $decision = $this->policy->evaluate(
            transcript: ['text' => 'manda um rm -rf no cache'],
            intentPacket: [
                'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                'risk_class' => VoxSchema::RISK_R4,
                'goal' => '',
                'constraints' => [],
                'compiled_prompt' => '',
                'context_refs' => [['kind' => 'none', 'ref' => null, 'resolved' => true]],
            ],
        );
        $shapeOk = ($decision['schema'] ?? null) === VoxSchema::INTERLOCUTOR_DECISION
            && ($decision['intervention'] ?? null) === VoxInterlocutorPolicy::INTERVENTION_DISAGREE
            && ($decision['blocking'] ?? false) === true;
        if (! $shapeOk) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'V5 Interlocutor não retornou disagree/blocking em caso destrutivo canônico.',
                'details' => [
                    'expected_schema' => VoxSchema::INTERLOCUTOR_DECISION,
                    'actual' => $decision,
                ],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'V5 Interlocutor disponível e bloqueia destrutivo canônico.',
            'details' => [
                'policy_version' => $decision['policy_version'] ?? null,
                'intervention' => $decision['intervention'],
                'reason_code' => $decision['reason_code'] ?? null,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkDogfoodEndpointOk(): array
    {
        $routes = $this->readFile(base_path('routes/api.php')) ?? '';
        $controller = is_file(base_path('app/Http/Controllers/AtlasAiVoxDogfoodController.php'));
        $model = is_file(base_path('app/Models/AtlasVoxDogfoodSession.php'));
        $migration = $this->fileExistsByGlob(base_path('database/migrations'), '*create_atlas_vox_dogfood_sessions*');
        $issues = [];
        if (! str_contains($routes, '/ai/vox/dogfood')) {
            $issues['route'] = 'rota /ai/vox/dogfood ausente em api.php';
        }
        if (! $controller) {
            $issues['controller'] = 'AtlasAiVoxDogfoodController ausente';
        }
        if (! $model) {
            $issues['model'] = 'AtlasVoxDogfoodSession ausente';
        }
        if (! $migration) {
            $issues['migration'] = 'migração create_atlas_vox_dogfood_sessions ausente';
        }
        if ($issues !== []) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'Endpoint/Modelo/Migração Dogfood incompleto.',
                'details' => $issues,
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'Dogfood Vox (rota + model + migration) presente.',
            'details' => [
                'route_prefix' => '/ai/vox/dogfood',
                'model' => 'App\\Models\\AtlasVoxDogfoodSession',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkNoRawAudioPersisted(): array
    {
        // O controller rejeita campos raw_audio explicitamente e o modelo
        // VoxIntentPacket exige raw_pcm_persisted=false. Validamos a presença
        // do guardrail no controller (defesa em profundidade).
        $controller = $this->readFile(base_path('app/Http/Controllers/AtlasAiVoxController.php'));
        if ($controller === null) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'AtlasAiVoxController.php ausente.',
                'details' => [],
            ];
        }
        $hasRejector = str_contains($controller, 'rejectAudioFields')
            && (str_contains($controller, 'raw_audio_field_forbidden')
                || str_contains($controller, 'prohibitedAudioFields'));
        $hasRawPcmGuard = str_contains($controller, 'raw_pcm_persisted')
            && str_contains($controller, 'raw_pcm_persisted_forbidden');
        if (! $hasRejector || ! $hasRawPcmGuard) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'Guardrails de áudio cru ausentes no controller.',
                'details' => [
                    'has_audio_field_rejector' => $hasRejector,
                    'has_raw_pcm_guard' => $hasRawPcmGuard,
                ],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'Controller rejeita campos de áudio cru e raw_pcm_persisted=true.',
            'details' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkNoPaidApiDependency(): array
    {
        $files = $this->backendCoreVoxFiles();
        $forbidden = [
            'Anthropic\\\\',
            'OpenAI\\\\',
            'use\s+Anthropic\\b',
            'use\s+OpenAI\\b',
            'api\\.anthropic\\.com',
            'api\\.openai\\.com',
            'sk-ant-',
            'OPENAI_API_KEY',
            'ANTHROPIC_API_KEY',
        ];
        $offenders = [];
        foreach ($files as $file) {
            $source = $this->readFile($file);
            if ($source === null) {
                continue;
            }
            foreach ($forbidden as $needle) {
                if (preg_match('/'.$needle.'/i', $source) === 1) {
                    $offenders[] = [
                        'file' => $this->shortenPath($file),
                        'needle' => $needle,
                    ];
                }
            }
        }
        if ($offenders !== []) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'Backend Vox importa/cita API paga — Lei 0 quebrada.',
                'details' => ['offenders' => $offenders],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'Backend Vox V3→V6 não depende de API paga.',
            'details' => ['files_scanned' => count($files)],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkNoVoiceRealtimeTouched(): array
    {
        $files = $this->backendCoreVoxFiles();
        $offenders = [];
        foreach ($files as $file) {
            $source = $this->readFile($file);
            if ($source === null) {
                continue;
            }
            if (preg_match('#App\\\\Services\\\\Ai\\\\Voice\\\\#', $source) === 1
                || preg_match('#App/Services/Ai/Voice/#', $source) === 1
            ) {
                $offenders[] = $this->shortenPath($file);
            }
        }
        if ($offenders !== []) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'Backend Vox V3→V6 importa Voice Realtime Surface — ADR 0003 quebrada.',
                'details' => ['offenders' => $offenders],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'V3→V6 não toca Voice Realtime Surface.',
            'details' => ['files_scanned' => count($files)],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkNoMobileTouched(): array
    {
        $files = $this->backendCoreVoxFiles();
        $offenders = [];
        foreach ($files as $file) {
            $source = $this->readFile($file);
            if ($source === null) {
                continue;
            }
            if (stripos($source, 'atlas-app/') !== false
                || stripos($source, 'atlas_app') !== false
                || stripos($source, 'namespace App\\Mobile') !== false
            ) {
                $offenders[] = $this->shortenPath($file);
            }
        }
        if ($offenders !== []) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'Backend Vox V3→V6 toca mobile/atlas-app.',
                'details' => ['offenders' => $offenders],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'V3→V6 não toca mobile/atlas-app.',
            'details' => ['files_scanned' => count($files)],
        ];
    }

    // ── Desktop checks ──────────────────────────────────────────────────

    /**
     * @return array<string,mixed>
     */
    private function checkDesktopReleaseCheck(): array
    {
        $desktopRoot = $this->desktopRoot();
        if ($desktopRoot === null) {
            return [
                'status' => self::STATUS_WARN,
                'message' => 'atlas-desktop não encontrado ao lado de atlas-server — rode na máquina do operador.',
                'details' => ['searched_at' => dirname(base_path()).'/atlas-desktop'],
            ];
        }
        $manifestPath = $desktopRoot.'/apps/desktop/test-results/vox-release-check/manifest.json';
        $manifest = $this->readJson($manifestPath);
        if ($manifest === null) {
            return [
                'status' => self::STATUS_WARN,
                'message' => 'Manifest vox-release-check ausente — rode `npm run vox:release-check --workspace=@atlas/desktop`.',
                'details' => ['expected_at' => $this->shortenPath($manifestPath)],
            ];
        }
        $status = (string) ($manifest['status'] ?? 'unknown');
        $totals = $manifest['totals'] ?? [];
        if ($status === 'fail') {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'vox:release-check fechou em fail — desktop não está pronto.',
                'details' => [
                    'manifest_path' => $this->shortenPath($manifestPath),
                    'totals' => $totals,
                    'generated_at' => $manifest['generated_at'] ?? null,
                ],
            ];
        }
        if ($status === 'warn') {
            return [
                'status' => self::STATUS_WARN,
                'message' => 'vox:release-check fechou em warn — usável, revise pendências.',
                'details' => [
                    'manifest_path' => $this->shortenPath($manifestPath),
                    'totals' => $totals,
                ],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'vox:release-check passou ('.((int) ($totals['pass'] ?? 0)).' checks).',
            'details' => [
                'manifest_path' => $this->shortenPath($manifestPath),
                'totals' => $totals,
                'generated_at' => $manifest['generated_at'] ?? null,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkDesktopVisualSmoke(): array
    {
        $desktopRoot = $this->desktopRoot();
        if ($desktopRoot === null) {
            return [
                'status' => self::STATUS_WARN,
                'message' => 'atlas-desktop não localizado.',
                'details' => [],
            ];
        }
        $manifestPath = $desktopRoot.'/apps/desktop/test-results/vox-visual-smoke/manifest.json';
        $manifest = $this->readJson($manifestPath);
        if ($manifest === null) {
            return [
                'status' => self::STATUS_WARN,
                'message' => 'Manifest vox-visual-smoke ausente — rode `npm run vox:visual-smoke --workspace=@atlas/desktop`.',
                'details' => ['expected_at' => $this->shortenPath($manifestPath)],
            ];
        }
        $status = (string) ($manifest['status'] ?? 'unknown');
        $totals = $manifest['totals'] ?? [];
        if ($status === 'fail') {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'vox:visual-smoke fechou em fail.',
                'details' => [
                    'manifest_path' => $this->shortenPath($manifestPath),
                    'totals' => $totals,
                ],
            ];
        }
        $resultStatus = $status === 'warn' ? self::STATUS_WARN : self::STATUS_PASS;

        return [
            'status' => $resultStatus,
            'message' => 'vox:visual-smoke '.($resultStatus === self::STATUS_PASS ? 'pass' : 'warn').' ('.((int) ($totals['pass'] ?? 0)).' checks).',
            'details' => [
                'manifest_path' => $this->shortenPath($manifestPath),
                'totals' => $totals,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkDesktopV6TauriCommands(): array
    {
        $desktopRoot = $this->desktopRoot();
        if ($desktopRoot === null) {
            return [
                'status' => self::STATUS_WARN,
                'message' => 'atlas-desktop não localizado.',
                'details' => [],
            ];
        }
        $required = [
            'crates/atlas-tauri/src/commands_vox_reply.rs',
            'crates/atlas-platform/src/vox_settings.rs',
            'crates/atlas-tauri/src/commands_vox_setup.rs',
            'crates/atlas-tauri/src/commands_vox_hotkey.rs',
        ];
        $missing = [];
        foreach ($required as $rel) {
            if (! is_file($desktopRoot.'/'.$rel)) {
                $missing[] = $rel;
            }
        }
        // Confirmar registro dos handlers V6 no lib.rs.
        $lib = $this->readFile($desktopRoot.'/crates/atlas-tauri/src/lib.rs');
        $registered = $lib !== null
            && str_contains($lib, 'commands_vox_reply::vox_settings_get')
            && str_contains($lib, 'commands_vox_reply::vox_settings_update')
            && str_contains($lib, 'commands_vox_reply::vox_speak_short');
        if ($missing !== [] || ! $registered) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'Comandos Tauri V6 (Reply Surface) ausentes ou não registrados.',
                'details' => [
                    'missing_files' => $missing,
                    'lib_handlers_registered' => $registered,
                ],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'Comandos Tauri V6 (settings_get/update/speak_short) presentes e registrados.',
            'details' => ['files' => $required],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkDesktopAmbientHelpers(): array
    {
        $desktopRoot = $this->desktopRoot();
        if ($desktopRoot === null) {
            return [
                'status' => self::STATUS_WARN,
                'message' => 'atlas-desktop não localizado.',
                'details' => [],
            ];
        }
        $required = [
            'crates/atlas-tauri/src/vox_ambient_launch.rs',
            'apps/desktop/scripts/voxDev.mjs',
        ];
        $missing = [];
        foreach ($required as $rel) {
            if (! is_file($desktopRoot.'/'.$rel)) {
                $missing[] = $rel;
            }
        }
        if ($missing !== []) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'Helpers ambient (boot start listening / dev launcher) ausentes.',
                'details' => ['missing' => $missing],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'Helpers ambient e boot start listening presentes.',
            'details' => ['files' => $required],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkDesktopVoiceReplyDefaultOff(): array
    {
        $desktopRoot = $this->desktopRoot();
        if ($desktopRoot === null) {
            return [
                'status' => self::STATUS_WARN,
                'message' => 'atlas-desktop não localizado.',
                'details' => [],
            ];
        }
        $settings = $this->readFile($desktopRoot.'/crates/atlas-platform/src/vox_settings.rs');
        $bridge = $this->readFile($desktopRoot.'/apps/desktop/src/lib/bridge.ts');
        $rustDefault = $settings !== null
            && preg_match('/impl\s+Default\s+for\s+VoiceMode\s*\{[^}]*Self::Off/s', $settings) === 1;
        $tsDefault = $bridge !== null
            && preg_match("/voiceMode\s*:\s*'off'/", $bridge) === 1;
        if (! $rustDefault || ! $tsDefault) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'voice_mode default não é "off" — Atlas falaria sem consentimento.',
                'details' => [
                    'rust_default_off' => $rustDefault,
                    'ts_default_off' => $tsDefault,
                ],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'voice_mode default = off em Rust e TS.',
            'details' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkDesktopSettingsPersistence(): array
    {
        $desktopRoot = $this->desktopRoot();
        if ($desktopRoot === null) {
            return [
                'status' => self::STATUS_WARN,
                'message' => 'atlas-desktop não localizado.',
                'details' => [],
            ];
        }
        $settings = $this->readFile($desktopRoot.'/crates/atlas-platform/src/vox_settings.rs');
        if ($settings === null) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'vox_settings.rs ausente.',
                'details' => [],
            ];
        }
        $hasPath = str_contains($settings, '.atlas/vox/settings.json');
        $hasStore = str_contains($settings, 'VoxSettingsStore')
            && str_contains($settings, 'load_or_initialize')
            && str_contains($settings, 'set_voice_mode');
        if (! $hasPath || ! $hasStore) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'Store de settings incompleto (path ou métodos ausentes).',
                'details' => [
                    'has_canonical_path' => $hasPath,
                    'has_store_api' => $hasStore,
                ],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'Settings persistem em ~/.atlas/vox/settings.json via VoxSettingsStore.',
            'details' => ['canonical_path' => '~/.atlas/vox/settings.json'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkDesktopOptionSpaceInProcess(): array
    {
        $desktopRoot = $this->desktopRoot();
        if ($desktopRoot === null) {
            return [
                'status' => self::STATUS_WARN,
                'message' => 'atlas-desktop não localizado.',
                'details' => [],
            ];
        }
        $hotkey = $this->readFile($desktopRoot.'/crates/atlas-tauri/src/commands_vox_hotkey.rs');
        $hotkeyMod = $this->readFile($desktopRoot.'/crates/atlas-platform/src/vox/hotkey.rs');
        $hasOptionSpace = ($hotkey !== null && stripos($hotkey, 'Option+Space') !== false)
            || ($hotkeyMod !== null && stripos($hotkeyMod, 'Option+Space') !== false)
            || ($hotkeyMod !== null && stripos($hotkeyMod, 'option_space') !== false);
        if (! $hasOptionSpace) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'Hotkey Option+Space não documentada em hotkey.rs/commands_vox_hotkey.rs.',
                'details' => [],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'Hotkey Option+Space in-process disponível (Rust hotkey runtime).',
            'details' => [],
        ];
    }

    // ── macOS checks ────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>
     */
    private function checkMacOsIdentifier(): array
    {
        $desktopRoot = $this->desktopRoot();
        if ($desktopRoot === null) {
            return [
                'status' => self::STATUS_WARN,
                'message' => 'atlas-desktop não localizado — pulei verificação macOS.',
                'details' => [],
            ];
        }
        $conf = $this->readJson($desktopRoot.'/crates/atlas-tauri/tauri.conf.json');
        if ($conf === null) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'tauri.conf.json ausente ou inválido.',
                'details' => [],
            ];
        }
        $identifier = (string) ($conf['identifier'] ?? '');
        if ($identifier !== 'com.atlas.code') {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'Bundle identifier diferente do canônico.',
                'details' => [
                    'expected' => 'com.atlas.code',
                    'actual' => $identifier,
                ],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'Bundle identifier = com.atlas.code.',
            'details' => ['identifier' => $identifier],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkMacOsMicrophoneUsageDescription(): array
    {
        $desktopRoot = $this->desktopRoot();
        if ($desktopRoot === null) {
            return [
                'status' => self::STATUS_WARN,
                'message' => 'atlas-desktop não localizado.',
                'details' => [],
            ];
        }
        $infoPlist = $this->readFile($desktopRoot.'/crates/atlas-tauri/Info.plist');
        if ($infoPlist === null) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'Info.plist ausente.',
                'details' => [],
            ];
        }
        $hasKey = str_contains($infoPlist, '<key>NSMicrophoneUsageDescription</key>');
        if (! $hasKey) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'NSMicrophoneUsageDescription ausente no Info.plist — macOS bloqueia STT.',
                'details' => [],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'NSMicrophoneUsageDescription presente no Info.plist.',
            'details' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkMacOsAudioInputEntitlement(): array
    {
        $desktopRoot = $this->desktopRoot();
        if ($desktopRoot === null) {
            return [
                'status' => self::STATUS_WARN,
                'message' => 'atlas-desktop não localizado.',
                'details' => [],
            ];
        }
        $ent = $this->readFile($desktopRoot.'/crates/atlas-tauri/entitlements.plist');
        if ($ent === null) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'entitlements.plist ausente.',
                'details' => [],
            ];
        }
        // Procura key + <true/> no mesmo bloco.
        $ok = preg_match(
            '#<key>com\.apple\.security\.device\.audio-input</key>\s*<true/>#s',
            $ent,
        ) === 1;
        if (! $ok) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'Entitlement com.apple.security.device.audio-input = true ausente.',
                'details' => [],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'Entitlement audio-input habilitado.',
            'details' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkMacOsSigningIdentity(): array
    {
        $desktopRoot = $this->desktopRoot();
        if ($desktopRoot === null) {
            return [
                'status' => self::STATUS_WARN,
                'message' => 'atlas-desktop não localizado.',
                'details' => [],
            ];
        }
        $conf = $this->readJson($desktopRoot.'/crates/atlas-tauri/tauri.conf.json');
        $signingIdentity = null;
        if (is_array($conf)) {
            // Tauri 2 aceita tanto `signingIdentity` (camelCase) quanto
            // `signing-identity` (kebab-case) no JSON. O canon do Atlas hoje
            // usa kebab-case; checamos as duas variantes para não emitir
            // falso WARN.
            $signingIdentity = $conf['bundle']['macOS']['signingIdentity']
                ?? $conf['bundle']['macOS']['signing-identity']
                ?? $conf['tauri']['bundle']['macOS']['signingIdentity']
                ?? $conf['tauri']['bundle']['macOS']['signing-identity']
                ?? null;
        }
        if (is_string($signingIdentity) && $signingIdentity !== '') {
            return [
                'status' => self::STATUS_PASS,
                'message' => 'Signing identity declarada em tauri.conf.json.',
                'details' => ['signingIdentity' => $signingIdentity],
            ];
        }

        // Em dev local, é normal não haver signing identity gerenciada pela
        // Apple Developer ID. O canon do Atlas usa "Atlas Local Code Signing"
        // em build local — não bloqueia dogfood, mas precisa de justificativa
        // documentada. Tratamos como WARN com next_action concreta.
        return [
            'status' => self::STATUS_WARN,
            'message' => 'Sem signing identity em tauri.conf.json — em build local usar Atlas Local Code Signing é OK; para distribuição, configurar Developer ID.',
            'details' => [
                'justification' => 'Atlas Local Code Signing aceitável em dogfood local; Developer ID exigido só em release pública.',
            ],
        ];
    }

    // ── UX checks ───────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>
     */
    private function checkUxNoLegacyStrings(): array
    {
        $desktopRoot = $this->desktopRoot();
        if ($desktopRoot === null) {
            return [
                'status' => self::STATUS_WARN,
                'message' => 'atlas-desktop não localizado — pulei scan UX.',
                'details' => [],
            ];
        }
        $snapshotsDir = $desktopRoot.'/apps/desktop/test-results/vox-visual-smoke';
        if (! is_dir($snapshotsDir)) {
            return [
                'status' => self::STATUS_WARN,
                'message' => 'Snapshots vox-visual-smoke ausentes — rode visual-smoke antes da cert.',
                'details' => ['expected_dir' => $this->shortenPath($snapshotsDir)],
            ];
        }
        $files = glob($snapshotsDir.'/overlay-*.html') ?: [];
        if ($files === []) {
            return [
                'status' => self::STATUS_WARN,
                'message' => 'Nenhum snapshot overlay-*.html para escanear.',
                'details' => [],
            ];
        }
        $hits = [];
        foreach ($files as $file) {
            $html = $this->readFile($file);
            if ($html === null) {
                continue;
            }
            foreach (self::FORBIDDEN_LEGACY_UI_STRINGS as $needle) {
                if (str_contains($html, $needle)) {
                    $hits[] = [
                        'file' => basename($file),
                        'needle' => $needle,
                    ];
                }
            }
        }
        if ($hits !== []) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'Strings legacy aparecendo na UI renderizada — UX V6 quebrou.',
                'details' => [
                    'hits' => $hits,
                    'forbidden_strings' => self::FORBIDDEN_LEGACY_UI_STRINGS,
                ],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'Nenhuma string legacy renderizada nos snapshots SSR.',
            'details' => [
                'snapshots_scanned' => count($files),
                'forbidden_strings_checked' => count(self::FORBIDDEN_LEGACY_UI_STRINGS),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkUxAdvancedDetailsCollapsed(): array
    {
        $desktopRoot = $this->desktopRoot();
        if ($desktopRoot === null) {
            return [
                'status' => self::STATUS_WARN,
                'message' => 'atlas-desktop não localizado.',
                'details' => [],
            ];
        }
        $overlay = $this->readFile($desktopRoot.'/apps/desktop/src/components/vox/VoxOverlay.tsx');
        if ($overlay === null) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'VoxOverlay.tsx ausente.',
                'details' => [],
            ];
        }
        $hasToggle = str_contains($overlay, 'Detalhes avançados');
        $defaultClosed = preg_match('/useState<boolean>\(false\)\s*\n\s*\/\/.*advancedOpen|advancedOpen.*useState<boolean>\(false\)/s', $overlay) === 1
            || str_contains($overlay, '[advancedOpen, setAdvancedOpen] = useState<boolean>(false)');
        if (! $hasToggle || ! $defaultClosed) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => '"Detalhes avançados" não estão colapsados por default no overlay.',
                'details' => [
                    'has_toggle_label' => $hasToggle,
                    'default_closed' => $defaultClosed,
                ],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'Detalhes técnicos recolhidos por default em VoxOverlay.',
            'details' => [],
        ];
    }

    // ── Safety checks ───────────────────────────────────────────────────

    /**
     * @return array<string,mixed>
     */
    private function checkSafetyNoTerminalAutoExecute(): array
    {
        $files = $this->backendCoreVoxFiles();
        $offenders = [];
        $forbidden = [
            '/\bSymfony\\\\Component\\\\Process\\\\Process\b/',
            '/\bexec\s*\(/',
            '/\bshell_exec\s*\(/',
            '/\bproc_open\s*\(/',
            '/\bpassthru\s*\(/',
            '/\bsystem\s*\(/',
            '/\bpopen\s*\(/',
        ];
        foreach ($files as $file) {
            $source = $this->readFile($file);
            if ($source === null) {
                continue;
            }
            foreach ($forbidden as $pattern) {
                if (preg_match($pattern, $source) === 1) {
                    $offenders[] = [
                        'file' => $this->shortenPath($file),
                        'pattern' => $pattern,
                    ];
                }
            }
        }
        if ($offenders !== []) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'Backend Vox dispara shell direto — terminal auto-execute proibido.',
                'details' => ['offenders' => $offenders],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'Backend Vox V3→V6 não auto-executa shell/terminal.',
            'details' => ['files_scanned' => count($files)],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkSafetyR4Blocks(): array
    {
        $cases = [
            ['text' => 'manda um rm -rf no diretório', 'risk' => VoxSchema::RISK_R4],
            ['text' => 'roda drop database production', 'risk' => VoxSchema::RISK_R4],
            ['text' => 'apaga tudo dessa pasta', 'risk' => VoxSchema::RISK_R3],
        ];
        $misses = [];
        foreach ($cases as $c) {
            $decision = $this->policy->evaluate(
                transcript: ['text' => $c['text']],
                intentPacket: [
                    'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                    'risk_class' => $c['risk'],
                    'goal' => '',
                    'constraints' => [],
                    'compiled_prompt' => '',
                    'context_refs' => [['kind' => 'none', 'ref' => null, 'resolved' => true]],
                ],
            );
            if (($decision['intervention'] ?? '') !== VoxInterlocutorPolicy::INTERVENTION_DISAGREE
                || ! ($decision['blocking'] ?? false)
            ) {
                $misses[] = [
                    'text' => $c['text'],
                    'risk' => $c['risk'],
                    'actual' => $decision['intervention'] ?? null,
                    'blocking' => $decision['blocking'] ?? null,
                ];
            }
        }
        if ($misses !== []) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'Caso(s) destrutivo(s) canônico(s) não foram bloqueados.',
                'details' => ['misses' => $misses],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'R4 e markers destrutivos canônicos seguem bloqueando.',
            'details' => ['cases_checked' => count($cases)],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkSafetyReceiptRequired(): array
    {
        $controller = $this->readFile(base_path('app/Http/Controllers/AtlasAiVoxController.php'));
        if ($controller === null) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'AtlasAiVoxController.php ausente.',
                'details' => [],
            ];
        }
        // /execute exige intent_id + receipt_id no validate.
        $requiresReceipt = preg_match(
            "/'receipt_id'\s*=>\s*\[\s*'required'/",
            $controller,
        ) === 1
            && preg_match(
                "/'intent_id'\s*=>\s*\[\s*'required'/",
                $controller,
            ) === 1;
        if (! $requiresReceipt) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => '/ai/vox/execute não exige intent_id+receipt_id required — receipt opcional é regressão.',
                'details' => [],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => '/ai/vox/execute exige intent_id+receipt_id obrigatórios.',
            'details' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkSafetyReplyHelper(): array
    {
        $desktopRoot = $this->desktopRoot();
        if ($desktopRoot === null) {
            return [
                'status' => self::STATUS_WARN,
                'message' => 'atlas-desktop não localizado.',
                'details' => [],
            ];
        }
        $reply = $this->readFile($desktopRoot.'/crates/atlas-tauri/src/commands_vox_reply.rs');
        if ($reply === null) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'commands_vox_reply.rs ausente.',
                'details' => [],
            ];
        }
        $helper = $this->extractBetween(
            $reply,
            'pub async fn vox_speak_short',
            'pub async fn atlas_voice_speak',
        ) ?? $reply;

        $issues = [];
        // Helper NÃO grava áudio.
        if (preg_match('/\b(audio_record|raw_pcm|wav_record|record_audio)\b/i', $helper) === 1) {
            $issues[] = 'reply helper menciona gravação de áudio';
        }
        // Helper NÃO chama provider (codex/claude/openai/anthropic).
        if (preg_match('/\b(codex|claude_cli|anthropic|openai|elevenlabs)\b/i', $helper) === 1) {
            $issues[] = 'reply helper invoca provider';
        }
        // Helper curto é whitelist-only; fala premium fica em outro comando.
        $usesWhitelist = str_contains($helper, 'VoxShortPhrase::from_key');
        $usesPremiumGate = str_contains($helper, 'premium_tts_required');
        if (! $usesWhitelist || ! $usesPremiumGate) {
            $issues[] = 'reply helper não usa whitelist canônica + gate premium_tts_required';
        }
        if ($issues !== []) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'Reply helper desktop violou contrato V6.',
                'details' => ['issues' => $issues],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'Reply helper curto usa whitelist + gate premium_tts_required (sem áudio bruto, sem provider).',
            'details' => [],
        ];
    }

    // ── UX · humanização ───────────────────────────────────────────────

    /**
     * V6 Regression Wall · garante que a função `humanizeOverlayError`
     * canônica continua viva e exportada do módulo isolado
     * (`voxOverlayHumanize.ts`). Se ela for removida, mensagens cruas do
     * Rust/Laravel voltam a vazar diretamente no banner do overlay.
     *
     * Esse check não roda a função — só prova que ela existe, que está
     * exportada, e que os ramos críticos (microfone, hotkey, fetch, JSON
     * cru) seguem presentes no source. A validação semântica fica nos
     * testes desktop (`voxOverlayHumanization.test.ts`).
     *
     * @return array<string,mixed>
     */
    private function checkUxOverlayHumanizesRawErrors(): array
    {
        $desktopRoot = $this->desktopRoot();
        if ($desktopRoot === null) {
            return [
                'status' => self::STATUS_WARN,
                'message' => 'atlas-desktop não localizado — pulei prova de humanização.',
                'details' => [],
            ];
        }
        $helper = $this->readFile($desktopRoot.'/apps/desktop/src/components/vox/voxOverlayHumanize.ts');
        $overlay = $this->readFile($desktopRoot.'/apps/desktop/src/components/vox/VoxOverlay.tsx');
        if ($helper === null) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'voxOverlayHumanize.ts ausente — humanização do banner desfeita.',
                'details' => ['expected' => 'apps/desktop/src/components/vox/voxOverlayHumanize.ts'],
            ];
        }
        if ($overlay === null) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'VoxOverlay.tsx ausente.',
                'details' => [],
            ];
        }
        $issues = [];
        if (! str_contains($helper, 'export function humanizeOverlayError')) {
            $issues[] = 'humanizeOverlayError não está exportada no helper';
        }
        // Ramos canônicos · cada um cobre uma família de erros cruos.
        $requiredBranches = [
            'audioinputinvalid' => 'microfone',
            'rms=0' => 'microfone',
            'peak=0' => 'microfone',
            'active_ratio=0' => 'microfone',
            'hotkey' => 'hotkey',
            'whisper' => 'modelo de voz local',
            'fetch' => 'servidor Atlas',
            'panic' => 'Detalhes avançados',
        ];
        $lowered = strtolower($helper);
        foreach ($requiredBranches as $marker => $_familia) {
            if (! str_contains($lowered, strtolower($marker))) {
                $issues[] = "ramo crítico ausente no humanizer: \"$marker\"";
            }
        }
        if (! str_contains($overlay, "from './voxOverlayHumanize'")) {
            $issues[] = 'VoxOverlay.tsx não importa humanizeOverlayError do módulo isolado';
        }
        if (! str_contains($overlay, 'humanizeOverlayError(error)')) {
            $issues[] = 'VoxOverlay.tsx não aplica humanizeOverlayError no banner';
        }
        if ($issues !== []) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'humanização do banner de erro do overlay quebrou.',
                'details' => ['issues' => $issues],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'humanizeOverlayError exportado + 8 ramos canônicos preservados.',
            'details' => [
                'helper' => 'apps/desktop/src/components/vox/voxOverlayHumanize.ts',
                'branches_checked' => array_keys($requiredBranches),
            ],
        ];
    }

    // ── Regression Wall ────────────────────────────────────────────────

    /**
     * V6 Regression Wall · prova que `buildVoxKernelIntentPayload` continua
     * achatando o transcript em snake_case na raiz. Se alguém ressuscitar
     * o wrapper legado `{ transcript: {...} }`, o Kernel devolve 422 e o
     * overlay mostra o JSON cru — exatamente o que essa muralha impede.
     *
     * @return array<string,mixed>
     */
    private function checkRegressionPayloadSessionIdRoot(): array
    {
        $desktopRoot = $this->desktopRoot();
        if ($desktopRoot === null) {
            return [
                'status' => self::STATUS_WARN,
                'message' => 'atlas-desktop não localizado — pulei prova de payload.',
                'details' => [],
            ];
        }
        $bridge = $this->readFile($desktopRoot.'/apps/desktop/src/lib/bridge.ts');
        if ($bridge === null) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'bridge.ts ausente — desktop não consegue chamar o Kernel.',
                'details' => [],
            ];
        }
        $issues = [];
        if (! str_contains($bridge, 'export function buildVoxKernelIntentPayload')) {
            $issues[] = 'buildVoxKernelIntentPayload não exportado em bridge.ts';
        }
        if (! str_contains($bridge, 'session_id: transcript.sessionId')) {
            $issues[] = 'session_id não vai na raiz a partir de transcript.sessionId';
        }
        if (! str_contains($bridge, 'transcript_id: transcript.transcriptId')) {
            $issues[] = 'transcript_id não vai na raiz a partir de transcript.transcriptId';
        }
        if (! str_contains($bridge, 'raw_pcm_persisted: transcript.rawPcmPersisted')) {
            $issues[] = 'raw_pcm_persisted não é propagado explicitamente (Lei 0.9)';
        }
        // Defesa adicional: nenhum trecho do builder pode reintroduzir o
        // wrapper legado `transcript:` aninhado dentro do payload.
        if (preg_match('/body\s*\[\s*[\'"]transcript[\'"]\s*\]\s*=/', $bridge) === 1
            || preg_match('/transcript\s*:\s*request\.transcript\b/', $bridge) === 1
        ) {
            $issues[] = 'builder voltou a empacotar { transcript: {...} } no payload (wrapper legado)';
        }
        if ($issues !== []) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'Builder /ai/vox/intent regrediu para formato legado.',
                'details' => ['issues' => $issues],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'buildVoxKernelIntentPayload achata transcript em snake_case na raiz.',
            'details' => [
                'bridge' => 'apps/desktop/src/lib/bridge.ts',
            ],
        ];
    }

    /**
     * V6 Regression Wall · varre os manifests gerados por `npm run vox:*`
     * em busca de padrões de token (Bearer, ATLAS_TOKEN=valor, sk-..., e
     * confirmation_token literal). Esses arquivos seguem o operador no
     * git/CI — qualquer leak ali é fail.
     *
     * @return array<string,mixed>
     */
    private function checkRegressionNoTokensInManifests(): array
    {
        $desktopRoot = $this->desktopRoot();
        if ($desktopRoot === null) {
            return [
                'status' => self::STATUS_WARN,
                'message' => 'atlas-desktop não localizado — pulei varredura de tokens.',
                'details' => [],
            ];
        }
        $base = $desktopRoot.'/apps/desktop/test-results';
        if (! is_dir($base)) {
            return [
                'status' => self::STATUS_WARN,
                'message' => 'test-results/ ainda não foi gerado — rode os smokes antes da cert.',
                'details' => ['expected_dir' => $this->shortenPath($base)],
            ];
        }
        $candidates = [
            $base.'/vox-first-use-smoke/manifest.json',
            $base.'/vox-visual-smoke/manifest.json',
            $base.'/vox-release-check/manifest.json',
            $base.'/vox-v6-certify/manifest.json',
            $base.'/vox-doctor/manifest.json',
        ];
        $leakPatterns = [
            'bearer_token' => '/Bearer\s+[A-Za-z0-9._\-]{16,}/',
            'openai_secret' => '/sk-[A-Za-z0-9]{20,}/',
            'atlas_token_value' => '/ATLAS_TOKEN["\']?\s*[:=]\s*["\']?[A-Za-z0-9._\-]{12,}/',
            'confirmation_token_literal' => '/"confirmation_token"\s*:\s*"[^"\s]{12,}"/',
        ];
        $hits = [];
        $scanned = 0;
        foreach ($candidates as $file) {
            if (! is_file($file)) {
                continue;
            }
            $scanned++;
            $body = $this->readFile($file);
            if ($body === null) {
                continue;
            }
            foreach ($leakPatterns as $label => $pattern) {
                if (preg_match($pattern, $body) === 1) {
                    $hits[] = ['manifest' => $this->shortenPath($file), 'pattern' => $label];
                }
            }
        }
        if ($hits !== []) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'Manifest test-results vazou padrão de token.',
                'details' => ['leaks' => $hits, 'scanned' => $scanned],
            ];
        }
        if ($scanned === 0) {
            return [
                'status' => self::STATUS_WARN,
                'message' => 'Nenhum manifest para varrer — rode os smokes desktop antes da cert.',
                'details' => ['searched_in' => $this->shortenPath($base)],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => "$scanned manifest(s) varridos, zero token detectado.",
            'details' => ['scanned' => $scanned],
        ];
    }

    /**
     * V6 Regression Wall · garante que o crate `atlas-platform` continua
     * declarando `raw_pcm_persisted: false` (e nunca `: true`) em qualquer
     * estado de sessão Vox. Lei 0.9: áudio cru nunca toca disco.
     *
     * @return array<string,mixed>
     */
    private function checkRegressionRawPcmInvariantInRust(): array
    {
        $desktopRoot = $this->desktopRoot();
        if ($desktopRoot === null) {
            return [
                'status' => self::STATUS_WARN,
                'message' => 'atlas-desktop não localizado — pulei invariante Rust.',
                'details' => [],
            ];
        }
        $session = $this->readFile($desktopRoot.'/crates/atlas-platform/src/vox/session.rs');
        if ($session === null) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'session.rs ausente — invariante raw_pcm_persisted não verificável.',
                'details' => [],
            ];
        }
        $hasTrue = preg_match('/raw_pcm_persisted\s*:\s*true/', $session) === 1;
        $hasFalse = preg_match('/raw_pcm_persisted\s*:\s*false/', $session) === 1;
        if ($hasTrue || ! $hasFalse) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'Invariante raw_pcm_persisted=false foi quebrada no Rust.',
                'details' => [
                    'has_true_declaration' => $hasTrue,
                    'has_false_declaration' => $hasFalse,
                ],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'session.rs preserva raw_pcm_persisted=false em todos os estados.',
            'details' => ['source' => 'crates/atlas-platform/src/vox/session.rs'],
        ];
    }

    /**
     * V6 Regression Wall · `tauri.conf.json` precisa usar `signingIdentity`
     * (camelCase, canon Tauri 2). A variante kebab-case `signing-identity`
     * é silenciosamente ignorada pelo Tauri e gera build sem assinatura.
     *
     * Esse check é STRICT — falha se kebab-case aparecer no JSON, mesmo
     * que camelCase também esteja presente, porque a ambiguidade já
     * causou drift entre release-check e build real.
     *
     * @return array<string,mixed>
     */
    private function checkRegressionSigningIdentityCamelCase(): array
    {
        $desktopRoot = $this->desktopRoot();
        if ($desktopRoot === null) {
            return [
                'status' => self::STATUS_WARN,
                'message' => 'atlas-desktop não localizado — pulei prova de signing identity.',
                'details' => [],
            ];
        }
        $confPath = $desktopRoot.'/crates/atlas-tauri/tauri.conf.json';
        $raw = $this->readFile($confPath);
        if ($raw === null) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'tauri.conf.json ausente — não consigo provar signing identity.',
                'details' => [],
            ];
        }
        // Defesa primeiro: kebab-case banido no arquivo cru, mesmo que
        // não esteja no caminho `bundle.macOS`. Qualquer chave
        // `"signing-identity"` é regressão.
        if (preg_match('/"signing-identity"\s*:/', $raw) === 1) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'tauri.conf.json contém `signing-identity` (kebab-case) — Tauri 2 ignora silenciosamente.',
                'details' => [
                    'conf_path' => $this->shortenPath($confPath),
                    'fix' => 'Renomeie para `signingIdentity` (camelCase) em bundle.macOS.',
                ],
            ];
        }
        $conf = $this->readJson($confPath);
        $macOS = $conf['bundle']['macOS']
            ?? $conf['tauri']['bundle']['macOS']
            ?? [];
        $signing = $macOS['signingIdentity'] ?? null;
        if (! is_string($signing) || $signing === '') {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'bundle.macOS.signingIdentity ausente — build assinada sairia sem identidade.',
                'details' => ['conf_path' => $this->shortenPath($confPath)],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'signingIdentity (camelCase) declarado, kebab-case proibido.',
            'details' => [
                'conf_path' => $this->shortenPath($confPath),
                'signingIdentity' => $signing,
            ],
        ];
    }

    // ── Helpers ────────────────────────────────────────────────────────

    /**
     * Lista dos arquivos backend Vox que compõem V3→V6 e são alvo dos
     * scans de fronteira (Voice Realtime, mobile, API paga, shell).
     *
     * @return list<string>
     */
    private function backendCoreVoxFiles(): array
    {
        return [
            base_path('app/Services/Ai/Vox/Interlocutor/VoxInterlocutorPolicy.php'),
            base_path('app/Services/Ai/Vox/Routing/VoxAutoModeRouter.php'),
            base_path('app/Services/Ai/Vox/VoxCompiler.php'),
            base_path('app/Services/Ai/Vox/VoxPromptCompiler.php'),
            base_path('app/Services/Ai/Vox/VoxPromptPolisher.php'),
            base_path('app/Services/Ai/Vox/VoxRiskClassifier.php'),
            base_path('app/Services/Ai/Vox/VoxIntentExtractor.php'),
        ];
    }

    private function desktopRoot(): ?string
    {
        $candidate = dirname(base_path()).'/atlas-desktop';

        return is_dir($candidate) ? $candidate : null;
    }

    private function readFile(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }
        $content = @file_get_contents($path);

        return $content === false ? null : $content;
    }

    private function extractBetween(string $source, string $startNeedle, string $endNeedle): ?string
    {
        $start = strpos($source, $startNeedle);
        if ($start === false) {
            return null;
        }

        $end = strpos($source, $endNeedle, $start + strlen($startNeedle));
        if ($end === false) {
            return substr($source, $start);
        }

        return substr($source, $start, $end - $start);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readJson(string $path): ?array
    {
        $raw = $this->readFile($path);
        if ($raw === null) {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function fileExistsByGlob(string $dir, string $pattern): bool
    {
        if (! is_dir($dir)) {
            return false;
        }
        $hits = glob($dir.'/'.$pattern) ?: [];

        return $hits !== [];
    }

    /**
     * Encurta caminhos absolutos para legibilidade do envelope JSON. NÃO
     * é redação — só substitui HOME por `~` quando aplicável.
     */
    private function shortenPath(string $path): string
    {
        $home = getenv('HOME');
        if (is_string($home) && $home !== '' && str_starts_with($path, $home)) {
            return '~'.substr($path, strlen($home));
        }

        return $path;
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     */
    private function aggregateStatus(array $checks): string
    {
        $hasFail = false;
        $hasWarn = false;
        foreach ($checks as $check) {
            $status = (string) ($check['status'] ?? self::STATUS_FAIL);
            if ($status === self::STATUS_FAIL) {
                $hasFail = true;
            } elseif ($status === self::STATUS_WARN) {
                $hasWarn = true;
            }
        }
        if ($hasFail) {
            return self::STATUS_FAIL;
        }
        if ($hasWarn) {
            return self::STATUS_WARN;
        }

        return self::STATUS_PASS;
    }

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

    /**
     * V6-ES-C · Prompt Quality Baseline.
     *
     * Sanity check determinístico: compila 4 vozes canônicas e verifica que
     * cada `compiled_prompt` traz todas as seções canônicas + vetos
     * universais. Se falhar, é regressão grave no compiler. Não chama
     * provider, não chama rede.
     *
     * @return array<string,mixed>
     */
    private function checkPromptQualityBaseline(): array
    {
        try {
            $polisher = new \App\Services\Ai\Vox\VoxPromptPolisher();
            $extractor = new \App\Services\Ai\Vox\VoxIntentExtractor($polisher);
            $compiler = new \App\Services\Ai\Vox\VoxPromptCompiler();

            $canonical = \App\Services\Ai\Vox\VoxPromptCompiler::CANONICAL_SECTIONS;
            $universalVetoes = [
                'Não execute comandos de terminal sozinho.',
                'Não use API paga, não chame provider remoto que cobre por uso.',
                'Não invente arquivo, função ou dependência que não exista no repo.',
            ];

            $voices = [
                'codex_diagnostic' => 'Codex, investiga por que o teste de microfone está quebrando, mas não mexa em VoxEvidenceService.',
                'claude_plan' => 'Claude, faz um plano pra refatorar o overlay sem tocar no kernel.',
                'local_text' => 'Resume em uma linha o que esse arquivo faz.',
                'auto_diff' => 'Aplica um diff curto pra corrigir o bug do hotkey no useVoxOverlay.',
            ];

            $missingPerVoice = [];
            foreach ($voices as $label => $voice) {
                $extracted = $extractor->extract(
                    ['text' => $voice, 'session_id' => 's', 'transcript_id' => 't'],
                    [],
                );
                $out = $compiler->compile($voice, [
                    'goal' => $extracted['goal'],
                    'constraints' => $extracted['constraints'],
                    'provider_hint' => $extracted['provider_hint'],
                    'executor_hint' => $extracted['executor_hint'],
                    'output_format' => $extracted['output_format'],
                    'context_refs' => $extracted['context_refs'],
                    'risk_class' => $extracted['risk_class'],
                    'risk_markers' => $extracted['risk_markers'],
                    'normalised_text' => $extracted['normalised_text'],
                ]);
                $prompt = (string) ($out['compiled_prompt'] ?? '');
                $missing = [];
                foreach ($canonical as $sec) {
                    if (! str_contains($prompt, $sec)) {
                        $missing[] = $sec;
                    }
                }
                foreach ($universalVetoes as $veto) {
                    if (! str_contains($prompt, $veto)) {
                        $missing[] = 'veto: '.$veto;
                    }
                }
                // Sanity adicional: prompt mínimo razoável (não-trivial).
                if (mb_strlen($prompt) < 600) {
                    $missing[] = 'prompt curto demais (<600 chars)';
                }
                if ($missing !== []) {
                    $missingPerVoice[$label] = $missing;
                }
            }

            if ($missingPerVoice !== []) {
                return [
                    'status' => self::STATUS_FAIL,
                    'message' => 'Compiler V6 produziu prompt sem seções canônicas ou vetos universais.',
                    'details' => ['missing' => $missingPerVoice],
                ];
            }

            return [
                'status' => self::STATUS_PASS,
                'message' => '4 vozes canônicas compilaram com seções canônicas + vetos universais presentes.',
                'details' => [
                    'voices_tested' => array_keys($voices),
                    'canonical_sections' => $canonical,
                    'universal_vetoes' => $universalVetoes,
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'Compiler V6 lançou exceção ao compilar voz canônica.',
                'details' => ['exception' => $e->getMessage()],
            ];
        }
    }

    /**
     * V6-FPG-B · invoca o `selfCheck` determinístico do compiler em 5
     * vozes canônicas e exige:
     *   - score ≥ 0.8 em cada uma
     *   - zero `lost_negations`
     *   - has_goal + has_expected_output em todas
     *
     * Falha grave aqui = compiler ficou genérico ou perdeu intenção do
     * operador — bloqueia release V6. Não chama LLM, não chama rede.
     *
     * @return array<string,mixed>
     */
    private function checkPromptSelfCheckScore(): array
    {
        try {
            $polisher = new \App\Services\Ai\Vox\VoxPromptPolisher();
            $extractor = new \App\Services\Ai\Vox\VoxIntentExtractor($polisher);
            $compiler = new \App\Services\Ai\Vox\VoxPromptCompiler();

            $voices = [
                'codex_diagnostic_with_constraint' => 'Codex, investiga o erro do hotkey no VoxOverlay, mas não toque no VoxEvidenceService.',
                'claude_plan_defer' => 'Claude, faz um plano de refator do overlay sem mexer no kernel, não implementa ainda.',
                'codex_diff_min' => 'Codex, aplica um diff curto pra corrigir o bug do useVoxOverlay sem instalar pacote novo.',
                'atlas_internal' => 'Atlas Dev, me explica como o VoxEvidenceService trabalha com hard gates.',
                'multi_constraints' => 'Investiga o erro no AtlasAiVoxController mas não toque no VoxEvidenceService, sem API paga, sem dependência nova, antes de tudo confirma o schema.',
            ];

            $minScore = 0.8;
            $failures = [];
            $perVoice = [];
            foreach ($voices as $label => $voice) {
                $extracted = $extractor->extract(
                    ['text' => $voice, 'session_id' => 's', 'transcript_id' => 't'],
                    [],
                );
                $compiled = $compiler->compile($voice, [
                    'goal' => $extracted['goal'],
                    'constraints' => $extracted['constraints'],
                    'provider_hint' => $extracted['provider_hint'],
                    'executor_hint' => $extracted['executor_hint'],
                    'output_format' => $extracted['output_format'],
                    'context_refs' => $extracted['context_refs'],
                    'risk_class' => $extracted['risk_class'],
                    'risk_markers' => $extracted['risk_markers'],
                    'normalised_text' => $extracted['normalised_text'],
                ]);
                $sc = $compiled['quality_self_check'] ?? null;
                if (! is_array($sc)) {
                    $failures[] = "$label: selfCheck ausente";
                    continue;
                }
                $perVoice[$label] = [
                    'score' => $sc['score'] ?? 0,
                    'issues' => $sc['issues'] ?? [],
                ];
                $score = (float) ($sc['score'] ?? 0);
                if ($score < $minScore) {
                    $failures[] = "$label: score $score < $minScore (issues: ".implode(',', $sc['issues'] ?? []).')';
                }
                if (! empty($sc['lost_negations'])) {
                    $failures[] = "$label: negações perdidas: ".implode('|', $sc['lost_negations']);
                }
                if (! ($sc['has_goal'] ?? false)) {
                    $failures[] = "$label: has_goal=false";
                }
                if (! ($sc['has_expected_output'] ?? false)) {
                    $failures[] = "$label: has_expected_output=false";
                }
            }

            if ($failures !== []) {
                return [
                    'status' => self::STATUS_FAIL,
                    'message' => 'Quality self-check do compiler ficou abaixo do mínimo enterprise.',
                    'details' => [
                        'failures' => $failures,
                        'min_score' => $minScore,
                        'per_voice' => $perVoice,
                    ],
                ];
            }

            return [
                'status' => self::STATUS_PASS,
                'message' => '5 vozes canônicas passaram no quality self-check (score ≥ 0.8 + zero negação perdida).',
                'details' => [
                    'min_score' => $minScore,
                    'per_voice' => $perVoice,
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'Quality self-check lançou exceção.',
                'details' => ['exception' => $e->getMessage()],
            ];
        }
    }
}
