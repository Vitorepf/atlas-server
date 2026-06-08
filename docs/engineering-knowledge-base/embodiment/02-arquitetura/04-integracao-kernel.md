# 04 — Integração com o Atlas Kernel

> **Propósito:** mapear, **estágio por estágio**, como o Embodiment se encaixa no Atlas Kernel Pipeline. Cada estágio do pipeline tem um papel específico em relação ao corpo físico — alguns recebem dados, alguns recebem influência sutil (sinais físicos contextuais), outros são afetados em direção (qual surface vai receber a saída).
>
> **Pré-requisitos:** [README](../README.md), [01-corpo-vs-alma.md](01-corpo-vs-alma.md), [02-loops-temporais.md](02-loops-temporais.md), [03-modalidades.md](03-modalidades.md), [07-integracao-atlas/01-surface-adapter.md](../07-integracao-atlas/01-surface-adapter.md).
>
> **Fora do escopo:** detalhes de implementação dos componentes do Atlas em si (assumidos como dados); trabalhamos só na fronteira.

---

## 1. Recapitulação do pipeline

```
[Surface Layer]
       ↓
[Atlas Input]
       ↓
[Operation Envelope Created]
       ↓
[Atlas Intent / Routing]
       ↓
[Atlas Decide]  ◄────── (Evidence Ledger / Memory Signals alimentam)
       ↓
[Decision Receipt Attached]
       ↓
[Domain Plane: Programming, Finance, Personal Dev, Marketing, Self-Improvement]
       ↓
[Context Builder: Open Brain, memória, code intelligence, KB]
       ↓
[Policy / Profile]
       ↓
[Runtime / Executor: Super Tool Runtime, Providers, Harness]
       ↓
[Quality Gates] → [Repair Loop] (se falha)
       ↓
[Output Renderer]
       ↓
[Surface (de volta)]
```

Para cada estágio, este documento responde: **o que muda quando o Embodiment está em jogo?**

---

## 2. Surface Layer

### Papel base no Atlas
Camada que recebe entradas de qualquer origem (Usuário/App/CLI/API).

### Mudança com Embodiment

O `stackchan` se registra como nova surface no Surface Layer.

- Surface ID por corpo (ex.: `stackchan_main`).
- Tipo `stackchan` reconhecido.
- Tudo que entra carrega `surface = "stackchan"` no `Operation Envelope`.

Implementação concreta = **Surface Adapter** (`07-integracao-atlas/01-surface-adapter.md`).

### Implicação
Outras surfaces (CLI, app) seguem inalteradas. Embodiment é **adição**, não substituição.

---

## 3. Atlas Input

### Papel base
Ponto de entrada do pipeline. Aceita texto, imagem, arquivo, áudio, contexto.

### Mudança com Embodiment

Atlas Input recebe um novo **adapter de entrada** — o `Interaction Envelope` traduzido em `Operation Envelope` (mapeamento detalhado em `07-integracao-atlas/01-surface-adapter.md`).

Conteúdo único do Embodiment que enriquece Atlas Input:

| Tipo | Vindo de | Como entra |
|---|---|---|
| Texto | STT pós voice_streaming | Como qualquer texto |
| Áudio bruto | voice_streaming | Como input de áudio (existente) |
| Imagem | camera_image | Como imagem (existente) |
| Sinais físicos | context_signals | Em campo `context` do Operation Envelope (NOVO) |
| Modalidades múltiplas | Envelope multimodal | Em `payload.modalities` (NOVO) |
| Trigger não-textual | touch, NFC, presence | Em `payload.trigger` (NOVO) |

### Princípio
Atlas Input não interpreta. Apenas garante o formato canônico.

---

## 4. Operation Envelope Created

### Papel base
Empacota a operação como unidade canônica.

### Mudança com Embodiment

Operation Envelope ganha campos opcionais (já existentes ou expandidos):

