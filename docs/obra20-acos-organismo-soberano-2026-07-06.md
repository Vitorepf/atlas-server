# Obra #20 — ACOS Organismo Soberano: a metamorfose

Data: 2026-07-06 · Método: conselho de 13 especialistas em paralelo (workflow determinístico, 895k tokens de investigação, substrato verificado por rg/Read em cada claim) + síntese com o Cético como constituição · Status: aprovável
Pré-requisito: linha #15-#19 implementada. Papel: **a obra que muda a natureza do ACOS — de infraestrutura cognitiva que responde para organismo cognitivo que vive.**

## A tese da metamorfose (o que os 13 viram juntos e nenhum sozinho)

Pós-#19, o ACOS é uma **coleção de órgãos excelentes ligados por demanda**: responde quando perguntado, prova quando mandado, lembra quando consultado. Os 13 conselheiros, olhando de ângulos independentes, encontraram o MESMO padrão: os órgãos do próximo patamar **já existem no repo e estão desligados uns dos outros** — working memory sem persistência e sem consumidor, swarm conductor com 1 caller, corredor de auto-construção castrado no último metro (`directly_enqueueable=false`), 9 manifests de domínio com 1 adapter vivo, journal de evidência que não cobre a memória, invariantes prováveis que não são teia, contrafactuais sem consumidor de engenharia, pipeline pessoal (captures) numa espinha paralela à memória canônica. **A Obra #20 não constrói uma catedral: fecha os fios que transformam peças em organismo.** 10× vem de mudanças de NATUREZA (de mapa para modelo, de reativo para curioso, de mortal para derivável, de 1 mão para fábrica, de repo para vida), cada uma medida por evento externo.

## CONSTITUIÇÃO DA OBRA (lente do Cético — governa todas as frentes; nada entra sem isto)

1. **Scorecard 10× pré-registrado**: documento congelado por hash em Decision Receipt ANTES da primeira linha de código, com baselines pós-#19 medidos na semana anterior. Dimensões mensuráveis: contexto citado-e-usado; retrabalho por contexto ignorado (÷10); captura→consumo em 30 dias; turns-até-ação-correta em sessão fria; % de candidatos G0 prontos-para-assinar; antecipação útil; custo/slice da fábrica; RTO do cérebro. **Dimensões onde 10× é IMPOSSÍVEL, declaradas para ninguém vendê-las**: julgamento tácito do operador; dados que exigem tempo-de-calendário; capacidade bruta do provider (é o N, não o M); segurança (piso binário, não escala); volume de gravação (10× memórias = envenenamento).
2. **Escada de eventos externos**: frente N+1 só ATIVA em produção quando a frente N acumulou ~5 eventos externos no ledger (pack que mudou diff mergeado, contradição que impediu erro real, memória citada por sessão que não a gerou). Desenho/spec/teste pré-escrito podem ser paralelos; ativação é sequencial. **Nenhuma frente declara sucesso sobre si mesma.**
3. **Quarentena de síntese com rollback de safra**: TUDO que a obra sintetiza nasce `synthetic+quarantined`, fora do pack default, com proveniência e deleção em bloco por geração; gradua por citação externa; TTL 45 dias ⇒ expira e conta no scorecard. Taxa de graduação <10% por 2 semanas ⇒ a frente PAUSA.
4. **Orçamento de manutenção**: cada frente declara minutos/semana do operador em regime; soma da obra >30min/semana ⇒ algo é DELETADO antes de algo novo entrar.
5. Pétreas intactas e reforçadas: G0-G8 nunca auto-promovem; tier 0 até assinatura; autor≠juiz em todo julgamento; merge no cérebro é SEMPRE humano; local-first absoluto; vocabulário proibido.

## OS 8 SISTEMAS DO ORGANISMO

