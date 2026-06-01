---
id: atlas-code-multi-project-claude-goal-prompt
type: engineering_knowledge
title: Atlas Code Multi-Project Claude Goal Prompt
status: active
category: programming-forge
priority: 100
summary: Short /goal prompt for an external Claude Code agent to finish, harden and validate the Atlas Code multi-project workspace implementation professionally.
tags:
  - atlas-code
  - claude-prompt
  - goal
  - multi-project
capabilities:
  - external_agent_goal_prompt
  - implementation_hardening
decisions:
  - The goal prompt must force completion, validation and honest residual risk reporting.
  - The goal prompt must preserve the maximum product priority of Atlas Code.
maintenance:
  - Update when the one-shot prompt or multi-project implementation contract changes.
related_paths:
  - docs/engineering-knowledge-base/atlas-code-multi-project-claude-one-shot-prompt.md
  - docs/engineering-knowledge-base/atlas-code-multi-project-workspace-os.md
owner: programming
layer: 2.1-multi-project-workspace
line_limit: 520
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-code-multi-project-claude-goal-prompt
graph_title: Atlas Code Multi-Project Claude Goal Prompt
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-code-multi-project-claude-one-shot-prompt
graph_status: active
graph_source: repo
human_name: Atlas Code Multi-Project Claude Goal Prompt
canonical_name: Atlas Code Multi-Project Claude Goal Prompt
technical_name: atlas-code-multi-project-claude-goal-prompt
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/atlas-code-multi-project-claude-goal-prompt.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-code-multi-project-claude-goal-prompt.md
allowed_changes:
  - Refine prompt wording while preserving the completion contract.
forbidden_changes:
  - Remove validation, design-system or honest residual-risk requirements.
depends_on:
  - atlas-code-multi-project-claude-one-shot-prompt
flows_to:
  - external-agent-hardening
unlocks:
  - claude-goal-hardening
governs:
  - external_agent.goal.multi_project_workspace
evidence:
  - docs/engineering-knowledge-base/atlas-code-multi-project-claude-goal-prompt.md
evidence_refs:
  - symbol: AtlasCodeMultiProjectClaudeGoalPromptService
  - command: atlas:aaeos:atlas-code-multi-project-claude-goal-prompt
  - test: AtlasCodeMultiProjectClaudeGoalPromptTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: medium
visual_tags:
  - prompt
  - goal
ai_entrypoints:
  - Use after the one-shot prompt to force completion and validation.
ai_usage_notes:
  - This is a prompt artifact, not runtime code.
quality_gates:
  - docs-health
  - tests-reported
failure_modes:
  - Agent stops at partial scaffolding without validation.
observability_signals:
  - prompt_version
next_actions:
  - Run after the one-shot implementation prompt.
---
# Atlas Code Multi-Project Claude Goal Prompt

## Resumo

Prompt curto para finalizar e endurecer a implementacao multi-projeto apos o prompt grande.

## Papel no Atlas

Forca o agente externo a terminar de forma profissional, completa e honesta.

## Onde Se Encaixa

Use depois de `atlas-code-multi-project-claude-one-shot-prompt.md`.

## Contratos

Copie este prompt curto para Claude Code:

```text
/goal
Finalize a implementacao do Atlas Code Multi-Project Workspace OS com qualidade profissional.

Obrigatorio:
- Leia e cumpra docs/engineering-knowledge-base/atlas-code-multi-project-workspace-os.md.
- Preserve a identidade maxima do Atlas Code: ferramenta mais poderosa para programacao IA ultra-hard, contextos longos e sessoes longas.
- Preserve o design system atual da tela Atlas Code. Nao transforme em chat generico, IDE generica, landing page ou arvore lateral confusa.
- Garanta a separacao: Projeto/Workspace e onde o software vive; Obra e trabalho governado dentro do Projeto.
- Atlas e Blackink sao Projetos, nao Obras.
- Cartografia e Code devem operar dentro do Projeto ativo.
- Consultas, Intervencoes Rapidas, Candidatos de Obra e Obras devem ser project-scoped.
- Nao crie runtime fake. Se algo ficou scaffold, marque honestamente.
- Garanta que nenhum comando/execucao rode sem workspace/repo resolvido.
- Rode testes/validacoes possiveis e reporte resultados.
- Corrija regressões obvias antes de finalizar.

Definition of done:
- Project/Workspace read-model existe.
- Desktop mostra Projeto ativo.
- Atlas Code ainda funciona para Obras existentes.
- O caminho para Blackink/outros projetos esta claro.
- Design visual continua consistente com Atlas Code.
- Validacoes executadas e reportadas.
- Resposta final lista arquivos alterados, comandos rodados, riscos e proximos passos.
```

## Fluxo

1. Cole o prompt no agente depois da primeira implementacao.
2. Exija que ele rode validacoes.
3. Exija que ele reporte residuos sem maquiar.

## Regras para IA

- Nao aceitar resposta sem arquivos alterados ou sem validacoes.
- Nao aceitar conclusao sem riscos e proximos passos.

## Escopo de Implementacao

Fechamento, hardening, testes e reparos da primeira fatia.

## Dependencias

- `atlas-code-multi-project-claude-one-shot-prompt`
- `atlas-code-multi-project-workspace-os`

## Evidencias

Este arquivo e o prompt de fechamento.

## Riscos

- O agente pode fingir completion sem testes.
- O agente pode deixar scaffold ambigua.

## Exemplos

Uso:

```text
Depois do prompt grande, cole este /goal no mesmo Claude Code.
```

## Proximas Acoes

1. Rodar o prompt grande.
2. Rodar este `/goal`.
3. Revisar diff e validar localmente.
