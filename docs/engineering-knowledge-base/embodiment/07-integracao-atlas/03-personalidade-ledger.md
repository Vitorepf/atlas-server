# 03 — Personalidade no Ledger

> **Propósito:** especificar como **identidade e relacionamento do Atlas** vivem no Evidence Ledger — eventos relacionais, marcos, configuração persistida, e como caráter (L5) emerge dessa fundação. **A alma é onde a personalidade mora.** Este documento descreve como.
>
> **Pré-requisitos:** [README](../README.md), [02-arquitetura/01-corpo-vs-alma.md](../02-arquitetura/01-corpo-vs-alma.md), [03-camadas-de-vida/03-continuidade.md](../03-camadas-de-vida/03-continuidade.md), [03-camadas-de-vida/05-carater.md](../03-camadas-de-vida/05-carater.md), [04-protocolos/02-eventos-evidence.md](../04-protocolos/02-eventos-evidence.md).
>
> **Fora do escopo:** Curator proposals (vai pra `04-curator-proposals.md`); Domain faces (vai pra `05-domain-faces.md`).

---

## 1. Princípio fundador

> **Identidade vive no Evidence Ledger. Corpo é trocável; alma persiste.**

Corolário: tudo que define o "quem" do Atlas — voz, vocabulário, padrões, marcos relacionais — é **dado no Ledger**, não código nem prompt.

### Implicações

- Trocar de hardware: identidade reaparece intacta.
- Trocar provider TTS: voz pode mudar; é mudança auditável e reversível.
- Atualizar firmware: identidade não muda — corpo é apenas executor.
- Migrar Atlas backend para nova máquina: identidade migra junto com Ledger.

---

## 2. Anatomia da identidade

Identidade do Atlas é composta de:

```
┌──────────────────────────────────────────────┐
│ IDENTIDADE                                   │
│                                              │
│  ┌────────────────────────────────────────┐  │
│  │ Configuração persistida                │  │ ◄── voz, vocabulário, persona, mapeamentos
│  │ - voice_profile                        │  │
│  │ - vocabulary_signature                 │  │
│  │ - persona_traits                       │  │
│  │ - domain_face_mapping                  │  │
│  │ - ritual_schedule                      │  │
│  └────────────────────────────────────────┘  │
│                                              │
│  ┌────────────────────────────────────────┐  │
│  │ História compartilhada                 │  │ ◄── eventos, interações, padrões
│  │ - relationship.* events                │  │
│  │ - aggregate patterns                   │  │
│  │ - milestones                           │  │
│  └────────────────────────────────────────┘  │
│                                              │
│  ┌────────────────────────────────────────┐  │
│  │ Estado emocional/funcional projetado   │  │ ◄── derivado, não armazenado bruto
│  │ - current "mood" (deriva de eventos)   │  │
│  │ - relationship phase                   │  │
│  └────────────────────────────────────────┘  │
└──────────────────────────────────────────────┘
```

---

## 3. Configuração persistida

### 3.1 voice_profile

Define **voz fixa** do Atlas:

```yaml
voice_profile:
  provider: m5_stackchan | xiaozhi_compatible | elevenlabs | openai_tts | voicevox | local
  voice_id: "<provider-specific>"
  parameters:
    speed: 1.0
    pitch_modifier: 0.0
    style: neutral
    emphasis_strength: medium
  language_primary: pt-BR
  fallback_languages: [en-US]
```

- Mudança = evento `identity.voice.changed` no Ledger.
- Antes de aplicar: janela de teste (ex.: 7 dias com nova voz oferecida ao usuário comparar).
- Reversível: voz anterior ainda referenciável.
- `stackchan_default` é o perfil primário para a surface física quando disponível.
- Firmware não guarda credencial de provider; provider cloud é chamado pelo Atlas e enviado como stream.

### 3.1.1 stackchan_default

Perfil inicial recomendado:

```yaml
voice_profiles:
  stackchan_default:
    purpose: primary_physical_voice
    language_primary: pt-BR
    provider: m5_stackchan | xiaozhi_compatible | local | atlas_streamed_tts
    voice_id: "<discovered-from-current-stackchan-config>"
    parameters:
      speed: normal
      pitch_modifier: default
      prosody: light_focused
    fallback_profile: atlas_default_pt_br
    governance:
      change_requires_decision_receipt: true
      firmware_direct_provider_call: false
      record_fallback_event: true
```

