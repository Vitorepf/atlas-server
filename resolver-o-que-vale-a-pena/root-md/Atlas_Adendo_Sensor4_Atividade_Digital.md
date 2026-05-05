# ATLAS — ADENDO

**Sensor 4: Atividade Digital**

*Proposta de expansão do Sistema Tri-Sensorial para Quatro Sensores*

---

| | |
|---|---|
| **Operador** | Vitor Emanuel |
| **Status** | Adendo de pesquisa, não constitucional |
| **Versão de origem** | Documento Mestre v6.0 |
| **Data de proposta** | 28 de abril de 2026 |
| **Decisão constitucional prevista** | Annual Review janeiro 2027 |
| **Implementação operacional sugerida** | V1.5 (sem aguardar revisão constitucional) |

---

## Status do Documento

Este adendo **não altera a v6.0**. A constituição vigente permanece com Sistema Tri-Sensorial (pensamento, saúde, cognição). Esta proposta documenta uma expansão arquitetural identificada após a finalização da v6 e a registra para council formal de Annual Review.

A implementação operacional pode (e deve) começar antes da decisão constitucional, dentro do backlog V1.5+, porque envolve coleta de dados que terá valor independente do desfecho da revisão. Esperar 9 meses para começar a coletar é desperdício de dataset histórico.

A substância da proposta tem três funções:

1. **Registrar o pensamento** antes que se perca, conforme Lei 10 (curadoria de input começa pela curadoria do próprio pensamento sobre o sistema).
2. **Orientar a equipe técnica** sobre direção provável da v7, evitando refatoração custosa quando a expansão for ratificada.
3. **Permitir teste empírico** durante 9 meses antes da formalização, para que council de janeiro decida com dados reais, não com argumentos.

---

## 1. O Gap Identificado

### 1.1 O que a v6 cobre

A v6.0 estabelece Sistema Tri-Sensorial:

- **Sensor 1 — Pensamento.** Capturas ativas e estruturadas. O input cognitivo direto que Vitor escolhe registrar.
- **Sensor 2 — Saúde.** O corpo como input contínuo via HealthKit, Apple Watch Ultra, Bevel, Rize.
- **Sensor 3 — Cognição.** Métricas longitudinais de articulação, motivação e transferência.

### 1.2 O que a v6 NÃO cobre adequadamente

A v6 menciona Rize.io no Cap. 10.5 como sensor de atividade digital, mas trata superficialmente. O gap real é maior:

**Atlas vê o que Vitor escolhe capturar. Não vê o que entra na cabeça de Vitor antes da captura.**

Vitor passa horas em consumo digital. Twitter, YouTube, Substack, Apple News, Reddit, WhatsApp, jogos, streaming, leitura técnica, documentação. Esse fluxo molda pensamento, decisão, mood, energia, identidade. E Atlas é cego para ele.

Consequência: **Lei 10 (Curadoria de Input) está cumprida pela metade.** A Lei trata da curadoria do que vira captura, mas não da curadoria do que entra antes — input bruto que precede toda captura.

### 1.3 Por que isso importa constitucionalmente

A literatura da pesquisa fundadora (Anexo F da v6) aponta diretamente:

- **Gerlich 2025** — correlação negativa forte entre uso de IA / offloading cognitivo e pensamento crítico (r = -0,68 e -0,75). Mesma mecânica vale para consumo passivo de mídia algorítmica.
- **Microsoft CHI 2025** — confiança em fontes externas substitui esforço crítico próprio. Aplicável a IA e a feeds.
- **Carpenter et al. 2022** — recuperação ativa e espaçamento são fundamentais. Consumo passivo é o oposto.

Atlas que ignora consumo digital é Atlas que detecta atrofia cognitiva sem detectar a causa principal dessa atrofia.

---

## 2. Proposta: Sensor 4 — Atividade Digital

### 2.1 Por que sensor separado e não expansão do Sensor 2

Três opções foram consideradas:

