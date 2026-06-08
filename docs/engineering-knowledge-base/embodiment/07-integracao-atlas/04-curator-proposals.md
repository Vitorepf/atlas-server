# 04 — Curator → Proposals → Embodiment

> **Propósito:** especificar o **fluxo completo** pelo qual o Curator (componente de Self-Improvement do Atlas) detecta padrões, gera **proposals**, e essas proposals chegam ao usuário **via Embodiment** — passando por `interruption_policy`, sendo apresentadas com gradiente, recebendo aprovação/rejeição via touch, alimentando auto-tuning. É a coluna vertebral da camada L4 (iniciativa).
>
> **Pré-requisitos:** [README](../README.md), [01-surface-adapter.md](01-surface-adapter.md), [03-camadas-de-vida/04-iniciativa.md](../03-camadas-de-vida/04-iniciativa.md), [03-personalidade-ledger.md](03-personalidade-ledger.md).
>
> **Fora do escopo:** Curator algorithms internos (assumido); `interruption_policy` formal (vai pra `05-policies/02-interrupcao.md`, a escrever).

---

## 1. Visão geral do fluxo

```
[ Evidence Ledger ] ─── (Curator analisa periodicamente)
                                    │
                                    ▼
                         [ Proposal gerada ]
                                    │
                                    ▼
                         [ Proposal Queue ] (estado: pending)
                                    │
                                    │ (interruption_policy avalia)
                                    ▼
                         [ Decisão: present | defer | discard ]
                                    │
                                    ▼ (se present)
                         [ Output Renderer compõe bundle ]
                                    │
                                    ▼
                         [ StackChan apresenta com gradiente ]
                                    │
                                    ▼
                  ┌─────────────────┼─────────────────┐
                  ▼                 ▼                 ▼
              [aprovar]         [rejeitar]      [snooze/timeout]
                  │                 │                 │
                  ▼                 ▼                 ▼
           [ Effect ]       [ Auto-tune ]      [ Re-queue / discard ]
                  │
                  ▼
       [ Aplicação efetivada ]
                  │
                  ▼
          [ Evidence registra outcome ]
```

---

## 2. Princípios

### P1 — Curator propõe, policy decide
Curator nunca interrompe diretamente. `interruption_policy` é o gatekeeper.

### P2 — Apresentação gradiente sempre
Default: silencioso primeiro (LED). Escala se contexto justifica.

### P3 — Touch é o canal de baixa fricção
Aprovação/rejeição via touch. Voz é raro e reservado para crítico.

### P4 — Auto-tuning é conservador
Reduz prioridade fácil; aumenta com cuidado. Direção segura: silêncio.

### P5 — Tudo registrado
Cada proposal: criação, apresentação, outcome — eventos no Ledger. Audit total.

### P6 — Curator nunca afirma certeza absoluta
Proposal sempre é convite, não comando. Atlas Decide pode rejeitar a proposal antes mesmo de chegar à interruption_policy.

---

## 3. Como Curator gera proposals

### 3.1 Análise periódica

Curator roda a cada **30min** (configurável). Tarefas:

| Tarefa | Inputs | Output |
|---|---|---|
| Pattern detection | Eventos recentes (últimos N dias) | Padrões repetidos |
| Drift detection | Métricas atuais vs baseline | Anomalias |
| Gap detection | Domain usage por Domain | Domínios com gap > threshold |
| Bug signal | Quality gate failures, repair loops | Drift técnico |
| Identity drift | Vocabulário, voz, padrão de resposta | Drift de personalidade |
| Optimization | Padrões repetidos + automação possível | Sugestão de atalho/automação |

### 3.2 Categorias de proposal

Cada proposal tem `category`:

| Category | Exemplos |
|---|---|
| `automation` | "Você fez X 5x esta semana — criar atalho NFC?" |
| `gap_attention` | "Finanças há 14 dias sem visita — revisar?" |
| `drift_notice` | "Wake word com 3x mais false positives — ajustar threshold?" |
| `policy_adjustment` | "Modo silencioso virou default — repensar interruption_policy?" |
| `health_concern` | "Bateria degradando ao longo de meses — considerar replacement" |
| `identity_drift` | "Vocabulário-assinatura caindo de uso — investigar" |
| `relationship_milestone` | "100 dias contínuos — vale comemorar?" |
| `optimization` | "Build query muito frequente — cachear localmente?" |

