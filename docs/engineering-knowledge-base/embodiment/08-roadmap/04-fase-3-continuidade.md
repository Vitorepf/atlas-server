---
id: atlas-embodiment-08-roadmap-04-fase-3-continuidade
type: engineering_knowledge
title: "Fase 3 — Continuidade"
status: source_material
authority_class: design_reference
implementation_state: design_reference_no_runtime
category: physical-surface
summary: "Source material for the Atlas Embodiment/StackChan physical surface design; not current runtime authority."
canonical_owner: docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md
---
# Fase 3 — Continuidade

> **Propósito:** ativar **continuidade temporal e relacional** — heartbeat scheduler, rituais agendados, eventos relacionais no Evidence Ledger, memória contextual referenciada em respostas, identidade preservada através de troca de hardware. Ao final da Fase 3, o Atlas atinge a **camada de vida L3 (continuidade)** — "esse Atlas me conhece".
>
> **Pré-requisitos:** [README](../README.md), [Fase 0–2](.), [03-camadas-de-vida/03-continuidade.md](../03-camadas-de-vida/03-continuidade.md), [02-arquitetura/02-loops-temporais.md](../02-arquitetura/02-loops-temporais.md), [04-protocolos/02-eventos-evidence.md](../04-protocolos/02-eventos-evidence.md).
>
> **Fora do escopo:** iniciativa governada do Curator (Fase 4 — Curator analisa silenciosamente nesta fase, mas não interrompe), caráter (Fase 5).

---

## 1. Por que esta fase

### O risco que estamos mitigando

Após Fase 1 e 2, o Atlas tem voz e corpo expressivo — mas **cada interação começa do zero**. Sem L3:
- Sem rituais (bom dia, fim de dia).
- Sem referência ao passado em respostas.
- Sem identidade preservada se trocar hardware.
- Sem padrões de uso reconhecidos.

Fase 3 instala a **dimensão temporal**. O robô passa a existir ao longo dos dias, não só no momento.

### Princípio orientador

> **Continuidade demonstrada com curadoria, nunca com dump.**

Lembrar tudo é assustador. Lembrar do certo, na hora certa, é caráter emergente. A fase 3 estabelece a base; calibração emergente vem com Fase 4 e Fase 5.

---

## 2. Definição funcional

Ao final da Fase 3:

| Comportamento | Acontece quando |
|---|---|
| Ritual de bom dia executa às 9h (ou horário configurado) | Heartbeat L4 dispara |
| Ritual de fim de dia executa às 18h | Heartbeat L4 dispara |
| Resumo semanal sexta 17h | Heartbeat L4 |
| Atlas referencia interação passada em resposta quando relevante | Memory consultada |
| Métricas do dia visíveis em info-card (commits, tarefas, foco) | Aggregate de eventos |
| "Ontem você parou em X" — continuidade contextual | Last session tracking |
| Curator detecta padrões silenciosamente | Análise periódica (ainda não interrompe) |
| Sinais de drift detectados (silenciosamente) | Curator analysis |
| Troca de hardware → identidade reaparece | Migration funcionou |
| Eventos relacionais (`relationship.milestone`) registrados | Marcos detectados |
| "Faz X dias que..." surge espontâneo quando contexto pede | Memory + Context Builder |

### O que **NÃO** acontece nesta fase

- Curator **não** interrompe diretamente — apenas analisa, prepara proposals, fila acumula. **Apresentação** das proposals fica para Fase 4.
- Eventos críticos **não** disparam interrupção ainda — Fase 4.
- Touch pads ainda passivos para approval (sem fluxo de approval ainda) — Fase 4.
- IR transmit, NFC ainda inativos — Fase 4.

Fase 3 é **continuidade silenciosa** — robô lembra, mas ainda não toma iniciativa de te puxar pra conversa.

---

## 3. Arquitetura mínima implementada

### 3.1 Na alma (Atlas)

| Componente | Estado em Fase 3 |
|---|---|
| Heartbeat scheduler L4 | ✅ |
| Catálogo de rituais agendados configurável | ✅ |
| Eventos relacionais no Evidence Ledger | ✅ |
| Memory signals consumindo Ledger | ✅ |
| Context Builder amplificado com referências históricas | ✅ |
| Curator running periódico (análise silenciosa) | ✅ |
| Identity layer (voz fixa, vocabulário-assinatura, persona) persistido no Ledger | ✅ |
| Migration tool entre surface_ids (caso troque hardware) | ✅ |
| Aggregation de telemetria (perfil de uso por hora/dia/semana) | ✅ |
| Retenção configurável por categoria de evento | ✅ |
| Modo de "esquecer" — comando explícito | 🟡 opcional Fase 3 |

### 3.2 No corpo (firmware)

A Fase 3 é **mais alma que corpo**. Mudanças no firmware são pequenas:

| Componente | Estado em Fase 3 |
|---|---|
| Renderer de cards rotacionais (métricas, histórico) | ✅ |
| Suporte a info-cards com data/timestamp explícito ("ontem", "há 3 dias") | ✅ |
| RTC sync periódico via NTP | ✅ |
| Persistência mínima de surface_id e config (já existia) | ✅ |
| Sound_id `morning_chime`, `evening_chime` para rituais | ✅ (assets) |

