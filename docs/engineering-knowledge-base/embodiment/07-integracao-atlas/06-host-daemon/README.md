---
id: atlas-embodiment-07-integracao-atlas-06-host-daemon
type: engineering_knowledge
title: "Atlas Host Daemon — módulo"
status: source_material
authority_class: design_reference
implementation_state: design_reference_no_runtime
category: physical-surface
summary: "Source material for the Atlas Embodiment/StackChan physical surface design; not current runtime authority."
canonical_owner: docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md
---
# Atlas Host Daemon — módulo

> **Propósito:** documentação completa do **Atlas Host Daemon** — componente da integração Atlas que gerencia o ciclo de sleep/wake do macOS, substituindo o `caffeinate` manual com gerenciamento inteligente baseado em sinais reais (presença, pipeline ativo, rituais agendados).
>
> **Pré-requisitos:** [README mestre](../../README.md), [02-arquitetura/01-corpo-vs-alma.md](../../02-arquitetura/01-corpo-vs-alma.md), [01-surface-adapter.md](../01-surface-adapter.md).
>
> **Fora do escopo:** firmware do StackChan (vai pra `../../06-firmware-stackchan/`), Atlas core (assumido como dado).

---

## O problema que este módulo resolve

Atlas backend roda no MacBook do usuário. Mac entra em sleep → Atlas dorme junto. Hoje o usuário usa `caffeinate` manualmente e esquece. Após avaliação de alternativas (USB HID wake, cloud relay, mini-server, VPS-hosted Atlas), a escolha foi:

> **Manter Mac sempre acordado plugado, com daemon dedicado que gerencia wake locks dinamicamente baseado em sinais reais.**

Custo de energia: ~R$30-40/ano. Trivial. Em troca: zero fricção cognitiva, zero esquecimento, comportamento determinístico.

---

## Arquitetura em uma frase

**Atlas Host Daemon** = Swift LaunchAgent (~500 linhas) que:
- Recebe sinais via Unix socket do Atlas backend
- Mantém um conjunto dinâmico de razões para wake lock
- Cria/libera `IOPMAssertion` conforme o conjunto não esteja vazio
- Reage a `NSWorkspace.willSleep`/`didWake` notificando Atlas (e via Atlas, o StackChan)
- Não vê nenhum conteúdo cognitivo — só metadata operacional

---

## Índice deste módulo

Os 5 documentos abaixo cobrem o módulo end-to-end. **Leitura sequencial recomendada na primeira passada.**

### [01 — Visão Geral](01-visao-geral.md)

Definição precisa, motivação, princípios fundadores (P1-P7), arquitetura interna (componentes do daemon), estados e transições, catálogo de razões, relação com Atlas/StackChan/macOS, não-objetivos, privacy posture.

**Leitura:** ~15 min. **Foco:** entender *o que é* e *por que existe*.

### [02 — APIs do macOS](02-apis-macos.md)

Deep-dive técnico nas APIs do macOS: `IOPMAssertion` (lifecycle, tipos, audit), `NSWorkspace` notifications, `pmset` (schedule e settings), `IOPowerSources` (battery), `CGEventSource` (fallback), RunLoop e threading, `os.log`, permissões e build.

**Leitura:** ~25 min. **Foco:** referência para implementação.

### [03 — Configuração e Lifecycle](03-configuracao-lifecycle.md)

Manual operacional: LaunchAgent vs LaunchDaemon, plist completo, comandos `launchctl`, setup script, config YAML completo, hot reload via SIGHUP, lifecycle (boot/operação/sleep/wake), restart, uninstall, troubleshooting.

**Leitura:** ~25 min. **Foco:** instalar, configurar, manter.

### [04 — Protocolo & Audit](04-protocolo-atlas-audit.md)

Especificação do protocolo daemon ↔ Atlas backend: Unix socket transport, formato JSON line-delimited, handshake, catálogo de mensagens em ambas direções (com schemas completos), eventos no Evidence Ledger, coordenação com StackChan via Atlas, versionamento, reconnect strategy.

**Leitura:** ~30 min. **Foco:** contrato entre componentes.

### [05 — Edge Cases & Testes](05-edge-cases-testes.md)

Catálogo de edge cases (~30 cenários: bateria, sleep/wake, Atlas/StackChan, daemon próprio, sistema, multi-corpo), estratégia de testes em 4 camadas (unit/integration/system/long-running), critérios de aceitação mensuráveis, métricas em produção, anti-padrões.

