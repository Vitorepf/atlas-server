# 06 — StackChan Bridge e preservação de voz/persona

> **Propósito:** especificar a arquitetura que permite usar o **StackChan como corpo/persona física** enquanto o **Atlas permanece o cérebro/backend**. Este documento fecha a decisão: preservar a voz, cadência, expressões e comportamento físico padrão do StackChan sem deixar o firmware, o app M5, Xiaozhi ou modelos gratuitos decidirem pelo Atlas.
>
> **Pré-requisitos:** [02-arquitetura/01-corpo-vs-alma.md](../02-arquitetura/01-corpo-vs-alma.md), [04-protocolos/03-comandos-fisicos.md](../04-protocolos/03-comandos-fisicos.md), [04-protocolos/05-streaming.md](../04-protocolos/05-streaming.md), [07-integracao-atlas/01-surface-adapter.md](01-surface-adapter.md), [07-integracao-atlas/02-output-renderer.md](02-output-renderer.md), [07-integracao-atlas/03-personalidade-ledger.md](03-personalidade-ledger.md).
>
> **Fora do escopo:** escolha definitiva do provedor TTS comercial/local; implementação concreta do firmware; fork do app M5.

---

## 1. Decisão

O Atlas deve usar o StackChan como:

- corpo físico;
- voz sonora;
- rosto;
- timing expressivo;
- reflexos locais;
- persona de superfície.

O Atlas deve manter:

- raciocínio;
- memória;
- ferramentas;
- leitura de código;
- decisões;
- políticas;
- auditoria;
- roteamento de modelos;
- custo/privacidade.

**Não vamos substituir o StackChan por um "assistente genérico no boneco".** Também **não vamos deixar o StackChan/M5/Xiaozhi virar um cérebro paralelo**.

Forma canônica:

```text
Usuário
  ↓ voz/toque/presença
StackChan firmware
  ↓ Interaction Envelope / media stream
Atlas StackChan Bridge
  ↓ Operation Envelope
Atlas Core
  ↓ Decision Receipt
StackChan Persona Adapter
  ↓ output text + voice profile + face + motion + led
Surface Adapter / Transport
  ↓ Output Commands / TTS chunks
StackChan firmware
```

---

## 2. Componentes novos

### 2.1 Atlas StackChan Bridge

Camada lógica dentro da integração `stackchan`, no lado Atlas.

Responsabilidades:

| Responsabilidade | Detalhe |
|---|---|
| Normalizar entrada | Converte eventos/voz/toque do corpo em `OperationEnvelope` sem semântica extra. |
| Proteger a fronteira | Impede que app M5, Xiaozhi ou modelo direto bypass o Atlas Core. |
| Roteamento para Atlas | Encaminha tudo para o pipeline normal: Intent, Decide, Policy, Runtime, Receipt. |
| Preservar surface context | Anexa capabilities, estado de voz, tts profile, latência, modo físico. |
| Controlar providers | Modelos "grátis" entram como providers do Atlas, nunca como decisão do firmware. |

Não faz:

- não escolhe resposta;
- não consulta LLM diretamente;
- não mantém memória conversacional;
- não cria prompt de personalidade independente;
- não decide ferramenta.

### 2.2 StackChan Persona Adapter

Camada de saída, chamada pelo Output Renderer especializado `stackchan`, depois do Atlas decidir.

Responsabilidades:

| Responsabilidade | Detalhe |
|---|---|
| Preservar voz de superfície | Mantém `voice_profile` padrão do StackChan quando disponível. |
| Adaptar texto | Transforma resposta Atlas em fala curta, fluida e compatível com presença física. |
| Gerar sinais expressivos | Define emoção, face, LED, gesto, pausa e interrupibilidade. |
| Preservar timing | Ajusta tamanho de frase, chunking e pausas para TTS natural. |
| Evitar persona paralela | Não muda conteúdo factual nem decisão; só materializa estilo. |

Não faz:

- não corrige fatos;
- não decide se algo pode ou não ser dito;
- não escolhe ferramentas;
- não ignora policy;
- não cria "memória afetiva" fora do Ledger.

---

## 3. Contrato de saída estilizada

O Output Renderer deve produzir um objeto intermediário antes de virar comandos físicos:

```json
{
  "schema_version": "atlas.stackchan.styled_output.v1",
  "decision_receipt_ref": "rcpt-...",
  "surface_id": "stackchan_main",
  "text": {
    "canonical": "O build do projeto X está passando. Houve 12 commits hoje.",
    "spoken": "O build do X está passando. Foram 12 commits hoje.",
    "display_title": "Build OK",
    "display_body": "Projeto X passando\n12 commits hoje"
  },
  "persona": {
    "style_id": "stackchan_default",
    "register": "pt-BR_direct_warm",
    "brevity": "short",
    "cuteness": "low",
    "technical_density": "adaptive"
  },
  "voice": {
    "voice_profile": "stackchan_default",
    "tts_route": "local_or_m5_compatible",
    "fallback_voice_profile": "atlas_default_pt_br",
    "speech_rate": "normal",
    "pitch": "default",
    "prosody": "light_focused"
  },
  "embodiment": {
    "face": "informative",
    "palette": "focused",
    "led": { "color": "green", "pattern": "pulse", "intensity": 0.45 },
    "motion": { "gesture": "small_nod", "intensity": "subtle" },
    "mouth_sync": "tts_energy",
    "interruptible": true
  }
}
```

Regra: `canonical` é o conteúdo autorizado pelo Atlas; `spoken` é a versão falada. O Adapter pode comprimir, suavizar e ajustar ritmo, mas **não pode adicionar fatos ou mudar decisão**.

---

## 4. Preservação da voz

"Voz" tem duas camadas diferentes.

### 4.1 Voz sonora

Inclui:

- timbre;
- TTS provider;
- `voice_id`;
- velocidade;
- pitch;
- estilo/prosódia;
- idioma;
- volume percebido;
- latência de TTFB.

Fonte de verdade:

- `identity.voice_profile` no Evidence Ledger.
- `surface.voice_capabilities` no Surface Registry.
- assets/engine disponíveis no firmware.

### 4.2 Voz de personalidade

Inclui:

- frases curtas;
- hesitações naturais;
- confirmação rápida;
- calor controlado;
- jeito de responder sem virar "fofo demais";
- cadência de companheiro de mesa;
- silêncio quando a melhor resposta é não interromper.

Fonte de verdade:

- `identity.persona_surface_profiles.stackchan_default`;
- `vocabulary_signature`;
- eventos relacionais no Ledger;
- políticas de domínio.

---

## 5. `voice_profile` canônico

Adicionar ao Ledger:

```yaml
identity:
  voice_profiles:
    stackchan_default:
      status: active
      owner: atlas
      purpose: primary_physical_voice
      language_primary: pt-BR
      sonic:
        provider: m5_stackchan | xiaozhi_compatible | local | openai_tts | elevenlabs | voicevox
        voice_id: "<provider-specific-or-null>"
        tts_endpoint_ref: "<secret-ref-or-null>"
        speech_rate: normal
        pitch_modifier: default
        prosody: light_focused
      fallback:
        profile: atlas_default_pt_br
        reason: provider_unavailable_or_policy_blocked
      governance:
        change_requires_receipt: true
        change_event: identity.voice.changed
        test_window_required: true
        allow_firmware_direct_provider_call: false
```

Hard rule:

> O firmware não guarda API key de provider de voz. Se a voz padrão depender de cloud, o Atlas chama o provider e envia áudio/TTS stream ao corpo.

Exceção permitida:

- voz/TTS local no firmware sem provider externo;
- samples curtos locais (`ack`, `wake_confirm`, `error`) sem conteúdo cognitivo.

---

## 6. `persona_surface_profile` canônico

Adicionar ao Ledger:

```yaml
persona_surface_profiles:
  stackchan_default:
    register: pt-BR_direct_warm
    response_shape:
      default_max_spoken_sentences: 2
      prefer_short_ack_before_work: true
      avoid_long_monologue_on_speaker: true
      use_display_for_dense_detail: true
    tone:
      warmth: medium
      cuteness: low_to_medium
      confidence: calibrated
      humor: sparse
      apology: direct
    companion_behavior:
      proactive_interruptions: policy_governed
      physical_motion_frequency: calm
      idle_expression_frequency: rare
      acknowledge_touch_locally: true
    forbidden:
      - inventing_memory
      - bypassing_policy
      - pretending_offline_reasoning
      - exposing_provider_details
      - using_sensitive_code_context_in_third_party_model_without_policy
```

---

## 7. Roteamento de modelos gratuitos

Modelos expostos pelo app M5/Xiaozhi, como Qwen/DeepSeek/Kimi/GLM, podem ser úteis, mas só como **providers atrás do Atlas**.

Permitido:

- teste de latência;
- benchmark de qualidade;
- protótipo de conversa;
- fallback barato;
- tarefas não sensíveis;
- comparação de resposta.

Proibido:

- firmware chamar modelo direto para resposta cognitiva;
- app M5 decidir conversa do Atlas;
- enviar código privado, estratégia ou finanças sem policy/redaction;
- armazenar memória nesses provedores;
- depender de modelos marcados como `Internal Test`;
- tratar "aparentemente grátis" como SLA.

Provider routing:

```yaml
model_route:
  selected_by: atlas_policy_router
  visible_to_firmware: false
  allowed_for_stackchan_surface: true
  requires_receipt: true
  cost_policy: opportunistic_free_or_low_cost
  privacy_policy: redact_sensitive_context
  fallback_order:
    - atlas_primary_model
    - qwen_fast_if_allowed
    - deepseek_code_if_allowed
    - local_small_model_if_available
```

O StackChan deve receber apenas:

- texto final;
- áudio final ou stream TTS;
- comandos de corpo;
- ACK/telemetria.

Ele não precisa saber qual LLM foi usado.

---

## 8. Fluxos de implementação

### 8.1 Conversa normal

```text
1. Usuário chama StackChan.
2. Firmware detecta wake word local.
3. Firmware envia `stream.announce` + audio chunks.
4. Surface Adapter cria `OperationEnvelope`.
5. Atlas decide intent, domínio, ferramentas e modelo.
6. Atlas emite `DecisionReceipt`.
7. StackChan Persona Adapter gera `styled_output`.
8. Output Renderer cria bundle:
   - display.show_face
   - led.set_pattern
   - servo.gesture
   - audio.tts_stream
   - display.show_card opcional
9. TTS usa `voice_profile=stackchan_default`.
10. Corpo toca voz e sincroniza mouth/face/motion.
11. Corpo envia ACKs.
12. Ledger registra evento completo.
```

### 8.2 Voz padrão indisponível

```text
1. Atlas tenta `stackchan_default`.
2. Provider/engine falha, policy bloqueia ou latência excede budget.
3. Atlas emite evento `identity.voice.fallback_used`.
4. Output usa `fallback_voice_profile`.
5. Corpo mostra indicação discreta de degraded voice, sem assustar o usuário.
6. Curator pode propor correção depois.
```

### 8.3 Modelo gratuito disponível

```text
1. Policy Router detecta tarefa não sensível.
2. Modelo gratuito/baixo custo é elegível.
3. Atlas chama provider.
4. Resultado passa por quality/privacy gates.
5. Persona Adapter adapta estilo.
6. StackChan recebe output normal, sem saber provider.
```

### 8.4 Modo offline/degradado

```text
1. StackChan perde Atlas.
2. Firmware entra em degraded explícito.
3. Mantém blink/reflexos mínimos.
4. Não responde perguntas cognitivas.
5. Pode dizer frase local fixa: "estou desconectado" se asset local existir.
6. Ao reconectar, Atlas restaura estado.
```