1. **Expandir Sensor 2 (Saúde) para "Saúde + Comportamento Digital".** Tecnicamente possível. Rize já mora no Cap. 10. Argumento contra: natureza dos dados é diferente. Saúde fisiológica é sinal contínuo passivo do corpo. Atividade digital é sinal de comportamento intencional (ou de inércia comportamental). Misturar reduz clareza analítica.

2. **Tratar como sub-categoria do Sensor 1 (Pensamento).** Argumento contra: Sensor 1 é captura ativa por escolha. Atividade digital é coleta automática contínua, sem ato de captura.

3. **Sensor 4 separado com articulação cruzada aos outros três.** Recomendado. Natureza própria, fontes próprias, métricas próprias, mas integração obrigatória com os demais sensores.

### 2.2 Definição

> **Sensor 4 — Atividade Digital.** Sistema contínuo de captura, categorização semântica e análise do consumo digital do operador. Mede o que entra na cabeça via tela antes de virar captura ativa. Subordinado à Lei 10 (Curadoria de Input) e à Lei 9 (Cognição Aumentada Não-Negociável). Cruza ativamente com os Sensores 1, 2 e 3 para formar visão integrada do estado cognitivo real.

### 2.3 Princípio operacional

Sensor 4 não julga moralmente. Não classifica binariamente "produtivo / improdutivo". Reconhece que Crimson Desert por 2 horas escolhido é vida boa (Cap. 21), enquanto YouTube autoplay por 2 horas não escolhido é erosão.

A diferença é **intencionalidade**, mensurável via padrões. E a categorização é de Vitor — não genérica, não imposta, não importada de produtividade-app de mercado.

---

## 3. Fontes e Sinais

### 3.1 Fontes primárias

| Fonte | Plataforma | Granularidade | Status |
|---|---|---|---|
| **Rize.io** | macOS | Categoria automática + tempo por app/site, sessões de foco | Já em uso por Vitor; integração via API ou export |
| **Screen Time API** | iOS / macOS | Tempo por app, pickups, notificações, primeira-uso-do-dia | Native; requer entitlement Family Controls (iOS) |
| **Apple Focus** | iOS / macOS / watchOS | Modo de foco ativo, contexto declarado | Sinal de intencionalidade |
| **Apple Books / Kindle / Readwise** | iPhone / iPad | Tempo lendo, livros ativos, highlights | Input curado mensurável |
| **Plataformas explícitas de entretenimento** | Multi | Netflix, Steam (PC/Mac), Apple TV, PlayStation | Manual ou export periódico |
| **Apps de comunicação** | iPhone | WhatsApp, iMessage — só metadados, não conteúdo | Tempo, não conteúdo |
| **Browser history (opcional)** | macOS / iOS | URLs, tempo em domínio | Manual via export, não tracking automático |

### 3.2 Cláusula de privacidade estrutural

Atlas captura **metadados de comportamento digital**, não conteúdo de mensagens privadas. WhatsApp com Carol é tempo total de uso, não transcrição. Browser history é domínio, não query. A linha é clara: dados sobre o que Vitor faz, não sobre o que outros compartilham com Vitor em confiança.

A cláusula reforça Lei 6 (Dataset Sagrado) e Lei 5 (Stakeholders): registrar comportamento digital de Vitor não pode comprometer privacidade de terceiros.

### 3.3 Sinais derivados

A partir das fontes primárias, Atlas computa:

- **Tempo total por categoria semântica** (ver §4)
- **Pickups e fragmentação** — quantas vezes Vitor pega o iPhone por dia, intervalo médio entre pickups
- **Janelas de foco profundo** — sessões >25 min sem interrupção em apps de trabalho
- **Janelas de procrastinação** — sessões em entretenimento default em horário onde missão do dia exigia trabalho deep
- **Padrão circadiano de consumo** — distribuição ao longo do dia
- **Latência de primeiro uso ofensivo** — em qual minuto após acordar Vitor abre Twitter/YouTube/feed
- **Tempo entre intenção e ação** — se Vitor declarou Focus "Work Deep" e em quanto tempo violou
- **Notification load** — número e categoria de notificações recebidas

---

## 4. Categorização Semântica

### 4.1 Princípio

