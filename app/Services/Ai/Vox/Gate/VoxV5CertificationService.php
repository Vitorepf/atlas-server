<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Gate;

use App\Services\Ai\Vox\Interlocutor\VoxInterlocutorPolicy;
use App\Services\Ai\Vox\Routing\VoxAutoModeRouter;
use App\Services\Ai\Vox\VoxSchema;
use Carbon\CarbonImmutable;

/**
 * Atlas Vox V5 · Symbiotic Interlocutor — read-only certification.
 *
 * Devolve um envelope `atlas.vox.v5_certification.v1` indicando se a camada
 * conversacional V5 está pronta para uso real. Nunca grava, nunca executa,
 * nunca chama provider. Nunca destrava V6/V7 sozinha.
 *
 * Cada check é independente e protegido contra exceção: uma falha isolada
 * não mascara outra. Agregação:
 *   - qualquer check.status='fail' → status='fail'
 *   - qualquer check.status='warn' (e nenhum fail) → status='warn'
 *   - caso contrário → 'pass'
 *
 * Hard rules:
 *   - READ-ONLY. Sem migração, sem cache write, sem ledger.
 *   - Nunca toca `app/Services/Ai/Voice/` (Voice Realtime Surface).
 *   - Nunca executa CLI/shell/terminal.
 *   - Nunca chama API paga.
 *   - Tom: tudo em PT-BR no humano; checks técnicos podem ter texto curto
 *     em inglês como rótulo de chave (`check`, `status`), mas a `message`
 *     visível ao operador fica em PT-BR.
 */
final class VoxV5CertificationService
{
    use VoxGateStatusHelper;

    public const SCHEMA = 'atlas.vox.v5_certification.v1';

    public const VERSION = '0.1.0';

    public const STATUS_PASS = 'pass';

    public const STATUS_WARN = 'warn';

    public const STATUS_FAIL = 'fail';

