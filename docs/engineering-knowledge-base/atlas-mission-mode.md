---
id: atlas-mission-mode
type: engineering_knowledge
title: Atlas Mission Mode
status: active
category: atlas-ai
priority: 100
summary: Doutrina operacional que transforma pedidos nao triviais em missoes persistentes com Definition of Done, evidencias, gates, loop ate conclusao e blocker real em vez de respostas rapidas, fracas ou incompletas.
tags:
  - atlas-ai
  - mission-mode
  - goal-first
  - execution-quality
  - evidence
capabilities:
  - goal_first_execution
  - mission_definition
  - evidence_driven_completion
  - quality_gates
  - blocker_reporting
  - completion_audit
decisions:
  - Todo pedido nao trivial deve virar missao interna, mesmo quando o usuario nao usar /goal.
  - Missao so termina com evidencia verificavel, certification ou blocker real.
  - Resposta rapida e aceitavel apenas para pedido trivial, factual ou claramente limitado.
  - Analise ampla, implementacao, pesquisa, debug, review, automacao e engenharia sempre exigem DoD explicito.
  - O Atlas deve evitar o padrao de agente que "da uma olhada" e responde cedo demais.
maintenance:
  - Atualize este doc antes de mudar comportamento padrao de metas, missoes ou conclusao.
  - Nao relaxe evidence refs, gates ou completion audit para melhorar velocidade aparente.
related_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-runtime.md
  - docs/engineering-knowledge-base/atlas-autonomous-engineering-operating-system.md
  - docs/engineering-knowledge-base/atlas-real-engineering-execution-kernel.md
  - docs/engineering-knowledge-base/atlas-hyperflow-operation.md
  - docs/engineering-knowledge-base/atlas-compounding-engineering-intelligence.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-mission-mode
graph_title: Atlas Mission Mode
graph_world: atlas
graph_layer: system
graph_kind: policy
graph_parent: atlas-ai
graph_status: active
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-mission-mode.md
allowed_changes:
  - Refinar classificacao entre trivial, task, mission e obra.
  - Adicionar gates por dominio quando novos runtimes entrarem no Atlas.
forbidden_changes:
  - Permitir que pedido nao trivial termine sem evidencia ou blocker.
  - Tratar "analise tudo" como leitura superficial.
  - Marcar missao completa por intencao, plausibilidade ou resumo sem audit.
depends_on:
  - atlas-ai-router-runtime-enterprise-upgrade
  - atlas-autonomous-software-company-runtime
  - atlas-autonomous-engineering-operating-system
flows_to:
  - atlas_autonomous_software_company_runtime
  - atlas_research
  - atlas_dev
  - atlas_debug
  - atlas_review
  - atlas_forge
unlocks:
  - atlas_goal_first_default_behavior
  - atlas_high_quality_autonomous_execution
governs:
  - atlas_ai.default_execution_policy
  - atlas_ai.completion_policy
evidence:
  - docs/engineering-knowledge-base/atlas-mission-mode.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Leia Resumo, Classificacao, Fluxo, Regras para IA, Evidencias, Riscos e Definition of Done antes de alterar comportamento de execucao.
quality_gates:
  - mission-classified
  - dod-created
  - sources-or-context-identified
  - execution-plan-ready
  - evidence-collected
  - tests-or-validation-run
  - completion-audit-finished
  - blocker-or-certification-emitted
failure_modes:
  - Responder cedo demais.
  - Fazer analise superficial de escopo amplo.
  - Ignorar fontes obrigatorias.
  - Nao rodar testes/gates disponiveis.
  - Declarar completo sem checklist de requisitos.
  - Esconder blocker como recomendacao vaga.
observability_signals:
  - mission_id
  - mission_class
  - definition_of_done
  - evidence_refs
  - gates_run
  - blockers
  - certification_status
  - completion_audit_hash
next_actions:
  - Integrar Mission Mode ao Router/Hyperflow como politica padrao.
  - Criar contratos e persistencia de mission quando o runtime for implementado.
line_limit: 520
---
# Atlas Mission Mode

## Resumo

Atlas Mission Mode e a doutrina padrao para impedir respostas fracas,
superficiais ou incompletas. Todo pedido nao trivial vira uma missao interna
com objetivo, escopo, Definition of Done, evidencias, gates, audit de conclusao
e blocker real quando nao for possivel terminar.

O usuario nao precisa sempre escrever `/goal`. O Atlas deve perceber quando o
pedido exige qualidade de missao.

## Papel no Atlas

Mission Mode fica acima dos runtimes especializados. Ele define quando o Atlas
pode responder direto e quando precisa abrir um ciclo serio de execucao.

Ele governa:

- analise profunda;
- pesquisa;
- implementacao;
- debug;
- review;
- automacao;
- criacao de ferramentas;
- trabalhos multi-etapa;
- qualquer pedido com alto risco de resposta superficial.

## Onde Se Encaixa

```text
User prompt
-> Mission classifier
-> trivial answer OR mission
-> Router / Hyperflow
-> Company Runtime / Research / Dev / Debug / Forge
-> evidence
-> completion audit
-> final answer or blocker
```

Mission Mode nao substitui o Company Runtime. Ele decide quando ativar esse
tipo de runtime e qual qualidade minima a resposta precisa atingir.

## Contratos

