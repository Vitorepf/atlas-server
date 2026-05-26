---
id: atlas-reality-outcome-gates
type: engineering_knowledge
title: Atlas Reality Outcome Gates
status: future
category: atlas-ai
priority: 99
implementation_state: future_target_partial_runtime_implementable
summary: Catalogo canonico dos 15 reality gates do Atlas. Complementa, sem substituir, os 15 universal gates AAEOS. Mede impacto observado no mundo real (revenue, retention, security posture, regret minimization, compounding intelligence delta, financial outcome, sovereignty preservation) e nao apenas verde tecnico (lint, tests, scope, schema). Reality gate verde sem universal gate verde nao promove; universal gate verde sem reality gate verde alerta e exige Operator Decision Receipt para continuar.
human_summary: Os 15 portoes que medem se uma Obra do Atlas entregou valor real ou so verde tecnico.
human_what: Catalogo de gates de outcome real complementando os gates universais tecnicos AAEOS.
human_purpose: Impedir que Obra passe nos 15 gates tecnicos mas falhe no mundo real (negocio, usuario, seguranca, financeiro, etica, soberania).
human_input: Recebe metricas runtime, telemetria de uso, observacao de mundo real, sinais ASRE e receipts financeiros/seguranca.
human_output: Entrega reality_gate_report com status por gate, evidencia atrelada, exception receipts e alerta para Mission Control.
human_change_when: Mexa quando criar gate de realidade novo, mudar escala de severity, ajustar threshold ou refinar bloco de safety/sovereignty.
human_block_when: Bloqueie quando IA tentar promover Obra sensivel ignorando reality gate vermelho ou misturar gate tecnico com gate de realidade.
tags:
  - atlas-ai
  - reality-gates
  - outcome-governance
  - evidence-certification
  - asre
  - genesis-initiative
  - antifragility
capabilities:
  - reality_outcome_gate_catalogue
  - reality_gate_evaluation_runtime
  - reality_gate_exception_receipt
  - reality_gate_severity_classification
  - reality_gate_to_universal_gate_mapping
  - reality_anchored_promotion_decision
decisions:
  - Reality gates complementam os 15 universal gates AAEOS; nao substituem nem competem.
  - Reality gate verde sem universal gate verde nao promove Obra; universal gate verde sem reality gate verde aciona Operator Decision Receipt obrigatorio.
  - Cada reality gate tem severity declarada (critical, high, medium, low) e threshold canonico mensuravel.
  - Reality gate runtime opera sobre evidencia ja registrada no Evidence Ledger ou ASRE; nao consome dados crus de fora.
  - Reality gate em dominio sensivel (legal, healthcare, finance, trading, cyber) exige bloco safety/sovereignty canonico aplicado antes de avaliacao.
  - Promocao L7 AAEOS -> L8 Autonomous Company OS exige reality gate pass rate minimo de 0.90 em janela mavel de 30 dias.
maintenance:
  - Atualizar antes de adicionar/remover gate de realidade, alterar severity, threshold ou owner.
  - Sincronizar com `atlas-evidence-certification-runtime.md` quando schema de evidencia mudar.
  - Sincronizar com `atlas-strategic-reality-engine.md` quando ASRE mudar contratos de medicao.
  - Rodar docs-health + sync apos qualquer alteracao.
related_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-company-os-genesis-initiative.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - docs/engineering-knowledge-base/atlas-strategic-reality-engine.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os-runbook.md
  - docs/engineering-knowledge-base/atlas-universal-failure-mode-catalog.md
  - docs/engineering-knowledge-base/atlas-trust-ledger-canonical.md
  - docs/engineering-knowledge-base/atlas-autonomy-ladder-promotion-runbook.md
  - docs/engineering-knowledge-base/atlas-mission-control-cockpit-spec.md
  - docs/engineering-knowledge-base/atlas-cognitive-antifragility-equation.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-reality-outcome-gates
graph_title: Atlas Reality Outcome Gates
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-evidence-certification-runtime
graph_status: future
graph_source: repo
human_name: Atlas Reality Outcome Gates
canonical_name: Atlas Reality Outcome Gates
technical_name: atlas-reality-outcome-gates
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-reality-outcome-gates.md
owner: atlas-ai
patamar_after:
  - atlas-evidence-certification-runtime
  - atlas-strategic-reality-engine
patamar_next:
  - atlas-autonomous-software-company-runtime
versions: []
repo_paths:
  - docs/engineering-knowledge-base/atlas-reality-outcome-gates.md