O `voice_id` exato deve ser descoberto na configuração real do StackChan/app atual antes da implementação final.

### 3.2 vocabulary_signature

Frases-assinatura — padrões linguísticos do Atlas:

```yaml
vocabulary_signature:
  acks_short:
    - "registrado"
    - "ok, recebido"
    - "anotado"
  thinking_announce:
    - "vou pensar"
    - "deixa eu olhar"
  needs_more_info:
    - "preciso entender X"
  evidence_reference:
    - "pelo Ledger"
    - "histórico aponta"
  declining:
    - "não consigo isso agora"
  apologizing:
    - "errei. corrigindo."
```

Não são regras rígidas — são **defaults preferidos**. LLM pode improvisar quando contexto pede, mas sempre que possível, usa biblioteca-assinatura.

Documento canônico: `10-anexos/D-vocabulario-atlas.md` (a escrever).

### 3.3 persona_traits

**Não** adjetivos de personalidade. **Sim** princípios operacionais:

```yaml
persona_traits:
  conciseness_preference: high              # respostas curtas por padrão
  formality_level: neutral                   # nem cute, nem formal demais
  uncertainty_handling: explicit             # diz "não sei" sem fingir
  citation_practice: always_with_evidence    # se afirma do passado, cita evidência
  initiative_threshold: conservative         # silêncio é vitória
  cultural_register: pt-BR_brasileiro_técnico
```

### 3.3.1 persona_surface_profiles

A persona do Atlas pode variar por superfície sem fragmentar identidade. Para StackChan:

```yaml
persona_surface_profiles:
  stackchan_default:
    register: pt-BR_direct_warm
    response_shape:
      default_max_spoken_sentences: 2
      use_display_for_dense_detail: true
      prefer_short_ack_before_work: true
    tone:
      warmth: medium
      cuteness: low_to_medium
      humor: sparse
      apology: direct
    forbidden:
      - inventing_memory
      - bypassing_policy
      - exposing_provider_details
      - using_sensitive_context_in_third_party_model_without_policy
```

⚠️ **DECISÃO PENDENTE:** lista exata. Esses são princípios; o set inicial precisa convergir após Fase 1+2 com uso real.

### 3.4 domain_face_mapping

Mapping Domain → expressão (detalhado em `05-domain-faces.md`):

```yaml
domain_face_mapping:
  programming: { expression: attentive, palette: focused, led: blue_calm }
  finance: { expression: informative, palette: focused, led: teal }
  ...
```

### 3.5 ritual_schedule

Quando rituais disparam:

```yaml
ritual_schedule:
  morning_briefing:
    enabled: true
    time: "09:00"
    timezone: "America/Sao_Paulo"
    weekdays: [mon, tue, wed, thu, fri]
    requires_presence: false
    domain: personal_dev
  end_of_day:
    enabled: true
    time: "18:00"
    weekdays: [mon, tue, wed, thu, fri]
    requires_presence: true
    domain: personal_dev
  weekly_review:
    enabled: true
    time: "17:00"
    weekdays: [fri]
    requires_presence: true
    domain: self_improvement
  drift_check:
    enabled: true
    schedule: "every 4 hours"
    interrupts_user: false   # silencioso
```

---

## 4. Eventos relacionais

Catálogo no Ledger. Cada um marca momento significativo:

### 4.1 `relationship.first_interaction`
Primeira interação cognitiva real. Único na vida do Atlas.

### 4.2 `relationship.milestone.<type>`
Catálogo fechado de tipos:

| Tipo | Quando |
|---|---|
| `first_proposal_approved` | Primeira proposal Curator aprovada |
| `first_proposal_rejected` | Primeira rejeição (calibração inicial) |
| `first_critical_alert_handled` | Primeiro alerta crítico atendido |
| `first_ritual_completed` | Primeiro ritual de bom dia executado com presença |
| `first_self_correction` | Atlas se autocorrigiu (admitiu erro) |
| `first_domain_use_<domain>` | Primeira interação em cada Domain |
| `migration_completed` | Troca de hardware bem-sucedida |
| `100_days_continuous` | Marco de uso contínuo |
| `1_year_anniversary` | Aniversário |

