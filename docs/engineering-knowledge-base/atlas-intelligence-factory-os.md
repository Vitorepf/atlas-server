---
id: atlas-intelligence-factory-os
type: engineering_knowledge
title: Atlas Intelligence Factory OS
status: active
category: autonomous-intelligence
priority: 99
summary: Atlas Intelligence Factory OS e o sistema que transforma o Atlas em uma fabrica autonoma de capacidades, ferramentas, agentes, workflows, simulacoes, doutrinas e melhorias operacionais. O runtime tecnico interno e ASEIF, Atlas Self-Evolving Intelligence Factory Runtime.
implementation_state: local_runtime_active_with_sandbox_certification
blocker: nenhum para runtime local; execucao externa, auto-trust e benchmark continuam bloqueados por governance.
tags:
  - atlas-ai
  - intelligence-factory
  - aseif
  - capability-foundry
  - simulation-runtime
  - self-improvement
  - tool-synthesis
  - agent-foundry
  - workflow-foundry
capabilities:
  - capability_gap_detection
  - build_buy_borrow_decision
  - tool_synthesis_lab
  - simulation_swarm
  - factory_sandbox_certification
  - agent_foundry
  - workflow_foundry
  - experimentation_runtime
  - doctrine_engine
  - self_evolving_operation
  - capability_marketplace
  - forge_promotion_bridge
decisions:
  - O nome de produto/camada e Atlas Intelligence Factory OS.
  - ASEIF e o runtime interno que opera a autoevolucao da fabrica.
  - O objetivo nao e criar scripts soltos; e criar capacidades certificadas, versionadas, reutilizaveis e melhoradas por outcome.
  - Toda nova capacidade passa por simulacao, sandbox, testes, evidence, certification e AEMOR antes de virar default.
  - A fabrica pode criar ferramentas, agentes, workflows, playbooks e policies propostas, mas nao muda comportamento critico sem governance.
  - Atlas Intelligence Factory OS nao substitui APCR, AEMOR, ACIE, ACOL, Dev ou Forge; ele compoe essas camadas.
  - Atlas Intelligence Factory OS tambem nao substitui Atlas Agentic Engineering OS; quando a pergunta for area tech/engenharia de software, leia o Authority Map.
maintenance:
  - Atualizar antes de implementar Capability Foundry, Simulation Runtime, Agent Foundry ou Workflow Foundry.
  - Nao criar capability store paralelo sem ADR; preferir registrar no Capability Registry canonico.
  - Manter claim policy: sem benchmark externo, sem superioridade numerica e sem auto-policy mutation sem evidencia.
related_paths:
  - docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-documentation-inventory.md
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
  - docs/engineering-knowledge-base/atlas-execution-memory-outcome-runtime.md
  - docs/engineering-knowledge-base/atlas-persistent-context-runtime.md
  - docs/engineering-knowledge-base/atlas-context-intelligence-engine.md
  - docs/engineering-knowledge-base/atlas-conversation-operations-layer.md
  - docs/engineering-knowledge-base/atlas-compounding-engineering-intelligence.md
  - docs/engineering-knowledge-base/atlas-ai-scenario-simulation-harness.md
  - docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-hyperflow-operation.md
  - docs/engineering-knowledge-base/atlas-tool-economy.md
  - docs/engineering-knowledge-base/atlas-world-model.md
  - docs/engineering-knowledge-base/atlas-strategic-reality-engine.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-intelligence-factory-os
graph_title: Atlas Intelligence Factory OS
graph_world: atlas
graph_layer: system
graph_kind: system
graph_parent: atlas-autonomous-intelligence-operating-system
graph_status: active
graph_source: repo
human_name: Atlas Intelligence Factory OS
canonical_name: Atlas Intelligence Factory OS
technical_name: atlas-intelligence-factory-os
cartography_type: system
canonical_source: docs/engineering-knowledge-base/atlas-intelligence-factory-os.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-intelligence-factory-os.md
allowed_changes:
  - Implementar incrementos de Capability Foundry, Simulation Runtime, Agent Foundry e Workflow Foundry.
  - Adicionar contracts, migrations, commands, read models e tests quando cada componente sair do papel.
  - Refinar gates quando AEMOR gerar dados reais suficientes.
