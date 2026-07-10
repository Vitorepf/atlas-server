# Atlas Quality Foundry — Plano Mestre para atingir 10× mundial

> **Execution contract:** executar por packets TDD, com review independente por packet. Nenhum estado temporal ou comparativo pode ser antecipado. O override do operador em 2026-07-09 autoriza trabalho na `main` local sem worktree; não autoriza push, deploy ou cutover.

## 1. Situação real e objetivo

O estado inicial é `implemented_not_cutover_ready`:

- Tasks 0–7 da Elite Engineering Factory v2 concluídas no stream anterior;
- P0-13 e P0-19 ainda parciais e sujeitos a revalidação no HEAD vivo;
- Tasks 8–15 ainda não comprovadas;
- o Kernel vertical `provider → sandbox → evidence → release → outcome` ainda não foi provado;
- coverage mutativa, rollback, canary, cutover e soaks permanecem pendentes.

Este plano executa duas etapas inseparáveis:

1. concluir e certificar a Elite Engineering Factory v2;
2. construir a Quality Foundry e a prova comparativa mundial sobre essa fundação.

O plano predecessor é `docs/superpowers/plans/2026-07-09-atlas-elite-engineering-factory-v2.md`.

### Objetivo final

Dev, Forge e Autônomos são três regimes da mesma empresa autônoma de produto e engenharia:

- **Dev:** conversacional, altamente agêntico, R0–R5; operador fornece intenção e autoridade, não supervisão de testes, integração ou retries.
- **Forge:** recebe commissioning e autoridade inicial e executa Obras de semanas ou meses sem participação rotineira do operador.
- **Autônomos:** trabalha 24/7 exclusivamente na evolução do Atlas, sem sessão humana ou operador no caminho ordinário.

Os três compartilham a mesma barra, Kernel, sistema de produto, arquitetura, implementação, verificação, release, evidência e autoridade de claims. O limite da entrega é problema até outcome em produção.

### Fora de escopo

- runtime v3 paralelo;
- shell/IDE própria;
- tratar geração de código como entrega;
- depender de especialistas humanos;
- claim universal sem escopo/evidência;
- permitir que Autônomos altere Constituição, kill switch, claim engine ou envelope de autoridade.

## 2. Constituição da qualidade

### 2.1 Unidade indivisível

```text
intenção
→ verdade de produto
→ especificação congelada
→ execução
→ evidência independente
→ release
→ canary
→ outcome observado
```

Patch, teste verde ou merge isolado não é entrega.

### 2.2 Níveis oficiais

| Estado | Significado |
|---|---|
| `art_grade_delivery` | Todas as dimensões aplicáveis aprovadas, sem vermelho ocultado por média |
| `multiplier_proven` | Melhoria causal sobre o mesmo modelo sem Atlas |
| `world_leading` | Superioridade sobre os melhores baselines no escopo declarado |
| `world_10x_quality_proven` | Limite superior IC95 da razão Atlas/baseline ≤ 0,10 |

Esses estados são independentes de `cutover_ready`, janelas 24h–150d, custo e velocidade.

### 2.3 Dimensões conjuntivas

Toda entrega recebe disposição explícita de:

1. `product_strategy`;
2. `product_management`;
3. `domain_research`;
4. `ux_research`;
5. `interaction_design`;
6. `visual_design`;
7. `software_architecture`;
8. `backend`;
9. `frontend`;
10. `mobile`;
11. `data`;
12. `qa_test`;
13. `appsec_privacy`;
14. `performance_resilience`;
15. `devops_sre`;
16. `observability`;
17. `release`;
18. `technical_docs_dx`;
19. `maintainability_simplification`;
20. `outcome_analysis`;
21. `evidence_audit`;
22. `final_certification`.

Cada papel emite `pass`, `block` ou `not_applicable`. `not_applicable` exige regra, justificativa, evidência e assinatura. Ausência equivale a `block`.

