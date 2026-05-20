---
id: atlas-autonomous-intelligence-operating-system
type: engineering_knowledge
title: Atlas Autonomous Intelligence Operating System
status: active
category: atlas-ai
priority: 100
summary: Definicao canonica do Atlas AI como sistema operacional de inteligencia autonoma multi-dominio: Kernel comum, Mission Mode, Objective Intelligence, Domain Company Runtimes, Tool Economy, Evidence Ledger, Control Plane, certificacao e aprendizado continuo.
tags:
  - atlas-ai
  - autonomous-intelligence
  - operating-system
  - tool-use
  - research
  - automation
  - evidence
capabilities:
  - mission_mode
  - world_intelligence
  - deep_research
  - tool_selection
  - tool_creation
  - browser_automation
  - terminal_execution
  - github_execution
  - api_orchestration
  - software_company_runtime
  - evidence_certification
  - compounding_learning
  - domain_company_runtimes
  - enterprise_intelligence_holding
decisions:
  - Atlas AI e o Autonomous Intelligence OS multi-dominio; nao e apenas chat, provider, CLI, Codex wrapper ou fluxo de programacao.
  - O Autonomous Intelligence OS e o patamar acima do Software Company Runtime; software e um dominio, nao o universo inteiro.
  - O formato final do Atlas AI e Kernel comum + Domain Company Runtimes plugaveis + Evidence/Policy/Tool/Control Plane compartilhados.
  - Todo pedido complexo vira mission com DoD, contexto, ferramentas, evidencias, gates e certification.
  - O Atlas deve decidir quando pesquisar, usar ferramenta, clonar repositorio, chamar API, automatizar browser, criar ferramenta propria ou promover para Forge/Obra.
  - Execucao autonoma so e aceitavel com safety, permissao, escopo, logs e evidencia.
  - Superioridade contra sistemas externos exige benchmark real; sem evidencia, declarar apenas capacidade interna.
maintenance:
  - Atualize este doc antes de adicionar novo runtime de dominio, tool runtime ou politica global de autonomia.
  - Nao criar capacidades autonomas fora do Mission Mode, Evidence e Safety.
  - Nao permitir tool use externo sem contrato de risco, custo, autenticacao e evidencia.
related_paths:
  - docs/engineering-knowledge-base/atlas-mission-mode.md
  - docs/engineering-knowledge-base/atlas-objective-intelligence.md
  - docs/engineering-knowledge-base/atlas-domain-company-runtimes.md
  - docs/engineering-knowledge-base/atlas-domain-runtime-contract.md
  - docs/engineering-knowledge-base/atlas-ai-multi-domain-implementation-sequence.md
  - docs/engineering-knowledge-base/atlas-local-agent-memory-ingestion.md
  - docs/engineering-knowledge-base/atlas-tool-economy.md
  - docs/engineering-knowledge-base/atlas-world-model.md
  - docs/engineering-knowledge-base/atlas-strategic-reality-engine.md
  - docs/engineering-knowledge-base/atlas-permission-budget-safety-layer.md
  - docs/engineering-knowledge-base/atlas-experimentation-engine.md
  - docs/engineering-knowledge-base/atlas-evidence-truth-layer.md
  - docs/engineering-knowledge-base/atlas-autonomous-control-plane.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-runtime.md
  - docs/engineering-knowledge-base/atlas-autonomous-engineering-operating-system.md
  - docs/engineering-knowledge-base/atlas-real-engineering-execution-kernel.md
  - docs/engineering-knowledge-base/atlas-hyperflow-operation.md
  - docs/engineering-knowledge-base/atlas-compounding-engineering-intelligence.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-autonomous-intelligence-operating-system
graph_title: Atlas Autonomous Intelligence Operating System
graph_world: atlas
graph_layer: system
graph_kind: system
graph_parent: atlas-ai
graph_status: active
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
allowed_changes:
  - Adicionar novos dominios autonomos, tool runtimes, policies, gates e certificacoes.
  - Refinar regras de uso de ferramentas externas e criacao de ferramentas proprias.
