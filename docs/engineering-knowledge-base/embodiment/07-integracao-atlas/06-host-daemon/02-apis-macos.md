---
id: atlas-embodiment-07-integracao-atlas-06-host-daemon-02-apis-macos
type: engineering_knowledge
title: "Atlas Host Daemon — APIs macOS"
status: source_material
authority_class: design_reference
implementation_state: design_reference_no_runtime
category: physical-surface
summary: "Source material for the Atlas Embodiment/StackChan physical surface design; not current runtime authority."
canonical_owner: docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md
---
# Atlas Host Daemon — APIs macOS

> **Propósito:** mapear cada API nativa do macOS que o Atlas Host Daemon consome, com snippets Swift idiomáticos, gotchas conhecidos e racional de escolha. Documento de referência para quem implementa.
> **Pré-requisitos:** leitura prévia de `01-visao-geral.md` (estados, reasons, papel do daemon). Familiaridade com Swift 5.9+, conceito de RunLoop, e modelo de execução LaunchAgent. Xcode 15+ ou toolchain Swift instalada via `xcode-select --install`.
> **Fora do escopo:** protocolo do socket Unix com Atlas backend (ver `04-protocolo-atlas-audit.md`), policy engine de transição entre estados (ver `01-visao-geral.md` (catálogo de razões)), instalação e plist do LaunchAgent (ver `03-configuracao-lifecycle.md`).

---

## 1. Por que Swift

A escolha de linguagem é uma decisão de longo prazo: o daemon roda no host pessoal do usuário 24/7, integra com APIs Apple-only, e não precisa de portabilidade. O custo dominante é manutenção, não throughput.

| Critério | Swift | Go | Rust | Objective-C |
|---|---|---|---|---|
| Bindings IOKit / IOPMAssertion | Nativo, primeira-classe | Cgo + headers manuais | `objc2` ou cgo, frágil | Nativo, verboso |
| Bindings AppKit / NSWorkspace | Nativo | Inviável (precisa Cocoa runtime) | `objc2` | Nativo |
| Tamanho do binário stripped | ~3–5 MB | ~8–12 MB (runtime embutido) | ~2–4 MB | ~2–3 MB |
| Tooling (LSP, debugger, profiler) | Xcode + sourcekit-lsp | Excelente, mas externo | Excelente, externo | Xcode |
| Concorrência idiomática | `actor`, `async`/`await` | goroutines | `tokio` adiciona peso | GCD |
| Manutenibilidade do código | Alta (tipo forte, opcionais) | Alta | Alta mas verbosa | Média (manual memory hints) |
| Custo de onboarding para o autor | Baixo (já no ecossistema Apple) | Médio | Alto | Médio |
| Risco de quebra em macOS futura | Mínimo (Apple commits) | Médio (cgo + headers) | Médio | Mínimo |

Swift vence porque o daemon **é** uma peça macOS — toda função crítica é uma chamada para framework Apple. Linguagem fora do ecossistema paga overhead de FFI por nenhuma vantagem de performance (o daemon idle gasta < 0.1% de CPU).

---

## 2. Estrutura do projeto (Swift Package Manager)

Sem Xcode project; SPM puro. Build reproduzível, headless, integrável em script de instalação.

```
atlas-host-daemon/
├── Package.swift
├── Sources/
│   └── AtlasHostDaemon/
│       ├── main.swift                  // entry point, RunLoop
│       ├── WakeLock/
│       │   ├── AssertionManager.swift  // IOPMAssertion lifecycle
│       │   └── ReasonRegistry.swift    // actor com set de reasons
│       ├── SleepObserver/
│       │   └── WorkspaceObserver.swift // NSWorkspace notifications
│       ├── Power/
│       │   ├── BatteryMonitor.swift    // IOPowerSources
│       │   └── IdleDetector.swift      // CGEventSource
│       ├── Connector/
│       │   ├── SocketServer.swift      // Unix socket reader
│       │   └── Protocol.swift          // tipos de mensagem
│       ├── Policy/
│       │   └── StateMachine.swift      // AWAKE_LOCKED → ...
│       └── Logging/
│           └── Log.swift               // wrappers os.log
└── Tests/
    └── AtlasHostDaemonTests/
```

