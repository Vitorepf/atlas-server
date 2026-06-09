---
id: atlas-unified-evolution-loop
type: engineering_knowledge
title: Atlas Unified Evolution Loop
doc_schema: atlas_canonical_module_doc.v1
graph_layer: system
status: active
category: autonomous-evolution
priority: 100
summary: Supervisor propose-only que usa busca sob verificadores frozen para evoluir codigo, docs e P4 pequeno do Atlas com gates deterministas, refutadores e revisao humana.
tags:
  - atlas-ai
  - autonomous-evolution
  - p4
  - verifier-frozen
  - propose-only
capabilities:
  - unified_evolution_loop
  - frozen_verifier_search
  - p4_small_certification
  - intent_verifier_factory
  - independent_proposal_reverification
decisions:
  - O loop nunca mergeia; proposta certificada significa pronta para revisao humana.
  - P4 pequeno so entra quando existir verificador frozen RED antes do provider e certificado semantico depois do candidato.
  - Intent Verifier Factory falha fechado quando a intencao nao tem atomo executavel estreito.
maintenance:
  - Atualizar quando mudarem modos, gates, comandos do loop, materializacao P4 ou atomos do Intent Verifier Factory.
  - Rodar testes focados, PHPStan, sync da KB e Code Intelligence apos alteracoes de codigo/doc canonico.
related_paths:
  - app/Services/Ai/AutonomousEvolution/AtlasLoopIntentVerifierFactory.php
  - app/Services/Ai/AutonomousEvolution/AtlasLoopSemanticImplementationCertifier.php
  - app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php
  - app/Services/Ai/AutonomousEvolution/Verify/AtlasEngineeringHonestyGate.php
  - app/Console/Commands/AtlasLoopCompileVerifierCommand.php
  - app/Console/Commands/AtlasLoopCertifyImplementationCommand.php
  - tests/Unit/Ai/AutonomousEvolution/AtlasLoopIntentVerifierFactoryTest.php
  - tests/Feature/Loop/AtlasLoopGrindTaskCommandTest.php
graph_id: atlas-unified-evolution-loop
graph_title: Atlas Unified Evolution Loop
graph_world: atlas
graph_kind: module
graph_parent: atlas-ai-canonical-architecture-index
graph_status: active
graph_source: repo
repo_paths:
  - docs/engineering-knowledge-base/atlas-unified-evolution-loop.md
  - app/Services/Ai/AutonomousEvolution/AtlasLoopIntentVerifierFactory.php
  - app/Services/Ai/AutonomousEvolution/AtlasLoopSemanticImplementationCertifier.php
  - app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php
allowed_changes:
  - Atualizar modos, gates, comandos, materializacao P4 e atomos do Intent Verifier Factory quando codigo/testes mudarem.
forbidden_changes:
  - Declarar merge automatico, P4 amplo autonomo ou intencao semantica generica sem verificador RED e revisao humana.
  - Rebaixar gates propose-only, RED-preflight, revert-recheck ou refutadores obrigatorios.
depends_on:
  - atlas-ai-knowledge-governance-system
  - atlas-code-reality-usage-intelligence
  - atlas-software-twin-verified-evolution-runtime
flows_to:
  - atlas-forge
  - atlas-engineering-knowledge-base
unlocks:
  - p4-small-propose-only-loop
  - independently-verified-proposal-queue
governs:
  - unified-evolution-loop
  - p4-small-certification
evidence:
  - tests/Unit/Ai/AutonomousEvolution/AtlasLoopIntentVerifierFactoryTest.php
  - tests/Feature/Loop/AtlasLoopCompileVerifierCommandTest.php
  - tests/Feature/Loop/AtlasLoopGrindTaskCommandTest.php
required_tests:
  - "php artisan test tests/Unit/Ai/AutonomousEvolution/AtlasLoopIntentVerifierFactoryTest.php tests/Feature/Loop/AtlasLoopCompileVerifierCommandTest.php tests/Feature/Loop/AtlasLoopGrindTaskCommandTest.php"
  - "php -d memory_limit=3G vendor/bin/phpstan analyse --no-progress --level=5 app/Services/Ai/AutonomousEvolution/AtlasLoopIntentVerifierFactory.php app/Console/Commands/AtlasLoopCompileVerifierCommand.php tests/Unit/Ai/AutonomousEvolution/AtlasLoopIntentVerifierFactoryTest.php tests/Feature/Loop/AtlasLoopCompileVerifierCommandTest.php tests/Feature/Loop/AtlasLoopGrindTaskCommandTest.php"
