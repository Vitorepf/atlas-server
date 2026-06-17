# Atlas Company Success Engine — Buildout Canônico (meta ≥70%, beat Polsia)

> **Status:** spec de implementação canônica. Fonte de verdade para esta iniciativa. Autor: sessão de dissecação Polsia (2026-06-17). Implementadores: IAs posteriores (loop Hermes/MiniMax/codex; PHP-native).
> **Memória-mãe:** `atlas-company-success-goal-beat-polsia` · **Contexto:** `polsia-dissection`, `venture-foundry-sector-built`.
> **Regra de leitura obrigatória antes de codar:** rode/emule `php artisan atlas:ai:session-bootstrap --task="company success engine" --json` e `php artisan atlas:ai:place-feature "..." --json`; leia `docs/engineering-knowledge-base/atlas-venture-foundry-operating-system.md` e `atlas-ai-knowledge-governance-system.md`. Não confie só nesta doc — verifique o código vivo (caminhos podem ter evoluído).

---

## 1. A META (travada com o operador, 2026-06-17)

> **≥ 70% das empresas que o Atlas CRIA ou GERE atingem receita recorrente sustentada — definida como MRR ≥ R$1.000 mantido por ≥ 3 meses — medido por marco, sem prazo fixo. E mais eficiente que a Polsia (custo-por-empresa-de-sucesso).**

| Parâmetro | Valor travado |
|---|---|
| **Sucesso (numerador)** | MRR ≥ R$1.000 **sustentado** por ≥3 observações mensais consecutivas |
| **Coorte (denominador)** | TODAS as empresas sob o Atlas: **criadas** (Atlas originou) + **geridas** (Atlas não criou mas opera, ex.: Blackink) |
| **Janela** | Por marco, sem prazo fixo (venture só entra na conta ao atingir OU falhar definitivamente o marco) |
| **Alvo** | ≥ 70% |
| **Baseline Polsia** | ~10% fazem ≥$1 (régua mais fraca); 48% churn mês-1; melhor empresa ~$3-4k; $1-1.5M/mês de IA |

**Por que 70% é honesto:** não vem de "construir melhor" — vem de **pré-filtrar o denominador** (validar e matar ideia ruim ANTES de virar "empresa criada"). O cérebro que a Polsia não tem (Comprehension + Assessment + escada `no observation, no gate`) limpa a coorte.

---

## 2. Princípios pétreos (NÃO violar — invalidam a meta se quebrados)

1. **Sucesso é medido por MRR REAL observado e persistido, nunca auto-declarado.** Sem observação → `insufficient_data`, nunca "sucesso". (Anti-Goodhart, padrão `[[acde-compounding-delivery-engine]]`.)
2. **Scorecard TRIPLO sempre junto:** (a) taxa de sucesso %, (b) **taxa de admissão** (ideias avaliadas vs admitidas), (c) **contagem absoluta** de sucessos. Reportar só (a) é proibido — gameável por covardia (só admitir aposta certa) e por inflação (Polsia). 
3. **Reúso, não refragmentação.** `ai_venture_metric_observations` JÁ existe para MRR; a escada JÁ avalia gates por métrica. Tabela nova só quando nada existente cobre o conceito (e justificar no PR).
4. **Prove em si antes de escalar (disciplina NightShift):** `"Atlas must night-shift itself before it night-shifts any company"`. Validar o motor primeiro; Blackink (geri­da, sub-coorte mais dura) antes de criar nova.
5. **Toda autonomia default-OFF + byte-identical-OFF.** Flags novas desligadas; com a flag OFF o comportamento é idêntico ao atual. Ações externas (post/spend/charge) passam por trust-ladder + mandato aprovado pelo operador.
6. **Soberania local-first.** Coorte = empresas do operador + internas. Operar para terceiros (multi-tenant) é departure da tese — **fora de escopo desta meta** salvo decisão explícita.

---

## 3. O que JÁ existe (reusar) — grounded no código