### 2.4 Profundidade por risco

| Risco | Profundidade |
|---|---|
| R0 | aplicabilidade determinística e evidência mínima |
| R1 | revisão leve independente e testes locais |
| R2 | revisão padrão, contratos e integração |
| R3 | múltiplos verificadores, regressão, compatibilidade e release controlado |
| R4 | segurança, mutation/property, chaos e rollback |
| R5 | candidatos concorrentes, verificadores de famílias diferentes e disaster drills |

Registrar separadamente:

- `mode=dev|forge|autonomos`;
- `risk_class=R0…R5`;
- `complexity_band=C0…C5`;
- `duration_regime=interactive|durable_task|obra|continuous`;
- `work_topology=single|candidate_set|workcell|DAG|portfolio`.

Modo não define risco. Dev pode executar R5; Forge pode executar R1 ou R5; Autônomos executa qualquer risco permitido pela Constituição.

### 2.5 Quality loss e claim 10×

`quality_loss` inclui defeitos escapados, vulnerabilidades, privacidade, regressões, rejeições, rollback, rework, incidentes, impacto ao usuário, manutenção futura, outcome gap e incerteza apresentada como certeza. Pesos e normalização são pré-registrados e idênticos entre braços.

```text
upper_IC95(quality_loss_atlas / quality_loss_melhor_baseline) <= 0,10
```

Também é obrigatório:

- nenhuma dimensão crítica inferior ao baseline;
- nenhum `block` contabilizado como sucesso;
- intent-to-treat;
- falha, timeout, recusa e rollback no denominador;
- baseline zero ou exposição insuficiente não permite claim 10×.

Custo e tempo são medidos e otimizados, mas não bloqueiam qualidade. Gasto sem ganho causal é desperdício.

## 3. Arquitetura final

```text
Dev | Forge | Autônomos
        ↓
Product Intent Court → Spec Adversary → ExecutionOrder
        ↓
EliteExecutorKernel → Workcells/Candidatos → Verification Court
        ↓
Governor → Release/Canary/Rollback → Outcomes 0h–150d
        ↓
Causal Learning Gate → Atlas Decide → Kernel
        ↓
atlas_ledger_events → Rivals Adjudicator → Claims escopados
```

### 3.1 Contratos públicos

```php
ProductIntentCourt::adjudicate(ProductIntentCase $case): ProductIntentVerdict;
EliteExecutorKernel::execute(ExecutionOrder $order): EngineeringOutcome;
EliteExecutorKernel::observeOutcome(OutcomeObservation $observation): OutcomeLearningReceipt;

AtlasDevExecutionService::plan(DevIntent $intent): DevPlan;
AtlasDevExecutionService::run(ConfirmedDevRun $run): DevRunResult;

ForgeObraRuntime::commission(ForgeCommissioning $commissioning): ForgeObraSnapshot;
ForgeObraRuntime::tick(ForgeObraId $obra, ForgeTickBudget $budget): ForgeTickResult;
ForgeObraRuntime::control(ForgeObraId $obra, ForgeControlCommand $command): ForgeObraSnapshot;
```

Autônomos mantém CLI apenas como controle/diagnóstico; o ciclo produtivo vive em serviço interno chamado pelo daemon.

### 3.2 `ExecutionOrder`

Campos: schema, run/delivery IDs, modo, risco, complexidade, duração, topologia, hashes de ProductIntent/spec, world snapshot, workspace/base commit, allowed/forbidden scope, authority/DecisionReceipt, operator contract, roster/profundidade, rota de provider/model, tool permissions, evidence/release/outcome policies, experiment ref, idempotency key e `budget_posture=unbounded_quality_first`.

### 3.3 `EngineeringOutcome`

Campos: status (`released|completed_read_only|held|blocked|refused|reverted|release_uncertain`), hashes correlacionados, 22 role dispositions, evidence bundle, provider/sandbox/release/canary/rollback receipts, esforço do operador, custo/tokens/tempo, incertezas, agenda 0h–150d e `claim_eligible=false` por padrão.

