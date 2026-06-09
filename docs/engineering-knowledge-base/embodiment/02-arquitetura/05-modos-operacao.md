---
id: atlas-embodiment-02-arquitetura-05-modos-operacao
type: engineering_knowledge
title: "05 — Modos de operação"
status: source_material
authority_class: design_reference
implementation_state: design_reference_no_runtime
category: physical-surface
summary: "Source material for the Atlas Embodiment/StackChan physical surface design; not current runtime authority."
canonical_owner: docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md
---
# 05 — Modos de operação

> **Propósito:** especificar os **modos operacionais** do corpo — estados macro que determinam quais modalidades estão ativas, como loops temporais se comportam, como o Atlas pode interagir. Modo é a configuração macro; tudo abaixo respeita.
>
> **Pré-requisitos:** [README](../README.md), [02-loops-temporais.md](02-loops-temporais.md), [03-modalidades.md](03-modalidades.md), [04-protocolos/03-comandos-fisicos.md](../04-protocolos/03-comandos-fisicos.md), [05-policies/01-privacidade.md](../05-policies/01-privacidade.md).
>
> **Fora do escopo:** privacy detalhada (vai pra `05-policies/01-privacidade.md`), implementação dos modos (vai pra `06-firmware-stackchan/`).

---

## 1. Por que modos existem

Sem modos, cada situação exigiria configurar dezenas de modalidades individualmente. Modo é **preset coerente** — uma configuração testada que faz sentido junta.

Outro motivo: modo é **comunicável**. "O Atlas está em modo private" é mais claro do que enumerar dez flags.

Princípio: **modo é um eixo; privacy é outro.** Combinam-se. Detalhes na seção 7.

---

## 2. Catálogo de modos

| Modo | Finalidade |
|---|---|
| `ambient` | Default. Presença passiva + responsividade normal. |
| `interaction` | Usuário ativamente conversando — mais rápido, mais brilhante. |
| `do_not_disturb` (`dnd`) | Sem interrupções — só aceita comandos diretos. |
| `private` (privado lógico) | Captura cognitiva limitada; modo de policy. |
| `private_physical` | Mute físico de hardware — ver `05-policies/01-privacidade.md`. |
| `degraded` | Sem alma. Reflex layer só. |
| `standby` | Display off, baixo consumo. Acorda por estímulo forte. |

`private` e `private_physical` são tratados juntos por dependerem de policy de privacidade. Aqui foco em `ambient`, `interaction`, `dnd`, `degraded`, `standby`.

---

## 3. Modo `ambient`

### Definição
Modo padrão. Robô na mesa, ligado, conectado. Não há interação ativa, mas está pronto pra reagir.

### Comportamento

| Aspecto | Estado |
|---|---|
| Display | Face neutra + info-cards rotacionais |
| LED | Azul calmo respirando |
| Servo | Idle pattern (frequência baixa) |
| Mic (wake word) | ON |
| Mic streaming | OFF até wake word |
| Câmera presença | ON |
| Câmera frame | OFF |
| Touch | ON |
| NFC | ON |
| Heartbeat scheduled (L4) | ON — rituais agendados disparam |
| Curator proposals | Permitidos via interruption_policy |

### Triggers de saída
- Wake word → transiciona para `interaction`.
- Touch deliberado (não-acidental) → transiciona para `interaction`.
- Comando da alma (`system.set_mode`) → modo solicitado.
- Toque combinado (3 pads 2s) → `private_physical`.
- Perda de conexão → `degraded`.
- Inatividade prolongada (config, ex: 1h sem presença) → `standby`.

### Princípio
Ambient é o estado da maior parte do tempo. Tem que ser **silencioso, presente, eficiente em bateria**.

---

## 4. Modo `interaction`

### Definição
Usuário ativamente engajado. Pergunta foi feita, comando dado, conversação aberta.

### Comportamento

