---
id: atlas-terminal-100-campaign-state
type: engineering_knowledge
title: Campanha Atlas Terminal 100% — spec das 6 obras + estado durável
status: active
category: strategy
priority: 95
doc_schema: atlas_canonical_module_doc.v1
graph_layer: system
summary: "Spec canônica e ESTADO DURÁVEL da campanha que leva a superfície terminal do Atlas a 100% medível: 6 obras (review semântico, contrato app-ready, ACOS/memória/contexto na superfície, compactação, ergonomia dos 3 executores, performance), cada uma com critério de PRONTO executável. Atualizar a cada slice; é a fonte de verdade se a sessão compactar."
tags: [atlas-ai, terminal, campaign, review-cockpit, app-ready, performance, work-charter]
capabilities: [terminal_campaign_state]
decisions:
  - PRONTO de obra = critério MEDÍVEL passando (rodado, nunca declarado).
  - Não tocar arquivo sujo de outro worker; design alternativo aditivo ou bloqueio registrado aqui.
maintenance:
  - Atualizar o ESTADO a cada slice landado (obra atual, slices feitos/faltando, provas, bloqueios).
related_paths:
  - docs/engineering-knowledge-base/atlas-terminal-work-charter.md
  - docs/engineering-knowledge-base/atlas-terminal-first-focus.md
  - docs/engineering-knowledge-base/atlas-open-gaps-regressions-ledger.md
graph_id: atlas-terminal-100-campaign-state
graph_title: Atlas Terminal 100 Campaign State
graph_world: atlas
graph_kind: runbook
graph_parent: atlas-terminal-work-charter
graph_status: active
graph_source: repo
owner: atlas-ai
human_name: Atlas Terminal 100 Campaign State
canonical_name: Atlas Terminal 100 Campaign State
technical_name: atlas-terminal-100-campaign-state
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/atlas-terminal-100-campaign-state.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-terminal-100-campaign-state.md
allowed_changes:
  - Atualizar estado/slices/provas/bloqueios a cada slice.
forbidden_changes:
  - Declarar obra PRONTA sem a prova do critério executável.
depends_on:
  - atlas-terminal-work-charter
flows_to:
  - atlas-terminal-first-focus
unlocks:
  - terminal-100-campaign
governs:
  - terminal-100-campaign
evidence:
  - docs/engineering-knowledge-base/atlas-open-gaps-regressions-ledger.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: medium
next_actions:
  - Executar O1 (census reuse-first primeiro).
---

# Campanha Atlas Terminal 100% — spec + estado

> **Contrato:** este doc é a spec canônica do `/goal` da campanha e o estado durável entre sessões.
> Método (toda obra): (1) census reuse-first ANTES de construir; (2) slice-por-slice com prova tripla
> (`php -l` + teste sqlite `:memory:` + rodar no vivo); (3) "construído vs provado no vivo" sempre explícito;
> (4) PRONTO só quando o critério ⟦medível⟧ passa — rodado, nunca declarado; (5) verificação adversarial
> nos slices de risco (agente tenta refutar a prova).

## AS 6 OBRAS (ordem; PRONTO entre ⟦⟧)

**O1 REVIEW SEMÂNTICO** — completar o moat: `atlas:task:review:deep` ganha modo semântico (IA lê o diff da landing com contexto AOBG/brain e produz findings com severidade/confiança, via provider governado, default-off).
⟦Rodar sobre 3 landings reais e produzir ≥1 finding semântico correto que os checks mecânicos não pegam; fail-open se provider off.⟧

**O2 CONTRATO APP-READY** — a API do app mobile/desktop: inventariar os comandos-núcleo (cockpit, inbox, review, brain, task, obra, memory, context, chat), garantir `--json` canônico com `schema_version` em TODOS; criar `atlas:api:describe --json` (catálogo machine-readable de comandos/schemas para o app descobrir a superfície).
⟦`describe` lista os núcleos com schema; cada núcleo roda `--json` válido; teste de contrato cobre.⟧

