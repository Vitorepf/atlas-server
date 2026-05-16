# Seed · backend-cache-invalidation

SettingsRepository devolve cache stale após update porque a invalidação nunca acontece no write. O arm precisa adicionar invalidação determinística (write-through ou explicit invalidate) sem depender de TTL.

## Arquivos
- `SettingsRepository.php` — bug presente: write não invalida cache.
- `SettingsRepositoryTest.php` — 3 cenários cobrindo cache hit, write-then-read, invalidate-determinism.

## Como o arm sabe que acertou
```
php artisan test --filter='SettingsRepositoryTest'
```

## Como o arm sabe que errou
- `test_update_invalidates_cache` continua falhando.
- Solução depende de TTL (acceptance proíbe).