### 3.4 Eventos canônicos

- `experiment.preregistered`;
- `unit.frozen`;
- `execution.started`;
- `operator.interval.closed`;
- `role.disposition.recorded`;
- `acceptance.adjudicated`;
- `release.authorized`;
- `release.landed`;
- `release.reverted`;
- `outcome.observed`;
- `learning.adjudicated`;
- `claim.evaluated`;
- `claim.issued`;
- `claim.revoked`.

Todos carregam `run_id`, hashes, timestamp, schema e provenance.

### 3.5 Autoridade dos dados

```text
Kernel/modos → atlas_ledger_events → Rivals Adjudicator → claim bundle → projeções
```

Reusar `atlas_engineering_runs`, `atlas_engineering_run_operator_actions`, `atlas_ledger_events`, `AiEngineeringCompanyRoleRun`, `ai_run_outcomes`, `ai_temporal_certifications`, tabelas Forge e artifacts Rivals. Outcome memories e certificações temporais não emitem claims.

A única tabela operacional inicialmente autorizada é `atlas_task_scope_reservations`, se não houver equivalente transacional: UUID, run, scope, active scope unique, owner, modo, authority hash, state, fencing token e timestamps.

## 4. Fase −1 — concluir a Factory v2

### 4.0 Baseline e ownership

- Override: executar na `main` local, preservando WIP concorrente.
- Consultar bootstrap, placement e blackboard antes de cada packet.
- Claim por arquivo/mission; conflito não é roubado.
- Revalidar P0 no HEAD vivo.
- Nenhum relatório substitui teste vivo.
- Sem push, deploy ou cutover implícito.

### 4.1 P0-13 — reservations Forge

Implementar acquire, renewal, expiry, release, takeover pós-expiração, fencing, retry idempotente e reconstrução pós-crash. Testar concorrência, worker antigo, crash e replay. Gate: P0-13 `CLOSED` e suite Forge verde.

### 4.2 P0-19 — apply nativo Autônomos

Produção não depende de callbacks de teste; `dryRun=true` não é default; provider passa por `ProviderPort`; apply ocorre no sandbox; worker não se autoverifica; daemon sobrevive a timeout/kill/provider down; apply failure não resolve task. Gate: suites Native Worker/Daemon verdes e P0-19 `CLOSED`.

### 4.3 Task 8 — Kernel vertical

Implementar contratos tipados, provider real, sandbox, evidence/repair/replay/regression, Verification Court, `AuthorizedMergeAction`, release, canary, rollback e outcome correlacionado. Executar fatias read-only, provider, sandbox, verification, release, canary, rollback e reconciliation. Gate: E2E real com hashes correlacionados.

### 4.4 Tasks 9–11 — modos

- Dev: façade única, surfaces como adapters, R0–R5, WIP preservado, sem dispatch direto de provider, handoff Forge idempotente.
- Forge: `ForgeObraRuntime`, commissioning, supervisor/leases/heartbeat/fencing, Kernel por packet, integração serial, snapshot por eventos.
- Autônomos: Brain → Arena → Courts → reservation → Task Fabric → native workcell → Kernel → Governor → outcome → learning; zero sessão humana e zero commit direto.

### 4.5 Tasks 12–13 — outcomes e cutover readiness

Outcome ausente é `unknown`; writers somente v2; v1 só traduz; migrations N−1; coverage 100%; rollback exercitado; readiness Kernel/Dev/Forge/Autônomos. Ativar por modo sem dual executor: Dev low/mixed, Dev R5, Forge 1/3/10 packets, Autônomos staging e produção limitada. Gate: `cutover_ready`.

### 4.6 Tasks 14–15

Iniciar janelas 24h, 7d, 30d, 90d e 150d. Remover v1 só depois da janela de rollback e zero uso. Não remover por prefixo `AtlasLoop*`; migrar lógica útil antes.

