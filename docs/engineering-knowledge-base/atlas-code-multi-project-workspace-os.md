---
id: atlas-code-multi-project-workspace-os
type: engineering_knowledge
title: Atlas Code Multi-Project Workspace OS
status: active
category: programming-forge
priority: 100
summary: Canonical contract that Atlas Desktop Cartografia and Atlas Code must operate inside a selected software Project/Workspace, so Atlas can program Atlas, Blackink and any other repository without confusing Project with Obra.
tags:
  - atlas-code
  - atlas-desktop
  - multi-project
  - workspace
  - programming-obras
capabilities:
  - multi_project_workspace
  - project_scoped_cartography
  - project_scoped_atlas_code
  - programming_workspace_profile
decisions:
  - O termo Workspace OS neste doc e escopo local de projeto/workspace para Atlas Code; ele nao compete com Atlas Agentic Engineering OS, Programming Governance ou Forge Continuum.
  - Atlas Code is not Atlas-only; it must support multiple software projects and repositories.
  - Project/Workspace is the selected software context; Obra is a governed work unit inside that context.
  - Cartografia and Atlas Code remain primary surfaces, but both must be scoped by the active Project/Workspace.
  - Blackink, Atlas and other products are Projects/Workspaces, not Obras.
  - Consultation, Quick Intervention, Obra Candidate and Obra all belong to a Project/Workspace.
maintenance:
  - Update before changing Atlas Desktop navigation, project selection, Cartografia scoping, Atlas Code work lists, workspace paths or Obra creation.
related_paths:
  - docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md
  - docs/engineering-knowledge-base/atlas-code-multi-project-claude-one-shot-prompt.md
  - docs/engineering-knowledge-base/atlas-code-multi-project-claude-goal-prompt.md
  - docs/engineering-knowledge-base/atlas-code-programming-obras-operating-system.md
  - docs/engineering-knowledge-base/atlas-code-attention-control-plane-v1.md
  - docs/engineering-knowledge-base/atlas-ai-conversation-surface-and-atlas-dev-v1.md
  - docs/engineering-knowledge-base/atlas-desktop-code-surface.md
  - docs/engineering-knowledge-base/atlas-cartographic-knowledge-os.md
  - docs/engineering-knowledge-base/atlas-ai-obras-operating-system.md
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - ../atlas-desktop/apps/desktop/src/surfaces/code/
  - ../atlas-desktop/apps/desktop/src/surfaces/cartografia/
owner: programming
layer: 2.1-multi-project-workspace
line_limit: 520
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-code-multi-project-workspace-os
graph_title: Atlas Code Multi-Project Workspace OS
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-desktop-code-surface
graph_status: active
graph_source: repo
human_name: Atlas Code Multi-Project Workspace OS
canonical_name: Atlas Code Multi-Project Workspace OS
technical_name: atlas-code-multi-project-workspace-os
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-code-multi-project-workspace-os.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-code-multi-project-workspace-os.md
allowed_changes:
  - Refine project/workspace schema and UX when Atlas Desktop gains real project switching.
  - Add implementation links once project profile service, selector, APIs and tests exist.
forbidden_changes:
  - Treat this doc as the mother OS for Agentic Software Engineering, Programming Governance or Forge.
  - Treat a product repository such as Atlas or Blackink as a single Obra.
  - Make Cartografia or Atlas Code implicitly Atlas-only.
  - Create Obras without linking them to an owning Project/Workspace when the work is software-specific.
  - Use a lateral tree as the product contract; the contract is active Project/Workspace scoping.
depends_on:
  - atlas-desktop-code-surface
  - atlas-code-programming-obras-operating-system
  - atlas-ai-obras-operating-system
flows_to:
  - atlas-code
  - atlas-cartography
  - project-scoped-programming-obras
unlocks:
  - blackink-programming-workspace
  - multi-repository-atlas-code
  - project-scoped-consultations
  - project-scoped-quick-interventions
governs:
  - atlas_desktop.project_context
  - atlas_code.project_scoping
  - atlas_cartography.project_scoping
evidence:
  - docs/engineering-knowledge-base/atlas-code-multi-project-workspace-os.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
visual_tags:
  - atlas-code
  - workspace
  - project
ai_entrypoints:
  - Leia este doc antes de implementar seletor de projeto, workspace profile, Cartografia por projeto, Atlas Code multi-repo ou Blackink dentro do Atlas.
