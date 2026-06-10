<?php

namespace App\Services\Ai;

use App\Models\AiQualityEvaluation;
use App\Models\AiTrace;
use App\Services\Ai\Kernel\Behavior\AgentBehaviorQualityGate;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\AuditLogService;
use Illuminate\Support\Str;

class AiQualityEvaluator
{
    private const VERSION = 'heuristic-v1';

    public function __construct(
        private readonly AuditLogService $audit,
        private readonly AgentBehaviorQualityGate $agentBehaviorGate,
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    public function evaluateTrace(AiTrace $trace): ?AiQualityEvaluation
    {
        if (! DatabaseTableAvailability::has('ai_quality_evaluations')) {
            return null;
        }

        $trace = $trace->fresh(['qualityEvaluation']) ?: $trace;
        $response = trim((string) $trace->response_text);
        if ($response === '' && $trace->status !== 'succeeded') {
            return null;
        }

        $metadata = $this->arrayValue($trace->metadata);
        $assessment = $this->assess($trace, $response, $metadata);

        /** @var AiQualityEvaluation $evaluation */
        $evaluation = AiQualityEvaluation::query()->updateOrCreate(
            ['trace_id' => $trace->id],
            [
                'thread_id' => $trace->thread_id,
                'session_id' => $trace->session_id,
                'provider' => $trace->provider,
                'model' => $trace->model,
                'agent_slug' => $trace->agent_slug,
                'evaluator_version' => self::VERSION,
                'score' => $assessment['score'],
                'status' => $assessment['status'],
                'dimensions' => $assessment['dimensions'],
                'flags' => $assessment['flags'],
                'suggested_actions' => $assessment['suggested_actions'],
                'metadata' => [
                    'response_chars' => mb_strlen($response),
                    'response_words' => str_word_count($response),
                    'evaluated_at' => now()->toJSON(),
                    'evidence' => $assessment['evidence'],
                ],
            ],
        );

        $trace->update([
            'metadata' => array_merge($metadata, [
                'quality' => [
                    'evaluation_id' => $evaluation->id,
                    'evaluator_version' => self::VERSION,
                    'score' => $evaluation->score,
                    'status' => $evaluation->status,
                    'flags' => collect($assessment['flags'])->pluck('code')->values()->all(),
                    'evaluated_at' => now()->toJSON(),
                ],
            ]),
        ]);

        $this->audit->record('ai_quality_evaluated', [
            'subject_type' => 'ai_trace',
            'subject_id' => $trace->id,
            'severity' => $evaluation->status === 'passed' ? 'info' : 'warning',
            'summary' => "Resposta de IA avaliada com score {$evaluation->score}.",
            'evidence' => [
                'provider' => $trace->provider,
                'agent_slug' => $trace->agent_slug,
                'score' => $evaluation->score,
                'status' => $evaluation->status,
                'flags' => collect($assessment['flags'])->pluck('code')->values()->all(),
                'suggested_actions' => collect($assessment['suggested_actions'])->pluck('code')->values()->all(),
            ],
            'privacy' => $this->arrayValue(data_get($metadata, 'privacy')),
            'refs' => [
                'trace_id' => $trace->id,
                'thread_id' => $trace->thread_id,
                'session_id' => $trace->session_id,
                'quality_evaluation_id' => $evaluation->id,
            ],
        ]);

        $this->recordAgentBehaviorGateEvaluation($trace, $evaluation->refresh(), $assessment);

        return $evaluation->refresh();
    }

    /**
     * @return array{score:int,status:string,dimensions:array<string,int>,flags:array<int,array<string,mixed>>,suggested_actions:array<int,array<string,string>>,evidence:array<string,mixed>}
     */
    private function assess(AiTrace $trace, string $response, array $metadata): array
    {
        $lowerResponse = $this->normalize($response);
        $lowerInput = $this->normalize((string) $trace->operator_input);
        $contextAvailable = $this->hasConversationContext($metadata);
        $taskLooksLikeDevelopment = $this->taskLooksLikeDevelopment($trace, $metadata, $lowerInput);
        $codeFenceCount = substr_count($response, '```');
        $codeLikeLineCount = $this->codeLikeLineCount($response);
        $responseChars = mb_strlen($response);
        $flags = [];
        $suggestedActions = [];

        if ($response === '') {
            $flags[] = $this->flag('empty_response', 'critical', 'A resposta final ficou vazia.');
            $suggestedActions[] = $this->action('rerun_provider_or_fallback', 'Reexecutar com outro provider ou abrir fallback de conselho.');
        }

        if ($this->containsAny($lowerResponse, [
            'context pack atlas',
            'estado operacional atlas',
            'contrato de resposta atlas',
            'contexto mapeado. o atlas tem',
            'execution_plan',
            'task_request',
            'context_refs',
            'context_window',
            'recent_turns',
            'thread_id',
            'session_id',
            'trace_id',
        ])) {
            $flags[] = $this->flag('internal_context_leak', 'high', 'A resposta parece expor metadados internos do harness.');
            $suggestedActions[] = $this->action('tighten_context_silence_contract', 'Reforçar no prompt que contexto interno é para raciocínio, não para exibição.');
        }

        if ($contextAvailable && $this->containsAny($lowerResponse, [
            'não há pergunta anterior',
            'nao ha pergunta anterior',
            'não tenho contexto',
            'nao tenho contexto',
            'não tenho acesso ao contexto',
            'nao tenho acesso ao contexto',
            'não vejo a conversa anterior',
            'nao vejo a conversa anterior',
            'não sei do que você está falando',
            'nao sei do que voce esta falando',
            'precisa de referência',
            'precisa de referencia',
        ])) {
            $flags[] = $this->flag('lost_continuity', 'critical', 'A resposta negou continuidade mesmo havendo contexto de conversa.');
            $suggestedActions[] = $this->action('increase_conversation_context_or_handoff', 'Enviar janela de conversa, compactação e handoff antes de chamar o provider.');
        }

        if (! $this->inputIsAboutProviderIdentity($lowerInput) && $this->containsAny($lowerResponse, [
            'como claude',
            'sou o claude',
            'como codex',
            'sou o codex',
            'como chatgpt',
            'sou o chatgpt',
        ])) {
            $flags[] = $this->flag('provider_identity_leak', 'medium', 'A resposta fala como se o provider fosse o produto final.');
            $suggestedActions[] = $this->action('enforce_atlas_identity', 'Responder sempre como Atlas, tratando provider como motor intercambiável.');
        }

        if ($responseChars > 9000 || $codeFenceCount >= 3 || $codeLikeLineCount >= 18) {
            $flags[] = $this->flag('likely_oververbose_or_code_heavy', 'medium', 'A resposta está longa ou técnica demais para o padrão executivo do Atlas.');
            $suggestedActions[] = $this->action('apply_clear_communicator_skill', 'Reduzir saída, evitar despejo de código e destacar apenas decisões e mudanças.');
        }

        $agentBehaviorFindings = $this->agentBehaviorGate->evaluate([
            'response_text' => $response,
            'task_type' => data_get($metadata, 'task_request.task_type'),
            'task_looks_like_development' => $taskLooksLikeDevelopment,
        ]);

        foreach ($agentBehaviorFindings as $finding) {
            if (($finding['code'] ?? null) === 'agent.verification_missing') {
                $flags[] = $this->flag('verification_missing', 'medium', (string) $finding['body']);
                $suggestedActions[] = $this->action('request_verification_or_tests', (string) $finding['recommendation']);
            }
        }

        $flagCodes = collect($flags)->pluck('code')->all();
        $dimensions = [
            'clarity' => $this->dimension(92, [
                'empty_response' => 92,
                'likely_oververbose_or_code_heavy' => 22,
            ], $flagCodes),
            'continuity' => $this->dimension($contextAvailable ? 94 : 86, [
                'lost_continuity' => 65,
            ], $flagCodes),
            'context_discipline' => $this->dimension(95, [
                'internal_context_leak' => 60,
                'provider_identity_leak' => 25,
            ], $flagCodes),
            'actionability' => $this->dimension($responseChars < 80 ? 70 : 88, [
                'empty_response' => 70,
                'verification_missing' => 12,
            ], $flagCodes),
            'verification' => $this->dimension($taskLooksLikeDevelopment ? 88 : 84, [
                'verification_missing' => 34,
                'empty_response' => 84,
            ], $flagCodes),
        ];

        $score = (int) round(array_sum($dimensions) / max(count($dimensions), 1));
        $score = max(0, min(100, $score));
        $status = match (true) {
            $score >= 80 => 'passed',
            $score >= 55 => 'needs_review',
            default => 'failed',
        };

        return [
            'score' => $score,
            'status' => $status,
            'dimensions' => $dimensions,
            'flags' => $flags,
            'suggested_actions' => array_values(collect($suggestedActions)->unique('code')->all()),
            'evidence' => [
                'context_available' => $contextAvailable,
                'task_looks_like_development' => $taskLooksLikeDevelopment,
                'response_chars' => $responseChars,
                'code_fence_count' => $codeFenceCount,
                'code_like_line_count' => $codeLikeLineCount,
                'agent_behavior_findings' => $agentBehaviorFindings,
            ],
        ];
    }

    private function hasConversationContext(array $metadata): bool
    {
        return filled(data_get($metadata, 'context_pack.conversation.recent_turns'))
            || filled(data_get($metadata, 'context_pack.conversation.latest_compaction'))
            || filled(data_get($metadata, 'context_pack.session_state'))
            || filled(data_get($metadata, 'context_pack.provider_handoff'));
    }

    private function taskLooksLikeDevelopment(AiTrace $trace, array $metadata, string $lowerInput): bool
    {
        $taskType = $this->normalize((string) data_get($metadata, 'task_request.task_type'));
        if (in_array($taskType, ['dev', 'debug', 'review', 'implementation', 'debugging', 'code_review', 'refactor', 'technical_design'], true)) {
            return true;
        }

        return $this->containsAny($lowerInput, [
            'implemente',
            'implementa',
            'corrija',
            'bug',
            'erro',
            'codigo',
            'código',
            'programar',
            'backend',
            'frontend',
            'cli',
            'api',
            'teste',
            'typecheck',
            'deploy',
        ]);
    }

    private function inputIsAboutProviderIdentity(string $lowerInput): bool
    {
        return $this->containsAny($lowerInput, [
            'claude',
            'codex',
            'chatgpt',
            'provider',
            'provedor',
            'modelo',
        ]);
    }

    private function codeLikeLineCount(string $response): int
    {
        preg_match_all(
            '/^\s*(namespace|use\s+[^;]+;|class\s+\w+|function\s+\w+|public\s+|private\s+|protected\s+|return\s+|if\s*\(|foreach\s*\(|Schema::|DB::|Route::|import\s+|export\s+|const\s+\w+\s*=|let\s+|var\s+)/mi',
            $response,
            $matches,
        );

        return count($matches[0] ?? []);
    }

    /**
     * @param  array<int,string>  $flagCodes
     * @param  array<string,int>  $penalties
     */
    private function dimension(int $base, array $penalties, array $flagCodes): int
    {
        foreach ($flagCodes as $flagCode) {
            $base -= $penalties[$flagCode] ?? 0;
        }

        return max(0, min(100, $base));
    }

    private function flag(string $code, string $severity, string $message): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'message' => $message,
        ];
    }

    private function action(string $code, string $description): array
    {
        return [
            'code' => $code,
            'description' => $description,
        ];
    }

    /**
     * @param  array<int,string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $text): string
    {
        return Str::of($text)
            ->lower()
            ->ascii()
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->value();
    }

    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @param  array{score:int,status:string,dimensions:array<string,int>,flags:array<int,array<string,mixed>>,suggested_actions:array<int,array<string,string>>,evidence:array<string,mixed>}  $assessment
     */
    private function recordAgentBehaviorGateEvaluation(AiTrace $trace, AiQualityEvaluation $evaluation, array $assessment): void
    {
        $findings = (array) data_get($assessment, 'evidence.agent_behavior_findings', []);
        if ($findings === []) {
            return;
        }

        $this->ledger->recordAgentBehaviorGateEvaluation($trace, $evaluation, $findings);
    }
}