requires_evidence: true
risk_level: high
next_actions:
  - Expandir Intent Verifier Factory para DB-state com RED-preflight.
  - Adicionar refutadores externos reais por provider para P4 acima do fixture local.
owner: operator
updated: 2026-06-09
---

# Atlas Unified Evolution Loop

## Resumo

O Unified Evolution Loop é o supervisor único, propose-only, que roda os modos de
evolução baseados em verificador-frozen sobre o próprio Atlas e os interliga numa só
fila de propostas com um só relatório visível. Ele cobre, de forma honesta e auditável,
três dos quatro pontos pedidos pelo operador: varredura de código em busca de código
morto (P1/P3), varredura de documentação para completar módulos canônicos (P2) e
varredura de código-versus-documentação para achar implementação falsa (P3). Ele também
emite sinais read-only de clones, hotspots de complexidade, gaps de cobertura, doc-drift
e duplicação de docs como backlog de julgamento. O quarto ponto — implementações grandes
(P4) — entra como backlog roteado para o humano/Forge, nunca executado às cegas. Nada é
mergeado: o loop só propõe; um humano revisa. A fila também recebe uma camada read-only
de inteligência (`AtlasLoopIntelligenceOverlay`) que calcula prioridade por impacto,
aprende com feedback humano apenas como peso de fila, mostra matriz de providers e lista
slots cross-domínio sem executar nada automaticamente.

Para P4 pequeno, o loop agora possui `Semantic Implementation Certification`: uma
camada final que compõe o gate P4 determinístico (`evaluateImplementation`), o painel
adversarial determinístico, refutadores externos/provider-safe quando configurados e um
recibo JSON. A saída continua propose-only: certificado significa "pronto para revisão",
nunca merge automático.

Para o mesmo P4 pequeno, o loop também possui `Intent Verifier Factory`: antes de
chamar provider, o Atlas compila uma intenção estreita em teste frozen executável,
prova que esse teste nasce RED no baseline, permite refutadores externos contra o
próprio verificador e só então entrega `acceptance.commands` ao grinder. Intenção
ambígua falha fechada; o compiler não inventa comportamento.

A garantia de qualidade espelha o loop de trading: cada vencedor do juiz frozen ainda
precisa passar por um holdout independente (`AtlasEngineeringHonestyGate`) que o
candidato nunca otimizou, antes de virar proposta certificada-para-revisão.

## Papel no Atlas

É o "motor de convergência" que mantém código e documentação honestos, consistentes e
sem código morto, de forma autônoma e contínua, durante 24h+, sempre propondo e nunca
aplicando. Os providers (hermes_cli por padrão, provider-agnóstico) são apenas o músculo
que produz o candidato; o cérebro — descoberta, verificador frozen, gate de honestidade,
governança propose-only — é do Atlas.

## Onde Se Encaixa

Consome o motor de busca existente (`AtlasEvolutionScenarioExplorer`,
`AtlasEvolutionFrozenJudge`, `AtlasEvolutionLoopRunner`) — não o reescreve. Roda ao lado
da campanha de código de teste-gerado (`atlas:loop:campaign`, modo P1/P4) e dobra o
status dela no mesmo painel. O dispatcher `AtlasP3FindingDispatcher` é a ponte que liga
os quatro pontos: uma varredura emite achados tipados que ou fecham aqui (auto-loop) ou
são roteados para o arm certo (humano/Forge para implementação e julgamento).

## Contratos

- Entrada: raiz do repo + lista de modos (`deadcode`, `docs_structure`) + provider.
- Verificadores frozen (aceitação por-arquivo, exit 0 iff limpo):
  - `atlas:code:deadcode-check --path=` imprime `ATLAS_DEADCODE=<n>`.
  - `atlas:docs:lint-file --path=` imprime `ATLAS_DOC_VIOLATIONS=<n>`.
  - `atlas:docs:reality-check-file --path=` imprime `ATLAS_DOC_PHANTOM=<n>` (discovery/flag).
  - `AtlasLoopSignalAnalyzer` roda como discovery read-only e emite flags
    `code_clone`, `complexity_hotspot`, `coverage_gap`, `doc_drift` e
  `doc_duplicate`.
