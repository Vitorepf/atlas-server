# 03 — Configuração e Lifecycle do Host Daemon

> **Propósito:** Manual operacional do Atlas Host Daemon no macOS. Cobre instalação inicial, plist do LaunchAgent, configuração YAML, comandos `launchctl`, hot reload via SIGHUP, lifecycle completo (boot → operação → sleep → wake), upgrade, uninstall e troubleshooting.
> **Pré-requisitos:** macOS 13+ (Ventura ou superior, Apple Silicon ou Intel), conta de usuário com privilégio `sudo` no install (não em runtime), binário `atlas-host-daemon` compilado conforme [02-arquitetura-comportamento.md](02-arquitetura-comportamento.md), Atlas backend rodando em `/tmp/atlas-host.sock`.
> **Fora do escopo:** Implementação Swift do daemon (ver `01-visao-geral.md`), protocolo de mensagens via socket Unix (ver `04-protocolo-socket.md`), políticas de wake lock e razões (ver `02-arquitetura-comportamento.md`), integração com Evidence Ledger (ver `../05-evidence-integration.md`).

---

## 1. Visão geral do lifecycle

O daemon vive em três tempos: **install** (uma vez, com `sudo`), **boot** (a cada login do usuário, automático via `launchd`) e **runtime** (contínuo, supervisionado por `KeepAlive`). O fluxo abaixo cobre o caminho desde o checkout do repositório até a primeira mensagem trocada com Atlas backend.

```
┌─────────────────────────────────────────────────────────────────┐
│  INSTALL (uma vez, manual)                                      │
│  ─────────────────────────────────────────                      │
│  scripts/install.sh                                             │
│    ├─ sudo cp binário → /usr/local/bin/                         │
│    ├─ mkdir ~/.config/atlas-host-daemon/                        │
│    ├─ cp default.yaml → ~/.config/.../config.yaml               │
│    ├─ sudo pmset repeat wakeorpoweron MTWRF 08:45:00            │
│    ├─ sudo pmset -c displaysleep 15 sleep 0                     │
│    ├─ cp plist → ~/Library/LaunchAgents/                        │
│    └─ launchctl bootstrap gui/$(id -u) <plist>                  │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  BOOT (a cada login)                                            │
│  ─────────────────────────────────────────                      │
│  launchd lê plist ──► spawn /usr/local/bin/atlas-host-daemon    │
│                          │                                      │
│                          ├─ lê config.yaml                      │
│                          ├─ abre socket Atlas (retry backoff)   │
│                          ├─ registra NSWorkspace observers      │
│                          ├─ instala SIGHUP handler              │
│                          └─ entra no run loop                   │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  RUNTIME (contínuo)                                             │
│  ─────────────────────────────────────────                      │
│  ┌───────────────────────────────────────────┐                  │
│  │ AWAKE_UNLOCKED ◄──► AWAKE_LOCKED          │                  │
│  │       ▲                  │                │                  │
│  │       │                  ▼                │                  │
│  │  MAC_WAKING       SLEEP_PREP              │                  │
│  │       ▲                  │                │                  │
│  │       └── MAC_SLEEPING ◄─┘                │                  │
│  └───────────────────────────────────────────┘                  │
│  KeepAlive: crash → throttle 10s → restart                      │
└─────────────────────────────────────────────────────────────────┘
```

A separação entre install e boot é intencional: tudo que exige `sudo` (binário em `/usr/local/bin`, `pmset repeat`) acontece na fase de install; o boot é estritamente user-level.

---

## 2. LaunchAgent vs LaunchDaemon — escolha

O macOS oferece duas formas de daemonizar processos via `launchd`. A escolha define escopo, permissões, momento de carga e superfície de ataque.

| Aspecto | LaunchAgent | LaunchDaemon |
|---|---|---|
| Escopo | Por usuário (sessão GUI) | Sistema inteiro (root) |
| Path do plist | `~/Library/LaunchAgents/` | `/Library/LaunchDaemons/` |
| UID do processo | UID do usuário logado | `root` (ou `UserName`) |
| Necessita `sudo` para load | Não | Sim |
| Carrega quando | Login do usuário | Boot do Mac (antes do login) |
| Acesso a `NSWorkspace` | Sim | Não (sem sessão GUI) |
| Acesso a `IOPMAssertion` user-level | Sim | Sim (mas escopo diferente) |
| Roda quando ninguém está logado | Não | Sim |
| Superfície de ataque | Baixa (usuário) | Alta (root) |
| Bug em runtime derruba o sistema | Não (mata sessão usuário) | Sim (root crash) |

**Decisão: LaunchAgent.** Razões:

1. O daemon precisa apenas de privilégios user-level: `IOPMAssertion` para o usuário logado, socket Unix em `/tmp` (com auth secret), eventos `NSWorkspace` (que exigem sessão GUI ativa).
2. Atlas Embodiment foi desenhado para o ciclo de vida do usuário (presença, rituais, conversas) — não faz sentido manter o daemon ativo quando ninguém está logado.
3. Menos superfície de ataque: um bug no parser YAML não escala para root.
4. Menos fricção de install: `sudo` apenas no momento de copiar o binário e configurar `pmset`.
5. `pmset repeat wakeorpoweron` é configurado uma vez no install — não exige daemon root para reaplicar.

LaunchDaemon seria justificável se o daemon precisasse acordar o Mac ANTES do login (caso headless), o que não é o cenário do MacBook pessoal.

---

## 3. Plist do LaunchAgent — `com.atlas.host-daemon.plist`

O plist é o contrato entre o daemon e `launchd`. Substitua `USER` pelo username real (o `install.sh` faz isso automaticamente via `sed`).

`~/Library/LaunchAgents/com.atlas.host-daemon.plist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key>
  <string>com.atlas.host-daemon</string>

  <key>ProgramArguments</key>
  <array>
    <string>/usr/local/bin/atlas-host-daemon</string>
    <string>--config</string>
    <string>/Users/USER/.config/atlas-host-daemon/config.yaml</string>
  </array>

  <key>KeepAlive</key>
  <dict>
    <key>SuccessfulExit</key>
    <false/>
    <key>Crashed</key>
    <true/>
  </dict>

  <key>RunAtLoad</key>
  <true/>

  <key>ThrottleInterval</key>
  <integer>10</integer>

  <key>StandardOutPath</key>
  <string>/Users/USER/Library/Logs/atlas-host-daemon.out</string>

  <key>StandardErrorPath</key>
  <string>/Users/USER/Library/Logs/atlas-host-daemon.err</string>

  <key>ProcessType</key>
  <string>Background</string>

  <key>EnvironmentVariables</key>
  <dict>
    <key>ATLAS_LOG_LEVEL</key>
    <string>info</string>
  </dict>
</dict>
</plist>
```

### 3.1 Cada chave, em detalhe

| Chave | Função | Por que esse valor |
|---|---|---|
| `Label` | Identificador único reverse-DNS | `com.atlas.host-daemon` é o handle usado em todos os comandos `launchctl` |
| `ProgramArguments` | Argv passado ao binário | Sempre absoluto; `--config` aponta para o YAML do usuário |
| `KeepAlive.SuccessfulExit=false` | Não reinicia se exit code 0 | Permite shutdown limpo via SIGTERM |
| `KeepAlive.Crashed=true` | Reinicia em crash (signal abort, segfault) | Auto-recovery sem intervenção |
| `RunAtLoad` | Inicia imediatamente quando carregado | Garante daemon ativo ao login |
| `ThrottleInterval=10` | Mínimo de 10s entre tentativas de spawn | Evita restart loop ao consumir CPU 100% se daemon crashar imediatamente |
| `StandardOutPath` / `StandardErrorPath` | Redirecionamento stdout/stderr | Logs persistem em `~/Library/Logs/` mesmo após crash |
| `ProcessType=Background` | Hint para o scheduler | Em Apple Silicon, indica ao kernel que o processo pode ser agendado em E-cores; reduz consumo de bateria |
| `EnvironmentVariables.ATLAS_LOG_LEVEL` | Override de log level via env | Permite ajuste sem editar config (útil em debug agudo) |

`ProcessType=Background` é especialmente importante em M1/M2/M3: sem essa hint, o daemon pode ser agendado em P-cores desnecessariamente, drenando bateria. Outras opções (`Standard`, `Adaptive`, `Interactive`) têm semânticas diferentes — `Background` é o correto para um supervisor de wake lock que gasta a maior parte do tempo dormindo no socket.

**Não use `KeepAlive=true` (boolean).** A forma `dict` é granular e permite distinguir exit limpo de crash. `KeepAlive=true` reiniciaria mesmo em SIGTERM voluntário, impedindo shutdown.

---

## 4. `launchctl` — comandos relevantes

`launchctl` migrou para uma sintaxe moderna em macOS 10.10+ (`bootstrap`/`bootout`/`kickstart`). A sintaxe legada (`load`/`unload`) ainda funciona mas está deprecated; use a moderna em scripts novos.

