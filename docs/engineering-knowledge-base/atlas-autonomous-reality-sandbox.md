---
id: atlas-autonomous-reality-sandbox
type: engineering_knowledge
title: Atlas Autonomous Reality Sandbox
status: active
category: simulation-runtime
priority: 100
summary: Canonical contract for AARS, the Atlas runtime that simulates reality, counterfactuals, risk and promotion readiness before Atlas acts in real workflows.
tags:
  - atlas-ai
  - aars
  - simulation
  - reality-sandbox
  - decision-quality
capabilities:
  - reality_twin_simulation
  - sandbox_counterfactual_projection
  - impact_projection
  - risk_projection
  - sandbox_certification
decisions:
  - AARS is a simulation and certification layer, not a real execution runtime.
  - AARS must reuse ASRE, ASEIF, AAEL, AWEOS, AVER and AEMOR instead of replacing them.
  - AARS never calls providers, runs benchmarks or executes external actions by itself.
  - AARS output is not truth; it is a governed decision aid that must name uncertainty.
maintenance:
  - Update when simulation schemas, certification checks, control plane wiring or downstream gates change.
related_paths:
  - app/Services/Ai/RealitySandbox/AtlasAutonomousRealitySandboxService.php
  - app/Services/Ai/RealitySandbox/AtlasAutonomousRealitySandboxCertificationService.php
  - app/Console/Commands/AtlasAarsCommand.php
  - app/Console/Commands/AtlasAarsCertifyCommand.php
  - database/migrations/2026_05_20_220000_create_atlas_aars_tables.php
  - tests/Feature/Ai/RealitySandbox/AtlasAutonomousRealitySandboxServiceTest.php
  - docs/engineering-knowledge-base/atlas-strategic-reality-engine.md
  - docs/engineering-knowledge-base/atlas-autonomous-evolution-loop.md
  - docs/engineering-knowledge-base/atlas-verified-execution-runtime.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
owner: atlas-ai
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-autonomous-reality-sandbox
graph_title: Atlas Autonomous Reality Sandbox
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-canonical-architecture-index
graph_status: active
graph_source: repo
human_name: Atlas Autonomous Reality Sandbox
canonical_name: Atlas Autonomous Reality Sandbox
technical_name: atlas-autonomous-reality-sandbox
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-autonomous-reality-sandbox.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-reality-sandbox.md
  - app/Services/Ai/RealitySandbox
allowed_changes:
  - Extend AARS with stronger simulation models, scenario types and validation gates.
forbidden_changes:
  - Do not let AARS execute real-world actions.
  - Do not let AARS claim simulation as proof of real outcome.
  - Do not bypass ASRE, AVER, AEMOR, AAEL or human approval gates.
depends_on:
  - atlas-strategic-reality-engine
  - atlas-intelligence-factory-os
  - atlas-autonomous-evolution-loop
  - atlas-autonomous-work-execution-os
  - atlas-verified-execution-runtime
flows_to:
  - atlas-strategic-reality-engine
  - atlas-autonomous-evolution-loop
  - atlas-forge
  - atlas-dev
unlocks:
  - simulated-decision-readiness
  - counterfactual-before-action
governs:
  - atlas-reality-sandbox
evidence:
  - app/Services/Ai/RealitySandbox/AtlasAutonomousRealitySandboxService.php
  - tests/Feature/Ai/RealitySandbox/AtlasAutonomousRealitySandboxServiceTest.php
evidence_refs:
  - symbol: AtlasAutonomousRealitySandboxService
  - command: atlas:aars
required_tests:
  - "php artisan test tests/Feature/Ai/RealitySandbox"
  - "php artisan atlas:aars:certify --json --strict"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Keep AARS read-only/simulation-only until downstream execution gates approve.
  - Add scenario types only with tests, receipts and control-plane visibility.
  - Use AARS before high-impact AAEL, Forge, ASRE or external action decisions.
---
# Atlas Autonomous Reality Sandbox

## Resumo