⚠️ **DECISÃO PENDENTE:** lista exata de categorias, com critérios.

### 3.3 Estrutura de uma proposal

```yaml
proposal:
  proposal_id: prop-2026-05-05T12:00:00Z-a8f2
  created_at: 2026-05-05T12:00:00Z
  category: automation
  priority: normal       # critical | high | normal | low | background
  title: "Criar atalho NFC para 'status do build'?"
  body: |
    Você perguntou status do build do projeto X 6 vezes esta semana.
    Posso criar um atalho NFC que dispara essa consulta automaticamente.

  rationale:
    detected_pattern: "domain=programming intent=build_status frequency=6/week"
    evidence_refs: [ev-..., ev-..., ev-...]      # Ledger refs
    confidence: 0.85

  proposed_action:
    type: create_nfc_mapping
    payload:
      tag_label_suggested: "BUILD_STATUS"
      maps_to_intent: "programming.build_status"

  expected_effect:
    description: "Atalho de 1s vs 5s pergunta vocal"
    reversible: true

  policy_hints:
    interruption_priority: normal
    presentation_modality: visual   # visual primeiro; sem voz
    touch_response_expected: true

  expires_at: 2026-05-12T12:00:00Z
```

### 3.4 Queue management

- Proposals vão para fila: `pending`, `presented`, `responded`, `expired`, `dismissed`.
- Limite por categoria: máx 3 pending da mesma categoria (deduplica).
- Expiração: configurável; default 7 dias.

---

## 4. interruption_policy decide

Para cada proposal `pending`, policy avalia:

```python
def should_present(proposal, current_state):
    # 1. Modo do corpo
    if current_state.mode == "private_physical":
        return SUPPRESS_FOREVER

    if current_state.mode == "dnd" and proposal.priority != "critical":
        return DEFER

    if current_state.mode == "private" and proposal.priority != "critical":
        return DEFER

    if current_state.mode == "interaction":
        return DEFER  # não interromper conversa

    # 2. Rate limits
    if recently_presented_count(window="1h") >= max_per_hour(current_state.mode):
        return DEFER

    if last_interruption_at < min_gap_min_ago():
        return DEFER

    # 3. Auto-tuning
    if category_paused_until > now:
        return DEFER

    # 4. Categoria histórico
    if rejection_rate(proposal.category, window="7d") > 0.7:
        if proposal.priority < "high":
            return DEMOTE  # apresenta com prioridade reduzida ou descarta

    # 5. Auto-acceptance
    if acceptance_rate(proposal.category, window="7d") > 0.85 and is_low_risk(proposal):
        # Curator pode propor mudar política para auto-aplicar essa categoria
        # mas isso vira meta-proposal, não auto-aplicação direta
        ...

    return PRESENT
```

(Pseudo-código; implementação em `05-policies/02-interrupcao.md` quando escrito.)

### Outcomes

| Outcome | Significado |
|---|---|
| `PRESENT` | Pode apresentar agora |
| `DEFER` | Volta para pending; revisitar mais tarde |
| `DEMOTE` | Apresenta mas sem voz / com menos peso |
| `SUPPRESS` | Não apresenta nesta sessão; volta para pending |
| `SUPPRESS_FOREVER` | Categoria pausada |
| `DISCARD` | Proposal expirou ou virou irrelevante |

---

## 5. Apresentação gradiente

Quando policy diz `PRESENT`:

### 5.1 Estágio 1 (T=0)

LED laranja pulsante lento. Sem som. Sem voz. Face neutra.

→ Sinal mínimo de "tem algo".

### 5.2 Estágio 2 (T+30s, sem reação)

Card aparece silenciosamente:
- title da proposal
- corpo curto (uma frase)
- icon orange
- `interaction_hint`: pads 1/2/3 ativos

→ Usuário pode olhar de canto, ler, decidir.

### 5.3 Estágio 3 (T+60s, sem reação)

Para `low`/`normal`: **descartar** ou minimizar.
Para `high`: micro-som de ack ambient (não voz). LED intensifica.

### 5.4 Estágio 4 (T+90s, sem reação, só se priority alta)