Categorização binária produtivo/improdutivo é falsa e moralmente carregada. Atlas usa categorização de **10 classes** que reflete a vida real do operador, com granularidade que permite análise útil sem julgamento moral implícito.

A categorização é **declarada pelo operador**, não importada. Vitor mapeia cada app e site uma única vez (com refinamento contínuo) para uma das 10 classes. Mapping vive em arquivo versionado.

### 4.2 As 10 Classes

| Classe | O que conta | Função no sistema |
|---|---|---|
| **1. Trabalho deep** | Cursor, Terminal, Figma, Linear, Postman, Xcode, sessões de código | Input de alto valor — foco profundo |
| **2. Trabalho shallow** | Slack, email, Notion admin, planilhas, reuniões Zoom/Meet | Necessário, mas vigiado contra excesso |
| **3. Input curado** | Substack curado, Apple Books, Readwise, Anthropic docs, papers, podcasts longos, YouTube canais selecionados | Lei 10 — input de qualidade explícita |
| **4. Input algorítmico** | Twitter/X, Instagram, TikTok, YouTube Shorts, Reddit feed, Apple News feed | Lei 10 — risco de erosão cognitiva |
| **5. Entretenimento intencional** | Netflix com escolha deliberada, jogos com narrativa (Crimson Desert), filmes, séries em sessão fechada | Vida boa, parte do Cap. 21 |
| **6. Entretenimento default** | YouTube autoplay, scrolling sem intenção, Netflix por inércia | Procrastinação real, candidato a confronto |
| **7. Comunicação primária** | WhatsApp com Carol/família/amigos próximos | Lei 5 — relacionamentos |
| **8. Comunicação shallow** | Group chats baixo valor, broadcast lists, comunidades muito ativas | Vigiado contra dreno cognitivo |
| **9. Mercado / B3** | Investidor10, TradingView, corretora, apps financeiros | Trabalho ou ansiedade dependendo de contexto |
| **10. Pesquisa pontual** | Google, Wikipedia, Stack Overflow para resolver problema específico | Neutro, raramente vira padrão |

### 4.3 Cláusula de evolução

A categorização não é fixa. Vitor pode reclassificar app a qualquer momento. Reclassificação preserva histórico (campo `category_at_time` no schema), permitindo análise correta de períodos passados sob categoria vigente naquele momento.

Reclassificações em massa (ex: "todo o YouTube vira input algorítmico exceto canais X, Y, Z") são suportadas via mapping versionado em arquivo, não via UI repetitiva.

### 4.4 Cláusula contra-genérica

A literatura de produtividade publica frameworks como "Cal Newport deep work hours". Atlas **não importa** essas categorias. O que é trabalho deep para Vitor é definido por Vitor. Cursor é deep. Slack não. Obsidian é... depende: capturando pensamento próprio é input curado; lendo notas alheias é shallow. Nuance é função do operador, não do framework.

---

## 5. Articulação Cruzada com os Outros Sensores

A inteligência real do Sensor 4 não está nos números brutos. Está no cruzamento com Sensores 1, 2 e 3.

### 5.1 Sensor 4 × Sensor 1 (Pensamento)

**Hipóteses operacionais a testar:**

- Capturas após sessões de trabalho deep têm complexity score maior?
- Capturas após sessões de input algorítmico têm complexity score menor?
- Articulação ecoa fontes consumidas? (briefing echo digital — extensão do Cap. 11.4)
- Decisões tomadas após >60min de input algorítmico mostram padrão diferente em qualidade ou alinhamento com princípios?

**Mecânica de detecção:**

Se Vitor passa 45min em Twitter antes de capturar uma decisão financeira, Atlas marca a captura com `pre_capture_digital_context`. Análise longitudinal cruza: capturas com `pre_capture_digital_context = algorithmic_input` versus capturas com contexto de input curado ou trabalho deep.

**Confronto possível (V2+):**

> *"Suas últimas 8 capturas de decisão financeira foram precedidas por mais de 30 minutos de input algorítmico. Em decisões similares no passado, isso correlacionou com revisitação negativa em 67% dos casos. Continuar?"*

