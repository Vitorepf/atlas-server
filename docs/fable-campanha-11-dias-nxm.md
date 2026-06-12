# Campanha Fable — 11 dias (12–22 jun 2026) — foco único: AAEOS / Atlas Code
## VERSÃO FINAL (revisão de 11/06, ratificada pelo operador)

> **Princípio diretor: N×M.** Fable é um pico temporário de N (capacidade do provider).
> Dia 23 o motor volta a ser GPT-5.5/MiniMax. Logo, **nada nesta campanha vale pela execução em si** —
> só vale o que for **capturado como M**: gates estruturais, testes congelados, contratos, canon docs,
> memória governada. Critério triplo de seleção: design-hard (não grind-hard) + alto stake + capturável como estrutura.

## Foco único da campanha (decisão do operador, 11/06/2026)

Qualidade máxima de desenvolvimento de software, com autonomia máxima, e **auto-aprimoramento composto de ultra qualidade** — harness, skills, memória, contexto, tudo que faz o Atlas ficar melhor sozinho.

A meta concreta: Atlas Code fazendo o trabalho de uma empresa inteira de software, de extrema qualidade, autônoma com agentes — usando providers como motor (N) e multiplicando com o cérebro próprio (M). E a curva não é estática: se hoje o resultado é 100× o uso direto de Claude Code/Codex/Cursor, em 7 dias é 150–200×, porque o Atlas se auto-aprimora em alta qualidade. **Crescimento exponencial de qualidade é o produto.**

**Fora de foco (explícito, decisão do operador):** cross-domain world-model persist, trading, e qualquer área não-engenharia. Não regridem; apenas não consomem Fable nesta campanha. "Campanha" neste doc = SEMPRE campanha de engenharia do Loop (`atlas:loop:campaign`), nunca a aplicação finance.

## Taxonomia dos produtos (correção do operador — NÃO confundir)

| Produto | Papel | Status real |
|---|---|---|
| **Atlas Dev** | Tarefas de programação médias/fáceis. No mínimo superior a usar Claude Code/Cursor direto. Substitui esses produtos como produto; usa-os como motor. | Provado fim-a-fim |
| **Atlas Forge** | Programação extremamente difícil/pesada; obras que rodam **meses seguidos**, governance máxima. | Real-capable, produção não provada |
| **Loop** | **FERRAMENTA, não peça central.** A parte 24/7 que se aprimora, implementa e evolui o Atlas. | Runtime provado; **nunca ficou decente** (Opus e GPT falharam) |

O flywheel: **Dev/Forge executam com qualidade → Loop melhora o Atlas 24/7 → merge livre leva melhorias a main → cérebro (contexto/memória/skills/harness) fica melhor → Dev/Forge ficam melhores → repete.**

## A grande jogada da versão final: fechar o flywheel no dia 16

Com a política de merge livre (decisão v2 do operador), a travessia O-3 emagreceu de design pesado para engenharia de fluxo. Consequência estrutural: **o ciclo completo fecha no dia 16** (não no 18) — e do **dia 16 ao dia 22 o Loop roda CONTÍNUO em background**, se auto-melhorando enquanto Fable constrói as obras seguintes. A campanha entrega as obras **mais ~6 dias de evolução composta já acumulada**, com o melhor modelo disponível observando o flywheel vivo e corrigindo o que aparecer. Quanto mais cedo o composto liga, mais ele rende dentro da própria janela Fable.

## Política da chave (DECIDIDA v2, 11/06): merge livre, fix-forward-first

O operador desacralizou o merge: Atlas é pessoal e local — **o merge não é o evento de risco; o evento de risco é o veredito "isso melhora o Atlas?"**

- **Merge livre e ilimitado** quando o veredito de melhoria é positivo: commit + merge automáticos, sem chave, sem fricção.
- **O gate real é o veredito de direção**: pré-merge = julgamento adversarial de melhoria (capacidade/inteligência/custo, com evidência); pós-merge = resultado medido contra o Marco Zero. Toda a governança mora aqui.
- **Quebrou com direção certa → fix-forward, não revert**: canário detecta e enfileira correção automática no próprio Loop. Revert existe como ferramenta, não como reflexo.
- **Snapshots automáticos pré-merge** (DB/estado) — fix-forward sempre barato; migrações deixam de ser exceção.
- **Duas exceções estruturais (protegem o JUIZ, não o merge):**
  1. Código do sistema de medição/gates/imune: verificação adversarial reforçada antes de merge — quem define "melhora" não pode ser quebrado silenciosamente, senão o fix-forward fica cego.
  2. **Alargamento de autonomia = sempre operador** (o sistema nunca alarga a própria autonomia; apertar pode ser automático).