forbidden_changes:
  - Fazer automacao externa sem avaliar risco tecnico, legal, autenticacao e custo.
  - Declarar missao completa sem evidence pack e completion audit.
  - Tratar pesquisa profunda como resumo rapido de web.
  - Usar repositorio de terceiros sem provenance, licenca e security scan quando aplicavel.
depends_on:
  - atlas-mission-mode
  - atlas-objective-intelligence
  - atlas-domain-company-runtimes
  - atlas-tool-economy
  - atlas-world-model
  - atlas-permission-budget-safety-layer
  - atlas-experimentation-engine
  - atlas-evidence-truth-layer
  - atlas-autonomous-control-plane
  - atlas-autonomous-software-company-runtime
  - atlas-hyperflow-operation
  - atlas-compounding-engineering-intelligence
flows_to:
  - atlas_research
  - atlas_automation
  - atlas_software_company_runtime
  - atlas_tool_builder
  - atlas_browser_runtime
  - atlas_terminal_runtime
  - atlas_github_runtime
  - atlas_forge
unlocks:
  - atlas_world_class_autonomous_intelligence
  - atlas_primary_operational_ai
governs:
  - atlas_ai.global_autonomous_execution
  - atlas_ai.tool_use_policy
  - atlas_ai.research_policy
  - atlas_ai.automation_policy
evidence:
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: critical
ai_entrypoints:
  - Leia Resumo, Identidade Canonica, Papel no Atlas, Dominios, Fluxo, Tool Policy, Safety, Debug e Definition of Done antes de implementar.
quality_gates:
  - mission-created
  - domain-selected
  - source-plan-ready
  - tool-plan-ready
  - safety-gate-passed
  - execution-evidence-collected
  - validation-complete
  - learning-recorded
  - certification-complete
failure_modes:
  - Responder com opiniao quando a missao exige pesquisa.
  - Escolher ferramenta errada por falta de discovery.
  - Clonar repo inseguro ou inutil sem auditoria.
  - Criar ferramenta propria quando uma confiavel ja existe.
  - Usar API/browser automation contra politica, custo ou login sem permissao.
  - Declarar concluido sem validar o resultado real.
observability_signals:
  - mission_id
  - domain_runtime
  - selected_tools
  - created_tools
  - source_quality_score
  - safety_status
  - execution_receipts
  - evidence_pack_hash
  - certification_status
  - learning_outcome_hash
next_actions:
  - Implementar runtime global depois de Mission Mode e Company Runtime.
  - Criar Tool Registry, Tool Builder e Tool Evolution Loop.
  - Criar Deep Research Runtime e Automation Runtime.
  - Implementar Evidence/Certification Runtime conforme `atlas-evidence-certification-runtime.md` (Meta 4) com EvidencePack, Receipt, Claim, Certification, Blocker e AuditEvent universais.
line_limit: 520
---
# Atlas Autonomous Intelligence Operating System

## Resumo

Atlas Autonomous Intelligence Operating System e a definicao canonica do Atlas
AI. Ele transforma uma meta complexa em inteligencia operacional: entender o que
o usuario realmente quer, pesquisar as melhores fontes, escolher ferramentas,
executar no ambiente digital, criar ferramentas quando necessario, validar o
resultado, aprender e entregar evidencia.

Software e apenas um dominio dentro desse OS. O objetivo maior e fazer o Atlas
operar como uma inteligencia autonoma superior para pesquisa, automacao,
engenharia, cyber security autorizado, financas, marketing, estrategia,
desenvolvimento humano, dados, ferramentas e execucao digital.

## Identidade Canonica

Atlas AI e o produto/sistema operacional de inteligencia. Ele nao e um provider,
uma tela, um agente isolado ou um fluxo de codigo. A forma final e:

```text
Atlas AI Surface
-> Mission Mode
-> Objective Intelligence
-> WorkOrder / Mission
-> Atlas Kernel
   -> Policy / Permission / Budget
   -> Tool Runtime
   -> Evidence Ledger
   -> Memory / Knowledge
   -> Control Plane
   -> Certification
-> Domain Company Runtimes
   -> Software, Cyber, Finance, Marketing, Strategy, Research, Personal
      Development, Operations, Automation e futuros dominios
```