Confronto informacional, não controlador (Lei 7).

### 5.2 Sensor 4 × Sensor 2 (Saúde)

**Hipóteses operacionais a testar:**

- Tempo em entretenimento default à noite correlaciona com latência de adormecer?
- Pickups frequentes durante o dia correlacionam com HRV em queda?
- Mais de 3h de input algorítmico em um dia correlaciona com energia subjetiva no dia seguinte?
- Latência de primeiro uso ofensivo após acordar correlaciona com produtividade do dia?

**Mecânica de detecção:**

`health_snapshots` e `digital_activity_snapshots` são cruzados em análise noturna. Atlas computa correlações específicas do operador (não médias populacionais — o que importa é o padrão de Vitor, não literatura genérica).

**Snapshot Cognitivo expandido:**

Snapshot trimestral (Cap. 11.6 da v6) ganha sub-seção:

```
SAÚDE × DIGITAL — Q2 2026
  Latência de primeiro uso ofensivo: 17 min após acordar (Q1: 4 min, +13 min positivo)
  Tempo em entretenimento default noturno: 38 min/dia (Q1: 71 min, -33 min positivo)
  Correlação HRV × pickups: r = -0.34 (HRV mais baixo em dias com mais pickups)
  Correlação energia × input algorítmico ontem: r = -0.41
```

### 5.3 Sensor 4 × Sensor 3 (Cognição)

Esta é a articulação mais importante. **Sub-dimensão A6 nova: Higiene de Input.**

A Dimensão A (Cognição) da v6 tem 5 sub-dimensões. Adicionar:

**A6 — Higiene de Input**

- **Curated/Algorithmic Ratio.** Tempo em classes 3+1 versus tempo em classes 4+6. Ratio crescente = curadoria evoluindo.
- **Deep Work Density.** Sessões de classe 1 com duração >25min sem interrupção, por dia.
- **Information Source Diversity.** Número de domínios distintos em input curado por mês — diversidade saudável evita echo chamber.
- **Echo Chamber Indicator.** Similaridade semântica entre conteúdo consumido (extraível de Readwise highlights, papers lidos) e capturas. Score alto sustentado = ecoamento.
- **Notification Discipline.** Notificações recebidas vs notificações ignoradas. Proxy de fricção atencional.

**Dimensão B (Motivação) ganha sinais:**

- **Pickups antes de tarefa profunda** — proxy de ansiedade pré-trabalho
- **Time-to-flow** — tempo entre abrir app de trabalho e atingir sessão de foco profundo

**Dimensão C (Transferência) ganha teste:**

- Durante Semana Solo (Cap. 15.9), Sensor 4 mede se padrões de consumo digital mudam quando Atlas está em silêncio. Se Vitor consome menos algoritmico em semana solo, isso revela que Atlas estava induzindo certo padrão (positivo ou negativo).

### 5.4 Detecção integrada de procrastinação

Procrastinação real só é detectável com cruzamento dos 4 sensores:

```
DEFINIÇÃO OPERACIONAL DE PROCRASTINAÇÃO REAL:

  Sensor 4 (Digital): tempo em classe 6 (entretenimento default) >30min
  +
  Sensor 1 (Pensamento): missão do dia declarada e não cumprida
  +
  Sensor 2 (Saúde): estado fisiológico não justifica (HRV ok, energia ≥3)
  +
  Sensor 3 (Cognição): check-in subjetivo não declarou pausa intencional

  → Procrastinação real, candidato a confronto V2+
```

Com a definição rigorosa, Atlas evita falsos positivos. Vitor descansando após HRV em queda, com energia baixa e pausa declarada, **não é procrastinação** — é regulação.

---

## 6. Implicações Constitucionais

### 6.1 Lei 10 (Curadoria de Input) — cláusula adicional

Cláusula proposta para council de janeiro:

> **Cláusula de Input Digital:** Curadoria de input se aplica ao consumo digital. Atlas mede categoricamente o que entra via tela e confronta padrões de input algorítmico que erodem cognição. Operador é responsável por escolher fontes de informação de qualidade. Atlas é responsável por mostrar quando o padrão derivou.