## 5. Fase 1 — Constituição executável e honestidade

- materializar níveis, taxonomia e RoleDisposition;
- estender o roster existente, sem segundo catálogo;
- remover `passed` automático;
- packet `planned` não é execução/sign-off;
- ausência de evidence, ledger, world snapshot ou role receipt bloqueia;
- claims sem proveniência viram `legacy_unproven`;
- apenas Rivals emite/revoga claims.

Gate: paridade entre modos, autor não certifica a própria saída, N/A forjado falha, nenhuma média mascara vermelho e nenhum componente fora de Rivals emite `world_*`.

## 6. Fase 2 — Elite Workcell Operating System

Aprofundar `AtlasAgenticWorkcellRuntimeService`, registry de papéis e Engineering Company Runtime. Não criar outro Elite Workcell.

Regras:

- todos os 22 papéis participam;
- topologia muda ordem/profundidade, não membership;
- builder, verifier e final certifier têm contextos independentes;
- R4/R5 usam famílias de modelos diferentes quando disponíveis;
- sem verifier independente, entrega é segurada;
- candidatos trabalham em sandboxes separados e integram serialmente;
- juiz recebe spec/artefato, não a defesa do autor;
- certificador não altera código.

Circuit breakers:

- mesmo failure fingerprint três vezes → replanejar;
- duas rodadas sem evidence delta → trocar abordagem;
- spec ambígua → Courts;
- providers indisponíveis → pausa durável;
- autoridade insuficiente, ledger inconsistente ou irreversibilidade fora do envelope → hard-stop;
- candidato sem frontier improvement encerra a linha, não a missão.

## 7. Fase 3 — Product/Intent e Spec Courts

Criar somente `ProductIntentCourt`, aprofundando Product Truth, IntentRouter/Envelope e falsification probes. O veredito contém problema, usuário, valor, métrica/janela, fontes, restrições, não objetivos, hipóteses, incertezas, alternativas, falsificadores, side effects, aceitação, release e outcomes.

Aprofundar `SpecAdversary`, sem segunda court: ProductIntent hash, world snapshot, invariantes, NFRs, segurança, acessibilidade, observabilidade, compatibilidade, migração, rollback, roles, oráculos e invalidadores.

Gate: contradição/métrica ausente bloqueiam; spec drift exige nova versão/hash; paridade entre modos.

## 8. Fase 4 — Engineering World Model

Usar `AtlasSoftwareTwinRuntimeService` como fachada, reutilizando AURG, code world model e `WorldModelGraphRanker`. Incluir código, contratos, deploy, runtime, flags, incidentes, ownership, outcomes, performance, segurança, docs, decisões, concorrência e versões de ferramentas/providers.

Todo fato tem provenance, workspace, validade temporal, freshness, confiança calibrada, consumidor e prediction→observed. Unknown/stale permanece unknown; simulação não é evidência; previsão errada reduz confiança; cada order congela snapshot.

## 9. Fase 5 — Atlas Decide e Capability Market

Criar um `CapabilityMarketClearingService` interno ao Atlas Decide. Ordem: elegibilidade/autoridade, risk-fit, qualidade comprovada, evidência/calibração, diversidade, disponibilidade, tempo e custo. Barato pior nunca vence.

Matriz N×M: modelos eficientes/intermediários/frontier × bare/Atlas/concorrente/full-power. Provar uplift same-model, generalização cross-provider, eficiente+Atlas versus frontier bare e absorção de modelos novos sem fork.

Rotas novas: shadow, tráfego limitado, hipótese/preregistration, promoção causal e revogação por regressão tardia.

## 10. Fase 6 — Verification, Release e Outcomes

Criar no máximo `VerificationCourtAcceptanceGate implements AcceptanceGate`, compondo os órgãos existentes.