| Aspecto | Estado |
|---|---|
| Display | Face informativa, brilho normal a alto |
| LED | Conforme estado do pipeline (verde/amarelo/vermelho) |
| Servo | Atento — vira para fonte sonora, gestos durante TTS |
| Mic (wake word) | ON (mas raramente disparado, já em interação) |
| Mic streaming | Janelas curtas após cada pergunta |
| Câmera presença | ON |
| Câmera frame | OFF (a menos que comando) |
| Touch | ON (atalhos contextuais) |
| NFC | ON |
| Heartbeat scheduled (L4) | Suprimido até voltar a ambient |
| Curator proposals | Suprimidos durante interação ativa |

### Triggers de saída
- Inatividade (sem nova interação em N segundos, default 60s) → `ambient`.
- Comando explícito de fim ("obrigado", touch específico) → `ambient`.
- Comando da alma → modo solicitado.

### Princípio
Modo "atento". Mais responsivo, mais expressivo, mais bandwidth. Mas com timeout — não mantém estado de interação para sempre.

---

## 5. Modo `do_not_disturb` (`dnd`)

### Definição
Usuário pediu silêncio. Modo de foco profundo. Robô não inicia nada.

### Comportamento

| Aspecto | Estado |
|---|---|
| Display | Dim, info-card minimalista (apenas hora + status crítico) |
| LED | Off ou dim azul |
| Servo | Idle desabilitado (parado) |
| Mic (wake word) | ON (mas confiance threshold mais alto) |
| Mic streaming | Após wake word, normal |
| Câmera presença | ON |
| Câmera frame | OFF |
| Touch | ON |
| NFC | ON |
| Heartbeat scheduled (L4) | **Suprimido** — rituais não disparam |
| Curator proposals | **Suprimidos** — nada de alma falando primeiro |
| Audio TTS | Volume reduzido por padrão |
| Audio sounds | Mutados (apenas eventos críticos) |

### Triggers de entrada
- Comando explícito de voz ("Atlas, modo foco").
- Tag NFC "FOCUS".
- Touch combinado específico.
- Configuração de horário (ex.: 9h-12h em deep work).

### Triggers de saída
- Comando explícito.
- Fim de janela agendada.

### Princípio
Modo "respeite minha concentração". Robô continua pronto a responder mas **nunca inicia**. Curator espera; rituais ficam pra depois.

---

## 6. Modo `private` e `private_physical`

Detalhes em `05-policies/01-privacidade.md`. Resumo aqui:

### `private` (lógico)
- Sem captura cognitiva (mic streaming OFF mesmo após wake word).
- Sem câmera frame.
- Robô responde só a touch.
- LED amarelo curto indicativo.
- Modo software — aplicado via comando da alma com Receipt.

### `private_physical` (hardware)
- Mute físico via firmware (PMIC desliga câmera, codec desabilitado).
- Não-bypassável por comando da alma.
- Saída só via combinação física de touch.
- LED vermelho fixo todo o anel.

---

## 7. Modo `degraded`

### Definição
Conexão com a alma perdida. Reflex layer (L0) mantém presença mínima.

### Comportamento

| Aspecto | Estado |
|---|---|
| Display | Face triste/neutra fixa, ícone de "desconectado" |
| LED | Roxo respirando |
| Servo | Idle pattern muito reduzido |
| Mic (wake word) | ON local; detecta mas não tem destino |
| Mic streaming | OFF (não há para onde mandar) |
| Câmera presença | OFF |
| Câmera frame | OFF |
| Touch | ON (apenas combo de modo silencioso ainda funciona) |
| NFC | OFF |
| Heartbeat scheduled (L4) | N/A (alma fora) |
| Curator proposals | N/A |
| Telemetria local | Continua coletando para enviar quando reconectar |

### Reação a wake word em degraded
- Beep curto + face de "desconectado" + LED flash roxo.
- **Sem fingimentos.** Não tenta responder localmente.

### Triggers de saída
- Reconexão estabelecida com a alma → volta para `ambient` (ou último modo conhecido).

