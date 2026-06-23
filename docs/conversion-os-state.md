# Conversion Pattern OS — estado do loop

Loop: Claude Code destila os padrões de conversão mais brutais do mundo e cristaliza no motor de marketing do Atlas como bibliotecas determinísticas, mensuráveis (PatternLibraryScorer único) e auditáveis. Métrica: FORÇA DE CONVERSÃO real, provada cross-nicho (não overfit a um nicho). Campo de prova: OT169 (saúde) + finanças + relacionamento.

Régua atual (piso de maturidade): **85** / teto 100. — **VOLTA 2 COMPLETA** (todas as 10 bibliotecas ≥85, ciclos 9-22). Régua sobe pra 90.

## Volta 2 — entregue (14 ciclos, 9-22)
| Ciclo | Entrega |
|---|---|
| 9 | ConversionAuditor + AggressionAmplifier (loop fechado) |
| 10 | Composer consolidado no auditor unificado |
| 11 | CLI `atlas:ai:marketing:audit` (raio-X humano) |
| 12 | CLI `atlas:ai:marketing:amplify` (loop fechado humano) |
| 13 | Persuasion +10 SOTA mestres (21→31) |
| 14 | AngleBigIdea +10 swipe files de elite (12→22) |
| 15 | OfferArchitecture +10 alavancas raras Hormozi (16→26) |
| 16 | HookLead +10 (Bencivenga/Halbert/Carlton) (14→24) |
| 17 | Objection +10 neutralizadores avançados (12→22) |
| 18 | CognitiveBias +10 vieses raros (13→23) |
| 19 | NarrativeVoice +10 voice signatures mestres (14→24) |
| 20 | Awareness +10 engenharia de transição (10→20) |
| 21 | FunnelSequence +10 jornada multistep (16→26) |
| 22 | VisualPersuasion +10 design persuasivo raro (15→25) |

**Conversion OS no fim da Volta 2:** ~243 padrões (vs 146 fim Volta 1) × 10 libs × scorer único × ConversionAuditor × AggressionAmplifier × 2 CLIs. Todas libs maduras ≥85.