**Leitura:** ~20 min. **Foco:** garantia de robustez.

---

## Como ler conforme o objetivo

| Objetivo | Documentos prioritários |
|---|---|
| Entender por que existe | 01 |
| Implementar do zero | 01 → 02 → 03 → 04 |
| Operar/instalar/manter | 01 → 03 |
| Integrar Atlas core ao daemon | 01 → 04 |
| Validar/testar | 01 → 05 |
| Auditar segurança/privacidade | 01 (Privacy posture) → 04 (Privacy considerations) → 05 (Anti-padrões) |
| Diagnosticar problema em produção | 03 (Troubleshooting) → 04 (Eventos) → 05 (Edge cases) |

---

## Caminho rápido — TL;DR técnico

1. **Linguagem:** Swift, LaunchAgent (não LaunchDaemon).
2. **APIs centrais:** `IOPMAssertion` (IOKit) + `NSWorkspace.shared.notificationCenter` + `pmset` (uma vez no install).
3. **Transporte com Atlas:** Unix socket (`/tmp/atlas-host.sock`), JSON line-delimited.
4. **Razões:** 6 fixas (presence_active, pipeline_active, stream_open, ritual_lookahead, recent_interaction, manual_lock). Cada uma com TTL.
5. **Wake lock:** criado quando conjunto de razões não está vazio; liberado quando esvazia.
6. **Estados:** AWAKE_LOCKED / AWAKE_UNLOCKED / SLEEP_PREP / MAC_SLEEPING / MAC_WAKING.
7. **Sleep imminent → daemon avisa Atlas → Atlas avisa StackChan → graceful degradation.**
8. **Wake → daemon avisa Atlas → StackChan volta para ambient.**
9. **Privacy:** daemon vê apenas booleans, numbers, strings de razão. Sem áudio, sem imagem, sem mensagens.
10. **Tamanho do binário:** ~3-5MB. **Memory footprint:** <20MB. **CPU idle:** ~0%.

---

## Princípios de quem mantém este módulo

Resumidos dos 5 documentos:

- **Daemon não decide.** Recebe sinais, gerencia assertions. Princípio "Surface não decide" estendido.
- **Toda razão tem TTL.** Sem razão eterna. Vazamento = bug.
- **Atlas é a única ponte para StackChan.** Daemon nunca fala direto com o robô.
- **Sleep válido é OK.** Mac dormir não é falha — só não pode ser quando deveria estar acordado.
- **Falha segura é dormir.** Se algo dá errado, libera lock; melhor deixar Mac dormir do que segurar acordado por engano.
- **Auditável end-to-end.** Cada wake lock, cada razão, cada transição vai pro Evidence Ledger.

---

## Onde isso entra no roadmap

Antecipado para **[Fase 0 — Espelho](../../08-roadmap/01-fase-0-espelho.md)**.

Razão: a fricção que o daemon resolve (`caffeinate` manual esquecido) é um problema **atual** do usuário, não algo que esperaria pra fases avançadas. Custo extra na Fase 0: ~1 semana de trabalho. Benefício: elimina fricção desde o dia 1, fundação operacional sólida para todas as fases seguintes.

---

## Arquivos relacionados fora deste módulo

- [../../02-arquitetura/01-corpo-vs-alma.md](../../02-arquitetura/01-corpo-vs-alma.md) — separação corpo/alma; daemon vive na fronteira do macOS.
- [../../02-arquitetura/02-loops-temporais.md](../../02-arquitetura/02-loops-temporais.md) — loops temporais; daemon contribui sinais.
- [../../04-protocolos/02-eventos-evidence.md](../../04-protocolos/02-eventos-evidence.md) — schema dos eventos `host.*`.
- [../../04-protocolos/04-transporte.md](../../04-protocolos/04-transporte.md) — transporte StackChan ↔ Atlas (referência paralela).
- [../../05-policies/01-privacidade.md](../../05-policies/01-privacidade.md) — princípios de privacy aplicáveis.
- [../03-personalidade-ledger.md](../03-personalidade-ledger.md) — como Atlas usa eventos do daemon para construir contexto.
- [../04-curator-proposals.md](../04-curator-proposals.md) — Curator pode propor ajustes baseados em métricas do daemon.
- [../../08-roadmap/01-fase-0-espelho.md](../../08-roadmap/01-fase-0-espelho.md) — onde o módulo é entregue.
