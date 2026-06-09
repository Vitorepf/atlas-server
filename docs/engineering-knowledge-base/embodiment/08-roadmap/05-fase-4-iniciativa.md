---
id: atlas-embodiment-08-roadmap-05-fase-4-iniciativa
type: engineering_knowledge
title: "Fase 4 — Iniciativa"
status: source_material
authority_class: design_reference
implementation_state: design_reference_no_runtime
category: physical-surface
summary: "Source material for the Atlas Embodiment/StackChan physical surface design; not current runtime authority."
canonical_owner: docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md
---
# Fase 4 — Iniciativa

> **Propósito:** ativar **iniciativa governada** — Curator apresenta proposals via interruption_policy, eventos críticos podem interromper, touch pads ganham semântica de approval, NFC funciona como atalho de fluxo. **A fase mais perigosa do projeto.** Mal calibrada, vira Alexa irritante; bem calibrada, vira presença ativa útil. Ao final da Fase 4, o Atlas atinge a **camada de vida L4 (iniciativa)**.
>
> **Pré-requisitos:** [README](../README.md), [Fase 0–3](.), [03-camadas-de-vida/04-iniciativa.md](../03-camadas-de-vida/04-iniciativa.md), [05-policies/01-privacidade.md](../05-policies/01-privacidade.md).
>
> **Fora do escopo:** caráter (Fase 5), polimento contínuo (vida do projeto).

---

## 1. Por que esta fase

### O risco que estamos mitigando

A Fase 3 deixou Curator com fila de proposals e padrões mapeados — mas tudo silencioso. Fase 4 abre o canal: Atlas começa a falar primeiro.

Sem essa fase, o Embodiment é um **assistente reativo elegante**. Com ela, vira **presença ativa**. Diferença qualitativa enorme.

Mas se for malfeita: confiança quebrada. Usuário liga modo silencioso permanente. L4 morre na prática, e com ela L5 (caráter).

### Princípio orientador

> **Iniciativa é privilégio governado. Nunca um direito.**

A `interruption_policy` é a peça mais crítica do projeto. Implementá-la corretamente é o trabalho central desta fase.

---

## 2. Definição funcional

Ao final da Fase 4:

| Comportamento | Acontece quando |
|---|---|
| Curator proposal apresentada com **gradiente** (LED → card silencioso → voz) | Quando policy permite |
| Eventos críticos (build down, security alert) interrompem mesmo em DND | Prioridade `critical` |
| DND bloqueia tudo exceto crítico | Modo respeitado |
| Private bloqueia voz, mantém indicação visual mínima | Modo respeitado |
| `private_physical` bloqueia tudo | Modo absoluto |
| Touch pads (1, 2, 3) com semântica fixa | Mapeamento aplicado |
| Pad 1 = ✓ aprovar; Pad 2 = ✗ rejeitar; Pad 3 = 💬 explicar | Em contexto de proposal pendente |
| NFC tags mapeadas a fluxos pré-definidos | Tag aproximada |
| Auto-tuning reduz frequência de categorias rejeitadas | Após ignorados/rejeitados |
| Rate limit por hora ativo | Máximo de N interrupções/hora |
| Câmera frame disponível com consent explícito | Comando "tira foto" ou tag específica |
| IR transmit funcional (apagar luz, etc.) | Comando do Runtime |
| Snooze de proposal via touch | Adia X minutos |
| "Modo silencioso virou default" detectado | Curator alerta |

### O que **NÃO** muda nesta fase

- Caráter ainda emergente — não há "implementação de personalidade" (ver `03-camadas-de-vida/05-carater.md`).
- Todas funcionalidades anteriores (voz, corpo, continuidade) continuam.

---

## 3. Arquitetura mínima implementada

### 3.1 Na alma (Atlas)

