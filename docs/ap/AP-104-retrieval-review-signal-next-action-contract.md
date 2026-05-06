# AP-104 — Retrieval Review Signal / Next Action Contract

Status: implemented-operational-review-signal

## Objetivo

Transformar indisponibilidade de retrieval em sinal de revisao e acao
operacional. Um bloqueio por `evidence_replay` ausente deve dizer ao operador e
ao Curator exatamente o que precisa ser recuperado.

## Contrato

`summary.retrieval_plan.review_signal` deve carregar:

```text
status: ok | warning | blocking
severity: none | medium | high
reason
sources
recommended_action
```

Acoes canonicas iniciais:

```text
refresh_evidence_replay_or_attach_trace_before_retry
refresh_code_intelligence_before_retry
refresh_memory_context_before_retry
degrade_graph_context_or_attach_relationship_evidence
refresh_context_sources_before_retry
```

`next_actions` deve traduzir essas acoes para instrucoes legiveis no retorno do
Open Brain.

## Critérios De Aceite

- [x] `review_signal` existe dentro de `summary.retrieval_plan`.
- [x] Fonte obrigatoria ausente gera `status=blocking`, `severity=high`.
- [x] `evidence_replay` ausente recomenda
      `refresh_evidence_replay_or_attach_trace_before_retry`.
- [x] `next_actions` inclui instrucao operacional especifica.
- [x] Architecture validate expoe
      `ap104_retrieval_review_signal_next_action_contract`.