```swift
// Package.swift
// swift-tools-version:5.9
import PackageDescription

let package = Package(
    name: "AtlasHostDaemon",
    platforms: [.macOS(.v13)],
    targets: [
        .executableTarget(
            name: "AtlasHostDaemon",
            path: "Sources/AtlasHostDaemon"
        ),
        .testTarget(
            name: "AtlasHostDaemonTests",
            dependencies: ["AtlasHostDaemon"]
        )
    ]
)
```

Sem dependências externas. Tudo que o daemon precisa vive no SDK do macOS: `Foundation`, `AppKit` (para `NSWorkspace`), `IOKit`, `IOKit.ps`, `CoreGraphics`, `os.log`. Manter assim — toda dep externa é vetor de quebra futura num componente que precisa rodar por anos.

---

## 3. IOPMAssertion (IOKit) — wake locks

A API que define o daemon. Uma assertion é um voto declarativo: "enquanto eu existir, sistema não pode entrar em idle sleep". O kernel agrega assertions de todos os processos; a mais forte vigente vence.

### 3.1 Tipos de assertion

| Tipo | O que impede | Display dorme? | Quando usar no daemon |
|---|---|---|---|
| `kIOPMAssertionTypePreventUserIdleSystemSleep` | Sistema entrar em idle sleep por inatividade | Sim, normalmente | **Default.** Mantém Atlas backend vivo, deixa display economizar energia. |
| `kIOPMAssertionTypePreventUserIdleDisplaySleep` | Display dormir | Não (e portanto sistema também não) | Raro. Apenas se houver UI visual ativa que precise estar visível. |
| `kIOPMAssertionTypePreventSystemSleep` | Sistema dormir mesmo por força externa (lid close, comando explícito) | Sim | Raríssimo. Sobrepõe intenção do usuário; reservado para bursts curtos críticos. |
| `kIOPMAssertionTypeNoIdleSleep` | (legacy) idle sleep | Sim | **Evitar.** Substituída pela `Prevent...` family; mantida para compat. |

O daemon Atlas opera quase 100% com `kIOPMAssertionTypePreventUserIdleSystemSleep`. Display continua dormindo após inatividade — isso é desejado: economiza energia e o usuário percebe que a máquina "descansa" mesmo com Atlas vivo.

### 3.2 Lifecycle: create / hold / release / audit

```swift
import IOKit
import IOKit.pwr_mgt

final class AssertionManager {
    private var assertionID: IOPMAssertionID = IOPMAssertionID(0)
    private var held: Bool = false

    func acquire(reasonSummary: String) {
        guard !held else { return }
        let result = IOPMAssertionCreateWithName(
            kIOPMAssertionTypePreventUserIdleSystemSleep as CFString,
            IOPMAssertionLevel(kIOPMAssertionLevelOn),
            "Atlas Host Daemon — \(reasonSummary)" as CFString,
            &assertionID
        )
        if result == kIOReturnSuccess {
            held = true
            Log.wakeLock.info("assertion held id=\(self.assertionID) reason=\(reasonSummary, privacy: .public)")
        } else {
            Log.wakeLock.error("IOPMAssertionCreateWithName failed: \(result)")
        }
    }

    func release() {
        guard held else { return }
        let result = IOPMAssertionRelease(assertionID)
        if result == kIOReturnSuccess {
            held = false
            Log.wakeLock.info("assertion released id=\(self.assertionID)")
        } else {
            Log.wakeLock.error("IOPMAssertionRelease failed: \(result)")
        }
    }
}
```

Pontos:

- A assertion **morre quando o processo morre**. Crash do daemon = release automático. Isso é desejável.
- `IOPMAssertionID` é `UInt32`; zero é sentinela "nenhuma".
- Operação síncrona, não bloqueante; retorna `IOReturn` (0 = sucesso).
- Não há limite formal de assertions concorrentes do mesmo processo, mas o daemon mantém **uma única** assertion e atualiza a string de reason — simplifica auditoria.

### 3.3 Auditoria via `pmset -g assertions`

```
$ pmset -g assertions
2026-05-06 09:14:33 -0300
Assertion status system-wide:
   PreventUserIdleSystemSleep   1
   PreventSystemSleep           0
Listed by owning process:
   pid 4421(AtlasHostDaemon): [0x000004210000172c] 00:23:11 PreventUserIdleSystemSleep named: "Atlas Host Daemon — pipeline_active+presence_active"
```

