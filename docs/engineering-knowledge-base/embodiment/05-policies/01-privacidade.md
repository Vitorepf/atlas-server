---
id: atlas-embodiment-05-policies-01-privacidade
type: engineering_knowledge
title: "01 — Privacidade"
status: source_material
authority_class: design_reference
implementation_state: design_reference_no_runtime
category: physical-surface
summary: "Source material for the Atlas Embodiment/StackChan physical surface design; not current runtime authority."
canonical_owner: docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md
---
# 01 — Privacidade

> **Propósito:** definir a **política de privacidade do Embodiment** — o que pode ser capturado, sob que condições, com que defaults, com que mecanismos de garantia. Privacidade não é feature: é fundação. Decisões aqui precedem todo o resto.
>
> **Pré-requisitos:** [README](../README.md), [00-introducao/01-visao-geral.md](../00-introducao/01-visao-geral.md), [01-hardware/01-componentes.md](../01-hardware/01-componentes.md).
>
> **Fora do escopo:** outras policies (interrupção, autonomia, degradação) — vão em arquivos vizinhos. Implementação concreta de mute hardware vai pra `06-firmware-stackchan/02-reflex-layer.md`.

---

## 1. Princípios fundadores

Quatro princípios não-negociáveis. Toda decisão deste documento deriva deles.

### P1 — Privacidade é hardware antes de policy

Modo privado físico não é flag em código. É **desligamento físico do mic + indicação visual inegável**. Software bug não pode reativar.

### P2 — Default conservador, opt-in para captura ativa

Captura mais profunda exige consentimento mais explícito. Mic ambient sempre off; wake word local on (não captura). Imagem off; presença binária pode estar on. Cada modalidade tem default que privilegia silêncio.

### P3 — Toda captura é auditável

Cada evento de captura — efetivado ou negado — vai pro Evidence Ledger com timestamp, modalidade, motivo, contexto. Sem audit, não dá pra confiar.

### P4 — Indicação visual quando captura está ativa

Captura sem indicação é vigilância. Mic ativo, câmera ativa: LED vermelho específico (cor reservada, não usada para outra coisa). Sem fallback, sem "modo discreto".

---

## 2. Tabela de defaults

A "matriz da verdade" do Embodiment. Toda modalidade tem entrada. Esta tabela é a fonte canônica.

| Modalidade | Default na Fase 0 | Default em fases ≥ 1 | Como ativar | Onde processa | Indicação visual quando ativo |
|---|---|---|---|---|---|
| **Wake word (mic offline)** | OFF | ON | Implícito ao boot do firmware | Local no DSP | Nenhuma (não é captura) |
| **Mic ambient (sem wake word)** | OFF | OFF | Sempre OFF (não há caso de uso) | N/A | N/A |
| **Mic streaming (após wake word)** | OFF | ON | Pós wake word, por janela curta | Atlas (cloud ou Module-LLM) | LED vermelho fixo + face "te ouvindo" |
| **Câmera presença (binário)** | OFF | ON | Implícito ao boot | Local | LED vermelho dim no anel inferior |
| **Câmera frame (imagem)** | OFF | OFF | Comando explícito do usuário (voz "tira foto" ou touch) | Atlas | LED vermelho fixo + "shutter" sonoro + face "capturei" |
| **Touch pads** | ON (passivo) | ON | Implícito | Local | Highlight ao tocar |
| **NFC** | OFF | ON | Aproximação de tag conhecida | Local (UID) → alma (semântica) | LED azul curto |
| **IMU (gestos)** | OFF | ON | Implícito (mas só gestos pré-definidos) | Local | Nenhuma |
| **Ambient light + proximity** | ON | ON | Implícito (sinal binário/escalar) | Local | Nenhuma |
| **IR receiver** | OFF | OFF | Comando explícito | Local | LED branco curto |
| **BLE peripheral discovery** | OFF | OFF | Comando explícito | Local + alma | LED azul curto |

**Regra geral:** captura que pode revelar conteúdo (mic streaming, imagem) requer ativação explícita. Sinal estrutural (presença binária, luz ambiente) pode ser passivo.

### Por que mic streaming default OFF mesmo em fase 1

Diferente de assistentes comerciais — eles deixam mic streaming "vai sempre que detectar wake word". Aqui:

