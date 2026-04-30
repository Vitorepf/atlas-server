<?php

namespace App\Services\Ai\ValueObjects;

class AiContextPack
{
    public function __construct(
        private readonly array $data,
        private readonly array $contextRefs,
    ) {}

    public function toArray(): array
    {
        return $this->data;
    }

    public function contextRefs(): array
    {
        return $this->contextRefs;
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

        if (! empty($memory)) {
            $lines[] = '';
            $lines[] = '## Memoria Semantica Recuperada';
            $lines[] = 'Use silenciosamente quando ajudar. Nao anuncie "contexto mapeado" e nao liste estrutura interna do Atlas salvo se o operador pedir.';
            foreach ($memory as $item) {
                $score = isset($item['score']) ? ' score='.$item['score'] : '';
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