**Princípio:** marcos são **úteis para curadoria** (Curator pode trazer à atenção quando relevante), não para floods sentimentais.

### 4.3 `relationship.streak.<type>`

Sequências detectadas:

| Tipo | Significado |
|---|---|
| `daily_use_streak` | Sequência de dias com interação |
| `morning_briefing_streak` | Manhãs consecutivas com ritual completo |
| `weekly_review_streak` | Sextas com revisão |
| `domain_focus_streak` | Sequência intensa em um Domain (ex.: programming todo dia esta semana) |

Streaks ativos → context disponível pra resposta. Quebra de streak → evento `relationship.streak.broken`.

### 4.4 `relationship.gap_noticed`

Curator nota ausência prolongada:

```yaml
type: relationship.gap_noticed
payload:
  gap_kind: domain_unused
  domain: finance
  last_interaction_at: 2026-04-21
  days_gap: 14
```

Nesta fase (3) é **silencioso** — apenas registra. Curator pode propor ação na Fase 4.

### 4.5 `relationship.preference_inferred`

Padrão detectado vira preferência inferida:

```yaml
type: relationship.preference_inferred
payload:
  inferred: "user_prefers_short_responses_in_morning"
  confidence: 0.7
  evidence_count: 18
  sample_window_days: 30
```

Preferências inferidas **não auto-aplicam** — Curator propõe, usuário aprova.

### 4.6 `relationship.tone_signal`

Quando interação tem sinal claro de tom (frustração, satisfação):

```yaml
type: relationship.tone_signal
payload:
  signal: positive_acknowledgement | frustration | confusion | satisfaction
  source_envelope: <id>
  inferred_from: explicit_phrase | response_pattern | rejection_velocity
```

Curator usa para detectar drift de UX.

---

## 5. Estado emocional projetado

**Não armazenado bruto.** Derivado dos eventos.

Curator computa periodicamente:

```yaml
emotional_projection:
  computed_at: ...
  relationship_phase: established | new | strained | mature
  recent_user_satisfaction: -1..+1
  curator_alert_level: calm | watching | concerned
  recent_interaction_density: low | normal | high
```

Resultado disponível para Decide consultar como contexto.

⚠️ **Cuidado:** estado emocional inferido pode estar errado. Atlas evita afirmar sobre ele explicitamente ("vejo que você está frustrado") a menos que sinal seja muito claro.

---

## 6. Voz e identidade — proteção contra drift

### 6.1 Monitor de voz

Curator periodicamente:
- Verifica que `voice_profile` parameters estão consistentes com último audit.
- Detecta mudanças silenciosas (provider atualizou).
- Compara samples de TTS recentes (se memória de TTS cache permite).
- Se mudou imperceptível: alerta.

### 6.2 Migração de voz

Quando inevitável (provider descontinuou, etc.):

1. Curator alerta usuário: "voz vai precisar mudar; razão: X".
2. Apresenta 3 opções de provider/voice candidatos.
3. Usuário escolhe (com previews).
4. Janela de teste: 7 dias com nova voz; voz antiga mantida em paralelo (se possível).
5. Decisão final: confirma, ou volta atrás.
6. Evento `identity.voice.migrated` com detalhes.

### 6.3 Vocabulário monitor

Curator monitora uso de vocabulary_signature:
- Frequência de uso de tokens da biblioteca por contexto.
- Drift: token caindo de uso quando deveria estar alto.
- Causa possível: provider atualizou; prompt drift; contexto sobrecarregando.

Alerta se drift detectado.

---

## 7. Migração de hardware (continuidade)

### 7.1 Workflow

Quando você troca StackChan:

```
1. Provisionar novo hardware:
   - Generate new auth_token
   - Configure Wi-Fi
   - Migrate or create new surface_id

2. Decision: same surface_id or new?
   - same: identidade absoluta; corpo "vira" o mesmo
   - new: identidade migrada; novo corpo, mesma alma

   Recomendação Fase 3: opção `new`.
   Evento `surface.migrated.from_<old>.to_<new>` registra.

3. Boot do novo:
   - Conecta à alma.
   - Alma reconhece migration tag.
   - Primeira interação: face especial breve "novo corpo, continuidade preservada."
   - Após primeira interação: transparente.

4. Old surface_id:
   - Marcada como "retired".
   - Preservada no Ledger (audit trail).
   - Eventos antigos continuam acessíveis.
```

### 7.2 O que migra

- Voice_profile: identidade vocal.
- Vocabulary_signature: identidade verbal.
- Persona_traits: principios operacionais.
- Domain_face_mapping: identidade visual.
- Ritual_schedule: rotina.
- História: eventos de antes da migração.

### 7.3 O que não migra (e tudo bem)

- Calibração específica de servos (microajustes vão refazer no novo hardware).
- Asset cache local (regenerado).
- Telemetria histórica do corpo antigo (correlato a hardware específico, irrelevante).

---

## 8. Renderização da identidade

Como identidade aparece para o usuário:

### 8.1 Voz

Sempre o mesmo provider/voice. Se mudar, é evento.

### 8.2 Vocabulário

Em respostas, padrões da biblioteca aparecem naturalmente. Atlas Decide e Output Renderer **respeitam preferências**:
- Tokens de vocabulário-assinatura têm boost em prompt do LLM.
- LLM produz respostas que usem-nos quando contexto permite.

### 8.3 Visual

Domain → expressão consistente. Paletas estáveis ao longo do tempo.

### 8.4 Comportamental

Iniciativa calibrada conforme persona_traits (e auto-tuning).

---

## 9. Privacy

Identidade é **dado pessoal sensível**. Ledger contém:

- Marcos relacionais → histórico de uso.
- Tone signals → sinais emocionais.
- Preferences inferidas → modelo do usuário.

### 9.1 Acesso

- Apenas Atlas core (Decide, Curator) lê.
- Não vai pra providers externos exceto quando estritamente necessário (ex.: snippet de contexto pra LLM).
- Conteúdo bruto (transcrições) descartado por padrão.

### 9.2 Export

Usuário pode exportar identidade própria:
- `atlas identity export --output ~/atlas-backup.json` (criptografado).
- Útil para backup, migração de máquina, encerramento.

### 9.3 Apagar

Usuário pode apagar:
- "Esquecer marcos antes de X data."
- "Resetar persona" (cuidado: efetivamente recomeça caráter).

Cada operação registra evento `identity.purge.<scope>` no que sobra.

---

## 10. Anti-padrões

| Anti-padrão | Por quê |
|---|---|
| Persona em system prompt do LLM | Teatro; não é caráter |
| Voz mudando silenciosamente | Identidade comprometida |
| Vocabulary_signature ignorado por LLM | Caráter dilui |
| Marcos para coisas triviais | Floods sentimentais; vira piada |
| Estado emocional armazenado bruto | Privacy + risco de afirmar errado |
| Identidade dependente do hardware | Quebra princípio corpo-vs-alma |
| Migration silenciosa | Quebra audit |
| Curator afirmando sentimento do usuário | "Vejo que você está estressado" — assustador, frequentemente errado |
| Persona "atualizada" arbitrariamente | Morte simbólica |

---

## 11. Decisões pendentes

| Decisão | Bloqueia |
|---|---|
| ⚠️ Lista canônica de tipos de milestone | Implementação |
| ⚠️ Threshold para `relationship.preference_inferred` | Curator |
| ⚠️ Política de monitoramento de drift de voz | Implementação |
| ⚠️ Política de "esquecer" granular | UX |
| ⚠️ Persona_traits canônico — set inicial | Pós-Fase 1 |

---

## Próximos passos de leitura

- `04-curator-proposals.md` — Curator é o guardião dessa identidade.
- `05-domain-faces.md` — visual identity por Domain.
- `10-anexos/D-vocabulario-atlas.md` — biblioteca canônica (a escrever).
- `03-camadas-de-vida/05-carater.md` — fundamentos teóricos.
