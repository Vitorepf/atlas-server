<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Gate;

use App\Services\Ai\Vox\Dogfood\VoxDogfoodSummaryService;
use App\Services\Ai\Vox\Gate\Checks\BackendChecks;
use App\Services\Ai\Vox\Gate\Checks\DesktopChecks;
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
        // GOD-DEBULK · grupos de check extraídos. Opcionais/lazy: quando
        // ausentes (testes que instanciam à mão só com os 3 primeiros), são
        // resolvidos pelo container em runtime — mesmo padrão de summary/v7Gate.
        private readonly ?BackendChecks $backendChecks = null,
        private readonly ?DesktopChecks $desktopChecks = null,
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

    // ── Desktop checks ──────────────────────────────────────────────────

    // ── macOS checks ────────────────────────────────────────────────────

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

    }
