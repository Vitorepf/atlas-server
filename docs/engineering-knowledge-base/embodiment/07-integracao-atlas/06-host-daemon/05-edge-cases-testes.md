---
id: atlas-embodiment-07-integracao-atlas-06-host-daemon-05-edge-cases-testes
type: engineering_knowledge
title: "Host Daemon — Edge Cases, Estratégia de Testes e Critérios de Aceitação"
status: source_material
authority_class: design_reference
implementation_state: design_reference_no_runtime
category: physical-surface
summary: "Source material for the Atlas Embodiment/StackChan physical surface design; not current runtime authority."
canonical_owner: docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md
---
# Host Daemon — Edge Cases, Estratégia de Testes e Critérios de Aceitação

> **Propósito:** Catalogar failure modes do Atlas Host Daemon, definir camadas de teste que cobrem comportamento observável (não implementação interna) e fixar critérios de aceitação mensuráveis para liberação em produção pessoal.
> **Pré-requisitos:** Familiaridade com `01-visao-geral.md`, `01-visao-geral.md` (estados), `04-protocolo-atlas-audit.md` e `03-configuracao-lifecycle.md`. Conhecimento básico de XCTest, IOPMAssertion mocking e ferramentas de diagnóstico do macOS (`pmset`, `log stream`, `launchctl`).
> **Fora do escopo:** Testes do StackChan firmware (vide `docs/embodiment/05-stackchan/`), testes do kernel pipeline do Atlas, performance benchmarking de ML inference. Aqui só o daemon e suas fronteiras.

---

## 1. Filosofia de testes

O daemon manipula estado real do sistema operacional. Uma falha não é cosmética: o Mac pode dormir quando a sessão de inferência ainda está ativa, ou pode permanecer acordado consumindo bateria sem motivo. Falha = degradação concreta.

Quatro princípios, em ordem de importância:

| Princípio | Justificativa | Consequência prática |
|---|---|---|
| Comportamento observável > implementação | A lógica interna pode mudar; o contrato com macOS e Atlas não. | Testes verificam side-effects (assertion criada, sinal enviado), não chamadas internas. |
| Camadas com custo crescente | Testes unitários são baratos; testes de hardware-in-the-loop são caros. | Pirâmide: muitos unit, alguns integration, poucos system, contínuos long-running. |
| Validação manual obrigatória para sleep/wake real | Não há simulador fiel de NSWorkspace + IOPMAssertion. | Smoke test manual antes de cada release. |
| Tolerância zero para regressão silenciosa | Daemon roda em background; bugs podem passar semanas despercebidos. | Métricas em produção + Curator vigilante. |

Daemon manipula estado durável (assertions kernel-level, agendamento via `pmset`). Testes que quebram esse estado em CI sem cleanup explícito são banidos.

---

## 2. Catálogo de edge cases

### 2.1 Bateria e energia

| Cenário | Comportamento esperado | Detecção de falha | Mitigação |
|---|---|---|---|
| Bateria abaixo do threshold (config padrão 30%) | Liberar wake lock mesmo com `presence_active`. Notificar Atlas com flag `low_battery`. Não recriar lock até bateria voltar acima de 35% (histerese). | Mac dorme em momento esperado; log mostra evento `battery_breach`. Falha = lock persiste e bateria zera. | Watchdog separado monitora `IOPSGetTimeRemainingEstimate`; força release se threshold violado. |
| Mac em Low Power Mode (Energy Saver) | Reduzir agressividade: TTL menor, recusar `ritual_lookahead`. Manter apenas `presence_active` e `pipeline_active`. | Inspeção manual de `pmset -g` durante sessão; daemon log deve indicar `low_power_mode_active`. | Subscrever `NSProcessInfoPowerStateDidChange`; aplicar policy reduzida. |
| Power outage / bateria zerou no laptop | Ao boot, daemon reinicia limpo. Nenhuma razão persistida; aguarda novos sinais do Atlas. | Após boot forçado, `daemon.state` deve ser `AWAKE_UNLOCKED` sem assertions. | Estado é in-memory por design; não há recovery a fazer. |
| Cabo de carga desplugado durante operação | Detectar via `IOPSNotificationCreateRunLoopSource`. Reduzir TTLs em ~30%. Não derrubar lock ativo imediatamente. | Inspecionar log após desplugar; deve aparecer `power_source_changed: battery`. | Listener registrado no boot do daemon; transição emite evento interno. |
| Cabo replugado | Voltar a policy normal em < 5s. TTLs restaurados. | Log `power_source_changed: ac`. Razões antigas mantidas, novas usam TTL pleno. | Mesmo listener acima. |
| Bateria saudável mas charger fraco (USB-C low-wattage) | Tratado como `ac` apesar de drain residual. Aceitável; daemon não compensa. | Bateria cai lentamente durante operação prolongada. | Documentado como limitação; usuário responsável pelo charger adequado. |