### SISTEMA 1 — Presente Contínuo (uma mente, N avatares)
Fecha a tese "avatares momentâneos de uma memória" no tempo real, não no post-mortem:
- **Working Memory Una**: `AtlasCognitiveWorkingSetMemoryService` (existe: heat score, modos, delta receipts — array in-process sem consumidor) vira estado persistido escopo operador+projeto, lido/escrito pelos hooks que já existem. Fato dito no mobile está na sessão desktop em ≤10s. Gate: re-explicação do operador −80%.
- **Intenções em voo**: blackboard (existe, advisory) eleva claims a intenções estruturadas (objetivo, estado, próximo passo); handoff avatar A→B com ≤1 turn de orientação; SSE existente entrega ao desktop.
- **Postura de projeto**: vetor derivado de EVIDÊNCIA (gates vermelhos, incidentes) seleciona o modo de TODOS os avatares — uma queimadura ensina todas as mãos no mesmo minuto. Zero opinião de modelo; 100% receipt.

### SISTEMA 2 — Pensamento Soberano (o cérebro pensa a noite inteira, sem provider pago)
Mata o assassino silencioso de todas as obras anteriores:
- **ALIS (Atlas Local Inference Substrate)**: servidor de inferência local (mlx-lm/llama.cpp, modelo ~30B 4-bit — cabe nos 48GB medidos) como LaunchAgent + provider de 1ª classe no registry. Gate: probe de síntese passa COM REDE BLOQUEADA.
- **Turno noturno soberano**: os órgãos noturnos JÁ agendados (semantic activate morning_briefing, weekly digest, nightly counterfactuals — bootstrap/app.php:398-490) ganham `execution_policy=sovereign_first`. Briefing da manhã vira síntese real com custo provider = R$0 auditado pelo cost sentinel. Sempre ADITIVO ao brief determinístico (que permanece piso).
- **Juiz assimétrico**: gerar é caro, verificar é barato — toda síntese sovereign entra G0 com checks determinísticos (toda citação resolve para id/arquivo real; motor de contradição; dedup) + spot-audit amostrado por frontier na próxima sessão paga (autor≠juiz). Discordância acima do limiar ⇒ lane auto-rebaixa para draft-only. **É a licença de operação do sistema inteiro.**

### SISTEMA 3 — Modelo Causal do Mundo (de mapa que descreve para modelo que prevê e se corrige)
- **Blast probability**: edges do world model (bi-temporais, existem) ganham P(quebra em B | mudança em A) aprendido do corpus de falhas + pares diff→testes-quebrados + receipts do test:impacted — prior estrutural, atualização bayesiana. Gate: recall@10 ≥1,5× o baseline de alcançabilidade em replay de landings reais; Brier score publicado.
- **Invariantes como teia de leis**: os `@invariant` que o formal-proof já parseia viram NÓS do grafo (`protects`/`threatened_by`); falhas do corpus viram invariantes mineradas (observe-mode até promoção humana). O corpus de dor vira constituição. Gate: ≥60% das falhas históricas teriam sido sinalizadas.
- **`atlas:worldmodel:simulate`**: funde os 3 simuladores parciais existentes (TEOS-I3/I4 + SoftwareTwinSimulationPredictor + NightlyCounterfactuals) num what-if único que sai como TEXTO para Dev/esteira/spec-critic. Shadow-mode 2 semanas; latência p95 ≤5s; NUNCA bloqueante no dia 1.
- **Fechamento**: divergência previsão↔realidade re-pesa edges na consolidação noturna. Brier estratificado por classe de falha (anti-Goodhart), decrescente mês a mês.
- Absorção fronteira (opcional, observe-mode): verificação por traço padrão CWM em funções quentes, 100% local, fail-open.

### SISTEMA 4 — Grafo Bi-temporal Unificado (o conhecimento vira consultável no tempo; o pack vira subgrafo)
- **Arestas 4D**: `atlas_aurg_edges` ganha as colunas de temporal truth (padrão `HasTemporalTruth` JÁ aplicado a 2 outras tabelas — extensão byte-a-byte); `stateAt(T)` vira SQL; tick log hash-chained fica como auditoria. Gate: reconciliação SQL≡replay em N datas; as-of p95 <200ms.
- **Porquê como aresta**: relations/supersedes/receipts/outcomes viram arestas `refutes`/`proves`/`supersedes`/`derived_from`. "O que refuta X" vira traversal de 1 hop — **mata re-proposta NA ORIGEM**. Gate: suite dourada de refutados com 0 re-proposições.
- **Strangler prosa→grafo nos packs**: o grafo decide O QUE entra; a fonte fornece O TEXTO na hidratação; shadow-diff por seção até convergir. Zero big-bang. Gate: ≥80% das seções servidas pelo grafo com diff aprovado.
- **Compilador pergunta→plano-de-traversal**: plano declarativo JSON whitelisted (templates ≥70%, modelo local só desambigua), replayable como receipt. Destrava a pergunta que define superfície cognitiva única: *"o que mudou na crença sobre Y entre D1 e D2, e qual evidência causou"*. Gate: ~30 perguntas douradas ≥90% corretas (baseline ≈0 respondíveis).