ai_usage_notes:
  - Este doc nao troca Cartografia/Code por uma arvore lateral. Ele define que as surfaces existentes devem ser escopadas pelo Projeto ativo.
  - Se houver duvida de hierarquia, o Authority Map vence: este doc e local a project/workspace scoping.
quality_gates:
  - docs-health
  - active-project-selected
  - workspace-profile-present
  - project-bound-work-items
failure_modes:
  - Confundir Projeto com Obra.
  - Mostrar Obras de todos os projetos como se fossem uma lista unica.
  - Rodar comando no repo errado.
  - Cartografia mostrar apenas Atlas quando o projeto ativo e Blackink.
observability_signals:
  - project_id
  - workspace_id
  - workspace_path_hash
  - active_surface
  - repo_root
  - obra_id
  - consultation_id
  - quick_intervention_id
  - obra_candidate_id
next_actions:
  - Criar Project/Workspace Profile para Atlas e Blackink.
  - Adicionar seletor de Projeto ativo no Atlas Desktop.
  - Filtrar Cartografia e Atlas Code pelo Projeto ativo.
---
# Atlas Code Multi-Project Workspace OS

## Resumo

Nota de autoridade: este nome usa `Workspace OS` para o escopo local de
Project/Workspace no Atlas Code. Ele nao e o sistema-mae da area; a hierarquia
completa vive em `atlas-agentic-software-engineering-authority-map.md`.

Atlas Desktop deve ser multi-projeto. `Cartografia` e `Code` continuam sendo as surfaces principais, mas elas precisam operar dentro de um Projeto/Workspace selecionado.

Regra central:

```text
Projeto/Workspace e onde o software vive.
Obra e uma unidade de trabalho governada dentro de um Projeto/Workspace.
```

Atlas e Blackink nao sao Obras. Eles sao Projetos/Workspaces. Dentro deles existem Consultas, Intervencoes Rapidas, Candidatos de Obra e Obras.

As Consultas e o uso diario de programacao devem seguir
`atlas-ai-conversation-surface-and-atlas-dev-v1.md`: Atlas AI e a conversa
persistente, Atlas Dev e o modo de programacao diaria, e Forge/Obra e a
promocao para trabalho pesado. Isso impede que uma pequena correcao na Blackink
vire Obra obrigatoria.

## Papel no Atlas

O Atlas Code existe para programacao pesada e contextos longos, mas ele nao pode ser Atlas-only. Para ser ferramenta real de desenvolvimento assistido por IA, ele precisa programar varios repositorios:

- Atlas;
- Blackink;
- outros produtos;
- bibliotecas;
- clientes;
- experimentos.

A UI conceitual correta nao e uma arvore gigante fixa. A regra correta e:

```text
Selecionar Projeto
-> usar Cartografia daquele Projeto
-> usar Code daquele Projeto
```

## Onde Se Encaixa

Camada correta:

```text
Atlas Desktop
-> Active Project/Workspace
-> Cartografia | Code | Atencao
```

Quando o projeto ativo e `Atlas`:

```text
Atlas · Cartografia
Atlas · Code
```

Quando o projeto ativo e `Blackink`:

```text
Blackink · Cartografia
Blackink · Code
```

As surfaces nao mudam de natureza. O contexto delas muda.

## Contratos

Definicoes:

- Projeto/Workspace: produto, repositorio ou conjunto de repositorios onde o software vive.
- Project Profile: ficha operacional do Projeto.
- Consulta: conversa leve dentro de um Projeto.
- Intervencao Rapida: mudanca pequena, clara e reversivel dentro de um Projeto.
- Candidato de Obra: descoberta estruturada antes de virar Obra.
- Obra: entrega governada, verificavel e persistente dentro de um Projeto.
- Cartografia de Projeto: mapa visual da documentacao, arquitetura, fluxos, riscos e evidencias daquele Projeto.

Hierarquia correta:

```text
Project/Workspace
-> Consultas
-> Intervencoes Rapidas
-> Candidatos de Obra
-> Obras
-> Cartografia
-> Docs
-> Evidence
```

Hierarquia proibida:

```text
Obra
-> Projeto inteiro
```

## Fluxo

Fluxo normal:

1. Humano seleciona Projeto ativo.
2. Atlas carrega Project Profile.
3. Cartografia mostra conhecimento daquele Projeto.
4. Code mostra trabalhos daquele Projeto.
5. Consulta, Intervencao Rapida, Candidato ou Obra sao criados vinculados ao Projeto.
6. Qualquer execucao usa o workspace/repo correto.
7. Evidence, docs e receipts ficam ligados ao Projeto e ao trabalho especifico.

