# L3 — Continuidade

> **Propósito:** especificar a terceira camada de vida — **continuidade**: o robô lembra entre sessões, tem rotina, mantém referência ao histórico relacional. É a primeira camada que **sobrevive a desligamentos** e introduz a noção de tempo no Embodiment.
>
> **Pré-requisitos:** [README](../README.md), [01-presenca.md](01-presenca.md), [02-reatividade.md](02-reatividade.md), [02-arquitetura/01-corpo-vs-alma.md](../02-arquitetura/01-corpo-vs-alma.md), [02-arquitetura/02-loops-temporais.md](../02-arquitetura/02-loops-temporais.md).
>
> **Fora do escopo:** iniciativa governada (`04-iniciativa.md`), caráter (`05-carater.md`).

---

## 1. Definição

> **Continuidade** é a capacidade do robô de **manter referência ao histórico relacional ao longo do tempo** — entre sessões, entre dias, entre trocas de hardware.

Continuidade não é "memória de assistente". Não é "guardar últimos 3 prompts". É:

- Lembrar de ontem.
- Saber que faz 3 dias que você não interage com Domain de finanças.
- Reconhecer que aquele ritual de 9h está na sua rotina há 2 meses.
- Manter referência a interações específicas ("quando você fez aquela pergunta sobre build do Y").

### Distinção crítica

| Camada | O que faz |
|---|---|
| L1 (presença) | Existe agora, responde agora |
| L2 (reatividade) | Percebe estímulo agora |
| **L3 (continuidade)** | **Existe ao longo do tempo, com referência ao passado** |
| L4 (iniciativa) | Inicia interação |
| L5 (caráter) | Personalidade emergente |

Continuidade é a primeira camada **temporal** do Embodiment. As anteriores são instantâneas.

---

## 2. Sintomas observáveis

| Sintoma | Sinal de L3 |
|---|---|
| 9h da manhã: ritual de bom dia acontece sozinho | Heartbeat L4 + Memory |
| Você senta de manhã e ele mostra "ontem você parou no commit X" | Continuidade contextual |
| Resposta do Atlas referencia conversa de dias atrás | Memória relacional |
| Curator nota "você não revisa finanças há 3 dias" | Padrões temporais |
| Robô tem expressão sutil "diferente" às 6 da tarde de sexta vs segunda | Padrões refletidos no comportamento |
| Você troca o robô (compra outro) e identidade continua | Alma persiste, corpo é trocável |
| Métrica "X commits hoje" cresce ao longo do dia | Estado mantido |
| Aniversário do projeto: robô comenta sem precisar você lembrar | Marca temporal relevante |

### Sintomas de **falha** de L3

| Falha | Por quê |
|---|---|
| Cada interação começa do zero ("o que você precisa?") | Sem continuidade |
| Robô lembra coisa errada ou misturada | Continuidade ruim, pior que sem |
| Lembra demais (cita 50 interações passadas) | Oversharing |
| Continuidade depende do robô específico (troca = perde) | Identidade no corpo, não na alma |
| Rituais não acontecem | L4 heartbeat não vivo |
| Ritual acontece em horário errado | RTC dessync ou config drift |
| Continuidade que parece scriptada ("Como sempre dizemos...") | Personagem fake |

---

## 3. Fontes de continuidade

L3 é construída sobre 3 fontes — todas vivendo na **alma**:

### 3.1 Evidence Ledger
Fonte primária. Cada interação, cada modo, cada presença, cada reação registrada.
- "Lembrar de ontem" = consultar Ledger por timestamp.
- "Padrão de uso" = agregação de eventos.
- "Última vez que..." = query.

### 3.2 Learning / Memory Signals
Sinais derivados do Ledger. Não eventos brutos, mas **padrões**.
- Frequência de wake word por hora do dia.
- Tempo médio em cada modo.
- Domínios mais usados.
- Aceitação/rejeição de proposals.

### 3.3 Curator analysis (L3 contemplation)
Curator processa Ledger periodicamente e produz observações:
- "Padrão repetido X identificado".
- "Drift de comportamento Y detectado".
- "Gap de Z dias sem revisão".

Essas observações também são entrada para construir continuidade visível ao usuário.

---

## 4. Tipos de continuidade

