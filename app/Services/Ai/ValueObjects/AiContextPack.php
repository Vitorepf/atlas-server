<?php

namespace App\Services\Ai\ValueObjects;

class AiContextPack
{
    private readonly array $data;

    private readonly array $contextRefs;

    /**
     * Optional Integer ID Mapping for Absorcao 1 phase 2 (forward substitution).
     * When provided, toPromptSection() renders provider-safe labels "[N]" instead
     * of raw UUIDs in source_id fields. Default null preserves legacy behaviour.
     */
    private ?ContextIdRemap $idRemap = null;

    public function __construct(array $data, array $contextRefs, ?ContextIdRemap $idRemap = null)
    {
        $this->contextRefs = array_values($contextRefs);
        $this->data = $this->withManifest($data, $this->contextRefs);
        $this->idRemap = $idRemap;
    }

    /**
     * Returns the optional Integer ID Mapping attached to this pack.
     */
    public function idRemap(): ?ContextIdRemap
    {
        return $this->idRemap;
    }

    /**
     * Renders a UUID in provider-safe form using the optional remap. Falls back
     * to the original UUID when remap is absent or the UUID is not mapped.
     */
    private function safeId(?string $rawId): ?string
    {
        if ($rawId === null || $rawId === '') {
            return $rawId;
        }
        if ($this->idRemap === null) {
            return $rawId;
        }
        $label = $this->idRemap->internalLabel($rawId);

        return $label ?? $rawId;
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function contextRefs(): array
    {
        return $this->contextRefs;
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<int,mixed>  $contextRefs
     * @return array<string,mixed>
     */
    private function withManifest(array $data, array $contextRefs): array
    {
        if (isset($data['manifest']) && is_array($data['manifest'])) {
            return $data;
        }

        $createdAt = now();
        $ttlSeconds = max(60, (int) data_get($data, 'policy.context_pack_ttl_seconds', config('atlas.ai.context_pack_ttl_seconds', 3600)));
        $sources = $this->sources($data, $contextRefs);
        $hashPayload = [
            'task' => $data['task'] ?? [],
            'surface' => $data['surface'] ?? [],
            'sources' => $sources,
            'context_refs' => $contextRefs,
        ];

        $data['manifest'] = [
            'schema_version' => 'atlas.context_pack.manifest.v1',
            'context_pack_id' => 'ctx_'.substr(hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''), 0, 24),
            'created_at' => $createdAt->toJSON(),
            'expires_at' => $createdAt->copy()->addSeconds($ttlSeconds)->toJSON(),
            'ttl_seconds' => $ttlSeconds,
            'source_count' => count($sources),
            'sources' => $sources,
            'context_ref_count' => count($contextRefs),
            'context_ref_hash' => hash('sha256', json_encode($contextRefs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            'builder' => static::class,
        ];

        return $data;
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<int,mixed>  $contextRefs
     * @return array<int,array<string,mixed>>
     */
    private function sources(array $data, array $contextRefs): array
    {
        $sources = collect((array) data_get($data, 'evidence.sources', []))
            ->filter(fn (mixed $source): bool => is_scalar($source) && trim((string) $source) !== '')
            ->map(fn (mixed $source): array => [
                'type' => 'evidence_source',
                'id' => trim((string) $source),
            ]);

        $refs = collect($contextRefs)
            ->filter(fn (mixed $ref): bool => is_array($ref))
            ->map(fn (array $ref): array => [
                'type' => (string) ($ref['type'] ?? 'context_ref'),
                'id' => (string) ($ref['id'] ?? $ref['path'] ?? $ref['title'] ?? 'unknown'),
                'path' => $ref['path'] ?? null,
                'privacy_class' => $ref['privacy_class'] ?? null,
            ]);

        return $sources
            ->merge($refs)
            ->unique(fn (array $source): string => ($source['type'] ?? 'unknown').':'.($source['id'] ?? 'unknown'))
            ->values()
            ->all();
    }

    public function toPromptSection(): string
    {
        $task = $this->data['task'] ?? [];
        $constraints = $this->data['constraints'] ?? [];
        $conversationData = $this->data['conversation'] ?? [];
        $conversation = data_get($conversationData, 'recent_turns', []);
        $continuity = $this->data['continuity'] ?? [];
        $activeState = data_get($continuity, 'active_state');
        $latestCompaction = data_get($continuity, 'latest_compaction');
        $latestHandoff = data_get($continuity, 'latest_provider_handoff');
        $rankedRecall = data_get($this->data, 'memory.recall', []);
        $retrievalPlan = data_get($this->data, 'retrieval', []);
        $registryMemory = data_get($this->data, 'memory.registry', []);
        $verbatimMemory = data_get($this->data, 'memory.verbatim', []);
        $memory = data_get($this->data, 'memory.semantic', []);
        $openQuestions = $this->data['open_questions'] ?? [];
        $excluded = $this->data['excluded_context'] ?? [];

        $lines = [
            '# Context Pack Atlas',
            '',
            '## Tarefa',
            '- tipo: '.($task['type'] ?? 'unknown'),
            '- modo: '.($task['desired_mode'] ?? 'direct'),
            '- risco: '.($task['risk_level'] ?? 'low'),
            '- dominio: '.($task['domain'] ?? 'unknown'),
            '- superficie: '.data_get($this->data, 'surface.kind', 'unknown'),
            '- workspace: '.(data_get($this->data, 'surface.workspace') ?: 'n/a'),
            '',
            '## Objetivo',
            (string) ($task['objective'] ?? ''),
        ];

        if (! empty($conversationData['thread_id'])) {
            $lines[] = '';
            $lines[] = '## Continuidade da Thread Atlas';
            $lines[] = '- thread_id: '.$conversationData['thread_id'];
            $lines[] = '- titulo: '.($conversationData['thread_title'] ?: 'n/a');
            $lines[] = '- fonte: '.($conversationData['source'] ?: 'n/a');
            if (! empty($conversationData['context_window'])) {
                $windowMode = data_get($conversationData, 'context_window.mode', 'n/a');
                $compactedThrough = data_get($conversationData, 'context_window.compacted_through_position');
                $messagesIncluded = data_get($conversationData, 'context_window.messages_included');
                $lines[] = '- janela: '.$windowMode.'; mensagens='.$messagesIncluded.'; compactado_ate='.($compactedThrough ?: 'n/a');
            }
            if (! empty($conversationData['thread_summary'])) {
                $lines[] = '- resumo: '.$conversationData['thread_summary'];
            }
        }

        if (is_array($activeState) && ! empty($activeState)) {
            $lines[] = '';
            $lines[] = '## Estado Operacional Atlas';
            $lines[] = 'Use este estado como fonte primaria de continuidade. Ele pertence ao Atlas, nao ao provider.';
            $lines[] = '- objetivo: '.($activeState['objective'] ?? 'n/a');
            $lines[] = '- fase: '.($activeState['current_phase'] ?? 'n/a');
            $lines[] = '- topico: '.($activeState['current_topic'] ?? 'n/a');
            if (! empty($activeState['user_position'])) {
                $lines[] = '- posicao recente do operador: '.$activeState['user_position'];
            }
            foreach ([
                'decisions' => 'Decisoes',
                'open_loops' => 'Pendencias',
                'next_steps' => 'Proximos Passos',
                'relevant_artifacts' => 'Artefatos Relevantes',
                'constraints' => 'Restricoes',
            ] as $key => $title) {
                $items = data_get($activeState, $key, []);
                if (! is_array($items) || empty($items)) {
                    continue;
                }

                $lines[] = $title.':';
                foreach (array_slice($items, -8) as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $value = $item['text'] ?? $item['value'] ?? null;
                    if ($value) {
                        $lines[] = '- '.$value;
                    }
                }
            }
        }

        if (is_array($latestCompaction) && ! empty($latestCompaction['summary'])) {
            $lines[] = '';
            $lines[] = '## Compactacao Atlas Mais Recente';
            $lines[] = '- compaction_id: '.($latestCompaction['id'] ?? 'n/a');
            $lines[] = '- motivo: '.($latestCompaction['reason'] ?? 'n/a');
            $lines[] = '- qualidade: '.($latestCompaction['quality_gate_status'] ?? 'n/a');
            $lines[] = (string) $latestCompaction['summary'];
        }

        if (is_array($latestHandoff) && ! empty($latestHandoff['brief_text'])) {
            $lines[] = '';
            $lines[] = '## Handoff De Provider Atlas';
            $lines[] = 'Se esta chamada veio apos troca de motor, preserve este handoff e continue a sessao sem pedir reexplicacao.';
            $lines[] = '- de: '.($latestHandoff['from_provider'] ?? 'n/a');
            $lines[] = '- para: '.($latestHandoff['to_provider'] ?? 'n/a');
            $lines[] = (string) $latestHandoff['brief_text'];
        }

        if (! empty($conversation)) {
            $lines[] = '';
            $lines[] = '## Contexto Conversacional Recente';
            $lines[] = ($conversationData['instruction'] ?? null) ?: 'Use para entender referencias curtas como A/B/C, "ambos", "isso", "continua" e troca de provider.';
            $lines[] = 'Nao diga que nao ha pergunta anterior quando este bloco existir. A sessao pertence ao Atlas, nao ao provider.';
            foreach ($conversation as $turn) {
                $provider = ! empty($turn['provider']) ? " ({$turn['provider']})" : '';
                $position = isset($turn['position']) ? ' #'.$turn['position'] : '';
                $lines[] = '- '.($turn['role'] ?? 'user').$provider.$position.': '.($turn['text'] ?? '');
            }
        }

        if (! empty($constraints['must_do'])) {
            $lines[] = '';
            $lines[] = '## Obrigatorio';
            foreach ($constraints['must_do'] as $item) {
                $lines[] = '- '.$item;
            }
        }

        if (! empty($constraints['must_not_do'])) {
            $lines[] = '';
            $lines[] = '## Nao Fazer';
            foreach ($constraints['must_not_do'] as $item) {
                $lines[] = '- '.$item;
            }
        }

        if (is_array($retrievalPlan) && ! empty($retrievalPlan['selected_sources'])) {
            $lines[] = '';
            $lines[] = '## Retrieval Router Plan';
            $lines[] = '- schema: '.($retrievalPlan['schema_version'] ?? 'unknown');
            $lines[] = '- mode: '.($retrievalPlan['mode'] ?? 'balanced');
            foreach (array_slice((array) $retrievalPlan['selected_sources'], 0, 8) as $source) {
                if (! is_array($source)) {
                    continue;
                }

                $lines[] = '- '.($source['type'] ?? 'unknown')
                    .'; reason='.($source['reason'] ?? 'n/a')
                    .'; limit='.($source['limit'] ?? 'n/a')
                    .'; required='.(($source['required'] ?? false) ? 'true' : 'false');
            }
        }

        if (! empty($rankedRecall)) {
            $lines[] = '';
            $lines[] = '## Recall Atlas Priorizado';
            $lines[] = 'Use esta ordem para resolver conflito entre memorias. Este bloco e um indice compacto; os detalhes aparecem nas secoes de memoria abaixo.';
            foreach ($rankedRecall as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $lines[] = '### #'.($item['rank'] ?? '?').' '.($item['title'] ?: ($item['type'] ?? 'Memoria'));
                $lines[] = '- fonte: '.($item['source'] ?? 'n/a').'; tipo: '.($item['type'] ?? 'n/a').'; escopo: '.($item['scope'] ?? 'n/a');
                $lines[] = '- motivo: '.($item['reason'] ?? 'memoria relevante');
                if (! empty($item['summary'])) {
                    $lines[] = '- resumo: '.$item['summary'];
                }
                if (! empty($item['excerpt'])) {
                    $lines[] = '- trecho: '.$item['excerpt'];
                }
            }
        }

        if (! empty($registryMemory)) {
            $lines[] = '';
            $lines[] = '## Memoria Registrada Atlas';
            $lines[] = 'Use como contexto canonico e rastreavel. Cada item tem escopo e fonte; nao exponha IDs internos ao operador salvo se ele pedir auditoria.';
            foreach ($registryMemory as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $label = $item['title'] ?: ($item['summary'] ?: $item['type']);
                $lines[] = '### '.($label ?: 'Memoria sem titulo');
                $lines[] = '- tipo: '.($item['type'] ?? 'n/a');
                $lines[] = '- escopo: '.($item['scope'] ?? 'n/a');
                $lines[] = '- prioridade: '.($item['priority'] ?? 'n/a').'; importancia: '.($item['importance'] ?? 'n/a');
                $lines[] = '- fonte: '.($item['source_type'] ?? 'n/a').(! empty($item['source_id']) ? ':'.$this->safeId((string) $item['source_id']) : '');
                $lines[] = '- motivo de inclusao: '.($item['reason'] ?? 'memoria relevante');
                if (! empty($item['summary'])) {
                    $lines[] = '- resumo: '.$item['summary'];
                }
                if (! empty($item['body'])) {
                    $lines[] = '- conteudo: '.$item['body'];
                }
            }
        }

        if (! empty($verbatimMemory)) {
            $lines[] = '';
            $lines[] = '## Recall Verbatim Atlas';
            $lines[] = 'Use como evidencia exata ja redigida e aprovada para provider. Nao exponha IDs internos ao operador salvo se ele pedir auditoria.';
            foreach ($verbatimMemory as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $label = $item['title'] ?: ($item['summary'] ?: $item['type']);
                $lines[] = '### '.($label ?: 'Recall verbatim sem titulo');
                $lines[] = '- tipo: '.($item['type'] ?? 'n/a');
                $lines[] = '- escopo: '.($item['scope'] ?? 'n/a');
                $lines[] = '- fonte: '.($item['source_type'] ?? 'n/a').(! empty($item['source_id']) ? ':'.$this->safeId((string) $item['source_id']) : '');
                $lines[] = '- motivo de inclusao: '.($item['reason'] ?? 'recall verbatim relevante');
                if (! empty($item['summary'])) {
                    $lines[] = '- resumo: '.$item['summary'];
                }
                if (! empty($item['snippet'])) {
                    $lines[] = '- trecho: '.$item['snippet'];
                }
            }
        }

        if (! empty($memory)) {
            $lines[] = '';
            $lines[] = '## Memoria Semantica Recuperada';
            $lines[] = 'Use silenciosamente quando ajudar. Nao anuncie "contexto mapeado" e nao liste estrutura interna do Atlas salvo se o operador pedir.';
            foreach ($memory as $item) {
                // PHP 8.4+ refusa coerção NaN→string. Score pode chegar NaN
                // quando rerank dá divisão zero — guardamos com is_finite().
                $rawScore = $item['score'] ?? null;
                $score = '';
                if ($rawScore !== null) {
                    $floatScore = is_numeric($rawScore) ? (float) $rawScore : NAN;
                    if (is_finite($floatScore)) {
                        $score = ' score='.number_format($floatScore, 4, '.', '');
                    }
                }
                $lines[] = '### '.($item['title'] ?? 'Sem titulo').$score;
                $lines[] = 'path: '.($item['path'] ?? 'n/a');
                $lines[] = 'tipo: '.($item['type'] ?? 'n/a');
                $lines[] = 'resumo: '.($item['summary'] ?? '');
                if (! empty($item['excerpt'])) {
                    $lines[] = 'trecho: '.$item['excerpt'];
                }
            }
        }

        if (! empty($openQuestions)) {
            $lines[] = '';
            $lines[] = '## Lacunas / Perguntas Abertas';
            foreach ($openQuestions as $question) {
                $lines[] = '- '.$question;
            }
        }

        if (! empty($excluded)) {
            $lines[] = '';
            $lines[] = '## Contexto Excluido';
            foreach ($excluded as $item) {
                $lines[] = '- '.$item;
            }
        }

        return implode("\n", $lines);
    }
}