A string passada em `IOPMAssertionCreateWithName` aparece literal — por isso ela serve como mecanismo de auditabilidade humana. Convenção do daemon: prefixo `Atlas Host Daemon — ` seguido das reasons ativas concatenadas com `+`, ordenadas alfabeticamente (estável entre runs).

### 3.4 Atualização dinâmica de reason

Quando o conjunto de reasons muda (ex.: `presence_active` cai mas `pipeline_active` continua), há duas estratégias:

| Estratégia | Prós | Contras |
|---|---|---|
| `IOPMAssertionSetProperty(id, kIOPMAssertionNameKey, newName)` | In-place, mantém ID estável, sem janela de 0 assertions | API menos documentada; alguns devs reportam comportamento inconsistente em macOS antigos (≤ 11) |
| Release + create | Mais simples, comportamento óbvio | Janela de microssegundos sem assertion; se kernel checa naquele instante, teoricamente sleep poderia disparar (na prática quase impossível, mas existe) |

```swift
// Estratégia in-place (preferida no daemon)
func updateReason(_ summary: String) {
    let key = kIOPMAssertionNameKey as CFString
    let newName = "Atlas Host Daemon — \(summary)" as CFString
    let result = IOPMAssertionSetProperty(assertionID, key, newName)
    if result != kIOReturnSuccess {
        Log.wakeLock.warning("SetProperty failed (\(result)), falling back to release+create")
        release()
        acquire(reasonSummary: summary)
    }
}
```

⚠️ **DECISÃO PENDENTE:** confirmar empiricamente que `IOPMAssertionSetProperty` com `kIOPMAssertionNameKey` propaga corretamente para `pmset -g assertions` no macOS Sonoma e Sequoia. Se não, fixar release-recreate como única estratégia.

### 3.5 Nomenclatura para auditabilidade

Convenção rígida:

```
Atlas Host Daemon — <reasons-ordenadas-com-+>
```

Exemplos válidos:
- `Atlas Host Daemon — pipeline_active`
- `Atlas Host Daemon — presence_active+pipeline_active`
- `Atlas Host Daemon — manual_lock`

Nunca incluir conteúdo cognitivo, IDs de sessão, prompts, ou qualquer dado do usuário na string. Ela vai parar em `pmset -g assertions` e em logs do sistema — é metadado operacional puro.

---

## 4. NSWorkspace notifications — sleep/wake events

`NSWorkspace.shared.notificationCenter` emite eventos de ciclo de vida de power do sistema. Crítico para o daemon entrar em `SLEEP_PREP` antes do sleep efetivar.

### 4.1 Notificações relevantes

| Notificação | Quando dispara | Latência | Uso no daemon |
|---|---|---|---|
| `NSWorkspace.willSleepNotification` | ~30s antes do sleep efetivar (janela de preparação) | < 100ms após decisão do kernel | Disparar `SLEEP_PREP`: avisar Atlas backend, flush de logs, fechar streams. |
| `NSWorkspace.didWakeNotification` | Após wake, sistema utilizável | < 200ms após wake completo | Disparar `MAC_WAKING → AWAKE_*`: re-handshake com Atlas backend, reabrir socket se necessário. |
| `NSWorkspace.screensDidSleepNotification` | Display entrou em sleep | Imediato | **Não relevante** para system sleep. Display dormindo é estado normal com o daemon ativo. Pode ser logado debug-only. |
| `NSWorkspace.screensDidWakeNotification` | Display acordou | Imediato | Idem; informativo apenas. |
| `NSWorkspace.sessionDidBecomeActiveNotification` | User session ativada (fast user switching, login) | Imediato | Recriar assertions se outro usuário causou release. |
| `NSWorkspace.sessionDidResignActiveNotification` | User session desativada (switch para outro usuário) | Imediato | Daemon pode liberar assertion (sessão atual não é a ativa). |

### 4.2 Registro do observer