- Saída: `report.json` (utilização/aproveitamento), `proposals.jsonl` (certificadas),
  `independently_verified.jsonl` (re-provas em checkout limpo), `refuted.jsonl`
  (re-provas recusadas), `rejected.jsonl` (com razões), `backlog.json`
  (flags por modo). Invariante: `merged_to_main: false` sempre.
- Certificação P4 pequeno: `AtlasLoopSemanticImplementationCertifier` emite
  `atlas.loop.semantic_implementation_certification.v1` com gate determinístico,
  painel adversarial, refutadores externos, razões fail-closed e invariantes
  `proposal_only=true` / `merged_to_main=false`.
- Compiler P4 pequeno: `AtlasLoopIntentVerifierFactory` emite
  `atlas.loop.intent_verifier_factory.v1` com teste frozen, acceptance, holdouts,
  RED-preflight e refutadores do próprio verificador. O pacote só fica `ready`
  quando o baseline sem implementação é RED.
- Inteligência de fila: `backlog.json.intelligence` e `report.json.intelligence`
  carregam `impact_score`, clusters por causa provável, resumo de feedback,
  `provider_matrix` advisory e `cross_domain_slots`.
- Feedback humano: `atlas:loop:review-feedback` grava `review_feedback.jsonl`
  append-only no run; esse sinal altera prioridade futura, nunca aplica proposta.
- Liveness: `atlas:loop:unified:supervisor` combina `heartbeat.json`, idade de
  `report.json` e scan de processo PHP real. Um `report.status=running` sem worker
  PHP vira `stale_running`, nunca "saudável".
- Supervisao 24h local: `atlas:loop:unified:install-launchd` instala um LaunchAgent
  do macOS com `KeepAlive`, `RunAtLoad`, logs dedicados e retomada pelo mesmo
  `--run-id`. O processo reiniciado continua propose-only e respeita o kill-switch.

## Fluxo

1. Scan (dispatcher) → achados auto-loop (deadcode, docs_structure) + flags
   (phantom, clone, complexidade, cobertura, doc-drift, duplicação de docs).
2. Para cada achado não-visto: monta task métrica → `AtlasEvolutionLoopRunner` (N cenários,
   juiz frozen pega o melhor) → reconstrói o conteúdo proposto pelo diff → holdout no
   `AtlasEngineeringHonestyGate`.
3. Certifica-para-revisão só se o gate aprovar; senão registra rejeição com a razão exata.
4. Persiste, atualiza o relatório, faz heartbeat. Repete por ciclos até o budget de tempo,
   o kill-switch (`storage/atlas/loop/unified/STOP`) ou a varredura drenar.
5. Re-prova posterior: `atlas:loop:verify-proposals` cria worktree limpo por proposta,
   reconstrói o diff `target.php/target.md`, re-roda o verificador frozen, prova
   revert-to-RED e grava o veredito independente.
6. Overlay de inteligência: calcula `impact_score`, incorpora feedback humano,
   expõe provider matrix e slots cross-domínio. A saída só reordena/explica a fila.
7. Intent → verificador: quando uma task framework pede `intent_verifier_factory`
   ou não traz `acceptance.commands`, o grinder compila o pacote frozen a partir
   de intenção + alvo + átomo executável (`method_return`, `command_output`,
   `http_response`, `event_dispatched` ou `job_dispatched`), roda
   RED-preflight em worktree materializada e persiste o resumo.
8. P4 pequeno: o grinder materializa Laravel em worktree, roda a busca, reaplica o diff
   vencedor em uma worktree limpa de gate e só mantém a proposta se o certificado
   semântico passar. Se `provider_refuters_required > 0` e os refutadores não rodarem,
   o certificado falha fechado.

## Regras para IA

- NUNCA mergear; o loop só propõe. Três camadas abaixo do loop proíbem merge.
- Só fechar autonomamente o que é behavior-free/checável (remoção de código morto,
  seções estruturais). Phantom de doc, clone, complexidade, cobertura e doc-drift
  são julgamento → FLAG, nunca auto-editar.
