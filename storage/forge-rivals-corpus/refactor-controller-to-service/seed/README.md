# Seed · refactor-controller-to-service

ReportController concentra HTTP + agregação + persistência. O arm precisa extrair a agregação para `ReportService` injetado, mantendo o contrato HTTP idêntico. Os testes feature do controller continuam verdes byte-a-byte (sem alteração); um novo teste unitário cobre o service.

## Arquivos
- `ReportController.php` — versão seed: 60 linhas com lógica embutida.
- `ReportControllerTest.php` — feature test cobrindo o payload de saída atual.
- `ReportService.php` — placeholder que o arm precisa popular.
- `ReportServiceTest.php` — testes unitários que o arm precisa fazer passar.

## Como o arm sabe que acertou
```
php artisan test --filter='ReportServiceTest|ReportControllerTest'
```
- Feature test: NENHUMA alteração no payload.
- Unit test: cobre 3 cenários isolados de agregação.
