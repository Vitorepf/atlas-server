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
| O2 | ⏳ não iniciada | — | — | — |
| O3 | ⏳ não iniciada | — | — | — |
| O4 | ⏳ não iniciada | — | — | — |
| O5 | ⏳ não iniciada | — | — | — |
| O6 | ⏳ não iniciada | — | — | — |

**Pré-campanha (09/07, obra do cockpit):** produtor+verdict+gerador+cockpit+cadência+L3 call-sites entregues (commits `0d6bdff4b6`→`c8c7222015`); medidor L3 em voo por outro worker (monitor armado). Ver `atlas-terminal-work-charter.md` §5.

## Decisões que ficam com o operador
- Ligar `enforce` da governança (GAP-GOV-01) e a cadência do publisher (`ATLAS_TERMINAL_REVIEW_PUBLISH_ENABLED=true`).
- Push dos commits da campanha.