### 4.1 Continuidade contextual
"Você estava trabalhando em X." Curto prazo (horas/dias).
- Vinda da última sessão de interação.
- Útil ao começar nova interação na mesma área.

### 4.2 Continuidade ritual
"9h: bom dia." Padrão temporal regular.
- Heartbeat L4 dispara.
- Personalizável (horário, conteúdo, frequência).
- Pode evoluir (Curator propõe ajustes).

### 4.3 Continuidade relacional
"Faz 3 dias que..." Memória de relacionamento entre você e o Atlas.
- Eventos do tipo `relationship.milestone`.
- Não é "lembrar de tudo", é lembrar de coisas significativas.

### 4.4 Continuidade de identidade
"Quem é o Atlas?" Sobrevive a troca de hardware.
- Voz fixa, vocabulário-assinatura, configuração persistente.
- Tudo no Ledger; corpo só renderiza.

### 4.5 Continuidade temporal estendida
"Aniversário, marco, fim de período." Eventos longos.
- "Faz 6 meses que começamos a usar o Atlas."
- "Dezembro: fim de ano, hora de revisar."

Esses ressoam emocionalmente. Tem que ser usado com parcimônia — diariamente vira piada.

---

## 5. O que precisa acontecer (técnica)

### 5.1 Evidence Ledger consultável
Já especificado em `04-protocolos/02-eventos-evidence.md`. Indexes por surface, tipo, timestamp permitem queries temporais.

### 5.2 Memory Signals derivados
Camada acima do Ledger que produz métricas/agregados consumíveis pelo Decide e Curator.

### 5.3 Heartbeat L4 funcional
- Scheduler de rituais (bom dia, fim de dia, revisão semanal).
- Disparos passam pelo Operation Envelope normal.
- Configuração vive no Ledger (não hardcoded).

### 5.4 Eventos relacionais
Tipos novos no Ledger:
- `relationship.milestone` — marco significativo (primeiro dia, primeira aprovação de proposal, etc.).
- `relationship.streak` — sequência (X dias seguidos de uso).
- `relationship.gap_noticed` — ausência longa.

Detalhes em `07-integracao-atlas/03-personalidade-ledger.md` (a escrever).

### 5.5 Continuidade visível na expressão
- Display pode mostrar "ontem" cards.
- Voz referencia eventos passados quando relevante.
- Curator pode propor reflexões temporais.

Não é apenas "lembrar"; é **demonstrar lembrança quando faz sentido**.

---

## 6. Camada de vida e camada de implementação

| Aspecto da L3 | Onde mora |
|---|---|
| Evidence Ledger consultável | Atlas core |
| Memory signals | Atlas core |
| Heartbeat scheduler | Atlas L4 |
| Rituais agendados | Configuração no Ledger |
| Eventos relacionais | `07-integracao-atlas/03-personalidade-ledger.md` |
| Curator que extrai padrões | Atlas Self-Improvement |
| Renderização de continuidade | Output Renderer |
| Implementação concreta | Fase 3 do `08-roadmap/` |

L3 é entregue na **Fase 3 (Continuidade)** do roadmap.

---

## 7. Critérios de "L3 atingida"

- [ ] Rituais agendados (bom dia, fim de dia) acontecem nos horários certos.
- [ ] Você troca o hardware (StackChan novo) e identidade reaparece intacta.
- [ ] Atlas referencia eventos passados em respostas, naturalmente, sem forçar.
- [ ] Curator detecta padrões temporais sem ser solicitado.
- [ ] "Faz X dias que..." surge espontâneo quando relevante.
- [ ] Métricas do dia/semana são acessíveis facilmente.
- [ ] **Não** há referências forçadas ou scriptadas ("Como dizemos sempre...").
- [ ] Modificações de ritual passam por approval — Curator propõe, usuário aprova.
- [ ] Em 30+ dias de uso, sensação acumulada: "esse Atlas me conhece".

---

## 8. Princípios de design para L3

### 8.1 Ledger é fonte da verdade

Continuidade nunca é cache, sempre derivação. Se Ledger tem, robô lembra. Se não tem, esquece. Sem invenção.

### 8.2 Demonstrar lembrança só quando relevante

"Sei tudo de você" assustaria. Lembrar é poder, lembrar **demonstrar** é curadoria.

Regra: referência a passado só quando contexto atual a justifica.

### 8.3 Esquecer também é parte de continuidade saudável