```yaml
operation_envelope:
  surface: { id: stackchan_main, type: stackchan }
  request_type: voice_query | touch_approval | nfc_trigger | presence_change | ...
  payload:
    trigger: { ... }              # Como veio
    modalities: [ ... ]           # Quais canais usados
    intent_hint: { ... }          # Reflex local
  context:                        # NOVO em forma — sinais físicos
    user_present: bool
    ambient_light_lux: int
    ambient_sound_level: enum
    battery_pct: int
    privacy_mode: enum
    ...
  metadata:
    source_envelope_id: ...       # Ref ao Interaction Envelope
    surface_capabilities: [...]
```

### Principle
Operation Envelope é **genérico ao tipo de surface**. A diferença do Embodiment está em **ter mais sinais de contexto** que CLI não tem.

---

## 5. Atlas Intent / Routing

### Papel base
Entende pedido, risco, domínio, tipo de tarefa.

### Mudança com Embodiment

#### O que ganha
- **Multimodal context** — pode usar `intent_hint` (reflex local) como dica.
- **Trigger types não-textuais** — `touch_approval`, `nfc_trigger`, `presence_change`, `scheduled` são novos tipos de operação.
- **Sinais físicos** podem **rotear** ("usuário em modo foco" → pular interação opcional).

#### O que **não** muda
- A lógica central de classificação de intent permanece.
- Voice queries são apenas texto após STT — entram como texto normal.

#### Exemplo de mudança
Mesmo intent ("verifica status do build") roteado diferentemente conforme surface:
- Via CLI: resposta textual longa, formato terminal.
- Via stackchan: resposta verbal curta + card visual.

Roteamento sabe disso para escolher fluxo (quando há sinais para o Output Renderer ajustar).

---

## 6. Atlas Decide

### Papel base
Escolhe domínio, fluxo, modelo, budget, gates.

### Mudança com Embodiment

#### Sinais físicos como entrada de decisão
Decide pode considerar:

| Sinal | Influência possível |
|---|---|
| `user_present: false` | Adia interação opcional, registra silenciosamente |
| `current_mode: do_not_disturb` | Suprime tudo exceto crítico |
| `current_mode: private` | Recusa qualquer captura/output sonoro |
| `current_mode: degraded` | Fall-through para nada (corpo já está em fallback) |
| `battery_pct: low` | Evita workloads pesados que dependem do corpo |
| `last_interaction_age_s: short` | Conversation continuation; reusar contexto |
| `ambient_sound_level: noisy` | Prefere resposta visual sobre sonora |

#### Decisão de target_surface
Decide pode escolher onde a resposta vai:
- Mesmo surface que originou (default).
- Outra surface se contexto manda (ex.: `camera_image` requisitada via voz, mas resposta detalhada vai pro app).
- Múltiplas surfaces simultaneamente (raro).

Resulta em `decision_receipt.target_surfaces: [...]`.

#### Princípio
Decide continua sendo **o único decisor cognitivo**. Sinais físicos enriquecem mas não decidem por ele.

---

## 7. Decision Receipt

### Papel base
Contrato e limites da decisão. Toda execução em cima dela.

### Mudança com Embodiment

Receipt ganha campos relacionados a output físico:

```yaml
decision_receipt:
  ...
  target_surfaces: ["stackchan_main"]    # Para onde vai
  output_intent:                         # Intenção semântica (não comando físico)
    primary_modality: voice              # voice | visual | mixed | silent
    duration_hint_ms: 3500
    importance: low | medium | high
    interruptible: true
  consent_tags: ["explicit"]             # Para captura, se aplicável
  privacy_constraints:                   # Limites
    - cannot_use: [camera_image]
    - require_indication: true
```

Output Renderer especializado lê `output_intent` e produz comandos físicos concretos.

### Princípio
Receipt continua abstrato. Não diz "ligar LED 3 em verde". Diz "respond positivamente com indicação visual de sucesso". Output Renderer traduz.

---

## 8. Domain Plane

### Papel base
Domínios verticais: programming, finance, personal_dev, marketing, self_improvement.

### Mudança com Embodiment

