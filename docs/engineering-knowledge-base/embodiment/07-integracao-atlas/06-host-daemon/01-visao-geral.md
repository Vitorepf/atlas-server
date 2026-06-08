# 01 — Visão geral do Atlas Host Daemon

> **Propósito:** especificar **o que é, por que existe e como se encaixa** o Atlas Host Daemon — o componente macOS-side que mantém o Mac do usuário acordado enquanto o Atlas precisa estar vivo, e que coordena os ciclos sleep/wake com o backend e (indiretamente) com o StackChan. Este documento é o portal do submódulo `06-host-daemon/`.
>
> **Pré-requisitos:** [README](../../README.md), [02-arquitetura/01-corpo-vs-alma.md](../../02-arquitetura/01-corpo-vs-alma.md), [01-surface-adapter.md](../01-surface-adapter.md), [05-policies/01-privacidade.md](../../05-policies/01-privacidade.md).
>
> **Fora do escopo:** APIs concretas do macOS (vai pra `02-apis-macos.md`), instalação e LaunchAgent plist (vai pra `03-configuracao-lifecycle.md`), protocolo Atlas↔daemon e auditoria (vai pra `04-protocolo-atlas-audit.md`), edge cases e testes (vai pra `05-edge-cases-testes.md`).

---

## 1. Definição precisa do componente

O **Atlas Host Daemon** é um pequeno binário Swift (~500 linhas de código alvo) executado como `LaunchAgent` na sessão do usuário no MacBook que hospeda o Atlas backend. Sua única responsabilidade é **gerenciar o ciclo de power do macOS** em função do que o Atlas está fazendo.

Ele é:

- **Persistente** entre restarts do Atlas backend (deploy, crash, update).
- **Sem cognição.** Vê apenas booleans, números e strings de razão.
- **Único endpoint power-aware** do sistema: ninguém mais cria `IOPMAssertion`.
- **Tradutor**, em ambas as direções, entre eventos do macOS (sleep/wake, lid close, AC change) e o Atlas backend.
- **Coordenador indireto** com o StackChan — sempre via Atlas, nunca direto.

Em uma frase: o daemon é o **braço operacional do Atlas no kernel do Mac**, executando policy de continuidade que vem da alma.

---

## 2. Por que existe

### 2.1 O problema concreto

Atlas roda no MacBook do usuário. O usuário **fecha a tampa**, **deixa idle**, ou simplesmente sai e o Mac entra em sleep. Atlas morre. Quando o usuário volta, precisa reabrir tudo, perde-se contexto vivo, sessões caem, StackChan vai pra `degraded` sem aviso.

A solução manual atual é abrir um terminal e rodar `caffeinate -dimsu`. **O usuário esquece.** Toda vez. A fricção real **não é energética**, é **cognitiva**: lembrar de um comando.

### 2.2 Reframe do custo

A objeção intuitiva é "deixar Mac plugado e acordado o dia todo desperdiça energia". Quantificando:

| Item | Valor |
|---|---|
| Consumo idle MacBook plugado, lid open, sem display em uso ativo | ~2 a 5 W |
| Consumo anual contínuo (assumindo 24/7) | ~17 a 44 kWh |
| Custo anual (tarifa residencial BR ~R$0,80/kWh) | ~R$14 a R$36 |
| Equivalente em fricção evitada (esquecimentos × tempo de reboot/recovery) | dezenas de horas/ano |

O custo é **trivial**. A solução não é economizar 30 reais; é eliminar a categoria de problema "esqueci o caffeinate".

### 2.3 Alternativas avaliadas e descartadas

| Alternativa | Por que descartada |
|---|---|
| Mac dormir e acordar via USB HID wake do StackChan | Latência alta no wake, complexidade de provisionamento, edge cases de "Mac não acordou" sem feedback claro |
| Cloud relay (Atlas hospedado em servidor) | Reescrita massiva, latência adicional, custo recorrente, perde acesso direto ao filesystem do usuário |
| Mini-server dedicado (Mac mini, NUC) | Hardware extra, complexidade de manter dois ambientes, sincronização de estado |
| VPS dedicada para Atlas | Mesmos problemas do cloud relay + custo + latência |
| Continuar com `caffeinate` manual | É exatamente o problema |