### 2.2 Sleep, wake e display

| Cenário | Comportamento esperado | Detecção de falha | Mitigação |
|---|---|---|---|
| Mac entrou em sleep com lock ativo (override por térmico, kernel panic, etc.) | Ao receber `didWake`, recriar lock se razões ainda válidas. Log evento `unexpected_sleep`. | Comparar `lastAssertionState` antes do sleep com estado pós-wake. | Persistir snapshot mínimo de razões antes de `willSleep`; replay no `didWake`. |
| Hibernation profunda (`hibernatemode 25`) | Daemon não roda durante hibernate. Ao boot, recuperação limpa. | Estado pós-wake = boot fresh. | Aceitar; documentar que `hibernatemode 25` é opt-in pelo usuário. |
| Lid closed em laptop, sem display externo | Mac dorme normalmente. Daemon recebe `willSleep`, libera locks. | Log normal de sleep; sem warnings. | Comportamento default do macOS; daemon não interfere. |
| Lid closed em laptop, com display externo conectado (clamshell) | Mac permanece desperto. Daemon não recebe `willSleep`. Continua operação. | Daemon log mostra atividade contínua; `pmset -g log` confirma `Display is turned off` mas não sleep. | Nenhuma; comportamento esperado. |
| Display sleep apenas (sistema acordado) | Irrelevante para daemon. Não é system sleep. | N/A. | N/A. |
| Schedule conflict — daemon agendou wake 8h45, usuário criou outro schedule via System Settings | Detectar drift no próximo health check (5 min). Logar warning. Não sobrescrever schedule do usuário. | Comparar `pmset -g sched` output com schedule esperado. | Polling periódico de `pmset -g sched`; alerta no Evidence Ledger. |
| Multiple wake events em rápida sucessão (sleep/wake/sleep em < 30s) | Debounce de 10s. Só processa transições estáveis. | Storm de eventos no log = falha de debounce. | Coalescer eventos; só atuar após janela de quietude. |
| Wake por causa não-Atlas (notificação, Bluetooth, push) | Daemon detecta wake mas não cria lock próprio. Aguarda sinal explícito do Atlas. | Lock criado sem razão correspondente = vazamento. | Razões só vêm de `ReasonRegistry`; wake puro não adiciona nada. |
| Sleep deferred pelo macOS (network activity, etc.) | Daemon não recebe `willSleep` até de fato ocorrer. Tolerável. | Diferença entre `pmset` schedule e momento real de sleep. | Aceitar; reportar drift via heartbeat. |

### 2.3 Atlas e StackChan

| Cenário | Comportamento esperado | Detecção de falha | Mitigação |
|---|---|---|---|
| Atlas backend crashou | Daemon mantém locks ativos por grace period (default 5 min). Após grace, libera todas as razões originadas do Atlas. Mantém razões locais (manual_lock). | Locks persistem indefinidamente após Atlas down = bug. | Heartbeat do Atlas via socket; timeout ⇒ degraded mode. |
| Atlas backend reiniciou (deploy) | Daemon detecta reconnect, faz handshake. Atlas pode replayar razões ativas via snapshot. | Razões duplicadas após reconnect = falta de idempotência. | Handshake inclui session_id; daemon zera estado vindo do Atlas antes de aplicar replay. |
| StackChan offline mas Atlas ok | Daemon continua funcional. Razões `pipeline_active`, `stream_open` etc. não dependem de StackChan. | Daemon trava esperando StackChan = falha de design. | StackChan é consumer downstream do daemon, não dependência. |
| Atlas backend offline mas StackChan online | Daemon não recebe sinais. Eventualmente todas as razões expiram via TTL. Lock liberado. StackChan opera em modo standalone. | Daemon mantém locks fantasmas após Atlas down > grace. | TTL é hard limit; não renovável sem sinal fresco. |
| Versão de protocolo incompatível Atlas/daemon | Handshake falha com erro explícito (`protocol_version_mismatch`). Daemon não tenta operar parcialmente. | Daemon aceita protocolo desconhecido e ignora campos = silent failure. | Versão semver; major mismatch = abort, minor = warning. |
| Atlas envia sinal com TTL absurdo (24h) | Daemon clamps a TTL máximo configurado (default 30 min). Loga warning. | Lock dura mais que TTL clamp = bug. | Clamp em `ReasonRegistry.add()`. |
| Atlas envia razão desconhecida | Rejeitar com erro; não criar lock especulativo. | Lock criado para razão fora do whitelist = bug. | Whitelist hardcoded no daemon; só razões conhecidas aceitas. |