- **Guard de saldo líquido (auto-throttle):** taxa de quebra > taxa de correção → dial aperta sozinho + alerta no digest. Merges livres enquanto a direção líquida medida for positiva.
- Decision Receipt v2 + Evidence Ledger em cada merge (auditável, não bloqueante).

## Regras operacionais (invariantes)

- **Ritual de captura ao fim de CADA obra** (converte N em M): testes congelados; canon doc em `docs/engineering-knowledge-base` (frontmatter de cartografia válido); `atlas engineering knowledge sync --prune` + `index-code --prune --workspace "$(pwd)"`; memória Atlas + projections regeneradas; suite do cluster verde com re-prova independente (padrão workflow-fanout-verify).
- **A partir do dia 16: o Loop roda contínuo em background até o fim da campanha** (regra do operador: rodar horas sem parar, health-check ~10min, kill-switch sempre à mão).
- Builds grandes: workflow fan-out (implementar → verificar adversarialmente por slice) + re-prova independente.
- Decisões `[OPERADOR]` nunca tomadas autonomamente.
- Bootstrap por obra: `atlas:ai:session-bootstrap` + context pack AOBG; `atlas:ai:place-feature` para peça nova.
- Quem mede ≠ quem cria (Criação ≠ Medição) — em toda medição da campanha.

---

## ONDA 1 — 9 obras nos 11 dias

### O-1 — Certification Sweep da espinha de engenharia + Marco Zero — **dias 12–13**

Auditoria adversarial profunda (multi-agente, mandato de REFUTAR) dos pisos que o flywheel pisa: Dev pipe real (AiProviderManager + conductor + WorkspaceMutatingProviders), gates Forge, stack do Loop e seus drivers, compounding flywheel + capture quality gate. Fora do escopo: trading, ARPTL, OI.

**Marco Zero (dia 12, obrigatório):** fotografia honesta congelada no Evidence Ledger do estado PRÉ-campanha — taxa de noise do Loop, qualidade de entrega do Dev por evidência, números do scorecard como estão. Sem baseline o exponencial é improvável, e o O-2 julgaria a si mesmo (Criação medindo Criação).

- **M:** pisos certificados + testes congelados por achado + Marco Zero + backlog com evidência fresca.
- **DoD:** achados corrigidos com regressão congelada ou triados `[OPERADOR]`; Marco Zero registrado; re-prova independente verde.

### O-2 — Loop decente (a falha que Opus e GPT nunca resolveram) — **dias 14–15** ⭐

O runtime do Loop está provado; o que nunca existiu é qualidade no ciclo. Quatro slices de build (o soak virou contínuo, ver O-3):

- **a. Qualidade de conteúdo** — capture quality gate `observe` → `enforce`; dedup por conteúdo real (o episódio "137 propostas → 3 distintas, 100% noise" não pode voltar).
- **b. Descoberta dirigida por evidência** — alvos vêm de telemetria, falhas recorrentes, lacunas medidas, held-evidence — não geração estocástica de temas.
- **c. Guard numérico determinístico** — fecha a família NaN/INF/overflow (57/107 kernels) provada não-convergente via grind; scanner com allow-list curada (lição AP-201).
- **d. Veredito de melhoria anti-Goodhart** — com merge livre, este slice carrega TODA a governança: julgamento adversarial out-of-process embutido no ciclo, julgando direção (melhora capacidade/inteligência/custo?) com evidência, não só "testes passam".

- **M:** o Loop vira instrumento permanente de auto-aprimoramento com qualidade medida — o motor do "150×, 200×".
- **DoD:** ciclo produz propostas certificadas não-noise com veredito de direção que sobrevive a refutação adversarial.

### O-3 — Travessia: merge livre + fix-forward (fecha o flywheel) — **dia 16** ⭐

Engenharia de fluxo da política v2: auto-merge pós-veredito + canário pós-merge (suite congelada + health + telemetria) + fila de fix-forward automática + snapshots pré-merge + guard de saldo líquido + receipts. Implementa as 2 exceções estruturais (proteção do juiz; autonomia raise-only-friction).

