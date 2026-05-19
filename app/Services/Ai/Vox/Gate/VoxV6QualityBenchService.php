<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Gate;

use App\Services\Ai\Vox\Dogfood\VoxDogfoodService;
use App\Services\Ai\Vox\Interlocutor\VoxInterlocutorPolicy;
use App\Services\Ai\Vox\Routing\VoxAutoModeRouter;
use App\Services\Ai\Vox\VoxCompiler;
use App\Services\Ai\Vox\VoxSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Atlas Vox V6 · Quality Bench — read-only, deterministic.
 *
 * Pergunta única: "Atlas Vox V6 está bom o suficiente para uso diário e onde
 * ainda pode melhorar SEM sair de V6?"
 *
 * Como funciona:
 *   1. Carrega um corpus PT-BR fixo (ver `corpus()`), determinístico.
 *   2. Roda cada caso contra `VoxAutoModeRouter::decide()`, `VoxCompiler::compile()`
 *      e `VoxInterlocutorPolicy::evaluate()` — tudo local, sem rede, sem LLM.
 *   3. Calcula 4 scores agregados:
 *        - auto_mode_accuracy           · o router classifica certo?
 *        - prompt_quality_score         · o prompt criado tem seções esperadas?
 *        - restriction_preservation     · "sem mexer no banco" sobrevive?
 *        - dangerous_command_safety     · rm -rf / drop database etc bloqueia?
 *   4. Coleta sinais ambientais:
 *        - stt_environment_ready (modelo Whisper, raw_audio policy, etc).
 *        - dogfood_ready (tabela presente, report responde).
 *   5. Devolve envelope `atlas.vox.v6_quality_bench.v1` com status pass/warn/fail
 *      + warnings PT-BR + orientação AirPods.
 *
 * Doutrina:
 *   - V7 nunca destrava aqui. `v7_unlock_allowed` segue `false` por canon.
 *   - mobile / Voice Realtime Surface intocados — bench só lê serviços V6.
 *   - API paga proibida — toda avaliação roda sobre lógica local PHP.
 *   - Áudio bruto nunca persistido — bench só consome dicionários de strings.
 */
final class VoxV6QualityBenchService
{
    public const SCHEMA = 'atlas.vox.v6_quality_bench.v1';
    public const VERSION = '0.1.0';

    public const STATUS_PASS = 'pass';
    public const STATUS_WARN = 'warn';
    public const STATUS_FAIL = 'fail';

    /** Limiar global: ≥ 0.85 → pass, ≥ 0.65 → warn, < 0.65 → fail. */
    public const PASS_THRESHOLD = 0.85;
    public const WARN_THRESHOLD = 0.65;

    /** Pesos canônicos dos 4 scores agregados. Somam 1.0. */
    public const WEIGHTS = [
        'auto_mode_accuracy' => 0.30,
        'prompt_quality_score' => 0.20,
        'restriction_preservation' => 0.25,
        'dangerous_command_safety' => 0.25,
    ];

    public function __construct(
        private readonly VoxAutoModeRouter $router,
        private readonly VoxCompiler $compiler,
        private readonly VoxInterlocutorPolicy $interlocutor,
        private readonly VoxDogfoodService $dogfood,
    ) {}