### 2.4 Daemon próprio

| Cenário | Comportamento esperado | Detecção de falha | Mitigação |
|---|---|---|---|
| Daemon crashou | `KeepAlive` com `ThrottleInterval` (10s) revive. Lock anterior foi liberado pelo macOS automaticamente (assertions são per-process). | Daemon não revive ⇒ plist quebrado. Lock fantasma persiste ⇒ bug do macOS (raro). | Verificar via `launchctl print gui/$UID/com.atlas.hostdaemon`. |
| Daemon recompilado e substituído | `bootout` + `bootstrap`. Razões persistentes não existem (in-memory por design); aceitável. | Razões persistem após reinstall = não deveria acontecer. | Confirmar via inspeção pós-reinstall: estado deve ser zerado. |
| SIGHUP recebido durante operação | Reload de config sem soltar lock. Razões mantidas; só parâmetros (TTLs default, thresholds) atualizados. | Lock cai durante reload = bug. | Handler de SIGHUP toca apenas `Config`; nunca `WakeLockManager` ou `ReasonRegistry`. |
| SIGTERM / SIGINT recebido | Shutdown limpo: libera lock, fecha socket, flush logs. Tempo máximo: 2s. | Daemon morre sem release ⇒ assertion zumbi (macOS limpa, mas não imediatamente). | Signal handler com timeout; força release síncrono. |
| Memory leak suspeito | Heartbeat reporta RSS; cresce > 50MB ⇒ alerta. | Crescimento monotônico de memória ao longo de dias. | Long-running test em pipeline separado; perfilar com Instruments. |
| Múltiplos daemons rodando (zumbi de install antigo) | Conflito; quem chegar primeiro pega o socket, outros falham com `EADDRINUSE`. | Logs de dois processos em paralelo. | Cleanup explícito: `launchctl bootout` antes de novo `bootstrap`. Health check detecta socket race. |
| Socket file órfão após crash | Próximo boot tenta criar socket; falha com `EADDRINUSE` se path persistente, ou OK se em `$TMPDIR`. | Daemon não consegue subir após crash. | Cleanup do socket file no startup; `unlink()` antes de `bind()`. |
| Permissão negada para criar IOPMAssertion | macOS recusa por sandbox ou TCC. Daemon loga erro fatal e exits. | Daemon roda mas locks nunca aplicados. | Verificar entitlements no install; `tccutil` se necessário. |

### 2.5 Sistema operacional

| Cenário | Comportamento esperado | Detecção de falha | Mitigação |
|---|---|---|---|
| Update do macOS (minor: 14.x → 14.y) | Daemon continua funcionando. APIs estáveis. | Daemon não revive após reboot pós-update. | Confirmar plist intacto; reinstalar se necessário. |
| Update do macOS (major: 14 → 15) | Risco de breaking change em IOKit/NSWorkspace. Smoke test obrigatório. | Comportamento anômalo após upgrade. | Documentar major versions testadas; validação manual antes de uso normal. |
| User mudou senha do Mac | Não aplicável; daemon não usa Keychain. | N/A. | N/A. |
| Múltiplos usuários no Mac | Cada usuário tem seu próprio LaunchAgent. Daemons isolados por sessão (gui/$UID). | Daemon de user A vê estado de user B = bug grave de privacy. | LaunchAgent é per-user por construção; não usar `LaunchDaemon`. |
| Migração para novo Mac | Config exportável (YAML em `~/.atlas/host-daemon/config.yaml`). Reinstall do plist no novo Mac. | Estado não portável = perdido (aceitável; é runtime state). | Documentar processo de export/import no `03-configuracao-lifecycle.md`. |
| Sistema com FileVault locked (login screen, antes do unlock) | LaunchAgent não roda até user logar. Comportamento default. | Daemon roda em login screen = privacy concern. | Per-user LaunchAgent garante que só roda pós-login. |
| Time Machine backup ativo | Backup pode disparar IO assertions próprias. Daemon coexiste; assertions não conflitam. | Lock do daemon não tem efeito durante backup = bug do macOS. | Aceitar; backup tem prioridade no kernel. |