```swift
import AppKit

final class WorkspaceObserver {
    init() {
        let nc = NSWorkspace.shared.notificationCenter
        nc.addObserver(
            self,
            selector: #selector(onWillSleep(_:)),
            name: NSWorkspace.willSleepNotification,
            object: nil
        )
        nc.addObserver(
            self,
            selector: #selector(onDidWake(_:)),
            name: NSWorkspace.didWakeNotification,
            object: nil
        )
    }

    @objc private func onWillSleep(_ note: Notification) {
        Log.sleepObserver.info("willSleepNotification received")
        // policy.transition(to: .sleepPrep)
    }

    @objc private func onDidWake(_ note: Notification) {
        Log.sleepObserver.info("didWakeNotification received")
        // policy.transition(to: .macWaking)
    }

    deinit {
        NSWorkspace.shared.notificationCenter.removeObserver(self)
    }
}
```

Gotchas:

- O notification center de `NSWorkspace` é **distinto** de `NotificationCenter.default`. Esquecer isso e registrar no errado é o erro #1 — o callback nunca dispara.
- Callbacks chegam na **main thread**. O daemon precisa de RunLoop ativo (ver §9), senão notificações nunca são entregues.
- `willSleepNotification` dá tipicamente ~30s, mas **não há garantia formal**. Em sleep forçado (lid close + low battery), pode dar < 1s. Daemon precisa fazer SLEEP_PREP ser idempotente e rápido (< 500ms ideal).
- Notificações **não chegam** se o processo já estiver suspenso. LaunchAgent normal não é suspenso pelo App Nap (porque é CommandLine sem UI), mas vale verificar com `ProcessInfo.processInfo.beginActivity` se houver dúvida.

### 4.3 Janela de SLEEP_PREP

Recebido `willSleepNotification`, o daemon tem ~30s. Sequência:

1. Notifica Atlas backend via socket (`event: pre_sleep`).
2. Atlas backend tem janela para flush ledger, sinalizar StackChan a entrar em modo dormir, fechar streams.
3. Daemon libera assertion (release → kernel sabe que pode dormir).
4. Loga timestamp e estado das reasons no momento do sleep (para post-mortem se algo der errado no wake).

Se o daemon segurar a assertion durante `willSleepNotification`, kernel **honra** a assertion e **não dorme** — daemon precisa liberar explicitamente para permitir sleep quando justificado.

---

## 5. pmset — schedule e configuração de sleep

`pmset` é a CLI de Power Management. Daemon usa apenas para **schedule de wake**, executado uma vez no setup. Configurações de sleep ficam fora do daemon (modificá-las em runtime requer sudo e acopla daemon a privilégio elevado).

### 5.1 Schedule de wake

```bash
# Wake recorrente de segunda a sexta, 08:45
sudo pmset repeat wakeorpoweron MTWRF 08:45:00

# Wake pontual em data/hora específica
sudo pmset schedule wakeorpoweron "05/07/2026 08:45:00"

# Inspeção
pmset -g sched
```

Sintaxe de dias para `repeat`: `M` (Mon), `T` (Tue), `W` (Wed), `R` (Thu), `F` (Fri), `S` (Sat), `U` (Sun). Concatenados: `MTWRFSU` = todos os dias.

Wake schedule é mecanismo de hardware (RTC) — funciona **mesmo com Mac dormindo profundamente**, **mesmo com daemon morto**. É a única forma confiável de garantir que ritual matinal aconteça se o usuário fechar o lid à noite.

### 5.2 Configurações relevantes

| Setting | Default laptop | Recomendado para Atlas | Notas |
|---|---|---|---|
| `displaysleep` | 10 min | 5–10 min | Daemon não toca; display dormir é normal e desejado. |
| `sleep` | 1 min após display sleep | irrelevante | Daemon usa assertion para anular este. Não precisa setar 0 (que era prática antiga). |
| `hibernatemode` | 3 (safe sleep) | **0** quando sempre na tomada | Ver tabela abaixo. |
| `standby` | 1 (ativado) | 0 | Standby = transição para deep hibernation após `standbydelay`. Desativar evita Mac dormir profundamente sob daemon. |
| `standbydelay` | 10800 (3h) | irrelevante se standby=0 | — |
| `tcpkeepalive` | 1 | 1 | Mantém conexões TCP durante sleep curto (Power Nap). |
| `powernap` | 1 | 1 | Permite tarefas de manutenção durante sleep. |
| `autopoweroff` | 1 | 0 | Auto power off após delay longo; desativar. |