### SISTEMA 5 — Curiosidade + Auto-construção (o organismo que se pergunta e se regenera)
- **Ledger de Ignorância**: lacunas tipadas (módulo sem invariante, decisão sem porquê, zona quente sem teste, contradição aberta, retrieval-miss recorrente) com valor = calor da zona × custo-de-errar; derivado, recomputado à noite, nunca autoral. **Nasce com consumidor ou não nasce.**
- **Ciclo Pergunta→Experimento→G0**: passe ocioso puxa top-K lacunas, formula hipótese falsificável, responde pelo experimento MAIS BARATO (rg grátis → replay contrafactual → test:impacted em sandbox → teste novo SÓ no sandbox do Loop), grava candidato G0 com evidência. Orçamento hard por noite; zero token de provider por padrão. **Métrica única: taxa de pré-resposta** — % de memórias da curiosidade recuperadas E úteis em sessão real ≤14 dias (alvo ≥25%; <10% por 2 semanas ⇒ auto-reduz orçamento).
- **Curiosidade dirigida ao operador**: lacunas humanamente-exclusivas viram pergunta de 1 linha entregue quando você toca a zona. Cap pétreo NO CÓDIGO: ≤3/semana, ≤1/dia, 2 ignoradas = 30 dias de silêncio. Gate: ≥60% respondidas.
- **Corredor de auto-construção FECHADO**: o flip do `directly_enqueueable` — proposta aprovada vira ordem Kit K real (teste pré-escrito por caminho separado, callers congelados), delegada ao músculo barato, provada pelos gates #19, staging→promotion→**assinatura humana no merge (o único ponto do sistema que NUNCA se otimiza)**. Sensor de deficiência generalizado (contradição recorrente, surpresa sistemática, outcome negativo = candidate-gap com evidência). **Metabolismo**: órgão sem consumo em N dias gera proposta de APOSENTADORIA com tripla prova — auto-construção sem auto-retirada diverge. Gates: ≥3 órgãos-fix reais com 1 assinatura cada; net-LOC do SelfConstruction não cresce; razão uso-provado/total ≥80%.

### SISTEMA 6 — Fábrica de Frotas (1 obra = N mãos sob 1 cérebro; o operador decide ~5 coisas/dia)
- **Obra Compiler (linha de montagem fechada)**: decomposição (Loop) → ordens Kit K → SwarmConductor monta arms K=1..5 → dispatch paralelo em sandboxes clonefile → crítico certificado julga → merge na porta lockada. Cada peça EXISTE com 1 caller; a obra fecha o fio. Gate: obra-sombra de ~20 slices com ≥80% landed sem toque do operador, floor intacto, zero clobber.
- **Economia de arms**: crédito por (provider, modelo, classe) vem SÓ de outcome pós-merge (teste congelado passa + zero revert em 72h) — nunca da nota do crítico (anti-Goodhart). Conductor escolhe K por evidência: spec cristalina ⇒ K=1; ambígua ⇒ K=3. Gate: custo/slice ↓≥40% com taxa de land igual+.
- **Fila de decisões irredutíveis**: a fábrica roda até a fronteira do trust ladder (autonomia CONQUISTADA, nunca concedida) e empacota para você só o irredutível — decisão binária de 30s com evidência + default + consequência. Slice bloqueada não bloqueia a obra (lanes independentes). Gate: ≥90% das slices atravessam sem item na fila; mediana de decisão <2min.
- Regra de coordenação (fronteira externa): LLM NUNCA no hot path de coordenação (o incidente kernel-offline provou); fan-out só em fios independentes; coordenação é determinística (locks, blackboard, allowedFiles).

