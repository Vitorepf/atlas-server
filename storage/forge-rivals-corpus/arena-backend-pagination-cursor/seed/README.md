# arena-backend-pagination-cursor · seed

Estado inicial: endpoint usa paginação offset-based que duplica linhas em
insert concorrente. Cliente mobile mostra capturas duplicadas no scroll.

## Files

- `CapturesController.php` — endpoint atual com `offset/limit`.
- `PaginationCursor.php` — stub a evoluir.
- `CapturesPaginationTest.php` — test que ainda passa com 50 capturas estáticas mas falha com inserts concorrentes simulados.

## Objetivo

Substituir por cursor opaco `(created_at, id)`; sem duplicates entre páginas.
Compat legado via header `X-Pagination-Mode: offset`.
