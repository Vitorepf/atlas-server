> Cleanup status: superseded_source_material.
> Canonical replacement: docs/engineering-knowledge-base/atlas-local-agent-surface.md; docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md; docs/engineering-knowledge-base/atlas-ai-operating-system.md.
> Cleanup note: Operational Mac Agent runbook remains useful; architecture authority now lives in the local agent surface doc.

# Atlas Mac Agent

O Atlas Mac Agent gerencia energia local do Mac para dois cenarios:

- modo remoto manual pelo app mobile;
- janela de manutencao automatica para jobs/refatoracoes.

## Componentes

- `com.atlas.mac-agent`: LaunchAgent do usuario. Mantem heartbeat, expira sessoes, cria sessoes de manutencao e segura o Mac acordado com `caffeinate`.
- `com.atlas.power-helper`: LaunchDaemon root. Necessario para `pmset schedule wakeorpoweron`, porque o macOS exige privilegio root para programar wake.

## Instalar

```bash
cd /Users/vitorepf/Develop/atlas/atlas-server
bash scripts/install-mac-agent-launch-agent.sh
bash scripts/install-power-helper-launch-daemon.sh
```

O segundo comando pede senha de administrador do macOS.
Para remover somente o helper root:

```bash
cd /Users/vitorepf/Develop/atlas/atlas-server
bash scripts/uninstall-power-helper-launch-daemon.sh
```

## Validar

```bash
/opt/homebrew/bin/php artisan atlas:host status --json
/opt/homebrew/bin/php artisan atlas:host bootstrap --dry-run --json
/opt/homebrew/bin/php artisan atlas:host bootstrap --json
launchctl print gui/$(id -u)/com.atlas.mac-agent
sudo launchctl print system/com.atlas.power-helper
pmset -g sched
```

Campos esperados no status:

- `status`: `online_idle`, `held_awake`, `running_jobs` ou `offline_or_sleeping`.
- `mac_agent.ready`: `true` quando o LaunchAgent `com.atlas.mac-agent` existe e esta carregado no dominio do usuario.
- `mac_agent.next_action`: comando para instalar/recarregar o LaunchAgent quando ele nao esta pronto.
- `power_helper.installed`: `true`.
- `power_helper.ready`: `true` quando o LaunchDaemon existe, esta carregado e ja registrou check root.
- `power_helper.next_action`: proxima acao operacional quando o helper ainda nao esta pronto.
- `power_helper.last_success_at`: horario do ultimo check root bem-sucedido.
- `power_helper.running`: pode ser `false` entre execucoes periodicas; o helper roda por `StartInterval`.
- `caffeinate_runtime.orphan_count`: quantidade de jobs `com.atlas.caffeinate.*` no `launchctl` sem sessao ativa no banco.
- `wake_schedule.scheduled`: `true` quando o wake Atlas foi programado com sucesso.
- `wake_schedule.system_has_wakeorpoweron`: `true` quando o macOS possui algum wake do sistema, mesmo que nao seja do Atlas.
- `wake_schedule.atlas_confirmed`: fonte canonica para saber se o wake do Atlas esta pronto; `scheduled` espelha esse campo.
- `readiness.ready_for_remote`: o app pode manter o Mac acordado sob demanda.
- `readiness.ready_for_scheduled_wake`: o Mac consegue acordar por janela Atlas confirmada.
- `readiness.ready_for_background_jobs`: remoto + wake programado estao prontos para jobs autonomos de madrugada.
- `readiness.power_ready_for_background_jobs`: politica de energia para jobs autonomos; bloqueia bateria baixa sem bloquear modo remoto manual.
- `readiness.on_ac_power` / `readiness.battery_percent`: snapshot de energia usado pela politica de prontidao.
- `readiness.blockers`: lista acionavel dos bloqueios restantes.

Se `power_helper.needs_install=true`, o app mobile ainda consegue usar modo remoto e `caffeinate`, mas wake automatico por `pmset schedule wakeorpoweron` fica pendente. Use `power_helper.install_command` para ver o comando completo de instalacao no Mac.

Jobs agendados pelo Atlas respeitam `readiness.ready_for_background_jobs` no `AiWorker` antes do claim. Quando esse gate falha, o job permanece `queued`, nao consome tentativa, recebe `metadata.mac_background_readiness` com blockers/warnings do Mac Agent e ganha novo `available_at` alguns minutos a frente.

`atlas:host bootstrap --json` e idempotente: instala/recarrega o LaunchAgent do usuario quando necessario, cria/atualiza a janela de manutencao padrao e devolve `next_actions` para o que ainda falta. Por padrao ele nao tenta instalar o LaunchDaemon root; para isso rode manualmente `bash scripts/install-power-helper-launch-daemon.sh` ou use `--install-power-helper` em um terminal onde voce possa digitar a senha de administrador.

## Uso

Modo remoto:

```bash
/opt/homebrew/bin/php artisan atlas:host hold --ttl=4h --reason="Modo remoto"
/opt/homebrew/bin/php artisan atlas:host status --json
/opt/homebrew/bin/php artisan atlas:host release --session=<id>
```

As sessoes de energia usam jobs `launchctl submit` por sessao, com label `com.atlas.caffeinate.<uuid>` e `/usr/bin/caffeinate -dims`. O agente reconcilia sessoes ativas; se o PID morrer, ele recria a retencao e registra `power_session_caffeinate_restarted`.

Limpeza de retencoes orfas:

```bash
/opt/homebrew/bin/php artisan atlas:host cleanup-caffeinate --json
```

O loop `atlas:host-agent:work` tambem executa essa limpeza em cada ciclo. O app mobile expõe a mesma acao quando `caffeinate_runtime.orphan_count > 0`, via `POST /v1/mobile/mac/caffeinate/cleanup`. Isso evita que um job antigo do `launchctl` mantenha o Mac acordado depois de reloads, testes ou quedas.

O app mobile tambem expõe `Preparar Mac`, via `POST /v1/mobile/mac/bootstrap`. Essa acao segura limpa retencoes orfas, cria/atualiza a janela `Janela Atlas` e retorna `next_actions`; ela nao tenta instalar o LaunchDaemon root porque isso exige senha de administrador no Mac.

Remover somente o LaunchAgent do usuario:

```bash
cd /Users/vitorepf/Develop/atlas/atlas-server
bash scripts/uninstall-mac-agent-launch-agent.sh
```

Janela de manutencao:

```bash
/opt/homebrew/bin/php artisan atlas:host schedule-wake --name="Manutencao Atlas" --wake-time=02:00 --duration=120 --timezone=America/Sao_Paulo
```

## Politica de repouso

Ao terminar uma janela de manutencao, o agente so pede repouso se:

- nao houver job de IA em `processing`;
- nao houver sessao de energia ativa;
- o usuario estiver idle por pelo menos 900 segundos.

Isso evita derrubar trabalho ativo do operador ou provider.
