<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Gate\Checks;

/**
 * Grupo Desktop da certificação Vox V6 — manifests de release/visual-smoke,
 * comandos Tauri V6, helpers ambient, defaults de voz, persistência de
 * settings, empacotamento macOS (identifier/mic/entitlement/signing) e a
 * Regression Wall (payload snake_case, tokens em manifests, invariante
 * raw_pcm no Rust, signingIdentity camelCase).
 *
 * Scanners read-only puros — sem deps injetadas. Extraído de
 * VoxV6CertificationService na GOD-DEBULK, métodos verbatim.
 */
final class DesktopChecks
{
    use InspectsVoxSource;

    /**
     * @return array<string, callable(): array<string,mixed>>
     */
    public function checks(): array
    {
        return [
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

            // ── Regression Wall (desktop/rust/manifests/signing) ───────
            'regression_wall_payload_session_id_root' => fn () => $this->checkRegressionPayloadSessionIdRoot(),
            'regression_wall_no_tokens_in_manifests' => fn () => $this->checkRegressionNoTokensInManifests(),
            'regression_wall_raw_pcm_invariant_in_rust' => fn () => $this->checkRegressionRawPcmInvariantInRust(),
            'regression_wall_signing_identity_camel_case' => fn () => $this->checkRegressionSigningIdentityCamelCase(),
        ];
    }

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
}