O Atlas deve funcionar como uma holding de inteligencia operacional: cada dominio
e uma empresa digital especializada; o Kernel e o sistema comum que impede
duplicacao, improviso, risco nao governado e respostas fracas.

## Papel no Atlas

O Autonomous Intelligence OS governa qualquer trabalho nao trivial que exija
decisao, contexto, ferramenta ou execucao. Ele fica acima de:

- Mission Mode;
- Autonomous Software Company Runtime;
- Research Runtime;
- Automation Runtime;
- Tool Builder;
- Browser/Terminal/GitHub/API runtimes;
- Forge;
- Compounding Memory;
- Evidence/Certification.

## Onde Se Encaixa

```text
Atlas AI
-> Mission Mode
-> Autonomous Intelligence OS
   -> Atlas Kernel / WorkOrder / Evidence / Policy / Tool Runtime
   -> Research Company Runtime
   -> Software Company Runtime
   -> Cyber Security Company Runtime
   -> Finance / Investment Company Runtime
   -> Marketing / Growth Company Runtime
   -> Corporate Strategy / Venture Studio Runtime
   -> Personal Development / Learning Runtime
   -> Automation Runtime
   -> Tool Builder Runtime
   -> Data / Market Intelligence Runtime
   -> Browser / Terminal / GitHub / API Runtime
   -> Evidence / Safety / Certification
   -> Compounding Learning
```

Mission Mode decide quando um prompt vira missao. O Autonomous Intelligence OS
decide como essa missao sera resolvida no mundo digital.

## Objetivo

Construir um Atlas capaz de:

- entender prompt humano ambiguo;
- descobrir o verdadeiro objetivo;
- decompor meta grande em ciclos;
- pesquisar fontes valiosas e confiaveis;
- usar navegador, terminal, GitHub, APIs, banco, RAG, crawlers e ferramentas;
- decidir quando usar ferramenta existente;
- decidir quando clonar repositorio;
- decidir quando criar ferramenta propria;
- evoluir ferramenta propria com feedback;
- executar com safety, permissao e logs;
- validar resultado;
- gerar evidence pack;
- aprender com sucesso, erro, custo e oportunidade.

## Diferenca Para Company Runtime

| Camada | Escopo |
| --- | --- |
| Software Company Runtime | Engenharia de software |
| Autonomous Intelligence OS | Qualquer meta complexa de inteligencia e execucao digital |

O Company Runtime e o departamento de software dentro do Intelligence OS.
Pesquisa de mercado, automacao web, OSINT, tool building, data analysis,
browser automation e estrategia ficam no nivel do Intelligence OS.

## Dominios Oficiais

Dominio nao e agente solto. Dominio e uma empresa digital com charter, ontologia,
departamentos, workflows, agentes, ferramentas, memoria, policies, gates,
artefatos, metricas e certificacao. Todo dominio novo entra pelo contrato de
Domain Company Runtime.

- Deep Research: fontes fortes, contradicoes, citacoes, source quality e sintese.
- Market Intelligence: tendencias, vagas, empresas, dores, concorrentes e timing.
- Corporate Strategy / Venture Studio: oportunidades, empresas, TAM/SAM/SOM,
  unit economics, GTM, hiring, operacao e experimentos.
- Finance / Investment: research, valuation, portfolio, risk, compliance,
  reporting e trading somente por mandato, limites, approval e broker policy.
- Marketing / Growth: estrategia, ICP, campanhas, criativos, copy, funis,
  analytics e budget gates; sem publicacao/gasto sem permissao.
- Cyber Security: AppSec, GRC, remediation, defensive security e pentest/bug
  bounty somente com autorizacao, escopo, RoE e legal/privacy gates.
- Personal Development / Learning: estudo, habitos, rotina, pratica deliberada,
  feedback e desenvolvimento nao clinico.