#### `hibernatemode` em detalhe

| Valor | O que faz | Adequado para Atlas? |
|---|---|---|
| `0` | RAM-only sleep. Conteúdo da RAM mantido, nada escrito em disco. Wake instantâneo. Se bateria zerar, perde estado. | **Sim**, quando Mac está sempre plugado. Wake mais rápido, menos wear no SSD. |
| `3` | Safe sleep (default laptop). RAM mantida + imagem escrita em disco. Wake rápido se bateria OK; recupera de disco se bateria zerou. | OK, mas overhead de escrita a cada sleep. |
| `25` | Deep hibernation. RAM desligada, tudo em disco. Wake lento (~10s+). | **Não.** Nesse modo, daemon não roda durante sleep — quando Mac volta, daemon precisa re-bootstrap completo. Quebra modelo "sempre vivo". |

### 5.3 Por que daemon NÃO modifica em runtime

- `pmset` para mudar settings requer `sudo`. Daemon é LaunchAgent user-level; pedir sudo a cada runtime é não-iniciante.
- Settings de power são responsabilidade do operador (script de instalação), não do daemon. Separação de concerns: daemon gerencia comportamento dinâmico; setup gerencia config estática.
- Schedule de wake é a única exceção: pode ser configurado uma vez via setup, ou re-aplicado pelo daemon usando helper privilegiado se houver mudança planejada (ex.: ritual move de 08:45 para 09:00).

### 5.4 Verificação de drift

No startup, daemon executa `pmset -g` (sem sudo, leitura é pública), parseia, e compara com o esperado. Se houver desvio (ex.: usuário mexeu manualmente em System Settings), loga warning:

```swift
func auditPmsetConfig() {
    let process = Process()
    process.launchPath = "/usr/bin/pmset"
    process.arguments = ["-g"]
    let pipe = Pipe()
    process.standardOutput = pipe
    try? process.run()
    process.waitUntilExit()
    let data = pipe.fileHandleForReading.readDataToEndOfFile()
    guard let output = String(data: data, encoding: .utf8) else { return }

    if output.contains("hibernatemode         3") {
        Log.daemon.warning("hibernatemode is 3, expected 0; run setup script to fix")
    }
    // ... outras checagens
}
```

---

## 6. IOPowerSources — battery monitoring

API para snapshot do estado de fontes de energia (AC, bateria interna, UPS). Daemon consulta periodicamente e reage a callbacks de mudança.

### 6.1 Snapshot

```swift
import IOKit.ps

struct PowerSnapshot {
    let onAC: Bool
    let isCharging: Bool
    let percent: Int
}

func currentPowerState() -> PowerSnapshot? {
    guard let blob = IOPSCopyPowerSourcesInfo()?.takeRetainedValue(),
          let sources = IOPSCopyPowerSourcesList(blob)?.takeRetainedValue() as? [CFTypeRef]
    else { return nil }

    for source in sources {
        guard let info = IOPSGetPowerSourceDescription(blob, source)?.takeUnretainedValue() as? [String: Any]
        else { continue }

        let state = info[kIOPSPowerSourceStateKey] as? String
        let onAC = (state == kIOPSACPowerValue)
        let isCharging = info[kIOPSIsChargingKey] as? Bool ?? false
        let cur = info[kIOPSCurrentCapacityKey] as? Int ?? 0
        let max = info[kIOPSMaxCapacityKey] as? Int ?? 100
        let pct = max > 0 ? (cur * 100 / max) : 0

        return PowerSnapshot(onAC: onAC, isCharging: isCharging, percent: pct)
    }
    return nil
}
```

### 6.2 Callback em mudanças

`IOPSCreateLimitedPowerNotification` chama callback quando há transição AC ↔ Battery. Para mudanças de percentual contínuo, polling de 30–60s é suficiente (eventos finos não justificam complexidade).

### 6.3 Policy "battery_aware"

Daemon configurável: se `onAC == false` e `percent < threshold` (default 30%), libera assertion. Mac segue ciclo normal de sleep, Atlas backend perde processo continuum até re-plug. Decisão consciente: **não vale queimar bateria por presença persistente quando usuário esqueceu de plugar.**