- Honestidade acima de verde-falso: rejeição do gate é o sistema funcionando, não falha.
- Provider-agnóstico: nunca hardcode um provider; resolver de config/task.
- Feedback humano só pesa prioridade; não promove, não aplica e não muda provider.
- Slots cross-domínio são read-only até o operador executar o comando de verifier.
- Refutadores externos recebem somente um pacote JSON provider-safe via
  `ATLAS_SEMANTIC_REFUTER_PACKET`; qualquer refutação ou refutador obrigatório ausente
  bloqueia a certificação P4.
- O Intent Verifier Factory só compila intenções estreitas e executáveis; intenção
  ampla/sem átomo mensurável vira blocker (`no_executable_verification_atom`), não
  teste fabricado. Refutadores do verificador recebem `ATLAS_INTENT_VERIFIER_PACKET`.

## Escopo de Implementacao

Implementado e provado vivo: modo `deadcode` (analisador AST `AtlasDeadCodeAnalyzer`,
sound para membros private) end-to-end com Hermes + gate. Implementado: modo
`docs_structure` (via `AtlasDocStructureAnalyzer`), backlog `fake_implemented` (via
`AtlasDocClaimAnalyzer`, registro de comandos como ground truth) e sinais P2/P3
read-only via `AtlasLoopSignalAnalyzer`. Implementado também: prioridade por impacto,
feedback append-only, matriz de providers advisory e slots cross-domínio via
`AtlasLoopIntelligenceOverlay`. P4 pequeno tem materialização e certificado semântico
para mudanças de escopo estreito com teste frozen. O Intent Verifier Factory gera esse
teste frozen para os padrões estreitos `method_return`, `command_output`,
`http_response`, `event_dispatched` e `job_dispatched` e bloqueia o restante. Fora de escopo do auto-loop: implementações
grandes e julgamento arquitetural amplo — roteados para humano/Forge.

## Dependencias

- `AtlasEvolutionScenarioExplorer`, `AtlasEvolutionFrozenJudge`, `AtlasEvolutionLoopRunner`.
- `AtlasP3FindingDispatcher`, `AtlasEngineeringHonestyGate`.
- `AtlasLoopSignalAnalyzer` para flags de clone, complexidade, cobertura e doc drift.
- `AtlasLoopIntelligenceOverlay` e `atlas:loop:review-feedback` para prioridade,
  feedback, provider matrix e slots cross-domínio.
- `AtlasLoopSemanticImplementationCertifier` e `atlas:loop:certify-implementation`
  para certificar P4 pequeno em worktree já materializada.
- `AtlasLoopIntentVerifierFactory` e `atlas:loop:compile-verifier` para transformar
  intenção estreita em acceptance frozen antes da implementação.
- `AdversarialProofPanelService` como painel adversarial determinístico reaproveitado.
- nikic/php-parser (vendored) para a análise AST.
- Provider via Forge router (default `hermes_cli`).

## Evidencias

- Grind vivo P3-deadcode: 1 proposta certificada, propose-only, diff = exatamente o
  método morto removido (`merged_to_main:false`).
- `AtlasEngineeringHonestyGate`: certifica a proposta real e rejeita no-op, remoção de
  método vivo colateral e injeção de método público backdoor — cada um com razão precisa.
- Re-prova independente 2026-06-08: run `run-20260608-133531-fe7dda` tem 73
  propostas em `proposals.jsonl`, 73 em `independently_verified.jsonl` e 0 em
  `refuted.jsonl`.
- Supervisor 2026-06-08: `atlas:loop:unified:supervisor --run=run-20260608-133531-fe7dda`
  detecta `stale_running` quando `report.json` fica running sem worker PHP real.
- Launchd 2026-06-08: `atlas:loop:unified:install-launchd --dry-run --run=run-20260608-133531-fe7dda`
  gera plist com `KeepAlive`, `RunAtLoad`, `--run-id` fixo e logs em
  `storage/atlas/loop/unified/launchd.*.log`.
- Materialização P4 2026-06-08: `AtlasLoopFrameworkMaterializer`, clone de cenário por
  worktree e `evaluateImplementation` têm testes focados e PHPStan nível 5 limpo.