allowed_changes:
  - Adicionar gate de realidade novo com schema, severity e threshold.
  - Refinar threshold quando ASRE produzir baseline melhor.
  - Promover gate de future para active quando runtime de medicao real estiver provado.
forbidden_changes:
  - Remover gate critical ou high sem substituto canonico.
  - Reduzir reality gate a gate tecnico (lint, tests, scope, schema).
  - Permitir promocao de Obra sensivel ignorando reality gate vermelho.
  - Tratar reality gate como opcional em dominios sensiveis.
depends_on:
  - atlas-autonomous-company-os-genesis-initiative
  - atlas-evidence-certification-runtime
  - atlas-strategic-reality-engine
  - atlas-trust-ledger-canonical
flows_to:
  - atlas-mission-control-cockpit-spec
  - atlas-autonomy-ladder-promotion-runbook
  - atlas-autonomous-software-company-runtime
unlocks:
  - reality-anchored-promotion-decision
  - outcome-truth-governance
  - sensitive-domain-protection
governs:
  - atlas.reality.gates
  - atlas.outcome.governance
evidence:
  - docs/engineering-knowledge-base/atlas-reality-outcome-gates.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: critical
visual_tags:
  - gates
  - outcome
  - reality
  - genesis
ai_entrypoints:
  - Leia Catalogo dos 15 Reality Gates antes de propor promocao de Obra com impacto observavel no mundo real.
  - Leia Bloco Safety / Sovereignty antes de aplicar reality gate em dominio sensivel.
  - Leia Regras de Composicao Reality x Universal antes de mudar pipeline de promocao.
ai_usage_notes:
  - Este doc e `status: future` mas implementavel ja: muitos gates podem ser ligados conforme ASRE e Evidence Ledger maturarem.
  - Reality gate nunca substitui universal gate; sempre adiciona.
  - Reality gate vermelho em dominio sensivel bloqueia promocao sem excecao.
quality_gates:
  - reality-gates-fifteen-listed
  - severity-and-threshold-declared-per-gate
  - sovereignty-safety-block-present
  - mapping-to-universal-gates-explicit
  - exception-receipt-schema-declared
failure_modes:
  - Obra promovida com universal verde mas reality vermelho sem Operator Decision Receipt.
  - Reality gate medindo dado cru de fora sem passar por Evidence Ledger / ASRE.
  - Reality gate em dominio sensivel sem safety/sovereignty block aplicado.
  - Threshold de reality gate ajustado sem baseline ASRE evidenciada.
  - IA confunde reality gate com KPI generico sem schema canonico.
observability_signals:
  - reality_gate_pass_rate_overall
  - reality_gate_pass_rate_per_gate
  - reality_gate_exception_receipts_count
  - reality_gate_red_in_sensitive_domain_count
  - reality_gate_to_universal_gate_drift_alerts
next_actions:
  - Implementar `AtlasRealityOutcomeGatesEvaluatorService` que consome `atlas.reality.signals.v1` e emite `atlas.reality.gate_report.v1`.
  - Implementar schema `atlas.reality.gate_report.v1` no Contract Schema Registry.
  - Adicionar zona "Reality Outcome" ao Mission Control Cockpit.
  - Implementar gate de promocao L7 -> L8 exigindo pass rate >= 0.90 em 30 dias.
---
# Atlas Reality Outcome Gates

## Resumo

15 reality gates canonicos que medem se uma Obra do Atlas entregou impacto real no mundo. Complementam, sem substituir, os 15 universal gates AAEOS (que sao tecnicos: lint, tests, scope, schema, etc.).

A regra mestre e simples:

```text
universal_verde + reality_verde  -> promove livremente
universal_verde + reality_vermelho -> bloqueia ate Operator Decision Receipt
universal_vermelho + reality_verde -> bloqueia (gate tecnico tem veto)
universal_vermelho + reality_vermelho -> bloqueia duplo, alerta critico
```

## Papel no Atlas

Esta doc e contrato canonico (`graph_kind: contract`) sob `atlas-evidence-certification-runtime` (parent canonico de evidence) e flui para Mission Control Cockpit, Autonomy Ladder Promotion e Autonomous Software Company Runtime.

## Onde Se Encaixa

```mermaid
flowchart LR
  Obra[Obra Atlas concluida]
  Universal[15 Universal Gates AAEOS<br/>tecnicos]
  Reality[15 Reality Gates<br/>este doc]
  Evi[Evidence Ledger]
  ASRE[Strategic Reality Engine]
  Decision[Promocao decision]
  Cockpit[Mission Control]

  Obra --> Universal
  Obra --> Reality
  Evi --> Reality
  ASRE --> Reality
  Universal --> Decision
  Reality --> Decision
  Decision --> Cockpit
```