```swift
func evaluateBatteryPolicy(_ snap: PowerSnapshot) {
    if !snap.onAC && snap.percent < config.batteryThreshold {
        Log.power.notice("battery low (\(snap.percent)%) and on battery; releasing assertion")
        reasonRegistry.suppress(.batteryAware)
    } else {
        reasonRegistry.allow(.batteryAware)
    }
}
```

---

## 7. CGEventSource — fallback de atividade local

Quando StackChan está offline ou Atlas backend ainda não conectou, o daemon precisa de uma fonte mínima de "presença" — senão fica preso em `AWAKE_LOCKED` permanente sem critério para downgrade.

### 7.1 API

```swift
import CoreGraphics

func secondsSinceLastInput() -> TimeInterval {
    return CGEventSource.secondsSinceLastEventType(
        .combinedSessionState,
        eventType: .null   // .null = qualquer tipo de evento
    )
}
```

`combinedSessionState` agrega input de todas as fontes (teclado, mouse, trackpad, touchbar). `.null` como `eventType` significa "qualquer evento" — é a forma documentada de obter idle time global.

### 7.2 Uso no daemon

```swift
func evaluateLocalIdle() {
    let idle = secondsSinceLastInput()
    if idle > config.localIdleThreshold {
        // Sem StackChan, sem input local há muito tempo → liberar assertion
        reasonRegistry.suppress(.recentInteraction)
    } else if idle < 60 {
        reasonRegistry.allow(.recentInteraction)
    }
}
```

Polling de 30s no RunLoop é suficiente.

### 7.3 Privacy

`CGEventSource.secondsSinceLastEventType` retorna **apenas o intervalo desde o último evento**. Não vê tipo de tecla, posição do mouse, conteúdo, app focado, nada. Não requer Accessibility permission. É a API explicitamente desenhada para detecção de idle sem invasão.

---

## 8. RunLoop e threading

### 8.1 Daemon como CommandLine tool

`main.swift` precisa manter o processo vivo e processar callbacks. Sem RunLoop, o processo termina após `main()` retornar e nenhum NSWorkspace callback dispara.

```swift
// Sources/AtlasHostDaemon/main.swift
import Foundation

let daemon = Daemon()
daemon.start()

// Mantém processo vivo, processa eventos do RunLoop principal
RunLoop.main.run()
```

Alternativa: `dispatchMain()` (de `Dispatch`) — entra em loop de GCD principal. Equivalente prático para esse caso. Preferência: `RunLoop.main.run()` por compatibilidade com NSWorkspace que é Foundation/AppKit-bound.

### 8.2 Concorrência

| Componente | Thread / fila | Justificativa |
|---|---|---|
| NSWorkspace callbacks | main thread (RunLoop) | API exige. |
| Socket reader | dedicated `DispatchQueue.global()` task | I/O bloqueante, não pode ocupar main. |
| Battery polling | timer no RunLoop principal | Baixa frequência, OK na main. |
| Idle polling | timer no RunLoop principal | Idem. |
| ReasonRegistry | `actor` Swift concurrency | Acesso de múltiplos contextos (socket, callbacks, timers). |

### 8.3 ReasonRegistry como actor

```swift
actor ReasonRegistry {
    private var active: Set<Reason> = []

    func add(_ r: Reason) -> Bool {
        let inserted = active.insert(r).inserted
        return inserted
    }

    func remove(_ r: Reason) -> Bool {
        return active.remove(r) != nil
    }

    func snapshot() -> Set<Reason> {
        return active
    }

    func summary() -> String {
        return active.map(\.rawValue).sorted().joined(separator: "+")
    }
}
```

`actor` garante exclusão mútua sem locks manuais. Custo: chamadas viram `await`. Aceitável — registry é hot path mas não crítico em latência (escala de ms é ok).

---

## 9. Logging — os.log

Substituir `print` e `NSLog` por `Logger` do framework `os`. Vantagens: structured logging, filtering por subsystem/category, persistência via `log show`, privacy redaction nativa.

### 9.1 Setup

