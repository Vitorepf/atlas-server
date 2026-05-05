# Atlas CLI Bootstrap Setup

Este documento registra a configuracao operacional do Atlas CLI no Mac. O comando recomendado continua sendo um so:

```bash
./bin/atlas bootstrap --refresh-providers --strict
```

## Scheduler local

Para o P5, `atlas schedule` depende do Laravel Scheduler rodando uma vez por minuto. O bootstrap detecta isso e pode instalar a entrada gerenciada no crontab:

```bash
./bin/atlas bootstrap --install-scheduler-cron --strict
```

Entrada instalada:

```cron
* * * * * cd '/Users/vitorepf/Develop/atlas/atlas-server' && '/opt/homebrew/bin/php' artisan schedule:run >> /dev/null 2>&1
```

O bloco fica entre marcadores:

```text
# ATLAS CLI SCHEDULER START
# ATLAS CLI SCHEDULER END
```

Se preferir gerenciar manualmente, a mesma linha pode ser adicionada ao crontab. O `atlas doctor --strict` passa a reportar `scheduler_cron` como `needs_review` quando essa entrada nao existe.

## Comandos P5

```bash
atlas schedule add "titulo" --schedule="30m" --prompt="..." --skill=comunicador-claro
atlas schedule add "rotina" --schedule="every 2h" --prompt="..."
atlas schedule add "briefing" --schedule="0 9 * * 1-5" --prompt="..."
atlas schedule list
atlas schedule show <id>
atlas schedule run-now <id>
atlas schedule pause <id>
atlas schedule resume <id>
atlas schedule remove <id>
php artisan atlas:scheduler:tick --dry-run --json
```

Outputs locais ficam em:

```text
atlas-server/storage/app/atlas/scheduled/{task_id}/{timestamp}.md
```

`[SILENT]` no inicio da resposta suprime delivery futuro, mas o arquivo continua salvo para auditoria. Falhas nunca sao suprimidas.

`atlas:scheduler:tick --dry-run` e o diagnostico seguro do P5: ele mostra tarefas vencidas sem claim, sem avancar recorrencia e sem enfileirar jobs. Para validar claim real sem provider, use `php artisan atlas:scheduler:tick --no-dispatch --json`.
