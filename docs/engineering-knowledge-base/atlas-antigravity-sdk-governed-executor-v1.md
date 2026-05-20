---
id: atlas-antigravity-sdk-governed-executor-v1
type: engineering_knowledge
title: Atlas Antigravity SDK Governed Executor v1
status: active
category: programming-forge
priority: 98
implementation_state: experimental_driver_fail_closed
summary: Contrato canonico e implementacao experimental fail-closed do Antigravity SDK como executor agentic subordinado ao Atlas. Forge e o primeiro consumidor pratico, mas o provider/capability nasce transversal para Dev, Pesquisa e Dominios via Atlas Decide, Memory, SDD, Gates, Evidence e metricas minimas sem Rivals completo.
tags:
  - atlas
  - antigravity
  - sdk
  - forge
  - provider-harness
  - antifragile
  - governed-executor
capabilities:
  - antigravity_sdk_governed_executor
  - provider_harness_absorption
  - global_executor_candidate
  - minimal_provider_performance_signal
  - atlas_sovereign_provider_antifragility
decisions:
  - Antigravity SDK deve ser tratado como provider-harness multi-modelo candidato, nao como modelo bruto, dominio, surface, fonte de verdade ou substituto do Atlas.
  - A estrategia canonica e SDK-first: nao implementar Antigravity CLI como wrapper/driver se o SDK puder cumprir o papel programavel.
  - Atlas nunca deve depender de Antigravity, Google, Claude, Codex, Gemini ou qualquer provider para preservar memoria, SDD, Decide, gates, evidence, review ou continuidade.
  - O unico desenho permitido e Atlas Context/Memory/SDD/Gates multiplicando Antigravity Harness Execution como executor subordinado.
  - A implementacao experimental e fail-closed: driver governado, runtime Python dedicado, adapter Atlas-owned, receipts, tests e metricas minimas antes de qualquer promocao.
  - Rivals completo fica fora desta etapa; performance entra como sinal minimo local e advisory-only para Provider Performance Projection / Dynamic Compute Market.
  - Atlas Decide deve aprender performance do SDK por evidence acumulada, nao por preferencia estatica.
  - Antigravity SDK nao pode escolher provider/model, escopo, arquivos, sucesso, maturidade, completion claim ou policy critica sem Atlas Decide e review humano.
maintenance:
  - Atualizar quando docs oficiais do Antigravity SDK, modelos, MCP, permissions ou CLI mudarem.
  - Atualizar antes de alterar driver, adapter, config, schemas, cockpit UI, Provider Performance ou qualquer runtime Antigravity no Atlas.
  - Manter abaixo de 520 linhas; mover runbooks e codigo exemplo longo para docs filhos quando implementacao iniciar.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-ai-thesis-multiplier-channel.md
  - docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md
  - docs/engineering-knowledge-base/atlas-antigravity-cli-governed-terminal-executor-v1.md
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/atlas-programming-self-construction-forge-map-v1.md
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - docs/engineering-knowledge-base/atlas-forge-continuum-os.md
  - docs/engineering-knowledge-base/atlas-forge-governed-provider-invocation-v1.md
  - docs/engineering-knowledge-base/atlas-forge-real-provider-drivers-v1.md
  - docs/engineering-knowledge-base/atlas-forge-provider-capacity-continuity-v1.md
  - docs/engineering-knowledge-base/self-construction/multi-provider-agent-orchestration-contract.md
  - docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md
  - docs/engineering-knowledge-base/atlas-ai-operator-review-approval-gates.md
  - docs/engineering-knowledge-base/atlas-rivals-evidence-pack-replay-manifest-v1.md
  - app/Services/Ai/Programming/AtlasForgeAntigravitySdkInvocationDriver.php
  - app/Services/Ai/Programming/AtlasAntigravitySdkRuntimeExecutor.php
  - runtimes/python/antigravity_sdk/adapter.py
  - tests/Feature/Ai/Programming/AtlasForgeAntigravitySdkDriverTest.php