```swift
// Sources/AtlasHostDaemon/Logging/Log.swift
import os.log

enum Log {
    private static let subsystem = "com.atlas.host-daemon"

    static let daemon         = Logger(subsystem: subsystem, category: "daemon")
    static let wakeLock       = Logger(subsystem: subsystem, category: "wake_lock")
    static let sleepObserver  = Logger(subsystem: subsystem, category: "sleep_observer")
    static let atlasConnector = Logger(subsystem: subsystem, category: "atlas_connector")
    static let policy         = Logger(subsystem: subsystem, category: "policy")
    static let power          = Logger(subsystem: subsystem, category: "power")
}
```

### 9.2 Uso

```swift
Log.wakeLock.info("assertion held id=\(id) reasons=\(summary, privacy: .public)")
Log.atlasConnector.error("socket accept failed: \(error.localizedDescription, privacy: .public)")
Log.policy.debug("transition \(from.rawValue) → \(to.rawValue)")
```

Privacy: por padrão, valores interpolados são marcados `.private` e aparecem como `<private>` em logs. Strings operacionais (sem dado do usuário) devem ser explicitamente `.public` para ficarem visíveis.

### 9.3 Leitura

```bash
# Última hora de logs do daemon
log show --predicate 'subsystem == "com.atlas.host-daemon"' --last 1h

# Stream em tempo real
log stream --predicate 'subsystem == "com.atlas.host-daemon"'

# Apenas erros e mais sérios
log show --predicate 'subsystem == "com.atlas.host-daemon" AND messageType >= 16' --last 24h

# Categoria específica
log show --predicate 'subsystem == "com.atlas.host-daemon" AND category == "wake_lock"' --last 1h
```

Logs persistem no sistema unified logging do macOS (`/var/db/diagnostics`); sobrevivem a restart do daemon e do Mac.

---

## 10. Permissões e entitlements

### 10.1 Escopo do processo

| Vetor | Status |
|---|---|
| LaunchAgent (user-level) | **Sim.** Roda no contexto do usuário, escopo de sessão. |
| LaunchDaemon (system-level) | Não. Não precisa rodar antes do login, não toca recursos system-wide. |
| Sandbox | Não. CommandLine tool standalone não sandboxed. |
| Hardened Runtime | Recomendado se houver code signing. |

### 10.2 Permissões por API

| API | Permissão necessária |
|---|---|
| IOPMAssertion | Nenhuma especial. User-level OK. |
| NSWorkspace notifications | Nenhuma. |
| `pmset -g` (read) | Nenhuma. |
| `pmset` (write) | sudo. **Daemon não faz em runtime** — script de setup faz. |
| IOPowerSources | Nenhuma. |
| CGEventSource (idle time) | Nenhuma. **Não confundir** com CGEventTap (que requer Accessibility). |
| Unix socket em `~/Library/Application Support/Atlas/host-daemon.sock` | Nenhuma — escopo do user. |

### 10.3 O que daemon NÃO requer

- Accessibility permission.
- Full Disk Access.
- Screen Recording.
- Input Monitoring.
- Automation (controle de outros apps).

Esta é uma feature, não acidente: o daemon é projetado para ser auditável e mínimo. Se uma futura funcionalidade exigir uma dessas, é sinal forte de que está em escopo errado e deve viver no Atlas backend, não no daemon.

### 10.4 Code signing

Para distribuição interna (apenas o autor instalando no próprio Mac): code signing **não é obrigatório**. Gatekeeper aceita binários sem signature mediante confirmação manual no primeiro launch.

Recomendado mesmo assim:

```bash
codesign --sign "Developer ID Application: <Nome>" \
  --options runtime \
  --timestamp \
  .build/release/AtlasHostDaemon
```

Razões: evita prompts, prepara terreno se um dia houver instalação em outras máquinas, expõe se binário foi tampered.

---

## 11. Build e tamanho

```bash
# Build release otimizado
swift build -c release

# Binário fica em .build/release/AtlasHostDaemon
ls -lh .build/release/AtlasHostDaemon

# Strip de symbols para distribuição
strip .build/release/AtlasHostDaemon
```

Tamanhos esperados:

| Build | Tamanho típico |
|---|---|
| Debug | 8–12 MB |
| Release sem strip | 5–7 MB |
| Release stripped | 3–5 MB |

Cold start: ~50–100ms até RunLoop estável. Memória residente em idle: ~8–12 MB. CPU em idle: < 0.1%. Esses números servem como baseline — qualquer regressão significativa em build futuro deve ser investigada.

