<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Gate\Checks;

use App\Services\Ai\Vox\Interlocutor\VoxInterlocutorPolicy;
use App\Services\Ai\Vox\Routing\VoxAutoModeRouter;
use App\Services\Ai\Vox\VoxIntentExtractor;
use App\Services\Ai\Vox\VoxPromptCompiler;
use App\Services\Ai\Vox\VoxPromptPolisher;
use App\Services\Ai\Vox\VoxSchema;

/**
 * Grupo Backend da certificação Vox V6 — rotas/endpoints, disponibilidade
 * V4/V5, fronteiras de doutrina (sem áudio cru, sem API paga, sem Voice
 * Realtime, sem mobile) e qualidade determinística do compiled_prompt.
 *
 * Extraído de VoxV6CertificationService na GOD-DEBULK — métodos verbatim.
 * O façade resolve este grupo pelo container e mescla `checks()` em build().
 */
final class BackendChecks
{
    use InspectsVoxSource;

    public function __construct(
        private readonly VoxAutoModeRouter $router,
        private readonly VoxInterlocutorPolicy $policy,
    ) {}

    /**
     * @return array<string, callable(): array<string,mixed>>
     */
    public function checks(): array
    {
        return [
            'backend_vox_endpoints_registered' => fn () => $this->checkBackendEndpointsRegistered(),
            'backend_v4_auto_mode_available' => fn () => $this->checkV4AutoModeAvailable(),
            'backend_v5_interlocutor_available' => fn () => $this->checkV5InterlocutorAvailable(),
            'backend_dogfood_endpoint_ok' => fn () => $this->checkDogfoodEndpointOk(),
            'backend_no_raw_audio_persisted' => fn () => $this->checkNoRawAudioPersisted(),
            'backend_no_paid_api_dependency' => fn () => $this->checkNoPaidApiDependency(),
            'backend_no_voice_realtime_touched' => fn () => $this->checkNoVoiceRealtimeTouched(),
            'backend_no_mobile_touched' => fn () => $this->checkNoMobileTouched(),
            'backend_prompt_quality_baseline' => fn () => $this->checkPromptQualityBaseline(),
            'backend_prompt_self_check_score' => fn () => $this->checkPromptSelfCheckScore(),
        ];
    }

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
            $polisher = new VoxPromptPolisher;
            $extractor = new VoxIntentExtractor($polisher);
            $compiler = new VoxPromptCompiler;

            $canonical = VoxPromptCompiler::CANONICAL_SECTIONS;
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
            $polisher = new VoxPromptPolisher;
            $extractor = new VoxIntentExtractor($polisher);
            $compiler = new VoxPromptCompiler;

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
