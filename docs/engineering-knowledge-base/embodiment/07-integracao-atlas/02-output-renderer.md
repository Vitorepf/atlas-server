---
id: atlas-embodiment-07-integracao-atlas-02-output-renderer
type: engineering_knowledge
title: "02 — Output Renderer especializado (alma)"
status: source_material
authority_class: design_reference
implementation_state: design_reference_no_runtime
category: physical-surface
summary: "Source material for the Atlas Embodiment/StackChan physical surface design; not current runtime authority."
canonical_owner: docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md
---
# 02 — Output Renderer especializado (alma)

> **Propósito:** especificar o **Output Renderer especializado para a surface `stackchan`** — o componente da alma que **traduz Decision Receipt abstratos em bundles concretos de comandos físicos**, coordena modalidades (face + voz + LED + servo + card) e despacha via Surface Adapter para o corpo.
>
> **Pré-requisitos:** [README](../README.md), [01-surface-adapter.md](01-surface-adapter.md), [02-arquitetura/04-integracao-kernel.md](../02-arquitetura/04-integracao-kernel.md), [04-protocolos/03-comandos-fisicos.md](../04-protocolos/03-comandos-fisicos.md), [04-protocolos/05-streaming.md](../04-protocolos/05-streaming.md).
>
> **Fora do escopo:** o Output Renderer geral do Atlas (assumido como dado); renderização para outras surfaces.

---

## 1. Posição no pipeline

```
[ Decision Receipt ]
        ↓
[ Output Renderer (geral) ] ── decide qual specialized renderer usar
        ↓
[ Output Renderer "stackchan" ]   ◄── ESTE DOCUMENTO
        ↓
[ StackChan Persona Adapter ]     ◄── preserva voz/estilo da surface
        ↓ (compõe bundle)
[ Surface Adapter — Outbound Handler ]
        ↓ (despacha via WS)
[ Corpo / firmware ]
```

O renderer **não decide nada novo**. Recebe Receipt já tomada, com `output_intent` semântico, e materializa em comandos concretos.

---

## 2. Princípios

### P1 — Receipt é fonte da verdade
Todo bundle físico tem `decision_receipt_ref` apontando para Receipt que autorizou. Sem Receipt, nada sai.

### P2 — Renderer é traditor, não decisor
Receipt diz "responda positivamente com indicação visual". Renderer escolhe **qual** expressão facial, **qual** padrão LED, **qual** gesture — mas dentro de **catálogo fechado** mapeado por configuração (não decisão livre).

### P3 — Coordenação multimodal é responsabilidade central
Bundle bem coordenado é a diferença entre "robô vivo" e "componentes piscando independentes".

### P4 — Privacy compliance antes de despacho
Cada bundle passa por gates antes de despacho. Falhar = Receipt falha (Repair Loop entra).

### P5 — Streaming first
Para TTS, comando é "começa stream". Áudio chega depois via media channel — não bloqueia outras saídas.

### P6 — Persona de superfície sem cognição paralela
O renderer passa a resposta já decidida pelo Atlas pelo `StackChan Persona Adapter`. O adapter pode ajustar fala, ritmo e sinais físicos, mas não pode mudar fatos, decisão, policy ou provider routing.

---

## 3. Input — Decision Receipt

Receipt típica que chega ao renderer especializado:

```yaml
decision_receipt:
  receipt_id: rcpt-...
  decision_made_at: ...
  target_surfaces: ["stackchan_main"]
  output_intent:
    primary_modality: voice         # voice | visual | mixed | silent
    duration_hint_ms: 3500
    importance: medium               # low | medium | high
    interruptible: true
    sentiment: neutral_positive      # influencia expressão facial
    urgency: normal                  # normal | alert
  domain_context:
    active_domain: programming
    domain_state: completed_query
  consent_tags: []                   # se aplicável
  privacy_constraints:
    - require_indication: false
    - cannot_use: []
  content:
    title: "Build status"
    body: "Project X passing — 12 commits hoje."
    icon: check_circle
    speech_text: "Build do X tá passando. Doze commits hoje."
    spoken_style: stackchan_default
    voice_profile: stackchan_default
    streaming_token_source: "..."   # ref para streaming TTS
```