| Comando | Forma moderna | Forma legada | Quando usar |
|---|---|---|---|
| Carregar plist | `launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/com.atlas.host-daemon.plist` | `launchctl load ~/Library/LaunchAgents/com.atlas.host-daemon.plist` | Install inicial; após copiar plist novo |
| Descarregar plist | `launchctl bootout gui/$(id -u)/com.atlas.host-daemon` | `launchctl unload ~/Library/LaunchAgents/com.atlas.host-daemon.plist` | Uninstall; antes de substituir plist |
| Restart forçado | `launchctl kickstart -k gui/$(id -u)/com.atlas.host-daemon` | (não há equivalente direto) | Pegar binário novo; reset de estado |
| Status detalhado | `launchctl print gui/$(id -u)/com.atlas.host-daemon` | `launchctl list com.atlas.host-daemon` | Debug; verificar último exit code |
| Listar todos os agents | `launchctl print gui/$(id -u)` | `launchctl list` | Auditoria geral |
| Habilitar/desabilitar | `launchctl enable gui/$(id -u)/com.atlas.host-daemon` | (n/a) | Reverter um `disable` |
| Disable permanente | `launchctl disable gui/$(id -u)/com.atlas.host-daemon` | (n/a) | Pausar sem desinstalar |

### 4.1 Convenção do domain

`gui/$(id -u)` é o domain do usuário em sessão GUI. Para usuários em SSH headless usaria-se `user/$(id -u)`. O Embodiment exige sessão GUI (NSWorkspace events), então sempre `gui/`.

### 4.2 Output útil de `launchctl print`

```
$ launchctl print gui/501/com.atlas.host-daemon
com.atlas.host-daemon = {
  active count = 1
  path = /Users/vitorepf/Library/LaunchAgents/com.atlas.host-daemon.plist
  state = running
  pid = 4821
  exit code = 0
  last exit reason = none
  ...
}
```

Os campos críticos para debug são `state` (deve ser `running`), `last exit reason` (se diferente de `none`, daemon está em loop de crash) e `pid` (deve mudar após `kickstart -k`).

---

## 5. Setup script — instalação inicial

`scripts/install.sh` é executado uma única vez por máquina. Idempotente: rodar de novo não quebra nada (preserva `config.yaml` editado pelo usuário).

```bash
#!/bin/bash
set -euo pipefail

# 1. Instalar binário
sudo cp -v build/atlas-host-daemon /usr/local/bin/
sudo chmod 755 /usr/local/bin/atlas-host-daemon

# 2. Diretório de config
mkdir -p ~/.config/atlas-host-daemon/
if [ ! -f ~/.config/atlas-host-daemon/config.yaml ]; then
  cp config/default.yaml ~/.config/atlas-host-daemon/config.yaml
fi

# 3. Diretório de logs
mkdir -p ~/Library/Logs/

# 4. Configurar pmset (uma vez)
sudo pmset repeat wakeorpoweron MTWRF 08:45:00

# 5. Ajustar sleep settings (Mac plugado vs bateria)
sudo pmset -c displaysleep 15 sleep 0
sudo pmset -b displaysleep 5 sleep 30
sudo pmset -a hibernatemode 3

# 6. Instalar LaunchAgent (com substituição de USER no plist)
sed "s|/Users/USER/|$HOME/|g" packaging/com.atlas.host-daemon.plist \
  > ~/Library/LaunchAgents/com.atlas.host-daemon.plist
launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/com.atlas.host-daemon.plist

# 7. Verificação
sleep 2
launchctl print gui/$(id -u)/com.atlas.host-daemon | head -20
echo "Setup concluído."
```

### 5.1 Cada passo

| # | Passo | Por que |
|---|---|---|
| 1 | `sudo cp` binário para `/usr/local/bin/` | Path canônico para binários locais; já está no `$PATH` padrão; permissões 755 (executável por todos, escrita só root) |
| 2 | Copia `default.yaml` se ausente | Permite reinstalar sem sobrescrever config customizada |
| 3 | `mkdir -p Logs/` | Garante que `StandardOutPath`/`StandardErrorPath` do plist consigam escrever |
| 4 | `pmset repeat wakeorpoweron MTWRF 08:45:00` | Mac acorda automaticamente às 08:45 dias úteis (segunda a sexta) — base para o ritual matinal |
| 5 | `pmset -c` (cabo) e `-b` (bateria) | Diferenciar comportamento: plugado nunca dorme; bateria dorme após 30min |
| 5 | `pmset -a hibernatemode 3` | Safe sleep — RAM mantida + dump em disco. Mais rápido que hibernação pura, mais seguro que sleep puro contra perda de bateria |
| 6 | `sed` para substituir `USER` | Plist é template; `$HOME` é resolvido no install |
| 6 | `launchctl bootstrap` | Carga moderna do plist |
| 7 | `launchctl print` | Confirma que daemon está `state = running` |