**Decisão:** Mac fica acordado **plugado**, com daemon dedicado substituindo `caffeinate` por gestão inteligente baseada em sinais reais.

---

## 3. Por que separado do Atlas backend

O daemon **não vive dentro do processo Atlas**. Roda como binário próprio. Quatro razões:

| Razão | Detalhe |
|---|---|
| Sobrevive restart do Atlas | Deploy, crash, update do backend não derrubam o wake lock. Mac continua acordado durante recovery. |
| Single-purpose | Faz uma coisa só. Sem tentação de virar dump-ground de funcionalidade. |
| Permissões mínimas | LaunchAgent vê IOKit, NSWorkspace, Unix socket. Atlas backend não precisa pedir essas permissões nem rodar com elas. |
| Unix philosophy | Componentes pequenos, compostos. Daemon é trivialmente substituível, testável, debugável isoladamente. |

### 3.1 Comparativo embedded vs separado

| Dimensão | Embedded em Atlas | Daemon separado |
|---|---|---|
| Sobrevive Atlas crash/restart | Não — wake lock cai junto | Sim — lock persiste entre restarts |
| Permissões necessárias no Atlas | IOKit, NSWorkspace, kernel notifications | Apenas socket de comunicação |
| Superfície de ataque | Atlas inteiro com IOKit | Daemon isolado de ~500 linhas |
| Testabilidade | Acoplada a stack inteira do Atlas | Mockável, isolável |
| Linguagem ótima | A do Atlas, mesmo que ruim para APIs Apple | Swift — nativo, idiomático |
| Reuso | Específico ao processo | Pode atender outros backends futuros |
| Custo de implementação inicial | Menor (1 processo) | Marginalmente maior (LaunchAgent + IPC) |

A diferença marginal de custo de implementação não compensa as perdas em todas as outras dimensões.

---

## 4. Princípios

**P1 — O daemon não decide cognição.** Razões válidas vêm do Atlas. Daemon executa, registra, expira. Sem heurística própria sobre "achar que o usuário está presente".

**P2 — Sleep é estado válido.** O objetivo não é manter o Mac acordado para sempre. É mantê-lo acordado **enquanto há razão**. Quando todas as razões expiram, lock é liberado e Mac dorme naturalmente.

**P3 — Single source of truth no kernel.** Apenas o daemon emite `IOPMAssertion`. Qualquer outro processo que precise influenciar power passa pelo Atlas, que passa pelo daemon. Sem duas mãos no volante.

**P4 — Razões são enumeradas e finitas.** Não é string livre. Conjunto fechado de 6 razões (seção 8). Adicionar nova razão é mudança de schema, registrada e auditada.

**P5 — TTL obrigatório.** Toda razão tem expiração. Se o emissor sumir, a razão morre sozinha. Sem locks órfãos.

**P6 — Privacy by data minimization.** Daemon recebe apenas o necessário para gerenciar power. Sem áudio, sem imagem, sem texto, sem identificação de conteúdo. Booleans, números, strings de razão — só.

**P7 — Falha segura é dormir.** Em qualquer ambiguidade ou erro, o estado seguro é liberar lock e deixar o Mac dormir. Nunca segurar lock por defensividade.

---

## 5. Arquitetura interna do daemon