### Princípio
Modo "estou vivo, mas sem alma". Dignidade visível. Não improvisa. Detalhes em `05-policies/04-degradacao.md` (a escrever).

---

## 8. Modo `standby`

### Definição
Baixo consumo. Robô pode ficar parado por tempo longo (ex.: noite, viagem, abandono temporário).

### Comportamento

| Aspecto | Estado |
|---|---|
| Display | Off |
| LED | Off |
| Servo | Posição parking, parado |
| Mic (wake word) | ON ou OFF (configurável) |
| Mic streaming | OFF |
| Câmera presença | OFF |
| Câmera frame | OFF |
| Touch | ON |
| NFC | OFF |
| Heartbeat scheduled (L4) | Suprimido (mas rituais críticos como bom dia podem disparar) |
| Conexão WS | Mantida; heartbeat reduzido |

### Triggers de entrada
- Inatividade prolongada (ex.: 1h sem presença ou interação).
- Comando explícito ("modo descanso", touch combo, scheduled).
- Bateria baixa.

### Triggers de saída
- Wake word (se ON em standby).
- Touch.
- Presence detected (câmera ligada periodicamente para sample? — decisão pendente).
- Comando da alma.
- Ritual agendado importante (ex.: bom dia 9h liga display).

⚠️ **DECISÃO PENDENTE:** se câmera presença liga periodicamente em standby (ex.: a cada 30s) ou desliga totalmente (mais privacy, menos responsividade).

### Princípio
Standby ≠ desligado. Pronto a despertar mas economizando. Detalhes de bateria em `01-hardware/02-limitacoes-fisicas.md`.

---

## 9. Combinação modo + privacy

Modo e privacy são eixos independentes, mas com **regras de coerência**:

| Modo \ Privacy | `default` | `restricted` | `private_physical` |
|---|---|---|---|
| `ambient` | OK | OK | OK (privacy domina) |
| `interaction` | OK | Mic streaming bloqueado — degrada para touch-only | OK (privacy domina, sem captura) |
| `dnd` | OK | OK | OK (privacy domina) |
| `degraded` | OK | OK | OK (privacy domina, captura já off) |
| `standby` | OK | OK | OK (privacy domina) |

**Privacy sempre vence.** Modo nunca pode forçar captura se privacy bloqueia.

---

## 10. Diagrama de estados

```
                  ┌─────────────┐
                  │   STANDBY   │
                  └──────┬──────┘
                         │ (wake / touch / scheduled)
                         ▼
   ┌──────────────► AMBIENT ◄──────────────────┐
   │                  │  ▲                     │
   │  (timeout)       │  │ (timeout 60s)       │
   │                  ▼  │                     │
   │           INTERACTION                     │
   │                  │                        │
   │  (cmd: dnd)      │                        │
   ▼                  │                        │
  DND ◄──────(cmd)────┘                        │
   ▲                                           │
   │ (cmd: ambient)                            │
   │                                           │
   ┌─────────────────────────────────┐         │
   │ Disconnect detected             │         │
   ▼                                 │         │
DEGRADED ──── (reconnect) ────────────┘        │
                                               │
   ┌──────────── 3-pad hold 2s ─────────────────┘
   ▼
PRIVATE_PHYSICAL ◄── (3-pad hold 2s) ── any mode
```

Transições adicionais:
- Qualquer modo → `degraded` (perda de conexão).
- `degraded` → modo previamente ativo (ao reconectar).

---

## 11. Quem manda em modo

| Trigger | Pode mudar para |
|---|---|
| Comando da alma (`system.set_mode`) com Receipt | Qualquer (exceto out de `private_physical`) |
| Wake word | `ambient` → `interaction` |
| Inatividade | `interaction` → `ambient`, `ambient` → `standby` |
| Touch combinado (3 pads 2s) | Qualquer → `private_physical`, `private_physical` → previous |
| Touch direto | `standby` → `ambient` |
| Perda de conexão | Qualquer (exceto `private_physical`) → `degraded` |
| Reconexão | `degraded` → previous |
| Tag NFC mapeada | Conforme mapeamento (ex.: "FOCUS" → `dnd`) |
| Ritual agendado | `standby` → `ambient` (apenas para rituais críticos) |