| Peça | Local | Reúso nesta meta |
|---|---|---|
| Observação de métrica | `ai_venture_metric_observations` (`AiVentureMetricObservation`): `venture_id, metric_key, value(24,6), unit, currency, observed_at` | **MRR vive aqui** — `metric_key='mrr'`. NÃO criar tabela. |
| Comando | `app/Console/Commands/AtlasVentureFoundryCommand.php` (`match($action)`); já tem `metric-record` | Adicionar ações novas no mesmo `match`. |
| Escada + gates | `VentureGrowthLadderService` — `recordMetric()`, `latestMetrics()`, `evaluate()`; gates `arr_positive/arr_1m/...`; `METRIC_ARR_USD` | Reusar a infra de gate; **adicionar avaliador "sustentado" (histórico, não ponto-no-tempo)**. |
| Assessment | `Assessment/` (`VentureQuestionEngine/FocusDecider/AssessmentService`), `ai_venture_assessment_runs` | Base do **gate de admissão**. |
| Comprehension | `Comprehension/` (entende a empresa real, achou MRR/risco da Blackink) | Insumo do gate de admissão (geridas). |
| Ponte de execução | `VentureExecutionBridgeService` (gaps→missões DRAFT autonomy=SUGGEST, nunca auto-exec) | Evoluir para **operação real sob gates** (Fase 2). |
| Domain Company Runtimes | `atlas:ai:marketing-domain / finance-domain / ...` (cada uma empresa governada c/ gates) | Os "braços" de operação de uma venture. |
| Holding | `atlas:ai:autonomous-holding` (portfolio governor, 200+ ações enterprise-*) | Governança de portfolio + operating-backbone (sales/support/treasury/GRC). |
| NightShift | `app/Services/Ai/NightShift/` (Area Focus Loop, 24/7) | O "while you sleep"; promoção NS-v1→v2 p/ empresa externa. |
| Substrato | Loop/ACDE, AAEOS, Mission Mode, ACOS/Memory, Evidence Ledger | Reusado por toda venture; não duplicar por empresa. |

**Gap aberto (o trabalho real):** a **operação autônoma fim-a-fim** sob os gates. Hoje a ponte é suggest-only; nada opera uma venture até MRR sustentado.

---

## 4. Arquitetura da solução (como as peças compõem)

```
IDEIA / EMPRESA-A-GERIR
   │
   ▼  [Fase 1] GATE DE ADMISSÃO  ──reject/park──►  ai_venture_admission_decisions (anti-covardia)
   │   (Comprehension + Assessment + sinal-de-demanda)
   ▼  admitida → entra na COORTE
   │
   ▼  [Fase 2] OPERAÇÃO sob gates  ── compõe ──►  Domain Runtimes (mkt/fin/support/code)
   │   (trust-ladder, SANDBOX, mandato externo)        Mission Mode · Loop/AAEOS
   │   24/7 via NightShift (após promoção)
   ▼
   ▼  MRR observado real  ──►  ai_venture_metric_observations (metric_key='mrr')
   │
   ▼  [Fase 0] AVALIADOR SUSTENTADO  → not_yet | succeeded | failed | insufficient_data
   │   (≥3 obs mensais ≥ R$1k)
   ▼
   ▼  SCORECARD TRIPLO  → success_rate · admission_rate · count  (created vs managed)
       persistido como receipt no Evidence Ledger
```

---

## 5. PLANO DE IMPLEMENTAÇÃO (slices, em ordem)

> Cada slice: **flag default-OFF, byte-identical-OFF, testes frozen, reúso verificado.** Ordem importa: medição ANTES de operação (não dá pra mirar 70% sem medir honesto).

### FASE 0 — Medição (primeiro; sem isso a meta é não-mensurável)

**Slice M1 — Contrato de MRR observado**
- **O quê:** canonizar `metric_key='mrr'` (+ `currency='BRL'`, `unit='month'`) em `ai_venture_metric_observations`. Documentar as chaves canônicas (`mrr`, `arr_usd`, `active_customers`, `churn_rate`, `cac`, `ltv`).
- **Onde:** constante em `VentureGrowthLadderService` (`METRIC_MRR = 'mrr'`); doc das chaves.
- **Reúso:** tabela e `recordMetric()` já existem. Zero migration.
- **DoD:** `atlas:venture metric-record --venture=blackink --metric=mrr --value=... --currency=BRL` grava; `latestMetrics()` retorna. Teste: observação MRR persiste e lê.

**Slice M2 — Avaliador de sucesso sustentado** ⭐ (o coração novo)
- **O quê:** `VentureSuccessEvaluator` — uma venture é `succeeded` sse houver **≥3 observações mensais consecutivas de `mrr` ≥ threshold** (config `success_mrr_threshold_brl=1000`, `success_min_consecutive_months=3`). Estados: `insufficient_data` (< N obs), `not_yet`, `succeeded`, `failed` (caiu abaixo após ter atingido = churn definitivo, por política). Lê HISTÓRICO de observações (não `latestMetrics`).
- **Onde:** `app/Services/Ai/VentureFoundry/Success/VentureSuccessEvaluator.php`. Config em `config/atlas_venture_foundry.php`.
- **Reúso:** lê `ai_venture_metric_observations`. **Sem tabela nova** (estado é computado).
- **DoD:** testes cobrindo: 2 obs → insufficient; 3 obs ≥1k → succeeded; 3 obs com 1 abaixo → not_yet; queda pós-sucesso → failed. Determinístico, zero provider.

