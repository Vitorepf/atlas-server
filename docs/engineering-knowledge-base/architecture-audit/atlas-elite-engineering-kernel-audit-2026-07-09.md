---
id: atlas-elite-engineering-kernel-audit-2026-07-09
type: engineering_knowledge
title: Atlas Elite Engineering Kernel Audit 2026-07-09
status: source_material
category: architecture
priority: 87
summary: Auditoria do kernel compartilhado por Atlas Dev, Forge e Autonomos, da tese N x M, das fronteiras entre modos e da capacidade real de garantir engenharia superior por contexto, execucao, prova, reparo e aprendizado.
tags:
  - engineering-kernel
  - elite-executors
  - n-times-m
  - architecture-audit
capabilities:
  - elite_engineering_kernel_audit
  - cross_mode_quality_assessment
  - multiplier_evidence_model
decisions:
  - Dev, Forge e Autonomos sao tres regimes de uma fabrica, nao tres barras de qualidade.
  - O kernel deve possuir mecanismos de execucao e evidencia; policy e presenca do operador pertencem aos adapters e control planes.
  - O multiplicador M so pode ser reivindicado por paired benchmarks e outcomes longitudinais.
  - Nenhum default de adapter pode fabricar evidencia favoravel ausente.
maintenance:
  - Revalidar apos consolidacao do Engineering Kernel, mudanca de adapters, governance, release ledgers, Rivals ou outcome learning.
related_paths:
  - docs/engineering-knowledge-base/atlas-real-engineering-execution-kernel.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-thesis-multiplier-channel.md
  - docs/engineering-knowledge-base/atlas-autonomous-engineering-government.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - app/Services/Ai/EngineeringKernel
  - app/Services/Ai/Kernel
  - app/Services/Ai/RealExecution
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-elite-engineering-kernel-audit-2026-07-09
graph_title: Atlas Elite Engineering Kernel Audit 2026-07-09
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-architecture-audit-readme
graph_status: active
graph_source: repo
human_name: Atlas Elite Engineering Kernel Audit 2026-07-09
canonical_name: Atlas Elite Engineering Kernel Audit 2026-07-09
technical_name: atlas-elite-engineering-kernel-audit-2026-07-09
cartography_type: module
canonical_source: docs/engineering-knowledge-base/architecture-audit/atlas-elite-engineering-kernel-audit-2026-07-09.md
owner: architecture-audit
repo_paths:
  - docs/engineering-knowledge-base/architecture-audit/atlas-elite-engineering-kernel-audit-2026-07-09.md
allowed_changes:
  - Atualizar o snapshot quando a raiz comum, a evidencia ou os modos mudar.
forbidden_changes:
  - Tratar este audit como uma nova raiz de arquitetura.
  - Declarar garantia mundial ou multiplicador numerico sem evidence pack comparativo.
depends_on:
  - atlas-ai-architecture-audit-readme
  - atlas-real-engineering-execution-kernel
flows_to:
  - atlas-dev-elite-execution-audit-2026-07-09
  - atlas-forge-elite-execution-audit-2026-07-09
  - atlas-autonomos-elite-execution-audit-2026-07-09
unlocks:
  - elite-kernel-consolidation
governs:
  - architecture-audit
evidence:
  - docs/engineering-knowledge-base/architecture-audit/atlas-elite-engineering-kernel-audit-2026-07-09.md
evidence_refs:
  - symbol: EliteExecutorKernel
  - symbol: SovereignHonestyFloor
  - symbol: ProviderPort
  - symbol: WorkcellExecutor
  - symbol: ReceiptLedger
  - symbol: MergeActuator
  - symbol: BudgetMeter
  - command: atlas:ai:architecture-validate
  - command: atlas:programming:pre-benchmark-readiness
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: critical
visual_tags:
  - system
  - kernel
  - architecture-audit
ai_entrypoints:
  - Leia primeiro Resumo, Onde Se Encaixa e Estado real do kernel.
ai_usage_notes:
  - O desenho target e diagnostico; implementar exige patch nos owners canonicos.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Criar um kernel por modo.
  - Confundir interface existente com semantica implementada.
  - Chamar readiness estrutural de superioridade comprovada.