## Volta 3 (piso 90) — em curso
- **ConversionOrchestrator** ✅ — RESOLVIDO ciclo 23: pipeline end-to-end provider-free (asset → seed → amplifier → renderer). PROVA: asset OT169 vira HTML 7388 bytes com headline + CTA + 6 padrões injetados, score 6→15 (+9 num passe). CLI `atlas:ai:marketing:orchestrate` expõe pro operador.
- **LearnedWeightLedger** ✅ — RESOLVIDO ciclo 28: backbone do flywheel de pesos aprendidos. Tabela `ai_marketing_pattern_outcomes` (snapshot do audit + outcome real). `record()` congela o fingerprint, `computeWeights()` calcula CVR-lift Bayesian-smoothed por padrão dentro de um nicho (prior=10 pseudo-rows pra evitar overfit de baixa amostra). PROVA: 30 páginas com `killer_mechanism` em 8% CVR vs 30 sem em 2% → `killer_mechanism` aprende peso > `common_authority` (ubíquo). DORMANT até venda real chegar; a math está pronta.
- **CLI atlas:ai:marketing:record-outcome** ✅ — RESOLVIDO ciclo 29: interface humana do ledger. Operador roda campanha real, mede CVR, dispara `record-outcome page.html --niche=X --cvr=0.043 --clicks=2400 …`. A CLI audita a página + congela fingerprint + persiste. Sem ela, o ledger fica sem como receber dado.
- **HybridPatternScorer** ✅ — RESOLVIDO ciclo 30: a ponte que FECHA o flywheel. Mesmo contrato do scorer puro, mas mistura pesos craft (meus) + pesos learned (do ledger por nicho). Curva de blend sigmoidal: 0 outcomes = 100% craft; 30 = 50/50; 200+ = ~90% learned. Quando o ledger recebe dado, o scorer já passa a usar — sem rewriting. 0 outcomes = comportamento idêntico ao scorer puro.
- **MarketSpyHarvester** ✅ — RESOLVIDO ciclo 31: escala o Scout. Recebe N páginas vencedoras → trend patterns (que repetem em K+ páginas), trend candidates (construções recorrentes a aprender), aggregated library density, avg score+hollowness. Auto-threshold = ceil(N/2). PROVA: 3 páginas de saúde → identifica 8 padrões em 100% (common_enemy/authority/risk_reversal_strong/visual_hierarchy/…). Aprende com a SAFRA atual do mercado, não com 1 página.
- **CLI atlas:ai:marketing:spy** ✅ — RESOLVIDO ciclo 32: interface humana do Harvester. Recebe diretório/glob de HTMLs + threshold opcional, mostra avg score+hollowness, density agregada por library (barras visuais), trend patterns ranqueados, candidatos a aprender.
- **CLI atlas:ai:marketing:weights** ✅ — RESOLVIDO ciclo 33: janela do operador no flywheel. Mostra os pesos LEARNED por nicho/page_kind, ou mensagem amigável "sem dados ainda" enquanto não tem ≥ 30 outcomes. Quando tem, top-N padrões com lift ranqueados (barras visuais).
- **HybridScorer ligado no Auditor por default + niche propagado pelo Amplifier+Orchestrator+CLI** ✅ — RESOLVIDO ciclo 34: fechamento estrutural. ConversionAuditor agora wireia HybridPatternScorer por default no construtor; `audit()` aceita `niche` + `page_kind`; quando passado, blendeia craft+learned automaticamente. Amplifier propaga niche/page_kind; ConversionOrchestrator + CLI `orchestrate --niche=X` passam o tronco inteiro. Resultado: no dia que o ledger receber dado real, o motor passa a usar SEM precisar tocar em nada — basta passar `--niche` em qualquer CLI.
- **CLI atlas:ai:marketing:import-outcomes** ✅ — RESOLVIDO ciclo 35: import em lote de outcomes (CSV/JSON exportado do dashboard). Cobre o gap manual no caminho do flywheel sem precisar de webhook S2S (Atlas é local-first, não tem HTTP exposto). Quando o operador tiver tunneling + secret, webhook faz sentido; por enquanto este caminho cobre 100%.
- **MultivariateBattlePlan** ✅ — RESOLVIDO ciclo 36: motor de descoberta. Dado um asset + arrays de axes (angles × hooks × awarenesses), gera o produto cartesiano de variantes — cada uma orquestrada com axes PINNED (kicker via angle, lead via hook, awareness via asset clone). Cada variante carrega seu HTML + audit + hollowness + flag. `recommended` = melhor score não-flagged. Permite split-test por AXIS (não por random page). Diferenciação real provada (3 kickers/2 leads distintos em 8 variantes).
- **AntiGoodhartGuard** ✅ — RESOLVIDO ciclo 24: o inverso do scorer. Detecta copy que GAMES os markers (buzzword soup, claims sem prova, placeholders, AI tells, keyword stuffing, intensificadores vagos, CTA nu, sobrecarga de adjetivos). 0-100 hollowness + flags específicas. PROVA: copy hollow=100 (fraudulent, 5 flags) / copy substantiva=0 (substantive). Sem isto, o amplifier otimizaria pra contagem de markers em vez de conversão — o guardião do anti-Goodhart.
- **AntiGoodhartGuard integrado no Amplifier** ✅ — RESOLVIDO ciclo 25: cada injeção do amplifier passa pelo guard ANTES de ser aceita. Se aumenta hollowness em >15 pontos → REJEITADA. Output: 'hollowness' (final) + 'rejected' (lista de quem falhou no gate). A defesa estrutural cristalizada no ciclo principal.
- **WinningPatternScout** ✅ — RESOLVIDO ciclo 26: o loop INVERSO de aprendizado. Pega 1 página vencedora real (HTML do operador) e produz: (a) auditoria multi-lib + hollowness; (b) fingerprint dos padrões já presentes; (c) signal density por lib (hits/1000 palavras); (d) candidatos a aprender — construções heurísticas não-encodadas que o próximo ciclo de aprofundamento deve incorporar. PROVA: scout da bridge elite v3 = 107 padrões detectados, hollowness 0, 8 candidatos surfaced. Foundation pro flywheel de pesos aprendidos.
- Aprofundamento contínuo com swipe files do mês corrente.

## Fundação
- `PatternLibrary` (contrato): toda lib = `name()` + `all()` (padrão = key/name/category/weight/trigger/lever/markers) + `categories()`.
- `PatternLibraryScorer` (único, genérico): pontua QUALQUER lib → score 0-100 ponderado + cobertura por categoria + ausentes de maior alavancagem. Markers string ou /regex/.

