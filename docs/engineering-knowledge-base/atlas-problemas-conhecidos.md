---
id: atlas-problemas-conhecidos
type: engineering_knowledge
title: Atlas — Problemas Conhecidos (índice curado, ler ANTES de confiar em qualquer braço/gate)
status: active
category: strategy
priority: 100
doc_schema: atlas_canonical_module_doc.v1
graph_layer: system
summary: "ÍNDICE CURADO dos problemas ABERTOS e recém-fechados do Atlas com prova, severidade e caminho de conserto. Destaque 21/07: contrato de saída JSON³ degrada modelos que resolvem a tarefa (bfcl: kimi 30/30 cru vs 9/30 via Atlas, 7 casos falhando 21/21 DETERMINISTICAMENTE — fricção de encoding, não capacidade); governor exige autoridade de merge para escrever resposta de benchmark (30/30 blocked); hermes -z trava >30min (classe crash-before-usage) e derruba suítes via env-timeout. Complementa (não substitui) o ledger completo atlas-open-gaps-regressions-ledger.md."
tags: [atlas-ai, problemas, gap-ledger, output-contract, governor, hermes, rivals, honesty]
capabilities: [known_problems_index, honest_failure_map]
decisions:
  - Curriculum ladder / anti-ceiling fallacy is petreo (atlas-rivals-curriculum-ladder-and-anti-ceiling-fallacy).
  - Só entra problema com PROVA (evidência runtime ou commit); opinião/suspeita não entra.
  - Cada entrada carrega STATUS (ABERTO / MITIGADO / FECHADO+commit) e o caminho de conserto.
  - Este doc é o índice curado; o histórico exaustivo vive no atlas-open-gaps-regressions-ledger.md.
maintenance:
  - Atualizar quando um problema fechar (marcar FECHADO+commit, nunca apagar) ou quando prova nova mudar a severidade.
related_paths: [docs/engineering-knowledge-base/atlas-open-gaps-regressions-ledger.md, docs/rivals-warroom.md, docs/rivals-goal-mission.md]
graph_id: atlas-problemas-conhecidos
graph_title: Atlas Problemas Conhecidos
graph_world: atlas
graph_kind: runbook
graph_parent: atlas-open-gaps-regressions-ledger
graph_status: active
graph_source: repo
owner: atlas-ai
human_name: Atlas Problemas Conhecidos
canonical_name: Atlas Problemas Conhecidos
technical_name: atlas-problemas-conhecidos
---

# Atlas — Problemas Conhecidos (índice curado)

## PÉTRO — Curriculum ladder / anti-ceiling fallacy

**Canon:** `docs/engineering-knowledge-base/atlas-rivals-curriculum-ladder-and-anti-ceiling-fallacy.md`

- **80–100% num teste = aprovação de NÍVEL escolar**, nunca teto do modelo, do Atlas, nem “inteligência suprema”.
- Suite saturada → **S_sanity** (regressão/paridade). Multiplicador e claim forte → só em **S_frontier** não saturado.
- Raw ou Atlas dominou o frontier → **promover L_{k+1}** (próximo livro). **Proibido** dizer “50× / N×M impossível para sempre porque já faz 80%”.
- **2026 ≠ 2030 ≠ 2040 ≠ 2050**; o currículo **sobe**. Atlas 80% em teste medíocre = **inútil como meta final** — elevar o nível.
- Essência Rivals: medir com/sem Atlas e **sempre melhorar o Atlas no frontier**, não congelar tabuada.



Regra de leitura: tudo aqui tem PROVA (recibo, commit, medição ao vivo). Severidade: 🔴 degrada resultado/produto agora · 🟠 degrada medição/operação · 🟡 ruído/risco latente. O histórico exaustivo (100+ entradas com file:line) vive em `atlas-open-gaps-regressions-ledger.md`.

## 🔴 P1 — Contrato de saída JSON³ degrada modelos que RESOLVEM a tarefa (PARCIAL no produto; harness bfcl consertado)

**O problema:** o `atlas:cli:dev` exige que o provider devolva `patch_plan` JSON. Para tarefas cuja resposta é ela própria estruturada (chamada de ferramenta, JSON de resposta), o modelo é forçado a produzir **JSON dentro de JSON dentro de JSON** (argumentos → string JSON-escapada → array → patch_plan), enquanto no braço cru o mesmo modelo usa o canal de **function-calling nativo** que ele foi treinado para usar.

**Prova (21/07, bfcl, n=60, run 20260721_003602_d3edda73):** braço cru `kimi-k2.7-FC` = **30/30**. Braço Atlas com o mesmo kimi = **9/30**: os 3 casos cujo payload sobrevive ao encoding passam 3/3; os outros 7 casos falham **21/21 — zero variação entre repetições**. É fricção de formato pura: o modelo RESOLVE a chamada (ex.: 1.139 tokens de resposta real com a solução), o encoding mata no caminho. Escala do achado de 16/07 ("kimi resolve, Atlas Dev exige JSON e rejeita" — `invalid_provider_contract`).