observability_signals:
  - atlas:ai:architecture-validate --json
  - atlas:programming:pre-benchmark-readiness --json
  - atlas:cognition:scorecard --json
next_actions:
  - Fechar um unico caminho real de spec a outcome para os tres modos.
implementation_state: audit_snapshot_2026_07_09
line_limit: 520
---
# Kernel compartilhado — auditoria da fabrica de elite

## Papel no Atlas

Este e um snapshot de arquitetura e runtime em 9 de julho de 2026. Ele nao
substitui `atlas-real-engineering-execution-kernel.md`, nao transforma backlog
como Constitution Gate em fato landed e nao autoriza uma nova raiz chamada
“elite kernel”. Seu papel e comparar a raiz pretendida com codigo, testes,
commands e ledgers atuais.

## Resumo

O Atlas tem uma **tese arquitetural forte**: modelos sao motores substituiveis;
contexto, decomposicao, ferramentas, verificacao, reparo, memoria e learning
formam o multiplicador do sistema. Tambem tem componentes reais e sofisticados
para quase todas essas funcoes.

O Atlas ainda nao tem uma **unica fabrica profundamente integrada** que torne a
mesma garantia inevitavel em Dev, Forge e Autonomos. Hoje existem:

- um `EliteExecutorKernel` real, concentrado em proof, honesty floor, repair e
  adapters de acceptance;
- cinco interfaces de Engineering Kernel para provider, workcell, receipt,
  merge e budget;
- implementacoes e runtimes valiosos em `Ai/Kernel`, `EngineeringKernel`,
  `RealExecution`, Programming, Forge e SelfConstruction;
- varios caminhos que ainda executam, verificam, persistem ou aterrissam fora
  do kernel comum;
- adapters com semantica desigual e defaults que podem tornar ausencia de
  evidencia parecida com evidencia limpa;
- certifiers estruturais verdes sem benchmark e com testes focados do kernel
  falhando no checkout auditado.

Em uma frase: **a raiz comum existe como arquitetura e acceptance seam; ainda
nao existe como o unico dono operacional de spec -> execution -> proof -> land
-> outcome**.

## A equacao N x M

O objetivo pode ser formalizado assim:

```text
Q_Atlas(N, W, C) = Q_modelo(N, W, C) x M_sistema(W, C)
```

onde:

- `N` e o motor/modelo disponivel;
- `W` e o workload de engenharia;
- `C` inclui repo, budget, tempo, risco e autoridade;
- `M` e o efeito conjunto do sistema Atlas;
- `Q` nao e uma nota unica: e um vetor de resultado.

O Atlas deve capturar um salto de um futuro modelo muito melhor sem precisar
reescrever a fabrica. Tambem deve permitir que um modelo eficiente supere um
modelo cru mais capaz quando contexto, tooling, critics, repair e learning
forem melhores. Isso e plausivel. Nao e ainda um numero comprovado.

### O que compoe M

```text
M = contexto x spec/decomposicao x provider/tools x execucao governada
    x verificacao/reparo x release safety x outcome memory x learning causal
```

O produto desses fatores nao deve ser interpretado literalmente como
multiplicacao de percentuais independentes. E uma tese de alavancagem: cada
camada aumenta a probabilidade de resultado correto e reduz custo de erro.

### Vetor Q obrigatorio

Qualidade/superioridade deve incluir: tempo ao primeiro patch correto e ao
release; first-pass acceptance; repair rate/success; escaped defects em 7/30
dias; rollback/incidents; complexidade/manutencao; cobertura de criterios;
evidence completeness; intervencoes/minutos do operador; custo por outcome;
conclusao long-horizon; recorrencia; e uplift atribuivel a learning.

Rapidez sem correcao e falsa. Qualidade sem tempo/custo e incompleta. Um numero
“100x” sem esse vetor esconde trade-offs e nao deve ser publicado.

## Onde Se Encaixa

Esta tabela descreve o **regime target**, nao o wiring atual:

| Eixo | Atlas Dev | Atlas Forge | Atlas Autonomos |
|---|---|---|---|
| Operador | Presente por intencao e decisoes de valor | Presente no commissioning/planejamento | Ausente da execucao ordinaria; humano preserva constituicao/kill |
| Duracao tipica | Minutos a dias, podendo assumir trabalho extremo | Uma semana a meses | Continua, 24/7 |
| Iniciacao | Pedido/conversa/evento do operador | Obra comissionada | Observacao/originacao interna |
| Comunicacao | Interativa, mas pouco chat/microgestao | Milestones, blockers materiais, reports | Telemetria, receipts e alarmes |
| Continuidade | Retomada e handoff sob a mesma conversa/missao | Supervisor duravel de Obra | Daemon/frota permanente |
| Budget | Por task/run, escalavel | Envelope de Obra e milestones | Budget autonomo e policy continua |
| Autoridade | Escopo concedido por pedido | Charter de Obra | Constituicao e policies permanentes |
| Barra de qualidade | Soberana | A mesma | A mesma |

Escala nao e capacidade intelectual. Dev nao e “patch rapido”; Forge nao e
“Dev melhor”; Autonomos nao e “loop sem humano”. Sao adapters de autoridade,
tempo e operator presence sobre uma capacidade compartilhada.

## Fluxo

```text
Surface / Trigger
  -> Mission Control + operator contract
  -> Policy Plane + budget + authority
  -> Context/Memory compiler
  -> Spec Court: intent, criteria, adversary, admission
  -> Engineering Kernel
       ProviderPort
       WorkcellExecutor
       Tool/Sandbox Runtime
       ReceiptLedger
       BudgetMeter
       Repair/Replay
       MergeActuator
  -> Verification Court
  -> Governor: land/canary/revert/release
  -> Outcome + causal learning
  -> next decision
```

### O que deve ser compartilhado

Schemas de intent/criteria/workcell/evidence/outcome; context e proveniencia;
provider/tool invocation; sandbox, scope e workspace baseline; false-claim e
honesty floor; failure taxonomy, repair/replay; receipt lineage;
land/canary/revert; budget; outcome learning; benchmark e claim policy.

### O que deve variar por modo

Quando o operador e consultado; quem inicia; tamanho/duracao do envelope;
cadencia de checkpoint; persistencia/lease/recovery; budget/permissions;
paralelismo; e stop/continue/escalation policy.

Nenhuma variacao pode omitir testes, inventar security limpa, waivar mutation
sem razao ou chamar simulacao de entrega.

## Estado real do kernel

### `EliteExecutorKernel`

O class atual declara explicitamente Dev, Forge e Autonomos com a mesma barra e
diferenca apenas de operator presence/scale. Expoe:

- `OutcomeProofGate`;
- `FalseClaimInvariant` por contrato unificado;
- `SovereignHonestyFloor`;
- `RepairDiagnosisStage`;
- adapters Dev, Forge e Autonomos.

Esta e uma boa seam de acceptance. Ela nao injeta nem possui `ProviderPort`,
`WorkcellExecutor`, `ReceiptLedger`, `MergeActuator` ou `BudgetMeter`. Portanto,
o nome “executor kernel” e hoje mais amplo que sua responsabilidade efetiva.

### Sovereign honesty floor

O floor modela invariantes excelentes: execucao/testes reais, contexto minimo,
mutation em superficies decisorias, simbolos/testes, security, criteria
frozen, diversidade de judges, performance, migration, architecture, sensitive
properties e regression lock/replay depois de repair.

O problema nao e a lista. E a origem do bundle e o ponto de enforcement:

- Dev traduz mutation e security reais, mas o run path frequentemente nao
  entrega todos os hashes, judges, contexto e assertion counts;
- Forge e uma shell deprecated sobre o adapter Autonomos;
- Autonomos/Forge usam mutation sempre waived e, se security faltar, criam um
  scan `ran=true`, limpo;
- no task serving, o gate post-commit pode retornar `ok=false`, mas esse retorno
  e ignorado antes de resolver o packet.

Logo, `bar(dev)=bar(forge)=bar(autonomos)` e verdade no target e nas invariantes,
mas nao no evidence plumbing real.

### Cinco interfaces e a lacuna semantica