external_references:
  - https://antigravity.google/product/antigravity-sdk
  - https://antigravity.google/docs/sdk-overview
  - https://antigravity.google/docs/models
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-antigravity-sdk-governed-executor-v1
graph_title: Atlas Antigravity SDK Governed Executor v1
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-forge-governed-provider-invocation-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-antigravity-sdk-governed-executor-v1.md
  - app/Services/Ai/Programming/AtlasForgeAntigravitySdkInvocationDriver.php
  - app/Services/Ai/Programming/AtlasAntigravitySdkRuntimeExecutor.php
  - runtimes/python/antigravity_sdk/adapter.py
  - tests/Feature/Ai/Programming/AtlasForgeAntigravitySdkDriverTest.php
allowed_changes:
  - Refinar contrato, fases, gates, schemas e criterios de promocao para Antigravity SDK.
  - Evoluir o driver experimental somente mantendo fail-closed, receipt, scope, evidence e tests.
  - Atualizar referencias oficiais e conclusoes de Rivals.
forbidden_changes:
  - Declarar o driver promovido/auto-best sem evidencia, policy patch, review humano e Decision Receipt novo.
  - Permitir que Antigravity decida contexto canonico, provider/model, escopo, success, policy, memory write ou completion claim.
  - Fazer Atlas depender de Antigravity para operar Dev, Forge, Memory, SDD, Decide ou Evidence.
  - Criar fluxo paralelo a Atlas Forge, Atlas Dev, Agent Control Plane ou Provider Evolution.
  - Usar SDK para bypass de allowlist, sandbox, Decision Receipt, operator approval, budget approval ou review humano.
depends_on:
  - atlas-ai-thesis-multiplier-channel
  - atlas-ai-provider-evolution-intelligence
  - atlas-forge-governed-provider-invocation-v1
  - atlas-forge-real-provider-drivers-v1
  - atlas-programming-forge-flow
flows_to:
  - atlas-dev-policy
  - atlas-ai-research-self-improvement-runtime
  - atlas-forge-rivals-provider-arena-corpus-v1
  - atlas-code-forge-operator-cockpit-v1
  - programming-professional-completion-audit
unlocks:
  - antigravity-sdk-experimental-driver
  - antigravity-sdk-rivals-arm
  - provider-harness-antifragility
governs:
  - antigravity-sdk-absorption
  - forge-provider-harness-candidates
  - provider-evolution-antifragility
evidence:
  - docs/engineering-knowledge-base/atlas-antigravity-sdk-governed-executor-v1.md
  - app/Services/Ai/Programming/AtlasForgeAntigravitySdkInvocationDriver.php
  - app/Services/Ai/Programming/AtlasAntigravitySdkRuntimeExecutor.php
  - runtimes/python/antigravity_sdk/adapter.py
  - tests/Feature/Ai/Programming/AtlasForgeAntigravitySdkDriverTest.php
  - https://antigravity.google/product/antigravity-sdk
  - https://antigravity.google/docs/models
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:architecture-validate --json"
  - "php artisan test --filter=AtlasForgeAntigravitySdkDriverTest"
  - "future: php artisan atlas:forge:rivals run-arena --arm-a=atlas_forge --arm-b=antigravity_sdk --json --strict"
requires_evidence: true
risk_level: critical
visual_tags:
  - forge
  - antigravity
  - governed-executor
  - antifragile
ai_entrypoints:
  - Leia este doc antes de propor Antigravity SDK no Atlas.
  - Se a tarefa pedir implementacao, primeiro confirme Provider Release Envelope, AP/patch plan, Rivals design e runtime boundary.
  - Trate Antigravity SDK como candidato a executor subordinado; nunca como autoridade.
ai_usage_notes:
  - A resposta correta para "usar Antigravity SDK" nao e "trocar provider"; e "avaliar se este provider-harness multiplica um Work Packet Atlas sob gates".
  - A rota correta e implementar SDK governado e deixar Atlas Decide aprender onde ele vence, empata ou perde.
  - Qualquer IA deve manter Atlas soberano e antifragil: se Antigravity sumir, falhar, mudar licenca, trocar modelo ou degradar, Atlas deve continuar operando e aprender com o evento.