## As 9 bibliotecas
| # | Biblioteca | Maturidade | Padrões | Status |
|---|---|---|---|---|
| 1 | **AngleBigIdeaLibrary** | **88** ✅ | **22** | APROFUNDADA ciclo 14: +10 ângulos raros de swipe files de elite — inimigo duplo (Hollywood), ex-insider que abriu o jogo, matemática proibida, estatística que contradiz, profecia previsível, cavalo de Troia (oferta disfarçada de informação), vingança pessoal, história do futuro, conhecimento roubado/herdado, contrário-com-tilt (Ramit Sethi). |
| 2 | **AwarenessSophisticationLibrary** | **92** ✅ | **20** | APROFUNDADA ciclo 20: +10 padrões de engenharia fina — 4 pontes de transição (unaware→problem, problem→solution, solution→product, product→most), meta-sofisticação ("eu sei, mais um?"), auto-correção de mismatch (redireciona leitor errado), camadas (mesma página atende vários níveis via TL;DR), pivô lateral ("você procurou X, mas X é sintoma de Y"), colapso de sofisticação (volta ao básico simples), criação de nova categoria (Schwartz endgame). |
| 3 | **PersuasionPatternLibrary** | **88** ✅ | **31** | APROFUNDADA ciclo 13: +10 padrões SOTA de mestres — confissão danosa (Schwartz), justificativa "porque" (Langer), especificidade premium (Halbert), loops aninhados (cliffhanger TV), causa→efeito implacável, name-drop de autoridade específica, unidade (Cialdini 7º), especificidade da perda, permissão para o desejo, mudança de identidade. |
| 4 | **CognitiveBiasLibrary** | **85** ✅ | **23** | APROFUNDADA ciclo 18: +10 vieses raros — heurística da disponibilidade (história vívida > stat), efeito recência (fechar com pico), viés de fluência (rima/aliteração yo-yo/no-brainer), quebrar status quo (inação é decisão), efeito avestruz (forçar confronto), efeito manada explícito ("X pessoas entraram"), efeito IKEA (esforço = valor), evitar reactância ("você decide"), contabilidade mental ("menos que Netflix"), gradiente do objetivo ("80% feito"). |
| 5 | **OfferArchitectureLibrary** | **88** ✅ | **26** | APROFUNDADA ciclo 15: +10 alavancas raras que separam killer de boa — razão valor:preço esmagadora (10× Hormozi), reversão invertida (te paga $200 se falhar), nome proprietário do mecanismo™, bônus mais valioso que o produto, escassez com lógica crível (não fake), preço com justificativa, pagamento dividido inteligente, vantagem injusta do operador, comunidade fechada, termos declarados de fechamento. |
| 6 | **ObjectionLibrary** | **90** ✅ | **22** | APROFUNDADA ciclo 17: +10 neutralizadores avançados — desqualificação preemptiva (Cialdini scarcity de identidade), agita falhas passadas sem culpar, prova em camadas (estudo+médico+caso), reframe do custo afundado, normaliza o ceticismo ("eu também não acreditei"), mostra a matemática do custo/dia, aborda a objeção óbvia ("você deve estar pensando…"), garantia com consequência real, objeção pela voz de 3ª pessoa, meta-objeção "bom demais pra ser verdade". |
| 7 | **HookLeadLibrary** | **88** ✅ | **24** | APROFUNDADA ciclo 16: +10 fórmulas raras dos mestres — cena em uma frase (Bencivenga), confissão incompleta (cliffhanger íntimo), justaposição estranha, avatar nomeado (Carlton), convite condicional, formato de carta (Halbert), formato de diário com data, verdade desconfortável (taboo), cadeia de perguntas (Cialdini consistência), gap de curiosidade específico ("o #1 ingrediente"). |
| 8 | **NarrativeVoiceLibrary** | **92** ✅ | **24** | APROFUNDADA ciclo 19: +10 técnicas dos mestres — hipnose conversacional (Milton Erickson em copy), micro-cliffhanger de linha (Sugarman/Schwartz: cada parágrafo aponta pro próximo), anti-clímax cômico, **3 voice signatures dos mestres** (Halbert cru-direto / Bencivenga educado-crível / Carlton íntimo-conspiracional), tempo presente imersivo (cena no presente), regra de 3 com clímax (Lincoln/MLK), aparte proibido em parênteses íntimos, arco narrativo completo (Save the Cat). |
| 9a | **VisualPersuasionLibrary** | **92** ✅ | **25** | APROFUNDADA ciclo 22: +10 padrões visuais raros — grifo amarelo Halbert, seta direcionando CTA, player de vídeo fixado ao rolar (mantém VTR), revelação progressiva da oferta, contrato imagem-texto, tabela comparativa com checks, selo visual de garantia, play button gigante pulsando, barra sticky de oferta, tipografia mobile-first generosa. |
| 9b | **FunnelSequenceLibrary** | **90** ✅ | **26** | APROFUNDADA ciclo 21: +10 padrões raros de jornada multistep — tripwire→continuity bridge (LTV), pilha de OTOs em sequência (3-5), refund-saver flow (resgata 20-40%), coreografia VSL→checkout (CTA sincronizado), camada SMS de re-engajamento (98% open), sequência de retargeting pago, milestone na comunidade (D+30/60/90), funil REVERSO (high-ticket primeiro), cross-sell no pico de felicidade, winback narrativo (não desconto). |

## Pós-bibliotecas (faculdade de ambição)
- **`ConversionAuditor`** ✅ — RESOLVIDO ciclo 9: raio-X unificado das 10 libs (9 copy + 1 estrutura) → score overall + grade + top_missing ponderado cross-OS.
- **`AggressionAmplifier`** ✅ — RESOLVIDO ciclo 9: loop generator+verifier fechado. Audita → pega top_missing → gera snippets de elite (~30 padrões com injeção pré-escrita) → injeta nos slots (kicker/mechanism_tease/body_sections/cta_blocks/objection_flips/ps) → re-audita. PROVA: bridge fraca 4 → 21 (+17 overall), 8 das 10 dimensões com lift, 7 padrões injetados em 3 iter.
- Flywheel de pesos aprendidos do resultado real (LATENTE — aguarda venda voltando pelo ledger; só liga quando o operador rodar campanha).

## Ciclos
- **Ciclo 1 (FEITO):** fundação (contrato `PatternLibrary` + `PatternLibraryScorer` único) + `AngleBigIdeaLibrary` (12 ângulos) + Persuasion adota o contrato; cross-nicho provado; 157 verdes. Próximo = AwarenessSophistication (a meta-camada de roteamento que multiplica todas).