- Semantic Implementation Certification 2026-06-08: `AtlasLoopSemanticImplementationCertifier`
  prova diff-earned, holdout selado, painel adversarial, refutador externo obrigatório
  e recibo; o teste integrado roda uma aceitação framework-reaching que bootstrapa
  Laravel e usa `App\Services\Ai\AutonomousEvolution\AtlasLoopWorkspaceMaterializer`.
- Intent Verifier Factory 2026-06-08: `AtlasLoopIntentVerifierFactory` compila
  intenção estreita em teste framework-reaching, prova baseline RED, aceita refutador
  externo do verificador e alimenta uma task P4 sem `acceptance.commands` manual até
  o SIC certificar a proposta.
- Intent Verifier Factory 2026-06-09: adiciona `event_dispatched` para gatilhos
  in-process (`method_call`, HTTP interno ou Artisan interno), provado no grinder P4
  com `Event::fake()` e mantendo RED-preflight antes do provider.
- Intent Verifier Factory 2026-06-09: adiciona `job_dispatched` para gatilhos
  in-process usando `Queue::fake()`/`Queue::assertPushed()`, provado no grinder P4
  com job real `App\Jobs\FlushBatchedMobilePushes`.
- Sinais P2/P3 2026-06-08: `AtlasLoopSignalAnalyzerTest` e
  `AtlasP3FindingDispatcherSignalTest` provam que clones, complexidade, cobertura,
  doc-drift e duplicação de docs entram no backlog como flags, não como auto-loop.
- Inteligência de fila 2026-06-08: `AtlasLoopIntelligenceOverlayTest` e
  `AtlasLoopReviewFeedbackCommandTest` provam `impact_score`, feedback append-only,
  matriz de providers sem invocação e slots cross-domínio sem auto-execução.
- phpstan nível 5 limpo em todo o código novo.

## Riscos

- Custo de provider em varreduras grandes (mitigado por `max_per_cycle` + propose-only).
- Conteúdo de seções de doc pode ser raso (mitigado por revisão humana propose-only).
- O gate de holdout é determinístico; ataques fora do conjunto de holdouts dependem da
  revisão humana — por isso propose-only é inegociável.

## Exemplos

```
php artisan atlas:loop:unified --once --modes=deadcode,docs_structure
php artisan atlas:loop:unified --run-id=run-YYYY --max-seconds=86400 --provider=hermes_cli
php artisan atlas:loop:unified:report
php artisan atlas:loop:unified:supervisor --run=run-YYYY --json
php artisan atlas:loop:unified:install-launchd --run=run-YYYY --dry-run --json
php artisan atlas:loop:verify-proposals --run=run-YYYY --json
php artisan atlas:loop:compile-verifier --intent='Add method foo() returns "ok".' --target=app/Foo.php --method=foo --returns=ok --strict --json
php artisan atlas:loop:compile-verifier --intent='Command foo should output ok' --target=app/Console/Commands/FooCommand.php --command='php artisan foo' --output-contains=ok --strict --json
php artisan atlas:loop:compile-verifier --intent='GET /foo returns ok' --target=app/Http/Controllers/FooController.php --http-path=/foo --http-status=200 --http-body-contains=ok --strict --json
php artisan atlas:loop:compile-verifier --intent='foo dispatches event' --target=app/Foo.php --method=foo --event-class=atlas.foo.ready --strict --json
php artisan atlas:loop:compile-verifier --intent='foo dispatches job' --target=app/Foo.php --method=foo --job-class='App\Jobs\FlushBatchedMobilePushes' --strict --json
php artisan atlas:loop:certify-implementation --workspace=/tmp/candidate --acceptance-file=/tmp/acceptance.json --refuter-command='php refute.php' --refuters=1 --json
php artisan atlas:loop:review-feedback --run=run-YYYY --path=app/Foo.php --mode=coverage_gap --action=approved --json
touch storage/atlas/loop/unified/STOP   # kill-switch
```

## Proximas Acoes

- Operacionalizar a próxima campanha 24h com `report.json.intelligence` já visível.
- Expandir o Intent Verifier Factory além de `method_return`/`command_output`/
  `http_response`/`event_dispatched`/`job_dispatched`: DB-state verifier, sempre
  com RED-preflight.
- Adicionar refutadores externos reais por provider para P4 acima do fixture local.
- Expandir slots cross-domínio de readiness para scanners/verifiers frozen específicos.