A cláusula não muda a substância da Lei 10; expande o escopo. Curadoria já é princípio; agora cobre input que precede captura, não apenas captura.

### 6.2 Lei 9 (Cognição Aumentada) — sub-dimensão de métrica

Sub-dimensão A6 (Higiene de Input) proposta entra na arquitetura de métricas cognitivas. Não como Lei nova; como sub-dimensão da métrica primária de Lei 9.

### 6.3 Lei 7 (Confronto Informacional) — escopo expandido

Confronto informacional agora pode disparar com base em padrões de Sensor 4. Mas as restrições da v6 permanecem: confronto informacional, não controlador. Mostrar dado, convidar reflexão, permitir override sem culpa.

Atlas **nunca** bloqueia app, **nunca** impõe limite forçado, **nunca** usa pressão moral. A linha é absoluta.

### 6.4 Cap. 21 (Vida Fora do Sistema) — proteção, não otimização

Sensor 4 captura tempo em jogos, streaming, entretenimento intencional. Cap. 21 da v6 deixa claro: esses dados existem para **proteger** vida boa, não para otimizar fora dela.

Cláusula sugerida:

> *Atlas reconhece tempo em entretenimento intencional como vida boa, não como métrica a minimizar. Crimson Desert, jogos, perfumes, livros, jantares com Carol — tudo isso é parte do que Atlas existe para proteger. Confronto sobre entretenimento default não se estende a entretenimento intencional. Sensor 4 distingue rigorosamente.*

---

## 7. Schema de Dados Proposto

### 7.1 Tabelas novas

```sql
-- Snapshots de atividade digital (captura passiva contínua)
digital_activity_snapshots
├── id, snapshot_date
├── total_screen_time_min
├── pickups_count, first_offensive_use_min_after_wake
├── deep_work_sessions_count, deep_work_total_min
├── notifications_received, notifications_actioned
├── focus_mode_active_min JSONB  -- por modo ativo
├── category_breakdown JSONB  -- min por classe (1-10)
├── source_breakdown JSONB  -- min por app/site
├── raw_rize_data JSONB
├── raw_screentime_data JSONB
└── created_at

-- Mapping de apps/sites para classes (versionado)
digital_category_mappings
├── id, source_identifier  -- bundle ID, domínio
├── source_name  -- "Twitter", "Cursor", etc.
├── category_class  -- 1-10
├── classified_by  -- "operator" | "system_suggestion"
├── valid_from, valid_until  -- nullable, NULL = atual
├── notes
└── created_at, updated_at

-- Sessões individuais relevantes para análise
digital_sessions
├── id, source_identifier, category_class_at_time
├── started_at, ended_at, duration_min
├── focus_mode_active, intentional_flag  -- declarado pelo operador
├── linked_capture_id  -- se sessão foi seguida por captura relevante
├── linked_decision_id
└── created_at

-- Contexto digital pré-captura (cruzamento com Sensor 1)
captures
├── ... campos da v6 ...
├── pre_capture_digital_context JSONB  -- NOVO
│   {
│     "last_60min_categories": {"4": 45, "1": 15},
│     "last_app_used": "Twitter",
│     "deep_work_today_min": 0,
│     "algorithmic_input_today_min": 180
│   }
└── ...

-- Eventos de procrastinação detectada (auditoria de regras)
procrastination_events
├── id, detected_at
├── duration_min, primary_category_class
├── mission_active  -- missão do dia declarada
├── physiological_state JSONB  -- HRV, energia, mood no momento
├── confronted, operator_response
└── created_at
```

### 7.2 Volume de dados estimado

Sensor 4 gera mais dados que os demais sensores combinados (granularidade por minuto, multi-fonte, multi-app). Implicações:

- `digital_activity_snapshots` agregado por dia: ~365 rows/ano (manejável)
- `digital_sessions` granular: ~5.000–15.000 rows/ano (manejável)
- `digital_category_mappings` baixo volume: ~200 rows total
- Raw data em JSONB pode crescer ~50–200 MB/ano

