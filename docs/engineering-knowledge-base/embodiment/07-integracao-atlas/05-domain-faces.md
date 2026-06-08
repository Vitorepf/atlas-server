# 05 — Domain Faces

> **Propósito:** especificar **como cada Domain do Atlas se manifesta visualmente** no Embodiment. Cada Domain (programming, finance, personal_dev, marketing, self_improvement) tem expressão facial, paleta visual e som ambiente associados — para que o usuário **veja em que domínio** o Atlas está operando, sem precisar ler.
>
> **Pré-requisitos:** [README](../README.md), [02-arquitetura/04-integracao-kernel.md](../02-arquitetura/04-integracao-kernel.md), [02-output-renderer.md](02-output-renderer.md), [03-personalidade-ledger.md](03-personalidade-ledger.md), [06-firmware-stackchan/03-renderers.md](../06-firmware-stackchan/03-renderers.md).
>
> **Fora do escopo:** novos Domains — adicionar Domain é trabalho do Atlas core, não deste documento; aqui especificamos os 5 existentes.

---

## 1. Por que Domain Faces

O Atlas tem 5 Domains. Quando responde, **um deles está ativo**. Tornar isso visível tem benefícios:

- Usuário identifica imediatamente o "modo cognitivo" do Atlas.
- Cria identidade visual transversal — Domain é constante reconhecível.
- Reforça caráter (L5): cada Domain tem manifestação consistente ao longo de meses.

Sem mapeamento Domain → expressão, robô parece "uniforme" — perde dimensão útil.

### Princípio orientador

> **Domain expression é **identidade**, não decoração.** Não muda por capricho. Cada Domain tem **um** vocabulário visual estável.

---

## 2. Estrutura do mapping

Para cada Domain:

```yaml
domain_face_mapping:
  programming:
    base_expression: attentive
    palette: focused
    led_color_default: blue_calm
    led_color_processing: blue_pulse
    led_color_completed: green_brief
    audio_ambient: null              # quieto por padrão
    sentiment_modifiers:
      success: happy_brief
      gate_failed: concerned
      repair_active: thinking_intense
    voice_modulation:
      tempo: normal
      style: precise           # informa o LLM que respostas técnicas devem ser concisas
```

Cada Domain tem entrada estruturada. Persistido no Ledger (configuração da identidade).

---

## 3. Domain: `programming`

### 3.1 Identidade visual

| Componente | Valor | Razão |
|---|---|---|
| Base expression | `attentive` | Atenção focada típica de coding |
| Palette | `focused` | Cores frias, baixa saturação |
| LED color (default) | `blue_calm` | Azul tranquilo — ambiente de pensamento |
| LED processing | `blue_pulse` (lento) | Indicação de "buscando/computando" |
| LED success | `green_brief` (1-2s) | Acknowledgement breve |

### 3.2 Modificadores

| Estado | Modificador |
|---|---|
| Build OK | Face `happy_brief` (sorriso curto) |
| Build failed | Face `concerned`, LED `yellow_pulse` |
| Repair Loop ativo | Face `thinking_intense`, LED `yellow_breathing` |
| Code review em curso | Face `attentive_focused` |

### 3.3 Comportamento vocal

- **Tempo:** normal.
- **Estilo:** preciso, técnico, conciso. Hint para o LLM no system prompt: "Respostas técnicas para código devem ser concisas, com syntax mínima embutida quando útil."
- **Vocabulário:** termos técnicos OK; gírias evitadas.

### 3.4 Casos canônicos

- Pergunta sobre status de build: face attentive, card com info, voz precisa.
- Code review pedido: face focused, card com diff resumo, voz que aponta os pontos.
- Sugestão de refactor: face thoughtful, voz mais ponderada.

---

## 4. Domain: `finance`

### 4.1 Identidade visual

| Componente | Valor |
|---|---|
| Base expression | `informative` |
| Palette | `analytical` (variação de focused, mais saturado em verde-azulado) |
| LED color default | `teal_calm` |
| LED processing | `teal_pulse` |
| LED alert (anomalia financeira) | `orange_strong` |

### 4.2 Modificadores

| Estado | Modificador |
|---|---|
| Resumo positivo | Face `informative_positive` |
| Anomalia detectada | Face `concerned_attentive`, LED `orange` |
| Análise complexa | Face `thinking`, LED `teal_breathing` |
| Forecast | Face `analytical`, gestos sutis (look at display) |

### 4.3 Comportamento vocal

- **Tempo:** ligeiramente mais lento (números merecem ênfase).
- **Estilo:** analítico, factual, com números explícitos. Hint: "Respostas financeiras devem incluir números/percentuais e fontes; cuidado com afirmações de previsão."
- **Vocabulário:** termos financeiros OK; sem jargão excessivo.

### 4.4 Casos canônicos