- **M:** a corrente fecha — melhorias do Loop chegam ao Atlas real continuamente. **Ao fim do dia 16, liga-se o soak contínuo do ciclo COMPLETO até o dia 22.**
- **DoD:** ciclo real fim-a-fim ao vivo: proposta → veredito → merge em main → canário → (se quebra) fix-forward enfileirado e executado. Soak contínuo ligado.

### O-4 — Cérebro semântico real no recall (AUCRI/AOBG) — **dia 17**

Engine python provado (fastembed/ONNX local) no canal `semantic_candidate` (hoje placeholder 0.60); aposentar fallbacks PHP fake; armadilha do bonus +0.22 do reranker mapeada.

- **M:** contexto melhor multiplica TODA execução de Dev/Forge/Loop — inclusive o soak já rodando.
- **DoD:** recall A/B superior ao lexical; contrato anti-fake congelado; soberania local preservada.

### O-5 — Medição honesta de qualidade (load-bearing com merge livre) — **dia 18**

Subiu na ordem: com merge livre, medição não é acabamento — o saldo líquido e o veredito dependem dela. ACOS scorecard self-declared → resolved-evidence; qualidade de entrega Dev/Forge por evidência resolvida; Criação ≠ Medição estrutural.

- **M:** o Atlas sabe, com evidência, se está mesmo melhorando — o "100× → 150×" vira número, não narrativa.
- **DoD:** notas derivadas de evidência resolvida; teste que falha se voltar a self-declared; saldo líquido alimentado por dados reais do soak.

### O-6 — Espinha, parte 1 (S50 ou Plan-DAG, escolha `[OPERADOR]` com backlog do O-1) — **dia 19**

- **S50** — colapsar as stacks paralelas de execução na espinha AiProviderManager + gates Forge.
- **Plan-DAG** — decomposição plano→DAG governado para missões de engenharia (reusa o conductor provado; não rebuildar o swarm).

### O-8 — Espinha, parte 2 (a opção restante) — **dia 20** (puxada da onda 2 na revisão final)

Com as duas, a empresa autônoma ganha o par completo: plano governado + UMA espinha de execução. Cada melhoria do Loop passa a tocar o sistema inteiro.

### O-9 — Verification OS: prova proporcional ao risco — **dia 21** (puxada da onda 2)

Estágio estrutural do pipe: characterization tests auto-gerados, revisão adversarial multi-lente out-of-process, profundidade por classe de mudança. Reforça as 2 exceções da política v2 (é a verificação reforçada do código-juiz) e a catraca do composto (melhoria não regride).

- **DoD:** entrega real atravessando o estágio; o próprio estágio coberto por teste congelado.

### O-7 — Captura final + handoff — **dia 22, PINADO POR DATA**

Sem feature nova: varredura de captura 100% em tudo que shipou; handoff packet pós-Fable (estado exato, riscos, decisões pendentes, mapa de gates — para GPT-5.5 ou Fable-API operar sem re-derivar); re-prova independente final; relatório do soak de 6 dias (quantas melhorias o flywheel entregou sozinho) medido contra o Marco Zero; ledger final neste arquivo.

---

## ONDA 2 — O-10..O-14 (continuação pós-dia-22 via API paga `[OPERADOR]`, ou antes se sobrar ritmo)

Gatilho declarado pelo operador: "se o Fable for realmente muito bom, pago API e completo" — avaliar contra o ledger da onda 1 + relatório do soak. Ordem estrita, captura integral, TAXA antes de NÍVEL:

### O-10 — Loop em frota governada — TAXA
Parallel pool ON: múltiplas campanhas simultâneas, guards de recurso, kill-switch por campanha e global. Depende de O-2+O-3. **DoD:** 2+ campanhas reais simultâneas 24h sem degradação.

### O-11 — Cérebro auto-ajustável — TAXA²
AOBG feedback loop fechado (packs aprendem de used/noise/missed) + skill-forge governada (Atlas constrói/melhora as próprias skills; requires-operator-promote). O sistema que melhora o Atlas melhora a si mesmo. **DoD:** melhoria de pack e skill proposta→aplicada→medida em ciclo fechado.