### 3.3 Protocolo

**Modalidades de input adicionadas:**
- `system_event` (já existia) ganha sub-tipos: `boot.first_after_migration`, `rtc.synced`, `rtc.drift_detected`.
- `scheduled` trigger type (já existia) ganha catálogo: `ritual: morning_briefing | end_of_day | weekly_review | drift_check`.

**Modalidades de output adicionadas:**
- `card_render` ganha subtipo `historical` (com timestamp visível).
- `audio_sound_id` adiciona sounds de ritual.

**Eventos no Ledger adicionados:**
- `ritual.scheduled.fired`
- `ritual.executed.<ritual_name>`
- `ritual.suppressed` (modo bloqueou)
- `relationship.milestone` ⭐
- `relationship.streak.<type>` ⭐
- `relationship.gap_noticed` ⭐ (silencioso nesta fase — Curator detecta mas não interrompe)
- `surface.migrated.from_<old_id>.to_<new_id>` ⭐
- `identity.config.changed` ⭐
- `curator.proposal.queued` ⭐ (proposals nascem aqui mas ficam fila — apresentação na Fase 4)

---

## 4. Critérios de aceitação

### 4.1 Rituais

- [ ] Ritual de bom dia executa nos horários configurados, com tolerância ±1min.
- [ ] Ritual respeita modo: em DND, é suprimido (e isso vira `ritual.suppressed`).
- [ ] Ritual presence-aware: se ninguém presente às 9h, adia até primeira presença ou descarta (config).
- [ ] Mudar horário de ritual via comando + approval funciona.
- [ ] Conteúdo do ritual usa Memory (não é texto fixo).

### 4.2 Continuidade contextual

- [ ] Pergunta "como tá X?" referencia última interação em X quando relevante.
- [ ] Resposta menciona "ontem" / "há 3 dias" naturalmente, não forçado.
- [ ] Atlas **não** dump histórico — só puxa o que é diretamente relevante.
- [ ] Sem inventar: se não consta no Ledger, não afirma.

### 4.3 Memory signals

- [ ] Padrão de modo por hora do dia agregado.
- [ ] Frequência de uso por Domain agregada.
- [ ] Tempo médio em cada modo na semana.
- [ ] Aceitação / rejeição (futura) tracking estruturado.

### 4.4 Curator silencioso

- [ ] Análise periódica roda (default: a cada 30min).
- [ ] Padrões detectados gerados como `curator.proposal.queued`.
- [ ] Drift signals detectados sem interromper.
- [ ] Fila de proposals é consultável (mas não apresentada — Fase 4).

### 4.5 Identidade preservada

- [ ] Voz fixa registrada no Ledger (configuração persistida).
- [ ] Vocabulário-assinatura documentado e referenciado.
- [ ] Migration: trocar hardware preserva identidade. Primeira interação com novo corpo: identidade reconhecível.
- [ ] Mudança de identidade (voz, vocabulário) é **comando explícito** com approval — não silenciosa.

### 4.6 Retenção e privacy

- [ ] Conteúdo bruto (transcrições, imagens) descartado conforme policy.
- [ ] Eventos estruturais retidos por período configurado.
- [ ] Comando "esquecer dia X" (se implementado) funciona end-to-end.

### 4.7 Estabilidade

- [ ] 30+ dias contínuos: rituais disparam consistentemente.
- [ ] Reset de Atlas backend → ao reiniciar, schedules e Memory carregam corretamente.
- [ ] RTC sync funciona; drift detectado e corrigido.

### 4.8 Anti-critérios

- [ ] Frases scriptadas ("Como sempre dizemos…").
- [ ] Dump de eventos passados sem ter sido pedido.
- [ ] Ritual disparando em DND ou private.
- [ ] Identidade mudando silenciosamente (provider trocou imperceptivelmente).
- [ ] Conteúdo bruto persistido sem necessidade.

---

## 5. Trabalho concreto envolvido

### 5.1 Atlas backend (alma)

1. **Heartbeat scheduler L4** — agendamento, cron-style, com awareness de modo do corpo.
2. **Ritual catalog** — biblioteca de rituais (good_morning, end_of_day, weekly_review, drift_check) com configuração.
3. **Ritual generator** — para cada ritual, função que produz Operation Envelope com `trigger.scheduled`.
4. **Memory aggregator** — sample do Ledger gerando agregados consultáveis pelo Decide.
5. **Context Builder amplificado** — incluir consultas históricas relevantes na decisão.
6. **Curator periodic analysis** — workflow rodando a cada N min, gerando proposals (em fila).
7. **Identity persistence layer** — voz, vocabulário, persona em Ledger.
8. **Migration tool** — comando administrativo (com approval) para migrar `surface_id`.
9. **Retention policies** — implementação por categoria, agendado.
10. **Modo "forget"** (opcional) — comando, janela de cooldown, execução cuidadosa.