| Componente | Estado em Fase 4 |
|---|---|
| `interruption_policy` formal | ✅ |
| Priority levels (critical, high, normal, low, background) | ✅ |
| Mode-aware allowance (qual prioridade passa em qual modo) | ✅ |
| Auto-tuning baseado em outcomes | ✅ |
| Rate limits | ✅ |
| Curator proposal apresentação fluxo completo | ✅ |
| Critical events handler | ✅ |
| Touch semantic mapper (pad → action no contexto) | ✅ |
| NFC tag → intent mapping configurável | ✅ |
| Camera capture handler com consent | ✅ |
| IR transmit handler (Runtime tool) | ✅ |
| Snooze handler | ✅ |
| Drift detection ("modo silencioso default") | ✅ |
| Approval workflow físico (Curator proposal → touch response → effect) | ✅ |

### 3.2 No corpo (firmware)

| Componente | Estado em Fase 4 |
|---|---|
| Touch pads com `interaction_hint` recebido da alma | ✅ |
| Câmera frame capture (com consent indication: LED + shutter + face) | ✅ |
| NFC reader ativo + UID + NDEF read | ✅ |
| IR transmitter funcional (codes mapeados) | ✅ |
| Renderer de proposal (LED laranja + card específico) | ✅ |
| Snooze button (touch combo dedicado) | ✅ |
| Indicação física inegável de captura de imagem | ✅ |

### 3.3 Protocolo

**Modalidades de input adicionadas:**
- `nfc_tag` ⭐
- `imu_gesture` (já existia, ganha mais usos)
- `touch_pad` agora com `interpretation_hint` baseado em contexto da alma
- `camera_image` ⭐ (com `consent_tag` obrigatório)
- `voice_short_command` (para confirmações curtas pós-proposal)

**Modalidades de output adicionadas:**
- `ir_transmit` ⭐
- `card_render` com `interaction_hint` (quais pads ativos)
- `face_render` com expressões: `proposing` (Curator falando), `awaiting_approval`, `acknowledging_approval`

**Modos:**
- Todos modos ativos.
- Comportamento de DND/private formalmente suprimindo iniciativa exceto crítico.

**Eventos no Ledger adicionados:**
- `curator.proposal.presented_via_surface`
- `curator.proposal.approved_via_touch`
- `curator.proposal.rejected_via_touch`
- `curator.proposal.snoozed`
- `curator.proposal.timeout`
- `policy.auto_adjusted.category_lowered` / `category_paused`
- `policy.silence_mode_drift_detected`
- `physical.nfc.read`
- `privacy.capture.image.started` (com consent_type)
- `physical.ir.transmitted`
- `surface.command.dispatched.ir.transmit`

---

## 4. Critérios de aceitação

### 4.1 interruption_policy funcional

- [ ] Cada proposal/evento tem prioridade declarada.
- [ ] Modo do corpo é consultado antes de qualquer apresentação.
- [ ] Rate limit ativo: máximo 3 interrupções/hora normal, 5/hora se acumulou em DND.
- [ ] Min gap entre interrupções: 5 min.
- [ ] Critical events bypass rate limit (mas não modos absolutos).

### 4.2 Apresentação gradiente

- [ ] Proposal normal começa com LED laranja pulsante (silencioso).
- [ ] Sem reação do usuário em 30s: card silencioso adicionado.
- [ ] Sem reação em 60s adicionais: para low/normal, **descartar** ou adicionar card minimalista; **não escalar para voz** automaticamente.
- [ ] High priority: pode escalar para voz após 60s.
- [ ] Critical: voz + face + LED imediato.

### 4.3 Modos respeitados

- [ ] DND: só critical interrompe.
- [ ] Private: só critical, sem voz, com indicação visual mínima.
- [ ] Private_physical: nada interrompe (zero).
- [ ] Standby: critical pode disparar wake-up.

### 4.4 Touch semântica

- [ ] Pad 1 = aprovar funciona em proposal pendente.
- [ ] Pad 2 = rejeitar / dismiss funciona.
- [ ] Pad 3 = explicar mais / abrir interação funciona.
- [ ] Mapeamento **não muda** entre proposals (consistência).
- [ ] Touch sem proposal pendente: registra evento simples, sem ação.
- [ ] Snooze (combo definido): adia 15min.

### 4.5 Auto-tuning

- [ ] Categoria rejeitada 3x → próxima dessa categoria entra com prioridade reduzida.
- [ ] Categoria ignorada 5x → pausada por 24h.
- [ ] Categoria aceita consistentemente → mantém prioridade.
- [ ] Detecção de "modo silencioso virou default" (>30% do dia) → Curator gera alerta.
- [ ] Auto-tuning é **conservador** — reduz mais facilmente que aumenta.