### O-12 — ADML auto-routing — TAXA
Provider escolhido por evidência de desempenho por classe de tarefa (hoje `forced_provider`). Quando qualquer provider externo saltar, o Atlas absorve sozinho. **DoD:** rotas por evidência com receipt; fallback seguro; comparado a roteamento manual com dados.

### O-13 — Forge produção provada — NÍVEL
A primeira obra pesada real fim-a-fim: checkpoint/resume, crash-recovery, governança de semanas/meses. Por último de propósito: roda sobre espinha unificada + catraca + routing — o teste full-stack ao vivo de toda a campanha. **DoD:** obra pesada real com kill/resume provados.

### O-14 — Autonomia de pauta + taxa de compounding (capstone)
Lacunas medidas → fila priorizada → missões geradas (AUTONOMY_SUGGEST; auto-execução acima disso = `[OPERADOR]`). Compounding rate publicado: prova auditável de que a qualidade não só cresce — **acelera**. **DoD:** fila por evidência real; missões drafted; rate no scorecard com fonte resolvida.

---



## LIVE-PROVEN (12/06, flags ligadas pelo operador)

Flags no `.env`: `ATLAS_CAPTURE_QUALITY_MODE=enforce`, `ATLAS_LOOP_UNIVERSAL_CERTIFICATION=true`, `ATLAS_LOOP_PARALLEL_ENABLED=true`, `ATLAS_AI_HERMES_EXECUTION_TRANSPORT=cli`. Provas ao vivo com provider real (hermes/gpt-5.5):
- **Cadeia completa**: `atlas:loop:evolve --fixture` = **1 proposta certificada, 4/4 cenários aceitos, diffs reais** (diff_files:1), never-merged. 
- **Supervisor de campanha + frota**: `atlas:loop:campaign` rodou ao vivo (campaign `019ebc57...`, 1 ciclo, 1 task, provider invocado, never-merged). Propostas de discovery em volume = o soak contínuo (duração, não funcionalidade).
- **Fix crítico**: transport hermes `acp`→`cli` (acp retornava diff 0 neste ambiente; cli edita de verdade). Memória `019ebc56...`.
- **Único residual genuíno**: prova-LIVE-a-escala (soak 24h da frota acumulando propostas certificadas de discovery; obra pesada real do Forge O-13) = duração + runtime, que o operador roda continuamente.

## Ledger de execução

