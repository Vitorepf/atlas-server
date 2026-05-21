---
id: atlas-dev-efficient-programming-flow-v1-part-01
type: engineering_knowledge
title: Atlas Dev Efficient Programming Flow v1 · Parte 1
status: active
category: programming
priority: 105
summary: Recorte focado de Atlas Dev Efficient Programming Flow v1: 1. Papel No Atlas ate 8. Pipeline Completo.
tags:
  - atlas-dev
  - efficient-programming-flow
  - split-doc
  - cartography-readable
capabilities:
  - atlas_dev_efficient_programming_flow
  - atlas_documentation_split
decisions:
  - Este recorte preserva uma parte normativa do Atlas Dev Efficient Programming Flow sem ampliar responsabilidade do índice canônico.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar junto com docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md quando o contrato alto-nivel mudar.
related_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-contracts-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-runbook-v1.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dev-efficient-programming-flow-v1-part-01
graph_title: Atlas Dev Efficient Programming Flow v1 Parte 1
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-dev-efficient-programming-flow-v1
graph_status: active
graph_source: repo
human_name: Atlas Dev Efficient Programming Flow v1 Parte 1
canonical_name: Atlas Dev Efficient Programming Flow v1 Parte 1
technical_name: atlas-dev-efficient-programming-flow-v1-part-01
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-part-01.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-part-01.md
allowed_changes:
  - Atualizar somente a parte descrita neste recorte.
forbidden_changes:
  - Misturar este recorte com contracts detalhados, runbook executável, benchmark, Rivals ou Forge production.
depends_on:
  - atlas-dev-efficient-programming-flow-v1
flows_to:
  - atlas-dev-efficient-programming-flow-v1
unlocks:
  - atlas_cartography_readable_documentation
governs:
  - atlas_dev.efficient_programming_flow
evidence:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Manter este recorte alinhado ao índice canônico e ao contrato de documentação.
---
# Atlas Dev Efficient Programming Flow v1 · Parte 1

## Resumo

Este recorte preserva uma parte focada de Atlas Dev Efficient Programming Flow v1: 1. Papel No Atlas ate 8. Pipeline Completo.

## Papel no Atlas

Mantém uma fatia normativa do fluxo de programação diária do Atlas Dev fora do índice principal para que a cartografia continue legível.

## Onde Se Encaixa

É filho canônico de `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md` e deve ser lido quando a pessoa precisar do detalhe desta parte do fluxo.

## Contratos

Segue o documento dono, o glossário canônico, o contracts doc e o modelo obrigatório de documentação do Atlas.

## Fluxo

Índice canônico → recorte focado → contrato, decisão ou regra operacional correspondente.

## Regras para IA

Não transformar este recorte em benchmark, Rivals, Forge production ou implementação sem evidence. Não misturar patamar, versão, fonte, risco, regra ou prova.

## Escopo de Implementacao

Este arquivo só guarda o detalhe extraído do documento maior.

## Dependencias

Depende do índice `atlas-dev-efficient-programming-flow-v1`, do contracts doc e do runbook.

## Evidencias

A evidência de origem é o documento principal e o `docs-health` verde depois da divisão.

## Riscos

Risco principal: alguém confundir contrato alto-nível com schema detalhado, runbook executável ou benchmark competitivo.

## Exemplos

Os exemplos abaixo são o conteúdo extraído, preservado sem perda semântica.

## Proximas Acoes

Atualizar este recorte quando a parte correspondente mudar e rodar docs-health.

## Conteudo Extraido
## 1. Papel No Atlas

Atlas Dev e o **fluxo especializado de desenvolvimento em workspace dentro do Atlas AI**. Ele cobre a fatia de programacao governada: patch, repair, refactor leve, code generation em workspace, review de diff/codigo, frontend pontual, multi-file edit dentro de scope contract e perguntas workspace-bound como "onde esta X no repo?".

O produto que substitui Claude Code/Cursor/Codex para o operador e **Atlas AI**, nao Atlas Dev isoladamente. Atlas AI e a interface unica; Atlas AI Router escolhe o fluxo especializado. Atlas Dev recebe pedidos ja roteados para desenvolvimento em workspace e aplica por cima contexto real, spec compacta, contrato de tarefa, prompt projection deterministico, scope guard, verification, repair, receipts, memoria e continuidade.

Quando um engine melhora, Atlas AI herda essa melhora automaticamente. Atlas Dev herda por consequencia quando o Router/Atlas Decide escolhe esse engine para tarefas de desenvolvimento. O multiplicador proprio vem da governanca e da composicao operacional, nao de tentar congelar um modelo especifico.