Renderer lê e compõe bundle apropriado.

---

## 4. Mapeamento Receipt → Bundle

### 4.1 Por `output_intent.primary_modality`

| Modalidade | O que o bundle inclui |
|---|---|
| `voice` | face_render + audio.tts_stream + led + servo gesture |
| `visual` | face_render + card_render + led; sem voz |
| `mixed` | tudo (voz + visual juntos) — caso default para queries |
| `silent` | face_render apenas; sem som; LED muda subtilmente |

### 4.2 Por `domain_context.active_domain`

Cada Domain tem mapeamento (ver `05-domain-faces.md`):

| Domain | Expression default | Palette | LED color |
|---|---|---|---|
| programming | `attentive` | `focused` | blue calm |
| finance | `informative` | `focused` | teal |
| personal_dev | `warm_attentive` | `warm` | warm orange |
| marketing | `informative` | `default` | magenta soft |
| self_improvement | `concerned_warm` | `warm` | purple-blue |

### 4.3 Por `domain_state`

| State | Modificações no bundle |
|---|---|
| `processing` | LED breathing; face thinking |
| `completed_query` | face informative; LED green pulse curto pós-resposta |
| `gate_failed` | face concerned; LED amarelo pulsante |
| `repair_active` | LED amarelo pulsante mais rápido; face concerned-thinking |
| `awaiting_user` | LED orange pulse (proposal-style); face attentive |

### 4.4 Por `output_intent.urgency`

| Urgency | Modificações |
|---|---|
| `normal` | Default |
| `alert` | LED red pulse; face concerned; servo alert_stance; voz mais firme |

⚠️ **Restrição:** LED red **fixo** é reservado para captura/privacy. `alert` usa red pulse — distinguível.

---

## 5. Composição de bundle típico

Receipt → bundle:

```yaml
bundle:
  bundle_id: bnd-...
  decision_receipt_ref: rcpt-...
  execution: parallel
  commands:
    # Visual base
    - type: display.show_face
      payload:
        expression: attentive       # determinado por domain + state
        palette: focused
        animation: subtle_motion
        transition_ms: 200

    # Status ambiente
    - type: led.set_pattern
      payload:
        color: blue
        pattern: breathing
        speed: slow
        intensity: 0.5

    # Atenção física
    - type: servo.gesture
      payload:
        gesture: look_at_user
        intensity: subtle

    # Conteúdo informativo
    - type: display.show_card
      payload:
        card_type: status
        title: "Build status"
        body: "Project X passing — 12 commits hoje."
        icon: check_circle
        ttl_ms: 30000
        interaction_hint: null

    # Resposta verbal
    - type: audio.tts_stream
      payload:
        stream_ref: tts:atlas:...
        voice_profile: stackchan_default
        fallback_voice_profile: atlas_default_pt_br
        persona_style: stackchan_default
        expected_duration_ms_hint: 3500
        interruptible: true
```

**Ordem de execução: parallel.** Cada renderer no firmware processa independente.

---

## 6. Coordenação temporal

Mesmo em parallel, há ordem implícita esperada:

```
T=0:    LED + face transition iniciados
T=0:    Servo gesture iniciado
T=0:    TTS stream announce enviado
T=~50ms: Card aparece (renderer terminou)
T=~100ms: Servo chegou em posição "look_at_user"
T=~200ms: Face fully transitioned
T=~500ms: TTS streaming chunks chegam — começa playback
T=~3500ms: TTS termina; LED pulse green curto; voltar para idle
```

Renderer não precisa orchestrar isso explicitamente — sequência emerge de:
- Comandos despachados em paralelo.
- Cada renderer no firmware com sua latência natural.
- TTS streaming inicia depois (chunks via media channel).

### Quando precisa orquestrar (modo `sequential`)