---

## 12. Anti-padrões

| Anti-padrão | Por que evitar |
|---|---|
| Modificar `pmset` settings em runtime via daemon | Requer sudo; acopla daemon a privilégio elevado; quebra separação setup/runtime. |
| Usar `kIOPMAssertionTypeNoIdleSleep` (legacy) | Substituída pela family `Prevent...`; pode ter comportamento inconsistente em macOS futuras. |
| Manter múltiplas assertions concorrentes do mesmo processo | Aumenta complexidade de auditoria; uma assertion com nome dinâmico é suficiente. |
| Registrar callbacks em `NotificationCenter.default` esperando eventos de sleep | Sleep events vivem em `NSWorkspace.shared.notificationCenter`. Erro silencioso: callback nunca dispara. |
| Bloquear na main thread em callback de sleep observer | Atrasa SLEEP_PREP, pode ultrapassar janela de 30s. Toda lógica não-trivial vai para fila secundária. |
| Incluir conteúdo cognitivo (prompts, IDs de sessão) na string de assertion | Vaza para `pmset -g assertions` e logs do sistema. Daemon vê apenas metadado operacional. |
| Adicionar dependências externas (Vapor, SwiftNIO, etc.) | Daemon precisa rodar por anos sem manutenção. Toda dep externa é vetor de breakage. SDK Apple é suficiente. |
| Usar `print` ou `NSLog` em vez de `os.log` | Sem subsystem/category, logs ficam impossíveis de filtrar; sem privacy redaction; sem persistência confiável. |
| Tentar usar CGEventTap para monitorar input | Requer Accessibility permission; viola princípio "daemon mínimo"; `CGEventSource.secondsSinceLastEventType` resolve sem permissão. |
| Esquecer de liberar assertion em `willSleepNotification` quando sleep é justificado | Kernel honra a assertion; Mac não dorme; usuário fica confuso ao fechar lid e ver máquina não suspender. |

---

## 13. Decisões pendentes

| ID | Decisão | Default provisório |
|---|---|---|
| HD-API-01 | `IOPMAssertionSetProperty(kIOPMAssertionNameKey)` é confiável em Sonoma+? Se não, usar release+create. | Tentar in-place; fallback automático. Confirmar em testes. |
| HD-API-02 | Targetar macOS 13 (Ventura) ou 14 (Sonoma) como mínimo? Sonoma traz APIs de Logger melhores; Ventura amplia base. | Ventura (13.0). Reavaliar se features de Sonoma justificarem. |
| HD-API-03 | `hibernatemode 0` é seguro mesmo em laptop sempre plugado, ou manter 3 por safety net se UPS falhar? | `0` enquanto Mac vive em local com UPS confiável; `3` como configuração mais conservadora se ambiente for incerto. |
| HD-API-04 | Polling de bateria (30s) ou callback `IOPSCreateLimitedPowerNotification`? Callback é mais elegante mas APIs CFRunLoopSource adicionam complexidade. | Polling 30s no MVP; migrar para callback se pressão de eficiência aparecer. |
| HD-API-05 | Threshold default de bateria para suspender assertions: 30% é razoável? | 30%. Tunável via config. |
| HD-API-06 | Code signing com Developer ID obrigatório a partir de qual fase? | MVP roda sem signing. Adicionar antes de qualquer eventual distribuição além da máquina do autor. |

---

## 14. Próximos passos de leitura

1. `01-visao-geral.md` — visão geral do daemon, estados, reasons (revisitar com APIs em mente).
2. `04-protocolo-atlas-audit.md` — formato das mensagens entre Atlas backend e daemon, comandos `add_reason` / `remove_reason` / `pre_sleep_ack`.
3. `01-visao-geral.md` (catálogo de razões) — como reasons combinadas determinam transição entre estados; quando suprimir / quando segurar.
4. `03-configuracao-lifecycle.md` — `Package.swift` completo, plist do LaunchAgent, script de setup com `pmset` calls e configuração de wake schedule.
5. `05-edge-cases-testes.md` (métricas em produção) — métricas exportadas pelo daemon, comandos `log show` úteis, integração com Atlas Curator para auditoria.