    /**
     * Casos canônicos da política V5. Cada item:
     *   - id: rótulo curto
     *   - text: fala que o operador diria
     *   - mode: VoxMode
     *   - risk_class: R0..R4
     *   - goal/constraints/compiled_prompt/context_refs: estado do intent
     *   - expected_intervention
     *   - expected_blocking
     *   - expected_reason (opcional; null = não checa)
     *
     * Esses mesmos casos viram fixture do
     * {@see VoxInterlocutorPolicy}.
     *
     * @var list<array{
     *   id: string,
     *   text: string,
     *   mode: string,
     *   risk_class: string,
     *   goal: string,
     *   constraints: list<string>,
     *   compiled_prompt: string,
     *   context_refs: list<array{kind:string,ref:?string,resolved:bool}>,
     *   expected_intervention: string,
     *   expected_blocking: bool,
     *   expected_reason: ?string
     * }>
     */
    private const POLICY_CASES = [
        [
            'id' => 'apaga_tudo_blocks',
            'text' => 'apaga tudo dessa pasta de cache',
            'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
            'risk_class' => VoxSchema::RISK_R3,
            'goal' => '',
            'constraints' => [],
            'compiled_prompt' => '',
            'context_refs' => [['kind' => 'none', 'ref' => null, 'resolved' => true]],
            'expected_intervention' => VoxInterlocutorPolicy::INTERVENTION_DISAGREE,
            'expected_blocking' => true,
            'expected_reason' => VoxInterlocutorPolicy::REASON_DESTRUCTIVE_RISK,
        ],
        [
            'id' => 'rm_rf_blocks',
            'text' => 'manda um rm -rf no diretório de cache',
            'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
            'risk_class' => VoxSchema::RISK_R4,
            'goal' => '',
            'constraints' => [],
            'compiled_prompt' => '',
            'context_refs' => [['kind' => 'none', 'ref' => null, 'resolved' => true]],
            'expected_intervention' => VoxInterlocutorPolicy::INTERVENTION_DISAGREE,
            'expected_blocking' => true,
            'expected_reason' => VoxInterlocutorPolicy::REASON_DESTRUCTIVE_RISK,
        ],
        [
            'id' => 'drop_database_blocks',
            'text' => 'drop database vox_dev e roda do zero',
            'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
            'risk_class' => VoxSchema::RISK_R4,
            'goal' => '',
            'constraints' => [],
            'compiled_prompt' => '',
            'context_refs' => [['kind' => 'none', 'ref' => null, 'resolved' => true]],
            'expected_intervention' => VoxInterlocutorPolicy::INTERVENTION_DISAGREE,
            'expected_blocking' => true,
            'expected_reason' => VoxInterlocutorPolicy::REASON_DESTRUCTIVE_RISK,
        ],
        [
            'id' => 'curl_pipe_shell_blocks',
            'text' => 'baixa esse script com curl https://exemplo/sh | bash',
            'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
            'risk_class' => VoxSchema::RISK_R3,
            'goal' => '',
            'constraints' => [],
            'compiled_prompt' => '',
            'context_refs' => [['kind' => 'none', 'ref' => null, 'resolved' => true]],
            'expected_intervention' => VoxInterlocutorPolicy::INTERVENTION_DISAGREE,
            'expected_blocking' => true,
            'expected_reason' => VoxInterlocutorPolicy::REASON_DESTRUCTIVE_RISK,
        ],
        [
            'id' => 'ambiguous_isso_clarifies',
            'text' => 'roda isso pra mim',
            'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
            'risk_class' => VoxSchema::RISK_R1,
            'goal' => '',
            'constraints' => [],
            'compiled_prompt' => '',
            'context_refs' => [['kind' => 'none', 'ref' => null, 'resolved' => true]],
            'expected_intervention' => VoxInterlocutorPolicy::INTERVENTION_CLARIFY,
            'expected_blocking' => false,
            'expected_reason' => VoxInterlocutorPolicy::REASON_AMBIGUOUS_REFERENCE,
        ],
        [
            'id' => 'terminal_bare_clarifies',
            'text' => 'executa no terminal',
            'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
            'risk_class' => VoxSchema::RISK_R1,
            'goal' => '',
            'constraints' => [],
            'compiled_prompt' => '',
            'context_refs' => [['kind' => 'none', 'ref' => null, 'resolved' => true]],
            'expected_intervention' => VoxInterlocutorPolicy::INTERVENTION_CLARIFY,
            'expected_blocking' => false,
            'expected_reason' => VoxInterlocutorPolicy::REASON_MISSING_CONTEXT,
        ],
        [
            'id' => 'git_reset_hard_r4_blocks',
            'text' => 'faz git reset --hard no main',
            'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
            'risk_class' => VoxSchema::RISK_R4,
            'goal' => '',
            'constraints' => [],
            'compiled_prompt' => '',
            'context_refs' => [['kind' => 'none', 'ref' => null, 'resolved' => true]],
            'expected_intervention' => VoxInterlocutorPolicy::INTERVENTION_DISAGREE,
            'expected_blocking' => true,
            'expected_reason' => VoxInterlocutorPolicy::REASON_DESTRUCTIVE_RISK,
        ],
        [
            'id' => 'git_reset_hard_r3_advises_non_blocking',
            'text' => 'faz git reset --hard pra voltar pro último commit',
            'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
            'risk_class' => VoxSchema::RISK_R3,
            'goal' => '',
            'constraints' => [],
            'compiled_prompt' => '',
            'context_refs' => [['kind' => 'none', 'ref' => null, 'resolved' => true]],
            'expected_intervention' => VoxInterlocutorPolicy::INTERVENTION_DISAGREE,
            'expected_blocking' => false,
            'expected_reason' => VoxInterlocutorPolicy::REASON_SAFER_PATH_AVAILABLE,
        ],
        [
            'id' => 'r2_no_marker_caution',
            'text' => 'edita o AuthController e troca a regex',
            'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
            'risk_class' => VoxSchema::RISK_R2,
            'goal' => 'editar AuthController',
            'constraints' => [],
            'compiled_prompt' => 'Editar AuthController e atualizar a regex de validação.',
            'context_refs' => [['kind' => 'none', 'ref' => null, 'resolved' => true]],
            'expected_intervention' => VoxInterlocutorPolicy::INTERVENTION_CAUTION,
            'expected_blocking' => false,
            'expected_reason' => VoxInterlocutorPolicy::REASON_DESTRUCTIVE_RISK,
        ],
        [
            'id' => 'polish_clean_returns_none',
            'text' => 'melhora esse texto que eu vou mandar pro time',
            'mode' => VoxSchema::MODE_PROMPT_POLISH,
            'risk_class' => VoxSchema::RISK_R0,
            'goal' => 'texto profissional pro time',
            'constraints' => [],
            'compiled_prompt' => 'Texto polido pra equipe.',
            'context_refs' => [['kind' => 'none', 'ref' => null, 'resolved' => true]],
            'expected_intervention' => VoxInterlocutorPolicy::INTERVENTION_NONE,
            'expected_blocking' => false,
            'expected_reason' => VoxInterlocutorPolicy::REASON_NONE,
        ],
        [
            'id' => 'strong_intent_returns_none',
            'text' => 'cria um prompt perfeito pro Codex resolver esse bug de auth',
            'mode' => VoxSchema::MODE_INTENT_COMPILE,
            'risk_class' => VoxSchema::RISK_R0,
            'goal' => 'resolver bug de auth',
            'constraints' => [],
            'compiled_prompt' => 'Prompt compilado: resolva o bug de autenticação no AuthController.',
            'context_refs' => [['kind' => 'workspace', 'ref' => 'atlas-server', 'resolved' => true]],
            'expected_intervention' => VoxInterlocutorPolicy::INTERVENTION_NONE,
            'expected_blocking' => false,
            'expected_reason' => VoxInterlocutorPolicy::REASON_NONE,
        ],
        [
            'id' => 'dictation_returns_none',
            'text' => 'anota que amanhã eu preciso passar no banco antes da reunião',
            'mode' => VoxSchema::MODE_DICTATION,
            'risk_class' => VoxSchema::RISK_R0,
            'goal' => '',
            'constraints' => [],
            'compiled_prompt' => '',
            'context_refs' => [['kind' => 'none', 'ref' => null, 'resolved' => true]],
            'expected_intervention' => VoxInterlocutorPolicy::INTERVENTION_NONE,
            'expected_blocking' => false,
            'expected_reason' => VoxInterlocutorPolicy::REASON_NONE,
        ],
        [
            'id' => 'ambiguous_with_resolved_context_is_silent',
            'text' => 'manda o Codex olhar isso',
            'mode' => VoxSchema::MODE_INTENT_COMPILE,
            'risk_class' => VoxSchema::RISK_R0,
            'goal' => 'olhar AuthController',
            'constraints' => [],
            'compiled_prompt' => 'Codex deve analisar AuthController e listar pontos críticos.',
            'context_refs' => [['kind' => 'workspace', 'ref' => 'atlas-server', 'resolved' => true]],
            'expected_intervention' => VoxInterlocutorPolicy::INTERVENTION_NONE,
            'expected_blocking' => false,
            'expected_reason' => VoxInterlocutorPolicy::REASON_NONE,
        ],
    ];

