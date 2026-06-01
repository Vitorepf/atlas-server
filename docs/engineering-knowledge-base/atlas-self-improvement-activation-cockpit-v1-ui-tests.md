---
id: atlas-self-improvement-activation-cockpit-v1-ui-tests
type: engineering_knowledge
title: Atlas Self-Improvement Activation Cockpit v1 UI Tests
status: active
category: self-construction
priority: 90
summary: Detalhes extraidos do cockpit de activation: UI, seguranca, evidence refs, testes e limites operacionais.
tags:
  - atlas
  - self-improvement
  - cockpit
  - ui
  - tests
capabilities:
  - self_improvement_activation_cockpit_ui
  - self_improvement_activation_cockpit_tests
decisions:
  - UI e testes do cockpit sao detalhe operacional do contrato pai.
  - Acoes humanas continuam bloqueadas por reviewer, reason e ack no-fast-path.
maintenance:
  - Atualize quando painel, bridge, Tauri commands, evidence refs ou testes mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-self-improvement-activation-cockpit-v1.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-self-improvement-activation-cockpit-v1-ui-tests
graph_title: Atlas Self-Improvement Activation Cockpit v1 UI Tests
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-self-improvement-activation-cockpit-v1
graph_status: active
graph_source: repo
human_name: Atlas Self-Improvement Activation Cockpit v1 UI Tests
canonical_name: Atlas Self-Improvement Activation Cockpit v1 UI Tests
technical_name: atlas-self-improvement-activation-cockpit-v1-ui-tests
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/atlas-self-improvement-activation-cockpit-v1-ui-tests.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-self-improvement-activation-cockpit-v1-ui-tests.md
allowed_changes:
  - Refinar UI, seguranca, evidence refs e testes preservando contrato pai.
forbidden_changes:
  - Permitir accept sem reviewer, reason e ack no-fast-path.
  - Disparar provider, Fast Path ou completion claim a partir do cockpit.
depends_on:
  - atlas-self-improvement-activation-cockpit-v1
flows_to:
  - atlas-self-improvement-activation-cockpit-v1
unlocks:
  - human_visibility_of_governed_activation_lifecycle
governs:
  - self_improvement_activation_cockpit.ui_tests
evidence:
  - docs/engineering-knowledge-base/atlas-self-improvement-activation-cockpit-v1-ui-tests.md
evidence_refs:
  - symbol: AtlasSelfImprovementForgeActivationService
  - command: atlas:self-improvement:activate-forge
  - test: AtlasSelfImprovementForgeActivationServiceTest
required_tests:
  - "php artisan test --filter='AtlasSelfImprovementForgeActivation|AtlasSelfImprovementActivationCockpit'"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Leia esta doc antes de tocar UI/testes do cockpit de activation.
ai_usage_notes:
  - Esta doc detalha UI/testes; o contrato pai governa semantica e limites.
quality_gates:
  - docs-health-pass
  - activation-cockpit-tests-pass
failure_modes:
  - UI permitir mutacao insegura.
observability_signals:
  - test_status
next_actions:
  - Manter evidencia e testes sincronizados com mudancas do cockpit.
line_limit: 520
---
# Atlas Self-Improvement Activation Cockpit v1 UI Tests

## Resumo

Esta doc filha guarda detalhes de UI, seguranca, evidence refs, testes e limites do cockpit. O contrato pai continua sendo `atlas-self-improvement-activation-cockpit-v1`.

## Papel no Atlas

Dar rastreabilidade operacional para a tela humana do cockpit e para os testes que impedem accept/reject inseguro.

## Onde Se Encaixa

Contrato pai -> painel desktop/bridge/Tauri/API -> testes e evidence refs.

## Contratos

- Pai: `atlas-self-improvement-activation-cockpit-v1`.
- Mutacoes seguras: reviewer + reason; accept tambem exige ack no-fast-path.

## Fluxo

1. Cockpit lista propostas.
2. Humano seleciona e revisa.
3. Accept/reject so habilita quando os campos obrigatorios existem.
4. Testes protegem os invariantes.

## Regras para IA

- Nao adicione mutacao nova no cockpit.
- Nao dispare Fast Path automaticamente.
- Nao esconda evidence refs ou hashes relevantes.

## Escopo de Implementacao

UI, bridge, comandos Tauri, seguranca local, evidence refs, testes e limites operacionais.

## Detalhes Extraidos

## UI

Painel: `atlas-desktop/apps/desktop/src/surfaces/code/panels/AtlasSelfImprovementActivationCockpitPanel.tsx`.

Estrutura editorial linear peso-decrescente (canon):

1. **PanelTitle** "Self-Improvement" + contador total.
2. **Status strip** — eyebrow + humanSummary + nextSafeAction.
3. **Propostas** — FilterBar (Todas/Pendentes/Aceitas/Rejeitadas/Com Obra) + lista de cards.
4. **Avaliação** — ProposalSummaryView + PowerGateView (visível apenas com selected).
5. **Before Snapshot** — 6 linhas Row + rationale (visível apenas com selected).
6. **Aprovação** — Form accept/reject ou histórico de decisão (visível apenas com selected).
7. **Obra criada** — CreatedObraView + botão Abrir (visível apenas após accept).
8. **Histórico / Trust** — trust_band + strategy_bucket + portfolio (visível com selected).
9. **Safety strip** — 5 invariantes sempre visíveis (provider externo · tokens · Fast Path auto · completion claim · separated_from).
10. **Avançado** — `<details>` com JSON raw + hashes + evidence refs para auditoria.

