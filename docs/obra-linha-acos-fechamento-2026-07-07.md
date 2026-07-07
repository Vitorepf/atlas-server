# Fechamento da linha ACOS #15→#20 — o que falta para 100% (07/07)

> **Governança:** Carta de Autonomia (`obra-linha-acos-carta-de-autonomia-2026-07-06.md`) — age sozinho na main local, commita e etiqueta no Diário; sem aprovação. Este doc é a spec do prompt de FECHAMENTO (idempotente/resumível).

Estado verificado por auditoria adversarial (memória `acos-blocos-1a4-audit-07-07`): **37/45 slices wired, Bloco 4 só 1/9, piso de honestidade intacto (zero fake-green).** Falta: (A) os 8 saltos restantes do Bloco 4; (B) 5 consertos nos blocos 1-3 (órgãos wired-mas-sem-produtor + P6 parcial + C2 não-medido + instrumentos ausentes).

## Regra do fechamento: resumível, não one-shot
Este é o closer. Cada run: checa `atlas:evolucao listar` + git log pelo que já landou, PULA o que está pronto, continua. Para num salto/conserto COMPLETO e reporta (Carta). Só está pronto quando TODO o checklist abaixo estiver verde. **Ordem: PARTE B primeiro (consertos baratos + segurança), depois PARTE A (os 8 saltos, XL no fim).**

## PARTE B — consertos dos blocos 1-3 (fazer primeiro)

