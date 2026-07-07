# Linha ACOS #15-#20 — Plano de Execução em 4 prompts `/goal` (Opus 4.8 + ultracode)

Data: 2026-07-06 · Governança: `obra-linha-acos-carta-de-autonomia-2026-07-06.md` (autonomia total, sem aprovação) · Gerado por conselho de planejamento com substrato verificado no repo.

**Uso:** cole cada bloco `/goal ...` numa sessão Opus 4.8 com ultracode, NA ORDEM (1→2→3→4). Cada um é autônomo e longo: gera as ordens de trabalho a partir dos planos-mestre, implementa slice a slice na main local, commita cada um e etiqueta no Diário de Evolução — sem pedir aprovação. Bloco 1 é o bootstrap inviolável (Kit de ordens + Diário + reversibilidade); os blocos 2-4 falham barulhento se rodados antes dele.

---

Tudo confirmado. Paths reais, colisão de comando confirmada (`AtlasFrontendWorkOrderCommand` existe), net-new confirmados, WO-cobaia no lugar. Produzo o plano.

---

# PLANO DE ENTREGA — 4 prompts `/goal`

## 1. VEREDITO: **4 prompts**, não menos, não mais

**Por que não 1:** a linha tem ~40 slices, XLs reais (os 3 Sistemas da #20 + T4-S1/S7), e três eixos de dependência dura que se cruzam (bootstrap do Kit, fundação de reversibilidade, escada do motor). Um só `/goal` derrapa: contexto estoura, o Diário vira lixão de commits sem coerência, e o drift dos docs antigos (a Carta venceu #17/#18/#19/#20) contamina tudo de uma vez sem ponto de auditoria.

**Por que não 6-10:** a maior parte das camadas compartilha substrato e se implementa em cascata natural (o motor-de-entrega #18/#19 é uma escada única; os saltos T4 são todos filhos de T2). Fatiar mais fino multiplica handoffs entre sessões e re-leitura de doc sem ganho — cada `/goal` já é autônomo e longo.

**Por que 4 é o número certo — imposto pelas DUAS fundações + o gargalo de bootstrap:**

- O **Kit K (K1-K4)** é a fábrica de ordens. Regra de ouro do LEIA-ME: *nenhum slice se implementa sem WO*, e a fábrica ainda não existe. Tem que vir no **primeiro** prompt, escrito à mão.
- O **Diário (DIARIO-1/2/3) + SIS8** é a trilha de autonomia e a licença de reversibilidade que **substitui a aprovação humana**. Sem DIARIO-1/2 no lugar, C3/K4/graduação não têm onde etiquetar; sem SIS8 (journal-first + replay) o `reverter` do Diário é fake. A nota do inventariante é explícita: SIS8 é fundação da autonomia, puxado para a Fase 0 apesar de estar listado na #20. Tem que vir no **primeiro** prompt junto do Kit.
- Isso trava o **Bloco 1 = as duas fundações**. Depois a linha se parte naturalmente em: **Bloco 2** = tornar o retrieval+contexto real (#17 T0-T3, a espinha cognitiva), **Bloco 3** = o motor que multiplica isso em toda superfície e o encanamento de prova/landing (#18 C/D + #19 P/L/S), **Bloco 4** = a inteligência avançada (7 saltos T4) + a metamorfose (3 Sistemas). Quatro unidades, cada uma com um tema auditável e uma barra de "pronto" clara.

---

## 2. A DIVISÃO em 4 blocos

### BLOCO 1 — FUNDAÇÃO: Kit de Ordens + Diário + Reversibilidade
**Slices:** K1, K2, K3, K4, DIARIO-1, DIARIO-2, DIARIO-3, SIS8, T0.1, C1, D1, D2
**Por que é uma unidade:** é o bootstrap inviolável. Kit K = a fábrica de WO (sem ela nada mais tem ordem). Diário + SIS8 = a trilha etiquetada e a licença de reversibilidade que **substituem a aprovação humana** — precisam existir *antes* de qualquer auto-promoção. T0.1/C1/D1/D2 entram aqui como as **cobaias que provam o Kit** (T0.1 já tem WO pronta; C1/D1/D2 são `pronto_agora`-independentes e alimentam o resto). SIS8 sobe da #20 para cá porque DIARIO-2 `reverter` depende materialmente do replay journal-first.
**Dependências:** nenhuma externa. Ordem interna: SIS8+DIARIO-1 → DIARIO-2 → K1 → K3 → K4 → K2 → DIARIO-3, com T0.1∥C1∥D1+D2 rodando cedo como cobaias.
**Tamanho:** GRANDE (2 XL: DIARIO-1, DIARIO-3; SIS8 XL; resto M/L). O bloco mais pesado depois do 4.
**Pronto quando o Diário mostrar:** comando `atlas:obra:work-order` gera+valida WO (2 falsos-fantasmas seriam pegos); linter+gate K3/K4 recusam ordem malformada na fonte; `atlas:evolucao hoje/listar/ver/reverter` funciona; kill-test `DROP DATABASE→atlas:brain:replay <5min→content_hash idêntico 100%`; DIARIO-3 emitiu ao menos 1 entrada `promocao-memoria`+`merge` de ação autônoma real; T0.1 mergeado com WO gerada pelo Kit (não à mão).

### BLOCO 2 — ESPINHA COGNITIVA: retrieval por query + contexto real
**Slices:** T0.2, T0.3, T0.4, T1, T2, T3
**Por que é uma unidade:** é a #17 inteira menos o T0.1 (já entregue no Bloco 1). Transforma "tem retrieval" em "retomei e ele sabia / lembra por quê e avisa antes / qualquer repo". Tudo encadeia num eixo: medir baseline → estado por obra → brief determinístico → multi-repo. É a fundação que os saltos T4 do Bloco 4 consomem.
**Dependências satisfeitas:** T1 precisa de T0.1+K1 (ambos no Bloco 1 ✅). T2←T1, T3←T2, todos internos.
**Tamanho:** MÉDIO-GRANDE (3 L: T1/T2/T3; resto S).
**Pronto quando o Diário mostrar:** gate de retomada ≤3 turns; perguntas evitáveis −≥50% (T2); raio de explosão cobre ≥85% dos arquivos tocados em 10 slices reais (T3); baselines-âncora (T0.4) publicados como entrada `evolucao-de-fase`; hit-rate do guard antes/depois do T0.1 medido pelo próprio Atlas (T0.2, não protocolo de operador).

### BLOCO 3 — MOTOR DE ENTREGA: multiplicador de memória + prova/landing/sessão
**Slices:** C2, C3, D3, D4, D5, P1, P2, P3, P4, P5, P6, L1, L2, L3, S1, S2, S3, S4
**Por que é uma unidade:** é #18 C/D + #19 inteira — a camada que pega a memória viva e a espinha do Bloco 2 e as **multiplica em toda superfície de execução** (Dev prompt via C1 já feito, esteira via C2, captura via D2 já feito), mais o motor de prova (P1-P6: pre-gate, test-impact, receipt-cache, paratest, golden, sandbox sem-wiper) e landing serializado (L1-L3). Coeso porque é tudo "encanamento que faz a entrega ser barata e segura", com escada própria (#19) paralela ao eixo de memória (#18) onde não compartilham arquivo.
**Dependências satisfeitas:** C2←T0.1✅; C3←D2✅; D3←D1✅; D4←T0.1✅; D5←D1+D2+D3+D4; P3←K4✅+P2; L2←L1; S2←T1✅ (Bloco 2). Tudo já resolvido nos blocos 1-2.
**Tamanho:** GRANDE (18 slices, mas quase todos M/S; 1 L: P4 paratest).
**Pronto quando o Diário mostrar:** memória dereferenciada no prompt do Dev (C1 já; C2 ≥80% dos packets em zona-com-decisão carregam-na); pre-gate ≤3s com phpstan des-crashado (P1); test-impact recall ≥0,85 provado (P2, PÉTREA); suite 329min→~50min sob paratest (P4, sem tocar o phpunit.xml sqlite); `atlas:land` com index.lock/sessão 58→~0 (L1); sandbox com `cp -Rc` — `composer dump-autoload` em worktree nunca reescreve autoload vivo (P6, PÉTREA anti-wiper); D5 dá ≤50 ao estado de HOJE e sobe só quando D1-D4 movem números crus.

### BLOCO 4 — SALTOS + METAMORFOSE: inteligência avançada + organismo soberano
**Slices:** T4-S1, T4-S2, T4-S3, T4-S4, T4-S5, T4-S6, T4-S7, SIS1, SIS4
**Por que é uma unidade:** são os 7 saltos T4 da #17 (todos filhos de T2, do Bloco 2) + os 2 Sistemas decomponíveis restantes da #20 (SIS8 já foi no Bloco 1). É a camada frontier — bi-temporal, contradição dialética, procedural, contrafactual, consolidação noturna, working-memory una. Coesa porque toda ela pressupõe as três camadas anteriores prontas e é onde a **convergência T4-S1 ↔ SIS4** (mesmo eixo bi-temporal via AURG-4D) tem que ser coordenada num só bloco pra não duplicar.
**Dependências satisfeitas:** T4-*←T2✅ (Bloco 2); T4-S7←D4✅; T4-S5←D3✅; T4-S6←T4-S2 (interno); SIS4←T4-S1+D3✅; SIS1←L2✅+S3✅ (Bloco 3). Todas resolvidas.
**Tamanho:** o MAIOR (4 XL: T4-S1, T4-S7, SIS1, SIS4; resto L). Candidato a rodar em ≥2 sub-sessões se o operador quiser.
**Pronto quando o Diário mostrar:** T4-S1/SIS4 entregam um só produtor bi-temporal (as-of p95<200ms; ~30 perguntas douradas ≥90% contra git real); precision@k no controle congelado nunca regride (T4-S7, com auto-promoção autônoma etiquetada `refatoracao`, não espera operador); ≥5 playbooks −40% turns (T4-S3); working-memory una cross-device ≤10s mobile→desktop, re-explicação do operador −80% (SIS1).

---

## 3. OS PROMPTS PRONTOS

> Regra comum embutida em todos: a Carta é a governança-mãe e **venceu** os docs #17-#20 onde eles dizem "gate:evento-operador / G0 nunca auto-promove / merge sempre humano / requires_human_approval=true / fila do operador". Leia sempre a Carta como override.

### PROMPT DO BLOCO 1

```
/goal FUNDAÇÃO ACOS — Kit de Ordens + Diário de Evolução + Reversibilidade journal-first. Este é o bootstrap inviolável da linha ACOS: sem ele nada mais roda nem se audita. Autonomia TOTAL sob a Carta: você AGE, commita cada slice na main LOCAL via atlas:land (nunca git add -A; só os arquivos do slice), roda os checks automáticos como gate, e ETIQUETA cada evolução no Diário. Zero aprovação humana; os únicos controles que sobrevivem são o master switch (ATLAS_LOOP_MASTER_ENABLED) e o push remoto — você não faz push.

LEIA PRIMEIRO (fonte da verdade, nesta ordem): docs/obra-linha-acos-carta-de-autonomia-2026-07-06.md (governança-mãe — override sobre tudo abaixo), docs/obra18-acos-materia-prima-canos-kit-2026-07-06.md, docs/obra20-acos-organismo-soberano-2026-07-06.md (só a seção do Sistema 8), docs/obra17-acos-3x-plano-mestre-2026-07-06.md (só T0.1), docs/work-orders/WO-17-T0.1-retrieval-por-query.md. Antes de qualquer código chame o MCP atlas-open-brain (atlas_open_brain_context_pack) com workspace=$CLAUDE_PROJECT_DIR; se cair, use o fallback bin/atlas.

DRIFT A CORRIGIR EM TODA WO QUE VOCÊ ESCREVER OU GERAR: onde os docs dizem "gate:evento-operador", "G0 nunca auto-promove", "merge sempre humano", "requires_human_approval=true", "fila do operador" — leia o que a Carta diz: gate:automático, G0 AUTO-promove ao passar os checks, auto-merge na main local, requires_human_approval=false, fila eliminada. Isso afeta K3 (o linter NÃO deve exigir rótulo de gate-operador) e DIARIO-3.

ORDEM DAS FATIAS:
0) Rode em paralelo como COBAIAS que provam o Kit: T0.1 (a WO já está pronta em docs/work-orders/ — corrija no glossário que AtlasMemoryVectorSearchService está em app/Services/Ai/Memory/, não no root; aditivo-only, $context['query'] opcional, ausente=byte-idêntico; vire config/atlas.php ~:4068 para true no MESMO commit; fallback sqlite pelo driver da CONNECTION do model, nunca config('database.default')); C1 (bug provado: OpenBrainProjectionAdapter::translateRefs() em app/Services/Ai/Programming/AtlasDev/Discovery/, :158-214, descarta title/summary e entrega URI opaco — inline title+summary provider-safe como PromptSectionsMapper já fez 2×; reusar guard de sendability; incluir RepairPromptComposer); D1 (re-hidratar as 44 memórias ativas de stub→corpo real a partir de docs canônicos+specs+git — quê+PORQUÊ+quando+evidence ref; remover marcador de truncamento); D2 (Stop hook grava learning estruturado claim+porquê+arquivos+evidence em vez de mb_substr(280); preencher post_execution_update do APCR).
1) SIS8 PRIMEIRO da fundação de reversibilidade: journal-first append-only hash-chained reusando o padrão de app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php (que HOJE não cobre memória) + criar atlas:brain:replay (NÃO existe). É a licença que substitui a aprovação.
2) DIARIO-1 (ledger etiquetado, mesmo padrão do AtlasEvidenceLedger; campos: data, tipo, o_que, por_que, evidencia, id_reversao) → DIARIO-2 (atlas:evolucao hoje/listar/ver/reverter; reverter = git revert do commit OU replay-sem-a-entrada, depende do SIS8).
3) K1 (schema da ordem = extensão aditiva do envelope de AtlasTaskServingService ~:990: novos campos frozen_callers, acceptance_test_ref path+hash em forbidden, stop_and_return, glossary sigla→path absoluto, baseline_artifact; escrito à MÃO — o kit ainda não se auto-gera) → K3 (linter = extensão de AtlasTaskPacketQualityInspector; recusa na fonte: sigla sem path, path inexistente, aceitação fora de allowed∪forbidden, sem teste pré-escrito; SEM regra de gate-operador) → K4 (gate = extensão do admission gate v2; wired_or_tagged/no_duplicate_logic seguem; checa hash do teste intocado, diff⊆allowed, suítes dos frozen_callers verdes, sem colisão de nome de comando artisan) → K2 (scaffolder atlas:obra:work-order <slice>; ATENÇÃO colisão: já existe AtlasFrontendWorkOrderCommand — rode `php artisan list` e escolha nome que não colida; gera a ordem do doc, auto-preenche frozen_callers via code graph, VALIDA que todo path/símbolo citado existe).
4) DIARIO-3 por ÚLTIMO (wiring de auto-promoção: G0 auto-promove→entrada promocao-memoria; auto-merge após K4 verde→entrada merge; graduação/aposentadoria de automação; requires_human_approval true→false na auto-construção→entrada orgao-novo; cada ponto emite entrada no mesmo ato). Só é seguro sob SIS8+AtlasTaskScopedCommitter — já entregues acima.

CHECK/GATE (roda sozinho antes de cada atlas:land): php -l + pint --test + phpstan nos paths tocados; testes dos frozen_callers verdes; para SIS8 o kill-test DROP DATABASE→atlas:brain:replay <5min→content_hash 100% idêntico; RTO ≤15min.

CONCLUSÃO: o Diário (atlas:evolucao hoje) mostra as 12 fatias etiquetadas; T0.1 foi mergeado com uma WO GERADA pelo K2 (prova o kit end-to-end, não à mão); o kill-test do SIS8 passou; K2 pegaria os 2 falsos-fantasmas se recebesse um doc com path inexistente. Commite cada slice separado na main local; não faça push.
```

### PROMPT DO BLOCO 2

```
/goal ESPINHA COGNITIVA ACOS (obra #17 T0-T3) — transformar "tem retrieval" em "retomei e ele sabia / lembra por quê e avisa antes / qualquer repo". PRÉ-REQUISITO já entregue no bloco anterior: Kit K (K1-K4), Diário (atlas:evolucao), SIS8, e T0.1 (retrieval por query, default true). Se atlas:evolucao ou atlas:obra:work-order não existirem, PARE e reporte — este bloco não roda sem a fundação.

Autonomia TOTAL sob a Carta: aja, gere a WO de cada slice com atlas:obra:work-order ANTES de implementar (regra de ouro: nenhum slice sem WO), commite cada um na main local via atlas:land (só os arquivos do slice), rode os checks como gate, etiquete cada evolução no Diário. Zero aprovação; só master switch + push (você não faz push).

LEIA: docs/obra-linha-acos-carta-de-autonomia-2026-07-06.md (override), docs/obra17-acos-3x-plano-mestre-2026-07-06.md (T0-T3). Chame atlas_open_brain_context_pack (workspace=$CLAUDE_PROJECT_DIR) antes de codar.

DRIFT: T0.2 — o "protocolo de 20 edições / gate:evento-operador" foi REVOGADO pela Carta; agora o Atlas MEDE sozinho e publica o hit-rate como entrada evolucao-de-fase no Diário. Nada espera evento externo.

ORDEM (encadeada):
1) T0.2 (S): medir o guard que JÁ existe — AtlasOpenBrainGuardService::decisionViolationCheck() em app/Services/Ai/AtlasOpenBrainGuardService.php:243 (chamado :160) via .claude/hooks/atlas-pretooluse-guard.sh; publica hit-rate antes/depois do T0.1 no Diário. T0.3 (S): alerta de heartbeat no UserPromptSubmit — artefato storage/atlas/scheduler/heartbeat.jsonl (lido por AtlasAcosEvolutionScoreService::heartbeatFresh); landing em .claude/hooks/ (~5 linhas); fail-OPEN sempre; dedup a linha (hooks às vezes 2× no settings.json); rg em storage/ exige --no-ignore. T0.4 (S): publicar a tabela de baselines-âncora (TPE de retomada, perguntas evitáveis, colisões tardias) — MEDIÇÃO real, proibido fabricar TPE; entrada no Diário.
2) T1 (L): estado por obra em storage/atlas/obras/<obra-id>.json escrito pelo Stop hook; obra ativa via storage/atlas/obras/current (comando explícito, NUNCA inferida). Religar LongHorizonContinuityPackEmitterService (app/Services/Ai/LongHorizon/, órfão) SÓ com call site consumidor NOMINAL + teste que prova a seção presente — NÃO "estender o harvester". Matcher de refutação na admissão. Gate de retomada ≤3 turns.
3) T2 (L): brief determinístico v1 SEM LLM (template por comando: módulos por churn, invariantes=decisões pétreas do registry, refutações, arquivos quentes por git log, generated_at+hash do HEAD; stale⇒pack injeta "BRIEF STALE"). Delta de surpresa: campo estruturado no Stop hook⇒candidato G0. P3 crítica de spec: evento-driven, municiado com brief+refutações. Gate: perguntas evitáveis −≥50%.
4) T3 (L): multi-projeto (workspace activate → index-code + brief mínimo automático; retriever já é workspace-scoped, falta o disparo). Raio de explosão determinístico consultando o code graph existente (call graph afetado + testes que cobrem + decisões tocadas). Gate: cobre ≥85% dos arquivos tocados em 10 slices reais.

CHECK/GATE (sozinho antes de cada land): php -l + pint --test + phpstan; suítes dos frozen_callers verdes; os gates numéricos de cada slice (retomada ≤3 turns, evitáveis −≥50%, cobertura ≥85%) medidos e etiquetados.

CONCLUSÃO: atlas:evolucao hoje mostra T0.2/T0.3/T0.4/T1/T2/T3 etiquetados com seus números batidos; baselines-âncora publicados; brief determinístico aparece no pack de uma sessão real. Commite cada slice separado; sem push.
```

### PROMPT DO BLOCO 3

```
/goal MOTOR DE ENTREGA ACOS (obra #18 C/D + obra #19 inteira) — multiplicar a memória viva em TODA superfície de execução + encanamento de prova/landing/sessão que torna a entrega barata e serializada. PRÉ-REQUISITO já entregue: Kit K, Diário, T0.1, C1, D1, D2 (bloco 1) e T1 (bloco 2). Se faltar atlas:obra:work-order, atlas:evolucao ou o brief do T1, PARE e reporte.

Autonomia TOTAL sob a Carta: gere WO por slice antes de implementar, commite cada um na main local via atlas:land (só arquivos do slice), checks como gate, etiquete no Diário. Zero aprovação; só master switch + push.

LEIA: docs/obra-linha-acos-carta-de-autonomia-2026-07-06.md (override), docs/obra18-acos-materia-prima-canos-kit-2026-07-06.md (C/D), docs/obra19-modelo-5x-motor-de-entrega-2026-07-06.md (P/L/S). Chame atlas_open_brain_context_pack antes de codar.

DRIFT: C3 — a nota "G0 continua nunca auto-promovendo" está OBSOLETA; G0 AUTO-promove ao passar os checks e etiqueta promocao-memoria no Diário (mantendo verify-then-absorb como auto-check).

DUAS ESCADAS PARALELAS (só serializam onde tocam o mesmo arquivo):
ESCADA MEMÓRIA (#18): C2 (quinta fonte advisory relevant_memory em AtlasTaskServingService::next(), padrão fail-open :188-206; escopada pelo allowed_files; gate ≥80% dos packets em zona-com-decisão carregam-na) → D3 (religar AtlasMemoryRelationsCommand, hoje 0 uso, atlas_memory_entry_relations=0 rows; toda memória nova referencia módulo do code graph; supersede/conflita explícito; gate ≥70% das ativas com ≥1 relação) → D4 (feedback implícito no hook: citada no pack ∧ presente no diff = útil; dedup do recall dominante — 90% dos recalls = 1 entrada, o incidente wiper; gate ≥20% dos usages com feedback_action) → C3 (fechamento simétrico: qualquer superfície emite candidato G0 pelo canal do Stop hook, delta-de-surpresa como filtro) → D5 por ÚLTIMO (recalibrar AtlasMemoryQualityService que dá 93/100 ao estado wiper; novos componentes: taxa título=resumo, taxa com-porquê, densidade de relações, fill de feedback, verificáveis por SQL; gate: score sobre o estado de HOJE ≤50, sobe só quando D1-D4 movem os números crus).
ESCADA PROVA/LANDING (#19): P1 (pre-gate atlas:pregate ≤3s; FIX CRÍTICO: phpstan CRASHA na invocação padrão — 128M no bootstrap do larastan; consertar o default e o caminho do EngineeringQualityScanService) ∥ P2 (atlas:test:impacted; ligar ProgrammingTestImpactAnalyzer às tabelas reais ai_codebase_world_model_edges via CodeGraphEdgeResolver:120; PÉTREA: recall ≥0,85 provado por atlas:programming:test-impact-benchmark ANTES de substituir suite; fallback DECLARADO "suite do módulo") → L1 (atlas:land expõe AtlasTaskScopedCommitter, fail-closed sob .git/atlas-task-commit.lock, git add -A impossível por construção; PÉTREA: nunca segurar lock durante testes; ordem task-commit→main-merge nunca inversa; gate index.lock/sessão 58→~0) → P3 (receipt-cache no gate K4: (hash teste, hash impl)→hasGreenReceipt() fresh⇒skip; substrato AtlasAaeosTestExecutionService anti-fake-green) ∥ P4 (ligar paratest ^7.20 já no composer.json; PÉTREA: só depois de sufixar TEST_TOKEN nos 3 paths compartilhados do phpunit.xml + quarentenar colisores; NÃO alterar o phpunit.xml que força sqlite :memory:; gate 329min→~50min) → S1 (fan-out sem stall: pesquisa em tipos sem ferramenta Agent, ≥2 fases⇒Workflow) ∥ S2 (sala de operações no bootstrap: estender AtlasAiSessionBootstrapCommand CONSUMINDO o arquivo de obra do T1, não duplicando; brief ≤3k chars) → L2 (blackboard visível ao serving: AtlasAobgBlackboardService; PreToolUse registra claim por arquivo com TTL; serving consulta no lease, path com claim de outro engine⇒packet adiado fail-open; DRIFT: é visibilidade de intenção, NÃO permissão) ∥ L3 (guard do reset do soak: recusa se git status --porcelain acusa edits fora do escopo — protege auto-merge de varrer WIP alheio) → P5 (atlas:golden:freeze/check; GOTCHA: ReadinessHash::stable só ksorta top-level, canonicalização precisa de ordenação PROFUNDA; substrato MissionCanonicalHash::sha256) ∥ P6 (PÉTREA anti-wiper: trocar symlink de vendor por cp -Rc clonefile em GovernedBranchMaterializationService::linkRuntimeDeps :648-660 e EngineeringWorkspaceService :634-650; .env hermético gerado nunca symlinkado; gate: composer dump-autoload em worktree nunca reescreve autoload vivo) → S3 (session-state.json efêmero re-injetado só após compactação; anti-eco por proveniência H2.5a) ∥ S4 (frontier↔barato intra-sessão + dieta do hook atlas-ctx.sh: dedupe por hash da última injeção + skip em prompt operacional).

CHECK/GATE (sozinho antes de cada land): atlas:pregate ≤3s; os gates PÉTREOS (P2 recall ≥0,85 provado antes de trocar suite; P4 sqlite intocado; P6 dump-autoload não reescreve autoload vivo — teste adversarial) são bloqueantes; suítes dos frozen_callers verdes.

CONCLUSÃO: atlas:evolucao hoje mostra os 18 slices etiquetados; suite roda em ~50min sob 8 workers sem DB-zero; atlas:land serializou os commits (index.lock/sessão→~0); D5 dá ≤50 ao estado de hoje; teste adversarial do P6 prova que o vetor wiper morreu. Commite cada slice separado; sem push.
```

### PROMPT DO BLOCO 4

```
/goal SALTOS + METAMORFOSE ACOS (obra #17 saltos T4 + obra #20 Sistemas 1 e 4) — a camada frontier: bi-temporal, contradição dialética, procedural, contrafactual, consolidação noturna, working-memory una. PRÉ-REQUISITO: blocos 1-3 inteiros (Kit, Diário, SIS8, T0-T3, motor de entrega, L2, S3, D3, D4). Se faltar T2, D3, D4, L2 ou S3, PARE e reporte — todo salto pressupõe a espinha pronta.

Autonomia TOTAL sob a Carta: WO por slice antes de implementar, commit por slice na main local via atlas:land, checks como gate, etiqueta no Diário. Zero aprovação; só master switch + push. Este é o bloco mais pesado (4 XL) — trate cada salto como uma sub-entrega própria e commite fatiado; se a sessão ficar longa, pare num salto completo e reporte progresso no Diário para retomar limpo.

LEIA: docs/obra-linha-acos-carta-de-autonomia-2026-07-06.md (override), docs/obra17-acos-3x-plano-mestre-2026-07-06.md (saltos T4), docs/obra20-acos-organismo-soberano-2026-07-06.md (Sistemas 1 e 4). Chame atlas_open_brain_context_pack antes de codar.

DRIFT: T4-S7 — "auto-revert por métrica" já é a intenção da Carta, agora AUTÔNOMO: auto-promoção + entrada refatoracao no Diário, NÃO espera operador.

CONVERGÊNCIA OBRIGATÓRIA (não duplicar): T4-S1 (#17, modelo bi-temporal via AURG-4D: stateAt/traverseTime prontos, sem produtor) e SIS4 (#20, grafo bi-temporal via atlas_aurg_edges + HasTemporalTruth em app/Support/TemporalTruth/, já aplicado a AtlasMemoryEntry+AtlasDecisionReceipt) atacam o MESMO eixo. Implemente T4-S1 como o PRODUTOR bi-temporal e faça SIS4 CONSUMIR/estender esse produtor (arestas 4D em atlas_aurg_edges, porquê-como-aresta, strangler prosa→grafo, compilador pergunta→plano JSON whitelisted) — nunca reconstruir. NÃO confundir T4-S4 (recall conversacional/MCP, salto S4 da #17) com SIS4 (bi-temporal da #20): ids distintos, escopos distintos.

ORDEM:
1) Filhos diretos de T2 (paralelizáveis entre si onde não compartilham arquivo): T4-S2 (surpresa como gate de gravação; substrato TEOS-I3 registrador de apostas sem consumidor, ProductiveFailureComparisonEngine; gate: alta-surpresa usada ≥2× em 30d, candidatos G0 −≥40% sem perda de precision) → T4-S6 (replay contrafactual, calibra o crítico do P3; substrato TEOS-I3 branches + AtlasEvidenceLedger; gate: curva de calibração após 5 obras). T4-S3 (memória procedural: playbook testado por classe; substrato RepairBrain corpus + AEMOR distill; gate ≥5 playbooks, −40% turns). T4-S4 (recall conversacional MCP com estado + mapa de incerteza; p95<2s nunca bloqueia; gate ≥50% das consultas mid-session usadas). T4-S5 (motor de contradição dialético; substrato refutation matcher + Memory Relations do D3; gate precisão ≥70%, zero packs entregando 2 lados de tensão aberta sem marcar).
2) T4-S1 (XL): PRODUTOR bi-temporal sobre AURG-4D + code graph; gate: 30 perguntas temporais ≥85% contra o git real. Depende de T3.
3) T4-S7 (XL): consolidação noturna (misses→hard negatives, re-ranker re-treina); substrato ARFL capture (app/Services/Ai/Context/AtlasRetrievalFeedbackLoopService.php) + AREBA + semantic_rag venv; gate: precision@k no controle congelado NUNCA regride; auto-promoção autônoma etiquetada refatoracao.
4) SIS4 (XL): CONSOME o produtor do T4-S1; gate: as-of p95<200ms, ~30 perguntas douradas ≥90%.
5) SIS1 (XL): Working Memory Una — AtlasCognitiveWorkingSetMemoryService (app/Services/Ai/CognitiveMemory/, hoje array in-process sem consumidor) vira estado persistido escopo operador+projeto, lido/escrito pelos hooks; fato no mobile chega ao desktop ≤10s via SSE; intenções em voo reusam o blackboard do L2; postura de projeto = vetor derivado de EVIDÊNCIA (gates vermelhos/incidentes), zero opinião de modelo; gate: re-explicação do operador −80%.

CHECK/GATE (sozinho antes de cada land): atlas:pregate; os gates numéricos de cada salto medidos contra dados reais (git real para o bi-temporal, controle congelado para T4-S7 — regressão de precision BLOQUEIA e auto-reverte); suítes dos frozen_callers verdes.

CONCLUSÃO: atlas:evolucao hoje mostra os 9 slices etiquetados com números batidos; existe UM só produtor bi-temporal (T4-S1) que SIS4 consome (as-of p95<200ms); T4-S7 provou que precision@k no controle nunca regrediu; SIS1 mostra fato mobile→desktop ≤10s. Commite cada salto separado; sem push.
```

---

## 4. ORDEM E PARALELISMO

**Estritamente sequencial (não fure):** Bloco 1 → Bloco 2 → Bloco 3 → Bloco 4. A cadeia de dependência é dura:
- Bloco 1 é o bootstrap: sem `atlas:obra:work-order` (K2) e `atlas:evolucao` (DIARIO), os blocos 2-4 não conseguem nem gerar WO nem etiquetar — cada prompt tem um **PARE-e-reporte** no topo justamente pra falhar rápido se rodado fora de ordem.
- Bloco 2 (T1, brief) é insumo do Bloco 3 (S2 consome o arquivo de obra; C2/D3/D4 usam retrieval).
- Bloco 4 é filho de T2 (Bloco 2), D3/D4/L2/S3 (blocos 2-3).

**Paralelismo real disponível (sessões separadas):**
- **Dentro do Bloco 1**, as cobaias T0.1∥C1∥D1+D2 são independentes entre si — o prompt já manda rodá-las em paralelo no fan-out multi-agente. Mas o *bloco* como um todo é pré-requisito de tudo.
- **Bloco 3 tem duas escadas paralelas por construção** (#18 memória ∥ #19 prova/landing) — só serializam onde tocam o mesmo arquivo. Se o operador quiser, pode partir o Bloco 3 em **dois `/goal` simultâneos** (um "escada-memória C/D", um "escada-prova P/L/S") — eles quase não colidem em arquivo, e a main serializada (L1/atlas:land) protege o merge. Isso viraria 5 prompts; mantive 4 porque um só `/goal` autônomo dá conta das duas escadas com o fan-out interno, e menos sessões = menos handoff.
- **Bloco 4** é o único candidato honesto a **quebrar em 2 sub-sessões** por peso (4 XL): "saltos T4" e depois "SIS1+SIS4". O prompt já instrui a parar num salto completo e reportar no Diário pra retomar limpo — então o operador pode rodar o mesmo prompt 2×, ou partir em dois, sem replanejar.

Resumo: **4 sessões sequenciais** é o caminho seguro. O operador pode ganhar paralelismo oportunista partindo o Bloco 3 em 2 (→5) ou o Bloco 4 em 2 (→5), mas nunca deve paralelizar *entre* blocos.

---

## 5. RISCOS de dividir assim + proteção embutida

**R1 — Drift dos planos (a Carta venceu #17-#20).** Os docs-fonte ainda dizem "gate humano / G0 nunca auto-promove / merge sempre humano". Se um `/goal` lê o doc literalmente, reintroduz gates que a Carta matou.
*Proteção:* todo prompt abre lendo a Carta como **override** e traz um bloco `DRIFT:` explícito com os pontos exatos que cada bloco toca (K3/DIARIO-3 no B1; T0.2 no B2; C3 no B3; T4-S7 no B4). O drift está inline, não como nota de rodapé.

**R2 — Bootstrap furado (implementar sem a fábrica de ordens).** Se o operador rodar B2/B3/B4 antes do B1, não há `atlas:obra:work-order` nem Diário — os slices ficam sem WO (viola a regra de ouro) e sem trilha de auditoria.
*Proteção:* B1 tem as duas fundações inteiras (Kit + Diário + SIS8) e prova o Kit end-to-end (T0.1 mergeado com WO **gerada**, não à mão). B2/B3/B4 abrem com **"se faltar `atlas:obra:work-order`/`atlas:evolucao`, PARE e reporte"** — falha barulhenta em vez de silenciosa.

**R3 — Colisão na main local.** Blocos (ou o Bloco 3 partido em 2) rodando com fan-out multi-agente podem pisar no mesmo arquivo.
*Proteção:* a main agora é **serializada e etiquetada** por construção — `atlas:land`/`AtlasTaskScopedCommitter` (L1) é fail-closed sob `.git/atlas-task-commit.lock`, `git add -A` é impossível, commit é por pathspec do slice. L2 dá visibilidade de intenção (blackboard) e L3 guarda o reset do soak contra varrer WIP alheio. Ordem de lock `task-commit→main-merge` (nunca inversa) e "nunca segurar lock durante testes" estão como PÉTREAS nos prompts. Todo prompt manda **"commite cada slice separado, só os arquivos do slice, sem push"**.

**R4 — Convergência duplicada (T4-S1 ↔ SIS4, mesmo eixo bi-temporal).** Se caíssem em blocos diferentes, dois produtores bi-temporais nasceriam.
*Proteção:* os dois estão **no mesmo Bloco 4**, e o prompt ordena explicitamente: T4-S1 é o produtor, SIS4 consome — nunca reconstrói. Também alerta o homônimo T4-S4≠SIS4.

**R5 — Slice PÉTREO tratado como slice comum (regressão de wiper / DB-zero / suite quebrada).** P2/P4/P6 têm pré-condições invioláveis (recall ≥0,85 antes de trocar suite; sqlite :memory: intocado; cp -Rc no lugar de symlink de vendor).
*Proteção:* cada PÉTREA está marcada como bloqueante no `CHECK/GATE` do prompt do Bloco 3, com o teste adversarial nomeado (P6: "composer dump-autoload em worktree nunca reescreve autoload vivo"). O gate roda sozinho e barra o land se a PÉTREA falhar.

**R6 — Bloco 4 grande demais pra uma sessão (context blow-up).** 4 XL num só `/goal` pode estourar contexto e degradar qualidade nos últimos saltos.
*Proteção:* o prompt instrui parar num salto completo e reportar no Diário pra retomada limpa; o operador pode rodar o prompt 2× (idempotente por checar pré-requisitos) ou partir em dois. O Diário torna a retomada barata — é exatamente pra isso que ele é fundação.

---

**Arquivos-fonte reais confirmados** (todos existem, checados agora): `docs/obra-linha-acos-carta-de-autonomia-2026-07-06.md`, `docs/obra17/18/19/20-*.md`, `docs/work-orders/WO-17-T0.1-retrieval-por-query.md`, e os 6 substratos-chave. **Net-new confirmados** (não existem, serão criados): `atlas:evolucao`, `atlas:brain:replay`, `atlas:obra:work-order`. **Colisão confirmada:** `app/Console/Commands/AtlasFrontendWorkOrderCommand.php` já existe — o prompt do B1 manda rodar `php artisan list` e escolher nome sem colisão para o K2.