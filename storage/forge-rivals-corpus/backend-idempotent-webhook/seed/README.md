# Seed · backend-idempotent-webhook

WebhookController processa o mesmo `event_id` duas vezes em retry, duplicando capturas no banco. O arm precisa torná-lo idempotente: ignorar event_id já registrado, gravar nova entrada em `ReceivedEvent` na primeira vez, devolver 200 OK em ambas as situações (provider externo não pode receber 4xx em retry legítimo).

## Arquivos
- `WebhookController.php` — bug presente: não dedup.
- `ReceivedEvent.php` — modelo in-memory para log de events processados.
- `WebhookControllerTest.php` — 3 cenários cobrindo first hit, duplicate, mismatched payload.

## Como o arm sabe que acertou
```
php artisan test --filter='WebhookControllerTest'
```

## Como o arm sabe que errou
- `test_duplicate_event_id_is_ignored` continua falhando.
- `ReceivedEvent::log` registra o mesmo event_id mais de uma vez.
