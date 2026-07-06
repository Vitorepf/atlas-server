# Obra #12 (proposta) — Refatoração da Pilha Cognitiva (ACOS runtime · APCR · ACIE · ACQCG · RAG local)

Data: 2026-07-06 · Status: **spec aprovável** (nenhuma linha tocada)
Método: 4 finders read-only por dimensão (famílias/clones, god-methods, transversal comandos+testes, Cognitive/LongHorizon/RuntimeBoundary) sobre a pilha de ~58k LOC, com as **regras de calibração provadas na Obra #8** (import custa +2/arquivo; corpo ≤3 linhas/site não paga; spec-table 1-linha-por-dado não paga; economia só com conta explícita).

## Escopo medido

| Área | LOC | Arquivos |
|---|---|---|
| app/Services/Ai/Context (AUCRI: ACRS, ACFQ, ATER, ARFL, arena RAG, Aucri/, Gates/) | 14.936 | 46 |
| Raiz Ai (Memory/Context/OpenBrain/RAG/Compaction) | 22.587 | ~40 |
| app/Services/Ai/Cognitive (inclui SRL/, Failure/, Dreyfus/) | 8.846 | 65 |
| app/Services/Ai/LongHorizon (TEOS) | 8.175 | 21 |
| RuntimeBoundary + Aemor + Knowledge | 3.735 | 27 |
| **Total** | **~58k** | ~200 |

## ⚖️ VEREDITO HONESTO (ler antes de aprovar)

**Esta pilha NÃO é uma mina de LOC.** Os 4 finders, calibrados, encontraram **~730–950 LOC líquidas confirmáveis (~1,6% da pilha)** + até ~230 condicionadas à governança de órfãos. Compare: a Obra #8 tirou −30,8k de 2,9M (~1%) — proporcionalmente esta pilha rende o MESMO ou mais, mas o valor absoluto é pequeno porque a pilha é 50× menor e **majoritariamente lógica distinta legítima** (cada bloco AUCRI faz coisa diferente; maior arquivo tem 1.102 linhas; padrão seal já migrado). Isso CONFIRMA o veredito da Obra #6 sobre núcleos: código denso ≠ código inchado.

**O que esta pilha precisa para chegar ao "altíssimo nível" não é menos linha — é mais prova**: pipeline do scorecard em 5.64/10 com ~66/73 subsistemas sem green-run receipt. Por isso o desenho recomendado abaixo casa cada slice com a Obra #11.

## Regras pétreas (herdadas da #8 + calibrações novas)