```
┌────────────────────────────────────────────────────────────────┐
│ Atlas Host Daemon (Swift, LaunchAgent)                         │
│                                                                │
│   ┌──────────────────┐       ┌────────────────────┐            │
│   │ ConfigLoader     │       │ AtlasConnector     │            │
│   │ - LaunchAgent    │       │ - Unix socket      │            │
│   │   plist + YAML   │       │ - JSON line-frame  │            │
│   │ - reload SIGHUP  │       │ - reconnect/retry  │            │
│   └────────┬─────────┘       └──────────┬─────────┘            │
│            │                            │                      │
│            ▼                            ▼                      │
│   ┌─────────────────────────────────────────────────────────┐  │
│   │ Core Loop (state machine + dispatcher)                  │  │
│   └────┬─────────────┬─────────────┬─────────────┬──────────┘  │
│        │             │             │             │             │
│        ▼             ▼             ▼             ▼             │
│  ┌──────────┐ ┌─────────────┐ ┌──────────────┐ ┌────────────┐  │
│  │ Reason   │ │ WakeLock    │ │ SleepWake    │ │ Battery    │  │
│  │ Registry │ │ Manager     │ │ Observer     │ │ Monitor    │  │
│  │          │ │             │ │              │ │            │  │
│  │ - set    │ │ - IOPM-     │ │ - NSWork-    │ │ - AC/batt  │  │
│  │   active │ │   Assertion │ │   space      │ │ - level    │  │
│  │ - TTLs   │ │ - assert/   │ │   willSleep  │ │ - thermal  │  │
│  │ - prio   │ │   release   │ │   didWake    │ │            │  │
│  └──────────┘ └─────────────┘ └──────────────┘ └────────────┘  │
│        │                                                       │
│        ▼                                                       │
│   ┌────────────────────┐                                       │
│   │ ActivityFallback   │   (último recurso, opcional)          │
│   │ - CGEventSource    │                                       │
│   │ - jiggle ínfimo    │                                       │
│   └────────────────────┘                                       │
└────────────────────────────────────────────────────────────────┘
```

### 5.1 Componentes internos — responsabilidades

| Componente | Responsabilidade |
|---|---|
| `ConfigLoader` | Lê plist + YAML de config; suporta reload via SIGHUP. |
| `AtlasConnector` | Cliente do Unix socket; reconexão exponencial; line-framed JSON. |
| `Core Loop` | Máquina de estados; dispatcher de eventos; aplicador de policy. |
| `ReasonRegistry` | Conjunto vivo de razões ativas; TTLs; prioridades; expiração automática. |
| `WakeLockManager` | Wrapper sobre `IOPMAssertionCreateWithName`; assert/release; idempotente. |
| `SleepWakeObserver` | Inscrito em `NSWorkspace.willSleepNotification` e `didWakeNotification`. |
| `BatteryMonitor` | Observa AC vs battery, nível, thermal state; alimenta policy. |
| `ActivityFallback` | Fallback opcional via `CGEventSource` se IOPMAssertion falhar — desligado por default. |

`Core Loop` é o único componente com lógica condicional. Os demais são adapters finos sobre APIs do macOS ou estruturas de dados.

---

## 6. Estados internos

```
                    ┌──────────────────┐
        ┌──────────►│  AWAKE_LOCKED    │◄──────────┐
        │           │  (lock ativo)    │           │
        │           └────────┬─────────┘           │
        │                    │ todas razões        │
        │                    │ expiram             │
        │                    ▼                     │
        │           ┌──────────────────┐           │
        │           │ AWAKE_UNLOCKED   │           │ nova razão
        │           │ (sem lock)       │           │ chega
        │           └────────┬─────────┘           │
        │                    │ NSWorkspace         │
        │                    │ willSleep           │
        │                    ▼                     │
        │           ┌──────────────────┐           │
        │  Atlas    │   SLEEP_PREP     │           │
        │  ack      │   (notificando)  │           │
        │  done     └────────┬─────────┘           │
        │                    │ macOS dorme         │
        │                    ▼                     │
        │           ┌──────────────────┐           │
        │           │  MAC_SLEEPING    │           │
        │           │  (daemon idle)   │           │
        │           └────────┬─────────┘           │
        │                    │ NSWorkspace         │
        │                    │ didWake             │
        │                    ▼                     │
        │           ┌──────────────────┐           │
        └───────────┤   MAC_WAKING     ├───────────┘
                    │ (reconnect Atlas)│
                    └──────────────────┘
```

### 6.1 Tabela de transições