### 4.6 Eventos críticos

- [ ] Lista de tipos de evento crítico documentada (build prod down, security alert, dispositivo crítico offline, drift severo do Atlas).
- [ ] Evento crítico chega mesmo em DND.
- [ ] Evento crítico chega mesmo em private (com indicação visual obrigatória).
- [ ] Evento crítico **não chega** em private_physical.
- [ ] Critério de "crítico" auditável — Curator pode revisar inflados.

### 4.7 NFC

- [ ] Tags físicas etiquetadas para discoverability.
- [ ] Tag → intent mapping configurável via comando + approval.
- [ ] NFC read em <500ms responde com feedback (LED azul curto + face).
- [ ] Tags em modo private não disparam fluxos cognitivos.

### 4.8 Câmera frame (com consent)

- [ ] LED vermelho fixo + face de "captura" + shutter sonoro **antes** do frame ser capturado.
- [ ] Imagem viaja para alma com `consent_tag = "explicit"`.
- [ ] Sem comando explícito: zero captura.
- [ ] Em private: captura recusada visualmente.

### 4.9 IR transmit

- [ ] Comando IR registra evento `physical.ir.transmitted` no Ledger.
- [ ] Códigos mapeados a dispositivos com `device_label`.
- [ ] Falha de IR (código inválido, etc.) registrada como `command.failed`.

### 4.10 Anti-critérios

- [ ] Iniciativa em private_physical (zero tolerância).
- [ ] Curator proposal repetida na mesma sessão após rejeição.
- [ ] Voz como primeiro contato em proposal normal/low.
- [ ] "Crítico" inflado para tudo (auditoria).
- [ ] Touch sem feedback visual.
- [ ] Captura de imagem sem indicação física inegável.

---

## 5. Trabalho concreto envolvido

### 5.1 Atlas backend (alma)

1. **`interruption_policy` engine** — implementação central. Alta prioridade.
2. **Auto-tuning** — tracking de outcomes, ajuste de prioridades por categoria.
3. **Curator proposal presenter** — fluxo completo: queue → policy check → bundle compose → render → wait response.
4. **Critical event handler** — pipeline dedicado, prioridade absoluta.
5. **Touch semantic resolver** — sabendo qual proposal está pendente, mapeia pad → action.
6. **NFC mapping table** — Policy/Profile com UID → intent.
7. **Camera capture flow** — handler de comando com validação de consent, indicação física, capture, processamento.
8. **IR transmit tool** — Runtime tool para acionar IR via Output Renderer.
9. **Drift detector** — análise periódica para detectar "silence mode default", proposal storm, etc.
10. **Snooze handler** — fila com TTL, re-apresentação após snooze.

### 5.2 Firmware (corpo)

1. **Touch interaction_hint integration** — receber hint do card, ativar feedback contextual.
2. **NFC reader integration** — driver, decode UID/NDEF, evento Envelope.
3. **Câmera frame capture pipeline** — driver, JPEG encode, indicação visual obrigatória, transmissão.
4. **IR TX driver** — biblioteca, codes diversos, transmissão.
5. **Proposal render** — LED laranja pulsante, card específico de proposal, transição suave.
6. **Snooze combo handler** — touch combo dedicado, ack visual.

### 5.3 Documentação adicional necessária

- ⬜ `05-policies/02-interrupcao.md` — interruption_policy detalhada formal.
- ⬜ `07-integracao-atlas/04-curator-proposals.md` — fluxo completo.
- ⬜ ADR sobre lista de eventos críticos.
- ⬜ ADR sobre rate limits.
- ⬜ ADR sobre catálogo inicial de NFC tags úteis.

---

## 6. O que esta fase prova / valida