forbidden_changes:
  - Criar ferramentas que executam acao externa real sem policy, sandbox e evidence.
  - Auto-promover capability para default por ter funcionado uma vez.
  - Clonar repositorio, instalar pacote ou chamar API externa sem provenance, license/security review e budget.
  - Tratar agente, ferramenta, workflow e policy como a mesma entidade.
  - Declarar superioridade contra Claude Code, Codex ou outro rival sem benchmark autorizado.
  - Tratar Intelligence Factory como doc-mae de Agentic Software Engineering ou Programming Governance.
depends_on:
  - atlas-autonomous-intelligence-operating-system
  - atlas-execution-memory-outcome-runtime
  - atlas-persistent-context-runtime
  - atlas-context-intelligence-engine
  - atlas-conversation-operations-layer
  - atlas-compounding-engineering-intelligence
  - atlas-ai-scenario-simulation-harness
flows_to:
  - atlas_dev
  - atlas_forge
  - atlas_research
  - atlas_automation
  - atlas_marketing
  - atlas_finance
  - atlas_tool_builder
unlocks:
  - self_evolving_atlas
  - reusable_capability_compounding
  - autonomous_tool_creation
  - workflow_creation
  - agent_creation
  - simulation_before_execution
governs:
  - atlas.capability_creation
  - atlas.tool_synthesis
  - atlas.workflow_synthesis
  - atlas.agent_synthesis
  - atlas.simulation_policy
  - atlas.self_evolution_policy
evidence:
  - docs/engineering-knowledge-base/atlas-execution-memory-outcome-runtime.md
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-scenario-simulation-harness.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:intelligence-factory:readiness --json --strict"
  - "php artisan atlas:intelligence-factory:certify --json --strict"
requires_evidence: true
risk_level: critical
line_limit: 520
next_actions:
  - Endurecer adapters reais de Tool Synthesis apenas depois de policy/sandbox dedicado.
  - Promover capabilities certificadas para trusted somente com revisao humana e outcomes AEMOR repetidos.
  - Expandir marketplace para UI quando Control Plane confirmar uso real suficiente.
---
# Atlas Intelligence Factory OS

## Resumo

**Atlas Intelligence Factory OS** e o patamar em que o Atlas deixa de apenas executar tarefas e passa a construir sua propria capacidade operacional.

O runtime tecnico interno chama-se **ASEIF: Atlas Self-Evolving Intelligence Factory Runtime**.

A diferenca central:

```text
Claude/Codex executam tarefas em uma sessao.
Atlas Intelligence Factory OS cria capacidades permanentes, testa, certifica, aprende e melhora o sistema que executa tarefas.
```

Ele nao e uma ferramenta unica. E uma fabrica de:

- ferramentas;
- agentes;
- workflows;
- simulacoes;
- playbooks;
- policies propostas;
- capabilities reutilizaveis;
- doutrinas operacionais;
- experimentos e melhorias.

## Papel no Atlas

O papel do Atlas Intelligence Factory OS e transformar aprendizado e gaps em capacidade real.

Camadas anteriores respondem:

- **APCR**: qual contexto usar?
- **ACIE**: como selecionar, compactar e validar contexto?
- **ACOL**: como operar conversa, handoff e subagentes sem sujar contexto?
- **AEMOR**: o que aconteceu e o que aprendemos com o outcome?
- **ACFSR**: qual capability/tool criar, simular ou certificar?

O Intelligence Factory OS responde:

```text
Como o Atlas evolui sua propria operacao de forma governada, cumulativa e reutilizavel?
```

Ele e a fabrica acima das fabricas: usa AEMOR para saber o que melhorar, ACFSR para criar capabilities, Simulation Runtime para testar, Compounding para propor mudancas, Policy para governar e Forge para transformar capacidade grande em Obra.

