# Seed · performance-n-plus-one-query

PostListService faz N+1: carrega o author dentro do loop sobre os posts. O arm precisa eager-loadar authors antes do loop para descer de N+1 para ≤ 2 queries totais, sem mudar o payload JSON de saída.

## Arquivos
- `PostListService.php` — bug presente (N+1).
- `PostListServiceTest.php` — 2 cenários: payload byte-a-byte + assertion de query count.
- `FakeQueryRunner.php` — DB in-memory que conta queries para o teste.

## Como o arm sabe que acertou
```
php artisan test --filter='PostListServiceTest'
```
- Payload do `test_summary_returns_expected_payload` continua idêntico.
- Query counter cai para ≤ 2.

## Como o arm sabe que errou
- Query count > 2.
- Payload do summary mudou.
