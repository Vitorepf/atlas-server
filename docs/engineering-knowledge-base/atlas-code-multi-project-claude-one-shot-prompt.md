---
id: atlas-code-multi-project-claude-one-shot-prompt
type: engineering_knowledge
title: Atlas Code Multi-Project Claude One-Shot Prompt
status: active
implementation_state: runbook_no_runtime
authority_class: runbook
category: programming-forge
priority: 100
summary: Full implementation prompt for an external Claude Code agent to bring the Atlas Code multi-project workspace, consultations, quick interventions and Obra candidates to life without breaking the Atlas Code design system.
tags:
  - atlas-code
  - claude-prompt
  - multi-project
  - workspace
  - implementation
capabilities:
  - external_agent_prompt
  - multi_project_workspace_prompt
  - atlas_code_implementation
decisions:
  - External agents must treat Atlas Code as a heavy-programming cockpit, not a generic chat or IDE.
  - The implementation must preserve the existing Atlas Desktop visual language and Code surface composition.
  - Project/Workspace is above Consulta, Intervencao Rapida, Candidato de Obra and Obra.
maintenance:
  - Update when the multi-project workspace contract, Atlas Code surface or implementation plan changes.
related_paths:
  - docs/engineering-knowledge-base/atlas-code-multi-project-workspace-os.md
  - docs/engineering-knowledge-base/atlas-code-programming-obras-operating-system.md
  - docs/engineering-knowledge-base/atlas-code-attention-control-plane-v1.md
  - docs/engineering-knowledge-base/atlas-desktop-code-surface.md
  - ../atlas-desktop/apps/desktop/src/surfaces/code/
  - ../atlas-desktop/apps/desktop/src/surfaces/cartografia/
owner: programming
layer: 2.1-multi-project-workspace
line_limit: 520
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-code-multi-project-claude-one-shot-prompt
graph_title: Atlas Code Multi-Project Claude One-Shot Prompt
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-code-multi-project-workspace-os
graph_status: active
graph_source: repo
human_name: Atlas Code Multi-Project Claude One-Shot Prompt
canonical_name: Atlas Code Multi-Project Claude One-Shot Prompt
technical_name: atlas-code-multi-project-claude-one-shot-prompt
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/atlas-code-multi-project-claude-one-shot-prompt.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-code-multi-project-claude-one-shot-prompt.md
allowed_changes:
  - Refine the prompt when implementation contracts change.
forbidden_changes:
  - Remove the product identity or design-system constraints from the prompt.
depends_on:
  - atlas-code-multi-project-workspace-os
flows_to:
  - atlas-code
  - external-agent-implementation
unlocks:
  - claude-one-shot-implementation
governs:
  - external_agent.prompt.multi_project_workspace
evidence:
  - docs/engineering-knowledge-base/atlas-code-multi-project-claude-one-shot-prompt.md
evidence_refs:
  - symbol: AtlasCodeMultiProjectClaudeOneShotPromptService
  - command: atlas:aaeos:atlas-code-multi-project-claude-one-shot-prompt
  - test: AtlasCodeMultiProjectClaudeOneShotPromptTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
visual_tags:
  - prompt
  - implementation
ai_entrypoints:
  - Use this prompt when sending the multi-project workspace implementation to Claude Code.
ai_usage_notes:
  - This is an external-agent prompt artifact, not runtime code.
quality_gates:
  - docs-health
  - implementation-tests
failure_modes:
  - Agent implements a generic sidebar tree instead of active Project scoping.
  - Agent treats Blackink as an Obra.
observability_signals:
  - prompt_version
next_actions:
  - Run this prompt in Claude Code from the Atlas workspace root.
---
# Atlas Code Multi-Project Claude One-Shot Prompt

## Resumo

Prompt grande para entregar a um agente Claude Code. O objetivo e implementar a primeira versao solida do Atlas Code multi-projeto sem diluir a identidade do Atlas Code.

## Papel no Atlas

Use este prompt para ativar agentes externos. Ele deve fazer o agente ler os contratos, entender o design system atual, implementar backend/frontend/testes e validar tudo.

## Onde Se Encaixa

Este prompt implementa:

```text
Active Project/Workspace
-> Cartografia | Code | Atencao
-> Consultas | Intervencoes Rapidas | Candidatos de Obra | Obras
```

## Contratos

Copie o prompt abaixo integralmente para Claude Code:

```text
Voce esta trabalhando no repositorio Atlas em /Users/vitorepf/develop/Atlas.

Objetivo maximo do produto:
Atlas Code existe para ser a ferramenta mais poderosa do mundo para programacao de software assistida por IA em tarefas ultra-hard, problemas dificeis, contextos longos e sessoes extremamente longas. Nao transforme Atlas Code em IDE generica, chat generico, dashboard bonito ou produto leve.

Missao desta sessao:
Dar vida a estrutura multi-projeto do Atlas Desktop/Atlas Code, criando a primeira versao profissional de Project/Workspace scoping para que Atlas Code e Cartografia nao sejam Atlas-only. O Atlas deve conseguir operar Atlas, Blackink e outros projetos. Projeto/Workspace e onde o software vive. Obra e uma unidade de trabalho governada dentro de um Projeto.

Leia antes de alterar arquivos:
- atlas-server/docs/engineering-knowledge-base/atlas-code-multi-project-workspace-os.md
- atlas-server/docs/engineering-knowledge-base/atlas-code-programming-obras-operating-system.md
- atlas-server/docs/engineering-knowledge-base/atlas-code-attention-control-plane-v1.md
- atlas-server/docs/engineering-knowledge-base/atlas-desktop-code-surface.md
- atlas-server/docs/engineering-knowledge-base/atlas-code-obra-command-center-v1.md
- atlas-server/docs/engineering-knowledge-base/atlas-programming-forge-flow.md

Codigo essencial a inspecionar:
- atlas-desktop/apps/desktop/src/surfaces/code/CodeSurface.tsx
- atlas-desktop/apps/desktop/src/surfaces/code/CodeSurfaceLayout.tsx
- atlas-desktop/apps/desktop/src/surfaces/code/leftRail/
- atlas-desktop/apps/desktop/src/surfaces/code/obra/
- atlas-desktop/apps/desktop/src/surfaces/code/stage/
- atlas-desktop/apps/desktop/src/surfaces/code/panels/
- atlas-desktop/apps/desktop/src/surfaces/cartografia/
- atlas-desktop/apps/desktop/src/hooks/useBridge.ts
- atlas-desktop/apps/desktop/src/lib/bridge.ts
- atlas-server/app/Http/Controllers/AtlasCodeWorkController.php
- atlas-server/app/Services/Ai/Programming/
- atlas-server/routes/api.php
- atlas-server/app/Models/AtlasProject.php
- atlas-server/app/Models/AtlasProgrammingWorkItem.php

Design system obrigatorio:
- Preserve a estetica atual do Atlas Code: dark blue/ink, bronze/gold accents, dense operational cockpit, mono labels, subtle borders, no landing page, no marketing hero.
- Nao usar cards gigantes decorativos.
- Nao aninhar cards dentro de cards.
- Nao criar uma arvore lateral enorme como contrato principal.
- A regra e active Project scoping, nao file explorer.
- UI deve continuar parecendo Atlas Code: profissional, densa, governada, com foco em trabalho real.
- Use componentes e estilos existentes sempre que possivel.
- Nao introduza uma paleta nova.
- Nao use emoji.

Conceitos obrigatorios:
- Project/Workspace: produto/repositorio/conjunto de repositorios onde software vive.
- Project Profile: ficha operacional do projeto: name, repo_root/workspace_path, production_status, stack_summary, commands, tests, build, critical_areas, docs_status, default_risk.
- Consulta: conversa leve dentro de um Projeto. Pode explicar codigo, pesquisar, tirar duvida. Nao altera codigo.
- Intervencao Rapida: mudanca pequena, clara e reversivel dentro de um Projeto. Tem objetivo, risco, arquivos, verificacao e mini evidence.
- Candidato de Obra: descoberta estruturada antes de virar Obra. Nao executa Forge.
- Obra: entrega governada dentro de Projeto, com intake, gates, evidence e aceite.
- Blackink e Atlas sao Projetos/Workspaces, nao Obras.

Implementacao esperada, primeira versao solida:

1. Backend Project/Workspace Profile
- Criar uma camada simples e robusta para listar Project Profiles.
- Pode usar config/service/read-model antes de criar uma model pesada, desde que seja extensivel.
- Deve retornar pelo menos Atlas como projeto padrao.
- Deve permitir Blackink como profile configuravel mesmo se repo_path nao existir ainda.
- Campos minimos: id, name, slug, kind, workspace_path, repo_root, production_status, stack_summary, commands, test_commands, build_commands, dev_server_command, critical_areas, docs_status, default_risk, created_at/updated_at se aplicavel.
- Criar endpoint API em /api/atlas-code/workspaces ou /api/atlas-code/projects/workspaces, seguindo padroes atuais.
- Nunca quebrar /api/atlas-code/works existente.

2. Active Project no Desktop
- Adicionar estado de activeProject/activeWorkspace no bridge/useBridge.
- Carregar Project Profiles no boot ou no fluxo da Code surface.
- Default deve ser Atlas se nada estiver selecionado.
- Persistir selecao no storage local se o projeto ja usa esse padrao.
- Mostrar no topo/label que o usuario esta em Atlas Code escopado: "Atlas · Code" ou "Blackink · Code".
- Quando Cartografia estiver ativa, mostrar "Atlas · Cartografia" ou "Blackink · Cartografia".

3. Atlas Code scoping
- LeftRail deve mostrar Obras filtradas pelo Project ativo quando o dado existir.
- Criacao de Obra deve anexar workspace/project context em metadata sem quebrar o endpoint atual.
- Se backend ainda nao filtrar perfeitamente, implementar fallback honesto: Atlas default e indicacao visual do workspace ativo.
- Nao misturar Obras de Blackink e Atlas como se fossem uma unica lista global.

4. Work type lanes dentro do Projeto
- Preparar a UI/estrutura para quatro tipos: Consultas, Intervencoes Rapidas, Candidatos de Obra, Obras.
- Nao precisa implementar execucao completa de todos os tipos se for grande demais, mas precisa deixar a arquitetura e read-model prontos para isso sem fake runtime.
- Consultas: podem ser rascunho/read-model; nao executar Forge.
- Intervencoes Rapidas: podem ser scaffold de schema/read-model; nao fazer patch real sem contrato.
- Candidatos de Obra: podem ser scaffold de schema/read-model; deve permitir promoted_to_obra no futuro.
- Obras: manter fluxo existente.

5. Cartografia scoping
- Nao reescrever Cartografia inteira.
- Adicionar contrato/prop/read-model para activeProject quando simples.
- UI deve deixar claro se Cartografia e Global ou do Projeto.
- Nao fingir que Blackink tem cartografia completa se nao ha docs; mostrar docs_status incompleto.

6. Safety/risk
- Para project production_status=production, UI deve destacar risco padrao maior para Intervencao Rapida/Obra.
- Nunca rodar comando sem workspace_path/repo_root resolvido.
- Se workspace_path ausente, bloquear execucao e permitir apenas Consulta/Descoberta.

7. Testes e validacao
- Rodar testes existentes relevantes.
- Adicionar testes de backend se criar endpoints/services.
- Rodar git diff --check.
- Rodar docs-health se docs forem alterados.
- Se frontend build/test existir e for razoavel, rodar.
- Nao mascarar falhas.

Arquitetura desejada:
- Evite mega componente.
- Crie tipos em packages/atlas-domain se o projeto usa dominio compartilhado.
- Crie service/backend coeso.
- Preserve nomes atuais e compatibilidade.
- Favor pequenos passos integraveis em vez de refatoracao total.

Critérios de aceite:
- Existe um Project/Workspace read-model real.
- Desktop mostra o Projeto ativo.
- Atlas Code continua funcionando para Obras existentes.
- Criar/selecionar Obra nao quebra.
- UI nao vira arvore lateral gigante.
- Design system atual foi preservado.
- Atlas e Blackink sao tratados como Projetos, nao Obras.
- O codigo deixa caminho claro para Consulta, Intervencao Rapida e Candidato de Obra.
- Nao ha runtime fake dizendo que algo esta implementado sem estar.
- Testes/validacoes relatados no final.

Resposta final esperada:
- Liste arquivos alterados.
- Explique o que foi implementado.
- Explique o que ficou como scaffold honesto.
- Liste comandos de validacao e resultados.
- Aponte riscos/residuos.
```

## Fluxo

1. Rodar o prompt em Claude Code no workspace raiz.
2. Pedir que o agente leia docs antes de editar.
3. Exigir resposta final com arquivos, testes e residuos.

## Regras para IA

- Nao usar este prompt se o agente nao tiver acesso ao repo local.
- Nao aceitar implementacao que confunda Projeto com Obra.
- Nao aceitar UI que substitua Atlas Code por chat generico.

## Escopo de Implementacao

O prompt mira uma primeira fatia profissional. Ele nao exige completar todos os runtimes de Consulta, Intervencao Rapida e Candidato de Obra, mas exige arquitetura honesta e extensivel.

## Dependencias

- `atlas-code-multi-project-workspace-os`
- `atlas-code-programming-obras-operating-system`
- `atlas-desktop-code-surface`

## Evidencias

Este arquivo e a evidencia do prompt principal.

## Riscos

- Agente externo tentar reconstruir a UI inteira.
- Agente criar arvore lateral confusa.
- Agente implementar dados fake.

## Exemplos

Uso:

```text
Cole o bloco do prompt em Claude Code no repo Atlas e rode.
```

## Proximas Acoes

1. Rodar este prompt no primeiro Claude Code.
2. Depois rodar o prompt curto `/goal`.