- Wake word é local. Áudio só sai do robô após detecção.
- Mas após wake word, captura é por **janela limitada** (ex: 6 segundos sem voz nova → desliga).
- **Não há "mic always streaming after wake".** Se o usuário continuar interagindo, alma manda comando explícito para reabrir janela.

Isso evita o caso "o robô começou a captar e nunca parou".

---

## 3. Modos de privacidade

O corpo pode estar em um de quatro modos de privacidade:

### 3.1 `default`

Comportamento da tabela acima. Modalidades nos defaults declarados.

### 3.2 `restricted`

- Sem captura de áudio nem com wake word.
- Sem captura de imagem.
- Sem detecção de presença.
- Sem heartbeat de telemetria que inclua campos sensíveis (battery_pct, location).
- Robô responde só a touch.
- LED amarelo curto para indicar.

Use case: visita em casa, conversa privada de outra pessoa próxima.

### 3.3 `private_physical`

**Modo mais forte.** Garantia de hardware:

- Mic **fisicamente desabilitado** (firmware corta clock do codec ES7210).
- Câmera **fisicamente desabilitada** (corte de power do GC0308 via PMIC).
- Servos travados.
- Display mostra "modo privado" estaticamente.
- LED **vermelho fixo todo o anel** (não-confundível).
- Robô continua reportando telemetria mínima (status), mas nada de captura.

**Como ativar:** combinação de touch física definida (ex: 3 pads simultâneos por 2s).
**Como desativar:** mesma combinação de touch.

Sem combinação física, não sai. Comando da alma **não** pode forçar saída — princípio P1.

### 3.4 `degraded`

Não é modo de privacidade per se, mas tem implicações:
- Sem alma, captura cognitiva não tem destino.
- Mic e câmera **desligados** automaticamente em modo degraded (não há para onde mandar).
- Wake word offline pode permanecer, mas detecções não disparam captura streaming.

---

## 4. Mecanismos de garantia

### 4.1 Modo privado físico (hardware mute)

Implementação **garante** que captura é impossível em `private_physical`:

| Componente | Como é desabilitado |
|---|---|
| Mic (ES7210) | I²C command disable + clock OFF |
| Câmera (GC0308) | Power rail desligado via PMIC AXP2101 |
| Servos | PWM cortado, pinos em estado conhecido |

Implementação no firmware deve ser **um único caminho de código** com testes garantindo que comando externo não bypassa. Audit por revisão.

### 4.2 LED reservado

Cor **vermelho** em padrão fixo é reservada para "captura ativa" e "modo privado". Outras situações **não usam vermelho fixo**, evitando confusão.

| Situação | Padrão LED |
|---|---|
| Mic streaming ativo | Vermelho fixo (todos os 12 LEDs) |
| Câmera capturando frame | Vermelho fixo (curto, 1-2s) |
| Modo private_physical | Vermelho fixo permanente |
| Quality gate falhou | Vermelho fixo? **NÃO.** Use vermelho-pulso ou laranja para distinguir. |
| Erro de sistema | Magenta ou pulso lento, **não vermelho fixo**. |

⚠️ Coordenação necessária com `01-hardware/01-componentes.md` (paleta de LEDs do projeto). Atualizar lá quando confirmado.

### 4.3 Indicação visual de captura

Quando mic streaming ativo, em paralelo ao LED:
- Face do avatar muda para "ouvindo" — visualmente óbvio.
- Display pode mostrar texto curto "captando" se modo o permitir.

Quando câmera captura frame:
- "Shutter" sonoro curto (gerado localmente, não pode ser silenciado por comando).
- Face de "capturei".
- LED breve.

Captura silenciosa **não acontece**. É anti-padrão.

### 4.4 Audit obrigatório

Eventos no Evidence Ledger:

| Evento | Quando |
|---|---|
| `privacy.capture_started` | Mic ou câmera ativa captura |
| `privacy.capture_ended` | Captura termina |
| `privacy.capture_denied` | Tentou capturar, policy negou |
| `privacy.mode_changed` | Mudança de modo (default→restricted etc.) |
| `privacy.physical_mute_entered` | Touch combinado disparou hardware mute |
| `privacy.physical_mute_exited` | Saída do hardware mute |
| `privacy.policy_updated` | Mudança de policy via Curator approval |

Estes eventos **nunca são silenciados, mesmo em private_physical**. São dados estruturais, não conteúdo.

---

## 5. Consentimento

### 5.1 Tipos de consentimento