**O3 ACOS/MEMÓRIA/CONTEXTO NA SUPERFÍCIE** — auditar (census honesto) o que dessas 3 áreas está wired-sem-produtor ou sem superfície terminal utilizável; fechar os top gaps que tocam a experiência de terminal (recall/pack/score visíveis e úteis no cockpit ou comando próprio).
⟦Auditoria documentada no ledger + gaps P0/P1 de superfície fechados com prova viva.⟧

**O4 COMPACTAÇÃO/CONTEXTO DE SESSÃO** — census do estado real (`EliteCompaction*`, context pack de sessão); documentar honesto no ledger; fechar o que for terminal-relevante e fechável sem colisão.
⟦Census no ledger + fechável fechado ou justificado.⟧

**O5 ERGONOMIA DEV/FORGE/AUTÔNOMOS** — dirigir os 3 executores sem decorar 849 comandos: `atlas:cli:cockpit` com ações prontas por contexto (next-step executável em cada seção) + veredito em lote no inbox (aprovar/rejeitar N landings).
⟦Fluxo real: do cockpit ao veredito de uma landing em ≤2 comandos, provado no vivo.⟧

**O6 PERFORMANCE** — medir p50/p95 dos comandos-núcleo (harness simples reproduzível); otimizar os que passarem de 3s (lazy boot, cache de read-model) sem mudar comportamento.
⟦Tabela antes/depois commitada; núcleos ≤3s p50 ou exceção justificada.⟧

## PÉTREO (herda o charter; reforços da campanha)
- Main local; commit escopado por slice (`git add -- <arquivos do slice>`); nunca `add -A`; nunca push sem OK.
- Aditivo-only; switches novos default-OFF; testes NUNCA no pgsql vivo.
- NÃO editar `Constitution/AtlasLoopMergeActuator`; `AutonomousEvolution/` → git escopado; não tocar `.claude/hooks/atlas-session-state.sh` nem `Brain/AtlasBrainHeartbeatLedger.php`.
- **Checar `git status` do arquivo antes de CADA edição**; sujo por outro worker → design alternativo aditivo ou bloqueio registrado aqui.
- Não construir casca própria; vocabulário proibido em nomes/docs novos.
- Anti-Goodhart: critério que se revelar teatro → corrigir o critério AQUI e avisar no placar.

## ESTADO (atualizar a cada slice)