Evidence aplicável: unit, integration, contract, E2E, property, mutation, differential, metamorphic, security, privacy, performance, accessibility, chaos, recovery, replay, static analysis, compatibility, migration, rollback e product outcome.

Release:

```text
prepare → authorize → act → canary → settle
```

Canary precede terminal state; falha reverte/quarentena; incerteza permanece `release_uncertain`; Governor decide e MergeActuator atua; credenciais são efêmeras; R3+ inclui SBOM/provenance.

Outcomes 0h, 24h, 7d, 30d, 90d e 150d capturam defeitos, incidentes, rollback, rework, vulnerabilidades, performance, adoção, impacto, manutenção e produto.

## 11. Fase 7 — Dominância por modo

### Dev

Conversation-first; operador controla intenção/autoridade; Atlas controla retries, testes, integração/recovery; R5; sandboxes; integração serial; handoff somente por duração/topologia; medir perguntas/minutos/overrides/cancelamentos; nenhum GET mutativo e nenhuma perda de WIP.

### Forge

Commissioning único; autoridade/release/interrupções congeladas; DAG persistente; packets retomáveis; leases/heartbeats/fencing/reaper; milestones após Kernel/Courts/Governor; snapshots por eventos; zero efeito duplicado.

### Autônomos

Zero operador steady-state; Brain+Muscle internos; apenas Atlas; ciclo 24/7; proposal arena; domain map/leverage; `dry` rotaciona; nenhuma sessão externa humana; nenhum commit direto; código volta como task; apenas routing/memory/policy reversível autoexperimentável; Constituição/claim engine/kill switch imutáveis pelo runtime.

## 12. Fase 8 — Compounding causal e simplificação

Adicionar `CausalLearningGate`. Nenhuma promoção sem hipótese, assignment receipt, baseline, métrica, comparação, janela, efeito+IC, confounders, rollback e outcome.

Manter domain map, structural leverage ranker, outcome memory, recurrence map, anti-template-farm, proposal competition, simplification-first, drift detector, benchmark gap generator e frontier observer.

Simplificação recebe crédito somente com equivalência, consumidores preservados, contratos/configs preservados, complexidade realmente reduzida e outcomes não inferiores. Correlação sem assignment fica `hold`; código nunca é promovido diretamente pelo learning layer.

## 13. Fase 9 — Atlas World Engineering Trial

Rivals é a única autoridade:

```text
preregister → freeze units → execute arms → ingest → adjudicate → observe → issue/reject/revoke
```

Benchmarks públicos são diagnósticos. O núcleo é um trial privado rotativo com tarefas frescas/time-sliced, hidden tests/golds fora do workspace, egress restrito, canários de contaminação, soluções alternativas, invalidação pré-unblinding, casos renovados e recursos pinados.

Braços:

1. mesmo modelo bare;
2. mesmo modelo com Atlas;
3. melhor concorrente nativo;
4. frontier bare;
5. artefato histórico humano aceito quando disponível;
6. Atlas full-power.

Sem especialistas/live judges humanos. Adjudicação combina investigadores-modelo independentes, hidden/property/metamorphic tests, verificadores implementation-independent, security, replay, outcomes reais e artifacts históricos.

Estatística: preregistration, poder ≥90%, caso/repo como unidade, repetições aninhadas, ITT, bootstrap hierárquico/mixed-effects, survival/RMST, Poisson/negative-binomial/event limits, Holm/hierarquia, IC95, três campanhas e nenhum best-run cherry-pick.

Fronteiras iniciais: Dev ~150 tarefas distintas com power prevalecendo; Forge ≥30 Obras; Autônomos 150 dias e exposição suficiente. Claims separados por modo/stack/risco/duração, expiram em 90 dias e revalidam após frontier/harness/regressão material.

## 14. Fase 10 — Ondas de domínio