    /**
     * Subconjunto dos POLICY_CASES que precisam terminar em blocking=true.
     * (Hard markers + soft markers em R4.)
     *
     * @var list<string>
     */
    private const BLOCKING_CASE_IDS = [
        'apaga_tudo_blocks',
        'rm_rf_blocks',
        'drop_database_blocks',
        'curl_pipe_shell_blocks',
        'git_reset_hard_r4_blocks',
    ];

    /**
     * Fragmentos em inglês que NÃO podem aparecer em message_pt_br/question_pt_br
     * dos cenários canônicos. Usamos `\b` no regex pra evitar matches dentro
     * de palavras em PT.
     *
     * @var list<string>
     */
    private const ENGLISH_LEAK_PATTERNS = [
        '/\bplease\b/i',
        '/\bsorry\b/i',
        '/\bwarning\b/i',
        '/\berror\b/i',
        '/\bare you sure\b/i',
        '/\bconfirm\b/i',
        '/\bblocked\b/i',
        '/\bsuggested\b/i',
        '/\bdestructive\b/i',
    ];

    public function __construct(
        private readonly VoxInterlocutorPolicy $policy,
        private readonly VoxAutoModeRouter $router,
    ) {}

    /**
     * Build the certification envelope. Pura: chamando duas vezes em
     * sequência produz exatamente o mesmo body (exceto `generated_at`).
     *
     * @return array{
     *   schema: string,
     *   version: string,
     *   status: string,
     *   checks: list<array<string,mixed>>,
     *   summary: array<string,mixed>,
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

        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'status' => $status,
            'checks' => $checks,
            'summary' => $summary,
            'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ];
    }

    /**
     * @return array<string, callable(): array<string,mixed>>
     */
    private function orderedChecks(): array
    {
        return [
            'policy_cases_passed' => fn () => $this->checkPolicyCases(),
            'blocking_cases_passed' => fn () => $this->checkBlockingCases(),
            'intervention_schema_valid' => fn () => $this->checkInterventionSchemaValid(),
            'pt_br_copy_check' => fn () => $this->checkPtBrCopy(),
            'no_voice_realtime_touch' => fn () => $this->checkNoVoiceRealtimeTouch(),
            'no_mobile_touch' => fn () => $this->checkNoMobileTouch(),
            'no_paid_api_dependency' => fn () => $this->checkNoPaidApiDependency(),
            'no_terminal_auto_execute' => fn () => $this->checkNoTerminalAutoExecute(),
            'v4_auto_mode_still_available' => fn () => $this->checkV4AutoModeStillAvailable(),
            'frontend_intervention_rendering_available' => fn () => $this->checkFrontendInterventionRendering(),
            'tests_declared' => fn () => $this->checkTestsDeclared(),
        ];
    }