**`sudo` é necessário apenas em (1), (4) e (5).** Tudo mais é user-level. Em particular, `launchctl bootstrap gui/$(id -u)` NÃO precisa de `sudo` — é o domain do usuário corrente.

### 5.2 `pmset` — settings explicados

| Setting | `-c` (cabo) | `-b` (bateria) | `-a` (ambos) | Razão |
|---|---|---|---|---|
| `displaysleep` | 15 min | 5 min | — | Tela dorme rápido na bateria; lock de wake protege CPU mas display pode descansar |
| `sleep` | 0 (nunca) | 30 min | — | Plugado = sempre disponível para Atlas; bateria = preserva carga |
| `hibernatemode` | — | — | 3 | Safe sleep universal |
| `repeat wakeorpoweron MTWRF 08:45:00` | — | — | — | Wake automático para ritual matinal |

`hibernatemode 3` é o default da Apple para MacBooks modernos; documentado aqui para tornar a decisão explícita.

---

## 6. Config YAML completo

`~/.config/atlas-host-daemon/config.yaml` é o único ponto de configuração runtime. Todo comportamento dinâmico passa por aqui.

```yaml
host_daemon:
  # Conexão com Atlas backend
  atlas_socket: /tmp/atlas-host.sock
  reconnect_initial_backoff_ms: 1000
  reconnect_max_backoff_ms: 30000
  auth_secret_path: ~/.config/atlas-host-daemon/socket.secret

  # Wake lock policy
  presence_grace_min: 10
  pipeline_grace_sec: 60
  stream_grace_sec: 30
  ritual_lookahead_min: 15
  recent_interaction_window_min: 30

  # Battery awareness (laptops em viagem)
  battery_aware:
    enabled: true
    minimum_pct_for_lock: 30
    on_battery_grace_min: 2
    respect_low_power_mode: true

  # Fallback sem StackChan
  fallback_to_local_activity:
    enabled: true
    idle_threshold_min: 5

  # pmset awareness (read-only em runtime)
  pmset_expected:
    morning_wake_time: "08:45"
    weekdays: [mon, tue, wed, thu, fri]
    hibernatemode: 3
    displaysleep_ac_min: 15
    displaysleep_battery_min: 5
    check_drift_at_boot: true

  # Logging
  log_level: info
  log_to_os_log: true

  # Audit
  emit_evidence_events: true
  evidence_sample_rate:
    wake_lock_acquired: 1.0
    wake_lock_released: 1.0
    reason_added: 0.5
    reason_expired: 0.5
```

### 6.1 Conexão com Atlas backend

| Chave | Tipo | Descrição |
|---|---|---|
| `atlas_socket` | path | Socket Unix onde Atlas backend escuta. Daemon é o cliente. **Exige restart para alterar.** |
| `reconnect_initial_backoff_ms` | int | Delay da primeira tentativa de reconexão após queda |
| `reconnect_max_backoff_ms` | int | Teto do backoff exponencial (×2 a cada falha, capped) |
| `auth_secret_path` | path | Arquivo com shared secret para handshake HMAC. Ler em `02-arquitetura-comportamento.md` §3 |

### 6.2 Wake lock policy

| Chave | Unidade | Significado |
|---|---|---|
| `presence_grace_min` | minutos | Quanto tempo manter `presence_active` ativo após último sinal antes de expirar |
| `pipeline_grace_sec` | segundos | Cauda do `pipeline_active` após pipeline encerrar (margem para follow-up) |
| `stream_grace_sec` | segundos | Cauda do `stream_open` após stream fechar |
| `ritual_lookahead_min` | minutos | Antecedência para criar lock antes de um ritual agendado |
| `recent_interaction_window_min` | minutos | Janela de "ainda quente" após última interação manual |

Esses valores são reloadable via SIGHUP — ver §8.

### 6.3 Battery awareness

| Chave | Default | Comportamento |
|---|---|---|
| `enabled` | `true` | Liga heurísticas específicas para bateria |
| `minimum_pct_for_lock` | 30 | Abaixo de 30% e na bateria, NÃO cria wake lock |
| `on_battery_grace_min` | 2 | Reduz todas as graces para 2min quando na bateria |
| `respect_low_power_mode` | `true` | Em Low Power Mode, daemon recusa todos os locks (degradação intencional) |

### 6.4 Fallback sem StackChan

Quando StackChan está offline, daemon usa `IOHIDIdleTime` para detectar atividade local (mouse/teclado). `idle_threshold_min: 5` significa: usuário sem mexer no Mac por 5min → considera ausente, libera wake lock.

### 6.5 pmset awareness

`pmset_expected` é declarativo: representa o que o install configurou. Em boot, daemon compara com `pmset -g sched` e `pmset -g` real. Se diverge (`check_drift_at_boot: true`), emite warning para o Evidence Ledger — sinal de que algum tweak manual ou update do macOS resetou as configs.