| Hipótese | Como é validada |
|---|---|
| `interruption_policy` impede drift para Alexa | Modo silencioso < 30% do dia, aceitação > 60% |
| Auto-tuning calibra ao longo do tempo | Categorias rejeitadas reduzidas; aceitas mantidas |
| Touch como aprovação de baixa fricção funciona | Tempo de approval < 10s típico |
| NFC como atalho físico tem discoverability viável | Tags usadas regularmente |
| Câmera com consent funciona com privacy intacta | Zero captura sem indicação |
| Eventos críticos atingem usuário em modos restritivos | Teste explícito com simulação |
| L4 (iniciativa) sustentável | Sensação acumulada após 30+ dias: "útil sem cansar" |

---

## 7. Riscos da fase

### 7.1 **Drift para Alexa** (risco principal)
Sintoma: modo silencioso virando default. Solução: detector + Curator alerta + revisão de policy + redução de iniciativa.

### 7.2 Confiança quebrada
Uma sequência ruim de interrupções → usuário perde confiança permanentemente. Solução: começar conservador, escalar lentamente, monitorar feedback.

### 7.3 Crítico inflado
Tudo vira crítico → categoria perde sentido. Solução: revisão regular, critérios objetivos, auditoria de Curator.

### 7.4 Auto-tuning enviesado
Dia ruim → rejeita tudo → categorias úteis sumindo. Solução: memória curta de tuning, threshold mínimo, possibilidade de reset.

### 7.5 NFC tags sem discoverability
Usuario esquece o que cada tag faz. Solução: etiquetas físicas, lista no card "ajuda", tags poucas e bem escolhidas.

### 7.6 Câmera capture acidental
Comando ambíguo dispara captura. Solução: critério de comando explícito alto; captura passiva inviável.

### 7.7 IR conflitando com outros remotos
Comando IR repetido / errado. Solução: códigos validados, registrar e auditar uso.

### 7.8 Snooze permanente
Usuário sempre snoozeia → proposal nunca é resolvida. Solução: limite de snoozes (ex: 2x), depois descarta.

---

## 8. Saída da fase / entrada da Fase 5

### Critério de saída
Todos os critérios de aceitação satisfeitos **em uso real de no mínimo 60 dias**. Iniciativa só se valida com tempo prolongado.

### Antes de iniciar Fase 5:
- ✅ Critérios da Fase 4 satisfeitos.
- ✅ `interruption_policy` documentada e versionada.
- ✅ Auto-tuning calibrado conforme uso real.
- ✅ Lista de eventos críticos auditada.

### O que Fase 5 herda
- Sistema completo: voz, corpo, continuidade, iniciativa governada.
- Padrões de uso bem mapeados.
- Identidade preservada.
- **Tudo pronto para caráter emergir** — bastará tempo + disciplina de configuração.

---

## 9. Anti-padrões específicos desta fase

| Anti-padrão | Como evitar |
|---|---|
| TTS chamativo como primeiro contato | Apresentação gradiente é dura |
| Repetir proposal recém-rejeitada | Auto-tuning manda sair |
| "Critério de crítico flexível" | Critério objetivo documentado |
| Snooze infinito | Limite |
| Touch sem feedback | Acks visuais imediatos |
| Captura de imagem com indicação fraca | LED + shutter + face — todos os três |
| NFC tag mapping editável sem approval | Mudança = approval; auditável |
| "Curator pode interromper sem policy nesse caso especial" | Sempre policy. Sem exceção. |
| Ignorar drift de modo silencioso | Sinal vital — Curator precisa alertar |

---

## 10. Resumo

**Objetivo:** iniciativa governada, calibrada, sustentável.
**Camada de vida atingida:** L4 (iniciativa).
**Duração estimada:** 6-8 semanas (60 dias mínimos de uso).
**Crítico de validar:** modo silencioso < 30%, aceitação > 60%.
**Saída:** Fase 5 (caráter emergente) — tudo pronto pra **deixar funcionar e caráter emergir**.
**Tom da fase:** humildade. Sistema vai cometer erros de calibração. Auto-tuning + revisão regular salvam.

---

## Próximos passos de leitura

- `06-fase-5-carater.md` — fase final.
- `05-policies/02-interrupcao.md` — interruption_policy detalhada (a escrever).
- `07-integracao-atlas/04-curator-proposals.md` — fluxo completo (a escrever).
- `03-camadas-de-vida/04-iniciativa.md` — fundamentos teóricos.