Casos raros:
- Apresentação de proposal: card aparece **antes** de qualquer som — sem som inicial.
- Alerta de sucesso: face muda → pequena pausa → voz comenta.

Para esses, bundle marca `execution: sequential`.

---

## 7. Streaming TTS coordinator

### 7.1 Lifecycle

1. Renderer emite `audio.tts_stream` com `stream_ref`.
2. `VoiceProfileResolver` resolve `stackchan_default` para engine local, stream Atlas ou fallback.
3. Em paralelo, inicia chamada ao TTS provider com texto/tokens quando necessário.
4. Provider retorna chunks.
5. Renderer encaminha chunks via media channel para o corpo (com `stream_ref`).
6. Quando provider termina, renderer envia `stream.end`.

O firmware nunca recebe credenciais nem escolhe provider. Modelos e TTS gratuitos/experimentais são rotas internas da alma e ficam escondidos do corpo.

### 7.2 Backpressure

Se corpo informa que speaker buffer está cheio (via control channel), renderer pausa upload de chunks. Quando libera, retoma.

### 7.3 Cancelamento

Receita de cancelamento (ex.: usuário disse "Atlas, espera"):
1. Renderer recebe `cancel_request` (interno).
2. Cancela chamada ao TTS provider.
3. Envia `audio.tts_stop` ao corpo.
4. Marca Receipt com outcome `interrupted_by_user`.

### 7.4 Cache de TTS

⚠️ **DECISÃO PENDENTE:** cache de respostas frequentes (mesma fala várias vezes — saudações, etc.).
- Pro: latência menor.
- Con: complexidade; perde streaming.

Provavelmente: cache de pequenos snippets ("registrado.", "ok.") como `audio.play_sound` ao invés de TTS.

---

## 8. Privacy gates antes de despacho

Antes de cada bundle ir, renderer valida:

| Gate | Verifica |
|---|---|
| `privacy_compliant` | Comandos de captura têm consent_tag adequado |
| `mode_compatible` | Modo atual permite os comandos (ex.: tts em DND? bloquear) |
| `capability_present` | Surface declarou capability necessária |
| `command_within_limits` | Pan/tilt em range, volume em range, etc. |
| `consent_for_modality` | camera_image requer consent explícito |

Falha em qualquer gate:
- Bundle abortado.
- Receipt re-encaminhada para Repair Loop com motivo.
- Evento `gate.<gate_name>.failed` no Ledger.

---

## 9. Tracking de comandos in-flight

Renderer mantém estado por comando despachado:

```yaml
in_flight:
  cmd-...:
    type: display.show_card
    dispatched_at: ...
    receipt_ref: rcpt-...
    state: dispatched | acked | failed | timed_out
    ack_received_at: null
```

ACK chegando do corpo atualiza estado. Timeout (TTL) sem ACK → marcação como failed.

Estado consultável (debug) e usado por Curator para análise (latência, taxa de falha).

---

## 10. Repair coordination

Quando comando falha:

```
Comando despachado → ACK falhou ou expirou
   ↓
Renderer: marca como failed
   ↓
Repair Loop entra:
   ├─ Tentativa 1 (re-empacotar com correção possível)
   ├─ Tentativa 2 (estratégia diferente)
   └─ Limite atingido → falha definitiva → Receipt bounded por reportar
```

Estratégias de repair específicas para Embodiment:

| Falha | Estratégia |
|---|---|
| Surface offline | Falhar Receipt, registrar; aguardar reconexão |
| Comando rejected (validação) | Re-formular com correção (ex.: clamp valores) |
| Comando expired | Reformular se ainda relevante |
| ACK timeout | Re-enviar (idempotente) — máximo 1x |
| Quality Gate falhou | Trocar fluxo (ex.: text-only se voz não pode) |

Limite de tentativas físicas: **baixo** (1-2). Polui ambiente repetir muito.

---

## 11. Multi-surface

Quando mais de uma surface está conectada, renderer especializado precisa decidir destino:

### 11.1 Roteamento explícito
Receipt declara `target_surfaces: ["stackchan_main"]` — específico.