quality_gates:
  - provider-release-envelope-created
  - runtime-driver-fail-closed
  - atlas-decide-authority-preserved
  - atlas-memory-authority-preserved
  - atlas-sdd-authority-preserved
  - work-packet-scope-locked
  - sandbox-and-allowlist-defined
  - operator-and-budget-approval-required
  - output-contract-hashed
  - evidence-ledger-complete
  - minimal-performance-signal-advisory-only
  - fallback-provider-path-green
failure_modes:
  - Antigravity vira mini-Atlas e duplica SDD, memory, Decide ou gates.
  - Atlas passa a depender de Antigravity para executar Forge.
  - SDK escolhe modelo/provider por fora de Atlas Decide.
  - Prompt/contexto cru vaza dados privados ou provider-unsafe.
  - Output do SDK vira completion claim sem review/gates.
  - Mudanca de quota/licenca/modelo quebra fluxo sem fallback.
observability_signals:
  - antigravity_sdk_driver_status
  - antigravity_sdk_model_selected_by_atlas_decide
  - work_packet_id
  - context_pack_hash
  - sdd_hash
  - allowed_files_hash
  - forbidden_files_hash
  - decision_receipt_id
  - invocation_receipt_hash
  - stdout_hash
  - artifact_hash
  - antigravity_sdk_minimal_performance_signal
  - atlas_decide_provider_score_delta
  - atlas_decide_use_case_fit
  - fallback_used
next_actions:
  - Criar Provider Release Envelope para mudancas futuras de API/modelos do Antigravity SDK antes de promover comportamento.
  - Criar AP/Rivals arm para comparar Atlas+Antigravity SDK contra Atlas+Claude CLI, Atlas+Codex CLI e Atlas+Gemini CLI.
  - Implementar SDK como rota principal; nao implementar Antigravity CLI wrapper/driver salvo decisao futura explicita.
---
# Atlas Antigravity SDK Governed Executor v1

## Resumo

Este documento define como o Atlas absorve o Antigravity SDK como executor
agentic subordinado. A implementacao inicial e experimental, fail-closed e sem
promocao automatica de roteamento.

A tese permitida e:

```text
Atlas Context / Memory / SDD / Gates
x
Antigravity Harness Execution
=
possivel multiplicador governado
```

A tese proibida e:

```text
Antigravity substitui Atlas Decide, Atlas Memory, SDD, Forge ou Evidence.
```

Antigravity SDK e um candidato a **provider-harness** transversal: uma camada
agentic programavel que pode operar arquivos, comandos, ferramentas, MCPs,
skills e modelos de raciocinio. Ele nao e fonte canonica, dominio Atlas,
surface Atlas, memoria soberana ou decisor de sucesso.

Forge e o primeiro consumidor real porque ja possui Governed Provider
Invocation. Atlas Dev, Pesquisa e Dominios so acessam esta capacidade pela
camada Atlas Decide/Provider, nunca por chamada direta ao SDK.

## Papel no Atlas

Atlas deve reinar como canal soberano. Providers, CLIs, SDKs, harnesses e labs
sao motores ou executores absorviveis. Quando um lancamento externo melhora, o
Atlas deve ficar mais forte porque transforma esse lancamento em driver,
benchmark, skill, connector, AP, recipe ou rejeicao documentada.

Antigravity SDK so faz sentido se aumentar a eficiencia de um bloco Atlas ja
governado:

```text
Obra / Work Packet / Domain Task
-> Atlas Memory + Context Pack + Code Intelligence
-> SDD / Task Contract
-> Atlas Decide / Decision Receipt
-> Antigravity SDK executor candidate
-> diff/artifacts/logs
-> Atlas gates/tests/review
-> Evidence Ledger / Rivals / Learning
```

O executor pode ser poderoso. A autoridade continua no Atlas.