Este modulo define seu **contrato canonico de alto nivel**.

Documentos irmaos:

- **Contracts**: `atlas-dev-efficient-programming-flow-contracts-v1.md` — schemas YAML detalhados, invariants, exemplos validos e invalidos, signatures PHP DTO, regras de hash e versionamento.
- **Runbook**: `atlas-dev-efficient-programming-flow-runbook-v1.md` — sequencia executavel de fatias (0 a 5), paths absolutos, signatures de service, fixtures e DoD operacional por PR.

Atlas Dev fica **dentro** do Atlas AI, ao lado de outros fluxos como Research, Explain, Debug, Review e Conversation. Forge entra quando o trabalho vira **Obra**: entrega longa, multiagente, persistente, auditavel, com necessidade de maximo poder de fogo.

### 1.1 Posicionamento No Atlas Kernel Pipeline

Atlas Dev nao e pipeline paralelo. Ele e **uma instancia governada do Atlas Kernel Pipeline canonico** para o dominio Programming, com fluxo Atlas Dev (workspace-bound). Mapeamento estagio a estagio:

| Estagio do Kernel | Componente Atlas Dev |
| ---: | --- |
| 1. Surface Plane | Atlas AI Desktop Mac (primeira surface); CLI/App/API entram como paridade |
| 2. Surface Adapter | `AtlasDesktopAiAdapter` (e demais 3) sob `AtlasDev/Surface/` |
| 3. Atlas Input | texto + screenshot/clipboard via `OperationEnvelope.attachments` |
| 4. Operation Envelope | `OperationEnvelope` (schema canonico Atlas Dev, nome identico ao Kernel) |
| 5. Intent / Routing | `IntakeNormalizer` + `TaskClassifier` + `RiskLevelScorer` |
| 6. Business Context | `business_context` em `OperationEnvelope` (organization, project, environment, customer) |
| 7. Domain / Profile / Flow | Domain=Programming, Profile=atlas_dev_fast_path, Flow=programming.dev_efficient |
| 8. Context Builder | `DocContextTierSelector` + `CodeDiscoveryEngine` + `OpenBrainProjectionAdapter` |
| 9. Policy / Profile | `LightTaskContract` com `policy_profile` (autonomy_level, privacy_class, cost_budget_usd, sandbox_required) |
| 10. Atlas Decide | Atlas Dev opera em `decision_mode=manual_override` (provider_lock) ate Atlas Decide ativar Programming; migra para `auto_best_allowed` quando ativar |
| 11. Decision Receipt v2 | `(envelope_hash, prompt_projection_hash, task_contract_hash)` co-validados antes do Run; nenhum runtime executa sem essa tripla |
| 12. Runtime / Executor | `SonnetClaudeCliAdapter` (provider driver Atlas Dev locked) + Programming Harness (capability) |
| 13. Quality Gates | `ScopeGuard` + `VerificationGate` + `CompletionStateGate` |
| 14. Repair / Escalation | `FailureCapsuleBuilder` + `RepairOrchestrator` + `EscalationDecisionEngine` |
| 15. Evidence Ledger | `ReceiptStorage` (FS local) com dual reference opcional para `atlas_engineering_evidence` (DB Governance) |
| 16. Learning / Proposals | `FastPathTelemetry` + `FastPathErrorLedgerEntry` alimentam Programming Curator via Proposal Inbox; nunca auto-aplicam |
| 17. Output Renderer | `SurfaceResponseFormatter` por surface; Desktop recebe `PatchResult`/`PlanOnlyResult` + `ui_hints` opcionais |

Os 14 principios canonicos do Kernel (Surface nao decide, Provider nao decide, Tool nao decide, Domain nao burla policy, Runtime nao executa sem Decision Receipt, Modelo manual e override auditado, Repair retorna via policy/receipt/Decide, Todos eventos relevantes viram Evidence, Learning nao altera comportamento critico sem proposal/review, AtlasVault e surface humana, etc.) sao **todos enforced** no Atlas Dev. Atlas Dev nao pode contradizer nenhum.

### 1.2 Posicionamento Dentro Do Atlas AI

```text
Atlas AI (produto / superficie unica do programador)
  -> Atlas AI Router
      -> Atlas Dev          (desenvolvimento em workspace)
      -> Atlas Research     (pesquisa conceitual / aprendizado)
      -> Atlas Explain      (explicacao de codigo/arquitetura sem patch)
      -> Atlas Debug        (logs/traces/erros sem patch obrigatorio)
      -> Atlas Review       (revisao profunda de diff/PR)
      -> Atlas Conversation (chat exploratorio)
      -> Atlas Forge        (Obra-driven, multiagente, semanas/mes)
      -> futuros fluxos     (QA, Security, DB, Design, ...)
```