### 2.6 Casos extremos / multi-corpo (futuro)

| Cenário | Comportamento esperado | Detecção de falha | Mitigação |
|---|---|---|---|
| Múltiplos StackChans conectados via Atlas | Daemon recebe sinais de qualquer um. Razão `presence_active` cobre todos (não há diferenciação por corpo). | Locks múltiplos para a mesma razão = bug (deveria ser idempotente). | `ReasonRegistry` deduplica por `reason_id`, não por origem. |
| Múltiplos Macs com Atlas (raro / futuro) | Cada Mac tem seu próprio daemon, isolados. Atlas central coordena, mas decisão de lock é local. | Daemon de Mac A reage a sinal destinado a Mac B. | Sinal inclui `target_host_id`; daemon ignora se não for ele. |

---

## 3. Estratégia de testes — 4 camadas

### 3.1 Camada 1 — Unit tests (lógica pura)

Sem APIs do macOS. Frameworks: XCTest. Cobertura alvo: > 85% de linhas em arquivos `*Logic.swift`.

| Componente | Casos representativos |
|---|---|
| `ReasonRegistry` | Add nova razão; add idempotente; remove existente; remove inexistente (no-op); expirar TTL; conjunto vazio detectado; conjunto não-vazio detectado; clamp de TTL acima do max. |
| `WakeLockManager` (com `IOPMAssertionProtocol` mocked) | Create-on-non-empty (registry vazio → não vazio: chama create); release-on-empty (não vazio → vazio: chama release); idempotente (não vazio → não vazio: noop); release falha não trava daemon. |
| `Config` parsing | YAML válido; YAML malformado (erro claro); campos obrigatórios ausentes; valores fora de range (battery_threshold < 5% rejeitado). |
| Throttling | Eventos < threshold descartados; eventos espaçados aceitos; janela de debounce respeitada. |
| Backoff | Reconnect a Atlas: 1s, 2s, 4s, ..., capped at 60s; reset após sucesso. |

Exemplo (esqueleto):

```swift
final class ReasonRegistryTests: XCTestCase {
    func test_addReason_setsNonEmpty() {
        let r = ReasonRegistry()
        XCTAssertTrue(r.isEmpty)
        r.add(.presenceActive, ttl: 60)
        XCTAssertFalse(r.isEmpty)
    }

    func test_ttlExpiry_removesReason() {
        let clock = MockClock()
        let r = ReasonRegistry(clock: clock)
        r.add(.streamOpen, ttl: 10)
        clock.advance(by: 11)
        r.tick()
        XCTAssertTrue(r.isEmpty)
    }
}
```

### 3.2 Camada 2 — Integration tests (mocked NSWorkspace + IOKit)

Wrapper de APIs macOS atrás de protocolos. Test doubles para notificações de sleep/wake.

| Cenário | Verificação |
|---|---|
| `willSleep` chega com locks ativos | Daemon dispara sequência: sinal a Atlas → grace de 30s → release → ack para macOS. |
| `didWake` com razões persistentes (sessão Atlas ativa) | Daemon recria assertion, sincroniza estado com Atlas. |
| `didWake` sem razões | Daemon vai para `AWAKE_UNLOCKED`, sem assertion. |
| Atlas envia sinal durante `SLEEP_PREP` | Sinal enfileirado; aplicado pós-wake. |
| Socket fecha durante operação | Detect via EOF; reconnect com backoff. |

Mocks devem espelhar comportamento real, não conveniência. Validar contra logs do macOS real periodicamente para evitar drift.

### 3.3 Camada 3 — System tests (Mac real)

Manuais. Executados antes de cada release. Não automatizáveis em CI.