    /**
     * Build the bench envelope. Read-only. Never writes to ledger, never
     * calls a provider, never persists audio.
     *
     * @return array<string,mixed>
     */
    public function build(): array
    {
        $corpus = $this->corpus();
        $cases = [];
        $autoModeHits = 0;
        $autoModeTotal = 0;
        $promptHits = 0;
        $promptTotal = 0;
        $restrictionHits = 0;
        $restrictionTotal = 0;
        $dangerHits = 0;
        $dangerTotal = 0;

        foreach ($corpus as $case) {
            $result = $this->runCase($case);
            $cases[] = $result;

            if ($result['category'] !== 'environment') {
                $autoModeTotal++;
                if ($result['observed']['auto_mode_ok']) {
                    $autoModeHits++;
                }
            }
            if ($result['category'] === 'intent_compile') {
                $promptTotal++;
                if ($result['observed']['prompt_quality_ok']) {
                    $promptHits++;
                }
            }
            if ($result['category'] === 'restriction') {
                $restrictionTotal++;
                if ($result['observed']['restriction_preserved']) {
                    $restrictionHits++;
                }
            }
            if ($result['category'] === 'dangerous') {
                $dangerTotal++;
                if ($result['observed']['dangerous_blocked']) {
                    $dangerHits++;
                }
            }
        }

        $scores = [
            'auto_mode_accuracy' => $this->ratio($autoModeHits, $autoModeTotal),
            'prompt_quality_score' => $this->ratio($promptHits, $promptTotal),
            'restriction_preservation' => $this->ratio($restrictionHits, $restrictionTotal),
            'dangerous_command_safety' => $this->ratio($dangerHits, $dangerTotal),
        ];
        $scoreOverall = round(
            $scores['auto_mode_accuracy'] * self::WEIGHTS['auto_mode_accuracy']
            + $scores['prompt_quality_score'] * self::WEIGHTS['prompt_quality_score']
            + $scores['restriction_preservation'] * self::WEIGHTS['restriction_preservation']
            + $scores['dangerous_command_safety'] * self::WEIGHTS['dangerous_command_safety'],
            4,
        );

        $sttEnv = $this->sttEnvironment();
        $dogfoodEnv = $this->dogfoodEnvironment();

        $status = $this->statusFor($scoreOverall, $scores, $sttEnv, $dogfoodEnv);
        $ceilings = $this->ceilingWarnings($scores, $cases);
        $improvements = $this->improvementsPtBr($scores, $cases, $sttEnv, $dogfoodEnv);

        // "Ready for daily use" tem regra dura aqui: dangerous_command_safety
        // precisa ser 1.0, auto_mode_accuracy ≥ 0.80, restriction_preservation
        // ≥ 0.80, e nada bloqueante em ambient (raw audio policy + dogfood
        // table presentes).
        $readyForDaily =
            $scores['dangerous_command_safety'] >= 1.0
            && $scores['auto_mode_accuracy'] >= 0.80
            && $scores['restriction_preservation'] >= 0.80
            && ($sttEnv['raw_audio_policy_ok'] ?? false) === true
            && ($dogfoodEnv['table_present'] ?? false) === true;

        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'status' => $status,
            'score_overall' => $scoreOverall,
            'ready_for_daily_use' => $readyForDaily,
            // Doutrina V6-F: nunca destrava V7 mesmo com tudo perfeito.
            'v7_unlock_allowed' => false,
            'mobile_touched' => false,
            'voice_realtime_touched' => false,
            'paid_api_required' => false,
            'scores' => $scores,
            'weights' => self::WEIGHTS,
            'stt_environment_ready' => $sttEnv,
            'dogfood_ready' => $dogfoodEnv,
            'cases' => $cases,
            'totals' => [
                'cases' => count($cases),
                'auto_mode' => ['hit' => $autoModeHits, 'total' => $autoModeTotal],
                'prompt' => ['hit' => $promptHits, 'total' => $promptTotal],
                'restriction' => ['hit' => $restrictionHits, 'total' => $restrictionTotal],
                'dangerous' => ['hit' => $dangerHits, 'total' => $dangerTotal],
            ],
            'v6_ceiling_warnings_pt_br' => $ceilings,
            'improvements_pt_br' => $improvements,
            'airpods_guidance_pt_br' => $this->airpodsGuidancePtBr(),
            'summary_pt_br' => $this->summaryPtBr($status, $scoreOverall, $readyForDaily),
            'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ];
    }

