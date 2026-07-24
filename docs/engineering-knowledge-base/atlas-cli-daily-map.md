---
id: atlas-cli-daily-map
type: engineering_knowledge
title: Atlas CLI Daily Map — portas diárias vs advanced
status: active
category: engineering
priority: 95
summary: "Mapa mental da superfície CLI (~926 commands). Diário = Dev/Forge/Autônomos/cockpit/brain/task/aaeos control. Maturity saiu de atlas:aaeos:* para atlas:aeos:* (TRI-HYGIENE)."
---

# Atlas CLI Daily Map

## Portas diárias (≤15)

| Intent | Command |
|--------|---------|
| Programar comigo (Dev) | `atlas:cli:dev`, harness externo, senior-loop |
| Obra longa (Forge) | `atlas:code:forge-*`, `atlas:forge:*` |
| Overnight Autônomos | `atlas:brain:next`, `atlas:brain:seed`, `atlas:task next` |
| Org plane opcional | `atlas:aaeos:run` |
| Situação / review | `atlas:cli:cockpit` |
| AAEOS saúde | `atlas:aaeos:scorecard`, `atlas:aaeos:certify` |
| Contexto IA | open-brain / `atlas:context-pack` / memory |

## AAEOS control (prefixo `atlas:aaeos:` — só control plane)

- `atlas:aaeos:run`
- `atlas:aaeos:cycle`
- `atlas:aaeos:scorecard`
- `atlas:aaeos:certify`

`scorecard` é observação de medidas: ausência de amostra fica `unknown` e não recebe nota
inventada. `certify` só sai verde quando os predicados possuem evidência medida; nunca trate
um número estático ou a palavra GOD_SOTA como prova de operação.

## Renomes TRI-HYGIENE (maturity saiu de aaeos)

| Antigo | Novo |
|--------|------|
| `atlas:aeos:maturity` | `atlas:aeos:maturity` |
| `atlas:aeos:department-status` | `atlas:aeos:department-status` |
| `atlas:aeos:department-registry` | `atlas:aeos:department-registry` |
| `atlas:aeos:choreography-status` | `atlas:aeos:choreography-status` |
| `atlas:aeos:verify-tests` | `atlas:aeos:verify-tests` |
| `atlas:learning:proposals-decision` | `atlas:learning:proposals-decision` |
| `atlas:memory:cognitive-immune-kernel` | `atlas:memory:cognitive-immune-kernel` |
| `atlas:aeos:deferred-worker` | `atlas:aeos:deferred-worker` |
| `atlas:review:codex-chain-contract` | `atlas:review:codex-chain-contract` |
| `atlas:acos:simplify-cycle` | `atlas:acos:simplify-cycle` |

## Advanced / não diário

- `atlas:aeos:observe` — god observe floors (~1800 LOC)
- `atlas:aaeos` (sem action) — thin router de help; com action encaminha para observe
- `atlas:software-company-stewardship` god CLI
- Finance/rivals/marketing suites

## Anti-confusão

- **AAEOS** = org control plane (run/cycle/certify)
- **AEOS / aeos** = maturity/gates/observe
- **Autônomos** = brain + task (não `atlas:loop`)

## Terminal Dev (2026-07-23)

Primary agent session: `atlas terminal` / `atlas:terminal` — see `atlas-terminal-dev-product.md`. `atlas dev` remains efficient oneshot. `atlas:cli:tui` is review cockpit, not the agent.