- Software Engineering: Dev, Debug, Review, Security, QA, Forge e Delivery.
- Automation/Tools/Data: browser, terminal, GitHub, APIs, PDFs, planilhas,
  datasets, RAG, repositorios, tool builder e tool evolution.

## Contratos

- `atlas.ai.intelligence.mission.v1`
- `atlas.ai.intelligence.domain_decision.v1`
- `atlas.ai.intelligence.research_plan.v1`
- `atlas.ai.intelligence.tool_plan.v1`
- `atlas.ai.intelligence.tool_receipt.v1`
- `atlas.ai.intelligence.execution_receipt.v1`
- `atlas.ai.intelligence.safety_gate.v1`
- `atlas.ai.intelligence.evidence_pack.v1`
- `atlas.ai.intelligence.certification.v1`
- `atlas.ai.intelligence.learning_outcome.v1`

Campos minimos:

- `mission_id`;
- `domain_runtime`;
- `objective`;
- `definition_of_done`;
- `sources_required`;
- `tools_considered`;
- `tools_selected`;
- `tools_created`;
- `safety_status`;
- `execution_steps`;
- `evidence_refs`;
- `validation_result`;
- `blockers`;
- `next_action`;
- `receipt_hash`.

## Fluxo

1. Receber prompt.
2. Mission Mode classifica trivial, task, mission ou obra.
3. Criar mission record e Definition of Done.
4. Identificar dominio principal e secundarios.
5. Criar source plan e tool plan.
6. Rodar safety/cost/auth/legal gate.
7. Recuperar contexto: web, repo, docs, banco, arquivos, memoria.
8. Decidir usar ferramenta pronta, clonar repo, API, browser ou criar ferramenta.
9. Executar ciclos com receipts.
10. Validar resultado com testes, fontes, score, screenshots, artifacts ou review.
11. Se falhar, replanejar, reparar ou declarar blocker.
12. Gerar evidence pack.
13. Atualizar memoria e tool evolution.
14. Certificar e responder.

## Tool Policy

Antes de usar ferramenta, o Atlas deve responder:

1. A ferramenta e necessaria?
2. Existe ferramenta confiavel pronta?
3. O custo/risco de clonar repo e aceitavel?
4. Licenca permite uso?
5. O repo tem atividade e reputacao suficientes?
6. Existe risco de supply chain?
7. Criar ferramenta propria e mais seguro/eficiente?
8. A ferramenta precisa persistir para reuso futuro?
9. Como validar que ela funcionou?
10. Como registrar aprendizado para evolucao?

## Regras Para IA

- Nao responder com achismo em missao de pesquisa.
- Nao usar fonte fraca quando fonte primaria e disponivel.
- Nao tratar Atlas AI como sistema de programacao apenas; programacao e um
  dominio dentro do OS.
- Nao criar dominio novo sem Domain Company Runtime contract.
- Nao automatizar site dificil sem avaliar API oficial, ToS, login e risco.
- Nao clonar repo sem avaliar licenca, atividade, seguranca e escopo.
- Nao criar ferramenta propria por ego; criar quando for melhor que usar pronta.
- Nao executar cyber ofensivo sem autorizacao, escopo, RoE e legal/privacy gate.
- Nao executar trade real sem mandato, risk limits, approval e broker policy.
- Nao gastar provider/API/custo externo sem permissao ou politica aplicavel.
- Nao declarar missao completa sem evidence pack.
- Nao esconder blocker; blocker real e resultado valido.
- Nao confundir capacidade com prova de superioridade.

## Escopo de Implementacao

Implementacao completa deve incluir:

- Mission classifier global.
- WorkOrder/Mission model universal.
- Domain Runtime Contract (design pack canonico em
  `atlas-domain-runtime-contract.md` / Meta 2).
- Domain router.
- Domain Registry e Capability Catalog.
- Deep Research Runtime.
- Automation Runtime.
- Tool Registry.
- Tool Builder Runtime.
- Tool Evolution Loop.
- Safety/Cost/Auth/Legal Gate.
- Evidence Pack Builder.
- Certification.
- Control Plane.
- Memory/Compounding integration.
- Commands/API para readiness, run, control-plane e certify.