Project Profile minimo:

```text
project_id
name
kind
repo_root
workspace_path
production_status
stack_summary
commands
test_commands
build_commands
dev_server_command
critical_areas
docs_status
default_risk
deployment_notes
```

Exemplo Blackink:

```text
Projeto: Blackink
Status: production
Docs: incompletas
Risco padrao: maior por estar em producao
Trabalhos pequenos: Intervencao Rapida
Trabalhos ambiguos: Candidato de Obra
Trabalhos grandes: Obra de Programacao
```

## Regras para IA

- Sempre pergunte ou inferira o Projeto ativo antes de orientar trabalho de codigo.
- Nunca trate `AtlasProject`/Obra como se fosse automaticamente o produto inteiro.
- Nunca misture Obras de Atlas e Blackink em uma lista unica sem escopo.
- Nunca rode comando sem workspace/repo explicitamente resolvido.
- Nunca usar Cartografia global como substituta da Cartografia de Projeto.
- Se o Projeto nao tem docs organizadas, criar Project Profile minimo antes de trabalho arriscado.
- Se o trabalho e pequeno e claro, sugerir Intervencao Rapida, nao Obra.
- Se o trabalho e ambiguo, sugerir Candidato de Obra.
- Se o trabalho e longo, arriscado ou estrutural, sugerir Obra.

## Escopo de Implementacao

UI esperada:

- seletor de Projeto ativo no topo ou lateral;
- label claro: `Atlas · Code`, `Blackink · Code`, `Atlas · Cartografia`, `Blackink · Cartografia`;
- lista de Obras filtrada pelo Projeto ativo;
- lista de Consultas/Intervencoes/Candidatos filtrada pelo Projeto ativo;
- Cartografia filtrada por Projeto, com opcao de Cartografia Global;
- indicador de repo/workspace atual antes de terminal ou execucao.

Nao implementar como:

- arvore lateral obrigatoria com todas as categorias abertas;
- duplicacao de Cartografia e Code dentro de cada item;
- troca de produto para um file explorer;
- Obra global que representa um produto inteiro.

## Dependencias

Depende de:

- `atlas-desktop-code-surface`;
- `atlas-code-programming-obras-operating-system`;
- `atlas-code-attention-control-plane-v1`;
- `atlas-ai-obras-operating-system`;
- `atlas-cartographic-knowledge-os`.

## Evidencias

Evidencias atuais:

- Atlas Code ja exibe `workspace_path` em Obras quando existe metadata.
- `AtlasCodeWorkController` usa `AtlasProject` como Obra/Work, mas isso ainda nao separa produto de Obra.
- Cartografia ja tem surface propria e poderia ser filtrada por fonte/projeto.
- O desktop hoje mostra `Atlas · Code` e `Atlas · Cartografia`, sinalizando que o escopo visual ja existe, mas ainda precisa virar contrato multi-projeto.

## Riscos

Riscos:

- Atlas Code continuar preso ao proprio Atlas.
- Blackink virar uma Obra artificial em vez de Projeto.
- Ajuste pequeno em produto production virar Obra pesada sem necessidade.
- Comando rodar no workspace errado.
- Cartografia de um projeto contaminar outro.

Mitigacao:

- Projeto ativo obrigatorio;
- Project Profile minimo;
- workspace path visivel;
- listas filtradas por Projeto;
- promocao explicita de Consulta/Intervencao/Candidato para Obra.

## Exemplos

Exemplo de uso correto:

```text
Projeto ativo: Blackink
Pedido: corrigir cor do botao de checkout
Classificacao: Intervencao Rapida
Workspace: repo Blackink
Verificacao: screenshot/teste visual
Nao cria Obra pesada.
```

Exemplo de Obra correta:

```text
Projeto ativo: Blackink
Pedido: reorganizar checkout e pagamentos
Classificacao: Candidato de Obra -> Obra de Programacao
Motivo: alto risco, production, multiplos modulos, criterios de aceite e gates.
```

## Proximas Acoes

1. Criar contrato de `Project Profile`.
2. Implementar seletor de Projeto ativo no Atlas Desktop.
3. Separar Projeto/Workspace de Obra no read-model do Atlas Code.
4. Fazer Cartografia aceitar escopo global ou Projeto.
5. Criar perfis iniciais para `Atlas` e `Blackink`.