### 6.6 Logging e audit

| Chave | Valores | Efeito |
|---|---|---|
| `log_level` | `debug`/`info`/`warn`/`error` | Filtro global. Override via `ATLAS_LOG_LEVEL` env |
| `log_to_os_log` | bool | Se `true`, duplica logs para `os_log` (visível em Console.app); se `false`, só arquivos |
| `emit_evidence_events` | bool | Liga emissão de eventos para Evidence Ledger |
| `evidence_sample_rate.*` | 0.0–1.0 | Sampling rate por tipo de evento. Locks acquired/released = 1.0 (sempre); reason add/expire = 0.5 (metade) para reduzir volume |

---

## 7. Hot reload via SIGHUP

`SIGHUP` é o sinal Unix tradicional para "reload config". Daemon registra handler que recarrega `config.yaml` sem perder estado interno (locks ativos permanecem; FSM não reseta).

### 7.1 Comandos

```bash
# Soft reload (preserva estado)
kill -HUP $(pgrep atlas-host-daemon)

# Hard restart (perde estado, força reinício completo)
launchctl kickstart -k gui/$(id -u)/com.atlas.host-daemon
```

### 7.2 SIGHUP vs kickstart

| Aspecto | SIGHUP | `kickstart -k` |
|---|---|---|
| Reset de estado | Não | Sim |
| Reabre socket Atlas | Não | Sim |
| PID muda | Não | Sim |
| Wake locks ativos | Preservados | Liberados (recriados após reload se ainda válidos) |
| Tempo | <100ms | 1–3s |
| Útil para | Ajuste de graces, sample rates, log level | Trocar `atlas_socket`, pegar binário novo, debugar leak de estado |

### 7.3 Campos reloadable

| Campo | SIGHUP recarrega? | Razão |
|---|---|---|
| `atlas_socket` | ❌ | Socket já está aberto; precisa reabrir |
| `reconnect_*_backoff_ms` | ✅ | Aplicado na próxima reconexão |
| `auth_secret_path` | ❌ | Handshake já foi feito |
| `presence_grace_min`, `pipeline_grace_sec`, etc. | ✅ | Aplicado na próxima avaliação de razões |
| `battery_aware.*` | ✅ | Re-avaliação imediata |
| `fallback_to_local_activity.*` | ✅ | Idle detector re-armado |
| `pmset_expected.*` | ⚠️ Parcial | Drift check só roda em boot; restante reload OK |
| `log_level`, `log_to_os_log` | ✅ | Logger reconfigura |
| `emit_evidence_events`, `evidence_sample_rate.*` | ✅ | Audit reconfigura |

Regra geral: **infra de I/O exige restart; políticas exigem só SIGHUP.**

---

## 8. Lifecycle completo — 4 fases

### 8.1 Boot do Mac (login)

```
T+0s   macOS sobe; kernel inicializa; loginwindow aparece
T+5s   Usuário insere senha
T+6s   launchd lê ~/Library/LaunchAgents/*.plist
T+6s   atlas-host-daemon.plist tem RunAtLoad=true → spawn
T+7s   Daemon: ler config.yaml
T+7s   Daemon: tentar conectar /tmp/atlas-host.sock
T+7s   Socket não existe ainda (Atlas backend não subiu)
       → entra em retry com backoff 1s, 2s, 4s, 8s, 16s, 30s, 30s...
T+12s  Atlas backend (LaunchAgent próprio ou login item) inicializa
T+15s  Atlas backend cria /tmp/atlas-host.sock e escuta
T+16s  Daemon conecta no próximo retry; HMAC handshake
T+17s  Atlas backend conecta no StackChan via Bluetooth/WiFi
T+18s  Daemon registra observers NSWorkspace
T+18s  Daemon começa a receber sinais do Atlas
T+20s  Primeira razão chega (presence_active) → wake lock criado
```

A ordem entre daemon e Atlas backend é não-determinística — qualquer um pode subir primeiro. O backoff exponencial garante convergência sem busy-loop.

### 8.2 Operação normal

Estado estável após boot. Características:

- **Razões fluindo:** Atlas envia `reason.add` e `reason.expire` conforme presença, pipelines, streams, rituais.
- **Wake lock dinâmico:** Criado quando `|reasons| > 0`, liberado quando vazio.
- **Heartbeat:** Daemon envia `ping` ao Atlas a cada 30s. Atlas responde `pong`. Sem resposta em 60s → reconectar.
- **Métricas internas:** Contadores de locks criados/liberados, tempo médio com lock ativo, % do tempo com `assertion` registrada. Expostos via Evidence Ledger.
- **Idle detection:** Em paralelo, `IOHIDIdleTime` é amostrado a cada 60s (apenas se `fallback_to_local_activity.enabled=true`).