### SISTEMA 7 — Simbiose Pessoal + Multi-domínio (a memória vira SUA, e a máquina vira de qualquer profissão)
- **Uma espinha só**: o pipeline pessoal (Capture→Whisper local→clarify→curation) deixa de ser paralelo — toda captura clarificada vira candidato G0 e passa pelos MESMOS órgãos (surpresa, consolidação, contradição, bi-temporal, relações). A ponte embrionária existe (`capture_to_open_brain` na review queue). Escopo `personal` separado: ruído pessoal NUNCA entra em pack de engenharia sem match forte. Gate: "o que decidi sobre X semana passada" no top-3 do recall; suite adversarial provando ZERO sensitive/secret em pack provider-bound (vira CI, não docblock).
- **Captura sempre-quente**: pensamento→memória governada em 1 toque/frase (push-to-talk mobile, hotkey desktop, Vox); clarificação com budget de 1 pergunta; errar barato e corrigir pelo bi-temporal. Gate: p50 <60s; hábito = capturas/dia sustentadas 14 dias (a medida real de simbiose).
- **Recall prospectivo**: o ActivationEngine (existe, teto 2/dia) generaliza — a memória certa chega quando o contexto casa, antes de ser pedida. Teto sobe SÓ por acted-rate provado (≥60%); dismissed 2× = silenciada naquele contexto. É a diferença entre arquivo e sócio.
- **Cognition Kernel multi-domínio**: extrair o córtex (episódio, outcome, refutação, procedimento, brief, crítica) como kernel domain-agnostic com adapter de 3 perguntas; `scope_type='domain'` na memória. Engenharia continua âncora e teste de regressão permanente (golden-diff dos packs = idêntico). **Trading como test bed**: o pipeline de settlement (ShadowSettlement/TradingHonestyGate — existem, com ZERO refs a memória hoje) fecha outcome REAL não-gameável na memória — "não re-entrar em X após Y" na mesma máquina do "não re-propor Number::clamp". Shadow-only, walk-forward, execução real proibida (pétreo). Gate: a MESMA suite de cognição passa em ≥3 domínios trocando só o adapter; hit-rate do shadow engine COM memória > SEM, em walk-forward.
- **Maturity ladder cognitiva**: domínio só sobe de estágio provando ciclo fechado (episódios→refutações reusadas→brief que mudou decisão). Shell sem cognição não sobe nunca mais.

### SISTEMA 8 — Sobrevivência (o cérebro que POR CONSTRUÇÃO não pode morrer)
O wiper aconteceu COM backup rodando — ele não cobria o que importava. Nenhuma obra anterior protege o que as outras constroem; esta protege TODAS:
- **Journal-first**: toda mutação de memória/KB escreve PRIMEIRO num journal append-only hash-chained (padrão do Evidence Ledger, que hoje NÃO cobre a memória — verificado); `atlas:brain:replay` reconstrói o cérebro do zero com diff de content_hash = 100%. Postgres vira gado. Gate: kill-test em sandbox — DROP DATABASE → replay <5min → idêntico.
- **Restore Drill semanal**: pg_dump criptografado + journal + storage cognitivo → 2 destinos (disco externo + git mirror); drill restaura em Postgres efêmero, roda invariantes, grava receipt "restaurável=true". Backup sem drill verde = alerta vermelho no briefing. Gate: RTO ≤15min; 4 drills verdes consecutivos; matar o agent dispara alarme <24h.
- **Sovereign Pack**: `atlas:sovereign:pack/hydrate` — máquina nova em <1h com drill verde, zero rede, zero provider para EXISTIR. Sensitive/secret em pack separado, só disco físico. Gate: cronometrado, 1×/mês.
- **Regra da obra inteira**: estado novo só nasce se o drill da semana seguinte já o restaura — conhecimento não nasce mortal nunca mais.

## ECONOMIA DO ORGANISMO (o M vira curva que pode CAIR)