Voz curta e direta:
> "Atlas: {title}. {body curto}. Pad 1 aprova, pad 2 dispensa."

Para `critical` (raro): inicia direto no estágio 4.

### 5.5 Timeout

Após estágio máximo + N minutos sem resposta:
- `low`/`normal`: descartar (registra `proposal.timeout`).
- `high`/`critical`: re-queue com prioridade reduzida.

---

## 6. Touch response

### 6.1 Mapeamento contextual

Quando proposal está apresentada, touch pads mudam de semântica:

| Pad | Ação |
|---|---|
| 1 | ✓ Aprovar |
| 2 | ✗ Rejeitar / dismiss |
| 3 | 💬 Explicar mais (abre voz: Atlas explica detalhes) |

Esse mapeamento vem do `interaction_hint` do card. Quando proposal sai (timeout, resposta), pads voltam a sem-mapeamento (apenas reflex).

### 6.2 Combos

| Combo | Ação |
|---|---|
| Pads 1+3 hold 1s | "Aplica e me mostre o resultado" (aceita + abre interação) |
| Pads 2+3 hold 1s | "Rejeita e me explica por quê foi proposto" (rejeita + abre interação) |
| Pad 1 longo (>2s) | Snooze 15min |

### 6.3 Voz

Usuário também pode responder por voz:
- "Aceito" / "Rejeito" / "Explica" / "Depois" → voice_short_command pós wake word.

---

## 7. Aplicação do efeito

Quando aprovada:

```yaml
proposal_approved:
  proposal_id: prop-...
  approved_by: touch_pad_1
  approved_at: ...
  ↓
Effect application:
  Atlas Decide cria nova Operation Envelope com:
    trigger: scheduled    # implícito
    intent: apply_proposal
    payload: proposal.proposed_action
  ↓
Pipeline normal Atlas processa:
  Quality Gates → execução → reporting
  ↓
Evento registrado:
  type: proposal.applied
  payload:
    proposal_id: ...
    outcome: success | partial | failed
    artifacts: [...]      # ex: nova NFC mapping criada
```

Aplicação **passa pelo pipeline normal** — não há atalho. Princípio: "Runtime não executa sem Receipt" continua válido.

### Reversibilidade

Cada aplicação tem entrada `reversible: bool` na proposal. Se sim, há comando "desfazer" disponível por janela curta:

```
Após aplicação, card mostra: "Aplicado. Desfazer? (pad 2)"
TTL de desfazer: 5 minutos.
```

---

## 8. Auto-tuning baseado em outcomes

Cada outcome alimenta tuning:

### 8.1 Aceitação

```
proposal.applied (success)
   ↓
category.acceptance_count++
   ↓
Se acceptance_rate(category, 7d) sustained alto:
   → manter prioridade ou propor auto-aplicação (meta-proposal)
```

### 8.2 Rejeição

```
proposal.rejected
   ↓
category.rejection_count++
   ↓
Se 3x consecutiva da mesma categoria:
   → reduzir prioridade dessa categoria por 24h
   ↓
Se 5x ignorado em janela:
   → pausar categoria por 24-48h
```

### 8.3 Snooze

```
proposal.snoozed
   ↓
Re-queue com novo expires_at
   ↓
Apresenta novamente após snooze_duration
   ↓
Se mesmo proposal_id snoozed 2x:
   → terceira apresentação ou descartar (config)
```

### 8.4 Padrão "modo silencioso virando default"

```
analyze: time_in(silent_mode, last_7d) > 30%
   ↓
Curator gera proposal especial:
  category: policy_adjustment
  priority: high
  title: "Modo silencioso virou padrão. Revisar interruption_policy?"
   ↓
Apresenta com gradient — usuário pode rebalancear policies.
```

---

## 9. Casos canônicos

### 9.1 Proposal de automação aceita

```
T=0:    Curator detecta "build_status query 6x semana".
T=5min: Proposal gerada, queue.
T=8min: Policy avalia: ambient mode, dentro de rate limit → PRESENT.
T=8min: LED orange pulse. Face neutra.
T=8min30: Usuário olhou, ignorou; LED continua.
T=9min: Card silencioso aparece.
T=9min15: Usuário lê, toca pad 1.
T=9min15: Ack visual + "Aplicado." voz curta.
        Tag NFC criada. Atlas mostra como etiquetar.
T+5min: Reverter ainda disponível. Após: definitivo.
```