### 8.3 Sleep imminent (`NSWorkspace.willSleepNotification`)

```
T-30s  macOS decide dormir (lid close, idle timeout, manual)
T-30s  NSWorkspace dispara willSleepNotification
T-29s  Daemon recebe notificação no main run loop
T-29s  Daemon emite host.sleep.imminent → Atlas
T-28s  Atlas envia system.set_mode degraded → StackChan
T-26s  StackChan transiciona com dignidade (anim de "vou descansar")
T-25s  Daemon persiste razões ativas em snapshot (audit)
T-25s  Daemon libera IOPMAssertion (mac pode dormir)
T-24s  Daemon emite host.sleep.confirmed → Atlas
T+0s   Mac dorme efetivamente
```

A janela de ~30s entre `willSleep` e sleep efetivo é controlada pelo macOS. Daemon NÃO tenta atrasar (seria abusivo); apenas usa para coordenação.

### 8.4 Wake (`NSWorkspace.didWakeNotification`)

```
T+0s   Lid abre / pmset wake / power button
T+0s   Mac acorda; kernel restaura RAM
T+1s   NSWorkspace dispara didWakeNotification
T+1s   Daemon recebe notificação
T+1s   Daemon: socket Atlas ainda válido? Testar com ping
T+2s   Se quebrou (provável) → reconectar com backoff
T+3s   Daemon re-avalia razões persistidas no snapshot
       (algumas podem ter expirado durante sleep)
T+3s   Daemon emite host.wake → Atlas
T+4s   Se há razões válidas → recria IOPMAssertion
T+5s   Atlas reabre conexão com StackChan
T+8s   StackChan volta para mode ambient
```

Critério de "razão sobreviveu sleep": comparar `reason.expires_at` com `Date()` atual. Razões com `expires_at < now` são descartadas.

---

## 9. Restart manual

Cenários e comandos:

| Cenário | Comando | Por que |
|---|---|---|
| Reload config (graces, log level) | `kill -HUP $(pgrep atlas-host-daemon)` | Preserva estado |
| Pegar binário novo | `launchctl kickstart -k gui/$(id -u)/com.atlas.host-daemon` | Spawn novo PID com binário atual |
| Daemon "engasgado" sem crashar | `launchctl kickstart -k gui/$(id -u)/com.atlas.host-daemon` | Hard reset sem unload |
| Trocar `atlas_socket` | `kickstart -k` (ou `bootout` + `bootstrap`) | Socket exige reabertura |
| Substituir plist (mudou KeepAlive, ProcessType, etc.) | `bootout` + cp + `bootstrap` | Plist só é lido em load |

**Por que evitar `unload + load` em runtime normal:** a sequência tem janela onde daemon não está supervisionado; se algo der errado entre `unload` e `load`, fica desativado até próximo login. `kickstart -k` é atômico — sempre prefira.

`KeepAlive.Crashed=true` cuida de crashes automaticamente, com `ThrottleInterval=10` evitando loop. Se daemon crashar 6× em 60s, `launchd` aplica throttle e espera; se persistir, marca como `last exit reason: too quick to spawn` e desiste — checar via `launchctl print`.

---

## 10. Uninstall script

`scripts/uninstall.sh`:

```bash
#!/bin/bash
launchctl bootout gui/$(id -u)/com.atlas.host-daemon || true
rm -f ~/Library/LaunchAgents/com.atlas.host-daemon.plist
sudo rm -f /usr/local/bin/atlas-host-daemon
sudo pmset repeat cancel
echo "Atlas Host Daemon removido. Configs em ~/.config/atlas-host-daemon/ preservados."
```

Notas de design:

- `bootout || true`: se daemon já não está carregado, não falha o script.
- `~/.config/atlas-host-daemon/` é **preservado**: contém `config.yaml` editado pelo usuário e `socket.secret`. Reinstall mantém setup.
- `pmset repeat cancel`: remove o wake schedule. Outros settings (`displaysleep`, `sleep`, `hibernatemode`) são preservados — usuário pode querer mantê-los.
- Logs em `~/Library/Logs/atlas-host-daemon.{out,err}` são **preservados** para post-mortem.

Para wipe total (raro, debug profundo):

```bash
rm -rf ~/.config/atlas-host-daemon/
rm -f ~/Library/Logs/atlas-host-daemon.{out,err}
```

---

## 11. Troubleshooting

Problemas comuns, ordenados por frequência observada.

