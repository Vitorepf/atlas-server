<?php

namespace App\Services\Semantic;

use App\Models\AiJob;
use App\Models\Capture;
use App\Services\Ai\AiGatewayService;
use App\Services\AuditLogService;
use App\Services\CapturePrivacyService;
use App\Support\Metadata;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

class CaptureSemanticClarifier
{
    public const EVENT_TYPE = 'capture_ready_for_curation';

    private const VERSION = 'aclarador-v1';

    private const MAX_EVENTS = 50;

    public function handleReady(Capture $capture, string $source = 'capture_ready'): Capture
    {
        $capture = $capture->refresh();
        $text = $this->captureText($capture);

        if ($text === '') {
            return $capture;
        }

        $contentHash = hash('sha256', $text);
        $metadata = $this->metadataArray($capture->metadata);
        $previous = $this->currentClarification($metadata);

        if (($previous['content_hash'] ?? null) === $contentHash && ($previous['status'] ?? null) === 'completed') {
            return $capture;
        }

        $eventId = (string) Str::uuid();
        $result = $this->clarify($capture, $text);
        $now = now()->toJSON();

        $clarification = [
            'version' => self::VERSION,
            'status' => 'completed',
            'event_type' => self::EVENT_TYPE,
            'event_id' => $eventId,
            'agent_slug' => 'aclarador',
            'source' => 'local_semantic_agent',
            'source_reason' => $source,
            'content_hash' => $contentHash,
            'generated_at' => $now,
            'result' => $result,
        ];

        $metadata['semantic_clarification'] = $clarification;
        $metadata['semantic_events'] = $this->prependEvent($metadata, [
            'id' => $eventId,
            'type' => self::EVENT_TYPE,
            'status' => 'completed',
            'source' => $source,
            'agent_slug' => 'aclarador',
            'content_hash' => $contentHash,
            'occurred_at' => $now,
            'processed_at' => $now,
            'density_score' => data_get($result, 'density.score'),
            'suggested_type' => $result['suggested_type'] ?? null,
        ]);

        $traceId = $this->enqueueAiClarifier($capture, $text, $clarification);
        if ($traceId) {
            $metadata['semantic_clarification']['ai_trace_id'] = $traceId;
            $metadata['semantic_events'][0]['ai_trace_id'] = $traceId;
            $metadata['semantic_events'][0]['ai_status'] = 'queued';
        }

        $capture->update([
            'metadata' => Metadata::forStorage($metadata),
        ]);

        app(AuditLogService::class)->record(self::EVENT_TYPE, [
            'subject_type' => 'capture',
            'subject_id' => $capture->id,
            'summary' => 'Captura pronta para curadoria semantica.',
            'evidence' => [
                'source' => $source,
                'agent_slug' => 'aclarador',
                'main_thesis' => $result['main_thesis'] ?? null,
                'suggested_type' => $result['suggested_type'] ?? null,
                'density' => $result['density'] ?? null,
                'future_triggers' => $result['future_triggers'] ?? [],
                'content_text' => $text,
            ],
            'privacy' => $this->privacyFor($capture),
            'refs' => [
                'capture_id' => $capture->id,
                'event_id' => $eventId,
                'ai_trace_id' => $traceId,
            ],
        ]);

        return $capture->refresh();
    }

