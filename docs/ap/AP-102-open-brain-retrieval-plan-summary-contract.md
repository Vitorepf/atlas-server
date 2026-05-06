# AP-102 — Open Brain Retrieval Plan Summary Contract

Status: implemented-operational-summary

## Objetivo

Garantir que o plano de retrieval emitido em AP-101 nao fique enterrado dentro
do Context Pack. O Open Brain precisa expor um resumo auditavel no payload de
injecao, no audit log e no cabecalho provider-safe.

## Contrato

`AtlasOpenBrainContextInjectionService` deve projetar:

```text
summary.retrieval_plan.schema_version
summary.retrieval_plan.mode
summary.retrieval_plan.selected_source_count
summary.retrieval_plan.selected_sources
summary.retrieval_plan.required_sources
summary.retrieval_plan.provider_safe_only
summary.retrieval_plan.max_context_refs
```

O prompt provider-safe deve conter uma linha compacta:

```text
retrieval_plan: mode=<mode>; selected=<sources>; required=<required_sources>
```

## Critérios De Aceite

- [x] Open Brain summary carrega `retrieval_plan`.
- [x] `selected_sources` e `required_sources` sao listas simples, estaveis e
      auditaveis.
- [x] O cabecalho provider-safe inclui linha compacta `retrieval_plan`.
- [x] Teste cobre summary e prompt header.
- [x] Architecture validate expoe
      `ap102_open_brain_retrieval_plan_summary_contract`.
