# Atlas Brain — Catálogo de Formas de Auto-Melhoramento

> Fonte: survey `wnankv6oh` (26/06/2026) — 3 mineradores (codebase Atlas + literatura de self-improvement de agentes + padrões do trendshift/topics/ai-agent) + gap-analyst.
> Encaixa no [Motor de Meta-Melhoramento](../../../.claude/projects/-Users-vitorepf-develop-Atlas-atlas-server/memory/brain-meta-improvement-engine.md): portfólio de PATHS + meta-selecionador + ledger que aprende.

## A DESCOBERTA (diagnóstico honesto da forma)

O cérebro do Atlas é uma **fortaleza de JULGAR** e **magro em PENSAR**. Das ~33 primitivas existentes, a esmagadora maioria é do lado-DIREITO (verification / anti-Goodhart / safety gates). **7 dos 8 maiores gaps de alavancagem são do lado-ESQUERDO** (comprehension depth, learning/memory, origination-search).

Isso é o **inverso da tese 70/20**: o Atlas super-construiu os 20% (julgar o resultado) e sub-construiu os 70% (compreender / arquitetar / afiar o spec ANTES de autorar). O verifier é de classe mundial; o pensador é raso. **A maior alavanca do cérebro agora é engrossar a esquerda.**

---

## A. Formas que JÁ usamos (HAVE — ~33 primitivas)

**Verification / gating (o forte):** Comprehension Grounding Gate, Semantic Implementation Certifier, Cross-Model Triangulator (consenso categórico, sem smoothing), Judge Consensus Gate, Adversarial Verifier Pool (P5 dissent-parker), Red Reason Gate (RED estrutural vs comportamental), Frozen Test Content Builder (bytes selados), Task Spec Translator (allowed-files grounding), Task Packet Quality Inspector, Seed Quality Gate, Vagueness Pre-Screener, Model Projection Critic, Refusal Critic Panel + Perpetual Adversarial Sweep.
`app/Services/Ai/AutonomousEvolution/{Verify,Discovery}/ + raiz`

**Anti-Goodhart / anti-fake:** Cycle Progress Verdict (S7), Evolution Level Classifier (proxy-delta), Ambition Leap Proposer (3+ refs aterrados), Calibrated Confidence Gate (banda empírica de merge), Goodhart Receipt Ledger (audit append-only).

**Safety / floor:** Harness Guard (FORBIDDEN_SELF_TARGETS pétreo, no-blinder), Master Switch (fail-closed), Leap Risk Auditor.

**Origination / orchestration:** Comprehension Originator (writer≠judge), Heavy Work Selector (ambição risk-seeking + trust-ladder), Frontier Gap Model (plateau/starvation), Scope Dry Probe (honest-stop), Explorer Strategy Bandit + Escalation Ladder, Decomposition services (DecompositionService/ShapePrior/FeatureSequencePlanner/LeapDecompositionSeeder/TaskDecompositionAmplifier).

**Comprehension:** Scope Comprehension Model Builder (oráculos não-gameáveis: orphan-grep, AST-clone, doc-gap).

**Memory / learning:** Done-Set Ledger (dedup sticky), Capability Trend + Transfer Gate + Learning Append, Decision→result outcome ledger (flywheel), Morning Digest.

**Simulation / "gêmeos":** Twin/AtlasLoopSimulableTwinOrchestrator + CounterfactualEditEvaluator, L8TwinGuidedSelectionGate, AtlasEvolutionScenarioExplorer, Simulation/AtlasLoopSimulationDryRunner, Parallel/ScenarioWaveDispatcher.

---

## B. Formas que FALTAM (MISSING), agrupadas por PATH do portfólio

**Comprehension-deepening path (o 70%):**
- Tree of Thoughts / Graph of Thoughts sobre o espaço de evolução (hoje os mapas orphan/clone/doc-gap são consumidos em silos, sem fusão nem busca com poda/backtrack).
- Self-RAG: auto-flag por-claim supported/unsupported contra file:line durante a compreensão.
- Local simulated-environment / world-generator (ensaiar decisão offline antes de gastar).
- Perception leve (OCR + UI element detection) pra dissecar criativo/landing/dashboard.

**Memory / learning path:**
- **Reflexion** — post-mortem verbal keyed por escopo, re-injetado na próxima compreensão (hoje só grava `terminal_reason` flat = log write-only, não aprendizado).
- **Generative-Agents memory stream** — recall por recência+relevância+importância + síntese periódica de reflexões.
- Meta-Policy Reflexion — promover reflexões recorrentes em regras canônicas (compounding no floor).
- STaR — corpus de rationales dos runs que passaram no gate.
- Group-evolving / cross-worker experience sharing — pool comum de skills/reflexões.

**Adversarial-critique path:**
- **Self-Refine** + **Constitutional critique-revise** do packet spec ANTES do handoff (hoje só screen pass/fail, nunca melhora iterativa).
- **Self-Consistency / Universal-SC** — N traços independentes + plurality na decisão de alavancagem.
- Multi-agent debate entre personas (proposer/skeptic/floor-keeper) na ORIGINAÇÃO.
- ThinkPRM generative verifiers — veredito com rationale escrito no Decision Receipt.
- Incentivized self-verify — tier fallback de menor confiança quando não há jury.
- **Correlated-error jury weighting** + **non-transitivity guard** — endurecer o consenso que já usamos.