Este e o caminho preferido sobre o CLI: o SDK e programavel, testavel,
observavel e encaixa no ciclo autonomo governado do Atlas. O CLI permanece
referencia operacional, nao driver.

## Onde Se Encaixa

```text
Layer -1 Thesis / Multiplicador / Canal Soberano
-> Provider Evolution Intelligence
-> Programming Forge Flow
-> Atlas Forge Governed Provider Invocation
-> Real Provider Drivers
-> Antigravity SDK Governed Executor (este doc)
-> Provider Performance / Dynamic Compute advisory
-> Rivals futuro / Evidence / Promotion Review
```

Antigravity SDK fica abaixo de:

- Atlas Decide;
- Atlas Memory / Context Pack;
- SDD / Task Contract;
- Work Packet / allowed files / forbidden files;
- Evidence Ledger;
- Review / Completion Gate;
- Provider Evolution / Rivals.

## Implementacao Experimental

O codigo atual registra `antigravity_sdk` como driver canonico do Forge e cria
um runtime Python dedicado:

- `AtlasForgeAntigravitySdkInvocationDriver`;
- `AtlasAntigravitySdkRuntimeExecutor`;
- `runtimes/python/antigravity_sdk/adapter.py`.

Configuracao default:

```text
ATLAS_ANTIGRAVITY_SDK_ENABLED=false
ATLAS_ANTIGRAVITY_SDK_PYTHON=python3
ATLAS_ANTIGRAVITY_SDK_MODULE=google.antigravity
ATLAS_ANTIGRAVITY_SDK_TIMEOUT=120
ATLAS_ANTIGRAVITY_SDK_MAX_OUTPUT_CHARS=12000
```

Sem enable, modulo, auth, workspace, Decision Receipt e `allowed_files`, o
driver bloqueia. `plan()` nunca chama provider; somente `invoke()` pode spawnar
o adapter depois dos gates upstream.

O adapter tenta usar a API Python oficial `Agent` + `LocalAgentConfig` quando
presente. Se o SDK local divergir desse contrato, falha fechado com
`antigravity_sdk_entrypoint_missing`, preservando Atlas como autoridade.

## Fluxos Transversais

Forge e o primeiro consumidor real, registra Evidence Ledger, emite
`ProviderReturned`, produz `atlas.provider.antigravity_sdk.performance_signal.v1`
e nunca promove completion claim.

Atlas Dev nao chama SDK diretamente. Preserva plan-first, provider lock fixo e
`fallback_allowed=false`; so pode usar `antigravity_sdk` quando Atlas
Decide/policy escolher esse lock para um run futuro.

Pesquisa pode usar o SDK como executor auxiliar de sintese/patch, mas nunca
como fonte de verdade, crawler, memoria, policy writer ou adjudicador.

Dominios nao chamam o SDK diretamente. Eles pedem capability ao
Kernel/Decide/provider layer.

Metrica minima registra provider, model_observed, status, duracao,
changed files, blockers e `routing_effect=none`. O sinal e consumivel por
Provider Performance Projection / Dynamic Compute Market como advisory-only e
nao muda roteamento automaticamente.

## Contratos

| Contrato | Regra |
|---|---|
| Soberania | Atlas decide objetivo, escopo, modelo permitido, gates e sucesso |
| Subordinacao | Antigravity executa Work Packet, nao cria fluxo paralelo |
| Reversibilidade | Se Antigravity falhar ou sumir, Atlas usa outro driver |
| Evidence | Toda invocacao gera receipt, hashes, logs redigidos e ledger |
| Medicao | Sinal minimo local e advisory-only no v1 |
| Privacy | Contexto enviado precisa ser provider-safe e scoped |
| Budget | Execute real exige aprovacao explicita de custo quando externo |
| Review | Output do SDK nunca promove completion claim sozinho |

## Fluxo

Atlas cria Work Packet -> Atlas Decide seleciona `antigravity_sdk` -> driver
monta manifest scoped -> adapter SDK executa -> Atlas valida diff, tests, gates,
Evidence e metricas. Chamada direta ao SDK fora desse fluxo e proibida.