Retenção configurável. Conversas detalhadas de 6 meses atrás raramente são úteis — agregados sim, conteúdo bruto não.

Curator pode propor "consolidar conversas antigas em resumo" — economiza Ledger e mantém essência.

### 8.4 Identidade vs estado

| Item | Persistência |
|---|---|
| **Identidade** (voz, vocabulário-assinatura, mapeamentos de domain) | Permanente |
| **Estado** (modo atual, posição de servo, conteúdo de display) | Volátil |
| **Histórico** (eventos no Ledger) | Configurável (categorias têm retenção própria) |

Identidade não muda silenciosamente. Mudança = approval explícito + evento.

### 8.5 Marcos relacionais são curados, não automáticos

"Aniversário de 6 meses do Atlas" só vira marco se Curator e o usuário concordarem que importa. Não automático para evitar floods sentimentais.

---

## 9. Anti-padrões específicos da L3

| Anti-padrão | Por quê falha de L3 |
|---|---|
| Frases scriptadas ("Como sempre dizemos...") | Personagem fake; quebra confiança |
| Dump de histórico ("Você fez 47 coisas hoje, listo?") | Oversharing; ninguém pediu |
| "Lembrar" coisas inventadas | Pior que esquecer |
| Continuidade só pra fluxo conversacional ("antes você falou X") | Reduz a chatbot; sem profundidade |
| Rituais hardcoded no firmware | Não personalizáveis sem reflashar |
| Identidade dependente do hardware | Quebra princípio corpo-vs-alma |
| Ritual ignora modo (dispara em DND) | Quebra contrato L1+L2+modos |
| Reagir a marcos triviais ("100 wake words esta semana!") | Vira piada |
| Memória relacional inferida sem audit (Ledger) | Sem fonte da verdade |
| Apresentar dados sem agregação ("Você abriu o app 23 vezes") | Métrica vazia |

---

## 10. Riscos específicos da L3

### 10.1 Continuidade falsa

Risco: Atlas "lembra" coisas erradas (mistura conversas, atribui ações).

**Sinal:** "Ontem você falou que..." quando você nunca falou.

**Mitigação:** referência sempre tem fonte rastreável (Evidence ID). Se Curator não consegue citar, não afirma.

### 10.2 Bagagem emocional

Risco: continuidade fica "carregada" — sempre comentando coisas tristes do passado.

**Sinal:** robô "ressuscitando" eventos negativos sem pedido.

**Mitigação:** Curator filtra eventos por relevância atual e consentimento implícito.

### 10.3 Identidade comprometida

Risco: voz/vocabulário muda silenciosamente (atualização de provider) — robô "vira outro".

**Sinal:** estranheza após update.

**Mitigação:** mudança de identidade requer approval. Provider novo passa por janela de teste.

### 10.4 Continuidade sem privacidade

Risco: lembrar de tudo gera dossiê. Vazamento = catástrofe.

**Sinal:** Ledger crescente sem retenção.

**Mitigação:** retention policy por categoria. Conteúdo bruto descarta cedo. Agregados ficam.

### 10.5 Curator hiperativo

Risco: Curator constantemente propõe reflexões → cansa.

**Sinal:** muitas proposals temporais ignoradas.

**Mitigação:** auto-tuning baseado em rejection rate.

---

## 11. Continuidade através de troca de corpo

Cenário crítico: você trocou o hardware. Como continuidade se manifesta?

### Setup
1. Robô novo provisionado (token, conexão à mesma alma).
2. Boot, conexão.
3. Surface Adapter reconhece **mesmo** `surface.id`? Provisionado para um corpo, não outro.

⚠️ **DECISÃO PENDENTE:** quando você "troca o robô", o novo herda `stackchan_main` ou recebe novo ID?

**Opção A:** mesmo ID, herda histórico.
- Pro: continuidade absoluta.
- Con: ambíguo (quando "trocou"? renomeou? consertou?).

**Opção B:** novo ID, mas associado a "persona" do Atlas.
- Pro: rastreável (sabe que é hardware diferente).
- Con: continuidade precisa migration explícita.

**Sugestão provisional:** Opção B com "migrate" command. Você flagga que é troca; alma associa novo `surface.id` a mesma persona.