| Obra | Status | Slices feitos | Prova do critério | Bloqueios |
|---|---|---|---|---|
| O1 | ✅ **PRONTA 09/07** | `--semantic` no `atlas:task:review:deep` (service `withSemantic` + parser markers `[[FINDING]]`/`[[NO_FINDINGS]]` + fallback-chain de provider + advisory-ceiling: IA nunca eleva pra blocking) | ⟦3 landings reais rodadas: op-01kwvxjz ok/0, op-01kwvx937 ok/**4 findings** (p1 rejected-count confirmado por leitura de código — mecânico não pega), op-01kwvxpw ok/0; fail-open coberto por teste (provider_unavailable)⟧ 8/8 testes | Descoberto GAP-HERMES-01 (transport perde chunk final do stdout — afeta o cérebro writer também); workaround markers-first+padding |
| O2 | ✅ **PRONTA 09/07** | `atlas:api:describe` (catálogo 12 áreas de produto: comando, invocação JSON, version_key, conformance) + modo `--check` = medidor vivo permanente do contrato (roda cada núcleo, valida JSON+version key; exit≠0 se falha) | ⟦describe lista 12 núcleos com schema ✅; check VIVO 8/8 conformes; review_deep/review_publish/context provados manualmente com --json válido; chat = única exceção interactive-only DECLARADA (app usa gateway HTTP)⟧ teste de contrato 2/2 (121 assertions) | version keys heterogêneas (`schema_version` vs `schema`) catalogadas honestamente em vez de reescrever comandos alheios |
| O3 | ✅ **PRONTA 09/07** (com 1 bloqueio registrado) | auditoria 3-áreas rodando comandos reais (ledger §E2) + 4 fechamentos: alias `atlas:memory:search`, `--peek` no recall (consulta sem efeito colateral), gauge `reflection_enabled` no brain:metrics (honestidade do flatline), `--dry-run` no aobg activate/onboard | ⟦auditoria no ledger ✅; os 4 fechamentos provados no vivo (peek usage=0; search alias resolve; gauge=0 visível; dry-run devolve status sem escrever)⟧ | P0-contexto (pack sem memória/docs) BLOQUEADO: fix mora nos arquivos do worker que unifica retrieval — re-medir caso-farol quando landar; P1-B frontier = gap de PRODUTOR (não fabricar eventos); flatline real do metrics = GAP-06 switch (decisão do operador) |
| O4 | ✅ **PRONTA 09/07** | census rodado e documentado (ledger §E3): compactação VIVA (status --json ok, loop-família=0 na superfície, dry-run everywhere); hooks de sessão funcionando | ⟦census no ledger ✅ + fechável fechado-ou-justificado ✅: zero gap de superfície em arquivo limpo; pendências (inventory, echo write-back) em obra alheia in-flight⟧ | re-verificar echo/write-back quando o worker do retrieval landar |
| O5 | ✅ **PRONTA 09/07** | `atlas:task:review:decide {targets*}` — veredito em LOTE (por sha, task_packet_id ou item id; `--reject`, `--reason`, `--dry-run`, `--json`) roteando pelo MESMO InboxActionRegistry (audit+ledger inclusos, zero autoridade nova) + next-step EXECUTÁVEL por estado no cockpit | ⟦fluxo ≤2 comandos provado: cockpit VIVO imprime `atlas:task:review:decide ...` como próximo passo → decide em lote; dry-run VIVO em 2 landings reais; veredito real (approve+reject+revert_command+idempotência) coberto em teste 4/4⟧ | veredito real nas 3 landings reais pendentes é DECISÃO DO OPERADOR (ferramenta pronta; não decidi por você) |
| O6 | ✅ **PRONTA 09/07** | `atlas:api:perf` — harness reproduzível (processo fresco por run, mede o MESMO catálogo do describe; exit≠0 se p50>3s) | ⟦tabela oficial 5 runs (09/07): cockpit 836/928 · inbox 295/355 · brain 249/275 · task_health 612/634 · autonomy 279/305 · obra 267/286 · memory 281/299 · dashboard 642/664 (p50/p95 ms) — **8/8 ≤3s p50 já na baseline**; "depois" = "antes" porque otimizar dentro do orçamento seria refactor-proxy (anti-Goodhart)⟧ | prova do harness = a própria medição viva (teste sqlite de um medidor-de-processos não prova nada útil — exceção registrada); re-rodar `atlas:api:perf` a qualquer momento pra re-medir |

**Pré-campanha (09/07, obra do cockpit):** produtor+verdict+gerador+cockpit+cadência+L3 call-sites entregues (commits `0d6bdff4b6`→`c8c7222015`); medidor L3 em voo por outro worker (monitor armado). Ver `atlas-terminal-work-charter.md` §5.

## Decisões que ficam com o operador
- ~~Ligar `enforce` + cadência do publisher~~ ✅ LIGADOS 09/07 à noite (autorizado; ver ledger "SWITCHES LIGADOS").
- Push dos commits da campanha.
- **Promover o retrieval unificado** (destrave do P0 do pack; gates já passam): `atlas:intelligence:rollout-promote unified_retrieval --to=shadow --apply` → observar → canary → default. Idem `fusion`.
- `hermes update` (218 commits atrás) e re-testar qwen3.6-27b.
- Veredito das landings reais pendentes: `atlas:task:review:decide <sha> [--reject]`.

## Re-medições pós-worker (09/07 noite)
- L3 ponta-a-ponta ✅: `bypass=0` provado no coverage summary (medidor do worker + call-sites da campanha).
- Farol do pack: `memory=0` esperado (retrieval default offline); promover = alavanca acima.