## Exemplos

Permitido: Forge usa `antigravity_sdk` com receipt, allowed files e budget.
Proibido: dominio ou surface chama SDK direto e aceita output como pronto.

## Regras Para IA

1. Nunca proponha Antigravity como substituto do Atlas.
2. Nunca proponha dependencia central de Antigravity.
3. Nunca promova SDK antes de Provider Release Envelope + AP/experimento.
4. Nunca permita contexto bruto sem provider-safe filter.
5. Nunca permita write sem Work Packet, allowed files e forbidden files.
6. Nunca permita execute sem Decision Receipt.
7. Nunca permita tool/terminal sem sandbox e allowlist.
8. Nunca permita completion claim sem Atlas review.
9. Nunca confunda modelo escolhido pelo Antigravity com autoridade de roteamento.
10. Nunca chame sucesso de "multiplicador" sem evidence.

## Escopo De Implementacao

Fase 0 - Driver experimental fail-closed:

- Registrar `antigravity_sdk` no router de providers.
- Criar adapter Python isolado.
- Bloquear sem enable, modulo, auth, workspace, receipt e allowed files.

Fase 1 - Medicao minima:

- Persistir `atlas.provider.antigravity_sdk.performance_signal.v1`.
- Alimentar Provider Performance / Dynamic Compute como advisory-only.
- Nao mudar roteamento automaticamente.

Fase 2 - Promotion review futura:

- Criar Provider Release Envelope para mudancas relevantes.
- Criar Rivals/arena fora deste modulo quando a equipe de medicao assumir.
- Promover somente com evidence, policy patch, review humano e novo receipt.
- Registrar fallback e rollback.

## Modelo De Dados Minimo

| Schema | Campos obrigatorios |
|---|---|
| `atlas.forge.antigravity_sdk_driver_status.v1` | `provider_harness`, `configured`, `sdk_present`, `auth_state`, `models_visible_to_atlas`, `external_provider_call_possible`, `blockers` |
| `atlas.forge.antigravity_sdk_invocation_request.v1` | `work_packet_id`, `decision_receipt_id`, `context_pack_hash`, `sdd_hash`, `allowed_files`, `forbidden_files`, `model_policy.selected_by=atlas_decide`, `model_policy.allowed_models`, `model_policy.fallback_allowed` |
| `atlas.forge.antigravity_sdk_invocation_result.v1` | `provider_harness`, `provider_called`, `model_reported`, `diff_hash`, `artifact_hashes`, `stdout_hash`, `scope_guard_status=pending_atlas_gate`, `completion_claim_allowed=false` |

Esses schemas devem nascer pequenos e fail-closed. Campos novos podem ser
adicionados por AP, mas nenhum campo pode transferir autoridade de Atlas
Decide, Memory, SDD, Gates ou Evidence para o SDK.

## Sandbox E Permissoes

O driver experimental deve comecar com permissao minima:

- ler apenas arquivos do Work Packet;
- escrever apenas `allowed_files`;
- rodar apenas comandos allowlisted;
- sem rede livre;
- sem secrets no prompt;
- sem raw provider tokens no output;
- sem MCP externo ate review explicito;
- timeout e output cap obrigatorios;
- logs redigidos antes de UI ou ledger.

Qualquer ampliacao exige AP/review.

## Criterios De Multiplicador

Antigravity SDK so multiplica se melhorar pelo menos um eixo sem degradar gates
criticos:

| Eixo | Medida |
|---|---|
| Qualidade | tests pass, review score, fewer defects |
| Velocidade | menor wall-clock por Work Packet |
| Custo | menor custo total ou menos repair loops |
| Robustez | menos scope violations, menos stalls, melhor failure classification |
| Cobertura | resolve tarefa que outros drivers falham |
| Antifragilidade | fallback limpo e aprendizado registrado |
| Decisao | melhora score do Atlas Decide por classe de tarefa |