| Tipo | Como é obtido | Onde é registrado |
|---|---|---|
| **Implícito de boot** | Aceito ao ligar o robô em estado consciente | Setup inicial, registrado uma vez |
| **Implícito de modo** | Mudar para `default` mode aceita os defaults daquele modo | Evento de mode_changed |
| **Por sessão** | Wake word ativa a captura streaming pela duração de uma interação | Cada captura tem evento próprio |
| **Explícito por evento** | "Tira uma foto" → consentimento explícito para esse frame | Evento dedicado |
| **Persistido** | "Pode usar câmera nas reuniões" → ativação contínua condicional | Policy update via Curator |

### 5.2 Princípio do consentimento estruturado

Consentimento é um conceito do Atlas Policy/Profile, **não do firmware**. O corpo só executa o que a alma manda. Mas a alma valida policy antes de mandar.

Se policy diz "câmera só com consent explícito" e Output Renderer recebe um Receipt para tirar foto, o adapter:
1. Valida que Receipt tem `consent_tag: "explicit"`.
2. Se não tem: rejeita o comando, registra no Ledger.
3. Se tem: dispara, registra captura.

---

## 6. Tratamento de dados capturados

### 6.1 Áudio (mic streaming)

| Etapa | Tratamento |
|---|---|
| Captura no corpo | Buffer em PSRAM, não persistido em microSD |
| Transporte | TLS via WS |
| Recepção na alma | Buffer em RAM enquanto STT processa |
| Após STT | Áudio bruto **descartado** por padrão. Texto persiste no Ledger. |
| Retenção opcional de áudio bruto | Off por default. Pode ser ativada por policy explícita (debug). |

### 6.2 Imagem (câmera frame)

| Etapa | Tratamento |
|---|---|
| Captura | Buffer em PSRAM |
| Transporte | TLS via WS |
| Processamento | Conforme intent (analise visual, OCR, etc.) |
| Após processamento | Imagem bruta **descartada** por padrão |
| Retenção | Off; pode ser ativada explicitamente |

### 6.3 Outros sinais

- Touch, NFC, IMU, ambient: dados estruturais, baixo risco. Persistem no Ledger normal.
- Telemetria (battery, thermal, etc.): persistem no Ledger com sample rate reduzido.

---

## 7. Egress — o que sai do robô

Categoricamente:

| Sai? | Modalidade | Para onde | Quando |
|---|---|---|---|
| ✅ | Áudio (após wake word) | Atlas backend | Streaming |
| ✅ | Imagem | Atlas backend | Sob comando |
| ✅ | Eventos estruturais (touch, NFC, IMU, presença) | Atlas backend | Conforme acontecem |
| ✅ | Telemetria | Atlas backend | Heartbeat |
| ❌ | Áudio sem wake word | Nada | Nunca |
| ❌ | Imagem sem comando | Nada | Nunca |
| ❌ | Dado bruto para terceiros | Nada | Nunca diretamente — sempre via alma |

**Regra:** corpo só fala com a alma. Alma fala com providers (com sua própria policy). Provider nunca recebe dado direto do corpo.

---

## 8. Provedores externos (LLM, STT, TTS)

Quando alma envia áudio capturado para um provider (OpenAI, ElevenLabs, etc.):

| Decisão | Política |
|---|---|
| Logging na cloud do provider | Desabilitar quando provider permitir (no_log flag) |
| Reuso de áudio para treinamento | Negar via flag de zero-retention |
| Provider local (Module-LLM) preferível | ✅ quando custo de privacidade > custo de latência |
| Avisar ao usuário onde processa | Idealmente: face ou LED indica "processando localmente" vs "processando na nuvem" |

⚠️ **DECISÃO PENDENTE:** indicar visualmente quando processamento é local vs nuvem? Provavelmente sim, mas mecanismo a definir.

---

## 9. Casos de uso e casos extremos

### 9.1 Visita em casa
- Usuário toca combinação de touch → entra `restricted` ou `private_physical`.
- Robô não responde a comando de voz nem de presença.
- Apenas hora visível, LED amarelo (restricted) ou vermelho (private).

### 9.2 Reunião confidencial
- Tag NFC "PRIVATE" pode acionar `private_physical` automaticamente.
- Saída requer toque combinado (não só remoção de tag).

### 9.3 Modo de viagem (sem alma acessível)
- `degraded` mode automaticamente desabilita captura cognitiva.
- Robô vira relógio + presença visual.