    /**
     * @return array<string,mixed>
     */

    /**
     * @return array<string,mixed>
     */
    private function checkPolicyCases(): array
    {
        $failures = [];
        foreach (self::POLICY_CASES as $case) {
            $decision = $this->policy->evaluate(
                transcript: ['text' => $case['text']],
                intentPacket: [
                    'mode' => $case['mode'],
                    'risk_class' => $case['risk_class'],
                    'goal' => $case['goal'],
                    'constraints' => $case['constraints'],
                    'compiled_prompt' => $case['compiled_prompt'],
                    'context_refs' => $case['context_refs'],
                ],
            );
            $diff = [];
            if ($decision['intervention'] !== $case['expected_intervention']) {
                $diff['intervention'] = [
                    'expected' => $case['expected_intervention'],
                    'actual' => $decision['intervention'],
                ];
            }
            if (((bool) $decision['blocking']) !== $case['expected_blocking']) {
                $diff['blocking'] = [
                    'expected' => $case['expected_blocking'],
                    'actual' => (bool) $decision['blocking'],
                ];
            }
            if ($case['expected_reason'] !== null
                && $decision['reason_code'] !== $case['expected_reason']
            ) {
                $diff['reason_code'] = [
                    'expected' => $case['expected_reason'],
                    'actual' => $decision['reason_code'],
                ];
            }
            if ($diff !== []) {
                $failures[] = [
                    'case_id' => $case['id'],
                    'text' => $case['text'],
                    'mismatch' => $diff,
                ];
            }
        }
        if ($failures !== []) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => count($failures).' caso(s) canônico(s) da política divergiram do esperado.',
                'details' => [
                    'total_cases' => count(self::POLICY_CASES),
                    'failures' => $failures,
                ],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => count(self::POLICY_CASES).' casos canônicos da política V5 passaram.',
            'details' => ['total_cases' => count(self::POLICY_CASES)],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkBlockingCases(): array
    {
        $failures = [];
        foreach (self::POLICY_CASES as $case) {
            if (! in_array($case['id'], self::BLOCKING_CASE_IDS, true)) {
                continue;
            }
            $decision = $this->policy->evaluate(
                transcript: ['text' => $case['text']],
                intentPacket: [
                    'mode' => $case['mode'],
                    'risk_class' => $case['risk_class'],
                    'goal' => $case['goal'],
                    'constraints' => $case['constraints'],
                    'compiled_prompt' => $case['compiled_prompt'],
                    'context_refs' => $case['context_refs'],
                ],
            );
            if (! (bool) $decision['blocking']) {
                $failures[] = [
                    'case_id' => $case['id'],
                    'text' => $case['text'],
                    'actual_intervention' => $decision['intervention'],
                ];
            }
        }
        if ($failures !== []) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'Caso destrutivo passou sem blocking=true.',
                'details' => [
                    'expected_blocking_count' => count(self::BLOCKING_CASE_IDS),
                    'failures' => $failures,
                ],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => count(self::BLOCKING_CASE_IDS).' marcadores destrutivos retornaram blocking=true.',
            'details' => [
                'blocking_case_ids' => self::BLOCKING_CASE_IDS,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkInterventionSchemaValid(): array
    {
        $sample = $this->policy->evaluate(
            transcript: ['text' => 'manda um rm -rf no diretório de cache'],
            intentPacket: [
                'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                'risk_class' => VoxSchema::RISK_R4,
                'goal' => '',
                'constraints' => [],
                'compiled_prompt' => '',
                'context_refs' => [['kind' => 'none', 'ref' => null, 'resolved' => true]],
            ],
        );
        $expectedKeys = [
            'schema', 'intervention', 'message_pt_br', 'question_pt_br',
            'blocking', 'reason_code', 'suggested_edit', 'policy_version', 'markers',
        ];
        $missing = array_values(array_diff($expectedKeys, array_keys($sample)));
        $schemaOk = ($sample['schema'] ?? '') === VoxSchema::INTERLOCUTOR_DECISION;
        $issues = [];
        if ($missing !== []) {
            $issues['missing_keys'] = $missing;
        }
        if (! $schemaOk) {
            $issues['wrong_schema_id'] = $sample['schema'] ?? null;
        }
        $validInterventions = [
            VoxInterlocutorPolicy::INTERVENTION_NONE,
            VoxInterlocutorPolicy::INTERVENTION_CLARIFY,
            VoxInterlocutorPolicy::INTERVENTION_CAUTION,
            VoxInterlocutorPolicy::INTERVENTION_DISAGREE,
            VoxInterlocutorPolicy::INTERVENTION_SUGGEST_BETTER_PROMPT,
        ];
        if (! in_array($sample['intervention'] ?? null, $validInterventions, true)) {
            $issues['intervention_not_canonical'] = $sample['intervention'] ?? null;
        }
        if ($issues !== []) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'Payload da política V5 não bate com o schema canônico.',
                'details' => ['issues' => $issues],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'Payload da política bate com '.VoxSchema::INTERLOCUTOR_DECISION.'.',
            'details' => [
                'sample_keys' => array_keys($sample),
                'schema_id' => $sample['schema'],
                'policy_version' => $sample['policy_version'] ?? null,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkPtBrCopy(): array
    {
        $leaks = [];
        foreach (self::POLICY_CASES as $case) {
            $decision = $this->policy->evaluate(
                transcript: ['text' => $case['text']],
                intentPacket: [
                    'mode' => $case['mode'],
                    'risk_class' => $case['risk_class'],
                    'goal' => $case['goal'],
                    'constraints' => $case['constraints'],
                    'compiled_prompt' => $case['compiled_prompt'],
                    'context_refs' => $case['context_refs'],
                ],
            );
            $strings = [
                'message_pt_br' => (string) ($decision['message_pt_br'] ?? ''),
                'question_pt_br' => (string) ($decision['question_pt_br'] ?? ''),
            ];
            foreach ($strings as $field => $value) {
                if ($value === '') {
                    continue;
                }
                foreach (self::ENGLISH_LEAK_PATTERNS as $pattern) {
                    if (preg_match($pattern, $value) === 1) {
                        $leaks[] = [
                            'case_id' => $case['id'],
                            'field' => $field,
                            'pattern' => $pattern,
                            'value' => $value,
                        ];
                    }
                }
            }
        }
        if ($leaks !== []) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'Texto em inglês vazando em fala humana — corrija o copy PT-BR.',
                'details' => ['leaks' => $leaks],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'Todos os textos visíveis da política V5 estão em PT-BR.',
            'details' => [
                'cases_scanned' => count(self::POLICY_CASES),
                'patterns_checked' => count(self::ENGLISH_LEAK_PATTERNS),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkNoVoiceRealtimeTouch(): array
    {
        $files = $this->v5SourceFiles();
        $offenders = [];
        foreach ($files as $file) {
            $source = $this->readFile($file);
            if ($source === null) {
                continue;
            }
            if (preg_match('#App\\\\Services\\\\Ai\\\\Voice\\\\#', $source) === 1
                || preg_match('#App/Services/Ai/Voice/#', $source) === 1
                || stripos($source, 'voice_realtime') !== false
            ) {
                // Mero acoplamento de string. Note: o controller cita
                // `voice_realtime_status` em /ai/vox/health — isso é prefixo
                // canônico do Vox V0 e NÃO é uma chamada à Voice Realtime
                // Surface. Esta checagem é feita só nos arquivos V5 abaixo
                // (não inclui o controller); então qualquer match aqui é
                // real.
                $offenders[] = $file;
            }
        }
        if ($offenders !== []) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'V5 não pode importar nem mencionar Voice Realtime Surface.',
                'details' => ['offenders' => $offenders],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'V5 não toca em Voice Realtime Surface.',
            'details' => ['files_scanned' => count($files)],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkNoMobileTouch(): array
    {
        $files = $this->v5SourceFiles();
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
                $offenders[] = $file;
            }
        }
        if ($offenders !== []) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'V5 não pode tocar mobile/atlas-app.',
                'details' => ['offenders' => $offenders],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'V5 não toca mobile/atlas-app.',
            'details' => ['files_scanned' => count($files)],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkNoPaidApiDependency(): array
    {
        $files = $this->v5SourceFiles();
        $forbidden = [
            'Anthropic\\\\',
            'OpenAI\\\\',
            'use Anthropic\\b',
            'use OpenAI\\b',
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
                    $offenders[] = ['file' => $file, 'needle' => $needle];
                }
            }
        }
        if ($offenders !== []) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'V5 não pode depender de API paga (Anthropic/OpenAI/etc).',
                'details' => ['offenders' => $offenders],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'V5 não importa SDK paga nem chama API paga.',
            'details' => ['files_scanned' => count($files)],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkNoTerminalAutoExecute(): array
    {
        $files = $this->v5SourceFiles();
        $offenders = [];
        $forbiddenPatterns = [
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
            foreach ($forbiddenPatterns as $pattern) {
                if (preg_match($pattern, $source) === 1) {
                    $offenders[] = ['file' => $file, 'pattern' => $pattern];
                }
            }
        }
        if ($offenders !== []) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'V5 não pode executar shell direto — apenas o Kernel V3 dispara executor governado.',
                'details' => ['offenders' => $offenders],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'V5 não dispara terminal/shell direto.',
            'details' => ['files_scanned' => count($files)],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkV4AutoModeStillAvailable(): array
    {
        if (! class_exists(VoxAutoModeRouter::class)) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'VoxAutoModeRouter (V4) não está disponível — regressão.',
                'details' => ['expected_class' => VoxAutoModeRouter::class],
            ];
        }
        if (! method_exists($this->router, 'decide')) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'VoxAutoModeRouter::decide() ausente — regressão de assinatura V4.',
                'details' => [],
            ];
        }
        $decision = $this->router->decide(
            transcript: ['text' => 'manda pro codex investigar o módulo Vox'],
            context: [],
        );
        $ok = ($decision['schema'] ?? null) === VoxSchema::AUTO_MODE_DECISION
            && ($decision['selected_mode'] ?? null) === VoxSchema::MODE_INTENT_COMPILE;
        if (! $ok) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'VoxAutoModeRouter mudou de comportamento — V4 quebrado.',
                'details' => [
                    'sample_schema' => $decision['schema'] ?? null,
                    'sample_selected_mode' => $decision['selected_mode'] ?? null,
                ],
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'V4 Auto Mode Router continua disponível e roteia "manda pro codex" para intent_compile.',
            'details' => [
                'schema' => $decision['schema'],
                'selected_mode' => $decision['selected_mode'],
                'router_version' => $decision['router_version'] ?? null,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkFrontendInterventionRendering(): array
    {
        $desktopRoot = $this->desktopRoot();
        if ($desktopRoot === null) {
            return [
                'status' => self::STATUS_WARN,
                'message' => 'Não consegui localizar atlas-desktop ao lado de atlas-server — pulei verificação frontend.',
                'details' => ['searched_at' => dirname(base_path()).'/atlas-desktop'],
            ];
        }
        $required = [
            'apps/desktop/src/lib/voxInterlocutor.ts',
            'apps/desktop/src/components/vox/VoxOverlay.tsx',
            'apps/desktop/src/components/vox/vox.css',
        ];
        $missing = [];
        $cssChecks = [];
        foreach ($required as $rel) {
            $full = $desktopRoot.'/'.$rel;
            if (! is_file($full)) {
                $missing[] = $rel;
            }
        }
        $cssPath = $desktopRoot.'/apps/desktop/src/components/vox/vox.css';
        if (is_file($cssPath)) {
            $css = (string) file_get_contents($cssPath);
            foreach ([
                '.vox-v4-interlocutor',
                '.vox-v4-interlocutor-clarify',
                '.vox-v4-interlocutor-caution',
                '.vox-v4-interlocutor-disagree',
                '.vox-v4-interlocutor-blocking',
                '.vox-v4-interlocutor-suggest_better_prompt',
            ] as $klass) {
                $cssChecks[$klass] = str_contains($css, $klass);
            }
        }
        $overlayPath = $desktopRoot.'/apps/desktop/src/components/vox/VoxOverlay.tsx';
        $overlayHasInterlocutor = is_file($overlayPath)
            && stripos((string) file_get_contents($overlayPath), 'interlocutor') !== false;

        $issues = [];
        if ($missing !== []) {
            $issues['missing_files'] = $missing;
        }
        $missingClasses = array_keys(array_filter($cssChecks, static fn ($v) => $v === false));
        if ($missingClasses !== []) {
            $issues['missing_css_classes'] = $missingClasses;
        }
        if (! $overlayHasInterlocutor) {
            $issues['overlay_missing_reference'] = true;
        }
        if ($issues !== []) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'Renderização frontend da intervenção V5 está incompleta.',
                'details' => $issues,
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'Frontend do Atlas Desktop renderiza intervenção V5 (overlay + css + lib).',
            'details' => [
                'desktop_root' => $desktopRoot,
                'css_classes' => array_keys($cssChecks),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkTestsDeclared(): array
    {
        $phpTest = base_path('tests/Unit/Ai/Vox/VoxInterlocutorPolicyTest.php');
        $controllerTest = base_path('tests/Feature/Ai/Vox/AtlasAiVoxControllerTest.php');
        $found = [
            'unit_policy_test' => is_file($phpTest),
            'feature_controller_test' => is_file($controllerTest),
        ];
        $tsTest = null;
        $desktopRoot = $this->desktopRoot();
        if ($desktopRoot !== null) {
            $candidate = $desktopRoot.'/apps/desktop/src/lib/__tests__/voxInterlocutor.test.ts';
            $tsTest = is_file($candidate);
            $found['frontend_interlocutor_test'] = $tsTest;
        }
        $hasFeatureV5 = false;
        if ($found['feature_controller_test']) {
            $source = (string) file_get_contents($controllerTest);
            $hasFeatureV5 =
                str_contains($source, 'interlocutor')
                || str_contains($source, 'v5_intent_attaches_interlocutor');
            $found['feature_controller_has_v5_assertions'] = $hasFeatureV5;
        }

        $missing = array_keys(array_filter($found, static fn ($v) => $v === false));
        if (in_array('unit_policy_test', $missing, true)) {
            return [
                'status' => self::STATUS_FAIL,
                'message' => 'VoxInterlocutorPolicyTest.php ausente — sem cobertura unitária da política V5.',
                'details' => $found,
            ];
        }
        if (in_array('feature_controller_has_v5_assertions', $missing, true)) {
            return [
                'status' => self::STATUS_WARN,
                'message' => 'Teste feature do controller não cobre intervenção V5 explicitamente.',
                'details' => $found,
            ];
        }
        if (in_array('frontend_interlocutor_test', $missing, true)) {
            return [
                'status' => self::STATUS_WARN,
                'message' => 'voxInterlocutor.test.ts não encontrado no Desktop.',
                'details' => $found,
            ];
        }

        return [
            'status' => self::STATUS_PASS,
            'message' => 'Testes V5 declarados (unit + feature + frontend).',
            'details' => $found,
        ];
    }

    /**
     * Fontes que compõem o comportamento V5 e devem ser escaneadas pelos
     * checks de fronteira (Voice Realtime, mobile, API paga, shell).
     *
     * NÃO incluímos este próprio arquivo: ele lista os patterns proibidos
     * como literais (sudo, OPENAI_API_KEY, …) e auto-matches são falso
     * positivo. Não incluímos tampouco o controller: ele expõe um campo
     * informativo `voice_realtime_status` no /ai/vox/health (canon do Vox)
     * que NÃO é chamada à Voice Realtime Surface; full-text scan ali daria
     * falso positivo. Para o controller existe outro guardrail (testes
     * feature + ADR-0003 boundary).
     *
     * @return list<string>
     */
    private function v5SourceFiles(): array
    {
        return [
            base_path('app/Services/Ai/Vox/Interlocutor/VoxInterlocutorPolicy.php'),
        ];
    }

    private function readFile(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }
        $content = @file_get_contents($path);

        return $content === false ? null : $content;
    }

    private function desktopRoot(): ?string
    {
        $candidate = dirname(base_path()).'/atlas-desktop';

        return is_dir($candidate) ? $candidate : null;
    }

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
        foreach ($checks as $check) {
            $s = (string) ($check['status'] ?? self::STATUS_FAIL);
            $byStatus[$s] = ($byStatus[$s] ?? 0) + 1;
            if ($s === self::STATUS_WARN) {
                $warnings[] = $check['check'];
            } elseif ($s === self::STATUS_FAIL) {
                $failures[] = $check['check'];
            }
        }
        $verdict = match ($status) {
            self::STATUS_PASS => 'V5 pronto para dogfood.',
            self::STATUS_WARN => 'V5 utilizável; revise os pontos de alerta antes de liberar publicamente.',
            default => 'V5 não está pronto — corrija as falhas antes de seguir.',
        };

        return [
            'verdict_pt_br' => $verdict,
            'totals' => $byStatus,
            'warnings' => $warnings,
            'failures' => $failures,
            'checks_count' => count($checks),
        ];
    }
}