Atlas Dev nao compete com esses fluxos e nao tenta virar "tudo que toca codigo". Se o pedido cair fora de desenvolvimento em workspace, Atlas Dev deve retornar `routing_decision=delegate_to_other_flow` com o fluxo sugerido, ou deixar o Atlas AI Router resolver antes de criar `OperationEnvelope`.

### 1.3 Escopo Canonico Do Fluxo

Tabela operacional que define o que Atlas Dev aceita executar e o que ele recusa/delega. Esta tabela e fronteira normativa: qualquer pedido fora da coluna "Dentro" exige delegacao via `routing_decision=delegate_to_other_flow` ou roteamento upstream pelo Atlas AI Router.

| Dentro do Atlas Dev (executa) | Fora do Atlas Dev (delega) | Fluxo destino |
| --- | --- | --- |
| patch em workspace (1-6 arquivos, scope contract) | pesquisa conceitual sem workspace | Atlas Research |
| repair de teste/gate falhando com workspace ativo | explicacao ampla sem patch alvo no repo | Atlas Explain |
| refactor leve com scope contract | debug standalone sem alvo de codigo concreto | Atlas Debug |
| code generation criando/alterando arquivos no workspace | chat exploratorio amplo / brainstorm | Atlas Conversation |
| review de diff/codigo existente ligado ao workspace | review profundo de PR/diff fora de workspace ativo | Atlas Review |
| frontend pontual com componente/arquivo identificavel | Obra longa, multiagente, semanas/mes | Atlas Forge |
| pergunta workspace-bound ("onde esta X no repo?") | redesenho arquitetural amplo sem patch imediato | Atlas Forge (preview) |
| multi-file edit dentro de scope contract (max 5-6 arquivos) | mudanca em auth/billing/migration/security/production | Atlas Forge (preview) |

Regras de fronteira:

- Atlas Dev executa apenas o que cabe na coluna "Dentro". Qualquer outra coisa vira `delegate_to_other_flow` ou `escalate_forge` (R4/R5).
- "Workspace-bound" significa que o pedido tem um workspace resolvido E uma intencao concreta sobre arquivos/simbolos/testes daquele workspace.
- Pergunta workspace-bound permanece no Atlas Dev mesmo sem patch porque depende de Code Discovery + repo real. Pergunta conceitual sem workspace vai para Atlas Research/Explain.
- Quando `flow_origin=atlas_ai_router` o pedido ja chegou pre-classificado; Atlas Dev confia no roteamento e valida apenas que o tipo cai em "Dentro".
- Quando `flow_origin=direct` (entrada legada CLI/API), Atlas Dev tambem precisa checar a tabela acima e delegar se nao for desenvolvimento em workspace.

## 2. Missao

```text
Construir Atlas Dev como fluxo de desenvolvimento em workspace
dentro do Atlas AI, usando o melhor engine escolhido pelo Router
e multiplicando sua entrega com contexto real, spec, contrato,
execucao, verificacao, repair, memoria, receipts e escalada para Obra.
```

Avaliacao competitiva, Rivals e Opus challenge **ficam fora desta fase**. Outro Codex/Claude pode montar essa avaliacao depois; este contrato governa apenas a **criacao** do Atlas Dev como fluxo operacional superior dentro do Atlas AI.

## 3. Tese

Dentro da fatia de desenvolvimento em workspace, Atlas Dev precisa entregar ao modelo e ao operador um sistema que o provider puro nao tem:

- contexto certo, nao dump grande;
- arquivos e simbolos provaveis antes da chamada;
- pesquisa workspace-bound ancorada no repo real;
- mini-spec antes de patch;
- contrato leve de tarefa;
- escopo permitido e proibido;
- testes focados;
- evidence e receipt;
- repair barato com erro real;
- memoria operacional;
- continuidade de sessao de desenvolvimento e aprendizado operacional;
- delegacao para outros fluxos quando nao for desenvolvimento em workspace;
- escalada para Forge quando o trabalho deixa de ser tarefa de desenvolvimento e vira Obra.

Formula:

```text
Atlas AI geral 25x =
  melhor_engine_disponivel
* router_e_fluxos_especializados

Atlas Dev workspace 10x =
  melhor_engine_disponivel
* (
  contexto_correto
+ escopo_correto
+ spec_leve
+ teste_focado
+ repair_barato
+ evidence_honesta
+ prompt_projetado_por_contrato
+ memoria_operacional
+ continuidade
+ delegate_to_other_flow
+ escalada_para_obra
)
```