Cada Domain pode definir **expressão facial associada** (mapeamento Domain → face palette/expression). Isso vai detalhado em `07-integracao-atlas/05-domain-faces.md` (a escrever), aqui só o esqueleto:

| Domain | Expressão facial recomendada | Paleta LED |
|---|---|---|
| Programming | `attentive`, `focused` | Azul calmo |
| Finance | `analytical`, `informative` | Verde-azulado |
| Personal Dev | `warm`, `attentive` | Laranja-suave |
| Marketing | `expressive`, `informative` | Magenta calmo |
| Self-Improvement | `concerned`, `informative` | Roxo-azulado |

Quando um Domain está ativo, expressão visual reflete. Visualmente, o usuário **vê em qual domínio** o Atlas está operando.

#### Princípio
Domain não comanda o corpo diretamente. Sugere via Receipt; Output Renderer aplica.

---

## 9. Context Builder

### Papel base
Open Brain, memória, code intelligence, KB.

### Mudança com Embodiment

Context Builder ganha **fonte de sinais físicos** como parte do contexto.

```
Context Builder consulta:
  ├─ Open Brain / KB (existente)
  ├─ Memória (existente)
  ├─ Code intelligence (existente)
  └─ Embodiment context (NOVO)
        ├─ Telemetria atual de surfaces conectadas
        ├─ Última presença detectada
        ├─ Modos atuais (privacy, dnd)
        └─ Histórico recente de interações físicas
```

#### Exemplo
Pergunta no chat: "estou disponível para uma reunião agora?"
- Context Builder consulta calendário (existente).
- E também: "presença detectada há 3min, modo `interaction`, ambient `quiet`" (novo).
- Atlas pode responder considerando ambos.

#### Princípio
Sinais físicos são uma **fonte de contexto entre outras**, não substituem memória ou KB.

---

## 10. Policy / Profile

### Papel base
Permissão, privacidade, autonomia, custo.

### Mudança com Embodiment

Policy precisa de **sub-policies específicas do Embodiment**:

| Sub-policy | Detalhe |
|---|---|
| `embodiment.privacy` | Defaults por modalidade — `05-policies/01-privacidade.md` |
| `embodiment.interruption` | Quando alma pode disparar voz/face — `05-policies/02-interrupcao.md` (a escrever) |
| `embodiment.autonomy` | O que pode ser auto-aplicado vs requerer approval físico — `05-policies/03-autonomia.md` (a escrever) |
| `embodiment.degradation` | Comportamento offline — `05-policies/04-degradacao.md` (a escrever) |

Estas policies aplicam a **toda operação que tem stackchan como surface** (input ou output). Outras surfaces seguem suas próprias.

#### Princípio
Policies são compostas. Embodiment **adiciona** restrições ou permissões; nunca contorna policies gerais.

---

## 11. Runtime / Executor

### Papel base
Provider, harness, tools, workers.

### Mudança com Embodiment

**Runtime ganha um novo tipo de "tool":** comandos físicos.

Quando um fluxo precisa atuar fisicamente (ex.: comandar luz via IR, mover servo para apontar para algo), Runtime invoca o **Output Renderer especializado** como se fosse uma tool — com Receipt, audit, gates.

Modelo conceitual:
```
Runtime executes step → calls "stackchan.ir_transmit" → Surface Adapter sends command → ACK → step done
```

Isso preserva o princípio "Runtime não executa sem Receipt": comandos físicos seguem o mesmo contrato que qualquer tool.

#### Casos
- Atlas decide apagar luz (Personal Dev → modo foco) → step do runtime invoca `stackchan.ir_transmit(tv_living_room.power)`.
- Atlas pede confirmação do usuário antes de ação destrutiva → step invoca `stackchan.show_card(approval_pending)` e aguarda evento de touch_pad.

#### Princípio
Atuação física é **tool**. Não é caminho lateral.

---

## 12. Quality Gates

### Papel base
Segurança, testes, SLO, compliance.

### Mudança com Embodiment