## Dependencias

- Atlas Kernel Mission Foundation (Meta 1, base universal Mission/Objective/WorkOrder com lifecycle, evidence e certification). Ver `atlas-kernel-mission-foundation.md`.
- Atlas Mission Mode.
- Router/Hyperflow.
- Autonomous Software Company Runtime.
- Real Engineering Execution Kernel.
- Forge OS.
- Compounding Engineering Intelligence.
- Evidence Ledger.
- Browser, terminal, GitHub, API e file runtimes.
- Security policy para ferramentas externas.

## Evidencias

Evidence pack pode conter:

- links e fontes lidas;
- hashes de documentos;
- screenshots;
- comandos executados;
- logs;
- repositorios avaliados;
- criterios de selecao de ferramenta;
- diffs;
- testes;
- outputs de API;
- planilhas/datasets;
- receipts;
- blockers;
- certification hash.

## Riscos

O OS deve bloquear ou pedir confirmacao quando houver:

- custo externo;
- login ou credencial;
- dados pessoais;
- scraping sensivel;
- ToS incerto;
- automacao contra protecao anti-bot;
- execucao destrutiva;
- clone de repo suspeito;
- comando com risco de apagar, exfiltrar ou alterar dados.

## Debug

Quando uma missao falhar, investigar:

1. A missao foi classificada corretamente?
2. O dominio escolhido era correto?
3. O source plan cobriu fontes obrigatorias?
4. O tool plan considerou alternativas suficientes?
5. O safety gate bloqueou corretamente?
6. A ferramenta funcionou ou falhou?
7. O resultado foi validado?
8. A falha virou repair, replan ou blocker?
9. A memoria aprendeu algo util?
10. A resposta final deixa claro o estado real?

## Exemplos

- Pesquisa de mercado: source plan, fontes primarias, oportunidades, riscos e
  estrategia acionavel.
- Automacao dificil: esclarecer objetivo, avaliar API oficial, login, ToS,
  browser automation e alternativa segura.
- Ferramenta propria: avaliar libs/repos, testar parser, criar somente se
  necessario, validar com amostras e registrar workflow reutilizavel.

## Proximas Acoes

A ordem canonica completa de implementacao multi-dominio vive em
`atlas-ai-multi-domain-implementation-sequence.md`. Resumo curto:

1. Consolidar Kernel comum: WorkOrder, Domain Runtime Contract, Policy,
   Evidence, Tool Runtime, Control Plane e Certification.
2. Adaptar Software Company Runtime como primeiro dominio completo.
3. Implementar Research e Strategy como dominios que alimentam todos os outros.
4. Implementar Tool Economy/Automation para uso, criacao e evolucao de tools.
5. Evoluir Finance e Marketing com policies e artifacts institucionais.
6. Evoluir Cyber apos safety, permission e evidence estarem maduros.
7. Criar certification e dashboards globais por dominio.

Antes de planejar APs ou Obras multi-dominio, consultar a doc de sequencia
para validar dependencias, paralelismo e fronteiras de arquivos.

## Definition of Done

O Autonomous Intelligence OS esta pronto quando:

- metas complexas viram missions automaticamente;
- dominio correto e selecionado;
- cada dominio relevante opera por Domain Company Runtime e nao por prompt solto;
- pesquisa profunda usa fontes fortes e evidencia;
- automacao avalia safety e executa quando permitido;
- ferramentas sao escolhidas, clonadas, criadas ou evoluidas com criterio;
- software, cyber, finance, marketing, strategy, research e desenvolvimento
  pessoal seguem policies, gates, artefatos e evidencias proprias;
- resultados sao validados;
- evidence pack e certification sao obrigatorios;
- blockers sao explicitos;
- memoria aprende com outcomes;
- docs-health passa;
- testes cobrem research, automation, tool selection, tool creation, blocker,
  software delegation e certification.