| Interface | Promessa | Adapter atual | Gap |
|---|---|---|---|
| `ProviderPort` | Invocar engine governado | Normaliza facts de uma invocacao feita fora | Metodo `invoke` nao invoca |
| `WorkcellExecutor` | Executar workcell admitida | Faz `next`/`report` no task serving | O trabalho de codigo continua externo |
| `ReceiptLedger` | Persistir prova canonica | Adapter real para release ledger | Contrato sofreu drift de campos |
| `MergeActuator` | land/canary/revert | Expoe apenas `revert` | Land e canary fora da interface |
| `BudgetMeter` | Medir custo comum | Adapter para Maestro cost | Boa seam, ainda nao universal |

As interfaces usam `array<string,mixed>`. Isso parece simples, mas transfere
uma interface enorme e implicita aos chamadores. DTOs/versioned schemas
pequenos tornariam estados impossiveis de representar e impediriam defaults
silenciosos.

## Fragmentacao atual

Ha pelo menos tres areas chamadas kernel/execution:

- `app/Services/Ai/Kernel`;
- `app/Services/Ai/EngineeringKernel`;
- `app/Services/Ai/RealExecution`;

Somam-se pipelines Dev, execution cycles Forge e task-serving Autonomos. Nem
toda duplicacao deve virar uma classe unica: orchestration por regime e
legitima. A duplicacao nociva e cada caminho possuir sua propria semantica de
provider, evidence, acceptance ou completion.

Pelo criterio de modulo profundo, a API comum deveria ser pequena e esconder
grande complexidade. Hoje a API e pequena na assinatura, mas rasa na semantica:
o chamador ainda precisa saber qual service realmente invoca, executa, prova,
aterrissa e registra outcome.

## Evidencia e ledgers

### Outcome ledger

`storage/atlas/atlas_decide/live_outcomes.jsonl` tinha 3.406 rows: 3.101
success e 305 failure. Apenas 39 estavam marcados `proven_real`; 36 tinham
`quality_score`. Na fatia desde 7 de julho, havia proven-real, mas nenhum
quality score preenchido. O arquivo mistura categorias e schemas historicos,
portanto nao e uma taxa de engenharia. Ele mostra um problema: outcome learning
nao recebe evidencia rica de forma universal.

### Release decision ledger

O verification court ledger continuava atualizando, mas o merge governor
release ledger parou em 4 de julho, enquanto centenas de commits `atlas-task`
ocorreram depois. O codigo explica o drift:

- o release ledger passou a exigir `evidence_refs` e `rollback_posture`;
- `AtlasTaskCommitGovernanceChain::record()` ainda chama sem esses campos;
- a excecao e capturada/fail-open;
- verification continua e o commit pode prosseguir.

Isso quebra lineage exatamente na fronteira release/commit. Nao e apenas um
dashboard stale; e uma parte da garantia comum que deixou de observar o runtime.

### Governance dossier

O dossier agregou milhares de verdicts e recomendou `hold`, com cerca de metade
dos eventos em estado que bloquearia se enforce estivesse ativo. O corpus inclui
IDs sinteticos/test fixtures e risk `unknown`; a taxa nao pode ser publicada
como qualidade de producao. Ainda assim, confirma dois gaps: proveniencia de
evidence e risk classification nao estao limpas, e muitos controles permanecem
observe-only.

## Gates e verificacao desta vistoria

`atlas:ai:architecture-validate --json` falhou 3 de 169 static scans, com quatro
violacoes: contrato de hash/JSON, route de kernel report ausente e evidence de
onboarding no self-improvement, alem da projecao `atlas_engineering_runs`
indisponivel. Isso nao invalida toda a arquitetura, mas impede claim green.

A bateria focada:

```text
php artisan test tests/Unit/Ai/EngineeringKernel tests/Feature/Ai/EngineeringKernel
```

terminou com 164 testes passados, 17 falhas e 3 warnings (502 assertions). Parte
das falhas referencia `AtlasLoopWiredCallerService`, removido com o Loop morto;
outras cobrem parity/ghost certifiers. O kernel tem um corpus forte, mas o
checkout atual nao esta fresh-green.

`atlas:cognition:scorecard` mostrou 9,93 estrutural, ao mesmo tempo em que
claim policy mantinha benchmark, rivals e superiority falsos e Context/Memory
tinham pipeline parcial. O contraste e instrutivo: **estrutura quase completa
nao equivale a capacidade superior comprovada**.

## Matriz cross-mode

