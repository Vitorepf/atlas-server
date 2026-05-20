<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Gate;

use App\Services\Ai\Vox\Governor\VoxCognitiveFlowGovernor;
use App\Services\Ai\Vox\VoxSchema;
use Carbon\CarbonImmutable;

/**
 * Atlas Vox V6.8 · Cognitive Flow Governor certification.
 *
 * Read-only. Prova que a camada V6.8 existe, é local-only, bloqueia R4,
 * pede contexto quando ambígua, mantém V7 fechado e não depende de mobile,
 * Voice Realtime ou API paga.
 */
final class VoxV68CertificationService
{
    public const SCHEMA = 'atlas.vox.v6_8_certification.v1';
    public const VERSION = '0.1.0';

    public const STATUS_PASS = 'pass';
    public const STATUS_WARN = 'warn';
    public const STATUS_FAIL = 'fail';

    public function __construct(
        private readonly VoxCognitiveFlowGovernor $governor,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function build(): array
    {
        $checks = [
            $this->checkSchemaConstants(),
            $this->checkSafeDictationPolicy(),
            $this->checkAmbiguityStopsAction(),
            $this->checkPromptCompileQualityPolicy(),
            $this->checkR4Blocks(),
            $this->checkCompositeRiskStepByStep(),
            $this->checkLocalOnlyGuards(),
            $this->checkControllerWiring(),
            $this->checkDoctrineBoundaries(),
        ];

        $status = $this->aggregate($checks);

        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'status' => $status,
            'name' => 'Atlas Vox V6.8 · Cognitive Flow Governor',
            'v6_8_ready' => $status !== self::STATUS_FAIL,
            'v7_unlock_allowed' => false,
            'checks' => $checks,
            'summary' => $this->summary($checks),
            'next_actions' => $status === self::STATUS_PASS
                ? ['Usar V6.8 no fluxo diário e registrar dogfood real quando houver atrito.']
                : ['Corrigir os checks fail antes de considerar V6.8 pronta.'],
            'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkSchemaConstants(): array
    {
        return $this->check(
            'schema_constants',
            VoxSchema::COGNITIVE_FLOW_GOVERNOR === 'atlas.vox.cognitive_flow_governor.v1'
                && VoxSchema::COGNITIVE_FLOW_GOVERNOR_VERSION !== '',
            'Schema V6.8 canônico disponível.',
            [
                'schema' => VoxSchema::COGNITIVE_FLOW_GOVERNOR,
                'version' => VoxSchema::COGNITIVE_FLOW_GOVERNOR_VERSION,
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkSafeDictationPolicy(): array
    {
        $g = $this->governor->govern(
            transcript: ['text' => 'anota esse texto'],
            intentPacket: ['mode' => VoxSchema::MODE_DICTATION, 'risk_class' => VoxSchema::RISK_R0],
            flowDecision: [
                'mode' => VoxSchema::MODE_DICTATION,
                'destination' => 'clipboard',
                'risk_class' => VoxSchema::RISK_R0,
                'confidence' => 'high',
                'needs_clarification' => false,
            ],
        );

        return $this->check(
            'safe_dictation_policy',
            ($g['execution']['policy'] ?? null) === 'single_safe_action'
                && ($g['context']['missing'] ?? true) === false
                && ($g['risk']['requires_confirmation'] ?? true) === false,
            'Ditado simples segue seguro e direto.',
            $g,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkAmbiguityStopsAction(): array
    {
        $g = $this->governor->govern(
            transcript: ['text' => 'executa isso'],
            intentPacket: ['mode' => VoxSchema::MODE_GOVERNED_EXECUTE, 'risk_class' => VoxSchema::RISK_R1],
            flowDecision: [
                'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                'destination' => 'none',
                'risk_class' => VoxSchema::RISK_R1,
                'confidence' => 'medium',
                'needs_clarification' => true,
                'clarifying_question' => 'O que exatamente você quer executar?',
            ],
        );

        return $this->check(
            'ambiguity_stops_action',
            ($g['execution']['policy'] ?? null) === 'no_action'
                && in_array('ask', (array) ($g['execution']['allowed_actions'] ?? []), true),
            'Ambiguidade vira pergunta, não ação.',
            $g,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkPromptCompileQualityPolicy(): array
    {
        $g = $this->governor->govern(
            transcript: ['text' => 'cria um prompt pro Codex investigar o Atlas Vox sem editar nada'],
            intentPacket: [
                'mode' => VoxSchema::MODE_INTENT_COMPILE,
                'risk_class' => VoxSchema::RISK_R1,
                'goal' => 'Investigar Atlas Vox sem editar nada',
                'provider_hint' => 'codex_cli',
            ],
            flowDecision: [
                'mode' => VoxSchema::MODE_INTENT_COMPILE,
                'destination' => 'codex',
                'risk_class' => VoxSchema::RISK_R1,
                'confidence' => 'high',
                'needs_clarification' => false,
            ],
            promptQuality: ['status' => 'pass', 'issues' => []],
        );

        return $this->check(
            'prompt_compile_quality_policy',
            ($g['quality']['prompt_quality_required'] ?? false) === true
                && ($g['quality']['needs_review'] ?? true) === false
                && ($g['flow']['destination'] ?? '') === 'codex',
            'Criação de prompt exige quality gate e preserva destino.',
            $g,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkR4Blocks(): array
    {
        $g = $this->governor->govern(
            transcript: ['text' => 'rm -rf no projeto'],
            intentPacket: [
                'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                'risk_class' => VoxSchema::RISK_R4,
            ],
            flowDecision: [
                'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                'destination' => 'terminal_proposal',
                'risk_class' => VoxSchema::RISK_R4,
                'confidence' => 'high',
                'needs_clarification' => false,
            ],
        );

        return $this->check(
            'r4_blocks',
            ($g['execution']['policy'] ?? null) === 'blocked'
                && ($g['risk']['blocked'] ?? false) === true
                && ($g['guards']['terminal_execute'] ?? true) === false,
            'R4 fica bloqueado no governador.',
            $g,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkCompositeRiskStepByStep(): array
    {
        $g = $this->governor->govern(
            transcript: ['text' => 'cria o prompt e depois roda teste'],
            intentPacket: [
                'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                'risk_class' => VoxSchema::RISK_R3,
                'goal' => 'Criar prompt e rodar teste',
            ],
            flowDecision: [
                'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                'destination' => 'codex',
                'risk_class' => VoxSchema::RISK_R3,
                'confidence' => 'high',
                'needs_clarification' => false,
                'composite' => [
                    'execution_policy' => VoxSchema::COMPOSITE_POLICY_STEP_BY_STEP,
                    'steps' => [['summary' => 'Criar prompt'], ['summary' => 'Rodar teste']],
                ],
            ],
        );

        return $this->check(
            'composite_risk_step_by_step',
            ($g['execution']['policy'] ?? null) === 'step_by_step_confirmation'
                && ($g['risk']['requires_confirmation'] ?? false) === true,
            'Pedidos compostos com risco viram confirmação passo a passo.',
            $g,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkLocalOnlyGuards(): array
    {
        $g = $this->governor->govern(
            transcript: ['text' => 'teste local'],
            intentPacket: ['mode' => VoxSchema::MODE_DICTATION, 'risk_class' => VoxSchema::RISK_R0],
            flowDecision: [
                'mode' => VoxSchema::MODE_DICTATION,
                'destination' => 'clipboard',
                'risk_class' => VoxSchema::RISK_R0,
                'confidence' => 'high',
                'needs_clarification' => false,
            ],
        );
        $guards = (array) ($g['guards'] ?? []);

        return $this->check(
            'local_only_guards',
            ($g['local_only'] ?? false) === true
                && ($guards['raw_audio_accepted'] ?? true) === false
                && ($guards['cloud_stt'] ?? true) === false
                && ($guards['paid_api_required'] ?? true) === false
                && ($guards['voice_realtime_touched'] ?? true) === false
                && ($guards['mobile_touched'] ?? true) === false,
            'Guards locais permanecem fechados.',
            $g,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkControllerWiring(): array
    {
        $path = app_path('Http/Controllers/AtlasAiVoxController.php');
        $source = is_file($path) ? (string) file_get_contents($path) : '';

        return $this->check(
            'controller_wiring',
            str_contains($source, 'VoxCognitiveFlowGovernor')
                && str_contains($source, "'cognitive_flow_governor' =>"),
            '/ai/vox/intent expõe cognitive_flow_governor.',
            ['path' => $path],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkDoctrineBoundaries(): array
    {
        $path = app_path('Services/Ai/Vox/Governor/VoxCognitiveFlowGovernor.php');
        $source = is_file($path) ? (string) file_get_contents($path) : '';

        return $this->check(
            'doctrine_boundaries',
            ! str_contains($source, 'LiveKit')
                && ! str_contains($source, 'OpenAI')
                && ! str_contains($source, 'Anthropic')
                && ! str_contains($source, 'atlas-app'),
            'Governador não contém dependência de Voice RT, mobile, provider pago ou áudio bruto.',
            ['path' => $path],
        );
    }

    /**
     * @param  array<string,mixed>  $details
     * @return array<string,mixed>
     */
    private function check(string $id, bool $passed, string $message, array $details = []): array
    {
        return [
            'check' => $id,
            'status' => $passed ? self::STATUS_PASS : self::STATUS_FAIL,
            'message' => $message,
            'details' => $details,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     */
    private function aggregate(array $checks): string
    {
        foreach ($checks as $check) {
            if (($check['status'] ?? '') === self::STATUS_FAIL) {
                return self::STATUS_FAIL;
            }
        }

        return self::STATUS_PASS;
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     * @return array<string,mixed>
     */
    private function summary(array $checks): array
    {
        $pass = 0;
        $fail = 0;
        $failures = [];
        foreach ($checks as $check) {
            if (($check['status'] ?? '') === self::STATUS_PASS) {
                $pass++;
            } else {
                $fail++;
                $failures[] = (string) ($check['check'] ?? '?');
            }
        }

        return [
            'totals' => ['pass' => $pass, 'warn' => 0, 'fail' => $fail],
            'failures' => $failures,
            'verdict_pt_br' => $fail === 0
                ? 'V6.8 pronta: fluxo inteligente final ativo, local e governado.'
                : 'V6.8 ainda não está pronta.',
        ];
    }
}