Quality Gates ganham verificações específicas:

| Gate | Verifica |
|---|---|
| `surface_available` | Surface alvo está conectada e em modo compatível |
| `privacy_compliant` | Nenhum comando viola policy de privacidade |
| `consent_present` | Comandos com `consent_tag` necessário têm |
| `mode_compatible` | Comandos compatíveis com `current_mode` (não tts em dnd, etc.) |
| `capability_present` | Surface declara capability necessária |
| `command_within_limits` | Pan/tilt em range, volume razoável, etc. |

Falha em qualquer gate → bloqueia execução, dispara Repair Loop ou registra como falha cognitiva.

#### Princípio
Gates físicos são **iguais a outros gates** — não negociam, não pulam. Falha = bloqueio.

---

## 13. Repair Loop

### Papel base
Corrige, reexecuta, limita tentativas.

### Mudança com Embodiment

Falhas físicas comuns que disparam Repair:

| Falha | Estratégia de Repair |
|---|---|
| Comando rejeitado pelo corpo (validação) | Re-empacotar com correção, max 1 tentativa |
| Comando expirou (TTL) | Reformular se ainda relevante |
| Surface offline | Falhar Receipt, registrar, esperar reconectar |
| ACK timeout | Re-enviar (idempotente por command_id) |
| Quality Gate falhou | Trocar fluxo (ex.: text-only se voz não pode) |

Limite de tentativas físicas: **baixo** (1-2). Loop infinito de retry de comando físico polui o ambiente.

#### Princípio
Repair retorna via policy/receipt/decide. Comandos físicos seguem mesmo padrão.

---

## 14. Output Renderer

### Papel base
Resposta, patch, plano, proposta.

### Mudança com Embodiment

**O Output Renderer geral delega para um Output Renderer especializado por surface.**

Para `stackchan`, o renderer especializado:
1. Lê Decision Receipt (output_intent abstrato).
2. Compõe um **bundle** de comandos físicos (`04-protocolos/03-comandos-fisicos.md`).
3. Submete via Surface Adapter.

Detalhes em `07-integracao-atlas/02-output-renderer.md` (a escrever).

#### Casos
- Output só verbal (CLI) → renderer especializado para CLI.
- Output multimodal (stackchan) → renderer compõe face + voz + LED + servo + card.
- Multi-surface (raro) → múltiplos renderers, coordenados.

#### Princípio
Renderer **não decide nada novo**. Apenas traduz Receipt em comandos da surface alvo.

---

## 15. Evidence Ledger

### Papel base
Eventos append-only, replay, auditoria.

### Mudança com Embodiment

Embodiment é **prolífico** em eventos. Catálogo em `04-protocolos/02-eventos-evidence.md`:

- Eventos de surface lifecycle (connect, disconnect, mode changes)
- Eventos de cada Envelope recebido
- Eventos de cada comando despachado/ACKed/falhado
- Eventos de privacy (capture started/ended, mode changes)
- Eventos de heartbeat (sample rate reduzido)

Volume é alto — Ledger precisa de:
- Sample rate por tipo de evento (heartbeat vai a 1/10).
- Indexes por surface_id + tipo + timestamp.
- Retenção configurável (telemetria pode ser mais agressiva que eventos relacionais).

#### Princípio
Tudo relevante vira Evidence — mas "relevante" tem granularidade. Telemetria contínua é amostrada; eventos discretos são todos persistidos.

---

## 16. Learning / Memory Signals

### Papel base
Memória, métricas, sinais de aprendizado.

### Mudança com Embodiment

Sinais únicos do Embodiment alimentam Memory:

| Sinal | Uso |
|---|---|
| Frequência de wake word | Saúde do reconhecimento |
| Taxa de aprovação/rejeição via touch | Calibração de propostas do Curator |
| Tempo entre presença e primeira interação | Padrão de uso |
| Modo predominante por hora | Perfil de uso |
| Latência percebida (Receipt → ACK final) | SLO físico |

Esses sinais alimentam Curator e podem virar proposals.