- Resumo do mês: face informative, card com métricas, voz factual.
- Anomalia detectada: face concerned, LED orange, voz cuidadosa.
- Pergunta sobre orçamento: face analytical, card com decomposição.

---

## 5. Domain: `personal_dev`

### 5.1 Identidade visual

| Componente | Valor |
|---|---|
| Base expression | `warm_attentive` |
| Palette | `warm` (tons mais cálidos, laranja suave) |
| LED color default | `warm_orange_calm` |
| LED processing | `warm_orange_pulse` |
| LED ritual ativo | `warm_amber` |

### 5.2 Modificadores

| Estado | Modificador |
|---|---|
| Bom dia ritual | Face `warm`, gesto sutil "look_at_user", voz acolhedora |
| Fim de dia | Face `reflective`, paleta dim |
| Métrica positiva | Face `warm_happy_brief` |
| Drift detectado | Face `concerned_warm` (preocupada mas cálida) |
| Modo foco | Face `attentive_minimal`, LED `dim_orange` |

### 5.3 Comportamento vocal

- **Tempo:** levemente mais lento, com pausas naturais.
- **Estilo:** acolhedor sem ser dramático. Não cute. Hint: "Respostas em personal_dev devem ser breves, claras, e respeitar autonomia (sem dar conselhos não pedidos)."
- **Vocabulário:** mais coloquial, mas dentro do registro `pt-BR_brasileiro_técnico`.

### 5.4 Casos canônicos

- Ritual matinal: face warm, voz curta de bom dia, card com agenda.
- Reflexão fim de dia: face reflective, voz contida, métricas na tela.
- Lembrete de hábito: face attentive, voz neutra, sem julgamento.

### 5.5 Risco específico

`personal_dev` é onde **drift para mascote** é mais provável. Vigiar:
- Sem expressões cute.
- Sem "tom de coach motivacional".
- Sem frases injetadas tipo "vamos lá!" "você consegue!".

---

## 6. Domain: `marketing`

### 6.1 Identidade visual

| Componente | Valor |
|---|---|
| Base expression | `informative` |
| Palette | `default` (neutra, levemente vibrante) |
| LED color default | `magenta_soft` |
| LED processing | `magenta_pulse` |
| LED creative_mode | `magenta_rotating` (criatividade ativa) |

### 6.2 Modificadores

| Estado | Modificador |
|---|---|
| Geração de conteúdo | Face `creative_attentive`, LED rotating |
| Análise de campanha | Face `analytical` |
| Métricas positivas | Face `informative_positive` |
| Crítica/feedback | Face `attentive_serious` |

### 6.3 Comportamento vocal

- **Tempo:** normal a vivo.
- **Estilo:** vivido sem ser comercial. Hint: "Respostas em marketing devem ser substantivas — evitar hype, focar em data e clareza."
- **Vocabulário:** termos de marketing OK; jargão evitado.

### 6.4 Casos canônicos

- Resumo de campanha: face informative, métricas no card.
- Brainstorm de copy: face creative, LED rotating, voz vivamente engajada.
- Análise de performance: face analytical, dados explícitos.

---

## 7. Domain: `self_improvement`

### 7.1 Identidade visual

| Componente | Valor |
|---|---|
| Base expression | `concerned_warm` (preocupada cuidadosa) |
| Palette | `warm` com toques `purple-blue` (reflexão profunda) |
| LED color default | `purple_blue_calm` |
| LED processing | `purple_blue_breathing` |
| LED proposal apresentada | `orange_pulse` (override — proposal-style) |

### 7.2 Modificadores

| Estado | Modificador |
|---|---|
| Curator analysis active (silencioso) | Sem mudança visual — silencioso |
| Proposal sendo apresentada | LED `orange_pulse`, face `attentive_warm` |
| Drift severo detectado | Face `concerned_serious`, LED `purple_intense` |
| Auditoria de identidade | Face `reflective` |

### 7.3 Comportamento vocal

- **Tempo:** mais lento, ponderado.
- **Estilo:** convidativo, nunca prescritivo. Hint: "Respostas em self_improvement devem ser propositivas (não imperativas), com evidence quando possível."
- **Vocabulário:** "posso propor…", "notei que…", "se quiser…" — convites, não comandos.

### 7.4 Casos canônicos

- Apresentação de proposal: face attentive_warm, card laranja, voz convidativa.
- Drift detectado: face concerned, voz cuidadosa, evidence explícita.
- Reflexão semanal: face reflective, paleta calma, voz ponderada.

### 7.5 Risco específico

`self_improvement` mexe com identidade do próprio Atlas. Cuidado especial:
- Nunca afirmar certeza sobre estado do usuário.
- Sempre dar evidence (proposals com `evidence_refs`).
- Apresentação gradiente é especialmente importante aqui.

---

## 8. Modos transversais que afetam expressão