**Status:** harness de medição bfcl CONSERTADO por simetria (modelo entrega args em JSON natural; harness empacota deterministicamente no formato do checker, espelho do que a API FC faz pro braço cru) — ver commit `fix(rivals): simetria de braços no bfcl`. **PARCIAL no produto (P1-JSON):** o port agora empacota um fato de function-call que um transporte realmente entregue e aceita resposta livre de alvo único sem exigir envelope JSON³; texto inutilizável continua fail-closed com razão explícita. O Hermes CLI atual, porém, não declara `atlas_apply_patch` ao provider nem devolve argumentos FC estruturados. Portanto o sufixo do modelo não é tratado como capacidade nativa, e não há alegação de que a regressão Kimi/FC esteja fechada em operação real. O conserto residual continua sendo uma rota provider-native comprovada (Decide → transporte FC → port → patch_plan), seguida de medição simétrica ao vivo.

**AAEOS ownership (2026-07-24):** este P1 é residual **R104** do programa Elite Deepening — lei da coroa no seam de resposta do provider (M não pode destruir N resolvido). A fatia **`EXECUTE P1-JSON`** prova automaticamente o empacotamento do port, a queda honesta para free-form de alvo único e a mesma cadeia de comissionamento Dev/AAEOS sem efeito de provider. Ela permanece **PARCIAL** até que um transporte selecionado prove FC nativo de ponta a ponta; não é “cosmético de CLI”, nem autorização para REAL_OPERATION.

## 🔴 P2 — Governor exige autoridade de MERGE para escrever resposta de benchmark (ABERTO, decisão de produto)

**O problema:** o fluxo mutativo do `EliteExecutorKernel` só libera o artefato com `authorized_merge_action` (corte de 22 papéis + `requires_canary_settlement: true`). Em fluxo de MEDIÇÃO (workspace descartável, arquivo de resposta), a autoridade nunca vem → `governor_authority_absent`.

**Prova (21/07):** **30/30 unidades** do braço Atlas no bfcl com `completion_state=blocked` + `governor_authority_absent` — inclusive as 9 que ACERTARAM (o patch-sink aplica o artefato mesmo bloqueado). Antes do patch-sink isso ZERAVA o braço (15/07: "2 de 195 recibos com Atlas real, ambos blocked").

**Status:** ABERTO. Mitigação viva: patch-sink no bridge. Conserto real (decisão do operador): escopo de autoridade próprio para fluxo de medição/execução efêmera — corte de merge não deve julgar workspace descartável.

## 🔴 P3 — `hermes -z` trava (hang real >30min, classe crash-before-usage) e derruba suítes (MITIGADO por watchdog)

**O problema:** chamadas oneshot do hermes ocasionalmente PENDURAM sem escrever usage-file (não é o GAP-HERMES-01 de chunk final — o trabalho não termina). A unidade espera, morre no timeout de 1800s como `environment_failure`, o processo hermes vira ÓRFÃO (o kill da unidade não mata netos), e a taxa de ambiente aborta a suíte inteira.

**Prova (21/07):** hermes de unidade cruxeval vivo **33min APÓS a battery do cruxeval morrer**; usage-file inexistente; 3 timeouts na noite (lcb 1/18, cruxeval 2/18) → 2 suítes abortadas por rate > 5%.

**Status:** MITIGADO — supervisor com watchdog mata hermes -z >12min (medianas reais 30–300s); unidade falha limpa como setup (excluída) em vez de envenenar a taxa. **ABERTO estrutural:** (a) fail-fast no HermesCliProvider + kill de árvore de processo no timeout do `rivals-engineering-unit.php`; (b) causa-raiz do hang no gateway/Verboo.

## 🔴 P4 — Batteries morrem-vivas em silêncio (MITIGADO por supervisor de sessão; watchdog permanente ausente)

**Prova:** deveval 20/07 — processo vivo 3h45 com ZERO filhos e ZERO eventos (mesma família do silent-death do testeval de 19/07; live_status mentindo "running").
**Status:** MITIGADO por supervisor de sessão (liveness por mtime de events.jsonl + stall 45min). **ABERTO:** watchdog PERMANENTE (launchd) — hoje a proteção morre com a sessão do agente.

## 🟠 P5 — long_code_arena: braço Atlas com solution.py de 0 bytes misclassificado como model_failure (ABERTO; capacidade gated)

**Prova (agente 20/07, evidência file:line):** métrica FUNCIONA (evaluate vivo com dummy → valid). O que quebra: `sandbox_apply_failed` no braço Atlas → bridge aplica 0 patches → solution vazia → `solution_or_metric_invalid` (driver:1512) rotulado `model_failure`. Família do P1/P2.
**Status:** capacidade `long_context_engineering` GATED no app (mostra razão, nunca número). Conserto: destravar o apply (P1/P2) + reclassificar essa cadeia como `candidate_preparation_blocked` + componente de precisão na métrica API_recall.