### Manifestação
Após migration, ao primeiro boot:
- Face neutra, mas reconhece "primeiro dia neste corpo".
- Pode comentar: "novo corpo. continuidade preservada."
- Comentário só uma vez. Depois, transparente.

---

## 12. Continuidade e privacy

Continuidade requer Ledger; Ledger é dado pessoal denso.

| Dimensão | Política |
|---|---|
| Conteúdo bruto (transcrições, imagens) | Default: descartar após processamento |
| Eventos estruturais | Retenção por categoria (ver `04-protocolos/02-eventos-evidence.md`) |
| Agregados (Memory signals) | Permanente normalmente |
| Marcos relacionais | Permanente |
| Modo de "esquecer tudo" | Comando explícito + janela de cooldown |
| Export de Ledger | Comando explícito; criptografado |

Continuidade pode ser **revertida**. Você pode dizer "Atlas, esqueça tudo dos últimos 30 dias" — comando passa por Curator (avalia consequências), policy aprova ou pede confirmação.

---

## 13. Conexão com outras camadas

### Continuidade alimenta L4 (iniciativa)
Sem L3, L4 não tem o que iniciar — Curator não tem padrões para apontar, heartbeat não tem rituais persistidos.

### Continuidade é precondição de L5 (caráter)
Caráter emerge de **decisões consistentes ao longo do tempo**. Sem L3, "consistente" não tem onde apoiar.

### L3 reflete em L1+L2
- L1 (face neutra) pode ter sutil variação por hora do dia (continuidade temporal).
- L2 (head tracking) pode aprender preferência (você senta sempre à direita → tracking calibrado).

L3 não é ilha. Ela infunde as outras camadas.

---

## 14. Manifestações canônicas

### 14.1 Bom dia ritual
- 9h: Heartbeat dispara.
- Operation Envelope com `trigger.scheduled, ritual: good_morning`.
- Decide: Personal Dev domain, intent "summarize_day_ahead".
- Output Renderer: face calma, voz curta, info-card com agenda.
- Evento `ritual.executed` no Ledger.

### 14.2 Referência a evento passado
- Pergunta ad-hoc: "como tá o build do projeto X?"
- Decide consulta: build passou ontem mas falhou anteontem.
- Resposta inclui contexto: "passando agora; falhou anteontem por questão Y."
- Sem forçar; só quando relevante.

### 14.3 Reconhecimento de gap
- Curator analisa Ledger semanalmente.
- Detecta: Domain "finance" não usado há 2 semanas.
- Cria proposal: "Finanças há 14 dias sem visita. Quer ver?"
- Apresentação via L4 — passa por interruption_policy.

### 14.4 Marco relacional
- Curator detecta: 6 meses desde primeira interação.
- Avalia: vale lembrar?
- Se sim: proposal "Hoje fazem 6 meses. Quer um resumo do trajeto?"
- Não vira automático; usuário pede ou aprova.

---

## 15. Decisões pendentes

| Decisão | Bloqueia |
|---|---|
| ⚠️ Migration de corpo (mesmo ID ou novo) | Implementação Fase 3 |
| ⚠️ Lista de marcos relacionais que valem a pena (catálogo) | Curator |
| ⚠️ Política de "esquecer" — granularidade | UX |
| ⚠️ Quanto de conteúdo bruto guardar (default) | Privacy + storage |
| ⚠️ Frequência de Curator analysis | Performance |

---

## 16. Resumo

| Item | Detalhe |
|---|---|
| **Camada** | L3 — Continuidade |
| **Definição** | Lembra entre sessões, tem rotina, mantém referência ao histórico relacional |
| **Sintoma resumo** | "Esse Atlas me conhece" |
| **Construído sobre** | Evidence Ledger + Memory + Curator + Heartbeat L4 |
| **Pré-requisito de** | L4 (sem padrões, nada a iniciar) e L5 (sem consistência temporal, sem caráter) |
| **Cobertura na fase** | Fase 3 do roadmap |
| **Risco principal** | Continuidade falsa, oversharing, identidade comprometida |
| **Critério de sucesso** | Em 30+ dias, sensação acumulada de "ele me conhece" |

---

## Próximos passos de leitura

- `04-iniciativa.md` — L4.
- `07-integracao-atlas/03-personalidade-ledger.md` — eventos relacionais (a escrever).
- `08-roadmap/04-fase-3-continuidade.md` — fase de implementação (a escrever).
