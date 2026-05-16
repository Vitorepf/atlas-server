# Seed · backend-permission-policy-leak

CapturePolicy autoriza acesso cross-tenant. Operador do tenant A consegue ver captura do tenant B porque `view()` devolve `true` sempre que o usuário está autenticado, sem comparar `tenant_id`. O arm precisa adotar deny-first comparando `tenant_id` do user com `tenant_id` da captura.

## Arquivos
- `CapturePolicy.php` — bug presente: deny-first ausente.
- `CapturePolicyTest.php` — 4 cenários cobrindo same-tenant, cross-tenant, guest e null user.

## Como o arm sabe que acertou
```
php artisan test --filter='CapturePolicyTest'
```
4 testes verdes.

## Como o arm sabe que errou
- `test_denies_cross_tenant` continua falhando.
- Algum teste foi relaxado (acceptance_criteria proíbe).