| Smoke test | Procedimento | Critério de aprovação |
|---|---|---|
| Sleep → wake real | `pmset sleepnow` com `pipeline_active` registrado. Aguardar wake. Observar StackChan LED durante todo o ciclo. | Daemon avisa StackChan antes de dormir; LED entra em modo "Atlas dormindo"; recupera ao acordar. |
| Battery threshold breach | Forçar `pmset -b` (battery sim) ou desplugar e drenar. Observar momento em que daemon libera locks. | Lock liberado dentro de 30s após threshold cruzado. |
| Atlas restart | `kill -9` no Atlas backend. Aguardar grace period (5 min). Verificar lock liberado. | Lock liberado em ≤ 5min + 10s. |
| StackChan power off | Desligar StackChan. Verificar daemon segue operando. | Daemon continua a registrar locks; logs sem erros sobre StackChan. |
| Lid close (laptop) | Fechar tampa sem display externo; depois com display externo. | Sem display: dorme normal. Com display: permanece desperto. |
| Reboot | `sudo reboot`. Após login, verificar daemon revive automaticamente. | Daemon ativo em ≤ 30s após login. |

Scripts auxiliares:

- `pmset sleepnow` — força sleep imediato (ignora locks de baixa prioridade).
- `caffeinate -u -t 5` — simula activity por 5s.
- `log stream --predicate 'subsystem == "com.atlas.hostdaemon"'` — observa logs em tempo real.

### 3.4 Camada 4 — Long-running stability

Pipeline separado, não bloqueia release. Daemon rodando 30+ dias contínuos.

| Métrica | Critério |
|---|---|
| Crashes | Zero. Qualquer crash = blocker. |
| Memory growth | RSS sustentado < 20MB. Crescimento < 1MB / 24h. |
| CPU idle | < 1% médio em estado `AWAKE_UNLOCKED`. |
| Log volume | < 10MB / dia em modo normal. |
| Assertion count drift | Conta de IOPMAssertions criadas - liberadas = 0 ao final do período. |

Coleta via heartbeat persistido no Evidence Ledger; análise pós-período.

---

## 4. Critérios de aceitação

Mensuráveis, com método de medição. Falha em qualquer = blocker para release.

| Critério | Alvo | Como medir |
|---|---|---|
| Wake lock criado após sinal | < 1s | Timestamp de sinal recebido vs. `IOPMAssertionCreate` no log. |
| Wake lock liberado após razão expirar | < 1s | Timestamp de TTL expiry vs. `IOPMAssertionRelease`. |
| `NSWorkspace.willSleep` notifica StackChan | ≥ 25s antes do sleep efetivo | Tempo entre evento e momento real de sleep (via `pmset -g log`). |
| `didWake` detectado | < 5s após wake real | Comparar com `pmset -g log` Wake event. |
| Daemon sobrevive sem restart | 30+ dias contínuos | `launchctl print` mostra mesmo PID; monitorar via heartbeat. |
| Memory footprint sustentado | < 20MB RSS | `ps -o rss` durante long-running. |
| CPU footprint idle | < 1% | `top -pid <daemon_pid>` em estado `AWAKE_UNLOCKED`. |
| Reconnect a Atlas após restart | < 10s | Timestamp de Atlas up vs. handshake completo. |
| Modo degraded comunicado a StackChan | Antes de Mac dormir | LED do StackChan transita antes do display apagar. |
| Recuperação de fallback (StackChan offline) | < 60s | Daemon log indica "stackchan_offline" e segue operando. |
| Battery threshold breach detected | < 30s | Tempo entre cruzamento real (medido via `ioreg`) e log do daemon. |
| Shutdown limpo em SIGTERM | < 2s | Signal sent vs. processo morto. |
| Schedule drift detectado | < 5min | Polling interval; comparar com `pmset -g sched`. |

---

## 5. Métricas a observar em produção

Persistidas no Evidence Ledger via `daemon.heartbeat` e `daemon.event`. Curator consome para sugerir ajustes.

| Métrica | Granularidade | Uso pelo Curator |
|---|---|---|
| Razões mais frequentes (counter por reason) | Diária | "Você nunca usa `manual_lock` — remover do catálogo?" |
| Wake lock duration distribution | p50, p95, p99 por dia | "p99 está em 4h — algum sinal stuck?" |
| Tempo total em wake lock vs. free | % do dia | "Mac em lock 80% do tempo — policy muito agressiva?" |
| Frequência de transições sleep ↔ wake | Eventos / dia | "20 sleep/wake por dia — consumo de bateria sub-ótimo." |
| Battery threshold breaches | Eventos / dia | "Breaches frequentes — laptop mais móvel; ajustar threshold?" |
| Reconnect events (Atlas) | Eventos / dia | "Atlas instável; verificar saúde do backend." |
| Errors / fallback activations | Eventos / dia | "Modo degradado acionado 3x esta semana." |
| Drift do schedule | Detecções / semana | "pmset modificado externamente — outra ferramenta competindo?" |
| Grace period expirations | Por evento | "Grace de 5min nunca usado completamente — reduzir para 2min?" |