| Invariante | Dev | Forge | Autonomos | Paridade real |
|---|---|---|---|---|
| Context intake | Wired | Parcial | Wired pre-commit | Parcial |
| Criteria frozen | Evidencia incompleta | Simulacao/partial | Evidence parcial | Nao |
| Execucao real | Real, fora do kernel comum | Nao observada | Externa ao kernel comum; commits reais | Nao |
| Test facts | Sim, nem sempre completos | Sinteticos em simulation | Server verifier real | Parcial |
| Security | Adapter real | Default favoravel herdado | Default favoravel | Nao |
| Mutation | Real quando chega | Waived | Waived | Nao |
| Sovereign enforcement | Parcial | Observe default | Retorno post ignorado/observe | Nao |
| Release ledger | Local paths | Nao provado | Drift apos 04/07 | Nao |
| Outcome/learning | Parcial | Corpus minimo | Parcial/starved | Nao |

## Scorecard

Notas de desenho medem coerencia/completude; operacionais usam `0` inexistente,
`5` parcial, `8` forte/integrado e `10` prova mundial repetivel. A readiness
`>10x` usa uma ladder comum: `1` harness sem corpus real elegivel; `2` runtime/
corpus local sem paired baseline; `3` paired interno; `5` externo repetido;
`8` baseline humano + 30 dias; `10` resultado mundial repetivel.

| Dimensao | Nota | Justificativa curta |
|---|---:|---|
| Tese e arquitetura target | 9.0 | N x M e fabrica unica sao direcoes fortes |
| Estrutura implementada | 6.3 | Muitos mecanismos reais, mas fragmentados |
| Profundidade do modulo comum | 5.0 | Acceptance forte; execution semantics permanecem fora |
| Barra de qualidade desenhada | 8.8 | Honesty floor e gates cobrem classes importantes |
| Barra aplicada igualmente | 4.8 | Evidence/default/enforcement divergem por modo |
| Evidencia e learning | 4.0 | Ledgers materiais, proveniencia/outcome incompletos |
| Fresh-green do kernel | 4.5 | 164 testes passam, 17 falham; architecture validate falha |
| Prova do multiplicador M | 2.0 | Pre-benchmark bloqueado e bateria externa nao rodada |

**Nota de estrutura target: 9,0/10. Nota da implementacao como kernel realmente
compartilhado: 6,3/10. Nota de qualidade operacional comprovada: 4,8/10.
Prontidao comprovada para sustentar o claim maior que 10x: 2,0/10.**

## Notas consolidadas dos modos

| Modo | Estrutura | Qualidade operacional comprovada | Fit atual ao telos |
|---|---:|---:|---:|
| Atlas Dev | 8.4 target / 6.7 runtime | 6.4 | 5.9 |
| Atlas Forge | 7.5 target / 4.0 runtime | 2.5 | 2.5 |
| Atlas Autonomos | 7.5 | 5.0 | 4.3 |
| Kernel comum | 9.0 target / 6.3 runtime | 4.8 | 5.2 |

Os numeros nao sao benchmarks externos; sao notas de auditoria explicadas por
evidencia local. Sua funcao e ordenar trabalho, nao gerar marketing.

## Sequencia de maior alavancagem

1. **Reconciliar autoridade/telos.** Dev cobre qualquer complexidade/barra; o
   regime duravel pode variar com escala. Remover owner-doc contradictions.
2. **Fazer o kernel possuir a execucao.** ProviderPort deve invocar; Workcell
   deve executar; MergeActuator deve land/canary/revert; receipt e budget devem
   ser universais.
3. **Substituir arrays por contracts versionados.** Spec, execution evidence,
   verdict, release e outcome sem defaults favoraveis.
4. **Paridade fail-closed.** Corrigir adapter Forge/Autonomos, retorno post-commit
   ignorado, gate order do Dev e modes observe.
5. **Restaurar lineage.** Corrigir o contrato do release ledger e backfill
   explicitamente, sem fabricar evidencia retroativa.
6. **Consertar a base de prova.** Remover dependencias de Loop morto nos testes,
   fechar architecture validate e separar fixture/production ledgers.
7. **Fechar outcome loop.** Quality score, 7/30-day outcome, recurrence e causal
   link devem alimentar provider/policy/task selection.
