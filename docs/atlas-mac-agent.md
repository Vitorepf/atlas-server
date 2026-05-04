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

## Validar

```bash
/opt/homebrew/bin/php artisan atlas:host status --json
launchctl print gui/$(id -u)/com.atlas.mac-agent
sudo launchctl print system/com.atlas.power-helper
pmset -g sched
```

Campos esperados no status:

- `status`: `online_idle`, `held_awake`, `running_jobs` ou `offline_or_sleeping`.
- `power_helper.installed`: `true`.
- `power_helper.last_success_at`: horario do ultimo check root bem-sucedido.
- `power_helper.running`: pode ser `false` entre execucoes periodicas; o helper roda por `StartInterval`.
- `wake_schedule.scheduled`: `true` quando o wake Atlas foi programado com sucesso.

Se `power_helper.needs_install=true`, o app mobile ainda consegue usar modo remoto e `caffeinate`, mas wake automatico por `pmset schedule wakeorpoweron` fica pendente.

## Uso

Modo remoto:

```bash
/opt/homebrew/bin/php artisan atlas:host hold --ttl=4h --reason="Modo remoto"
/opt/homebrew/bin/php artisan atlas:host status --json
/opt/homebrew/bin/php artisan atlas:host release --session=<id>
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