## Contratos

- Reality gate sempre complementa universal gate; nunca substitui.
- Cada gate tem severity (critical|high|medium|low) e threshold canonico mensuravel declarados nas tabelas.
- Reality gate consome apenas evidence ja registrada (Evidence Ledger ou ASRE); nunca dado cru de fora.
- Skip de reality gate exige `atlas.reality.gate_skip_receipt.v1` valido com operator signature.
- Reality gate vermelho em dominio sensivel NUNCA pode ser skipped via Operator Decision Receipt unilateral; exige dual signature + revisor humano licenciado.
- Schema canonico do report e `atlas.reality.gate_report.v1`; do skip receipt e `atlas.reality.gate_skip_receipt.v1`.
- Promocao L7 -> L8 exige reality pass rate >= 0.90 em janela mavel de 30 dias.

## Fluxo

```mermaid
sequenceDiagram
  participant Obra as Obra concluida
  participant Uni as 15 Universal Gates
  participant Rea as 15 Reality Gates
  participant Evi as Evidence Ledger / ASRE
  participant Dec as Promotion Decision
  participant Cock as Mission Control

  Obra->>Uni: avaliar gates tecnicos
  Obra->>Rea: avaliar gates de outcome
  Evi-->>Rea: fornece evidencia para gates 4,5,9,12,15
  Uni-->>Dec: status tecnico
  Rea-->>Dec: status outcome
  Dec->>Cock: promotion_decision + receipts pendentes
```

## Catalogo dos 15 Reality Gates

Cada gate tem: `id`, `severity` (critical | high | medium | low), `threshold` canonico, `evidence_source`, `owner_doc`, `applies_to_sensitive_domains`.

### Familia A - Outcome Funcional (gates 1-4)

| # | Gate ID | Severity | Threshold | Evidence Source | Owner |
|---|---------|----------|-----------|-----------------|-------|
| 1 | `business_outcome_realized` | critical | hipotese de valor declarada na Obra confirmada por metrica observada | ASRE outcome signal | strategic-reality-engine |
| 2 | `user_satisfaction_delta` | high | satisfaction delta nao-negativo em 7d apos release | telemetria runtime | strategic-reality-engine |
| 3 | `adoption_realized` | high | uso real >= 50% da projecao Obra em 30d | telemetria runtime | strategic-reality-engine |
| 4 | `regression_in_production_zero` | critical | zero regressao critica em producao em 7d apos release | evidence ledger + ops telemetry | evidence-certification-runtime |

### Familia B - Outcome de Confianca e Seguranca (gates 5-8)

| # | Gate ID | Severity | Threshold | Evidence Source | Owner |
|---|---------|----------|-----------|-----------------|-------|
| 5 | `security_posture_observed` | critical | zero incidente classificado high+ em 30d; zero CVE critica nao mitigada | security telemetry + cyber receipts | sovereign-os + governance |
| 6 | `adversarial_resilience_score` | high | sobreviveu a red-team adversarial run sem breach > medium | self-adversarial engine | self-construction-os |
| 7 | `privacy_sovereignty_preserved` | critical | zero vazamento sovereignty class sensitive/secret/cyber em 30d | sovereign-os audit | sovereign-os |
| 8 | `consent_chain_complete` | high | 100% das acoes em dominio sensivel tem consent chain rastreavel | trust ledger | trust-ledger-canonical |

### Familia C - Outcome Financeiro e Custo (gates 9-11)

| # | Gate ID | Severity | Threshold | Evidence Source | Owner |
|---|---------|----------|-----------|-----------------|-------|
| 9 | `financial_outcome_signed` | high | custo real <= 120% do custo projetado da Obra | finance receipts | governance |
| 10 | `cost_per_outcome_unit_acceptable` | medium | cost-per-outcome dentro de baseline canonico | finance + ASRE | strategic-reality-engine |
| 11 | `roi_positive_or_acceptable` | medium | ROI positivo OU justificado por strategic intent receipt | finance + ASRE | strategic-reality-engine |

### Familia D - Outcome Cognitivo e Compounding (gates 12-15)

