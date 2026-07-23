<?php

namespace App\Services\ProjectExecution;

use App\Models\AtlasProject;
use App\Models\AtlasProjectStep;
use App\Models\Capture;

/**
 * Pure planning/inference heuristics for ProjectExecutionService.
 * Deterministic string/int derivations driving inferPlan() and step specs.
 */
class PlanHeuristicsSection
{
    public function __construct(
        private readonly ProjectExecutionSupport $support,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function standalonePlanningAttributes(array $plan): array
    {
        $estimatedMinutes = $this->support->clamp((int) ($plan['estimated_minutes'] ?? 25), 5, 480);
        $priorityScore = match ($plan['priority'] ?? 'normal') {
            'urgent' => 92,
            'high' => 76,
            'low' => 25,
            default => 50,
        };

        return [
            'planned_for_date' => null,
            'planned_start_at' => null,
            'planned_end_at' => null,
            'estimated_minutes' => $estimatedMinutes,
            'energy_required' => $plan['energy_required'] ?? 'medium',
            'urgency_score' => $priorityScore,
            'impact_score' => $this->support->clamp($priorityScore + 8, 0, 100),
            'effort_score' => $this->support->clamp((int) round(($estimatedMinutes / 120) * 70), 0, 100),
            'priority_score' => $priorityScore,
            'planning_status' => 'suggested',
        ];
    }

    public function projectType(string $lower): string
    {
        if (preg_match('/\b(estudar|estudo|aprender|curso|aula|prova|investimento|livro|mat[ée]ria)\b/u', $lower)) {
            return 'study';
        }
        if (preg_match('/\b(app|aplicativo|backend|front|frontend|servidor|api|c[oó]digo|implementar|deploy|stack|mac|ios)\b/u', $lower)) {
            return 'technical_build';
        }
        if (preg_match('/\b(escrever|texto|artigo|roteiro|conte[úu]do|documenta[cç][aã]o)\b/u', $lower)) {
            return 'writing';
        }
        if (preg_match('/\b(cliente|receita|venda|produto|neg[oó]cio|black ink|lan[cç]ar)\b/u', $lower)) {
            return 'business';
        }
        if (preg_match('/\b(sa[úu]de|m[eé]dico|exame|treino|sono|acne|terapia)\b/u', $lower)) {
            return 'health';
        }
        if (preg_match('/\b(chat[oa]s?|tedios[oa]s?|arrumar|organizar|limpar|pagar|burocracia)\b/u', $lower)) {
            return 'tedious';
        }

        return 'personal';
    }

    public function priority(mixed $priority, string $lower): string
    {
        if (in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            return (string) $priority;
        }
        if (preg_match('/\b(hoje|urgente|prazo|deadline|bloqueando|cr[ií]tico)\b/u', $lower)) {
            return 'urgent';
        }
        if (preg_match('/\b(importante|cliente|sa[úu]de|receita|decis[aã]o|atlas|black ink)\b/u', $lower)) {
            return 'high';
        }

        return 'normal';
    }

    public function defaultNextAction(string $type, string $title, string $lower): string
    {
        return match ($type) {
            'study' => 'Definir a primeira pergunta de estudo e estudar por 25 minutos',
            'technical_build' => 'Definir o escopo mínimo e a próxima entrega executável',
            'writing' => 'Criar um rascunho de 10 linhas com a tese principal',
            'business' => 'Escrever a hipótese de valor e a próxima validação',
            'health' => 'Registrar o estado atual e escolher a menor ação segura',
            'tedious' => 'Abrir o material e executar 10 minutos sem otimizar',
            default => str_contains($lower, 'decidir') ? 'Listar as opções e escolher o próximo teste' : 'Definir a menor próxima ação executável',
        };
    }

    public function desiredOutcome(string $type, string $title): string
    {
        return match ($type) {
            'study' => "Entender {$title} a ponto de explicar e aplicar sem travar.",
            'technical_build' => "Transformar {$title} em uma entrega funcional e testável.",
            'business' => "Converter {$title} em uma decisão ou validação prática.",
            default => "Dar forma executável a {$title}.",
        };
    }

    public function minimumViableOutcome(string $type, string $title): string
    {
        return match ($type) {
            'study' => 'Uma página de síntese com 3 conceitos, 3 exemplos e 1 próxima pergunta.',
            'technical_build' => 'Um MVP pequeno, rodando, com próximo passo evidente.',
            'writing' => 'Um rascunho bruto que possa ser editado.',
            default => "Um avanço visível em {$title}, pequeno o suficiente para começar hoje.",
        };
    }

    public function definitionOfDone(string $type, string $title): string
    {
        return match ($type) {
            'study' => 'A síntese está registrada e existe uma ação de revisão.',
            'technical_build' => 'O incremento foi implementado, testado e documentado.',
            'business' => 'A hipótese foi validada, descartada ou virou próxima decisão.',
            default => "{$title} tem resultado, evidência e próximo destino definidos.",
        };
    }

    public function starterStep(string $type, string $nextAction): string
    {
        return match ($type) {
            'study' => 'Abrir a fonte principal e escrever a pergunta no topo.',
            'technical_build' => 'Abrir o repositório e escrever o menor escopo em 3 bullets.',
            'writing' => 'Abrir uma nota vazia e escrever a primeira frase ruim.',
            'tedious' => 'Começar por 2 minutos sem organizar o ambiente inteiro.',
            default => 'Abrir o lugar de trabalho e escrever a primeira micro-ação.',
        };
    }

    public function minimumViableAction(string $type, string $nextAction): string
    {
        return match ($type) {
            'study' => 'Ler por 10 minutos e registrar 3 bullets.',
            'technical_build' => 'Criar ou revisar um checklist técnico com o próximo commit.',
            'tedious' => 'Executar apenas o primeiro bloco de 10 minutos.',
            default => mb_substr($nextAction, 0, 180),
        };
    }

    public function ifThenPlan(string $type): string
    {
        return match ($type) {
            'study' => 'Se eu travar, reduzo para uma pergunta e um exemplo.',
            'technical_build' => 'Se o escopo crescer, volto para o menor incremento testável.',
            'tedious' => 'Se eu resistir, faço só 2 minutos e encerro com próximo passo escrito.',
            default => 'Se eu travar, escrevo a menor ação física possível e faço por 5 minutos.',
        };
    }

    public function rewardHint(string $type): string
    {
        return match ($type) {
            'study' => 'Marcar uma síntese visível depois do bloco.',
            'technical_build' => 'Fechar com um commit, teste ou checklist verde.',
            'tedious' => 'Parar no tempo combinado e registrar que começou.',
            default => 'Registrar progresso concreto antes de abrir outro ciclo.',
        };
    }

    public function processSteps(string $type, string $title): array
    {
        return array_map(fn (array $step): string => (string) $step['title'], $this->projectStepSpecs($type, $title));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function projectStepSpecs(string $type, string $title): array
    {
        return match ($type) {
            'study' => [
                [
                    'title' => 'Definir pergunta de estudo',
                    'description' => 'Escolher uma pergunta única para evitar estudo aberto demais.',
                    'expected_output' => 'Uma pergunta de estudo escrita em linguagem simples.',
                    'acceptance_criteria' => 'Existe uma pergunta que pode ser respondida em um bloco curto.',
                    'estimated_minutes' => 10,
                    'energy_required' => 'low',
                    'friction_level' => 35,
                ],
                [
                    'title' => 'Estudar bloco curto',
                    'description' => 'Fazer um bloco de estudo com escopo fechado.',
                    'expected_output' => 'Três bullets com conceitos ou dúvidas úteis.',
                    'acceptance_criteria' => 'O bloco foi concluído sem tentar cobrir o assunto inteiro.',
                    'estimated_minutes' => 25,
                    'energy_required' => 'medium',
                    'friction_level' => 55,
                ],
                [
                    'title' => 'Registrar síntese atômica',
                    'description' => 'Transformar o bloco em uma nota curta e reutilizável.',
                    'expected_output' => 'Uma síntese com tese, exemplo e dúvida aberta.',
                    'acceptance_criteria' => 'A síntese explica algo sem depender da fonte original.',
                    'estimated_minutes' => 20,
                    'energy_required' => 'medium',
                    'friction_level' => 50,
                ],
                [
                    'title' => 'Aplicar em exemplo',
                    'description' => 'Usar o que foi estudado em um exemplo concreto.',
                    'expected_output' => 'Um exemplo resolvido ou uma decisão prática.',
                    'acceptance_criteria' => 'O conhecimento saiu do resumo e virou uso.',
                    'estimated_minutes' => 25,
                    'energy_required' => 'medium',
                    'friction_level' => 58,
                ],
                [
                    'title' => 'Agendar revisão',
                    'description' => 'Criar uma revisão curta para consolidar o aprendizado.',
                    'expected_output' => 'Próxima revisão registrada.',
                    'acceptance_criteria' => 'Existe uma data ou captura de revisão.',
                    'estimated_minutes' => 10,
                    'energy_required' => 'low',
                    'friction_level' => 30,
                ],
            ],
            'technical_build' => [
                [
                    'title' => 'Definir resultado mínimo',
                    'description' => 'Escrever o menor comportamento que prova valor.',
                    'expected_output' => 'Escopo mínimo em até cinco bullets.',
                    'acceptance_criteria' => 'É possível testar o resultado sem construir o sistema inteiro.',
                    'estimated_minutes' => 20,
                    'energy_required' => 'medium',
                    'friction_level' => 45,
                ],
                [
                    'title' => 'Mapear stack e restrições',
                    'description' => 'Listar tecnologia, dados, APIs e riscos antes de codar.',
                    'expected_output' => 'Mapa curto de stack, restrições e riscos.',
                    'acceptance_criteria' => 'As decisões técnicas críticas estão explícitas.',
                    'estimated_minutes' => 30,
                    'energy_required' => 'medium',
                    'friction_level' => 52,
                ],
                [
                    'title' => 'Implementar backend mínimo',
                    'description' => 'Criar a menor base persistente/API necessária.',
                    'expected_output' => 'Endpoint, serviço ou persistência mínima funcionando.',
                    'acceptance_criteria' => 'Há teste ou verificação objetiva do backend.',
                    'estimated_minutes' => 60,
                    'energy_required' => 'high',
                    'friction_level' => 72,
                ],
                [
                    'title' => 'Implementar frontend mínimo',
                    'description' => 'Construir a menor interface que usa o backend.',
                    'expected_output' => 'Tela ou fluxo principal funcionando.',
                    'acceptance_criteria' => 'O usuário consegue executar o fluxo sem instrução externa.',
                    'estimated_minutes' => 60,
                    'energy_required' => 'high',
                    'friction_level' => 72,
                ],
                [
                    'title' => 'Testar fluxo principal',
                    'description' => 'Validar ponta a ponta e corrigir regressões óbvias.',
                    'expected_output' => 'Fluxo testado com resultado registrado.',
                    'acceptance_criteria' => 'A verificação cobre o caminho principal.',
                    'estimated_minutes' => 35,
                    'energy_required' => 'medium',
                    'friction_level' => 50,
                ],
                [
                    'title' => 'Documentar próxima decisão',
                    'description' => 'Registrar o que foi feito e a próxima escolha técnica.',
                    'expected_output' => 'Nota curta com decisão, evidência e próximo passo.',
                    'acceptance_criteria' => 'O projeto pode ser retomado sem reconstruir contexto.',
                    'estimated_minutes' => 15,
                    'energy_required' => 'low',
                    'friction_level' => 35,
                ],
            ],
            'tedious' => [
                [
                    'title' => 'Reduzir escopo para 10 minutos',
                    'description' => 'Cortar a tarefa para uma versão quase ridiculamente pequena.',
                    'expected_output' => 'Um primeiro bloco que cabe em 10 minutos.',
                    'acceptance_criteria' => 'A ação não exige motivação alta.',
                    'estimated_minutes' => 10,
                    'energy_required' => 'low',
                    'friction_level' => 40,
                ],
                [
                    'title' => 'Preparar ambiente mínimo',
                    'description' => 'Abrir só o necessário para começar.',
                    'expected_output' => 'Ambiente aberto no ponto exato de execução.',
                    'acceptance_criteria' => 'Não houve organização paralela desnecessária.',
                    'estimated_minutes' => 10,
                    'energy_required' => 'low',
                    'friction_level' => 45,
                ],
                [
                    'title' => 'Executar sem otimizar',
                    'description' => 'Fazer o bloco combinado sem procurar a forma perfeita.',
                    'expected_output' => 'Primeiro avanço concreto produzido.',
                    'acceptance_criteria' => 'Existe evidência de começo, mesmo imperfeita.',
                    'estimated_minutes' => 15,
                    'energy_required' => 'low',
                    'friction_level' => 65,
                ],
                [
                    'title' => 'Registrar próximo passo',
                    'description' => 'Fechar o ciclo deixando a continuação clara.',
                    'expected_output' => 'Próxima ação registrada.',
                    'acceptance_criteria' => 'Retomar não exige decidir tudo de novo.',
                    'estimated_minutes' => 5,
                    'energy_required' => 'low',
                    'friction_level' => 25,
                ],
            ],
            default => [
                [
                    'title' => "Clarificar {$title}",
                    'description' => 'Definir resultado, limites e motivo.',
                    'expected_output' => 'Projeto explicado em poucas linhas.',
                    'acceptance_criteria' => 'O próximo passo não depende de pensar no projeto inteiro.',
                    'estimated_minutes' => 15,
                    'energy_required' => 'low',
                    'friction_level' => 40,
                ],
                [
                    'title' => 'Escolher próxima ação',
                    'description' => 'Converter intenção em ação física e observável.',
                    'expected_output' => 'Uma ação única, rápida e sem ambiguidade.',
                    'acceptance_criteria' => 'A ação pode entrar na agenda.',
                    'estimated_minutes' => 10,
                    'energy_required' => 'low',
                    'friction_level' => 35,
                ],
                [
                    'title' => 'Executar bloco curto',
                    'description' => 'Avançar sem tentar concluir tudo.',
                    'expected_output' => 'Um incremento concreto.',
                    'acceptance_criteria' => 'Há evidência de avanço.',
                    'estimated_minutes' => 25,
                    'energy_required' => 'medium',
                    'friction_level' => 55,
                ],
                [
                    'title' => 'Registrar evidência',
                    'description' => 'Fechar o ciclo com resultado e próximo passo.',
                    'expected_output' => 'Registro do que mudou e do que vem depois.',
                    'acceptance_criteria' => 'O projeto pode ser retomado rapidamente.',
                    'estimated_minutes' => 10,
                    'energy_required' => 'low',
                    'friction_level' => 30,
                ],
            ],
        };
    }

    public function executionMode(string $type, int $estimatedMinutes, string $lower): string
    {
        if ($type === 'study') {
            return 'study';
        }
        if ($type === 'technical_build' || $estimatedMinutes >= 50) {
            return 'deep_work';
        }
        if ($type === 'tedious') {
            return 'tedious';
        }
        if (preg_match('/\b(decidir|escolher|priorizar)\b/u', $lower)) {
            return 'decision';
        }

        return 'quick_win';
    }

    public function estimatedMinutes(string $type, string $lower): int
    {
        if ($type === 'technical_build') {
            return 60;
        }
        if ($type === 'study') {
            return 25;
        }
        if ($type === 'tedious') {
            return 15;
        }
        if (preg_match('/\b(ligar|enviar|pagar|colocar|marcar|responder|comprar)\b/u', $lower)) {
            return 15;
        }

        return 25;
    }

    public function energyRequired(string $type, int $estimatedMinutes, string $priority): string
    {
        if ($type === 'technical_build' || $estimatedMinutes >= 50 || $priority === 'urgent') {
            return 'high';
        }
        if ($type === 'tedious' || $estimatedMinutes <= 15) {
            return 'low';
        }

        return 'medium';
    }

    public function energyProfile(string $energyRequired, string $type): string
    {
        if ($type === 'technical_build') {
            return 'mixed';
        }

        return in_array($energyRequired, ['low', 'medium', 'high'], true) ? $energyRequired : 'mixed';
    }

    public function avoidanceReason(string $lower, int $estimatedMinutes): string
    {
        if (preg_match('/\b(chat[oa]s?|tedios[oa]s?|burocracia|pregui[cç]a)\b/u', $lower)) {
            return 'boring';
        }
        if (preg_match('/\b(medo|dif[ií]cil|assusta|ansiedade|complexo)\b/u', $lower)) {
            return 'scary';
        }
        if (preg_match('/\b(perfeito|perfeccionismo|polir|premium)\b/u', $lower)) {
            return 'perfectionism';
        }
        if ($estimatedMinutes >= 50) {
            return 'too_large';
        }

        return 'unknown';
    }

    public function frictionLevel(string $avoidanceReason, int $estimatedMinutes): int
    {
        $base = match ($avoidanceReason) {
            'boring' => 70,
            'scary', 'perfectionism', 'too_large' => 78,
            default => 45,
        };

        return $this->support->clamp($base + ($estimatedMinutes >= 50 ? 8 : 0), 0, 100);
    }

    public function emotionalResistance(string $avoidanceReason): int
    {
        return match ($avoidanceReason) {
            'scary', 'perfectionism' => 78,
            'boring', 'too_large' => 64,
            default => 42,
        };
    }

    public function clarityLevel(string $lower, array $data): int
    {
        if (! empty($data['next_action']) || ! empty($data['goal'])) {
            return 76;
        }
        if (preg_match('/\b(algum|coisa|ideia|talvez|pensar|ver)\b/u', $lower)) {
            return 48;
        }

        return 62;
    }

    public function taskTitle(string $title): string
    {
        return mb_substr(trim($title) ?: 'Definir próxima ação do projeto', 0, 180);
    }

    public function taskDescription(AtlasProject $project, ?Capture $capture, array $plan): ?string
    {
        $parts = array_filter([
            $project->goal ? "Objetivo: {$project->goal}" : null,
            isset($plan['minimum_viable_action']) ? "Ação mínima: {$plan['minimum_viable_action']}" : null,
            isset($plan['starter_step']) ? "Passo inicial: {$plan['starter_step']}" : null,
            $capture?->content_text ? "Fonte: {$capture->content_text}" : null,
        ]);

        return $parts ? implode("\n", $parts) : null;
    }

    public function stepTaskDescription(AtlasProject $project, AtlasProjectStep $step, ?Capture $capture): ?string
    {
        $parts = array_filter([
            $project->goal ? "Objetivo: {$project->goal}" : null,
            $step->description ? "Etapa: {$step->description}" : null,
            $step->expected_output ? "Saída esperada: {$step->expected_output}" : null,
            $step->acceptance_criteria ? "Critério: {$step->acceptance_criteria}" : null,
            $capture?->content_text ? "Fonte: {$capture->content_text}" : null,
        ]);

        return $parts ? implode("\n", $parts) : null;
    }

    public function executionModeForStep(AtlasProject $project, AtlasProjectStep $step): string
    {
        if ($project->project_type === 'study') {
            return 'study';
        }
        if ($project->project_type === 'technical_build' || $step->estimated_minutes >= 50) {
            return 'deep_work';
        }
        if ($project->project_type === 'tedious') {
            return 'tedious';
        }
        if ($step->step_type === 'review') {
            return 'maintenance';
        }

        return 'quick_win';
    }

    public function starterForStep(AtlasProjectStep $step): string
    {
        return match ($step->step_type) {
            'review' => 'Abrir o registro do projeto e revisar apenas a etapa atual.',
            'milestone' => 'Conferir a saída esperada e marcar evidência.',
            default => 'Abrir o contexto do projeto e fazer somente esta etapa.',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function planFromStep(AtlasProject $project, AtlasProjectStep $step): array
    {
        return [
            'project_type' => $project->project_type,
            'priority' => $project->priority,
            'deadline_at' => $project->deadline_at,
            'next_action' => $step->title,
            'estimated_minutes' => $step->estimated_minutes,
            'energy_required' => $step->energy_required,
            'friction_level' => $step->friction_level,
            'emotional_resistance' => $project->avoidance_reason === 'unknown' ? 45 : 65,
            'clarity_level' => 80,
            'starter_step' => $this->starterForStep($step),
            'minimum_viable_action' => $step->expected_output,
            'if_then_plan' => $this->ifThenPlan((string) $project->project_type),
            'reward_hint' => $this->rewardHint((string) $project->project_type),
            'process_steps' => $this->processSteps((string) $project->project_type, $project->title),
        ];
    }
}