Nome canonico / produto: Atlas Autonomous Reality Sandbox.
Acronimo tecnico: AARS.
Nome interno de experiencia / superficie: Atlas Simulation Chamber.
Runtime tecnico: AtlasAutonomousRealitySandboxService.

AARS e a camada do Atlas que ensaia consequencias antes da acao. Ela recebe uma
meta, constroi um cenario, gera simulacao, compara contrafactuais, projeta risco,
certifica prontidao e recomenda o proximo gate. AARS nao executa a acao real.

## Papel no Atlas

AARS responde: "o que provavelmente acontece se o Atlas agir assim, e quais
condicoes precisam ser provadas antes da execucao?". Ele reduz falsa confianca,
promocao prematura e mudancas grandes sem ensaio.

## Onde Se Encaixa

AARS fica entre contexto/decisao e execucao. ASRE decide estrategia, AAEL escolhe
evolucao, AWEOS planeja trabalho, AVER verifica execucao e AEMOR aprende outcome.
AARS simula antes desses gates aceitarem risco.

## Contratos

- `atlas.aars.scenario.v1`: objetivo, dominio, flow, world_state, assumptions.
- `atlas.aars.simulation.v1`: opcoes, predicted_outcomes, impact_model,
  uncertainty e required_validation.
- `atlas.aars.counterfactual.v1`: baseline, alternativas, delta e efeitos.
- `atlas.aars.risk_projection.v1`: riscos, mitigacoes, rollback e gates humanos.
- `atlas.aars.certification_result.v1`: checks e promotion_gate.
- `atlas.aars.control_plane.v1`: leitura agregada sem objetivo bruto.
- `atlas.aars.certification.v1`: readiness do runtime AARS.

## Fluxo

1. Criar scenario com objective_hash e evidence refs.
2. Montar world_state com sinais de ASRE, ASEIF e AAEL quando disponiveis.
3. Simular baseline, sandbox-first e operator-review.
4. Rodar counterfactual replay contra baseline.
5. Projetar risco e rollback.
6. Certificar se a simulacao pode seguir para outro runtime.
7. Expor apenas hashes e agregados no Control Plane.

## Regras para IA

- Nunca tratar simulacao como verdade.
- Nunca executar provider, benchmark ou acao externa dentro do AARS.
- Sempre diferenciar `sandbox_passed`, `operator_review_required` e `blocked`.
- Sempre preservar evidence refs e hashes.
- Quando risco for alto, exigir operator gate.
- Quando for execucao real, encaminhar para AVER ou runtime governado.

## Escopo de Implementacao

Inclui persistencia, models, runtime, comandos `atlas:aars`, certificacao
`atlas:aars:certify`, testes e Control Plane. Nao inclui UX dedicada, execucao
real, WebSocket, benchmark ou provider ensemble.

## Dependencias

AARS depende semanticamente de ASRE, ASEIF, AAEL, AWEOS, AVER e AEMOR. Ele nao
substitui Atlas Dev ou Atlas Forge; ele informa quando esses fluxos podem agir.

## Evidencias

Evidencia minima: scenario_hash, simulation_hash, counterfactual_hash, risk_hash,
certification_hash, evidence_refs e comandos verdes. O Control Plane deve mostrar
contagens AARS sem vazar objective bruto.

## Riscos

- Simulacao falsa parecer prova.
- Cenario sem evidencia virar decisao forte.
- Duplicar ASRE/AAEL/AVER.
- Promover mudanca real sem rollback.
- Expor objetivo sensivel em painel agregado.

Mitigacao: claim_policy fail-closed, hashes, evidence refs, risk projection,
operator gates e testes anti-vazamento.

## Exemplos

`php artisan atlas:aars run --objective="simular mudanca no Atlas Dev" --evidence=test:aars --json`

`php artisan atlas:aars control-plane --json`

`php artisan atlas:aars:certify --json --strict`

## Proximas Acoes

1. Alimentar AARS com context packs APCR/ACIE quando disponivel.
2. Usar AARS como gate antes de evolucoes AAEL de alto impacto.
3. Expandir scenario types por dominio somente com testes e receipts.