## 🟠 P6 — Gate de ambiente abortа suíte com N pequeno (ABERTO, decisão de honestidade)

**Prova:** 1 timeout em 18 unidades = 5,6% > teto de 5% → suíte inteira exit 1 (lcb e cruxeval 20-21/07), mesmo com os recibos válidos persistidos.
**Status:** com packs de 10 (60 unidades) o mesmo evento vira 1,7% e passa. Proposta pendente (mexe em gate de claim → decisão do operador): piso absoluto (ex.: abortar só com ≥3 falhas de ambiente) além do rate.

## 🟠 P7 — Duas fontes de verdade para packs de casos (CONSERTADO o sintoma; risco estrutural ABERTO)

**Prova:** battery executa `config(case_packs)`, não as fixtures importadas — bfcl fechou com 9 pares tendo 10 casos no storage (20/07).
**Status:** listas sincronizadas 16×10 (commit `3bab1f6477`). **Risco residual:** config e fixtures podem divergir de novo em silêncio. Conserto barato: guard test que compara `case_packs` ↔ `tests/Fixtures/Rivals/cases/*`.

## 🟠 P8 — Caches "first3" do driver matavam casos 3..9 (FECHADO `e387f0eaf3`)

Driver regenerava caches upstream com teto hardcoded de 3 (bigcodebench `range(3)`, repobench/locagent/LCA `len>=3`) — cache apagado silenciosamente mataria os packs de 10. Regeneração agora cobre o índice pedido (mín. 10).

## 🟡 P9 — Runs órfãos "preflighted" a cada battery (ABERTO, cosmético-com-custo)

Cada `atlas:rivals battery --suite=X` PREPARA todas as 16 suítes do perfil e executa só X → 15 runs órfãos por battery poluem `runs/` e dashboards de estado. Prova: 15 runs preflighted em 20/07. Conserto: preparar só a suíte-alvo (ou marcar órfãos como `mint_only`).

## 🟡 P10 — Medianas de wall_ms com ruído de contenção sob lanes paralelas (DECLARADO)

Rodar 4-6 batteries simultâneas (ordem do operador, 20/07) adiciona contenção às MEDIANAS de tempo da capacidade Eficiência. Score/acurácia não são afetados (braços da mesma suíte rodam na mesma lane). Tratar eficiência como direção, não valor fino, enquanto houver paralelismo.

## 🟡 P11 — Scheduler heartbeat STALE >7 dias (ABERTO, fora do Rivals)

`storage/atlas/scheduler/heartbeat.jsonl` sem batimento há 7+ dias — Autônomos possivelmente parado (launchd print-disabled / master `atlas:agents:on|off autonomos`). Hook de sessão avisa todo turno.

## 🔴 P12 — Segurança: `.env.bak` vazado no GitHub (ABERTO — rotacionar e purgar)

Memória `github-main-env-bak-secret-leak`: segredo em histórico público. Ação: rotacionar chaves + purgar histórico. Nada no repo referencia o valor novo até lá.

## 🟡 P13 — FairClaudePolicy é código morto no caminho efficient (ABERTO, limpeza)

`return` na linha ~112 antes do gate na ~142 do caminho efficient — política nunca executa (memória 15/07). Remover ou religar.

## 🟡 P14 — Suítes x86 estacionadas (POR DESIGN, não regressão)

terminal_bench, swe_bench_live, senior_swe_bench, swe_marathon, hal_harness corrompem sob emulação x86 no arm64 (até patch gold do SWE-bench falha). Fora do perfil até haver host x86 — ausência honesta, nunca número falso.

## 🔴 P15 — Braço Atlas VENDADO nas suítes nativas (FECHADO `a9bf61b7b2`; releitura histórica pendente)

**O problema:** o prompt das 13 suítes nativas mandava "ler case_material.json", mas só o braço cru pode ler (hermes agêntico com tools/`--yolo`); o braço Atlas roda oneshot com `allowed_tools=[]` — o modelo NUNCA via o problema. Escrevia stubs ou chutava clássicos (isMatch/LC10 byte-idêntico em casos diferentes = determinismo de prompt-cego), julgados contra o problema real → 0 fabricado.

**Prova (21/07):** testeval bare 10/12 score-1 vs atlas 2/11; artefatos atlas de 167-244B "sanity stub"; hash `b6a28e13d084a52f` idêntico em testeval_001 (isMatch, score 1 — o chute ERA o caso) e testeval_002 (threeSum, score 0).

**Status:** FECHADO no instrumento — material embutido no prompt COMPARTILHADO (os dois braços veem o problema; o cru mantém o harness agêntico). Pendente: releitura/denylist dos runs nativos pré-fix (mesma régua auditável do P-bfcl) após as provas das re-medições.

---

**Vereditos honestos que NÃO são bugs:** derrota medida com IC (ex.: capacidade com delta negativo significativo após P1/P2 consertados) é INFORMAÇÃO — o objetivo do Rivals é dizer a verdade, e "Atlas pior em X" com prova é o mapa de evolução do produto, não um defeito do medidor.