| De | Para | Trigger | Ação no caminho |
|---|---|---|---|
| `AWAKE_UNLOCKED` | `AWAKE_LOCKED` | Primeira razão entra no Registry | `WakeLockManager.assert()` |
| `AWAKE_LOCKED` | `AWAKE_UNLOCKED` | Última razão expira/é removida | `WakeLockManager.release()` |
| `AWAKE_UNLOCKED` | `SLEEP_PREP` | `NSWorkspace.willSleep` | Notifica Atlas; aguarda ack ou timeout 1s |
| `AWAKE_LOCKED` | `SLEEP_PREP` | `NSWorkspace.willSleep` (raro — lock deveria impedir) | Mesmo fluxo + log de anomalia |
| `SLEEP_PREP` | `MAC_SLEEPING` | macOS efetivamente entra em sleep | Estado passivo; observador permanece ativo |
| `MAC_SLEEPING` | `MAC_WAKING` | `NSWorkspace.didWake` | Reconectar `AtlasConnector`; checar `BatteryMonitor` |
| `MAC_WAKING` | `AWAKE_LOCKED` | Atlas envia razão pós-wake | Reassert lock |
| `MAC_WAKING` | `AWAKE_UNLOCKED` | Sem razão dentro de janela curta (~5s) | Mac fica acordado mas sem lock; pode redormir |

Estados são **observáveis externamente** via `04-protocolo-atlas-audit.md` — Atlas pode consultar estado atual.

---

## 7. Catálogo de razões

Conjunto fechado. Adicionar razão é mudança de schema.

| Razão | Origem | TTL típico | Prioridade | Notas |
|---|---|---|---|---|
| `presence_active` | StackChan reportou `user_present: true` (via Atlas) | 5 min, renovado a cada heartbeat com presença | Alta | Razão dominante quando há corpo presente. |
| `pipeline_active` | Atlas Decide processando uma operação | Vida do pipeline + 30s buffer | Alta | Crítico — não pode dormir no meio de uma decisão. |
| `stream_open` | Captura de áudio ou playback de TTS em curso | Vida do stream | Alta | Cortar áudio mid-stream é vexame. |
| `ritual_lookahead` | Ritual agendado dentro de janela de 15min | Até T-0 do ritual | Média | Garante que ritual dispara mesmo sem trigger imediato. |
| `recent_interaction` | Última interação < 30min | 30min desde última interação | Baixa | Buffer pós-uso; usuário pode voltar. |
| `manual_lock` | Comando explícito do usuário (CLI, UI) | Configurável; default 1h | Crítica | Override humano; sempre ganha. |

### 7.1 Semântica do conjunto

- O lock fica ativo se **qualquer** razão estiver presente. União, não interseção.
- Cada razão tem seu próprio TTL. Renovação é por razão.
- Prioridade só importa para **logs e diagnóstico** — todas as razões valem igual para manter o lock.
- Razão é identificada por `(reason_id, source_id)`. Dois `pipeline_active` simultâneos de operações diferentes são entradas distintas.

---

## 8. Relacionamento com outros componentes

```
                ┌────────────────────────────────────────┐
                │            Atlas backend               │
                │ (Pipeline, Surface Adapter, Curator)   │
                └────────────┬───────────────────────────┘
                             │
                             │ Unix socket
                             │ (line-framed JSON)
                             │
                             ▼
                ┌────────────────────────────────────────┐
                │         Atlas Host Daemon              │
                │       (LaunchAgent, Swift)             │
                └──┬──────────────────────┬──────────────┘
                   │                      │
       IOKit /     │                      │  NSWorkspace
       IOPM-       │                      │  notifications
       Assertion   │                      │  (willSleep,
                   ▼                      ▼   didWake)
                ┌────────────────────────────────────────┐
                │              macOS                     │
                │  (kernel, power management, pmset)     │
                └────────────────────────────────────────┘

       StackChan ◄─── nunca direto ───► Daemon
       StackChan ────► Atlas ────► Daemon  (caminho válido)
```

### 8.1 Tabela de canais