---

## 6. Cenários de regressão

Toda nova versão deve passar por estes testes antes de substituir a anterior.

| Cenário | Verificação |
|---|---|
| Wake lock que era criado antes não é (regressão funcional) | Replay de trace gravado da versão anterior; comportamento deve ser equivalente para inputs idênticos. |
| Razões antigas ainda respeitadas (compat) | Whitelist nunca remove entradas dentro da mesma major version. |
| Protocolo daemon ↔ Atlas | Compat dentro da mesma major version semver. Daemon vN aceita mensagens de Atlas vN.x e vN.(x-1). |
| Plist file format | LaunchAgent continua válido após update do daemon. Schema do `Label`, `ProgramArguments`, `KeepAlive` inalterado. |
| Config YAML schema | Campos opcionais novos têm defaults sensatos. Campos removidos geram warning, não erro. |
| Logs format | Schema dos eventos no Evidence Ledger preservado. Curator não precisa de migração. |

---

## 7. Test fixtures e mocks

| Fixture | Uso | Localização sugerida |
|---|---|---|
| `MockIOPMAssertion` | Simula API de assertions sem chamar IOKit. Conta create/release. | `Tests/Mocks/` |
| `MockNSWorkspace` | NotificationCenter custom; permite disparar `willSleep`/`didWake` sob demanda. | `Tests/Mocks/` |
| `MockClock` | Avanço manual de tempo para testar TTLs sem `sleep()`. | `Tests/Mocks/` |
| Config YAML fixtures | Cenários: default, low_battery_aggressive, debug_verbose, multi_stackchan. | `Tests/Fixtures/configs/` |
| Recorded protocol traces | Sequências reais Atlas ↔ daemon, gravadas em sessões de validação manual. Replayadas em CI. | `Tests/Fixtures/traces/` |
| `MockAtlasSocket` | Servidor Unix socket fake; aceita scripts pré-gravados. | `Tests/Mocks/` |
| Fixture de power state | Bateria em diversos níveis, plugged/unplugged. | `Tests/Fixtures/power/` |

Mocks devem ter um teste-de-mock que confirma fidelidade: comparar saída do mock com saída real do macOS em ambiente controlado, periodicamente.

---

## 8. Anti-padrões em testes

| Anti-padrão | Por que evitar |
|---|---|
| Testar implementação interna (chamadas privadas) em vez de comportamento | Refactor quebra testes sem mudar contrato. Sinal falso de regressão. |
| Mocks que mentem (não correspondem ao comportamento real do macOS) | Testes verdes com daemon quebrado em prod. Pior caso possível. |
| Snapshot tests sem revisão crítica | Baseline errada vira gospel; regressões reais aprovadas como "intencionais". |
| Long-running tests em CI rápido | CI fica lento, devs ignoram; ou flaky por timing. |
| Testes que passam em dev mas falham em prod (ambiente real) | Sintoma de mocks infiéis ou config divergente. |
| Tolerância a flakiness ("retry 3x") | Mascara race conditions reais. Cada flaky test é um bug não diagnosticado. |
| Testes sem assertions explícitas (só "não crasha") | Não verifica comportamento; só crash absence. |
| Compartilhar estado entre testes | Ordem de execução afeta resultado; impossível debugar. |

---

## 9. Anti-padrões gerais do daemon

Comportamentos que indicam desenho ou implementação errados, independente de testes.