### 9.2 Proposal rejeitada com aprendizado

```
Curator: "Você não revisa newsletter X há 2 semanas — abrir?"
   → Proposal apresentada.
Usuário: pad 2 (rejeita).
Curator: registra rejection.
   → Próxima vez (1 semana depois): mesma categoria, prioridade reduzida.
Usuário rejeita de novo: 3ª vez consecutiva.
Auto-tuning: pausa categoria 24h.
   → Próximo dia: Curator não propõe newsletter de novo.
Usuário 1 mês depois: auto-tuning soltou pausa, mas categoria com peso reduzido.
   → Curator propõe outras coisas mais úteis.
```

### 9.3 Crítico em DND

```
Build de produção quebrou (crítico).
Modo: DND.
Policy: critical bypassa DND → apresenta.
Apresentação: voz direta + face alert + LED red pulse.
Usuário: toca pad 1 (acknowledged).
Sem retornar ao DND silenciosamente — usuário ativa de volta se quiser.
```

### 9.4 Proposal de identidade drift (raro mas importante)

```
Curator detecta: voz Atlas mudou parâmetros (provider atualizou).
Categoria: identity_drift, prioridade high.
Apresenta:
  Title: "Voz parece estar mudando."
  Body: "Detectei diferença em 3 sessões recentes. Investigar provider?"
Usuário pode investigar, ajustar config, ou aceitar mudança.
```

---

## 10. Eventos no Evidence Ledger

Toda etapa registrada (resumo — detalhes em `04-protocolos/02-eventos-evidence.md`):

```
curator.proposal.created
curator.proposal.queued
curator.proposal.presented_via_surface     # com presentation_mode
curator.proposal.timeout
curator.proposal.snoozed
curator.proposal.approved_via_touch         # ou .voice
curator.proposal.rejected_via_touch         # ou .voice
proposal.applied (com outcome)
proposal.application.reverted
policy.auto_adjusted.category_demoted
policy.auto_adjusted.category_paused
policy.silence_mode_drift_detected
```

Curator consome esses eventos retroativamente para refinar análise.

---

## 11. Multi-surface

Quando há mais de um corpo conectado:

- Proposal escolhe target via:
  - Presence (apresenta no corpo onde usuário está).
  - Last interaction (recente).
  - Default (corpo "primário" configurado).

- Sem múltiplas apresentações simultâneas (não duplica).

---

## 12. Anti-padrões

| Anti-padrão | Por quê |
|---|---|
| Curator chamando direto Output Renderer (bypass policy) | Quebra governance |
| Voz como primeiro contato em normal/low | Intrusivo |
| Repetir proposal recém-rejeitada | Auto-tuning quebra; usuário cansa |
| Proposal sem `evidence_refs` | Sem fonte rastreável |
| Auto-aplicar sem usuário ver | Quebra principle "Self-Improvement não autoaplica" |
| Critério de "crítico" inflado | Categoria perde sentido |
| Touch sem feedback visual | UX quebrada |
| Snooze infinito | Procrastinação infinita |
| Curator afirmando certeza ("você precisa…") em vez de propor ("posso…") | Quebra confiança |

---

## 13. Decisões pendentes

| Decisão | Bloqueia |
|---|---|
| ⚠️ Lista canônica de categorias com critérios | Implementação |
| ⚠️ Frequency da análise periódica (30min ok?) | Performance |
| ⚠️ Default expires_at por categoria | UX |
| ⚠️ Threshold para "rejection rate alta" auto-tune | Calibração |
| ⚠️ Janela exata de auto-tuning | Calibração |
| ⚠️ Snooze granularity (15min, 1h, customizable?) | UX |
| ⚠️ Reverter aplicação — janela exata | Operacional |

---

## Próximos passos de leitura

- `05-policies/02-interrupcao.md` — interruption_policy formal (a escrever).
- `05-domain-faces.md` — visual identity por Domain.
- `03-camadas-de-vida/04-iniciativa.md` — fundamentos teóricos.
- `08-roadmap/05-fase-4-iniciativa.md` — quando isso entra no roadmap.