### 9.4 Convidado controlando
- Usuário não-autorizado faz wake word — alma responde "não te conheço" sem processar mais.
- Detecção quem é o usuário é cognitiva (alma), não no corpo.
- Para Fase inicial, **não há multi-usuário**. Único usuário (o dono) é assumido.

### 9.5 Ataque de áudio (replay de "Atlas, ...")
- Wake word não autentica o falante.
- Alma decide se processa (pode pedir confirmação visual via touch antes de ações sensíveis).
- Comandos sensíveis (compras, exclusão de dados) **sempre** requerem segundo fator (touch específico, NFC).

### 9.6 Troca de robô (corpo novo)
- Provisioning gera novo token. Robô antigo é revogado.
- Mas qualquer dado em microSD do robô antigo precisa ser **apagado** antes de descarte.
- Documentação de descarte vai em `09-testes/` ou apêndice operacional.

---

## 10. Mudança de policy

Policy não é imutável. Pode evoluir:

- Curator pode propor mudança ("você nunca usa modo restricted; remover?").
- Usuário aprova explicitamente via touch ou approval físico.
- Mudança vira evento `privacy.policy_updated` no Ledger.

**Princípio:** policy não muda silenciosamente. Cada mudança é approval explícito + auditável.

Direção da mudança importa:
- **Mais restritivo:** baixa fricção. Curator ou usuário pode sugerir e aplicar com confirmação.
- **Menos restritivo:** alta fricção. Requer approval explícito do usuário, com janela de cooldown (ex: pode aplicar daqui 24h, dando tempo pra cancelar).

---

## 11. Subset da Fase 0

Fase 0 (Espelho) tem privacy especialmente simplificado:

- Mic, câmera, NFC, IMU gestures: **todos desabilitados em código**.
- Touch pads ativos mas sem ação cognitiva (só highlight visual).
- Ambient light + proximity: ativos (sinais estruturais, baixo risco).
- Sem captura nenhuma. Sem egress de dado sensível.

Privacy começa "fácil" porque hardware está dormindo. À medida que fases ativam modalidades, esta policy precisa ser implementada concretamente.

---

## 12. Anti-padrões

| Anti-padrão | Por quê é grave |
|---|---|
| Captura silenciosa (sem LED) | Vigilância |
| Mic always-on para "melhor wake word" | Quebra P2 — wake word local resolve |
| Áudio bruto persistido por padrão | Quebra P3 — captura sem necessidade |
| Modo private só por software | Quebra P1 — bug pode bypassar |
| Indicação ambígua (vermelho usado pra outra coisa) | Confunde usuário; perde garantia visual |
| Policy mudada via API silenciosa | Quebra audit trail |
| Provider third-party recebendo dado direto do corpo | Bypass do Atlas Pipeline |
| Cloud logging do provider habilitado | Vazamento downstream |
| "Modo discreto sem LED" | Pedido recorrente. Sempre rejeitar. |

---

## 13. Decisões pendentes

| Decisão | Bloqueia |
|---|---|
| ⚠️ Cor exata do LED de "captura ativa" — vermelho é claro, mas qual padrão? | Implementação |
| ⚠️ Janela de captura streaming pós wake word — segundos exatos | Fase 1 |
| ⚠️ Indicação visual "processamento local vs nuvem" — como? | UX |
| ⚠️ Combinação de touch para `private_physical` — qual? | Implementação |
| ⚠️ Multi-usuário — quando entra no escopo? | Roadmap pós Fase 5 |
| ⚠️ Política de descarte de microSD/firmware | Operacional |

---

## 14. Resumo em 4 frases

1. **Privacidade é hardware antes de policy.** Mute físico não-bypassável.
2. **Default é silêncio.** Captura ativa é opt-in por modalidade, com auditoria total.
3. **Indicação visual é não-negociável.** Cor vermelha reservada para captura ou modo privado.
4. **Tudo passa pela alma.** Corpo nunca fala com providers; nada vaza sem audit.

---

## Próximos passos de leitura

- `02-interrupcao.md` — quando alma pode interromper.
- `03-autonomia.md` — o que pode ser auto-aplicado, o que requer approval.
- `04-degradacao.md` — comportamento em modo offline.
- `06-firmware-stackchan/02-reflex-layer.md` — implementação concreta do hardware mute.