Frase local de offline é permitida porque comunica estado operacional do corpo, não conhecimento.

---

## 9. Mudanças necessárias no firmware

Firmware precisa suportar:

- `voice_profile` em `audio.tts_stream`;
- reprodução de TTS vindo do Atlas;
- fallback para samples curtos locais;
- `mouth_sync` baseado em energia do áudio ou eventos de chunk;
- `face`/`motion`/`led` coordenados por bundle;
- telemetria de TTS:
  - `tts_started_at`;
  - `tts_first_audio_ms`;
  - `tts_buffer_underrun_count`;
  - `voice_profile_used`;
  - `voice_fallback_used`;
- modo `degraded_voice`;
- refusal local para comando TTS sem receipt.

Firmware não deve suportar:

- API keys de LLM/TTS;
- seleção de modelo;
- prompt/persona local;
- histórico de conversa;
- endpoint direto para Xiaozhi como brain do Atlas.

---

## 10. Mudanças necessárias no Atlas

Atlas precisa implementar:

- Surface Registry com `voice_capabilities`;
- `StackChanBridge` dentro do adapter;
- `StackChanPersonaAdapter`;
- `VoiceProfileResolver`;
- provider routing por policy;
- redaction antes de modelo externo;
- TTS route resolver;
- events no Ledger:
  - `identity.voice.selected`;
  - `identity.voice.changed`;
  - `identity.voice.fallback_used`;
  - `surface.stackchan.voice_profile.reported`;
  - `surface.stackchan.persona_adapter.applied`;
  - `surface.stackchan.provider_route.hidden_from_firmware`;
- metrics:
  - time to first spoken audio;
  - fallback rate;
  - TTS error rate;
  - persona adapter edit distance;
  - user interruption rate.

---

## 11. Critérios de aceite

Um build só passa quando:

1. StackChan fala com `voice_profile=stackchan_default` quando disponível.
2. A resposta cognitiva vem do Atlas Core, comprovada por `DecisionReceipt`.
3. O firmware rejeita comando cognitivo sem receipt.
4. O provider/modelo usado não aparece no firmware.
5. A fala preserva estilo curto e físico; detalhes longos vão para display ou canal textual.
6. Trocar provider LLM não muda a persona de superfície.
7. Trocar TTS dispara evento de identidade/voz.
8. Queda do Atlas não gera resposta improvisada.
9. ACKs de áudio/face/servo/LED chegam ao Ledger.
10. Dados sensíveis só vão a provider externo se policy permitir.

---

## 12. Anti-padrões

| Anti-padrão | Por que é ruim | Correção |
|---|---|---|
| Trocar endpoint do Xiaozhi para Atlas e chamar pronto | Mantém cérebro/protocolo M5 como sombra | Criar Bridge + Receipt + Adapter |
| Firmware escolher Qwen/DeepSeek | Provider routing fora da policy | Roteamento no Atlas |
| Prompt de persona no firmware | Persona divergente e não auditável | Persona no Ledger |
| Voz padrão sem evento de mudança | Identidade muda sem trilha | `identity.voice.changed` |
| Resposta longa no speaker | Cansa e parece assistente genérico | Fala curta + card/display |
| Enviar código privado para modelo gratuito | Risco de privacidade | Redaction/policy/router |
| Internal Test como dependência | Pode sumir ou mudar | Apenas experimento |

---

## 13. Veredito implementável

A melhor forma possível para o objetivo do Atlas é:

```text
StackChan = corpo + voz + presença + charme
Atlas = cérebro + memória + ferramentas + policy
Bridge = fronteira segura
Persona Adapter = preservação do jeito StackChan
Providers gratuitos = opcionais e invisíveis ao firmware
```

Essa arquitetura preserva a experiência que já funciona no StackChan e, ao mesmo tempo, coloca engenharia de software, negócios, finanças, código, memória e decisões dentro do Atlas.