1. Byte-identidade de saída; **os certificadores da pilha são os goldens prontos**: `atlas:persistent-context:certify` (14/14), `atlas:context-intelligence:certify` (13/13), `atlas:context:quality-certify`, `atlas:ai:local-rag-readiness`, `atlas:cognition:scorecard` (hash). Toda slice fecha com o certificador da área verde.
2. Calibração de trait: +2 linhas/arquivo de adoção; corpo ≤3 linhas/site não paga (strictExit +13). Spec-table 1-linha-por-dado não paga (R-19 +84).
3. `Number::clamp` PROIBIDO em código de gate (N-05 da #8: NaN→1.0). Clamps compartilhados = helper local próprio.
4. Arquivos sujos da Obra #7 (`AtlasRetrievalFeedbackLoopService`, `AtlasOpenBrainContextPackService`, `AtlasMemory*`) só entram APÓS o merge da #7.
5. Órfãos 0-ref = órgãos: tag `@unwired-until` ou wire-or-retire com tripla prova — nunca deleção direta.
6. Lane do operador (não esteira do Loop). Commit por slice na main; push só com OK.
7. **Fechamento de slice = certificador verde + `atlas:cognition:mint-pipeline-receipts` na área tocada** (a obra emagrece E sobe o pipeline score — o minter já existe e funciona: 5 receipts cunhados hoje, 5.37→5.64).

## Onda única — slices confirmadas pelos finders (~730–950 LOC)

| ID | Slice | Arquivos-chave | LOC líq. | Risco | Prova |
|---|---|---|---|---|---|
| S-01 | Trait `TestsWithLedgerEvents` para o setUp/tearDown clonado em 7 testes de Cognitive (Failure/Dreyfus/SRL) | tests/Unit/Ai/Cognitive/* | ~118 | baixo | placar idêntico por arquivo |
| S-02 | Traits de família nos scorers/gates do Aucri: `ScalarExtraction` (int/float/string/boolValue duplicados em AucriSegmentRoiScorer + RecallContextBudgetSplitScorer) + clamp helper LOCAL (RetrievalFanoutGate, IrrelevantContextRatioGate, QualityCertification) | app/Services/Ai/Context/{Aucri,Gates}/ | ~145 | baixo | testes unit dos scorers + `atlas:context:quality-certify` |
| ~~S-03~~ | ~~EmitsCanonicalJson nos 27 certify~~ **REFUTADA por medição (06/07)**: shape real é 1 site/comando (`line(json_encode ?: '{}')` → `jsonLine()` = 1:1, LOC-neutro) + 2 de adoção/arquivo = **net POSITIVO**. Mesma classe do strictExit — o finder contou "2 linhas/comando" errado. | — | 0 | — | — |
| S-04 | RuntimeBoundary: trait/factory para o construtor + `run()` clonados nos 5 clients Python (GraphRank/StatsEngine/NearDuplicate/SemanticRag/HonestMetrics) | app/Services/Ai/RuntimeBoundary/ | ~70 | baixo | contratos de boundary + `atlas:ai:local-rag-readiness` |
| S-05 | AiCompactionService: normalizadores (must-keep/forced-discards/string-list) → canon compartilhado + `normalizeCompactionInput()` no compactForScope (128 ln) | app/Services/Ai/AiCompactionService.php | ~85 | baixo | testes de compaction + APCR certify 14/14 |
| S-06 | Consolidações intra-arquivo dos grandes: const `POLICY_PROVIDER_SAFE_DEFAULTS` (3 serviços), tabela única `ScopeTypeLabels` (2 matches duplicados), `extractRefMetadata()` e `reportSection()` helpers | Context/ + AiContextPackBuilder | ~100 | baixo | byte-identity dos subarrays policy/reason + ACIE certify 13/13 |
| S-07 | Testes: data providers nos grupos shape-idênticos de Cognitive/Failure + delegação `data_get`/AiValueNormalizer SÓ nos byte-provados (finder estimou 125; conta honesta pós-C-16/C-17 ≈ metade) | tests + Memory classifiers | ~85 | médio | placar idêntico + byte-prova por site |
| ~~S-08~~ | ~~Flexibilidade morta~~ **REFUTADA por verificação de intenção (06/07)**: flags `context_budget.*` e embedding keys são `env()`-driven (capacidade de rollout/override REAL — o finder não viu o `env()`); alias do Knowledge tem 6 consumidores + arquivo homônimo em Context/ (remoção custa mais que os ~2 LOC). | — | 0 | — | — |
| S-09 | `contextRefAttribution()` (108 ln, 3 loops sobre o mesmo array → 1 passada) | AtlasRetrievalFeedbackLoopService:539 | ~45 | médio | hash da attribution byte-idêntico — **AGUARDA merge Obra #7** |
| S-10 | Helper `relative(path)` duplicado em ~8 certification services | Aemor/LongHorizon | ~23 | baixo | certifies TEOS/Aemor verdes |

## Governança de órfãos (potencial adicional ~230 LOC — NÃO é deleção livre)

Descobertos SEM tag `@unwired-until` (violam a convenção da Obra #7): **SRL** (`SRLForethoughtCapture`, `SRLPerformanceObserver`, `SRLReflectionCapture` — 71 LOC, 0 callers, 0 testes), **Dreyfus** (`DreyfusEvidenceAggregator` — 80 LOC), **ProductiveFailure** (7 classes — ~71+ LOC). Ação da obra: para cada um, decidir wire (consumidor real) / tag (`@unwired-until` + missão) / retire (tripla prova). Isso é trabalho da esteira wire-or-retire, não desta spec — mas a spec REGISTRA a dívida de governança.

## NÃO-FAZER (refutados pelos próprios finders, com conta)

| Proposta | Por quê |
|---|---|
| Trait `CertificationServiceOrchestrator` unificando certify() de TEOS/Aemor/Continuity | Semânticas divergem de verdade (pass/fail vs pass/warn/fail + severity P0-P2); o trait reintroduziria os if-branches e anularia o ganho. Refutado na conta. |
| Padronizar os 17 comandos sem padrão --json/--strict | É ADIÇÃO (+51 LOC), não redução; e certification services pinam '--strict' no SOURCE das signatures. |
| Splits estruturais dos arquivos de 900-1.100 linhas (Ranking/Audit/FeedbackLoop) | Lógica densa distinta; split = +LOC +indireção sem dor comprovada (padrão Obra #6/Hermes-review). |
| `Number::clamp`, spec-tables 1-linha-por-dado, unificação epsilon cross-domínio | Herdados da #8 (N-05, R-19, veto hash-path). |

## Desenho recomendado da execução

**Casar com a Obra #11**: cada slice fecha com (a) certificador da área verde, (b) `mint-pipeline-receipts` cunhando o green-run do subsistema tocado. A obra entrega as duas metas do operador numa esteira: pilha mais enxuta E pipeline score subindo mensuravelmente a cada commit. Pré-requisito parcial: Obra #9 (vermelhos) para as áreas cujos testes estão red; as slices S-01..S-06 têm suites verdes hoje e podem começar imediatamente após aprovação.

**Sequência**: S-01 → S-02 → S-04 → S-05 → S-06 → S-10 → S-07 → S-08 → (pós-merge #7) S-09. Órfãos: esteira paralela de governança. (S-03 refutada na execução.)