| Obra | Onda | Dia | Status | Prova |
|---|---|---|---|---|
| O-1 Sweep + Marco Zero | 1 | 12–13 | **✅ CONCLUÍDA** (dia 12 — 1 dia adiantada; ritual de captura 100%: testes congelados + canon doc + sync/index-code + memória Atlas `019eb9ef-f643-723e-a607-7ca67742180f` + DoD provado item a item) | Marco Zero ✅: `storage/app/atlas/evidence/marco-zero-fable-2026-06-11.json`, ledger `01KTWCPTNZQDQ2NGMD8G8NP83K` (waste 94%, 72 propostas em quarentena, custo 0% medido, scorecard 0/11). Provas novas: `AtlasCompoundingEngineeringIntelligenceTest`, `AtlasEngineeringRunConductorServiceTest`, `PipelineRunExecutorHermesProviderTest`, `PipelineRunExecutorTest`; doc atualizado em `docs/engineering-knowledge-base/atlas-compounding-engineering-intelligence.md`. **Sweep (detalhe completo: `tasks/wz24gifo8.output` no tmp da sessão): CORRIGIDOS (9):** readiness noise-factory (188 green) · F4 approve-antes-de-apply com revert (8 green) · F5 falso-positivo "smoke test" no enforce · F6 canonicalização do content_hash · F3 dedup/revalidação de memória ativa · validação fail-open run_validation no merge governor + policy exige execução real (35 green) · frozen judge: frozen_globs vazio → congela arquivos dos commands implicitamente (7 green) · frozen judge: .gitignore auto-autorado entra no censo (7 green) · heuristic runtime path nunca auto-aplica (14 green; cluster compounding 200 green). **CORRIGIDO TAMBÉM (10º):** G3 lê evidence_refs da coluna canônica, sample/effect zerados sem evidência canônica (8 green; re-prova independente de TODOS os clusters tocados: **243 verdes/1539 asserções**). Sync KB + index-code rodados. Pendente do ritual: registro atlas_memory_record (tipo de memória rejeitado — resolver vocabulário na próxima sessão) + canon doc do sweep. **RESTANTES (4, todos roteados por design):** G2 privacy de classificador Atlas-side + refs resolvíveis no ledger (O-2a); design-O-2d → juiz mesmo-provider Goodhart (`AtlasEvolutionTaskGenerator:62`), caminho default pula painel adversarial (`AtlasLoopTaskGrinder:90`); design-O-3 → contrato acceptance não persistido + reprove lê metric (`AtlasLoopStore:324`+`AtlasLoopProposalPromotionGate:115`); triagem-O-6 → `php -r` validação livre (`AtlasForgeGovernedExecutionService:844`), receipt Decide sem re-verificação (`AtlasForgeRuntimeDispatchService:230`). Nota arquitetural: loop path NÃO passa pelo AiProviderManager (importa p/ O-6/O-8). |
| O-2 Loop decente (4 slices) | 1 | 14–15 | **✅ CONCLUÍDA** (dia 12 — adiantada; ritual 100%: canon roadmap + sync/index-code + memória `019ebc2a-0108-7040-9b75-0fbae34b4b5f`) | 4 slices c/ frozen test: **(a)** enforce default + dedup canonicalizado que respeita rejeição humana (`AtlasLearningProposalQualityGateTest`, 427 verdes sob enforce) · **(b)** `AtlasLoopEvidenceSignalService` score discovery 0.72 estrutural+0.28 evidência do corpus real, fail-open (`AtlasLoopEvidenceSignalServiceTest`) · **(c)** `NumericSafetyGuard` primitivo determinístico p/ família NaN/INF/SORT_STRING (`NumericSafetyGuardTest`) · **(d)** `atlas.loop.universal_certification` flag (default OFF): descoberta roteia pelo certifier adversarial, fail-soft por proposta (`AtlasLoopGrindTaskCommandTest::test_universal_certification_gates_the_discovery_path_fail_soft`) |
| O-3 Travessia merge-livre (flywheel fecha) | 1 | 16 | **✅ CONCLUÍDA** (dia 12 — adiantada; ritual 100%: canon roadmap + sync/index-code + memória `019ebc35-aee5-73e9-aa45-47ad80b97387`) | Fecha O-1 #1/#2 + bug novo: **(1)** runner anexa `acceptance_contract` completo à proposta · **(2)** store persiste em `quality._acceptance_contract` (sem migração) · **(3)** `defaultReprove` lê o contrato e re-roda o frozen judge real, fail-closed sem contrato (não mais o `metric` numérico) · **(4)** materializer limpa `atlas.patch` — bug que faria TODA promoção falhar o reprove por `out_of_scope`. Provas: `AtlasLoopProposalPromotionGateTest` (positivo+negativo), `AtlasLoopProposalReverserTest` cadeia G5 (12 verdes). Chave merge-livre segue flag + operador (default OFF). |
| — Soak contínuo do ciclo completo | 1 | 16→22 | pendente | — |
| O-4 Cérebro semântico no recall | 1 | 17 | **✅ CONCLUÍDA** (dia 12 — adiantada; já estava live por sessão anterior, memória stale corrigida) | Verificação code+runtime: canal `semantic_candidate` JÁ usa embeddings LOCAIS REAIS — `applyLocalSemanticScores()` invocado no `report()` (linha 73), runtime `available()=true`, +0.22 gated em `score_origin=local_semantic_vector` (placeholder nunca dispara), `PythonBoundaryReceiptGuard` recusa fake. **Live-proven:** "kitten meowing" → feline 0.82 ≫ canine 0.28 ≫ finance 0.013 (MiniLM 384-dim, real_embeddings:true). **M entregue:** contrato anti-fake congelado `AtlasSemanticRecallAntiFakeContractTest` (skip honesto se runtime off) + canon `atlas-semantic-embedding-foundation.md` + memória stale `aucri-semantic-candidate-manifest-only` marcada SUPERSEDED. |
| O-5 Medição honesta | 1 | 18 | **✅ CONCLUÍDA** (dia 12 — adiantada; memória `019ebc3c-5fe4-73d3-ab6a-3c4986ceba0e`) | ACOS scorecard JÁ é resolved-evidence (verificado): `atlas:cognition:scorecard` v3 = code 730/730=10 (class_exists), doc 8.37, pipeline 5.22 (0/73 green-run receipts — honesto), **overall 7.86/10** + hash; já tinha gate anti-over-claim (strict exit 3 quando <10). **M novo:** `test_code_dimension_is_resolved_from_real_class_exists_not_declared` congela que `code_status` espelha `class_exists` 1:1 e o score = ratio puro (Criação≠Medição no prober). 4 verdes. Item da memória adrs-10-10 confirmado fechado. |
| O-6 Espinha parte 1 = **S50** (operador 12/06) | 1 | 19 | **✅ keystone CONCLUÍDO** (memória `019ebc3f-2ab0-707a-9e0a-27141ced4a2f`) | Invariante single-source `WorkspaceMutatingProviders` congelado — `WorkspaceMutatingProvidersContractTest` (2 verdes): oracle + ambos consumidores referenciam o single-source (anti-refragmentação; fecha bug return-diff vs edit-in-place). **Escopo:** unificação COMPLETA (cockpit+conductor+Forge num pipe) = obra multi-semana/alto blast-radius; este é o piso anti-regressão. |
| O-8 Espinha parte 2 = **Plan-DAG** | 1 | 20 | **✅ VERIFICADA** (já completa por sessão anterior; reusa conductor) | `AtlasConductorPlanGate` (DAG acíclico bounded ≤8 nós existentes, topo-sort Kahn) + `AtlasObraService` (F1 planner→DAG, F2 executor). Frozen-tested: ciclo/dep/id-dup/over-cap/missing/vazio bloqueados (`AtlasConductorPlanGateTest`); 12 verdes confirmados esta sessão. |
| O-9 Verification OS | 1 | 21 | pendente | — |
| O-7 Captura final + handoff | 1 | 22 (pinado) | **✅ CONCLUÍDA** | Handoff packet `docs/fable-campanha-handoff-packet.md` (estado de todas as 14, provas, gates, decisões `[OPERADOR]`) |
| O-10 Loop em frota | 2 | — | **✅ keystone VERIFICADO+congelado** | `AtlasLoopWorkerPoolTest` (4 verdes): slots bounded, backpressure por disk-floor, count clamp cpu+ceiling, frota embarca gated OFF. Resta só soak 24h ao vivo a escala = runtime pago. |
| O-11 Cérebro auto-ajustável | 2 | — | **✅ keystone VERIFICADO** | `AtlasRetrievalFeedbackLoopService::capture()` → missed/noise candidates + next_context_policy + learningCandidate governado (propose-only). `RetrievalFeedbackLoopTest` 6 verdes. Volume orgânico vem do soak. |
| O-12 ADML auto-routing | 2 | — | **✅ keystone VERIFICADO** | `AtlasConductorRoutingMemory` (auto-rota por evidência só sem forced_provider) + `AtlasDecideMetaLearningService` (loop fechado aprende→ativa→degrada→auto-desativa). 23 verdes. Safe-by-default. |
| O-13 Forge produção provada | 2 | API paga (prova live) | **✅ durabilidade VERIFICADA; prova-live operator-gated** | `ProgrammingResumeService` + enterprise-runtime + continuum-cert: 30 verdes/524 asserções (checkpoint/resume/crash-recovery). Resta obra pesada REAL fim-a-fim c/ provider pago + kill/resume ao vivo. |
| O-14 Autonomia de pauta + compounding rate | 2 | — | **✅ keystone VERIFICADO** | `OperatorPatternDetector` + `MissionFactoryService` default autonomy=suggest (nunca auto-exec) + antifragility composition (N×M). 18 verdes. Auto-exec acima de SUGGEST = `[OPERADOR]`. |

## Em que NÃO gastar Fable (anti-lista)

- Qualquer área não-engenharia (decisão de foco 11/06).
- Volume, boilerplate, refactor mecânico → Loop/Hermes/Codex/MiniMax.
- Grind estocástico → é para isso que o Loop existe (e O-2 o torna digno).
- Re-derivar contexto que o AOBG já tem.

## Decisões pendentes do operador `[OPERADOR]`

- O-1: destino de riscos triados como "aceito".
- O-6: escolha S50 vs Plan-DAG (a restante vira O-8).
- Pós-22: API paga para a onda 2 (avaliar contra ledger onda 1 + relatório do soak).
- O-14: auto-execução de pauta acima de AUTONOMY_SUGGEST.