Registrado em `rightRailRegistry.tsx` com priority 3 (antes do Forge, depois de pré-Forge se houver). Tab id: `self_improvement`.

Origin badge no Forge: `ForgeWorkIntakePanel.tsx` renderiza "Criada por Self-Improvement Activation · {activationId}" quando `selfImprovementActivation?.activationId` está presente no state projection.

Tokens visuais (canon editorial cream + bronze + moss + rec-red, viewport 393, sem grade 2D).

## Segurança

Invariantes enforced no cockpit:

| Invariante | Como | Camada |
|---|---|---|
| Accept exige reviewer | Botão desabilitado + service v1 rejeita | UI + backend |
| Accept exige reason | Botão desabilitado + service v1 rejeita | UI + backend |
| Accept exige checkbox no-fast-path | Botão desabilitado se `!acknowledgesNoFastPath` | UI |
| Reject exige reviewer | Botão desabilitado + service v1 rejeita | UI + backend |
| Reject exige reason | Botão desabilitado + service v1 rejeita | UI + backend |
| Nunca cria Obra silenciosa | service v1 garante | backend |
| Nunca executa Fast Path | cockpit nunca chama runForgeFastPath | UI |
| Nunca chama provider | service não tem driver | backend |
| Nunca promove completion claim | receipt explícito false | backend |
| Nunca toca external_rivals_certification | separated_from = `external_rivals_certification` | backend |

Bridge layer também valida reviewer + reason + ack antes de chamar accept/reject (lança erro local sem fazer round-trip se condições não atendidas).

## Evidence

`evidence_refs[]` no payload de cada activation referencia:
- `doc:<path>@<sha256>` para cada doc canônica presente.
- `proposal:<id>` para o proposal_id.
- `power_gate:<gate_id>` para o gate.
- `baseline:<kind>@<hash>` para cada componente do before_snapshot (maturity, invariant_lock, regression_sentinel, strategy_portfolio, trust_ledger).
- `approval:<receipt_hash>` quando aceito.
- `obra:<obra_id>` quando obra materializada.

Tudo verificável via filesystem (docs) ou ledger (`atlas_ledger_events`, event_type `SELF_IMPROVEMENT_*`).

## Testes

```bash
php artisan test --filter='AtlasSelfImprovementForgeActivation|AtlasSelfImprovementActivationCockpit'
php artisan atlas:self-improvement:activation-cockpit --json --strict
npm run lint --workspace=@atlas/desktop
npm run build --workspace=@atlas/desktop
cargo check -p atlas-tauri
php artisan atlas:programming:completion-audit --json
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:ai:architecture-validate --json
```

Os 16 tests do `AtlasSelfImprovementActivationCockpitTest` cobrem:

- Schema canônico vazio (counters zerados, ack do read-model).
- Lista com labels humanos (tone + statusLabel).
- Detail expondo proposal + power gate + before snapshot.
- Detail de activation inexistente devolvendo blocked.
- Filtros por status preservando counters totais.
- Accept materializando Obra + receipt visível + open_obra_action habilitado.
- Rejection visível, sem Obra, com tone rec-red.
- API list e detail (200) + 404 para id inexistente.
- Forge controller enrichment (human_summary + next_safe_action backwards-compatible).
- CLI strict success (sem activation selecionada).
- CLI strict fail (activation bloqueada).
- Invariantes de não-execução (provider call false, tokens false, fast path false, completion claim false, separated_from correto).
- Completion audit expondo certification nova com >=25 invariantes.
- External rivals certification permanecendo presente e inalterada.

## Limites

- Cockpit nunca substitui a fonte canônica: accept/reject continuam servidos por `AtlasSelfImprovementForgeActivationService::accept/reject`.
- Cockpit nunca toca Voice, Cartografia, Inbox, Embodiment.
- Cockpit nunca desbloqueia `external_rivals_certification` (continua governado externamente).
- Botão "Abrir Obra" apenas troca contexto; Fast Path continua sendo decisão manual no Forge Human Panel.
- Tauri commands nativos foram entregues mas o cockpit suporta operação somente-HTTP (offline-shim) sem quebrar.
- O cockpit não tenta inferir trust band ou portfolio deviation — apenas projeta o que o service retornou.
- Nenhuma persistência local de form data — cada navegação entre activations exige reentrada de reviewer + reason + checkbox (intencional · previne accept-after-navigation).

## Dependencias

- `atlas-self-improvement-activation-cockpit-v1`.
- `AtlasSelfImprovementForgeActivationService`.

## Evidencias

- Esta doc filha.
- Testes do cockpit.

## Riscos

- UI permitir acao insegura.
- Texto visual esconder risco operacional.

## Exemplos

Use esta doc para revisar o painel e os testes sem carregar todo o contrato pai.

## Proximas Acoes

- Sincronizar testes sempre que UI ou contrato mudar.