    /**
     * @return array{
     *   main_thesis:string,
     *   atomic_ideas:array<int, string>,
     *   suggested_type:string,
     *   tension_or_question:string,
     *   density:array{score:float,label:string,drivers:array<int, string>},
     *   possible_destination:array{kind:string,note_type:string,title:string,path:string,reason:string},
     *   authorship_question:string,
     *   future_triggers:array<int, string>
     * }
     */
    public function clarify(Capture $capture, ?string $text = null): array
    {
        $text = $this->normalizeText($text ?? $this->captureText($capture));
        $mainThesis = $this->mainThesis($text);
        $atomicIdeas = $this->atomicIdeas($text, $mainThesis);
        $suggestedType = $this->suggestedType($text);
        $tension = $this->tensionOrQuestion($text, $capture->domain);
        $density = $this->density($text, $capture);
        $title = $this->title($mainThesis, $text);
        $path = $this->pathForType($suggestedType, $title);

        return [
            'main_thesis' => $mainThesis,
            'atomic_ideas' => $atomicIdeas,
            'suggested_type' => $suggestedType,
            'tension_or_question' => $tension,
            'density' => $density,
            'possible_destination' => [
                'kind' => 'semantic_curation_proposal',
                'note_type' => $suggestedType,
                'title' => $title,
                'path' => $path,
                'reason' => $this->destinationReason($suggestedType, $density['label']),
            ],
            'authorship_question' => $this->authorshipQuestion($suggestedType, $text, $capture->domain),
            'future_triggers' => $this->futureTriggers($text, $capture->domain),
        ];
    }

    public function resultFor(Capture $capture): ?array
    {
        $metadata = $this->metadataArray($capture->metadata);
        $result = data_get($metadata, 'semantic_clarification.result');

        return is_array($result) ? $result : null;
    }

    public function shouldPropose(Capture $capture): bool
    {
        $result = $this->resultFor($capture);
        if (! $result) {
            return false;
        }

        $score = (float) data_get($result, 'density.score', 0);
        $threshold = (float) config('atlas.semantic_memory.curation_min_density_score', 0.42);

        return $score >= $threshold
            || $this->hasStrategicSignal($this->captureText($capture));
    }

    public function completeAiClarification(AiJob $job): void
    {
        if (data_get($job->payload, 'event_type') !== self::EVENT_TYPE) {
            return;
        }

        $captureId = data_get($job->payload, 'capture_id');
        if (! is_string($captureId) || $captureId === '') {
            return;
        }

        $capture = Capture::query()->find($captureId);
        if (! $capture) {
            return;
        }

        $parsed = $this->parseAiJson((string) $job->result_text);
        if (! $parsed) {
            $this->markAiEvent($capture, $job, 'failed', ['ai_error' => 'invalid_json']);

            return;
        }

        $text = $this->captureText($capture);
        $result = $this->normalizeAiResult($parsed, $capture, $text);
        $metadata = $this->metadataArray($capture->metadata);
        $contentHash = hash('sha256', $text);
        $eventId = (string) (data_get($job->payload, 'clarification_event_id') ?: Str::uuid());
        $now = now()->toJSON();

        $metadata['semantic_clarification'] = [
            'version' => self::VERSION,
            'status' => 'completed',
            'event_type' => self::EVENT_TYPE,
            'event_id' => $eventId,
            'agent_slug' => 'aclarador',
            'source' => 'ai_gateway',
            'content_hash' => $contentHash,
            'generated_at' => $now,
            'ai_trace_id' => $job->trace_id,
            'ai_job_id' => $job->id,
            'result' => $result,
        ];

        $metadata['semantic_events'] = $this->replaceOrPrependEvent($metadata, [
            'id' => $eventId,
            'type' => self::EVENT_TYPE,
            'status' => 'completed',
            'source' => 'ai_gateway',
            'agent_slug' => 'aclarador',
            'content_hash' => $contentHash,
            'occurred_at' => data_get($job->payload, 'occurred_at') ?: $now,
            'processed_at' => $now,
            'ai_trace_id' => $job->trace_id,
            'ai_job_id' => $job->id,
            'ai_status' => 'succeeded',
            'density_score' => data_get($result, 'density.score'),
            'suggested_type' => $result['suggested_type'] ?? null,
        ]);

        $capture->update([
            'metadata' => Metadata::forStorage($metadata),
        ]);

        $job->update(['result_json' => Metadata::forStorage($result)]);
    }