1. Atlas + full-stack enterprise: PHP/Laravel, TypeScript/React, APIs, PostgreSQL, queues, auth, migrations, observabilidade, CI/CD, produto/UX e repos externos.
2. Polyglot/data: Python, Go, Java/Kotlin, Node, Rust, pipelines, streaming, busca e distribuídos moderados.
3. Mobile/desktop: iOS, Android, Expo/React Native, Flutter, desktop, offline/sync, acessibilidade/performance.
4. Infra crítica: cloud, SRE, segurança, privacidade, high-risk migrations, incident response, distribuídos e DR.
5. Especializados: ML systems, compilers, embedded, real-time, HPC e regulados.

Promover onda somente com corpus, benchmark privado, oracle coverage, multiplier causal, soak, outcomes e não regressão.

## 15. Testes e hard guards

| Subsistema | Cenários mínimos |
|---|---|
| Reservations | corrida, expiry, takeover, fencing, crash, replay |
| Kernel | read-only, provider, sandbox, release, rollback, reconciliation |
| Coverage | provider/fs/Git/release/deploy entrypoints |
| Product/Spec | contradição, métrica ausente, drift, stale world |
| Papéis | ausente, N/A forjado, autor como juiz, stale evidence |
| Workcells | kill, retry, candidates, ownership overlap |
| Decide | barato pior, unknown, exploration, deterministic replay |
| Verification | false-green, hashes, mutation, property, hidden |
| Release | Governor/ledger down, canary/revert fail, N−1 |
| Dev | WIP, surface parity, R5, handoff |
| Forge | 1/3/10 packets, crash boundaries, orphan recovery |
| Autônomos | zero human session, 500+ tasks, dry rotation, restart |
| Outcomes | missing, delayed, contradictory, revocation |
| Rivals | contamination, invalid task, unblinding, multiplicity, replay |
| Segurança | secrets, egress, prompt injection, poisoned dependency, authority replay |

Build falha para provider fora de `ProviderPort`, direct provider spawn, Git/fs fora das ports, release fora do Governor, self-verification, learning promovendo código, simulation→prod, missing evidence→pass, canary pós-resolved, callback produtivo de teste, sessão externa como autonomia, shared vendor symlink, destructive DB command ou teste em banco vivo.

## 16. Rollout e estados honestos

1. `implemented_not_cutover_ready`;
2. `cutover_ready`;
3. `observed_24h`;
4. `observed_7d`;
5. `observed_30d`;
6. `observed_90d`;
7. `observed_150d`;
8. `quality_foundry_ready`;
9. `multiplier_proven`;
10. `world_leading`;
11. `world_10x_quality_proven`.

Nenhum implica o próximo. Rollback usa flags por modo, mesmo Kernel, shadow read-only, stop/drain/quarantine e artefato N−1. Outcome contraditório revoga claim.

## 17. Child plans executáveis

1. `01-factory-v2-closure.md`;
2. `02-quality-constitution-and-evidence.md`;
3. `03-elite-workcell-product-and-spec.md`;
4. `04-world-model-and-capability-market.md`;
5. `05-verification-release-and-outcomes.md`;
6. `06-dev-forge-autonomos-dominance.md`;
7. `07-rivals-world-engineering-trial.md`;
8. `08-causal-compounding-and-domain-waves.md`.

Cada packet contém finding/hypothesis, owner, allowed files, teste vermelho, implementação mínima, testes focados/vizinhos, evidence, migration/rollback, docs, aceite, commit e próximo packet. Child plan decompõe; não redesenha.

## 18. Critério final

Completo somente com P0-13/19 fechados, Tasks 8–15 executadas, Kernel vertical, 100% coverage mutativa, zero bypass, 22 sign-offs, Courts reais, world model calibrado, release/canary/rollback exercitados, outcomes ativos, cutover por modo, soaks decorridos, Quality Foundry operacional, trials privados, multiplier causal e claims estatísticos.

Antes de evidência suficiente, o único estado correto é `world_10x_quality_proof_pending`.