- **Razão de Transações Cognitivas**: todo recordExecution posta dupla entrada (DÉBITO = conhecimento consumido + custo medido; CRÉDITO = resultado provado + conhecimento criado). M = d(resultado provado/custo)/dt a N fixo — o metric atual (log de atividade append-only, só sobe) é substituído por instrumento falsificável. Gate: fixture onde outcomes ruins DERRUBAM a curva — se M não cai no teste, o instrumento reprova.
- **Balanço com depreciação**: rendimento por memória (o AiRagFeedbackEvent já carrega o id); ativo com lift ≤0 sustentado ou contradito entra em cronograma de write-off PROPOSTO via review queue (nunca auto-deletado). Classe pétrea (refutações) isenta de depreciação por frequência — só cai por contradição comprovada.
- **Graduação**: procedimento com ≥5 execuções verdes + teste vira automação determinística local (proposta + assinatura + `reverse` funcional) — conhecimento que espera recall paga token por uso; conhecimento graduado rende em toda execução a custo ~0. É o deslocamento de custo provider→CPU que aparece direto na curva.

## ABSORÇÕES DA FRONTEIRA (padrões com evidência, nunca produtos)

Absorvidas DENTRO dos sistemas: grafo bi-temporal com arestas de invalidação (padrão Zep/Graphiti → Sistema 4); playbook evolutivo por delta-items sem rewrite integral (padrão ACE → packs do Sistema 4, com anti-colapso como gate); banco de casos de receipts com retrieval ponderado por outcome (padrão Memento → Sistema 6); verificação por traço (padrão CWM → Sistema 3, opcional); sleep-time compute (→ Sistema 2). Descartados: qualquer fine-tuning/re-treino local; qualquer framework externo de memória como dependência; maximalismo multi-agente (15× tokens sem decomposição limpa).

## OS VETOS CONSOLIDADOS (o que os 13 proibiram uns aos outros — lei da obra)

Nenhum kernel/daemon/barramento/graph-DB/vector-DB novo (Postgres+hooks+SSE+flocks cobrem tudo); nenhum órgão sem consumidor nomeado no mesmo commit; nenhum LLM no hot path de coordenação; nenhuma auto-promoção G0-G8 sob QUALQUER disfarce ("a frota aprendeu", "é local, é seguro", "reduz fricção"); nenhum auto-merge do próprio cérebro; nenhuma métrica de volume como gate (memórias/agentes/hipóteses/LOC); nenhum rewrite de substrato vivo; nenhuma superfície de captura nova; nenhum "digital twin que responde como o Vitor"; nenhum estado novo fora do censo do drill; nenhuma promessa de 10× nas dimensões declaradas impossíveis; nenhum adapter de domínio especulativo (3 domínios provados > 15 fachadas); MiniMax self-host não é o primeiro movimento do Sistema 2 (mlx-lm 30B entrega em dias o que a fantasia de datacenter atrasa 6 meses).

## SEQUÊNCIA (a escada, não o big-bang)

```
FASE 0 — Constituição (antes de tudo): scorecard 10× com hash em receipt · quarentena de síntese · razão de transações (o juiz de todas as frentes) · journal-first + drill (Sistema 8 — protege a obra da própria obra)
ONDA 1: Sistema 1 (presente contínuo) ∥ Sistema 2 ALIS+juiz ∥ Sistema 4 arestas 4D
ONDA 2 (por eventos externos da 1): Sistema 3 (causal) ∥ Sistema 4 strangler+compilador ∥ Sistema 5 curiosidade
ONDA 3: Sistema 6 (fábrica) ∥ Sistema 5 corredor fechado ∥ Sistema 7 espinha pessoal
ONDA 4: Sistema 7 multi-domínio (trading test bed) ∥ graduação/depreciação em regime
Cada onda ativa SÓ com os ~5 eventos externos da anterior no ledger. Todo slice via ordem de trabalho do Kit.
```

## O QUE A #20 ENTREGA, EM UMA FRASE POR SISTEMA

Uma mente só em todos os avatares · que pensa a noite inteira sem pedir licença a provider · que prevê consequência antes de executar · que responde "o que eu acreditava, quando, e o que me fez mudar" · que se pergunta, se constrói e se aposenta sob assinatura · que produz com N mãos e te consulta 5 vezes por dia · que lembra da sua vida com o mesmo rigor com que lembra do código, em qualquer profissão · e que, por construção, não pode morrer.

Isso é o outro patamar. E cada linha dele tem baseline, gate, evento externo e veto — porque a única grandiosidade que esta casa aceita é a que sobrevive à própria auditoria.