**Slice M3 — Ledger de admissão (anti-covardia)**
- **O quê:** registrar TODA ideia/empresa avaliada e a decisão (`admitted|rejected|parked`) + razão + score. Sem isso, 70% é gameável rejeitando o ambicioso.
- **Onde:** nova tabela `ai_venture_admission_decisions` (`venture_id?`, `idea_id?`, `decision`, `reason`, `assessment_run_id?`, `score`, `decided_at`, `decided_by`). Model `AiVentureAdmissionDecision`. **Verificar antes** que `ai_venture_assessment_runs` não cobre isso (não cobre: assessment ≠ decisão de admissão). Migration idempotente.
- **DoD:** decisão de admitir/rejeitar grava linha; teste de persistência + filtro por decision.

**Slice M4 — Scorecard triplo + ação de comando + receipt**
- **O quê:** `VentureSuccessScorecardService` computa, por coorte e por subtipo (created/managed): `success_rate = succeeded / (succeeded + failed + not_yet_eligible)`, `admission_rate = admitted / assessed`, `success_count` absoluto. Define claramente o denominador (o que conta como "na coorte": admitida e com marco definido).
- **Onde:** `Success/VentureSuccessScorecardService.php`; ação `success-scorecard` (+ `--json`) no comando; persistir snapshot como **receipt append-only no Evidence Ledger** (localizar o writer canônico — `AppendOnlyJsonlStore`/Decision Receipt v2 — NÃO inventar; reusar).
- **DoD:** `atlas:venture success-scorecard --json` retorna os 3 números + breakdown; snapshot vira receipt; teste com fixtures (ex.: 7/10 succeeded → 70.0%).

### FASE 1 — Gate de admissão (limpa o denominador = o mecanismo do 70%)

**Slice A1 — Decisão de admissão wired no Assessment**
- **O quê:** uma venture **criada** só entra na coorte após passar um gate de validação (Assessment focus sem risco existencial aberto + marco de sucesso declarado). Rejeição/park → ledger (M3). Geridas (Blackink) entram direto na coorte mas como sub-meta mais dura (sem filtro de admissão).
- **Onde:** `Success/VentureAdmissionGate.php`, chamado no fluxo de `promote`/criação. Flag `venture_admission_gate_enabled` default-OFF.
- **DoD:** ideia com risco existencial aberto → rejected+logged; ideia validada → admitted+marco set. Testes.

**Slice A2 — Validador de sinal de demanda (o Polsia-killer)** 
- **O quê:** antes de comprometer esforço de BUILD, exigir sinal de demanda real barato (landing + micro-ad/waitlist; threshold de CTR/sign-up configurável). Sem sinal → não constrói (mata 90% das ideias por ~$20 em vez de queimar IA construindo lixo). 
- **Onde:** `Success/DemandSignalValidator.php`. **Default-OFF, gated** (toca gasto externo → mandato). Integra com o Marketing Domain Runtime para o teste.
- **DoD:** sinal abaixo do threshold → veredito `no_demand` + venture parkada; teste mockando o sinal. Nenhum gasto real com flag OFF.

### FASE 2 — Camada de operação autônoma (fecha o gap)

**Slice O1 — Runtime de operação de venture sob gates**
- **O quê:** evoluir `VentureExecutionBridgeService` de suggest-only para **operação governada**: um loop que, para uma venture admitida, despacha trabalho aos Domain Runtimes (marketing/finance/support/code) via Mission Mode + Loop/AAEOS, sob trust-ladder + SANDBOX-equivalente + mandato p/ ações externas. Reusa os Domain Company Runtimes existentes como braços.
- **Onde:** `Success/VentureOperationRuntime.php` (orquestra; não duplica os Domain Runtimes). Flag `venture_autonomous_operation_enabled` default-OFF.
- **DoD:** com flag OFF = idêntico (só bridge-suggest). Com flag ON em sandbox: gera missões reais roteadas aos domains, ações externas bloqueadas sem mandato. Testes de roteamento + gate.

**Slice O2 — Promoção NightShift (24/7) para venture**
- **O quê:** implementar o receipt NS-v1→NS-v2 que permite night-shiftar uma venture externa (hoje inexistente — `AtlasNightShiftAreaFocusContractRegistry` só registra áreas-do-Atlas). Pré-requisito: o motor já provado em si.
- **Onde:** estender `NightShift/` com Area Contract de venture + receipt de promoção. Flag + gate de operador.
- **DoD:** Blackink registrável no NightShift só com receipt válido; sem receipt → `area_not_registered` (como hoje). Teste.

### FASE 3 — Provar na Blackink (geri­da, sub-coorte mais dura)