8. **Executar campanhas graduais.** Dev paired workload; Forge Golden Obra;
   Autonomos unplugged 7/30/90 dias.
9. **Publicar claim so no fim.** Rivals/evidence pack determina onde ha uplift,
   com intervalo de confianca e failure envelope.

## Protocolo para provar maior que 10x

1. Congelar workload, repo revision, acceptance criteria e outcome window.
2. Rodar: modelo direto, melhor harness concorrente e cada modo aplicavel; para
   claim contra o melhor time, um baseline humano comparavel e obrigatorio.
3. Igualar ou reportar budget, hardware, provider e tentativas.
4. Preservar todos os receipts, diffs, testes, intervencoes e custos.
5. Adjudicar blind quando possivel.
6. Observar defeitos/reversoes por 30 dias.
7. Reportar vetor completo, mediana, dispersao e failures, nao so winners.
8. Repetir em security, migrations, refactor, greenfield, debugging,
   long-horizon e self-construction.

“10x” so e verdadeiro na dimensao e no envelope medidos. E possivel ser 12x em
operator minutes, 2x em tempo e 1,3x em first-pass quality. O relatorio deve
preservar esse formato e nunca multiplicar ganhos dependentes.

## Contratos

- Um executor comum recebe spec admitida e devolve outcome evidenciado.
- Policy decide autoridade; kernel executa mecanismos, nao estrategia.
- Toda evidence ausente e `unknown/missing`, jamais `passed` por default.
- Completion e release exigem gates universais e receipt correlacionado.
- Simulacao usa tipos e stores separados.
- Learning so promove mudanca causalmente ligada a outcome.
- Provider/model pode trocar sem perder criteria, evidence ou lineage.

## Regras para IA

- Nao criar um quarto pipeline.
- Nao mover toda policy para dentro do kernel.
- Nao usar class existence ou test count como proof of capability.
- Nao chamar adapter normalizador de provider executor.
- Nao esconder falha de ledger em catch fail-open sem health alarm.
- Nao declarar `M=50x` ou `M=100x` antes do protocolo comparativo.

## Escopo de Implementacao

Este arquivo nao autoriza refator multi-modulo. Cada item exige context pack,
placement, branch/Obra, migration/backfill policy e testes de compatibilidade.

## Dependencias

Depende de docs governance, Mission Control, Policy Plane, Context/Memory,
provider/tool runtime, Programming, Forge, SelfConstruction, Spec/Verification
Courts, Governor, Evidence Ledger, TEOS, Rivals e claim policy.

## Evidencias

Snapshot do commit `7c6e729b8a84`. Comandos: `atlas:ai:architecture-validate`, readiness/certifiers Dev
e Forge, `atlas:task:health`, `atlas:brain:state`, cognition scorecard,
pre-benchmark readiness e a bateria focada de Engineering Kernel.

Paths centrais: `app/Services/Ai/EngineeringKernel/`,
`SelfConstruction/AtlasTaskServingService.php`,
`SelfConstruction/Governance/AtlasTaskCommitGovernanceChain.php`,
`config/atlas_task_governance.php` e `storage/atlas/atlas_decide/`.

## Riscos

- A arvore estava suja e workers paralelos alteram counts.
- Ledgers podem misturar fixtures, eventos historicos e producao.
- Checks estruturais nao exercitam provider/filesystem/outcome; scores sao julgamento datado, nao medida externa.

## Exemplos

Se um modelo futuro for 100x melhor, o Atlas deve troca-lo no ProviderPort e
preservar contracts, evidence e learning. Se um modelo barato for mais fraco,
o Atlas pode vencer por contexto, decomposition, critics e repair. Nos dois
casos, a afirmacao so nasce quando paired outcomes mostram a vantagem.

## Proximas Acoes

O primeiro corte deve fechar uma unica vertical real, nao criar mais catalogos:
uma spec admitida executada pelo ProviderPort/Workcell, certificada pelo mesmo
floor, aterrissada pelo Governor, registrada nos ledgers e observada depois.
Repita os mesmos invariantes nessa vertical nos tres modos; adapters e seus
schedulers, leases, budgets e recovery continuam especificos do regime. Esse e
o menor caminho para transformar N x M em sistema.