Se Atlas+Antigravity <= Atlas+provider direto, manter em hold. Se for pior e
degradar gates, reject ou demote.

## Proibicoes Criticas

- Proibido hardcodar "Antigravity e melhor".
- Proibido usar Antigravity como canal unico.
- Proibido remover drivers Claude/Codex/Gemini por causa do SDK.
- Proibido dar ao SDK memoria canonica do Atlas.
- Proibido permitir que o SDK salve ou reescreva AGENTS/CLAUDE/projections.
- Proibido enviar Evidence Ledger completo para provider.
- Proibido permitir prompt injection de repo/doc controlar policy.
- Proibido transformar quota, plano Google ou modelo disponivel em policy Atlas.
- Proibido declarar "AGI", "superioridade" ou "pronto para producao" sem evidence.

## Como Qualquer IA Deve Implementar

Antes de codigo:

```text
1. Rodar session-bootstrap e place-feature.
2. Ler este doc, Provider Evolution, Forge Invocation e Real Provider Drivers.
3. Criar ou localizar Provider Release Envelope.
4. Confirmar se ha AP/experimento aprovado para SDK.
5. Definir schemas, tests, Rivals e sinais para Atlas Decide antes de driver.
```

Ao codar:

```text
1. Adicionar driver como experimental e fail-closed.
2. `configured()` so inspeciona ambiente local.
3. `plan()` nunca chama SDK.
4. `execute()` exige gates ja existentes.
5. Todo output e hash/redacted.
6. Scope Guard roda depois e pode bloquear.
7. Completion claim permanece false.
```

Depois:

```text
1. Rodar tests focados.
2. Rodar architecture-validate e runtime-boundary.
3. Rodar Rivals arm.
4. Registrar evidence.
5. Atualizar aprendizado de Atlas Decide.
6. Curator propoe promocao ou rejeicao da capacidade, nao do provider como verdade absoluta.
```

## Riscos

O maior risco nao e Antigravity falhar. O maior risco e uma IA esquecer que
Atlas e o canal soberano e deixar um harness externo assumir autoridade.

Mitigacao: todo design precisa preservar:

```text
Atlas decides.
Atlas scopes.
Atlas verifies.
Atlas records.
Atlas learns.
External harness executes.
```

## Dependencias

- `atlas-canonical-glossary-and-naming.md` para nomes canonicos e evitar ambiguidade.
- `atlas-ai-thesis-multiplier-channel.md` para a tese do multiplicador.
- `atlas-ai-provider-evolution-intelligence.md` para absorcao antifragil de lancamentos.
- `atlas-forge-governed-provider-invocation-v1.md` para o contrato de provider externo.
- `atlas-forge-real-provider-drivers-v1.md` para padroes de drivers reais.
- `atlas-programming-forge-flow.md` para Obra, Work Packet, SDD e completion.

## Evidencias

Evidencia atual:

- Este doc e apenas contrato de absorcao.
- Docs oficiais indicam que Antigravity SDK oferece agent loop e ferramentas
  programaveis em Python.
- Docs oficiais indicam model selector com modelos Gemini, Claude e GPT-OSS.
- O Atlas ja possui Forge Governed Provider Invocation e Real Provider Drivers
  para padrao de integracao governada.

Evidencia ainda ausente:

- Driver Antigravity SDK no codigo.
- Teste focado.
- Rivals arm.
- Evidence pack real.
- Sinais persistidos para Atlas Decide aprender quando usar e quando nao usar.

Enquanto isso faltar, Antigravity SDK permanece `proposal_only_no_runtime_driver`.

## Proximas Acoes

1. Rodar `php artisan atlas:ai:provider-release-review --json` com release
   Antigravity SDK quando houver input formal.
2. Criar AP/Rivals arm `antigravity_sdk`.
3. Implementar driver SDK experimental fail-closed.
4. Comparar multiplicador em Work Packets pequenos.
5. Fazer Atlas Decide aprender performance por classe de tarefa.
6. Promover somente se Atlas+Antigravity provar ganho sem perder soberania.