| Sintoma | Diagnóstico | Resolução |
|---|---|---|
| Daemon não está rodando | `launchctl print gui/$(id -u)/com.atlas.host-daemon` mostra `state = exited` | Checar `last exit reason`; ler `~/Library/Logs/atlas-host-daemon.err`; corrigir; `kickstart -k` |
| Daemon sobe mas conexão Atlas falha | Logs mostram `connection refused` ou `no such file` | Verificar `atlas_socket` no YAML; checar se Atlas backend está rodando; checar permissões do socket |
| Wake lock não é criado | `pmset -g assertions` não lista assertion `com.atlas` | Logs mostram razões? Política `battery_aware` ativa em bateria baixa? `respect_low_power_mode` ativo? |
| Mac dorme apesar de daemon ativo | `pmset -g assertions` lista assertion mas Mac dorme mesmo assim | Outra app criou `BackgroundTask` em vez de `NoIdleSleep`; ver tipo de assertion. Pode ser bug de escolha de tipo |
| Múltiplos daemons rodando | `ps aux \| grep atlas-host-daemon` mostra 2+ PIDs | Provável install corrompido. `launchctl bootout` + `kill -9` os zumbis + `bootstrap` limpo |
| Logs não aparecem | `tail -f ~/Library/Logs/atlas-host-daemon.out` vazio | Permissão de `~/Library/Logs/`; `log_to_os_log: true` no YAML e usar `log stream --predicate 'subsystem=="com.atlas.host-daemon"'` |
| Após update macOS, daemon parou | `launchctl print` retorna `Could not find service` | Update do macOS pode invalidar `bootstrap`. `launchctl bootstrap` de novo; em casos raros, recopiar plist |
| `pmset repeat` sumiu após update | `pmset -g sched` não mostra wake | macOS reset. Re-rodar `sudo pmset repeat wakeorpoweron MTWRF 08:45:00` |
| Restart loop (daemon spawn → crash → spawn) | `launchctl print` mostra `last exit reason: too quick to spawn` | Bug no daemon. Logs em `.err`. Em emergência, `launchctl disable gui/$(id -u)/com.atlas.host-daemon` para parar até fix |
| `auth_secret_path` apontando para arquivo ausente | Logs: `failed to read socket secret` | `cat ~/.config/atlas-host-daemon/socket.secret` para confirmar; recriar via Atlas backend |
| Daemon não recebe willSleep/didWake | Logs silenciosos durante sleep cycles | Provavelmente rodando como LaunchDaemon (sem GUI) por engano; confirmar `gui/` no domain |
| Hot reload via SIGHUP não pega | Mudança no YAML não reflete | Checar se campo é reloadable (§7.3); se for `atlas_socket` → kickstart -k |

### 11.1 Comandos de diagnóstico úteis

```bash
# Status completo do daemon
launchctl print gui/$(id -u)/com.atlas.host-daemon

# Todas as IOPMAssertions ativas no sistema
pmset -g assertions

# Wake schedules configurados
pmset -g sched

# Settings de power management completos
pmset -g

# Logs em tempo real (arquivo)
tail -F ~/Library/Logs/atlas-host-daemon.{out,err}

# Logs em tempo real (os_log)
log stream --predicate 'subsystem == "com.atlas.host-daemon"' --level debug

# Últimas 100 linhas de os_log para o subsystem
log show --predicate 'subsystem == "com.atlas.host-daemon"' --last 1h

# Verificar se socket existe e tem permissões corretas
ls -la /tmp/atlas-host.sock

# Testar conectividade com socket (manual, com nc)
nc -U /tmp/atlas-host.sock
```

---

## 12. Atualização do daemon (upgrade)

Procedimento para deploy de versão nova sem reinstalar:

```bash
# 1. Compilar nova versão (em /Users/vitorepf/develop/Atlas/host-daemon/)
swift build -c release

# 2. Substituir binário (daemon ainda rodando com versão antiga)
sudo cp -v .build/release/atlas-host-daemon /usr/local/bin/

# 3. Restart pegar binário novo
launchctl kickstart -k gui/$(id -u)/com.atlas.host-daemon

# 4. Verificar
launchctl print gui/$(id -u)/com.atlas.host-daemon | grep -E "(state|pid)"
tail -20 ~/Library/Logs/atlas-host-daemon.out
```

Alternativa explícita (se mudou plist também):

```bash
launchctl bootout gui/$(id -u)/com.atlas.host-daemon
sudo cp -v .build/release/atlas-host-daemon /usr/local/bin/
cp packaging/com.atlas.host-daemon.plist ~/Library/LaunchAgents/
launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/com.atlas.host-daemon.plist
```

`KeepAlive` cuida do happy path: se você simplesmente substituir o binário e matar o processo, `launchd` respawna usando o binário novo. `kickstart -k` é mais explícito e auditável.

