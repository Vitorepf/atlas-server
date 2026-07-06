# Obra #16 (visão) — ACOS Cognição Ativa: o cérebro que pensa entre as sessões

Data: 2026-07-06 · Camada ACIMA da Obra #15 · Status: aprovável

## O salto conceitual

A #15 dá ao ACOS **memória de engenharia** (lembrar, recuperar, avisar). Esta obra dá **pensamento**: o ACOS deixa de ser um sistema que responde quando consultado e vira um participante cognitivo que **trabalha entre as sessões** — digere o dia, simula o amanhã, antecipa o que você vai precisar e critica o que vai ser construído ANTES de custar retrabalho. A diferença entre um arquivo bem indexado e um sócio de engenharia.

**Métrica-norte: antecipação útil.** Fração das necessidades reais de uma sessão que o ACOS preparou ANTES de ela começar (briefing aceito, impacto pré-computado usado, risco previsto que se confirmou). Hoje: 0% — tudo é reativo. Meta: ≥60%. Secundárias: time-to-correct-delivery (queda medida por classe de tarefa), retrabalho evitado (colisões pegas na spec vs. na implementação).

## P1 — Cognição noturna (o dia é digerido, a manhã vem pronta)
A cadência de hoje re-prova receipts; evolui para **trabalho de compreensão**: após cada dia de commits, o ACOS re-sintetiza os briefs afetados (F1 da #15), detecta drift entre intenção (specs/decisões) e realidade (código), e emite o **briefing da manhã**: o que mudou embaixo de você, que suposições quebraram, o que está pronto para decidir, o que os workers fizeram na main.
**Gate:** briefing diário gerado sem humano; ≥70% dos itens julgados úteis pelo operador em 2 semanas de uso (feedback de 1 toque).

## P2 — Simulação antecipatória (TEOS aplicado a engenharia real)
Antes de refatoração/implementação complexa, o ACOS pré-computa o **raio de explosão**: call graph afetado, testes que cobrem, decisões/invariantes tocados, e — usando o corpus de refutações e o histórico de falhas — **onde vai quebrar** ("mudar X põe em risco os invariantes A/B; a última tentativa parecida foi refutada por Y"). Os runtimes contrafactuais (TEOS-I3/I4) existem como subsistemas certificados; esta fase os liga ao trabalho de engenharia de verdade.
**Gate:** para 10 slices reais de obra: previsão de impacto cobre ≥85% dos arquivos realmente tocados; ≥1 quebra prevista confirmada que teria custado sessão inteira.

## P3 — Crítica adversarial de spec como órgão (a fase que garante qualidade, automatizada)
A definição canônica do Loop já diz: projeção frontier + crítica cross-model em loop é O que garante qualidade. Institucionalizar para obras humanas também: toda spec nova passa por crítica adversarial de um segundo modelo (política de providers vigente) municiado com o brief do projeto + refutation memories — ANTES da implementação. O crítico procura: colisão com decisão, item já refutado, estimativa inflada (finders superestimam 3-10× — regra já codificada), slice sem gate mensurável.
**Gate:** ≥1 defeito real de spec pego pela crítica por obra (na #8 foram 10 refutações descobertas só NA EXECUÇÃO — o alvo é pegá-las antes).

## P4 — Compreensão causal (por que quebra, não só o que chama o quê)
Minerar evidence ledger + corpus de reparos + histórico de falhas em **cadeias causais** (classe de mudança → assinatura de falha → correção que funcionou). O PredictiveFailureFlow existe; elevar de flow para órgão: cada edição planejada recebe score de risco APRENDIDO de outcomes reais, não heurística.
**Gate:** score de risco calibrado — nas edições de maior risco previsto, taxa de falha real ≥3× a das de menor risco (prova de que o score discrimina).

## P5 — Memória episódica hierárquica (meses sem perder profundidade)
Sessões → episódios → destilados semanais → sabedoria do projeto. A colheita de hoje é plana; horizonte de meses exige compressão hierárquica: o brief fica pequeno (cabe em qualquer contexto), e a profundidade continua alcançável por drill-down sob demanda. É o que faz "trabalho de 3 meses" não degradar no mês 2.
**Gate:** pergunta sobre decisão de 4+ semanas atrás respondida com razão correta + drill-down até a evidência original em ≤2 saltos.

## P6 — Contrato de simbiose provider↔ACOS (a surpresa vira sinal)
Formalizar a via de mão dupla: toda sessão de provider consome o brief E devolve delta estruturado — o que aprendeu, **o que o surpreendeu**, o que o pack não tinha. O canal de surpresa é o sinal de aprendizado mais rico que hoje se perde (a sessão descobre algo que o cérebro não sabia e isso morre no transcript). CPR vira bidirecional: pack→trabalho e trabalho→cérebro.
**Gate:** ≥80% das sessões devolvendo delta; itens de surpresa incorporados ao brief em ≤24h (cadência noturna).

## P7 — Metacognição medida (o contexto que aprende a se compor)
O ACOS experimenta consigo mesmo: composição de pack A/B por classe de tarefa (mais graph vs. mais memória vs. mais brief), mede CPR/outcome delta, adota o vencedor — o padrão do harness autopilot (1 mudança/dia, receitada, auto-revertida por outcome) aplicado ao motor de contexto. O órgão que entrega contexto passa a melhorar o próprio ato de entregar contexto.
**Gate:** ≥1 melhoria de composição adotada por evidência e sustentada por 2 semanas sem reversão.

## Relação com as obras anteriores
- **#14** = o organismo funciona (motor, higiene, receipts). Pré-requisito de tudo.
- **#15** = memória (brief, estado de obra, recall, aviso, multi-projeto). P1/P2/P5 constroem sobre F1/F2; P3 usa F1+refutações; P6/P7 estendem o loop de feedback.
- **#16** = pensamento (digestão, simulação, crítica, causalidade, simbiose, metacognição).

Ordem executável: #15 F3+F2 → #16 P1 (briefing já com F2) → #15 F1 → #16 P3+P2 → resto. P1 e P3 são os de maior valor/custo — começam primeiro dentro da #16.

## Filtro de 5 (auto-aplicado)
1. Multiplicador composto? Sim — cada provider novo herda pensamento acumulado, não só memória. 2. Antifrágil? Sim — quanto mais caos (workers, obras paralelas, drift), mais o briefing/drift-detection vale. 3. Fim-a-fim em linguagem natural? Sim — briefing da manhã É linguagem natural. 4. Destrava substituir função humana? Sim — o "tech lead que lembra tudo e antecipa" é função humana cara. 5. Local-first? Sim — tudo roda na cadência local; crítica cross-model usa a política de providers vigente.

## Regras herdadas (pétreas)
Byte-prova; gates mensuráveis antes de declarar entregue; G0 nunca auto-promove; crítica adversarial nunca vira hard-block sem histórico de acerto; vocabulário proibido; push só com OK.