Particionamento mensal recomendado em V1.5+. Backup separado por se eventualmente Vitor decidir purgar dados granulares preservando agregados.

---

## 8. Roadmap de Implementação

### 8.1 V1.5 — Coleta básica (sem inteligência ativa)

**Semanas 10–14:**

- [ ] Schema de Sensor 4 implementado
- [ ] Integração Rize.io via API ou export periódico
- [ ] Classificação inicial: Vitor mapeia top 30 apps/sites manualmente
- [ ] `digital_activity_snapshots` populando diariamente
- [ ] Dashboard básico mostrando categoria × tempo no Weekly Review

**Decisão de prioridade:** Sensor 4 entra em V1.5 ainda que council formal aconteça apenas em janeiro 2027. Razão: o dataset histórico vale mais que esperar ratificação. Se council rejeitar a expansão constitucional em janeiro, dados continuam sendo úteis operacionalmente. Custo de coletar é baixo; custo de não coletar é irrecuperável.

### 8.2 V1.5+ — Cruzamentos básicos

**Semanas 14–18:**

- [ ] `pre_capture_digital_context` populando em cada captura
- [ ] Cruzamento Sensor 4 × Sensor 2 em snapshot semanal
- [ ] Sub-dimensão A6 (Higiene de Input) entrando em métricas cognitivas
- [ ] Mapping completo de apps usados regularmente

### 8.3 V2 — Detecção e confronto

**Semanas 18–32:**

- [ ] Detecção integrada de procrastinação real (regra dos 4 sensores)
- [ ] Confronto informacional sobre input algorítmico crescente
- [ ] Briefing echo digital (extensão de Cap. 11.4)
- [ ] Alertas de echo chamber (baixa diversidade de fontes)
- [ ] Snapshot trimestral com seção Saúde × Digital

### 8.4 V2.5 — Correlações de alto impacto

**Semanas 32–48:**

- [ ] Correlação Sensor 4 × decisões financeiras (qualidade de B3 vs input pré-decisão)
- [ ] Recomendações de janelas para trabalho deep baseadas em padrão histórico
- [ ] Detecção de drift cognitivo via análise de fontes consumidas
- [ ] Regras JITAI específicas baseadas em Sensor 4

### 8.5 V3 — Coevolução

- [ ] Sensor 4 plenamente integrado ao centaur cognitivo
- [ ] Modelos mentais documentando padrões digitais do operador
- [ ] Curva longitudinal de Higiene de Input ao longo de anos

---

## 9. Riscos e Mitigações

### 9.1 Risco — Vigilância indesejada

**Cenário:** Atlas acaba virando ferramenta de vigilância sobre o próprio operador, gerando ansiedade constante sobre uso digital.

**Mitigação:** Confronto sempre informacional (Lei 7). Modo Silêncio (Cap. 13) desativa Sensor 4 inclusive. Operador pode pausar coleta a qualquer momento. Dashboards mostrados sob demanda, não empurrados.

### 9.2 Risco — Categorização imposta

**Cenário:** Atlas começa a impor visão de produtividade externa (Cal Newport, Andrew Huberman, qualquer guru) sobre vida do operador.

**Mitigação:** Cláusula contra-genérica em §4.4. Categorização é de Vitor. Atlas reconhece que Crimson Desert por 2h é vida boa, não erosão.

### 9.3 Risco — Privacidade comprometida

**Cenário:** Captura de browser history ou conteúdo de mensagens vaza dados de terceiros.

**Mitigação:** Cláusula §3.2. Metadados de comportamento, nunca conteúdo de terceiros. Browser history opcional, manual, não automático. WhatsApp é tempo total, não transcrição.

### 9.4 Risco — Otimização contra humanidade

**Cenário:** Atlas começa a recomendar redução de tempo com Carol porque WhatsApp aparece em "comunicação primária" com tempo alto. Lei 5 violada.

**Mitigação:** Comunicação primária (classe 7) é categoria positiva, não candidata a redução. Confronto sobre comunicação primária é proibido salvo se Vitor explicitamente declarar problema.

