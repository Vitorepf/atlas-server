# Seed · integration-fake-provider-timeout-retry

FakeProviderClient não tem timeout, não tem retry com upper bound, e pendura chamadas indefinidamente em provider lento. O arm precisa:

1. Adicionar timeout configurável (parâmetro do construtor).
2. Implementar retry com backoff exponencial e upper bound de tentativas.
3. Após hard timeout, devolver um blocker explícito (status='blocked', reason='timeout') em vez de exception silenciosa ou loop infinito.

A simulação NÃO chama provider real: FakeProvider é em-memória e usa um clock injetável para simular latência sem `sleep` real.

## Arquivos
- `FakeProviderClient.php` — versão seed: sem timeout, sem retry.
- `FakeProviderClientTest.php` — 3 cenários: success first try, retries-then-blocker, hard-timeout.

## Como o arm sabe que acertou
```
php artisan test --filter='FakeProviderClientTest'
```
