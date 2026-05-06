# AP-103 — Retrieval Required Source Availability Contract

Status: implemented-operational-gate

## Objetivo

Garantir que uma fonte marcada como obrigatoria pelo Retrieval Router nao seja
silenciosamente ignorada. O Atlas pode degradar fontes opcionais, mas nao deve
seguir com Open Brain `required` quando uma fonte obrigatoria, como
`evidence_replay`, nao apareceu no Context Pack.

## Contrato

Open Brain deve projetar:

```text
summary.retrieval_plan.available_sources
summary.retrieval_plan.unavailable_sources
summary.retrieval_plan.required_unavailable_sources
summary.retrieval_plan.availability.<source>.available
summary.retrieval_plan.availability.<source>.count
summary.retrieval_plan.availability.<source>.required
summary.retrieval_plan.availability.<source>.unavailable_action
```

Warnings canonicos:

```text
retrieval_source_unavailable
retrieval_required_source_unavailable
```

## Critérios De Aceite

- [x] Fontes selecionadas sao comparadas com o contexto provider-safe real.
- [x] Fonte opcional indisponivel degrada com warning.
- [x] Fonte obrigatoria indisponivel em `open_brain.mode=required` falha fechado.
- [x] `evidence_replay` considera ledger/replay refs, `previous_traces` e
      `replay_events`.
- [x] Architecture validate expoe
      `ap103_retrieval_required_source_availability_contract`.