#### Princípio
Learning não altera comportamento crítico sem proposal/review — vale aqui também. Padrões físicos viram propostas, não automatismos invisíveis.

---

## 17. Self-Improvement / Curator

### Papel base
Proposals, review, gaps, bugs, drift, melhorias.

### Mudança com Embodiment

Curator pode propor mudanças no comportamento físico:

| Tipo de proposal físico | Exemplo |
|---|---|
| Ajuste de paleta facial por Domain | "Programming domain detectou só uso noturno; trocar paleta para dim-blue" |
| Calibração de servo idle | "Movimento idle muito frequente (você relatou ruído); reduzir frequency" |
| Ritual de heartbeat | "Bom dia às 9h funciona — manter; check 16h ignorado em 8/10 — remover" |
| Threshold de wake word | "Falsos positivos altos em ambiente atual; aumentar threshold" |

Proposals são **apresentadas via Embodiment** (face do Curator + card + touch para aprovar/rejeitar) — fluxo descrito em `07-integracao-atlas/04-curator-proposals.md` (a escrever).

#### Princípio
Curator não autoaplica — mesma regra. Aplicação depende de approval do usuário (touch ou comando explícito).

---

## 18. Visão geral em uma tabela

| Estágio do Pipeline | O que muda com Embodiment |
|---|---|
| Surface Layer | Registra `stackchan` como surface |
| Atlas Input | Aceita Interaction Envelope traduzido |
| Operation Envelope | Inclui sinais físicos no `context` |
| Intent / Routing | Considera trigger não-textual e sinais |
| Atlas Decide | Usa sinais físicos como entrada de decisão; escolhe target_surface |
| Decision Receipt | Inclui `target_surfaces`, `output_intent` |
| Domain Plane | Cada Domain tem expressão visual associada |
| Context Builder | Embodiment context é fonte adicional |
| Policy / Profile | Sub-policies específicas (privacy, interruption, autonomy, degradation) |
| Runtime / Executor | Comandos físicos como tools com Receipt |
| Quality Gates | Gates específicos de surface, privacy, mode, capability |
| Repair Loop | Estratégias de repair para falhas físicas |
| Output Renderer | Renderer especializado por surface |
| Evidence Ledger | Eventos prolíficos, sample rate por tipo |
| Learning / Memory | Sinais físicos como métricas |
| Self-Improvement / Curator | Proposals físicas, mesmo fluxo de approval |

---

## 19. Princípios da integração

1. **Embodiment é adição, não substituição.** Pipeline existente continua válido.
2. **Sinais físicos são contexto, não decisão.** Decide segue único decisor.
3. **Comandos físicos são tools.** Sujeitos a Receipt, gates, audit.
4. **Output Renderer especializado por surface.** Não há cognição em renderer.
5. **Policies compostas.** Embodiment adiciona restrições; nunca pula.
6. **Eventos no Ledger têm sample rate.** Volume alto exige amostragem proporcional.

---

## 20. Anti-padrões

| Anti-padrão | Por quê |
|---|---|
| Atalho do Atlas Input direto pro Output Renderer (pular Decide) | Bypass cognitivo |
| Surface Adapter "cacheando" respostas e retornando sem alma | Cognição na surface |
| Receipt comandando hardware específico (LED 3 verde) | Receipt é abstrato |
| Domain comandando o corpo direto | Domain sugere via Receipt |
| Curator auto-aplicando proposal física | Self-Improvement não autoaplica |
| Gates físicos negociáveis ("ok ignorar privacy nesse caso") | Gates são duros |
| Múltiplos renderers conflitantes para mesma surface | Coordenação obrigatória |

---

## Próximos passos de leitura

- `05-modos-operacao.md` — modos do corpo e como afetam pipeline.
- `04-protocolos/02-eventos-evidence.md` — catálogo dos eventos.
- `07-integracao-atlas/02-output-renderer.md` — renderer especializado.
- `07-integracao-atlas/04-curator-proposals.md` — Curator → corpo.