- **B1 🔴 P6 GLOBAL (segurança nº1).** O P6 fechou o vetor-wiper só em 2 provisioners. Ainda fazem `@symlink(vendor)` (confirmado por rg): `AtlasRepoVerifiedDeliveryService.php:475`, `Rivals/Adapters/AtlasBenchSuiteAdapter.php:493`, `AutonomousEvolution/AtlasLoopFrameworkMaterializer.php:260`. Varrer `rg -n --no-ignore 'symlink\([^)]*vendor'` e trocar TODOS por `cp -Rc` clonefile (reusar o helper do GovernedBranchMaterializationService/EngineeringWorkspaceService — não reimplementar). Teste adversarial por provisioner: `composer dump-autoload` em worktree NUNCA reescreve autoload vivo da fonte. É o vetor do incidente 15/06→02/07.
- **B2 Produtores dos órgãos dormentes** (o gap real; conserto = criar o PRODUTOR, não o órgão). (a) Kit K2/K3/K4 dormentes: um produtor que enfileira kit-orders na fila viva (AtlasTaskServingService) para as regras saírem do fail-safe-pass. (b) DIARIO-3: `memoryPromoted/newOrgan/graduated/retired` têm 0 callers — ligar cada evento G0 real (promoção de lição, órgão novo da auto-construção, graduação) à chamada correspondente, emitindo entrada no Diário no mesmo ato. (c) D4: `atlas:memory:feedback-implicit` NÃO está wired — plugar no Stop hook/schedule (a automação é o valor; hoje só roda `--apply` à mão). (d) D3: rodar o backfill (grafo memória↔código está 0-row) + hook de auto-relação em memória nova; dedup com a tool de supersede já em `AtlasOpenBrainMcpService`. (e) T1: nascer um produtor obra-scoped de continuity (hoje `latestContinuityFor('obra',id)` é sempre `null`) OU remover o wire decorativo — não deixar mentira nominal.
- **B3 C2 gate REAL.** O teste é shape-only (enfileira sem memória semeada → passa vazio). Escrever teste que semeia decisão na zona e prova recall ≥80%; se não bater, é achado honesto — sinalizar no status doc (que hoje apresenta C2 como pronto SEM sinalizar o gap, ao contrário do D5).
- **B4 Instrumentos ausentes (senão os gates do #17 ficam imensuráveis):** TPE (turns-até-1ª-edição-correta) e contador de perguntas-evitáveis / `atlas valor semana`. Medição real, proibido fabricar.
- **B5 Vocabulário proibido no código:** `AtlasBenchSuiteAdapter` (pasta `Rivals/`) e o serviço de recall do P2 carregam termos proscritos. Renome é escopo separado (toca chaves) — NÃO no mesmo commit de outro slice; fazer como slice próprio com callers congelados por rg, ou registrar no Diário como dívida se arriscado.

## PARTE A — os 8 saltos do Bloco 4 (design já mapeado)

Fonte da verdade do design: memória `saltos-acos-t4-checkpoint.md` (mapa de substrato + design dos 8, salvo pelo T4-S2). Começar em **T4-S6**. Ordem: T4-S6 → T4-S3 → T4-S4 → T4-S5 → (XL) T4-S1 → T4-S7 → SIS4 → SIS1. Detalhes de gate no `docs/obra17-...` (T4) e `docs/obra20-...` (SIS1/SIS4).

- **VETO #20 pétreo:** proibido landar órgão órfão — **ou wira inteiro (produtor+consumidor no mesmo commit), ou não landa.**
- **CONVERGÊNCIA obrigatória (não duplicar):** T4-S1 é o PRODUTOR bi-temporal (AURG-4D); SIS4 CONSOME/estende esse produtor (não reconstrói). Landar T4-S1 antes de SIS4. NÃO confundir T4-S4 (recall conversacional/MCP) com SIS4 (bi-temporal #20).
- Gates (do checkpoint/docs): T4-S2 já feito; T4-S6 calibra o crítico P3 (stateless) + hook no closeObra() + organ de calibração; T4-S1 30 perguntas temporais ≥85% vs git real; T4-S7 precision@k no controle congelado NUNCA regride (auto-promoção etiquetada `refatoracao`); SIS4 as-of p95<200ms; SIS1 fato mobile→desktop ≤10s.

## PARTE C — drift de governança (achado cross-model, 07/07)

- **C-DRIFT1 🔴 `operator_signature` contradiz a Carta.** `AtlasAcosEvolutionScoreService.php:196,202` dá 1.0 dos 2.5 pontos de `execucao_governada` a `$chain['operator_signed']`, e emite `operator_signature=awaiting_operator_signature` quando não-assinado. A Carta REVOGOU a assinatura do operador → esse ponto fica perdido para sempre → autonomia/execução nunca atingem 10 por evidência (score vivo trava em ~9.0/9.07). Fix: sob a Carta, o crédito desse 1.0 deve vir do **equivalente autônomo** — auto-promoção + entrada no Diário + reversibilidade (SIS8), não de assinatura humana. Trocar `operator_signed` pela prova autônoma em `tierChainReadiness()`/`execucao_governada`, com teste que fixa "score não espera assinatura". É o mesmo flip que a propagação de autonomia fez nos docs (commit `d83fbd46f2`) mas esqueceu neste instrumento de código.
- **Nota:** gates de valor vivo (#18 D3 densidade ≥70%, D5 ≤50 por dados reais, D4 feedback fill, #19 P4 full-suite + métricas 5× sustentadas) exigem JANELA de dados real — são "código completo, prova pendente", não incompletos. Medir na cadência, não forçar.

## Pétreas (invioláveis)
Aditivo-only em símbolo público (sem callers congelados = PARE); testes NUNCA no pgsql vivo (phpunit.xml força sqlite :memory:, não altere); todo path/símbolo verificado por rg (`--no-ignore` em storage/); commit só dos arquivos do slice via `atlas:land`, nunca `git add -A`, nunca push; vocabulário proibido: Jarvis, Rivals, benchmark, superiority, concurrent.

## Pronto quando (checklist)
`atlas:evolucao listar` mostra: os 8 saltos etiquetados + B1-B4 etiquetados; `rg 'symlink\([^)]*vendor'` = 0 hits; C2 com teste que semeia e prova ≥80% (ou gap sinalizado); D3 grafo com rows reais; D4 disparando no hook; UM só produtor bi-temporal (T4-S1) que SIS4 consome; TPE e perguntas-evitáveis publicados. Só então a linha #15→#20 (versão dos 4 blocos) está 100%.