| Componente | Canal | Direção | Conteúdo |
|---|---|---|---|
| Atlas backend | Unix socket (`/tmp/atlas-host-daemon.sock` ou `~/Library/...`) | bidirecional | Razões, queries de estado, eventos sleep/wake |
| macOS / IOKit | `IOPMAssertionCreateWithName` / `Release` | daemon → kernel | Assert e release de wake locks |
| macOS / NSWorkspace | Notification center | kernel → daemon | `willSleep`, `didWake`, `screensDidSleep` |
| macOS / pmset | CLI invocada uma vez na instalação | daemon → sistema | Schedule wake configurado em setup, não em runtime |
| StackChan | (nenhum) | — | Daemon nunca fala direto. Via Atlas Surface Adapter. |

---

## 9. Não-objetivos (o que fica de fora)

| Não-objetivo | Onde isso vive |
|---|---|
| Decidir cognição (intent, domain, resposta) | Atlas Decide — daemon vê apenas razões |
| Conversar diretamente com StackChan | Surface Adapter (ver `../01-surface-adapter.md`) |
| Armazenar histórico, conteúdo, memória | Evidence Ledger no Atlas — daemon mantém só razões ativas |
| Rodar como root (LaunchDaemon) | Não — é LaunchAgent na sessão do usuário, sem privilégio elevado |
| Funcionar em Linux/Windows | macOS-only por design; APIs são Apple-específicas |
| Gerenciar wake remoto via WoL | Cenário de Mac dormindo é tratado pelo próprio macOS pmset |
| Otimizar bateria em modo nômade | Foco é Mac plugado em casa — escopo intencionalmente reduzido |
| Substituir Activity Monitor / power tools | Não é observatório de sistema, é executor de policy |
| UI gráfica | Sem GUI; CLI mínima de admin (`atlas-host-daemon status`, `manual-lock`, etc.) |

---

## 10. Privacy posture

O daemon é **executor**, não cognitor. A mesma regra "corpo não decide" aplica aqui: o daemon recebe a policy materializada (razões), nunca o conteúdo que gerou a policy.

| O daemon vê | O daemon NÃO vê |
|---|---|
| `reason_id` (string enumerada) | Texto da conversa |
| `source_id` (opaque) | Áudio capturado |
| TTL em segundos | Imagem da câmera |
| Timestamp | Identidade do usuário (além do dono da sessão macOS) |
| Estado AC/bateria/thermal | Conteúdo de envelopes |
| Timestamps de sleep/wake | Decision Receipts |

Auditoria: todo evento do daemon (assert, release, sleep, wake, razão entra/sai) é replicado para o Atlas como evento estruturado, registrado no Evidence Ledger pelo lado do Atlas. O daemon mantém apenas um buffer circular curto local (últimos N eventos) para diagnóstico em caso de socket caído.

Princípio: se o socket Atlas estiver caído, o daemon **não persiste cognição localmente** — só razões ativas em memória. Reboot do daemon zera tudo exceto config.

---

## 11. Resumo em três frases

O Atlas Host Daemon é um LaunchAgent Swift de propósito único que mantém o Mac acordado enquanto o Atlas tem razão para estar vivo, substituindo o `caffeinate` manual por gestão dirigida por sinais reais do pipeline. Ele é separado do backend para sobreviver a restarts, isolar permissões, e seguir filosofia Unix. Ele não decide nada cognitivamente — apenas materializa policy que vem do Atlas em assertions IOKit, e traduz eventos `NSWorkspace` de volta para o Atlas, sem nunca ver conteúdo.

---

## 12. Anti-padrões