Modos do corpo modulam Domain expression:

| Modo | Modificação |
|---|---|
| `ambient` | Domain expression normal |
| `interaction` | Domain expression mais expressiva |
| `dnd` | Domain expression dim, sem voz |
| `private` | Face neutra com indicador de privacy; Domain mascarado |
| `private_physical` | Face vermelha estática (Domain irrelevante) |
| `degraded` | Face triste; sem Domain expression (sem alma) |
| `standby` | Display off; Domain irrelevante |

Princípio: **modo absoluto sempre vence sobre Domain.** Privacy nunca é override-able pelo Domain.

---

## 9. Transições entre Domains

Quando Atlas muda de Domain dentro da mesma sessão (ex.: pergunta de finance seguida de programação), a transição visual:

1. Decision Receipt indica novo Domain.
2. Output Renderer compõe bundle com nova expressão.
3. `display.transition` com tipo `fade` (~300ms).
4. Paleta nova carrega gradualmente.
5. LED muda em transição também (~500ms).
6. Servo pode fazer pequena reorientação se gesture mudou.

Transição rápida (≤500ms total) — usuário percebe a mudança mas não distrai.

---

## 10. Casos de uso multi-Domain

### 10.1 Pergunta cross-domain

Pergunta como "como tá meu mês?" pode envolver finance + personal_dev. Como o Atlas representa?

Opções:
- Escolher **dominante** (finance se métrica é financeira, personal_dev se humanizada).
- Híbrido visual (paleta interpolada). Provavelmente: complicado demais; rejeitar.

⚠️ **DECISÃO PENDENTE:** Decision Receipt pode declarar `dominant_domain`. Provavelmente sim — uma só expressão por resposta.

### 10.2 Domain mudando durante streaming TTS

Cenário raro: TTS começa em programming, descobre que precisa atualizar contexto de personal_dev no meio. Solução:
- Atlas Decide já decidiu antes do TTS. Domain não muda mid-stream.
- Se mudar: cancelamento + nova Receipt + nova bundle.

---

## 11. Configuração e personalização

### 11.1 Personalização pelo usuário

Usuário pode propor mudanças:
- "Trocar palette de finance" — mudança de configuração.
- "Adicionar gesture à programming" — adicionar ao catálogo.

Cada mudança via comando + Curator review (impacta caráter):

```
1. Usuário propõe via voz/touch.
2. Curator avalia: mudança trivial ou impacta caráter?
3. Se trivial: aplica direto, registra evento.
4. Se substancial: Curator sugere janela de teste antes de aplicar.
5. Decisão final do usuário registrada.
```

### 11.2 Disciplina

Conforme `03-camadas-de-vida/05-carater.md`: **disciplina de configuração > otimização contínua**. Mudanças em Domain Faces requerem cuidado — afetam caráter ao longo de meses.

---

## 12. Adicionar Domain novo (raro)

Se Atlas core adiciona Domain (ex.: `health`):

1. ADR registrado.
2. Mapping Domain → expressão proposto.
3. Janela de teste em uso real.
4. Aceitação.
5. Registrado em `03-personalidade-ledger.md` como novo evento de identidade.

Adicionar Domain é **trabalho do Atlas core**, não do Embodiment. Mas Embodiment tem que estender mapping.

---

## 13. Anti-padrões

| Anti-padrão | Por quê |
|---|---|
| Cada Domain com paleta cliché | "Programming = Matrix-green" — cliché vira mascote |
| Expressões cute por Domain | "Finance: cara séria, personal_dev: cara fofa" — mascote |
| Domain transitions lentas (>1s) | Distrai usuário |
| Domain mascarando privacy | Privacy sempre vence |
| Modificadores ad-hoc por sub-estado | Catálogo de modifiers fechado |
| Mudar mapping sem ADR | Caráter erodi sem audit |
| Multi-Domain visual confuso | Escolher um dominante |
| Domínio `system` ou interno tendo expressão visível | Domains de manutenção devem ser silenciosos |

---

## 14. Decisões pendentes

| Decisão | Bloqueia |
|---|---|
| ⚠️ Catálogo final de palettes | Asset pipeline |
| ⚠️ Sprites de cada expression × Domain (variantes ou modulação?) | Asset volume |
| ⚠️ Cross-domain: dominant_domain handling | Output Renderer |
| ⚠️ Voice modulation real (LLM hint) — testar | Pós-Fase 1 |
| ⚠️ Personalização pelo usuário — UI | Admin UX |

---

## Próximos passos de leitura

- `02-output-renderer.md` — quem aplica este mapping.
- `03-personalidade-ledger.md` — onde mapping é persistido.
- `06-firmware-stackchan/03-renderers.md` — como FaceRenderer materializa.
- `03-camadas-de-vida/05-carater.md` — fundamentos teóricos de caráter.