**Slice B1 — Onboard completo da Blackink**
- **O quê:** Comprehension (feito) → registrar MRR real (M1) → declarar marco → operar sob gates (O1) → dirigir a MRR sustentado. É o caso real sem filtro de admissão.
- **DoD:** Blackink na coorte como `managed`; MRR real observado mensalmente; scorecard a reconhece.

**Slice B2 — Feedback econômico fechado**
- **O quê:** scheduler realoca esforço por MRR/ROAS observado por venture; aposenta zumbi (não queima IA em venture morta). Conserta a unit-economics que mata a Polsia.
- **Onde:** integra o scorecard (M4) ao scheduler do Loop/NightShift. Flag default-OFF.
- **DoD:** venture com MRR estagnado N ciclos → rebaixada/parkada; venture que cresce → mais ciclos. Teste determinístico.

### FASE 4 — Escalar para ventures CRIADAS

**Slice C1 — Pipeline criar-venture fim-a-fim**
- **O quê:** ideia → gate de admissão (A1/A2) → build (AAEOS/Loop) → operação (O1) → sucesso (M2), tudo medido no scorecard.
- **DoD:** ≥1 venture criada percorre o pipeline inteiro em sandbox; scorecard reflete.

**Slice C2 — Métrica de eficiência (o "mais eficiente")**
- **O quê:** custo-por-empresa-de-sucesso (tokens/infra por venture succeeded) vs baseline Polsia. Reusa `agent_runs`/telemetria de custo.
- **DoD:** scorecard reporta custo/sucesso; comparável ao baseline documentado.

---

## 6. Sequenciamento e dependências

```
M1 → M2 → M4        (medir é pré-requisito de tudo)
M3 → M4             (admissão alimenta o scorecard triplo)
M* → A1 → A2        (gate de admissão depende de medir)
A1 → O1 → O2        (operar depende de coorte limpa)
O1 → B1 → B2        (provar na Blackink antes de criar)
B* → C1 → C2        (criar depois de provar gerir)
```
**Caminho crítico:** M1→M2→M4 destrava a medição honesta; só então faz sentido construir A/O/B/C. **Não pular para operação sem o scorecard** — seria mirar 70% sem régua.

---

## 7. Definition of Done da META

- [ ] Scorecard triplo persistido (Evidence Ledger), computado de MRR real observado, por subtipo created/managed.
- [ ] Gate de admissão ativo e logado (admission_rate reportada — sem covardia).
- [ ] Camada de operação autônoma sob gates, provada em sandbox.
- [ ] **Blackink** gerida até MRR sustentado (≥R$1k ×3 meses) — primeira venture `succeeded`.
- [ ] ≥1 venture criada percorrendo o pipeline fim-a-fim.
- [ ] **success_rate ≥ 70%** na coorte, com admission_rate e count absoluto honestos, sustentado.
- [ ] Eficiência (custo/sucesso) documentada vs Polsia.
- [ ] Todos os subsistemas byte-identical-OFF; ações externas atrás de mandato.

---

## 8. Regras para o implementador (checklist pétreo)

1. **NUNCA** marcar sucesso sem ≥3 observações mensais reais de MRR persistidas. `insufficient_data` é resposta honesta, não falha.
2. **SEMPRE** reportar o scorecard triplo junto. Taxa de sucesso isolada é proibida.
3. **REUSAR** `ai_venture_metric_observations` para MRR; tabela nova só com justificativa no PR (verificar que nada existente cobre).
4. **Flags default-OFF, byte-identical-OFF**; teste que prova OFF==atual.
5. **Ações externas** (post/spend/charge) só sob trust-ladder + mandato aprovado; nunca auto-exec (a falha nº1 da Polsia).
6. **Provar na Blackink antes de criar** nova venture (disciplina NightShift).
7. Validar via **frozen tests + a régua de re-prova** (`[[loop-proposal-adversarial-verify]]`), não auto-declaração do agente.
8. Rodar `atlas engineering knowledge sync --prune` + `index-code --prune` após docs/código.
9. Toda venture na coorte declara o **marco de sucesso na admissão** (senão não conta no denominador — e isso é auditado pela admission_rate).

---

## 9. Decisões abertas para o operador (não assumir)

- **Threshold de sucesso:** R$1.000 MRR está travado; confirmar moeda/valor por venture (Blackink fatura em BRL; venture US usaria USD?).
- **Política de "failed":** uma venture que atinge MRR sustentado e depois cai abaixo conta como `failed` (churn) ou volta a `not_yet`? (Spec assume `failed` definitivo — confirmar.)
- **Multi-tenant para terceiros:** explicitamente FORA de escopo (tese de soberania). Reabrir só por decisão.
- **Promoção NightShift de venture externa (O2):** qual o critério do receipt NS-v1→v2 (quantos ciclos provados em si primeiro?).
