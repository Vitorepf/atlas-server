# AP-110 — Schedule Replay Inbox Refs Contract

Status: implemented-replay-read-model

## Objetivo

Fazer o replay de Self-Improvement expor as propostas emitidas por run sem
obrigar CLI, API, App ou Curator a parsearem eventos brutos.

## Contrato

`selfImprovementScheduleReportForWindow()` deve combinar:

```text
SELF_IMPROVEMENT_SCHEDULE_OBSERVED
OPERATION_COMPLETED where emitter_stage = atlas.self_improvement
```

por `envelope_id`, e expor:

```text
completed_count
emitted_count
emitted_inbox_item_ids
recent_events[].completed
recent_events[].finding_count
recent_events[].emitted_count
recent_events[].emitted_inbox_item_ids
```

## Regra De Arquitetura

O read model pode enriquecer a visao por `envelope_id`, mas nao pode recalcular
findings, dedupe ou policy. Ele apenas projeta eventos append-only ja emitidos
pelo runtime.

## Critérios De Aceite

- [x] Schedule replay une schedule observation e completion por envelope.
- [x] Summary expoe `completed_count`.
- [x] Summary expoe `emitted_inbox_item_ids`.
- [x] `recent_events[]` expoe refs de Inbox por run.
- [x] Architecture validate expoe
      `ap110_schedule_replay_inbox_refs_contract`.