## Onde Se Encaixa

Hierarquia recomendada:

```text
Atlas AI
  -> Mission Mode
  -> Hyperflow Router
  -> Domain/Flow Runtime
  -> APCR / ACIE / ACOL
  -> Atlas Dev / Forge / Research / Finance / Marketing
  -> AEMOR
  -> ACFSR
  -> Atlas Intelligence Factory OS / ASEIF
```

ASEIF nao fica no caminho critico de toda resposta simples. Ele entra quando existe:

- gap de capability;
- falha repetida;
- oportunidade de automacao reutilizavel;
- tarefa grande demais para improviso;
- necessidade de criar ferramenta propria;
- necessidade de criar agente/workflow especializado;
- necessidade de simular antes de agir;
- outcome AEMOR indicando melhoria estrutural.

## Contratos

Schemas canonicos planejados:

- `atlas.intelligence_factory.mission.v1`
- `atlas.intelligence_factory.capability_gap.v1`
- `atlas.intelligence_factory.build_buy_borrow_decision.v1`
- `atlas.intelligence_factory.capability_spec.v1`
- `atlas.intelligence_factory.tool_synthesis_plan.v1`
- `atlas.intelligence_factory.agent_spec.v1`
- `atlas.intelligence_factory.workflow_spec.v1`
- `atlas.intelligence_factory.simulation_plan.v1`
- `atlas.intelligence_factory.simulation_result.v1`
- `atlas.intelligence_factory.sandbox_certification.v1`
- `atlas.intelligence_factory.capability_release.v1`
- `atlas.intelligence_factory.capability_evolution.v1`
- `atlas.intelligence_factory.doctrine_proposal.v1`
- `atlas.intelligence_factory.certification.v1`

Entidades centrais:

- **Capability**: capacidade reutilizavel com contrato, exemplos, testes e status.
- **Tool**: executavel/script/worker/API adapter que realiza acao.
- **Agent**: papel especializado com objetivo, contexto minimo, inputs/outputs e limites.
- **Workflow**: orquestracao repetivel de steps, agents, tools, gates e evidence.
- **Simulation**: execucao hipotetica ou sandboxed antes de risco real.
- **Doctrine**: regra operacional proposta a partir de outcomes repetidos.

Status de capability:

```text
draft -> sandboxed -> certified -> trusted -> deprecated
                         -> blocked
```

Somente `certified` ou `trusted` pode entrar em execucao automatica. `draft` e `sandboxed` exigem supervisao ou dry-run.

## Fluxo

Fluxo principal:

```text
1. Mission recebe objetivo.
2. Router escolhe flow inicial.
3. APCR/ACIE montam contexto.
4. AEMOR consulta historico de outcomes.
5. Capability Gap Detector identifica capacidade ausente ou fraca.
6. Build/Buy/Borrow Engine escolhe usar, comprar, emprestar, clonar, criar ou promover.
7. Simulation Runtime testa caminhos antes de executar.
8. Tool/Agent/Workflow Foundry cria artefato quando necessario.
9. Sandbox Certification valida contrato, seguranca, custo, testes e evidence.
10. Capability Router usa capability certificada.
11. AEMOR registra outcome real.
12. Doctrine Engine propoe melhoria se o padrao se repetir.
```

Build/Buy/Borrow decisao:

- **Use**: capability interna ja existe e esta trusted.
- **Buy**: API externa e mais segura/barata que criar.
- **Borrow**: biblioteca ou repo externo resolve com baixo risco.
- **Build**: ferramenta propria e melhor para escopo, privacidade ou repeticao.
- **Promote**: trabalho virou sistema; enviar para Forge como Obra.
- **Block**: risco, legal, credencial, custo ou evidencia insuficiente.

## Regras para IA

Regras obrigatorias:

- Nao criar ferramenta nova antes de procurar capability existente.
- Nao usar repo externo sem license, provenance e security review proporcional.
- Nao executar acao externa real em draft capability.
- Nao transformar output de provider em capability trusted sem teste.
- Nao confundir simulation result com outcome real.
- Nao autoalterar policy, router ou provider topology.
- Nao promover capability para default sem AEMOR outcome positivo.
- Nao criar agente com contexto excessivo; agente especializado recebe contexto minimo e contrato claro.
- Nao criar workflow novo se um playbook existente resolve.
- Nao declarar vantagem externa sem benchmark autorizado.

Regra central:

```text
Toda capacidade nova precisa de spec, simulation, sandbox, tests, evidence, certification e AEMOR feedback.
```

## Escopo de Implementacao

Componentes do produto final:

1. **Capability Registry**  
   Catalogo persistente de capacidades: nome, versao, dominio, status, contratos, exemplos, testes, riscos, custos, owner, ultima certificacao e AEMOR stats.

2. **Capability Gap Detector**  
   Detecta lacuna entre objetivo e capacidades existentes. Classifica gap como parser, scraper, OCR, API adapter, code tool, workflow, agent, simulation, integration ou Forge Obra.

3. **Build/Buy/Borrow Decision Engine**  
   Decide se Atlas deve usar capability existente, biblioteca, API, repo, script proprio, worker robusto ou Forge.

4. **Tool Synthesis Lab**  
   Cria ferramentas pequenas com input/output contract, tests, smoke, security guard, cost guard e rollback.

5. **Agent Foundry**  
   Cria agentes especializados com papel, contexto minimo, DoD, output schema, handoff e stop conditions.

6. **Workflow Foundry**  
   Cria pipelines reutilizaveis com agents, tools, gates, receipts, evidence e replay.

7. **Simulation Swarm**  
   Simula alternativas: conservadora, agressiva, barata, rapida, enterprise e experimental. Um adjudicator escolhe caminho.

8. **Sandbox Executor**  
   Executa capability nova isolada, sem credenciais perigosas, sem acao externa irreversivel e com logs.

9. **Capability Certification**  
   Certifica contrato, testes, output, security, privacy, cost, evidence, replay e limitations.

10. **Capability Router**  
   Seleciona capability certa por flow, dominio, risco, contexto, provider, custo e status.

11. **Capability Marketplace**  
   Read model para humanos e IAs: o que existe, quando usar, quando nao usar, status, versao e evidencia.

12. **AEMOR Feedback Loop**  
   Mede sucesso, falha, retrabalho, custo, qualidade e confiabilidade por capability.

13. **Doctrine Engine**  
   Transforma padroes repetidos em proposals: checklist, policy, test gate, context rule ou workflow upgrade.

14. **Forge Promotion Bridge**  
   Quando capability vira produto/sistema, gera Obra Forge com SDD, milestones, work packets e governance.

Fora do escopo inicial:

- Benchmark externo.
- Execucao autonomica sem safety policy.
- UI pesada antes do backend.
- Auto-deploy de ferramentas sem review.
- Compra/acao financeira/conta externa sem permissao humana.

## Dependencias

Dependencias obrigatorias:

- **AEMOR** para outcome e aprendizado.
- **APCR** para contexto persistente.
- **ACIE** para retrieval, compaction e freshness.
- **ACOL** para handoffs, subagentes e conversa limpa.
- **Evidence Ledger** para receipts e replay.
- **Policy/Safety** para permissao, risco e autonomia.
- **Control Plane** para estado agregado.
- **Compounding** para proposals governadas.
- **Forge** para Obra longa.
- **World Model** para entender repo, dominios, ferramentas e riscos.

Sem AEMOR, a fabrica vira gerador de scripts.  
Sem Simulation, vira execucao arriscada.  
Sem Policy, vira autonomia perigosa.  
Sem Capability Registry, vira duplicacao e esquecimento.

## Evidencias

Evidencias exigidas para cada capability:

- capability spec;
- input/output schema;
- examples;
- safety policy;
- simulation result;
- sandbox run;
- tests run;
- evidence refs;
- certification hash;
- known limitations;
- AEMOR episode/outcome;
- promotion gate;
- deprecation/supersession policy.