### 5.2 Firmware (corpo)

1. **Card renderer com timestamp** — info-card que mostra data/hora referencial.
2. **NTP sync periódico** — manter RTC alinhado.
3. **Sound assets de ritual** — chime breve para entrada de ritual.
4. **Suporte a "primeira interação após migration"** — face especial breve, indicação visual sutil.

### 5.3 Documentação adicional necessária

- ⬜ `07-integracao-atlas/03-personalidade-ledger.md` — eventos relacionais detalhados.
- ⬜ `10-anexos/D-vocabulario-atlas.md` — frases-assinatura.
- ⬜ ADR sobre estratégia de migration de hardware.
- ⬜ ADR sobre frequência e profundidade da Curator analysis.

---

## 6. O que esta fase prova / valida

| Hipótese | Como é validada |
|---|---|
| Heartbeat L4 é confiável | 30 dias de rituais consistentes |
| Memory signals enriquecem decisões | Respostas tem contexto histórico verificável |
| Identidade sobrevive a troca de hardware | Migration testada |
| Curator detecta padrões úteis | Fila de proposals analisada qualitativamente |
| L3 (continuidade) é alcançável | Sensação acumulada após 30 dias |
| Privacy + continuidade convivem | Conteúdo bruto descartado, agregados úteis |

---

## 7. Riscos da fase

### 7.1 Continuidade falsa
Atlas "lembra" coisas erradas. Solução: referência sempre tem fonte rastreável (Evidence ID); se não consta, não afirma.

### 7.2 Oversharing
Demonstrar memória vira dump. Solução: curadoria — só puxa quando contexto pede; threshold de relevância.

### 7.3 Curator gerando proposals demais (em fila)
Volume cresce; quando Fase 4 ativar apresentação, vai inundar. Solução: Curator com taxa moderada, deduplicação, expiração de proposals stale.

### 7.4 Drift de identidade silencioso
Provider TTS muda parâmetro; voz subtilmente diferente. Solução: monitor periódico, alerta se mudou.

### 7.5 Ritual em modo errado
Ritual disparando em DND. Solução: gate `mode_compatible` obrigatório antes de qualquer ritual.

### 7.6 Storage explodindo
Ledger crescendo sem retenção efetiva. Solução: retention agendado, agregação de eventos antigos em resumos.

### 7.7 Migration mal-sucedida
Trocar hardware perde algo. Solução: migration via comando com checklist, validação pós-migration.

---

## 8. Saída da fase / entrada da Fase 4

### Critério de saída
Todos os critérios de aceitação satisfeitos **em uso real de no mínimo 30 dias**. Continuidade só se valida com tempo.

### Antes de iniciar Fase 4:
- ✅ Critérios da Fase 3 satisfeitos.
- ✅ Identidade do Atlas documentada (voz, vocabulário-assinatura) em ADR.
- ✅ Catálogo de rituais aprovado.
- ✅ Curator analisando consistentemente, fila de proposals razoável.
- ✅ Decisões pendentes da Fase 4 resolvidas (interruption_policy, prioridades, auto-tuning).

### O que Fase 4 herda
- Curator com fila de proposals pronta para apresentação.
- Memory signals para informar decisões de interrupção.
- Padrões de uso por hora/modo para calibrar quando interromper.
- Continuidade temporal — base para "faz X dias que…" do Curator.

---

## 9. Anti-padrões específicos desta fase

| Anti-padrão | Como evitar |
|---|---|
| "Vamos fazer Curator interromper logo" | Não. Apresentação é Fase 4 com governance. |
| "Memória relacional vai ser feita por prompt" | Memory deriva do Ledger, não de prompt. |
| "Identidade fica num arquivo de config" | Identidade vive no Ledger; arquivo é só projeção inicial. |
| "Vamos guardar todas transcrições por garantia" | Privacy. Default: descartar conteúdo bruto. |
| "Atlas pode improvisar 'lembrança' com prompt criativo" | Sem fonte = sem afirmação. |
| Schedule hardcoded no firmware | Configurável via Ledger; dinâmico. |
| Migration sem ADR | Decisão crítica; documentar. |
| "Esse evento parece relacional, vou marcar como milestone" | Catálogo fechado de milestones; ad-hoc não. |

---

## 10. Resumo

**Objetivo:** continuidade temporal e relacional silenciosa.
**Camada de vida atingida:** L3 (continuidade).
**Duração estimada:** 4-6 semanas (30 dias mínimos de uso para validar).
**Crítico de validar:** continuidade demonstrada com curadoria.
**Saída:** Fase 4 (iniciativa) tem padrões e proposals para começar a apresentar com governance.
**Tom da fase:** paciência. Continuidade não acontece em 1 sprint; valida-se com tempo.

---

## Próximos passos de leitura

- `05-fase-4-iniciativa.md` — próxima fase.
- `07-integracao-atlas/03-personalidade-ledger.md` — eventos relacionais (a escrever).
- `03-camadas-de-vida/03-continuidade.md` — fundamentos teóricos.
- `04-protocolos/02-eventos-evidence.md` — eventos detalhados.