## 4. Equipes

Este projeto tem duas equipes que **nao se contaminam**:

| Equipe | Escopo | Artefatos |
| --- | --- | --- |
| **Criacao** (dono deste contrato) | desenhar e construir o fluxo: pipeline, contratos, schemas, state machine, gates, scope guard, verification, repair, escalada, drivers, surfaces | este doc, contracts, runbook |
| **Medicao / Rivals** | desenhar e construir benchmark, oraculos, baterias, scoring, criterios de entrada no Rivals, validacao estatistica | docs proprios sob `atlas-forge-rivals-*` |

Decisoes sobre oraculos, difficulty, messy human prompts, scoring, cost-normalized score e regra de entrada no Rivals **nao pertencem a este doc**. Se aparecerem em PR ou edit, recusar.

## 5. Decisao De Foco Atual

Esta fase **nao cria**:

- Rivals arm;
- benchmark competitivo;
- Opus challenge;
- bateria de prompts baguncados;
- score custo-normalizado;
- oracle privado de avaliacao;
- claim de vitoria contra Sonnet/Opus.

Esta fase **cria**:

- runtime robusto;
- fluxo de desenvolvimento em workspace dentro do Atlas AI;
- modos workspace-bound: plano, patch, repair, code generation, review, frontend pontual, debug com alvo de codigo e continuidade;
- mini-spec e task contract reais;
- prompt/provider projection de alta qualidade;
- context tier selector;
- code discovery;
- scope guard;
- verification receipt;
- repair loop barato;
- telemetry operacional;
- error ledger;
- persistencia local de receipts;
- escalada honesta para Forge preview.

### 5.1 Surface Inicial Locked

A primeira implementacao completa de ponta a ponta sera a surface **Atlas AI Desktop Mac**, a aba `Atlas AI` do aplicativo desktop.

Identidade tecnica locked:

| Produto | Surface id no payload | Entrada desktop | Entrada backend |
| --- | --- | --- | --- |
| Atlas AI Desktop Mac | `atlas_desktop_ai` | `../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/AtlasAiSurface.tsx` + `contract.ts` | `app/Services/Ai/Surface/Adapters/AtlasDesktopAiSurfaceAdapter.php` + `AtlasDevRuntimeService` |

O CLI Dev continua sendo infraestrutura/facade reutilizada. A UX primaria para validar o fluxo de operador, plano, contexto, receipts e repair e o Atlas AI Desktop. CLI, App e API entram depois como surfaces de paridade, nao como produto inicial.

### 5.2 Principio Surface-Agnostic

Desktop-first e uma estrategia de entrega, nao acoplamento de arquitetura.

O core do Atlas Dev recebe apenas:

```text
OperationEnvelope
```

e retorna apenas:

```text
PlanOnlyResult | PatchResult
```

Regra dura: nenhum service em `app/Services/Ai/Programming/AtlasDev/{Schemas,Discovery,PromptProjection,Pipeline,Provider,Gate,Repair,Escalation,Persistence,Telemetry}/` pode conhecer Desktop, CLI, App ou API. Conhecimento de surface vive somente em `app/Services/Ai/Programming/AtlasDev/Surface/`.

Adapters de surface sao thin translators:

```text
payload nativo da surface -> OperationEnvelope
PlanOnlyResult|PatchResult -> resposta nativa da surface
```

`ui_hints` pode existir para facilitar render no Desktop, mas e sempre projecao derivada dos artefatos canonicos. Ele nunca decide rota, risco, provider, escopo, gate ou completion.

## 6. Nao Objetivos

- Nao recriar Forge no fast path.
- Nao usar council ou topology multi-provider por default.
- Nao criar Obra automaticamente.
- Nao carregar todos os docs enterprise em toda tarefa.
- Nao desenhar benchmark, Rivals, Opus challenge ou bateria de prompts nesta fase.
- Nao declarar sucesso sem receipt.

## 7. Arquitetura No Atlas AI