### 9.5 Risco — Ansiedade de pickups

**Cenário:** Saber que pickups são contados gera ansiedade, comportamento defensivo, captura performativa de "não estar usando o celular".

**Mitigação:** Métricas mostradas em frequência baixa (Weekly Review e Snapshot trimestral, não tempo real). Sem dashboard sempre-visível de pickups. Operador define se quer ver.

### 9.6 Risco — Dependência da categorização para julgamento

**Cenário:** Vitor para de exercer julgamento próprio sobre input e delega ao sistema. Lei 9 violada.

**Mitigação:** Sistema mostra padrão; operador interpreta. Confronto informacional jamais diz *"este input é ruim"* — diz *"este padrão correlaciona com X no seu próprio histórico, considere"*. Julgamento moral permanece com humano.

---

## 10. Decisão para Annual Review

### 10.1 O que council de janeiro 2027 deve decidir

**Decisão constitucional:**

- Sensor 4 entra como quarto sensor formal, alterando "Sistema Tri-Sensorial" para "Sistema Quadri-Sensorial"?
- Lei 10 ganha cláusula de Input Digital formalmente?
- Sub-dimensão A6 (Higiene de Input) entra em métricas cognitivas?

**Decisão operacional (já tomada):**

Implementação V1.5+ procede independente da decisão constitucional. Dataset coletado vale por si.

### 10.2 Inputs para o council de janeiro

Se este adendo for ratificado constitucionalmente, council deve receber:

- 9 meses de dados reais de Sensor 4 coletados
- Validação empírica das hipóteses operacionais (§5)
- Métricas de eficácia das categorias (categorização precisou ser revisada quantas vezes?)
- Auto-relato de Vitor sobre se Sensor 4 melhorou ou piorou relação dele com tela
- Análise de overrides em confrontos derivados de Sensor 4

Se dados mostrarem que Sensor 4 funciona, ratificação. Se mostrarem que Sensor 4 gerou mais ansiedade que insight, descontinuação ou refundação.

### 10.3 Critério de sucesso para ratificação

Para que Sensor 4 entre constitucionalmente em v7:

1. **Pelo menos 3 padrões reais detectados** que Vitor valida como verdadeiros e úteis.
2. **Pelo menos 1 mudança comportamental real** atribuível a confronto baseado em Sensor 4.
3. **Métricas de Higiene de Input estáveis ou crescentes** ao longo dos 9 meses.
4. **Auto-relato positivo** de Vitor: Sensor 4 ajudou a entender padrões que ele não veria sozinho?
5. **Sem violação de Cap. 21** (vida fora do sistema): Carol, jogos, perfumes, jantares preservados sem otimização indevida.

Se 4 dos 5 critérios atendidos, ratificação. Caso contrário, descontinuação ou nova iteração.

---

## 11. Encerramento

Este adendo registra um pensamento que emergiu após a v6 — um gap real identificado por Vitor durante leitura crítica do documento. O reconhecimento de que Atlas detecta atrofia cognitiva sem ver a causa principal dela (consumo digital) é insight constitucional, não detalhe operacional.

A v6 permanece intacta. Esta proposta entra em backlog técnico desde já, e em pauta de council formal para janeiro de 2027. A constituição evolui com evidência, não com narrativa. Os 9 meses entre agora e janeiro são exatamente o período de evidência que a council precisa.

Princípio raiz: Atlas é projeto de décadas. Constituição estável é precondição de autoridade. Mas constituição que ignora gap óbvio é constituição preguiçosa. Adendo é o caminho do meio: registra, prepara, testa, decide com dados.

---

**Versão:** Adendo 1.0 sobre v6.0
**Data:** 28 de abril de 2026
**Operador:** Vitor Emanuel
**Decisão constitucional prevista:** Annual Review janeiro de 2027
**Implementação operacional:** V1.5 do Atlas

> *Atlas vê o que você captura. Atlas precisa ver também o que entra na sua cabeça antes da captura. Sem isso, Atlas é cego para metade da Lei 10.*