| Anti-padrão | Por quê |
|---|---|
| Daemon mantendo lock "por garantia" sem razão ativa | Quebra P2 e P7 — Mac nunca dorme, vira `caffeinate -d` glorificado. |
| Atlas backend criando IOPMAssertion direto | Quebra P3 — duas mãos no volante; lock vaza no crash do Atlas. |
| Daemon recebendo conteúdo cognitivo (texto, áudio) | Quebra P1 e P6 — viola minimização de dados, expande superfície de ataque. |
| Razão sem TTL ("permanente até release explícito") | Quebra P5 — lock órfão se emissor crashar. |
| Daemon decidindo se a razão é "válida" via heurística | Quebra P1 — cognição na borda; razões válidas são definidas por enum. |
| Falar direto com StackChan sem passar por Atlas | Quebra modelo de surfaces — daemon vira segundo cérebro paralelo. |
| Rodar como LaunchDaemon (root) em vez de LaunchAgent | Quebra princípio de menor privilégio sem ganho funcional. |
| Usar `caffeinate` shell em vez de IOPMAssertion direto | Perde granularidade de razão, perde introspecção, perde release determinístico. |
| Ignorar `NSWorkspace.willSleep` e tentar prevenir sleep nele | Sleep é estado válido (P2); willSleep é para preparar, não para resistir. |
| Persistir histórico de razões em disco no daemon | Cognição vive na alma — Evidence Ledger no Atlas é a verdade auditável. |

---

## 13. Decisões pendentes

| Decisão | Bloqueia |
|---|---|
| ⚠️ **DECISÃO PENDENTE:** Path do socket Unix — `/tmp/atlas-host-daemon.sock`, `~/Library/Application Support/Atlas/host-daemon.sock`, ou abstrato namespace? | Implementação do AtlasConnector e do servidor no Atlas backend. |
| ⚠️ **DECISÃO PENDENTE:** `ActivityFallback` (CGEventSource jiggle) entra na v1 ou só se detectarmos falha real de IOPMAssertion? | Escopo da v1 e superfície de teste. |
| ⚠️ **DECISÃO PENDENTE:** Comportamento padrão quando bateria desconecta (laptop unplugged) — manter lock, soltar lock, ou pedir confirmação ao Atlas? | Policy de power off-grid; afeta `BatteryMonitor`. |
| ⚠️ **DECISÃO PENDENTE:** Razão `manual_lock` deve ter TTL máximo "duro" (ex.: 8h) ou ilimitado se usuário pediu? | UX de admin e segurança contra lock esquecido. |
| ⚠️ **DECISÃO PENDENTE:** Daemon deve oferecer comando para forçar sleep imediato ("durma agora, ignore razões")? | Modelo de override; intersecta com hardware mute do StackChan. |
| ⚠️ **DECISÃO PENDENTE:** Schema de razão é versionado independentemente ou junto com `Operation Envelope`? | Evolução do protocolo Atlas↔daemon. |

Lista replicada em `10-anexos/C-decisoes-pendentes.md` quando consolidada.

---

## Próximos passos de leitura

Dentro deste submódulo (`06-host-daemon/`):

- `02-apis-macos.md` — APIs Apple usadas: `IOPMAssertion`, `NSWorkspace` notifications, `pmset`, `IOPSCopyPowerSourcesInfo`, com snippets Swift e armadilhas conhecidas.
- `03-configuracao-lifecycle.md` — LaunchAgent plist, instalação, atualização, reload de config, integração com pmset schedule, comandos de admin.
- `04-protocolo-atlas-audit.md` — Schema do socket Unix: mensagens `reason.add`, `reason.remove`, `state.query`, `event.sleep`, `event.wake`; auditoria e replicação no Evidence Ledger.
- `05-edge-cases-testes.md` — Lid close, AC unplug, thermal throttle, socket caído, Atlas crash, daemon crash, wake espúrio, clock skew, plano de teste.

Em outras pastas do projeto:

- `../01-surface-adapter.md` — Como Atlas recebe sinais do StackChan que viram razões `presence_active`.
- `../02-output-renderer.md` — Como Atlas decide entrar em `degraded` antes do sleep, coordenando com StackChan.
- `../../05-policies/01-privacidade.md` — Princípios de minimização de dados que ancoram a privacy posture deste daemon.
- `../../02-arquitetura/01-corpo-vs-alma.md` — Princípio "corpo não decide" estendido aqui ao executor macOS-side.
- `../../04-protocolos/04-transporte.md` — Comparação útil: como o transporte com StackChan se contrasta com o socket local com o daemon.
