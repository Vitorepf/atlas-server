<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Gate\Checks;

use App\Services\Ai\Vox\Interlocutor\VoxInterlocutorPolicy;
use App\Services\Ai\Vox\VoxSchema;

/**
 * Grupo Safety + UX da certificação Vox V6 — barreiras de doutrina do
 * runtime (sem auto-execute de terminal, R4 bloqueia, receipt obrigatório,
 * reply helper sem áudio/provider) e as provas de UX PT-BR (sem strings
 * legacy renderizadas, detalhes técnicos colapsados, banner de erro
 * humanizado).
 *
 * Extraído de VoxV6CertificationService na GOD-DEBULK, métodos verbatim.
 */
final class SafetyUxChecks
{
    use InspectsVoxSource;

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
    ) {}

    /**
     * @return array<string, callable(): array<string,mixed>>
     */
    public function checks(): array
    {
        return [
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
}