**Origination / pattern-design path:**
- **Voyager skill library** — skills nomeadas, executáveis, composáveis, recuperáveis (capability aditiva e auditada, não re-derivada).
- ELO / Bradley-Terry tournament selection entre candidatos/rivais.
- Darwin-Gödel variant archive — arquivo de variantes certificadas pra ramificar.
- AlphaEvolve evolve-and-evaluate pra escopos com fitness limpo.
- Goal-driven outcome-contract — bind a "o escopo ficou exponencialmente mais capaz?", não à lista.

**Verification / multi-file path (desbloqueio do gargalo-mãe):**
- **Process Reward / step-level verification** — verificar cada commit de uma obra multi-file, não só o estado final.
- **ADaPT** — re-decompor recursivamente só a subtask que falhou.
- Verifier-guided search + **scaling-flaws guard** (cap anti-exploração de um verifier só + rotação).

**Scheduling / meta-selector path:**
- **Compute-optimal test-time scaling** — orçamento adaptado à dificuldade (sample/debate pesado só no high-leverage).
- Best-of-N packets com selector.
- Automatic curriculum — próxima evolução na fronteira da capacidade ATUAL.
- POET — população de desafios coevoluindo com stepping-stone transfer.
- Bandit de alocação de estratégia test-time aprendido do ledger.

**Safety path:**
- Conformal abstention (bound finito-amostral no act-vs-defer) + Selective prediction por-escopo.
- Skill-supply-chain scanner (screen prompt-injection/memory-poisoning antes de instalar skill/prompt — endurece o HiddenPoisonDetector incompleto).

---

## C. Top-8 gaps de alavancagem (RANKEADOS — ordem de construção)

1. **Reflexion + Generative-Agents memory stream** — *a maior alavanca de compounding da esquerda.* Transforma o append-ledger write-only em aprendizado: post-mortem semântico keyed por escopo, recall scored, re-injetado em TODA compreensão futura. Shape: upgrade `AtlasLoopLearningAppendService` → ledger episódico + organ de comprehension que recupera top-K reflexões pro prompt do Comprehension Originator + digest semanal que sintetiza.
2. **Self-Refine + Constitutional critique-revise do packet** — afia o spec ANTES do músculo (exatamente o 70%). Loop critique→revise N× sobre acceptance/scope/wiring + contra o floor pétreo, entre Task Spec Translator e Seed Quality Gate. Viola­ção do floor vira auto-correção, não rejeição.
3. **Self-Consistency / Best-of-N sobre a origination** — corta variância one-shot na decisão de maior variância (qual evolução). Wrap N-sample no Comprehension Originator/Heavy Work Selector; plurality pra picks enumeráveis, USC selector pra free-form.
4. **Tree/Graph of Thoughts sobre o espaço de evolução** — profundidade pura de compreensão. Expande ramos a partir dos fatos-oráculo que já temos, auto-score via o value panel determinístico, poda/backtrack, e nós de agregação que FUNDEM dedup/orphan/doc-gap num plano só.
5. **Process Reward step-level + ADaPT re-decomposição** — *desbloqueia o gargalo-mãe* (grind single-file não executa obra multi-file). Step-verifier em cada subtask das Decomposition services + re-decompor só a que falhou; veredito generativo alimenta o receipt ledger.
6. **Compute-optimal test-time scaling** — torna os métodos da esquerda AFFORDABLE (Self-Consistency/debate/ToT são caros flat). Estende o Explorer Strategy Bandit → alocador de orçamento por-origination keyed na dificuldade/leverage do comprehension model.
7. **Correlated-error jury weighting + non-transitivity guard** — endurece o consenso que o cérebro JÁ usa pra mergear. Pondera votos por independência-de-erro inter-juiz; detecta ciclos A>B>C>A. Barato, protege toda decisão de gate.
8. **Voyager skill library + cross-worker sharing** — é a tese de antifragilidade N×M em forma estrutural. Certificou deliverable generalizável → cristaliza em skill nomeada/executável/recuperável; pool compartilhado compõe em vez de re-derivar.

---

## D. Sequência recomendada

A keystone é **#1 (memory/learning stream)** — alimenta TODOS os paths e é o que falta pro meta-aprendizado (`{path → resultado}`) existir de verdade. Depois **#2/#3** (afiar o spec + cortar variância da decisão), que são a esquerda direta. **#5** quando o foco for o gargalo multi-file. **#6** logo que ≥2 métodos caros da esquerda estejam ligados. **#7** a qualquer momento (barato, defensivo). **#8** quando houver volume de deliverables certificados pra cristalizar.

Tudo isso são organs novos do lado-esquerdo + wiring; o lado-direito (gates) já está pronto e NÃO precisa de mais. O meta-selecionador + ledger de meta-aprendizado (a camada-portfólio) consome o #1 (memória) como substrato.