    private function enqueueAiClarifier(Capture $capture, string $text, array $clarification): ?string
    {
        if (! config('atlas.semantic_memory.enqueue_ai_clarification', false) || ! config('atlas.ai.enabled')) {
            return null;
        }
        if (! app(CapturePrivacyService::class)->externalAiAllowedForMetadata($capture->metadata)) {
            app(AuditLogService::class)->record('ai_external_blocked_by_privacy', [
                'subject_type' => 'capture',
                'subject_id' => $capture->id,
                'severity' => 'warning',
                'summary' => 'Aclaramento externo bloqueado pela politica de privacidade da captura.',
                'evidence' => [
                    'agent_slug' => 'aclarador',
                    'event_type' => self::EVENT_TYPE,
                    'content_text' => $text,
                ],
                'privacy' => $this->privacyFor($capture),
                'refs' => ['capture_id' => $capture->id],
            ]);

            return null;
        }

        try {
            $trace = app(AiGatewayService::class)->enqueueInteraction($this->aiInput($capture, $text), [
                'source_type' => 'capture',
                'source_id' => $capture->id,
                'agent_slug' => 'aclarador',
                'kind' => 'curation',
                'priority' => 25,
                'include_semantic_context' => false,
                'max_attempts' => 1,
                'payload' => [
                    'event_type' => self::EVENT_TYPE,
                    'capture_id' => $capture->id,
                    'capture_client_id' => $capture->client_id,
                    'clarification_event_id' => $clarification['event_id'],
                    'occurred_at' => $clarification['generated_at'],
                    'atlas_workflow_mode' => 'semantic_clarification',
                    'baseline_result' => $clarification['result'],
                ],
            ]);

            return $trace->id;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function aiInput(Capture $capture, string $text): string
    {
        $context = json_encode([
            'capture_id' => $capture->id,
            'kind' => $capture->kind,
            'domain' => $capture->domain,
            'captured_at' => $capture->captured_at?->toJSON(),
            'pre_capture_digital_context' => $capture->pre_capture_digital_context ?? [],
            'text' => $text,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return <<<TXT
Evento: capture_ready_for_curation

Analise esta captura para aclaramento semantico. A captura pode ser curta; nao descarte por tamanho.

Contexto JSON:
{$context}

Retorne somente JSON valido com as chaves:
main_thesis, atomic_ideas, suggested_type, tension_or_question, density, possible_destination, authorship_question, future_triggers.
TXT;
    }

    private function markAiEvent(Capture $capture, AiJob $job, string $status, array $extra = []): void
    {
        $metadata = $this->metadataArray($capture->metadata);
        $eventId = (string) (data_get($job->payload, 'clarification_event_id') ?: Str::uuid());
        $metadata['semantic_events'] = $this->replaceOrPrependEvent($metadata, [
            'id' => $eventId,
            'type' => self::EVENT_TYPE,
            'status' => $status,
            'source' => 'ai_gateway',
            'agent_slug' => 'aclarador',
            'ai_trace_id' => $job->trace_id,
            'ai_job_id' => $job->id,
            'ai_status' => $status,
            'processed_at' => now()->toJSON(),
            ...$extra,
        ]);

        $capture->update(['metadata' => Metadata::forStorage($metadata)]);
    }

    private function currentClarification(array $metadata): array
    {
        $clarification = data_get($metadata, 'semantic_clarification');

        return is_array($clarification) ? $clarification : [];
    }

    private function privacyFor(Capture $capture): array
    {
        $privacy = data_get($capture->metadata, 'privacy');

        return is_array($privacy)
            ? $privacy
            : [
                'domain' => $capture->domain,
                'sensitivity' => data_get($capture->metadata, 'sensitivity', 'normal'),
            ];
    }

    private function captureText(Capture $capture): string
    {
        return $this->normalizeText((string) $capture->content_text);
    }

    private function normalizeText(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function mainThesis(string $text): string
    {
        $first = $this->sentences($text)[0] ?? $text;
        $clean = preg_replace('/^(eu\s+)?(preciso|precisamos|quero|queria|devo|temos que)\s+(de\s+)?/iu', '', $first) ?? $first;
        $clean = trim($clean, " \t\n\r\0\x0B.");

        if ($clean === '' || mb_strlen($clean) < 12) {
            $clean = $first;
        }

        return $this->sentence("Necessidade: {$clean}");
    }

    /**
     * @return array<int, string>
     */
    private function atomicIdeas(string $text, string $fallback): array
    {
        $ideas = collect($this->sentences($text))
            ->flatMap(fn (string $sentence): array => preg_split('/\s+[;]\s+|\s+ e (?=\w{4,})/iu', $sentence) ?: [$sentence])
            ->map(fn (string $idea): string => $this->sentence(trim($idea)))
            ->filter(fn (string $idea): bool => mb_strlen($idea) >= 8)
            ->unique()
            ->take(6)
            ->values()
            ->all();

        return $ideas !== [] ? $ideas : [$fallback];
    }

    /**
     * @return array<int, string>
     */
    private function sentences(string $text): array
    {
        $parts = preg_split('/(?<=[.!?])\s+|\n+/u', $text) ?: [$text];

        return array_values(array_filter(array_map(
            fn (string $part): string => trim($part, " \t\n\r\0\x0B"),
            $parts,
        ), fn (string $part): bool => $part !== ''));
    }

    private function suggestedType(string $text): string
    {
        $lower = mb_strtolower($text);

        if (Str::contains($lower, ['hipotese', 'hipótese', 'testar', 'experimento', 'validar', 'correlacion'])) {
            return 'hypothesis';
        }
        if (Str::contains($lower, ['principio', 'princípio', 'regra', 'lei pessoal'])) {
            return 'principle';
        }
        if (Str::contains($lower, ['praticar', 'treinar', 'rotina', 'protocolo', 'exercicio', 'exercício'])) {
            return 'practice';
        }
        if (Str::contains($lower, ['decidi', 'decisao', 'decisão', 'nao fazer', 'não fazer'])) {
            return 'decision_identity';
        }
        if (Str::contains($lower, ['ideia', 'ideias', 'sintese', 'síntese', 'transformar', 'unica', 'única'])) {
            return 'synthesis';
        }

        return 'mental_model';
    }

    private function tensionOrQuestion(string $text, string $domain): string
    {
        foreach ($this->sentences($text) as $sentence) {
            if (str_contains($sentence, '?')) {
                return $sentence;
            }
        }

        if (preg_match('/transformar\s+(.+?)\s+em\s+(.+?)(\.|$)/iu', $text, $match)) {
            return $this->sentence('Como transformar '.trim($match[1]).' em '.trim($match[2]).'?');
        }

        return $this->sentence("O que precisa ficar claro para esta captura sobre {$domain} virar conhecimento utilizavel?");
    }

    /**
     * @return array{score:float,label:string,drivers:array<int, string>}
     */
    private function density(string $text, Capture $capture): array
    {
        $lower = mb_strtolower($text);
        $score = 0.22 + min(0.22, mb_strlen($text) / 900);
        $drivers = [];

        $signals = [
            'intencao_explicita' => ['preciso', 'quero', 'decidi', 'devo', 'temos que'],
            'valor_estrategico' => ['estrateg', 'produto', 'cliente', 'black ink', 'blackink', 'ferramenta', 'unica', 'única'],
            'geracao_de_ideias' => ['ideia', 'ideias', 'criar', 'transformar'],
            'testabilidade' => ['testar', 'hipotese', 'hipótese', 'validar', 'medir'],
            'reuso_futuro' => ['modelo', 'principio', 'princípio', 'gatilho', 'sistema'],
        ];

        foreach ($signals as $driver => $needles) {
            if (Str::contains($lower, $needles)) {
                $drivers[] = $driver;
                $score += 0.12;
            }
        }

        if ($capture->domain !== 'outro') {
            $drivers[] = 'dominio_explicito';
            $score += 0.08;
        }

        $score = round(min(0.96, $score), 3);
        $label = $score >= 0.72 ? 'alta' : ($score >= 0.45 ? 'media' : 'baixa');

        return [
            'score' => $score,
            'label' => $label,
            'drivers' => array_values(array_unique($drivers)),
        ];
    }

    private function title(string $mainThesis, string $text): string
    {
        $title = preg_replace('/^Necessidade:\s*/u', '', $mainThesis) ?? $mainThesis;
        $title = trim($title, " \t\n\r\0\x0B.");

        if ($title === '') {
            $title = $text;
        }

        return Str::headline(Str::limit($title, 72, ''));
    }

    private function pathForType(string $type, string $title): string
    {
        $folder = match ($type) {
            'hypothesis' => '04-hipoteses',
            'principle' => '03-principios',
            'practice' => '05-praticas',
            'synthesis' => '08-sinteses',
            'decision_identity' => '06-decisoes-identidade',
            'source_note' => '01-acervo/vida',
            default => '02-modelos-mentais',
        };

        return $folder.'/'.Str::slug($title).'.md';
    }

    private function destinationReason(string $type, string $density): string
    {
        return "Aclaramento {$density}; destino sugerido como {$type} para revisao humana.";
    }

    private function authorshipQuestion(string $type, string $text, string $domain): string
    {
        return match ($type) {
            'hypothesis' => 'Qual experimento pequeno provaria ou derrubaria esta hipotese?',
            'principle' => 'Em quais situacoes este principio deve mandar na sua decisao?',
            'practice' => 'Como voce repetiria isso amanha de forma observavel?',
            'synthesis' => "Qual aposta concreta tornaria esta ideia sobre {$domain} inevitavel?",
            'decision_identity' => 'Que comportamento futuro deve mudar se esta decisao for real?',
            default => 'Qual e a formulacao mais verdadeira desta ideia, escrita com suas palavras?',
        };
    }

    /**
     * @return array<int, string>
     */
    private function futureTriggers(string $text, string $domain): array
    {
        $lower = mb_strtolower($text);
        $triggers = ["dominio_{$domain}"];

        $map = [
            'planejamento_produto' => ['produto', 'ferramenta', 'roadmap', 'feature'],
            'sessao_de_ideacao' => ['ideia', 'ideias', 'brainstorm', 'cruz'],
            'revisao_de_estrategia' => ['estrateg', 'transformar', 'unica', 'única', 'diferencial'],
            'experimento_aberto' => ['testar', 'validar', 'hipotese', 'hipótese'],
            'contato_com_cliente' => ['cliente', 'usuario', 'usuário'],
            'revisao_do_vault' => ['nota', 'modelo', 'principio', 'princípio'],
        ];

        foreach ($map as $trigger => $needles) {
            if (Str::contains($lower, $needles)) {
                $triggers[] = $trigger;
            }
        }

        return array_values(array_unique($triggers));
    }

    private function hasStrategicSignal(string $text): bool
    {
        return Str::contains(mb_strtolower($text), [
            'preciso',
            'ideia',
            'ideias',
            'decidi',
            'hipotese',
            'hipótese',
            'transformar',
            'unica',
            'única',
            'black ink',
            'blackink',
        ]);
    }

    private function sentence(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return $text;
        }

        return preg_match('/[.!?]$/u', $text) ? $text : "{$text}.";
    }

    private function parseAiJson(string $output): ?array
    {
        $output = trim($output);
        if ($output === '') {
            return null;
        }

        $decoded = json_decode($output, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/su', $output, $match)) {
            $decoded = json_decode($match[0], true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    private function normalizeAiResult(array $result, Capture $capture, string $text): array
    {
        $fallback = $this->clarify($capture, $text);
        $mainThesis = $this->stringOr($result['main_thesis'] ?? null, $fallback['main_thesis']);
        $type = $this->validType($this->stringOr($result['suggested_type'] ?? null, $fallback['suggested_type']));
        $density = is_array($result['density'] ?? null) ? $result['density'] : [];
        $score = (float) ($density['score'] ?? data_get($fallback, 'density.score'));
        $score = round(max(0, min(0.96, $score)), 3);
        $label = $this->stringOr($density['label'] ?? null, $score >= 0.72 ? 'alta' : ($score >= 0.45 ? 'media' : 'baixa'));
        $title = $this->title($mainThesis, $text);

        return [
            'main_thesis' => $mainThesis,
            'atomic_ideas' => $this->stringList($result['atomic_ideas'] ?? null, $fallback['atomic_ideas']),
            'suggested_type' => $type,
            'tension_or_question' => $this->stringOr($result['tension_or_question'] ?? null, $fallback['tension_or_question']),
            'density' => [
                'score' => $score,
                'label' => $label,
                'drivers' => $this->stringList($density['drivers'] ?? null, data_get($fallback, 'density.drivers', [])),
            ],
            'possible_destination' => [
                'kind' => 'semantic_curation_proposal',
                'note_type' => $type,
                'title' => $this->stringOr(data_get($result, 'possible_destination.title'), $title),
                'path' => $this->stringOr(data_get($result, 'possible_destination.path'), $this->pathForType($type, $title)),
                'reason' => $this->stringOr(data_get($result, 'possible_destination.reason'), $fallback['possible_destination']['reason']),
            ],
            'authorship_question' => $this->stringOr($result['authorship_question'] ?? null, $fallback['authorship_question']),
            'future_triggers' => $this->stringList($result['future_triggers'] ?? null, $fallback['future_triggers']),
        ];
    }

    private function stringOr(mixed $value, string $fallback): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : $fallback;
    }

    /**
     * @param  array<int, string>  $fallback
     * @return array<int, string>
     */
    private function stringList(mixed $value, array $fallback): array
    {
        if (! is_array($value)) {
            return $fallback;
        }

        $items = array_values(array_filter(array_map(
            fn (mixed $item): ?string => is_string($item) && trim($item) !== '' ? trim($item) : null,
            $value,
        )));

        return $items !== [] ? array_slice($items, 0, 8) : $fallback;
    }

    private function validType(string $type): string
    {
        return in_array($type, [
            'source_note',
            'mental_model',
            'principle',
            'hypothesis',
            'practice',
            'synthesis',
            'decision_identity',
            'cognitive_game',
        ], true) ? $type : 'mental_model';
    }

    private function metadataArray(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (is_object($metadata)) {
            return (array) $metadata;
        }

        return [];
    }

    private function prependEvent(array $metadata, array $event): array
    {
        $events = Arr::wrap($metadata['semantic_events'] ?? []);

        return array_slice([
            $event,
            ...array_values(array_filter($events, fn (mixed $item): bool => is_array($item))),
        ], 0, self::MAX_EVENTS);
    }

    private function replaceOrPrependEvent(array $metadata, array $event): array
    {
        $events = Arr::wrap($metadata['semantic_events'] ?? []);
        $eventId = $event['id'] ?? null;
        $replaced = false;

        $events = array_map(function (mixed $existing) use ($event, $eventId, &$replaced): mixed {
            if (is_array($existing) && $eventId && ($existing['id'] ?? null) === $eventId) {
                $replaced = true;

                return [
                    ...$existing,
                    ...$event,
                ];
            }

            return $existing;
        }, $events);

        if (! $replaced) {
            array_unshift($events, $event);
        }

        return array_slice(array_values(array_filter($events, fn (mixed $item): bool => is_array($item))), 0, self::MAX_EVENTS);
    }
}