| # | Gate ID | Severity | Threshold | Evidence Source | Owner |
|---|---------|----------|-----------|-----------------|-------|
| 12 | `regret_minimization_score` | high | janela 90d: decisao mantida sem reverter; replay contrafactual nao mostra alternativa estritamente dominante | counterfactual runtime + trust ledger | strategic-reality-engine |
| 13 | `compounding_intelligence_delta` | high | sistema ficou mais inteligente apos a Obra: capability score Atlas total cresceu (nao zerado nem regrediu) | ACOS metric + trust ledger | cognition-operating-system |
| 14 | `learning_capsule_extracted` | medium | Obra produziu learning capsule canonica registrada no trust ledger | trust ledger | trust-ledger-canonical |
| 15 | `antifragility_multiplier_grew_or_held` | high | Antifragility_multiplier(t+1) >= Antifragility_multiplier(t) | antifragility equation runtime | cognitive-antifragility-equation |

## Schema Canonico do Reality Gate Report

```text
{
  "schema": "atlas.reality.gate_report.v1",
  "obra_id": "<obra_id>",
  "evaluated_at": "<iso8601>",
  "evaluator": "AtlasRealityOutcomeGatesEvaluatorService",
  "gate_results": [
    {
      "gate_id": "<one of the 15>",
      "severity": "critical|high|medium|low",
      "status": "green|yellow|red|skipped",
      "threshold": "<canonical>",
      "observed_value": "<observed>",
      "evidence_refs": ["<sha256:*>"],
      "skip_receipt_id": "<receipt_id if skipped>"
    }
  ],
  "overall_status": "green|yellow|red",
  "promotion_decision": "promote|hold|block",
  "operator_decision_receipt_required": <bool>,
  "sensitive_domain_safety_block_applied": <bool>
}
```

## Schema Canonico de Excecao (Skip Receipt)

```text
{
  "schema": "atlas.reality.gate_skip_receipt.v1",
  "receipt_id": "<uuid>",
  "obra_id": "<obra_id>",
  "gate_id": "<gate_id>",
  "reason": "<human-readable, >= 80 chars>",
  "operator_signature": "<sig>",
  "evidence_refs": ["<sha256:*>"],
  "expires_at": "<iso8601>"
}
```

Skip sem signature operator + evidence refs e invalido.

## Mapping Reality <-> Universal Gates

| Reality gate | Universal gate complementado | Relacao |
|--------------|------------------------------|---------|
| `regression_in_production_zero` | `tests-pass` | reality observa producao; universal observa CI |
| `security_posture_observed` | `secrets-policy` | reality observa incidente real; universal verifica policy estatica |
| `privacy_sovereignty_preserved` | `sovereignty-class-respected` | reality observa vazamento; universal verifica classificacao no envelope |
| `financial_outcome_signed` | `cost-budget-respected` | reality observa custo real; universal observa previsao |
| `compounding_intelligence_delta` | `learning-attached` | reality mede salto cognitivo; universal verifica capsule attached |

Mappings completos vivem em `atlas-universal-failure-mode-catalog.md` cross-reference.

## Bloco Obrigatorio Safety / Sovereignty para Dominios Sensiveis

Aplicado antes de qualquer reality gate avaliar Obra em dominio legal, healthcare, finance, trading ou cyber:

```text
sovereignty_class: sensitive | secret | cyber
operation_mode: assistive | research | draft | analysis only
no_autonomous_professional_advice: true
licensed_human_review_required: true   (onde aplicavel)
jurisdiction_check_required: true
consent_chain_verified_required: true
audit_trail_complete_required: true
liability_carrier_documented_required: true

# Em dominio sensivel, reality gate vermelho NUNCA pode ser
# skipped via Operator Decision Receipt unilateral. Exige
# dual signature operator + revisor humano licenciado +
# evidence pack auditavel anexado.
```

## Regras de Composicao Reality x Universal

```text
1. universal_verde + reality_verde
   -> promote
2. universal_verde + reality_yellow
   -> promote com warning anexado, observability alarm
3. universal_verde + reality_red (nao-sensivel)
   -> block; exige Operator Decision Receipt para override
4. universal_verde + reality_red (sensivel)
   -> block hard; exige dual signature + licensed reviewer
5. universal_yellow/red
   -> block sem opcao de override; corrige tecnico antes
6. reality_skipped sem skip_receipt valido
   -> equivale a reality_red
```

## Regras para IA