| Camada | Uso | Produto | Governanca |
| --- | --- | ---: | --- |
| Engines crus | Claude Code, Codex, Cursor, Sonnet, outros | motores internos | nenhuma ou propria do engine |
| Atlas AI Router | decide qual fluxo atende o pedido | orquestrador de produto | roteamento, composicao, memoria e policy |
| Atlas Dev | desenvolvimento em workspace: patch, repair, review, frontend pontual, code generation, perguntas repo-bound | fluxo especializado dentro do Atlas AI | compacta, adaptativa e verificavel |
| Outros fluxos Atlas AI | Research, Explain, Debug, Review, Conversation, QA, Security, DB, Design | fluxos especializados | propria por dominio |
| Atlas Forge | Obra definida: semanas/mes, multiagente, auditavel, persistente, maximo poder de fogo | Obra Production OS | forte, replay/evidence/topology/workspace persistente |

Forge **nao e Atlas Dev mais forte**. Forge e outro fluxo/produto operacional: Obra-driven, com evidence/replay/topology, workspace persistente, coordenacao multiagente e governanca pesada. Atlas Dev detecta e prepara a promocao quando o trabalho vira Obra; a criacao de Obra continua exigindo decisao humana.

### 7.1 Posicionamento De Produto

```text
Claude Code / Codex / Cursor / outros engines
  = motores internos que evoluem independentemente

Atlas AI
  = superficie unica do programador
  = Router + fluxos especializados + engines internos

Atlas Dev
  = fluxo de desenvolvimento em workspace
  = repo intelligence + spec + patch + repair + review + frontend pontual
    + verification + repair + receipts + memoria

Forge
  = sistema de producao de Obra
  = execucao longa, multiagente, governada, persistente, auditavel
```

O "10x" do Atlas Dev e tese estrutural para a fatia de desenvolvimento em workspace: cada run carrega contexto, escopo, spec, prompt projection, gates, evidence e memoria que engines crus nao possuem de forma integrada. O "25x" do Atlas AI vem da composicao de multiplos fluxos especializados por Router. O "20x" do Forge sobre o fluxo Dev vem quando existe Obra: decomposicao, paralelismo, workspace persistente, review gates, replay e execucao automatizada de longo prazo.

### 7.2 Principio Da Composicao Multiplicadora

Atlas AI nunca concorre contra a evolucao dos engines. Ele **embrulha e multiplica** o melhor engine disponivel por meio de fluxos especializados. Atlas Dev e o fluxo que aplica essa multiplicacao na fatia de desenvolvimento em workspace.

```text
resultado_atlas_ai =
  melhor_engine_atual
  x router_e_fluxos_especializados

resultado_atlas_dev =
  melhor_engine_atual
  x governance_atlas_dev_workspace
```

Implicacoes:

- se Codex, Claude, Cursor ou outro engine salta `N` vezes, Atlas AI herda esse salto automaticamente; Atlas Dev herda quando esse engine for escolhido para desenvolvimento;
- o multiplicador proprio do Atlas Dev vem de Code Intelligence, Open Brain, MiniSpec, TaskContract, ScopeGuard, Verification, Repair, Receipt, Telemetry e Plan-first na fatia workspace-bound;
- programador nao escolhe entre Atlas e engines: abre Atlas AI, e Atlas AI Router escolhe fluxo/engine por baixo;
- provider lock e fixo **por run** para impedir fallback escondido;
- Atlas Decide pode trocar o lock **entre runs** quando outro engine ficar melhor para uma categoria de tarefa;
- Forge aplica a mesma logica em escala Obra: `Forge = Atlas AI Router + Obra workspace + multiagente + replay/evidence pesado`.

## 8. Pipeline Completo

```text
Surface primaria: Atlas AI Desktop Mac (`surface_id=atlas_desktop_ai`)
Surfaces de paridade: CLI Dev | App | API
-> OperationEnvelope
-> Intake normalizado
-> Workspace + permission preflight
-> Classificacao de tarefa
-> Risk level R0-R5
-> Scope mode compact | structural
-> DocContextTierSelector
-> CodeDiscoveryManifest
-> OpenBrainProgrammingProjection
-> CompactSDD
-> MiniProgrammingSpec
-> LightTaskContract
-> ProviderPromptProjection
-> RoutingDecision
   -> read_only_answer
   -> atlas_dev_fast_path
   -> forge_promotion_preview
-> ProviderDecision (Sonnet locked, sem fallback)
-> ShortPlan
-> ScopedExecution (1 call principal)
-> PatchOrNoPatchReason
-> ScopeGuardReceipt
-> FocusedVerification
-> VerificationReceipt
-> CheapRepair (FailureCapsule -> mesma provider/model -> rerun do gate)
-> CompletionState
-> EscalationDecision (se aplicavel)
-> FastPathTelemetry
-> FastPathErrorLedgerEntry (se aplicavel)
-> Learning/Cartography proposal (se aplicavel)
```
