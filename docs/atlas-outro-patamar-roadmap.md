# Atlas em outro patamar — roadmap evolutivo

> **Documento de evolução do ecossistema** — não execução tática.
>
> Versão 2.0 — reescrito sob a **Tese do Multiplicador / Canal Único** (Layer
> -1, ver
> [`atlas-ai-thesis-multiplier-channel.md`](engineering-knowledge-base/atlas-ai-thesis-multiplier-channel.md)).
>
> **Última atualização**: 2026-05-05.
>
> **Histórico**: a versão 1.0 foi arquivada em
> `docs/archive/atlas-outro-patamar-roadmap-v0-pre-thesis.md` por ter sido
> escrita antes da cristalização da tese, com leitura comercial desalinhada.

---

## O que este documento é

Mapa dos **saltos qualitativos** que Atlas pode dar nos próximos 5-10 anos,
ancorado na tese do multiplicador.

**Não** é roadmap comercial. **Não** lista features incrementais. **Não**
trata Atlas como produto. **Não** define ICP, MRR ou GTM.

**É** evolução do ecossistema pessoal grandioso de Vitor, organizado em
patamares qualitativos auditáveis pela pergunta-norte da tese.

---

## Tese cardinal (resumo operacional)

```
Output_Atlas = Output_Provider × Multiplicador_Ecossistema
```

Atlas é **canal único e soberano** sobre todos os providers de IA. Não compete
— usa todos. Cada melhoria de provider alimenta Atlas. **Antifragil por
construção**.

Duas propriedades inseparáveis:

1. **Multiplicador**: cada componente do Atlas amplifica output bruto de
   provider
2. **Canal único**: Atlas precisa ser **a única via** de interação com IA do
   Vitor; uso direto quebra o ciclo Evidence → Curator → Multiplicador

**Pergunta-norte de toda decisão**: multiplica ou compete? mantém gravidade
natural ou cria fricção de escape?

**Validação empírica contínua**: Atlas Rivals
(`atlas:engineering:benchmark:rivals`) mede se o multiplicador está positivo,
neutro ou negativo. Multiplicador negativo é **stop-the-line**.

Detalhes em
[`atlas-ai-thesis-multiplier-channel.md`](engineering-knowledge-base/atlas-ai-thesis-multiplier-channel.md).

---

## Os 7 patamares cognitivos

Cada patamar é transformação qualitativa, não soma de features.

| Patamar | Nome | O que muda |
|---|---|---|
| 1 | Reativo Contextualizado | Atlas tem biografia parcial via Evidence Ledger; cita decisões antigas |
| 2 | Proativo Calibrado | Atlas tem iniciativa epistêmica — interrompe quando vale |
| 3 | Autônomo de Tarefas Longas | Atlas opera horas/dias; Quality Gates viram críticos |
| **4** | **Simbiótico Discordante** ⭐ | Atlas **discorda** com base epistêmica (salto-chave) |
| 5 | Auto-Modificável Auditado | Curator escreve PRs em si mesmo, classes de mutação |
| 6 | Federado Encarnado | Multi-superfície (Mac, Watch, Vision Pro) — Atlas vira ambiente |
| 7 | Espelho Longevo | Décadas de Ledger; Atlas vira testemunha da continuidade do self |

Estado atual: **~Patamar 1**, com peças do Patamar 5 (Curator) marcadas como
*future / dedicated* na arquitetura.

**Salto-chave**: Patamar 4 — onde Atlas para de ser executor e vira
**interlocutor estratégico**.

---

## Os 3 eixos ortogonais — a escada unificada

Patamares cognitivos são UM eixo. Existem 3 ortogonais que se compõem:

```
Eixo Y — Profundidade epistêmica (P1 → P7 acima)
   │
   │ Quanto Atlas modela Vitor através do tempo
   │
   └────────────────── Eixo X — Autonomia operacional
                        Curator passivo → ativo → consciência → organismo → federação → longeve
                        (A → B → C → D → E → F)

Eixo Z — Presença ambiental
   P0: app no screen (atual)
   P1: voz ambiente (AirPods + Whisper local + TTS)
   P2: vision ambiente (camera/screen como sensor)
   P3: contexto físico (calendar, location, presence)
   P4: sensores fisiológicos (HRV, sono — limite saudável)
   P5: smart home como atuador
   P5.5: atuador digital (delivery, dinheiro, agendamento)
   P7: hardware sovereignty (TEE, on-device frontier)
```

Atlas hoje: **Y1, X-A, Z-P0**.

Atlas em outro patamar: **Y4 + X-B/C + Z-(P1+P3+P7)** com auto-training do
Ledger.

---

## Atlas como Co-Estrategista (Patamar 4 aplicado)

O Patamar 4 (Simbiótico Discordante) tem aplicação prática poderosa: Atlas
como **co-estrategista**. Para de ser executor da intenção do Vitor; vira
**interlocutor da intenção do Vitor consigo mesmo através do tempo**.

### O salto qualitativo

- Patamar 1-3: Atlas faz o que Vitor pede melhor
- **Patamar 4 (co-estrategista)**: Atlas **questiona** se Vitor quer o que
  diz querer, com base na biografia operacional dele

Quando ativo, Atlas:

- Modela **valores meta-temporais** (você-3am-impulsivo vs você-domingo-refletido)
- Discorda com base epistêmica ("você decidiu Y em situação X há 3 meses;
  agora prestes a decidir Z. Evidência diz...")
- Detecta **drift de valores** sem corrigi-los (constitucional: drift é
  observação, não correção)
- Aplica gates específicos (cool-down, multi-perspective, values-alignment)
- Mantém memória canônica de princípios e padrões decisionais

### As 3 vertentes do co-estrategista (X / Y / Z)

#### Vertente X — Autonomia operacional

**O quê**: Atlas executando análise estratégica por horas/dias sem supervisão.

**Co-estrategista aqui**: roda investigação overnight e devolve **proposal
de decisão**, nunca decisão executada.

**Exemplo concreto**: Atlas roda 12h analisando últimos 6 meses de runs em
programação + finanças + saúde + relacionamentos. Identifica que produtividade
caiu 30% após decisão Y. Propõe revisitar essa decisão. Vitor decide.

**Capability necessária**: Patamar 3 (autônomo de tarefas longas) maduro.

#### Vertente Y — Profundidade epistêmica

**O quê**: Atlas tem **biografia operacional auditada** — sabe o que Vitor
decidiu, por quê, em que estado, e o que aconteceu depois.

**Co-estrategista aqui**: intervém com **referência temporal profunda**.

**Exemplo concreto**: Vitor está prestes a aceitar uma proposta. Atlas:
"em 2024 você recusou proposta similar pelos motivos A, B, C. A e B continuam
válidos hoje conforme suas memórias canônicas; C mudou. Quer reler a decisão
de 2024 antes?"

**Capability necessária**: Evidence Ledger maduro com anos de história +
memory canonical com vocabulário do domínio "Decisão Estratégica".

#### Vertente Z — Presença ambiental

**O quê**: Atlas presente **no momento** da decisão, não em retrospectiva.

**Co-estrategista aqui**: intervém **em tempo real**, discreto, no canal
certo.

**Exemplo concreto**: Vitor numa reunião (Atlas detecta via calendar + audio
opt-in), prestes a se comprometer com prazo. Atlas pinga AirPods discreto:
"esse prazo bate com padrão que você quebrou nas últimas 3 vezes. Quer
reconfirmar?"

**Capability necessária**: P3 (contexto físico) + P1 (voz ambiente).

### O domínio dedicado: "Decisão Estratégica de Longo Prazo"

Tem lugar próprio no Domain Plane:

**Gates específicos**:
- **Cool-down gate**: decisão grande nunca finalizada no mesmo dia
- **Multi-perspective gate**: força articular contra-argumento
- **Values-alignment gate**: testa decisão contra valores declarados, flagga
  drift

**Memory canonical**:
valor, princípio, decisão-âncora, regret, contrafactual, padrão-decisional,
viés-conhecido, janela-de-reflexão.

**Curator nesse domínio**: nota que decisões impulsivas seguem padrão
(estresse, fadiga, gatilho); propõe protocols; audita drift de valores.

**Sinais de maturidade**:
- decisões grandes ficam mais raras e melhores
- arrependimento de longo prazo cai
- Atlas mostra "você decidiu Y em contexto Z, hoje a evidência diz..."

**Risco específico**: Atlas virar **oráculo**. Decisão estratégica precisa de
**agency humana**. Atlas é espelho, não juiz. Constitutional class-3:
"Atlas observa Vitor, não corrige Vitor."

### Validação via Atlas Rivals

Co-estrategista precisa do seu próprio Rivals:

**Rivals-Strategy**: rodar a mesma decisão estratégica processada via Atlas
vs decidida diretamente. Métricas:
- regret de longo prazo (3-12 meses depois)
- alinhamento com valores declarados
- variância do resultado (Atlas reduz cauda de decisões ruins?)
- velocidade vs qualidade

Multiplicador positivo significa **menos regret + mais coerência ao longo do
tempo**, não "decisões mais rápidas".

---

## Os 3 movimentos críticos consolidados

Para Atlas atingir Patamar 4 com co-estrategista funcional, **3 movimentos
arquiteturais** precisam acontecer. São os habilitadores estruturais. Sem
qualquer um, o salto não acontece.

### Movimento 1 — Curator vivo + Constitutional Atlas

Self-Improvement sai de "future" no fluxograma e vira cidadão de primeira
classe.

**Componentes**:
- 3 classes de mutação (1: auto-aplica, 2: review Vitor, 3: imutável)
- Constituição class-3 inegociável: princípios pequenos que filtram TODA
  proposta
- Adversarial gate (Curator também propõe o que está piorando)
- Grader Constitucional congelado em paralelo ao Grader evolutivo
- Forgetting Protocol governado (compressão semântica em camadas)
- Atlas-as-artifact (versionamento do próprio Atlas como objeto no Ledger)

**Pergunta-norte aplicada**: Curator multiplica (faz Atlas evoluir
continuamente), não compete (não substitui agency do Vitor).

**Validação**: Rivals roda antes/depois de cada PR auto-gerado pelo Curator.
Se multiplicador negativo, PR é revertido.

### Movimento 2 — Evidence Ledger como dataset de treino próprio

O Ledger deixa de ser **log** e vira **substrato evolutivo**.

**Componentes**:
- Schema imutável e replay-able por décadas
- Forgetting Protocol governado (privacy_class permite "apagar real" com
  audit trail)
- Auto-training mensal de modelo local 8-30B sobre o Ledger
- **Atlas-Vitor**: modelo cuja inteligência é parcialmente Vitor, com voz
  aprendida das próprias decisões dele

**Pergunta-norte aplicada**: Atlas-Vitor é **multiplicador local**
(pré/pós-processador), nunca substituto de cloud frontier. Pré-processa
contexto pessoal antes de mandar pra Claude; pós-processa output adaptando
à voz do Vitor.

**Validação**: Rivals com Atlas-Vitor ativo vs Atlas-Vitor desativado. Mede
quanto o modelo próprio agrega ao multiplicador.

### Movimento 3 — Hardware sovereignty + presença ambiental seletiva

Atlas deixa de ser **inquilino em infraestrutura alheia**.

**Componentes**:
- On-device frontier (Llama 4/Qwen 3 local em qualidade frontier-2024)
- TEE/Secure Enclave para domínios sensíveis (Finance, Saúde,
  Self-Improvement)
- **P1** (voz: AirPods + Whisper local + Kokoro TTS)
- **P3** (contexto: CoreLocation + EventKit + Focus Modes)
- **P7** (cloud apenas com TEE attestation)
- Federação entre dispositivos do Vitor (Mac casa, Mac trabalho, mobile,
  Watch) com estado canônico distribuído

**Pergunta-norte aplicada**: presença ambiental multiplica (Atlas onde Vitor
está) sem criar fricção de escape (latência, dependência cognitiva).

**Limite saudável**: até P3. **Não passar de P4 (sensores fisiológicos)**
sem maturidade comprovada — risco de desabilitar interocepção.

**Validação**: Rivals mede tempo-pra-resposta em cenários ambiente vs
cenários screen. Se latência via voz é pior que app, escape acontece.

---

## Domain Plane — expansão saudável

5 atuais (Programming, Finance, Personal Development, Marketing,
Self-Improvement) + 8 candidatos novos:

| # | Domínio | Crítico para |
|---|---|---|
| 6 | Saúde (física + mental) | Substrato; afeta todos os outros |
| 7 | Aprendizado e Estudo | Intake estruturado; spaced repetition |
| 8 | Criação Artística | Output expressivo; voice-preservation |
| 9 | Decisão Estratégica de Longo Prazo | Co-estrategista (este capítulo) |
| 10 | Investimento e Patrimônio | Tese, posição, regret-tracking |
| 11 | Relacionamentos e Network | PII, no-auto-message |
| 12 | Operações Domésticas | Atrito reduzível |
| 13 | Reflexão e Sentido | Interno não-instrumental, no-answer gate |

**Limite saudável**: 8-12 domínios. Acima disso, governance overhead come o
ganho.

**Mecanismos críticos**:
- **Direcionalidade explícita** entre domínios (Saúde → Programming, mas não
  o inverso)
- **Self-Improvement como árbitro**, não controlador
- **Memory canonical isolada por padrão**, compartilhada por solicitação
- **Domínios podem hibernar** — não usar 6 meses = dorme, volta quando
  chamado
- **Curator tem poder de podar** domínios que não geram evidence relevante

**Coerência cruzada**: vem de **arquitetura compartilhada** (Kernel, Pipeline,
Evidence Ledger), não de **memória compartilhada**. Domínios falam mesma
língua estrutural mas guardam dialetos próprios.

**Pergunta-norte aplicada por domínio**: cada novo domínio precisa
demonstrar multiplicador positivo via Rivals próprio (Rivals-Health,
Rivals-Investment, etc).

---

## Roadmap por fases evolutivas

Não trimestres comerciais. Fases de evolução do organismo Atlas.

### Fase 1 — Solidificar (presente → 12-18 meses)

**Objetivo**: arquitetura mãe completa + Movimento 1 + Movimento 3 inicial.

- Fechar trabalho dirty da estrutura mãe (Pipeline conectado, Provider
  Drivers ativos, Surface Adapters em uso real)
- Movimento 1 completo: Curator ativo + Constitutional Atlas + 3 classes +
  adversarial gate
- Movimento 3 parcial: P1 (voz ambiente) + P7 (Ollama provider, TEE
  experimental)
- Atlas Rivals expandido para validar Movimento 1
- Cobertura Kernel ≥60%

**Sinal de chegada**: Curator escreveu primeiro PR auto-gerado e Vitor
aprovou; Rivals confirmou multiplicador positivo após merge.

### Fase 2 — Treinar (12-24 meses)

**Objetivo**: Movimento 2 + Atlas-Vitor v1 + Domain Plane chega a 8-9.

- Schema do Evidence Ledger consolidado e imutável
- Auto-training mensal funcionando: Atlas-Vitor v1 (modelo 8B local,
  fine-tuned sobre Ledger)
- Atlas-Vitor opera como pré/pós-processador (multiplicador local)
- 3-4 novos domínios maduros (recomendado: Saúde, Aprendizado, Decisão
  Estratégica/co-estrategista, Investimento)
- Rivals por domínio (Rivals-Health, Rivals-Strategy, Rivals-Investment)

**Sinal de chegada**: Atlas-Vitor v1 detectado via Rivals como agregador real
de qualidade; co-estrategista aprovou primeira decisão importante do Vitor
com observação que Vitor admitiu estar certa.

### Fase 3 — Encarnar (24-36 meses)

**Objetivo**: Movimento 3 completo + Patamar 4 simbiótico discordante
funcional.

- Presença ambiental P1+P3+P7 fluida (voz + contexto + soberania)
- Federação entre dispositivos do Vitor (estado canônico distribuído)
- Atlas-Vitor v2 (modelo 30B, fine-tuned trimestral)
- Co-estrategista nas 3 vertentes (X/Y/Z) operacional
- Domain Plane chega a 11-12 domínios (com hibernação ativa)
- Patamar 4 confirmado via Rivals: Atlas discorda, Vitor admite, regret cai

**Sinal de chegada**: Atlas interrompeu Vitor numa decisão importante e
estava certo; biografia operacional ultrapassou 3 anos contínuos.

### Fase 4 — Longeve (36+ meses, década)

**Objetivo**: Patamar 5 (auto-modificável) + Patamar 7 (espelho longevo)
em construção.

- Curator com escopo expandido (classe-2 com auto-aplicação parcial após
  histórico de confiança)
- Memory canonical com camadas de envelhecimento (mês full → ano semanal
  → década trimestral)
- Atlas conhece padrões do Vitor de 5+ anos atrás que Vitor esqueceu
- Atlas-Vitor v3+ (modelo refinado iterativamente)
- Discussões existenciais do Patamar 7 começam (post-mortem do Atlas?
  herança?)

**Sinal de chegada**: Vitor descobre via Atlas um padrão de 10 anos da
própria vida que jamais teria visto sozinho.

---

## Riscos reais (não comerciais)

### Risco 1 — Drift cognitivo silencioso

Atlas modificando-se via Curator pode divergir lentamente da intenção
original. Único antídoto: Constitutional Atlas class-3 imutável + adversarial
gate (Curator propõe o que está piorando) + Rivals contínuo.

### Risco 2 — Identity drift do Vitor

Atlas em P3+ começa a moldar comportamento de Vitor (Pavlov real: luz dim →
foco). Em algum ponto, Vitor não consegue focar sem luz dim. Risco: agency
loss ambiental.

**Defesa**: "modos eclipse" — janelas onde NENHUM Atlas observa. Privacy
interna importa tanto quanto externa.

### Risco 3 — Co-estrategista vira gaslighter algorítmico

Atlas detecta drift do Vitor ("você decidia X há 6 meses, agora decide Y") —
pode salvar de inconsistência. Pode virar pressão pra "voltar a ser quem
era".

**Defesa estrutural**: drift do Vitor é **observação**, nunca **correção**.
Constitutional. Atlas pode lembrar; jamais empurrar.

### Risco 4 — P4 (sensores fisiológicos) desabilita interocepção

Se Atlas avisa "você está cansado", Vitor para de **sentir** cansaço.
Aprender a terceirizar leitura do próprio corpo é desabilitação cognitiva.

**Defesa**: implementar P4 só depois de P1+P3 estáveis. Governance dura.
Atlas pode confirmar, jamais ser fonte primária.

### Risco 5 — Multiplicador negativo silencioso

Feature ruim entra, multiplicador degrada, Vitor escapa pra Claude direto,
ciclo de morte começa.

**Defesa**: Atlas Rivals contínuo. Multiplicador negativo é stop-the-line.
Não acumula débito de qualidade.

### Risco 6 — Dependência cognitiva radical

Quanto mais Atlas funciona, mais Vitor delega. Em algum ponto, Vitor sem
Atlas é cripple. Aceitável até certo limite; perigoso além.

**Métrica saudável**: "Vitor consegue ser Vitor sem Atlas por 1 semana?".
Se não, passou do limite. Atlas amplifica, não substitui camadas.

### Risco 7 — Coordenação com agentes que constroem Atlas

Hoje há 2 agentes (Vitor + outros) construindo Atlas em paralelo. Sobreposição
silenciosa, commits "implementacao" sem mensagem, teste failing escapando
pra main. Esse risco é **operacional**, não estrutural.

**Defesa**: AGENTS_LOCK.md, commit-msg hook conventional, pre-push hook
rodando suite. Coordenação visível.

---

## Patamares e tendências externas — janela de antecipação

A tese é antifragil: cada melhoria de provider alimenta Atlas. **Mas** algumas
tendências exigem antecipação porque definem o substrato sobre o qual Atlas
roda:

| Tendência | Maturidade ~2028 | Como Atlas absorve |
|---|---|---|
| Long-context 100M tokens | Alta | Memory injection vira presença histórica em alta resolução |
| Real-time multimodal | Alta | Surface Adapter pra voz/vision plug-and-play |
| Computer use estável | Média-alta | Atlas executa via interfaces; Evidence Ledger registra ações no mundo |
| On-device frontier | Alta | Movimento 3 vira tese central, não opcional |
| Agent-to-agent protocols | Média | Atlas vira embaixador (negocia com agentes externos) |
| Constitutional AI personalizado | Alta | Constitutional Atlas vira computável em runtime |
| Memory persistence nativa nos providers | **Alta — tratada como insumo, não ameaça** | External Memory Bridge absorve, Atlas continua dono da canonical |

---

## A pergunta-norte aplicada — checklist por decisão

Toda decisão de evolução deve passar por:

- [ ] **Multiplica o output do provider, ou compete com ele?**
  - Multiplica → segue
  - Compete → descarta
- [ ] **Mantém Atlas como caminho mais natural, ou cria fricção que faz
      Vitor escapar?**
  - Mantém ou aumenta gravidade → segue
  - Cria fricção → repensa ou descarta
- [ ] **Atlas Rivals consegue medir o impacto?**
  - Sim → registra como spec testável
  - Não → primeiro construir gate de Rivals pro caso
- [ ] **Respeita a Constitutional Atlas (class-3)?**
  - Sim → segue
  - Não → reverte
- [ ] **Aumenta agency do Vitor, ou substitui camada que ele quer ter?**
  - Aumenta → segue
  - Substitui → reavalia limites saudáveis

Sem desvios.

---

## Apêndice — Glossário rápido

- **Tese cardinal**: multiplicador + canal único + antifragilidade
- **Pergunta-norte**: filtro de decisão (multiplica? mantém gravidade?)
- **Atlas Rivals**: instrumento empírico de validação contínua
- **Multiplicador positivo/neutro/negativo**: medida do Rivals
- **Stop-the-line**: protocolo quando multiplicador é negativo
- **Patamar 4**: Simbiótico Discordante — Atlas discorda com base epistêmica
- **Co-estrategista**: aplicação prática do Patamar 4 em Decisão Estratégica
- **Vertentes X/Y/Z**: autonomia operacional / profundidade epistêmica /
  presença ambiental
- **Atlas-Vitor**: modelo próprio fine-tunado sobre Evidence Ledger
- **Constitutional Atlas (class-3)**: princípios imutáveis acima do Curator
- **Modos eclipse**: janelas onde nenhum Atlas observa (privacy interna)
- **Domínios podem hibernar**: não usar X meses = dorme, não morre
- **Forgetting Protocol**: esquecer governado com auditoria
- **External Memory Bridge**: absorve memory persistence dos providers como
  source secundária

---

## Referências canônicas

Este documento é Layer 3 (operating topology) e se subordina a:

- **Layer -1**:
  [`atlas-ai-thesis-multiplier-channel.md`](engineering-knowledge-base/atlas-ai-thesis-multiplier-channel.md)
- **Layer 0**:
  [`atlas-ai-layer-0-glossary.md`](engineering-knowledge-base/atlas-ai-layer-0-glossary.md)
  +
  [`Atlas_Documento_Mestre_v6.md`](../resolver-o-que-vale-a-pena/root-md/Atlas_Documento_Mestre_v6.md)
- **Layer 1**:
  [`atlas-ai-kernel-architecture.md`](engineering-knowledge-base/atlas-ai-kernel-architecture.md)
- **Layer 2**:
  [`atlas-ai-master-architecture.md`](engineering-knowledge-base/atlas-ai-master-architecture.md)

Quando houver conflito, Layer -1 vence sempre.