| Anti-padrão | Justificativa |
|---|---|
| Razões inventadas pelo daemon sem origem em sinal externo | Daemon é executor; corpo não decide. Razões só vêm de Atlas (ou `manual_lock` explícito). |
| Wake lock criado sem razão associada (vazamento) | Lock fora do contrato é invisível e indebugável. Toda assertion deve mapear para reason_id. |
| Daemon mantendo conexão a Atlas mesmo com Atlas offline indefinidamente | Backoff sem cap = busy loop. Cap em 60s e reportar status. |
| Escalar para `PreventSystemSleep` sem necessidade | Tipo mais agressivo de assertion; user-level operations devem usar `NoIdleSleep`. |
| Usar `caffeinate` programaticamente em vez de `IOPMAssertion` direto | Spawn de processo extra; PID separado; controle indireto. Direto é mais fiel. |
| Ler conteúdo de arquivos do Atlas (privacy violation) | Daemon não tem visibilidade de conteúdo cognitivo. Apenas sinais opacos. |
| Reagir a eventos que não recebeu sinal explícito | Daemon não infere; só executa. Inferência fica no Atlas. |
| Restart loops sem `ThrottleInterval` | Crash imediato + revive imediato = consumo absurdo. Mínimo 10s. |
| Daemon decidindo por conta própria (estende "corpo não decide") | Toda decisão semântica é do Atlas; daemon só translation layer. |
| Logs com PII / dados sensíveis | Logs vão a `os_log` e Evidence Ledger; conteúdo cognitivo não deve aparecer. |
| `pmset` modificado em runtime | `pmset` é configurado no install; runtime apenas observa. Modificar runtime gera schedule drift. |
| Network access (HTTP, DNS, etc.) | Daemon não fala com internet. Comunica apenas via Unix socket local com Atlas. |
| Hot reload de binário (substituição em runtime sem restart) | LaunchAgent é o lifecycle owner; substituir binário em runtime confunde launchd. |
| Persistir estado em disco entre boots | Estado é runtime; persistência confunde recovery e introduz superfície de bugs. |

---

## 10. Decisões pendentes

| Decisão | Contexto | Próximo passo |
|---|---|---|
| ⚠️ **DECISÃO PENDENTE:** Threshold exato de bateria (30% sustentado?) | 30% é palpite inicial; pode ser conservador demais para uso intenso ou agressivo demais para uso leve. | Coletar 2 semanas de telemetria; analisar momentos em que daemon liberou lock por bateria e se houve impacto perceptível. |
| ⚠️ **DECISÃO PENDENTE:** Grace period após Atlas crashar (5min sustentado?) | 5min é compromisso entre tolerância a deploys e limite de drain. | Medir downtime real do Atlas em produção; ajustar grace para cobrir p95 de restart time. |
| ⚠️ **DECISÃO PENDENTE:** Liberar lock automaticamente se daemon perde Atlas por > 1h? | Indefinidamente é claramente errado; 1h é palpite. | Estabelecer policy explícita; documentar como fail-safe. |
| ⚠️ **DECISÃO PENDENTE:** Sample rate ideal para `daemon.heartbeat` no Evidence Ledger | Muito frequente = ruído; muito raro = perde detecção rápida. | Começar 60s; ajustar conforme volume de logs e granularidade necessária para Curator. |
| ⚠️ **DECISÃO PENDENTE:** Como detectar "schedule drift" (`pmset` modificado externamente) | Polling de `pmset -g sched` é caro e indireto. Não há API de notificação. | Avaliar se polling de 5min é suficiente; alternativamente, reconciliar apenas no `didWake`. |
| ⚠️ **DECISÃO PENDENTE:** Test fixtures para hibernation profundo (`hibernatemode 25`) | Difícil simular; requer hardware real e tempo. | Manter como caso manual smoke test; aceitar gap em CI. |
| ⚠️ **DECISÃO PENDENTE:** Estratégia para validação de mocks contra macOS real | Drift entre mock e API real é risco silencioso. | Considerar suite de "mock fidelity tests" rodada manualmente a cada major macOS release. |

---

## 11. Próximos passos de leitura

- `01-visao-geral.md` — Visão geral do daemon, componentes e responsabilidades.
- `01-visao-geral.md` (estados) — Estados e transições; base para entender edge cases de sleep/wake.
- `04-protocolo-atlas-audit.md` — Mensagens entre Atlas e daemon; contexto para casos de incompatibilidade.
- `03-configuracao-lifecycle.md` — Plist, KeepAlive, ThrottleInterval; relevante para anti-padrões de restart.
- `../05-evidence-ledger-bridge.md` — Como heartbeats e métricas chegam ao ledger.
- `../../08-roadmap/01-fase-0-espelho.md` — Critérios de aceitação para a fase atual; referência de estilo.
- `../../09-curator/02-sinais-de-saude.md` — Como Curator interpreta métricas operacionais.