- Reality gate NUNCA mede dado cru de fora; consome apenas Evidence Ledger ou ASRE.
- Reality gate em dominio sensivel exige bloco safety/sovereignty aplicado antes da avaliacao.
- Skip de reality gate exige `atlas.reality.gate_skip_receipt.v1` valido com signature operador.
- Reality gate vermelho em dominio sensivel nao pode ser skipped unilateralmente; exige dual signature + revisor humano.
- IA NAO pode renomear gate, alterar severity nem threshold sem update desta doc + sync `atlas-evidence-certification-runtime`.

## O que este doc NAO e

- NAO substitui universal gates AAEOS.
- NAO e dashboard de KPI generico; gates tem schema, severity, threshold e evidence_source canonicos.
- NAO mede sucesso de feature isolada; mede outcome de Obra completa.
- NAO permite Obra sensivel ignorar reality vermelho.

## Escopo de Implementacao

- `AtlasRealityOutcomeGatesEvaluatorService` (novo) carrega `atlas.reality.signals.v1`, avalia os 15 gates e emite `atlas.reality.gate_report.v1`.
- Schemas `atlas.reality.gate_report.v1` e `atlas.reality.gate_skip_receipt.v1` registrados em `atlas-contract-schema-registry`.
- Zona "Reality Outcome" adicionada ao Mission Control Cockpit como 13a zona.
- Gate de promocao L7 -> L8 dependente de reality pass rate >= 0.90 em janela 30 dias.
- Cross-link com `atlas-universal-failure-mode-catalog` adicionando coluna `reality_gate_relacionado`.
- Implementacao em hot scope sob `App\Services\Ai\AgenticEngineeringOs\RealityOutcomeGates` (a criar).

## Dependencias

Dependencias canonicas declaradas em `depends_on`. Resumo:

- `atlas-evidence-certification-runtime` (parent canonico, source de evidencia).
- `atlas-strategic-reality-engine` (source de outcome signals).
- `atlas-trust-ledger-canonical` (registra learning capsules emitidas pelos gates).
- `atlas-agentic-engineering-os-runbook` (consome reality gates no decision de promocao).
- `atlas-autonomous-company-os-genesis-initiative` (manifesto que abre este doc).

## Evidencias

- Este doc canonico.
- Schemas `atlas.reality.gate_report.v1` e `atlas.reality.gate_skip_receipt.v1` quando registrados.
- Comando esperado `php artisan atlas:reality:gates --obra=<id> --json` retornando gate_report.
- Trust Ledger eventos `kind` = `reality_gate_passed`, `reality_gate_failed`, `reality_gate_skipped`.
- Mission Control zona "Reality Outcome" snapshot.

## Exemplos

### Exemplo 1: Obra de feature de pagamento

- 15 universal: 14 verde, 1 yellow (cobertura test 78% < 80%).
- 15 reality: gates 4 e 9 verde, gate 5 yellow (1 incidente medium-level), gate 10 verde, gate 11 medium (ROI just-positive).
- Decisao: bloqueia ate universal yellow virar verde (regra 5); reality nao consegue override gate tecnico.

### Exemplo 2: Obra de funcao em dominio legal

- 15 universal: tudo verde.
- 15 reality: gate 7 (privacy sovereignty) red — vazamento sensitive detectado.
- Decisao: block hard. Exige dual signature operador + advogado licenciado. Reality gate skipped via skip receipt unilateral seria invalido aqui por aplicar-se bloco safety/sovereignty.

## Riscos

- **Critico**: Obra promovida em dominio sensivel ignorando reality red. Mitigacao: gate `reality-gate-red-in-sensitive-domain-count` observado pelo cockpit + alerta.
- **Critico**: threshold de gate ajustado sem baseline ASRE. Mitigacao: maintenance regra obrigatoria.
- **Alto**: reality gate medindo dado cru fora do Evidence Ledger. Mitigacao: validator no `AtlasRealityOutcomeGatesEvaluatorService`.
- **Medio**: drift entre reality gate e universal gate mapeado. Mitigacao: docs-health cross-link.

## Proximas Acoes

1. Implementar `AtlasRealityOutcomeGatesEvaluatorService`.
2. Registrar `atlas.reality.gate_report.v1` e `atlas.reality.gate_skip_receipt.v1` no Contract Schema Registry.
3. Adicionar zona "Reality Outcome" ao Mission Control Cockpit como 13a zona.
4. Implementar gate de promocao L7 -> L8 dependente de reality pass rate >= 0.90 em janela 30 dias.
5. Cross-link com `atlas-universal-failure-mode-catalog.md` adicionando coluna reality_gate_relacionado.
6. Cross-link com `atlas-autonomy-ladder-promotion-runbook.md` adicionando regra de reality pass rate por nivel L.
