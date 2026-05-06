# AP-140 — Ledger Replay Command Surface

## Problema

O Atlas ja tinha replay generico por envelope via `KernelLedgerEnvelopeReportService`
e `atlas:ai:ledger`, mas a operacao ainda era pouco ergonomica e nao batia com a
linguagem do contrato macro: `atlas ledger replay --envelope=<id>`.

Isso cria dois riscos:

- operador e sessoes auxiliares procuram replay por envelope e nao descobrem a
  superficie correta;
- o replay generico do Evidence Ledger fica menos visivel que read models
  especializados como SLO, Repair, Kernel Pipeline ou DecisionReceipt.

## Contrato

`atlas:ledger:replay` deve ser uma surface fina sobre o mesmo report canonico:

```bash
php artisan atlas:ledger:replay --envelope=<id> --json
```

O comando deve:

1. Exigir `--envelope`.
2. Consumir `KernelLedgerEnvelopeReportService`.
3. Preservar `--limit`, `--slo`, `--repair` e `--kernel`.
4. Retornar JSON com `status`, `envelope_id`, `event_count`, `filters` e
   `events`.
5. Entrar no `AtlasArchitectureOperationsCatalog` como `ledger_replay`.
6. Ser esperado pelo Curator em `architectureOperationsFindings()`.

## Enforcement

`AtlasLedgerReplayCommandTest` prova:

- replay por `--envelope` em JSON;
- erro `envelope_required` quando a option nao e passada.

O scanner `ap140_ledger_replay_command_surface` valida comando, catalogo,
Curator, teste e documentacao.

## Status

Implementado. AP-140 torna o replay generico do Evidence Ledger uma operacao
descobrivel da arquitetura mae, sem duplicar a logica de replay.