---

## 13. Anti-padrões

| Anti-padrão | Por que evitar | Alternativa |
|---|---|---|
| Usar LaunchDaemon "para garantir que sempre roda" | Daemon não precisa de root; aumenta superfície de ataque drasticamente | LaunchAgent, aceitar que sem login não há daemon (consistente com modelo de presença) |
| `KeepAlive=true` (boolean simples) | Reinicia mesmo após exit limpo, impede shutdown gracioso | `KeepAlive` como dict com `Crashed=true`, `SuccessfulExit=false` |
| Omitir `ThrottleInterval` | Crash em loop consome 100% CPU até throttle implícito do `launchd` | Sempre `ThrottleInterval=10` (mínimo recomendado) |
| Hardcode de `/Users/USER/` no plist sem substituição | Plist quebra em outra máquina ou outro user | Template com `sed` no install (`s|/Users/USER/|$HOME/|g`) |
| Configurar `pmset` em runtime do daemon | Exige `sudo` em runtime, viola separação install/runtime | Configurar uma vez no `install.sh`; daemon só lê e detecta drift |
| Reload config via restart total quando SIGHUP basta | Perde wake locks ativos, derruba conexão Atlas, ciclo de 1–3s | SIGHUP para campos reloadable; restart só para infra de I/O |
| Logar em `/var/log/` ou `/tmp/` | `/var/log/` exige root; `/tmp/` é volátil | `~/Library/Logs/` (path canônico macOS para logs user-level) |
| Usar `launchctl load`/`unload` em scripts novos | Sintaxe legada, comportamento sutilmente diferente em macOS modernos | `launchctl bootstrap`/`bootout`/`kickstart` |
| Não setar `ProcessType` em Apple Silicon | Daemon roda em P-cores, drena bateria | `ProcessType=Background` |
| Permitir `auth_secret_path` em diretório world-readable | Secret vaza para outros usuários | Permissões 600 no arquivo, dir 700 |
| Uninstall que apaga `~/.config/atlas-host-daemon/` | Reinstall perde config; usuário fica frustrado | Preservar configs, deixar wipe explícito como passo manual |

---

## 14. Decisões pendentes

| Tópico | Questão | Impacto |
|---|---|---|
| ⚠️ **DECISÃO PENDENTE:** Versionamento do binário | Embedar version string no daemon e expor via socket (`get_version`) ou apenas via `--version` CLI? | Operacional: facilita verificar drift entre dev/prod sem `kickstart` |
| ⚠️ **DECISÃO PENDENTE:** Telemetria de saúde | Daemon expõe `/metrics` Prometheus-style em socket separado, ou só via Evidence Ledger? | Observabilidade: Prometheus é padrão SRE, mas adiciona surface |
| ⚠️ **DECISÃO PENDENTE:** Hot reload de `atlas_socket` | Implementar reabertura graceful do socket em SIGHUP (com state migration) ou manter como restart-only? | Complexidade ×simplicidade; trocar socket é raro o bastante para justificar restart |
| ⚠️ **DECISÃO PENDENTE:** Auto-update do daemon | Daemon checa github releases periodicamente e auto-instala? Ou strictly manual via Atlas Curator approval? | Segurança: auto-update é risco; manual é fricção. Curator-approved é compromisso natural |
| ⚠️ **DECISÃO PENDENTE:** Fallback para Intel Macs | `ProcessType=Background` em Intel ainda é hint útil ou no-op? Vale documentar override? | Performance em hardware legacy; provavelmente irrelevante na prática |
| ⚠️ **DECISÃO PENDENTE:** Multi-user em Mac compartilhado | Cada usuário tem daemon próprio (LaunchAgent independente) — como evitar conflito de socket em `/tmp/`? | `/tmp/atlas-host-${UID}.sock` resolve, mas exige convenção em Atlas backend também |

---

## 15. Próximos passos de leitura

- `01-visao-geral.md` — Motivação e desenho de alto nível do Host Daemon.
- `02-arquitetura-comportamento.md` — FSM, política de razões, lógica de wake lock, integração com Evidence Ledger.
- `04-protocolo-socket.md` — Schema das mensagens trocadas via socket Unix entre Atlas backend e daemon.
- `05-evidence-integration.md` — Eventos emitidos pelo daemon e como aparecem no Evidence Ledger.
- `../../06-firmware-stackchan/04-build-flash.md` — Equivalente operacional do lado do StackChan (firmware).
- `../05-atlas-backend-bridge.md` — O que o backend faz com os sinais que envia ao daemon.
- `../../09-operacao/02-runbook-incidentes.md` — Procedimentos de resposta a falhas em produção (incluindo daemon morto, drift de pmset, sleep não respeitado).