Comandos futuros:

```bash
php artisan atlas:intelligence-factory gap --objective="..." --json
php artisan atlas:intelligence-factory decide --objective="..." --json
php artisan atlas:intelligence-factory simulate --objective="..." --json
php artisan atlas:intelligence-factory register-capability --capability-key=... --name=... --evidence=... --json
php artisan atlas:intelligence-factory certify-capability --capability-id=... --json
php artisan atlas:intelligence-factory marketplace --json
php artisan atlas:intelligence-factory:control-plane --json
php artisan atlas:intelligence-factory:readiness --json --strict
php artisan atlas:intelligence-factory:certify --json --strict
```

## Riscos

Riscos principais:

- Gerar ferramentas demais e criar bagunca operacional.
- Criar ferramenta perigosa para automacao externa.
- Confiar em repo/lib externa sem supply-chain review.
- Confundir simulacao com execucao real.
- Promover capability fraca para default.
- Criar agentes com contexto demais e perder vantagem de isolamento.
- Criar workflows paralelos aos existentes.
- Transformar self-improvement em mutacao automatica sem governance.
- Inflar o Atlas com abstração sem uso real.

Mitigacoes:

- Reuse-first.
- Capability Registry obrigatorio.
- Sandbox antes de execucao.
- Evidence e AEMOR para tudo.
- Human review para risco alto.
- Forge promotion para sistemas grandes.
- Deprecation e supersession.
- Budget governor para ferramentas e memoria.
- Certification antes de default.

## Exemplos

Exemplo 1: YouTube multilíngue.

```text
Pedido: analisar 80 videos em espanhol, ingles e japones.
Gap: ingestion/transcription/translation/batch evidence fracos.
Decisao: criar capability video_intelligence_batch.
Simulacao: 3 videos pequenos, sem acao externa perigosa.
Certificacao: transcript status, source refs, language, confidence e report.
AEMOR: mede falha por idioma e custo por minuto.
```

Exemplo 2: Instagram scraping.

```text
Pedido: extrair dados do Instagram.
Gap: risco legal/autenticacao/anti-bot.
Decisao: block ou approval-required; preferir API/export/manual workflow.
Simulation: dry-run sem credencial.
Policy: nenhuma acao em conta real sem permissao.
```

Exemplo 3: Debug recorrente.

```text
AEMOR detecta 4 falhas em migrations JSON SQLite.
Factory cria capability migration_sqlite_guard.
Tool roda diff scan + teste alvo.
Capability vira certified depois de outcomes positivos.
```

Exemplo 4: Obra Forge.

```text
Capability de analise de video cresce para plataforma interna.
Forge Promotion Bridge gera Obra com SDD, milestones, work packets e gates.
```

## Proximas Acoes

Implementacao recomendada:

1. **ASEIF-I1: Capability Registry + Gap Detector**  
   Persistencia, schemas, command read-only e testes.

2. **ASEIF-I2: Build/Buy/Borrow + Simulation Plan**  
   Decision engine, cost/risk/provenance e dry-run contracts.

3. **ASEIF-I3: Tool Synthesis Lab + Sandbox Certification**  
   Criacao de tools pequenas com testes e safety.

4. **ASEIF-I4: Agent/Workflow Foundry**  
   Subagentes especializados, workflows reutilizaveis e handoff receipts.

5. **ASEIF-I5: Marketplace + AEMOR Evolution Loop**  
   Read model, reliability, deprecation, supersession e doctrine proposals.

Definition of Done:

- Doc canônica passa docs-health.
- Capability Registry existe.
- Gap Detector identifica lacunas reais.
- Build/Buy/Borrow gera decisao auditavel.
- Simulation Runtime bloqueia execucao arriscada.
- Tool Synthesis cria capability pequena com teste.
- Certification impede default sem evidence.
- AEMOR registra outcome de capability.
- Control Plane mostra marketplace/status.
- Forge Promotion Bridge gera handoff quando crescer.