- `atlas.ai.mission.v1`
- `atlas.ai.mission.definition_of_done.v1`
- `atlas.ai.mission.execution_plan.v1`
- `atlas.ai.mission.evidence_pack.v1`
- `atlas.ai.mission.completion_audit.v1`
- `atlas.ai.mission.certification.v1`

Campos minimos:

- `mission_id`;
- `user_prompt`;
- `mission_class`;
- `objective`;
- `scope`;
- `definition_of_done`;
- `required_sources`;
- `required_gates`;
- `evidence_refs`;
- `blockers`;
- `status`;
- `next_action`;
- `receipt_hash`.

## Classificacao

### Trivial

Pedido simples, factual ou local, sem risco real.

Exemplos:

- "que horas sao?"
- "explique em uma frase"
- "qual comando lista arquivos?"

Pode responder direto.

### Task

Pedido pequeno, mas exige acao verificavel.

Exemplos:

- corrigir um teste especifico;
- ajustar uma doc pequena;
- rodar um comando e resumir output.

Precisa evidencia leve.

### Mission

Pedido amplo, ambigue, multi-etapa ou de alta qualidade.

Exemplos:

- "analise toda a documentacao do Atlas Dev";
- "pesquise oportunidades de mercado";
- "corrija esse bug complexo";
- "crie uma automacao para Instagram";
- "implemente esse modulo completo".

Precisa DoD, plano, evidencias, gates e audit.

### Obra

Missao grande, longa, enterprise ou multi-ciclo.

Exemplos:

- refatorar um subsistema;
- criar produto inteiro;
- conduzir auditoria extensa;
- migrar arquitetura.

Deve promover para Forge ou runtime equivalente.

## Fluxo

1. Receber prompt.
2. Classificar como trivial, task, mission ou obra.
3. Para mission/obra, criar objetivo explicito.
4. Definir escopo e fora de escopo.
5. Criar Definition of Done.
6. Identificar fontes, arquivos, ferramentas e gates obrigatorios.
7. Selecionar runtime: Research, Dev, Debug, Review, Company Runtime ou Forge.
8. Executar ate completar, falhar com blocker ou pedir decisao necessaria.
9. Registrar evidencias.
10. Rodar audit de conclusao.
11. Responder com resultado, evidencias e blockers restantes.

## Regras Para IA

- Nao responder cedo para pedido amplo.
- Nao interpretar "analise tudo" como leitura parcial.
- Nao dizer "concluido" sem checklist de DoD.
- Nao esconder falta de contexto; declarar blocker.
- Nao rodar ferramenta cara/externa sem regra de permissao aplicavel.
- Nao confundir evidencia com intencao.
- Nao usar teste verde como prova se ele nao cobre o requisito.
- Nao rebaixar missao para task para terminar mais rapido.

## Escopo de Implementacao

Implementacao completa deve ter:

- mission classifier;
- mission record;
- Definition of Done builder;
- evidence pack;
- completion audit;
- certification;
- integração com Router/Hyperflow;
- integração com Company Runtime e Forge;
- policy de resposta final;
- testes de trivial/task/mission/obra.

## Dependencias

- Router Runtime para classificar intencao.
- Company Runtime para engenharia de software.
- Research Runtime para pesquisa profunda.
- Real Execution Kernel para execucao real.
- Forge para Obras.
- Compounding para aprendizado.
- Evidence Ledger/receipts para auditabilidade.

## Evidencias

Evidencia aceitavel depende da missao:

- arquivos lidos;
- fontes externas com links;
- comandos rodados;
- testes executados;
- screenshots ou artifacts;
- diffs;
- receipts;
- hashes;
- delivery packs;
- blockers com causa real.

## Riscos

- Aumentar qualidade reduz velocidade aparente.
- Missoes podem consumir muito tempo se escopo nao for controlado.
- O Atlas pode pedir permissao quando uma ferramenta externa tiver custo, login
  ou risco legal.
- Completion audit ruim pode virar burocracia sem qualidade.

## Exemplos

Prompt:

```text
analise toda a documentacao e fluxo do Atlas Dev
```

Mission Mode deve:

- mapear docs relevantes;
- ler fontes obrigatorias;
- identificar contradicoes;
- comparar fluxo real com docs;
- registrar lacunas;
- entregar conclusao com evidencias.

Prompt:

```text
faca uma automacao no Instagram
```

Mission Mode deve:

- checar objetivo real;
- avaliar API oficial, browser automation e restricoes;
- identificar risco tecnico/legal;
- propor ou implementar caminho permitido;
- declarar blocker se login/politica impedir.

## Proximas Acoes

1. Implementar Mission Classifier.
2. Criar contratos `atlas.ai.mission.*`.
3. Integrar com Router/Hyperflow.
4. Fazer Company Runtime consumir mission records.
5. Criar completion audit obrigatorio.
6. Testar respostas amplas contra regressao de superficialidade.

## Definition of Done

Mission Mode esta pronto quando:

- pedidos triviais continuam rapidos;
- pedidos nao triviais viram mission automaticamente;
- cada mission tem DoD e evidence pack;
- resposta final cita o que foi verificado;
- blocker real substitui resposta vaga;
- completion audit impede falso completo;
- Company Runtime e Forge respeitam a missao;
- docs-health passa;
- testes cobrem trivial, task, mission, obra e blocker.