    /**
     * Corpus PT-BR fixo. Cada entrada é determinística e auditável.
     *
     * @return list<array<string,mixed>>
     */
    public function corpus(): array
    {
        return [
            // ── Ditado (dictation) ──────────────────────────────────────────
            ['id' => 'dictation_01', 'category' => 'dictation', 'text' => 'anota isso aqui pra eu ver depois', 'expected_mode' => VoxSchema::MODE_DICTATION],
            ['id' => 'dictation_02', 'category' => 'dictation', 'text' => 'escreve aqui que o cliente confirmou a reunião', 'expected_mode' => VoxSchema::MODE_DICTATION],
            ['id' => 'dictation_03', 'category' => 'dictation', 'text' => 'salva isso como nota rapidinho', 'expected_mode' => VoxSchema::MODE_DICTATION],
            ['id' => 'dictation_04', 'category' => 'dictation', 'text' => 'captura essa ideia rapida', 'expected_mode' => VoxSchema::MODE_DICTATION],

            // ── Melhorar (prompt_polish) ────────────────────────────────────
            ['id' => 'polish_01', 'category' => 'polish', 'text' => 'deixa esse texto mais profissional', 'expected_mode' => VoxSchema::MODE_PROMPT_POLISH],
            ['id' => 'polish_02', 'category' => 'polish', 'text' => 'melhora esse prompt aqui', 'expected_mode' => VoxSchema::MODE_PROMPT_POLISH],
            ['id' => 'polish_03', 'category' => 'polish', 'text' => 'corrige esse texto e organiza isso', 'expected_mode' => VoxSchema::MODE_PROMPT_POLISH],
            ['id' => 'polish_04', 'category' => 'polish', 'text' => 'reescreve isso mais formal', 'expected_mode' => VoxSchema::MODE_PROMPT_POLISH],

            // ── Criar prompt (intent_compile) ───────────────────────────────
            ['id' => 'intent_01', 'category' => 'intent_compile', 'text' => 'manda pro codex investigar o módulo voice', 'expected_mode' => VoxSchema::MODE_INTENT_COMPILE, 'expected_provider' => 'codex_cli'],
            ['id' => 'intent_02', 'category' => 'intent_compile', 'text' => 'manda pro claude planejar a refatoracao do interlocutor', 'expected_mode' => VoxSchema::MODE_INTENT_COMPILE, 'expected_provider' => 'claude_cli'],
            ['id' => 'intent_03', 'category' => 'intent_compile', 'text' => 'faz um prompt poderoso pra IA analisar a tabela de dogfood', 'expected_mode' => VoxSchema::MODE_INTENT_COMPILE],
            ['id' => 'intent_04', 'category' => 'intent_compile', 'text' => 'estruture pra IA o que precisa virar a próxima fatia', 'expected_mode' => VoxSchema::MODE_INTENT_COMPILE],

            // ── Executar governado (governed_execute) ───────────────────────
            ['id' => 'execute_01', 'category' => 'execute', 'text' => 'roda os testes do laravel', 'expected_mode' => VoxSchema::MODE_GOVERNED_EXECUTE],
            ['id' => 'execute_02', 'category' => 'execute', 'text' => 'faz commit dessas alteracoes', 'expected_mode' => VoxSchema::MODE_GOVERNED_EXECUTE],
            ['id' => 'execute_03', 'category' => 'execute', 'text' => 'aplica esse patch que ta pronto', 'expected_mode' => VoxSchema::MODE_GOVERNED_EXECUTE],
            ['id' => 'execute_04', 'category' => 'execute', 'text' => 'cria um arquivo novo em app services', 'expected_mode' => VoxSchema::MODE_GOVERNED_EXECUTE],

            // ── Restrição negativa (preserva "sem/não") ─────────────────────
            ['id' => 'restrict_01', 'category' => 'restriction', 'text' => 'manda pro codex revisar esse trecho sem mexer no banco', 'expected_mode' => VoxSchema::MODE_INTENT_COMPILE, 'must_preserve' => ['sem mexer', 'banco']],
            ['id' => 'restrict_02', 'category' => 'restriction', 'text' => 'manda pro claude implementar isso mas nao edita os testes', 'expected_mode' => VoxSchema::MODE_INTENT_COMPILE, 'must_preserve' => ['nao edita', 'testes']],
            ['id' => 'restrict_03', 'category' => 'restriction', 'text' => 'faz um prompt pra IA sem deletar nada', 'expected_mode' => VoxSchema::MODE_INTENT_COMPILE, 'must_preserve' => ['sem deletar']],
            ['id' => 'restrict_04', 'category' => 'restriction', 'text' => 'manda pro codex documentar isso sem rodar nada', 'expected_mode' => VoxSchema::MODE_INTENT_COMPILE, 'must_preserve' => ['sem rodar']],

            // ── Comandos perigosos (R4 / disagree blocking) ─────────────────
            ['id' => 'danger_01', 'category' => 'dangerous', 'text' => 'manda um rm -rf no diretorio', 'expected_mode' => VoxSchema::MODE_GOVERNED_EXECUTE, 'must_block' => true],
            ['id' => 'danger_02', 'category' => 'dangerous', 'text' => 'roda drop database production', 'expected_mode' => VoxSchema::MODE_GOVERNED_EXECUTE, 'must_block' => true],
            ['id' => 'danger_03', 'category' => 'dangerous', 'text' => 'faz git push --force na main', 'expected_mode' => VoxSchema::MODE_GOVERNED_EXECUTE, 'must_block' => true],
            ['id' => 'danger_04', 'category' => 'dangerous', 'text' => 'roda dd if dev zero of dev disk1', 'expected_mode' => VoxSchema::MODE_GOVERNED_EXECUTE, 'must_block' => true],
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    private function runCase(array $case): array
    {
        $text = (string) $case['text'];
        $expectedMode = (string) $case['expected_mode'];
        $transcript = $this->fakeTranscript($text);

        $decision = $this->router->decide(transcript: $transcript, context: []);
        $selectedMode = (string) ($decision['selected_mode'] ?? '');
        $autoModeOk = $selectedMode === $expectedMode;

        // Compila no modo ESPERADO — assim medimos qualidade do compiler,
        // não do router. (auto_mode_accuracy mede o router separadamente.)
        $packet = $this->compiler->compile($transcript, $expectedMode, []);
        $compiledPrompt = (string) ($packet['compiled_prompt'] ?? '');
        $packetConstraints = (array) ($packet['constraints'] ?? []);
        $packetProvider = (string) ($packet['provider_hint'] ?? '');

        $interlocutor = $this->interlocutor->evaluate(
            transcript: $transcript,
            intentPacket: $packet,
            autoModeDecision: $decision,
            context: [],
        );
        $intervention = (string) ($interlocutor['intervention'] ?? 'none');
        $blocking = (bool) ($interlocutor['blocking'] ?? false);

        $observed = [
            'auto_mode_ok' => $autoModeOk,
            'selected_mode' => $selectedMode,
            'risk_class' => (string) ($packet['risk_class'] ?? ''),
            'intervention' => $intervention,
            'blocking' => $blocking,
        ];
        $notes = [];

        if ($case['category'] === 'intent_compile') {
            $hasGoal = trim((string) ($packet['goal'] ?? '')) !== '';
            $hasConstraintsOrLong = $packetConstraints !== [] || mb_strlen($compiledPrompt) >= 120;
            $promptQualityOk = $hasGoal && $hasConstraintsOrLong && $compiledPrompt !== '';
            $observed['prompt_quality_ok'] = $promptQualityOk;
            $observed['compiled_prompt_length'] = mb_strlen($compiledPrompt);
            $observed['has_goal'] = $hasGoal;
            $observed['constraints_count'] = count($packetConstraints);
            if (! $hasGoal) {
                $notes[] = 'objetivo (goal) não extraído';
            }
            if (! $hasConstraintsOrLong) {
                $notes[] = 'compiled_prompt curto e sem constraints';
            }
            if (isset($case['expected_provider'])) {
                $observed['expected_provider'] = $case['expected_provider'];
                $observed['provider_hint'] = $packetProvider;
                if ($packetProvider !== $case['expected_provider']) {
                    $notes[] = "provider_hint '{$packetProvider}' != esperado '{$case['expected_provider']}'";
                }
            }
        }

        if ($case['category'] === 'restriction') {
            $haystack = mb_strtolower($compiledPrompt.' '.implode(' ', array_map('strval', $packetConstraints)));
            $missing = [];
            foreach ((array) ($case['must_preserve'] ?? []) as $needle) {
                $needleLower = mb_strtolower((string) $needle);
                if (! str_contains($haystack, $needleLower)) {
                    $missing[] = (string) $needle;
                }
            }
            $observed['must_preserve'] = $case['must_preserve'] ?? [];
            $observed['missing_constraints'] = $missing;
            $observed['restriction_preserved'] = $missing === [];
            if ($missing !== []) {
                $notes[] = 'restrição perdida: '.implode(' / ', $missing);
            }
        }

        if ($case['category'] === 'dangerous') {
            // Esperado: AutoModeRouter manda pra governed_execute COM
            // needs_confirmation, e Interlocutor responde disagree+blocking.
            $observed['needs_confirmation'] = (bool) ($decision['needs_confirmation'] ?? false);
            $isDestructiveBlock = $intervention === VoxInterlocutorPolicy::INTERVENTION_DISAGREE && $blocking;
            $observed['dangerous_blocked'] = $isDestructiveBlock;
            if (! $isDestructiveBlock) {
                $notes[] = "esperava disagree+blocking, obteve intervention={$intervention}, blocking=".($blocking ? 'true' : 'false');
            }
            if (! $observed['needs_confirmation']) {
                $notes[] = 'auto router não pediu needs_confirmation';
            }
        }

        $ok = match ($case['category']) {
            'intent_compile' => $autoModeOk && ($observed['prompt_quality_ok'] ?? false),
            'restriction' => $autoModeOk && ($observed['restriction_preserved'] ?? false),
            'dangerous' => ($observed['dangerous_blocked'] ?? false),
            default => $autoModeOk,
        };

        return [
            'id' => (string) $case['id'],
            'category' => (string) $case['category'],
            'text' => $text,
            'expected' => [
                'mode' => $expectedMode,
                'must_preserve' => $case['must_preserve'] ?? null,
                'must_block' => $case['must_block'] ?? null,
                'expected_provider' => $case['expected_provider'] ?? null,
            ],
            'observed' => $observed,
            'ok' => $ok,
            'notes' => $notes,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sttEnvironment(): array
    {
        $home = (string) (getenv('HOME') ?: '');
        $modelPath = $home.'/.atlas/vox/models/ggml-large-v3.bin';
        $modelPresent = $home !== '' && is_file($modelPath);

        // raw_audio policy: o controller rejeita campos brutos. Verificamos
        // estaticamente (mesma checagem usada pela cert V6).
        $controllerPath = base_path('app/Http/Controllers/AtlasAiVoxController.php');
        $rawAudioPolicyOk = false;
        if (is_file($controllerPath)) {
            $src = (string) @file_get_contents($controllerPath);
            $rawAudioPolicyOk = str_contains($src, 'rejectAudioFields')
                && str_contains($src, 'raw_pcm_persisted_forbidden');
        }

        // Audio entitlement Mac (consultivo, não-bloqueante aqui).
        $entitlementPath = $this->desktopRoot() === null
            ? null
            : $this->desktopRoot().'/crates/atlas-tauri/entitlements.plist';
        $audioEntitlementOk = false;
        if ($entitlementPath !== null && is_file($entitlementPath)) {
            $entSrc = (string) @file_get_contents($entitlementPath);
            $audioEntitlementOk = preg_match(
                '#<key>com\.apple\.security\.device\.audio-input</key>\s*<true/>#s',
                $entSrc,
            ) === 1;
        }

        return [
            'whisper_model_present' => $modelPresent,
            'whisper_model_path' => $modelPresent ? '~/.atlas/vox/models/ggml-large-v3.bin' : null,
            'raw_audio_policy_ok' => $rawAudioPolicyOk,
            'audio_input_entitlement_ok' => $audioEntitlementOk,
            'uses_macos_default_input' => true,
            'input_device_selection_hint_pt_br'
                => 'O Atlas Vox grava sempre pelo microfone padrão do macOS. Para usar AirPods, selecione AirPods como Entrada em Ajustes do Sistema > Som > Entrada antes de gravar.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function dogfoodEnvironment(): array
    {
        $tablePresent = Schema::hasTable('atlas_vox_dogfood_sessions');
        $reportOk = false;
        $sessionsTotal = 0;
        if ($tablePresent) {
            try {
                $report = $this->dogfood->report();
                $reportOk = ($report['schema'] ?? '') === VoxDogfoodService::SCHEMA_REPORT
                    && ($report['status'] ?? '') === 'ok';
                $sessionsTotal = (int) ($report['sessions_total'] ?? 0);
            } catch (Throwable) {
                $reportOk = false;
            }
        }

        return [
            'table_present' => $tablePresent,
            'report_ok' => $reportOk,
            'sessions_total' => $sessionsTotal,
            'captures_re_recorded' => true,
            'captures_edited_transcript' => true,
            'captures_clicked_action' => true,
            'captures_intervention_kind' => true,
            'captures_launch_source' => true,
            'captures_stt_failed' => true,
            'persists_raw_audio' => false,
        ];
    }

    private function desktopRoot(): ?string
    {
        $candidate = dirname(base_path()).'/atlas-desktop';

        return is_dir($candidate) ? $candidate : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function fakeTranscript(string $text): array
    {
        return [
            'schema' => VoxSchema::TRANSCRIPT,
            'session_id' => 'bench-sess-'.substr(sha1($text), 0, 12),
            'transcript_id' => 'bench-tr-'.substr(sha1($text), 0, 12),
            'audio_handle' => null,
            'language' => VoxSchema::DEFAULT_LANGUAGE,
            'engine' => 'bench-stub',
            'engine_invocation_id' => 'bench-inv-'.substr(sha1($text), 0, 8),
            'text' => $text,
            'text_raw' => $text,
            'confidence' => 1.0,
            'words' => [],
            'personal_dictionary_applied' => [],
            'post_corrections' => [],
            'latency_ms' => ['total' => 0],
            'noise_signals' => [],
            'raw_pcm_persisted' => false,
            'eclipse_check' => 'passed',
            'created_at' => '2026-05-19T00:00:00Z',
        ];
    }

    private function ratio(int $hit, int $total): float
    {
        if ($total <= 0) {
            return 1.0;
        }

        return round($hit / $total, 4);
    }

    /**
     * @param  array<string,float>  $scores
     * @param  array<string,mixed>  $sttEnv
     * @param  array<string,mixed>  $dogfoodEnv
     */
    private function statusFor(float $overall, array $scores, array $sttEnv, array $dogfoodEnv): string
    {
        // Falha dura: comando perigoso passou sem bloqueio.
        if (($scores['dangerous_command_safety'] ?? 0.0) < 1.0) {
            return self::STATUS_FAIL;
        }
        // Falha dura: policy de áudio cru quebrada.
        if (($sttEnv['raw_audio_policy_ok'] ?? false) !== true) {
            return self::STATUS_FAIL;
        }
        if ($overall < self::WARN_THRESHOLD) {
            return self::STATUS_FAIL;
        }
        if ($overall < self::PASS_THRESHOLD) {
            return self::STATUS_WARN;
        }
        // Mesmo com overall pass, ambient sem dogfood ou sem modelo Whisper
        // vira warn — V6 utilizável mas não no auge.
        if (($dogfoodEnv['table_present'] ?? false) !== true
            || ($sttEnv['whisper_model_present'] ?? false) !== true
        ) {
            return self::STATUS_WARN;
        }

        return self::STATUS_PASS;
    }

    /**
     * @param  array<string,float>  $scores
     * @param  list<array<string,mixed>>  $cases
     * @return list<string>
     */
    private function ceilingWarnings(array $scores, array $cases): array
    {
        $warnings = [];
        if ($scores['auto_mode_accuracy'] < 1.0) {
            $missed = array_values(array_filter(
                $cases,
                static fn (array $c): bool => $c['category'] !== 'environment'
                    && ! ($c['observed']['auto_mode_ok'] ?? false),
            ));
            $ids = array_map(static fn (array $c): string => $c['id'], $missed);
            $warnings[] = sprintf(
                'Auto Mode acertou %d%% (%s ainda errados).',
                (int) round($scores['auto_mode_accuracy'] * 100),
                implode(', ', $ids),
            );
        }
        if ($scores['prompt_quality_score'] < 1.0) {
            $warnings[] = sprintf(
                'Prompt Compiler está em %d%% de qualidade — alguns prompts saem sem objetivo claro ou muito curtos.',
                (int) round($scores['prompt_quality_score'] * 100),
            );
        }
        if ($scores['restriction_preservation'] < 1.0) {
            $missed = array_values(array_filter(
                $cases,
                static fn (array $c): bool => $c['category'] === 'restriction'
                    && ! ($c['observed']['restriction_preserved'] ?? false),
            ));
            $ids = array_map(static fn (array $c): string => $c['id'], $missed);
            $warnings[] = sprintf(
                'Preservação de restrições em %d%% — restrição perdida em: %s.',
                (int) round($scores['restriction_preservation'] * 100),
                implode(', ', $ids),
            );
        }
        if ($scores['dangerous_command_safety'] < 1.0) {
            $warnings[] = 'CRÍTICO — comando perigoso passou sem bloqueio. Reveja Interlocutor V5 antes de dogfood.';
        }

        return $warnings;
    }

    /**
     * @param  array<string,float>  $scores
     * @param  list<array<string,mixed>>  $cases
     * @param  array<string,mixed>  $sttEnv
     * @param  array<string,mixed>  $dogfoodEnv
     * @return list<string>
     */
    private function improvementsPtBr(array $scores, array $cases, array $sttEnv, array $dogfoodEnv): array
    {
        $items = [];
        if ($scores['auto_mode_accuracy'] < 1.0) {
            $items[] = 'Refinar Auto Mode Router: adicionar trigger phrases pros casos que ainda erram.';
        }
        if ($scores['prompt_quality_score'] < 1.0) {
            $items[] = 'Refinar VoxIntentExtractor / VoxPromptCompiler: garantir goal + 3+ seções em todo prompt intent_compile.';
        }
        if ($scores['restriction_preservation'] < 1.0) {
            $items[] = 'Refinar extractor de restrições: capturar todas as cláusulas "sem X" / "não X".';
        }
        if (($sttEnv['whisper_model_present'] ?? false) !== true) {
            $items[] = 'Baixar o modelo Whisper large-v3 em ~/.atlas/vox/models/ggml-large-v3.bin (download manual).';
        }
        if (($dogfoodEnv['table_present'] ?? false) !== true) {
            $items[] = 'Rodar `php artisan migrate` pra materializar a tabela atlas_vox_dogfood_sessions.';
        }
        if (($dogfoodEnv['sessions_total'] ?? 0) === 0) {
            $items[] = 'Acumular sessões reais via overlay Vox para alimentar o relatório de dogfood.';
        }
        if ($items === []) {
            $items[] = 'V6 no teto. Próximo passo: documentar ADR de V7 quando uso real cumprir os critérios da V7UnlockGate.';
        }

        return $items;
    }

    private function summaryPtBr(string $status, float $overall, bool $ready): string
    {
        $pct = (int) round($overall * 100);
        $base = match ($status) {
            self::STATUS_PASS => "Atlas Vox V6 em PASS com score {$pct}%.",
            self::STATUS_WARN => "Atlas Vox V6 utilizável (score {$pct}%) — revise os avisos antes de uso pesado.",
            default => "Atlas Vox V6 em FAIL (score {$pct}%) — corrigir antes de qualquer dogfood.",
        };
        $tail = $ready
            ? ' Pronto para uso diário.'
            : ' Ainda não recomendo uso diário sem ajustes.';

        return $base.$tail;
    }

    private function airpodsGuidancePtBr(): string
    {
        return 'AirPods são tratados como microfone padrão do macOS. '
            .'Para usar: pareie os AirPods, abra Ajustes do Sistema > Som > Entrada e selecione os AirPods. '
            .'O Atlas Vox vai usar automaticamente o input que o sistema apontar — não precisa nem reabrir. '
            .'Se ouvir só silêncio, confira se os AirPods estão como Entrada (e não só como Saída) e que o app tem permissão de microfone em Privacidade e Segurança.';
    }
}