**Regra dura:** `private_physical` só sai por gesto físico. Comando da alma não tira.

---

## 12. Audit de mudanças de modo

Toda transição de modo gera evento no Evidence Ledger:

```yaml
event:
  type: surface.mode.changed
  surface_id: stackchan_main
  from: ambient
  to: dnd
  triggered_by: voice_command   # ou: touch, nfc, scheduler, alma_command, inactivity, ...
  triggered_at: 2026-05-05T11:30:14Z
  decision_receipt_ref: rcpt-... (se aplicável)
```

Isso permite:
- Curator analisar padrões de uso por modo.
- Detectar transições anômalas.
- Construir perfil temporal (quanto tempo em cada modo).

---

## 13. Casos de uso típicos

### 13.1 Manhã de trabalho
- 9h: heartbeat ritual "bom dia" → `standby` → `ambient`.
- 9h05: usuário senta → `ambient` continua, mais info-cards.
- 9h30: usuário fala "modo foco" → `ambient` → `dnd`.
- 12h: ritual de check (mas suprimido em dnd; registrado como pendente).
- 12h30: usuário tira tag de FOCUS → `dnd` → `ambient`.
- Pergunta "como tá meu dia?" → `ambient` → `interaction` → response → `interaction` aguarda 60s → volta para `ambient`.

### 13.2 Reunião confidencial
- Antes da reunião: tap NFC "PRIVATE" → `private_physical`.
- LED vermelho durante toda reunião.
- Ao fim: 3-pad hold → sai de `private_physical`.

### 13.3 Final do dia
- 22h: presença ausente há 2h → `ambient` → `standby`.
- Display off, LED off.
- Robô fica em standby pela noite.
- 9h: ritual disparou → `standby` → `ambient`.

### 13.4 Wi-Fi caiu
- WS heartbeat falha (90s).
- Em `ambient`: → `degraded`.
- LED roxo, face triste.
- 30 minutos depois: Wi-Fi volta, conexão restabelecida.
- `degraded` → `ambient` (volta ao último modo conhecido).

---

## 14. Anti-padrões

| Anti-padrão | Por quê |
|---|---|
| Adicionar modo ad-hoc sem documentar | Vocabulário se torna cacofonia |
| Comando da alma forçando saída de `private_physical` | Quebra garantia P1 (privacy hardware) |
| Mudança silenciosa de modo (sem indicação visual) | Usuário perde controle |
| Múltiplos modos simultâneos | Modo é eixo único — privacy é outro eixo |
| Ritual disparando interação em `dnd` | Quebra contrato de DND |
| Standby + wake word ON mesmo com bateria muito baixa | Gasta o resto; melhor sleep profundo |
| Mudança de modo sem evento no Ledger | Audit perdido |

---

## 15. Decisões pendentes

| Decisão | Bloqueia |
|---|---|
| ⚠️ Câmera presença em standby (sample periódico ou off total) | Implementação standby |
| ⚠️ Timeout de interaction → ambient (60s? configurável?) | UX |
| ⚠️ Lista de rituais críticos que disparam mesmo em standby | L4 |
| ⚠️ Combinação física exata para `private_physical` | Implementação |
| ⚠️ Modo "travel" (sem expectativa de alma)? Faz sentido ou degraded basta? | Roadmap |

---

## Próximos passos de leitura

- `04-protocolos/02-eventos-evidence.md` — eventos de mudança de modo.
- `05-policies/01-privacidade.md` — privacy modes e como combinam.
- `05-policies/04-degradacao.md` — degraded mode em detalhe (a escrever).
- `06-firmware-stackchan/02-reflex-layer.md` — implementação local dos modos.