### 11.2 Roteamento por presence
Sem declaração, renderer usa Surface Registry para escolher:
- Surfaces com `user_present: true`.
- Surface com última interação mais recente.

### 11.3 Múltiplas simultâneas (raro)
Receipt pode listar várias surfaces. Cada uma recebe bundle independente.

⚠️ **DECISÃO PENDENTE:** sincronia entre múltiplas surfaces (ex.: ritual de bom dia em ambos os corpos ao mesmo tempo). Provavelmente: bundle dedicado por surface, sem sincronia milimétrica.

---

## 12. Configuração

### 12.1 Mapping tables

Renderer consome (do Atlas Policy/Profile):
- Domain → expressão/palette mapping.
- Sentiment → expressão modulator.
- Urgency → modificações.
- Vocabulário-assinatura (tokens preferidos para TTS).
- Voice profile fixo.

Tudo persistido no Ledger; mudanças via approval.

### 12.2 Hot config

Mudança na mapping table não exige restart do renderer. Próximo Receipt usa mapping atualizado.

---

## 13. Métricas exportadas

Renderer reporta para observabilidade:

| Métrica | Descrição |
|---|---|
| `bundles_dispatched_total` | Counter total |
| `bundles_failed_total` | Counter de falhas |
| `command_latency_ms` | Histogram (dispatched → acked) |
| `tts_latency_ttfb_ms` | Histogram (start → primeiro byte de áudio) |
| `gate_failures_by_type` | Counter por tipo |
| `bundle_size_avg` | Comandos por bundle (saudável: 3-7) |

Curator consome para identificar drift.

---

## 14. Edge cases

### 14.1 Surface desconecta mid-bundle
- Comandos in-flight: marcados como surface_offline.
- Bundle falha; Receipt marcada com outcome especial.
- Sem reentrega automática quando reconecta (pode estar irrelevante já).

### 14.2 Modo muda durante bundle
- Ex.: usuário ativou DND enquanto Atlas estava prestes a falar.
- Renderer detecta mode change via Surface Registry.
- Aborta TTS via `audio.tts_stop`.
- Resto do bundle pode ser não-vocal — deixa renderizar.

### 14.3 TTS provider falha
- TTS chamada falha (timeout, rate limit).
- Renderer envia `stream.cancel` para corpo.
- Bundle parcialmente executado (visual sim, áudio não).
- Receipt marca degradação parcial.

### 14.4 Receipt sem `output_intent`
- Backwards-compat ou bug.
- Renderer aplica default conservador (silent + visual mínimo).
- Log warning.

---

## 15. Anti-padrões

| Anti-padrão | Por quê |
|---|---|
| Renderer interpretando intent (decidindo domain) | Quem decide é Atlas Decide |
| Bundle sem `decision_receipt_ref` | Princípio Atlas violado |
| TTS direto via comando inline (texto no comando) | TTS é stream, não inline |
| Comandos com payload arbitrário | Schema fechado |
| Inventar gestos/expressões ad-hoc | Catálogo fechado |
| 50 comandos pequenos quando bundle de 5 resolve | Coordenação frágil |
| Cache cognitivo no renderer | Cache na Decide |
| Renderer alterando Receipt | Receipts são imutáveis |
| Despachar sem gates | Bug crítico |

---

## 16. Decisões pendentes

| Decisão | Bloqueia |
|---|---|
| ⚠️ Cache de TTS pequeno snippets (sound_id auto) | Latência |
| ⚠️ Sincronia multi-surface | Pós-Fase 0 |
| ⚠️ Mapping tables UI/comando | Admin UX |
| ⚠️ Estratégia de repair para falhas comuns | Implementação |

---

## Próximos passos de leitura

- `03-personalidade-ledger.md` — eventos relacionais.
- `04-curator-proposals.md` — fluxo de proposals.
- `05-domain-faces.md` — mapping detalhado.
- `04-protocolos/03-comandos-fisicos.md` — schemas dos comandos.
