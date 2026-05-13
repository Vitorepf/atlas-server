---
id: atlas-ai-cyber-flow-profiles-proposal
type: engineering_knowledge
title: Atlas AI Cyber Flow Profiles Proposal
status: scaffold
category: architecture
priority: 86
summary: Flow profiles propostos para a Cyber Security extension; nao registrados em AtlasDomainProfileRegistry ate onboarding formal de cada flow ou promocao a domain proprio.
tags:
  - atlas-ai
  - cyber-security
  - flow-profiles
  - proposal
capabilities:
  - cyber_flow_profile_proposal
decisions:
  - Flows propostos vivem como extensao de programming + security; nao criam domain proprio enquanto place-feature retornar gate=blocked.
  - Cada flow declara skill primaria, runtime preference, gates, autonomia, surfaces, evidence required, refusal matrix referenciada.
  - Promocao para implemented exige migration que adiciona flow em AtlasDomainProfileRegistry.
maintenance:
  - Atualize quando flow promovido (adicionar implementacao, remover de proposal).
  - Atualize quando place-feature mudar a decisao de owner_domain.
related_paths:
  - atlas-server/docs/engineering-knowledge-base/cyber-security-extension.md
  - atlas-server/docs/engineering-knowledge-base/domains/programming.md
  - atlas-server/docs/engineering-knowledge-base/domains/security.md
  - atlas-server/docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
owner: atlas-ai
layer: extension
line_limit: 240
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-cyber-flow-profiles-proposal

graph_title: Atlas AI Cyber Flow Profiles Proposal

graph_world: atlas

graph_layer: flow

graph_kind: flow

graph_parent: atlas-ai-pipeline

graph_status: building

graph_source: repo

repo_paths:
  - docs/engineering-knowledge-base/cyber-security/flow-profiles-proposal.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - cyber-security

evidence:
  - docs/engineering-knowledge-base/cyber-security/flow-profiles-proposal.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - flow
  - flow
  - cyber-security

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Atlas AI Cyber Flow Profiles Proposal

Flows propostos para a Cyber Security extension. Status: `scaffold` — propostos, nao registrados.

## Schema de flow proposto

```yaml
flow_id: <ID>
proposed_owner_domain: <programming | security | cyber (futuro)>
extension_of: <flow existente que estende, ou null>
default_runtime: <super_tool_runtime | engineering_harness | conversation>
autonomy_default: <low | medium | high>
background_allowed: <true | false>
surfaces: [mac_cli, app, api, mcp]
primary_skill: <skill slug>
auxiliary_skills: [<lista>]
required_gates: [<lista>]
refusal_matrix_ref: cyber-security/refusal-matrix.md
evidence_required:
  - <tipo de evidencia 1>
  - <tipo de evidencia 2>
output_target: proposal_inbox_human_review
```

## Flows propostos

### `programming.security.audit`

Extensao do `programming.security` existente para self-audit ofensivo dos repos do Vitor.

```yaml
flow_id: programming.security.audit
proposed_owner_domain: programming
extension_of: programming.security
default_runtime: engineering_harness
autonomy_default: low
background_allowed: true
surfaces: [mac_cli, app]
primary_skill: cyber-pentest-webapp
auxiliary_skills: [security-review, dev-quality-gate, repo-context-pack]
required_gates: [secrets_checked, permissions_scoped, findings_have_evidence, refusal_matrix_check]
refusal_matrix_ref: cyber-security/refusal-matrix.md
evidence_required:
  - sast_scan_result
  - dependency_scan_result
  - secret_scan_result
  - finding_with_repro
  - patch_proposal
output_target: proposal_inbox_human_review
```

### `cyber.recon`

Recon contra alvo externo autorizado (programa BB).

```yaml
flow_id: cyber.recon
proposed_owner_domain: cyber (futuro) | programming (interim)
extension_of: null
default_runtime: super_tool_runtime
autonomy_default: low
background_allowed: true
surfaces: [mac_cli, app, api]
primary_skill: cyber-recon
auxiliary_skills: [orquestrador]
required_gates:
  - bb_program_proof
  - scope_validation
  - rate_limit_compliance
  - refusal_matrix_check
refusal_matrix_ref: cyber-security/refusal-matrix.md
evidence_required:
  - asset_map_artifact
  - tech_fingerprint
  - leak_findings_if_any
  - tool_run_traces
output_target: handoff_to_cyber_pentest_or_inbox
```

### `cyber.recon.continuous`

Recon agendado em loop curto (6h-12h-24h) para captar 0-day windows: novo CVE divulgado em X/GitHub e Atlas ja testou todo o escopo antes de attacker generico chegar.

```yaml
flow_id: cyber.recon.continuous
proposed_owner_domain: cyber (futuro) | programming (interim)
extension_of: cyber.recon
default_runtime: super_tool_runtime
autonomy_default: low
background_allowed: true
scheduled: true
schedule_cron_default: "0 */6 * * *"   # a cada 6h
surfaces: [mac_cli, api, mcp, scheduled_task]
primary_skill: cyber-recon
auxiliary_skills: [cyber-pentest-webapp, orquestrador]
required_gates:
  - bb_program_proof_still_valid
  - scope_validation
  - rate_limit_compliance
  - cost_quota_per_run
  - refusal_matrix_check
  - delta_only_processing  # so processa novos assets / mudancas
refusal_matrix_ref: cyber-security/refusal-matrix.md
evidence_required:
  - asset_map_delta
  - new_findings_since_last_run
  - tool_run_traces
output_target:
  - proposal_inbox_human_review (apenas se delta gerou finding novo)
  - silent_evidence_ledger (se delta zero — log mas nao notifica)
notes: |
  Padrao "verdict-first" pareado com Nuclei templates atualizados pela comunidade
  diariamente. Custo controlado via cost_quota_per_run gate (operador define teto USD/mes).
  Distribuicao opcional via recipe `axiom distributed-scan` quando escopo e grande.
  Pode ser disparado por GitHub Actions / Atlas scheduler conforme decisao Codex futura.
auto_triggers:
  new_asset_detected:
    condition: "delta inclui asset com priority_score >= 7"
    action: "gerar Inbox alert tipo 'high-priority new asset' SEM auto-pentest"
    operator_actions: [autorizar_pentest_imediato, agendar_pentest, marcar_falso_positivo, ignorar]
    rationale: "Velocidade e diferencial competitivo em BB; novo subdomain pode ser janela 0-day. MAS Atlas observa nao corrige — operador autoriza execucao."
  new_cve_relevant_to_stack:
    condition: "recipe cve-monitor sinaliza CVE novo afetando tech do asset map"
    action: "gerar Inbox alert tipo 'cve match' com priorizacao automatica de retest focado naquela tech"
    operator_actions: [autorizar_retest_focado, agendar, ignorar]
    rationale: "CVE em stack do alvo no momento de divulgacao = janela curta antes de attacker generico. Atlas prioriza, operador confirma."
  cve_monitor_recipe: cve-monitor twitter-github-watch
```

### `cyber.bb.full-flow`

Flow flagship: orquestra fluxo BB completo (recon -> discovery -> exploit -> triage -> inbox).

```yaml
flow_id: cyber.bb.full-flow
proposed_owner_domain: cyber (futuro) | programming (interim)
extension_of: null
default_runtime: super_tool_runtime + engineering_harness
autonomy_default: low
background_allowed: true
surfaces: [mac_cli, app, api]
primary_skill: cyber-bb-runner
auxiliary_skills: [cyber-recon, cyber-pentest-webapp, cyber-bb-triage, comunicador-claro, orquestrador]
required_gates:
  - bb_program_proof
  - scope_validation
  - refusal_matrix_check
  - findings_have_evidence
  - severity_justified
  - dedup_against_program
  - human_review_before_submission
refusal_matrix_ref: cyber-security/refusal-matrix.md
evidence_required:
  - bb_program_snapshot
  - asset_map_artifact
  - findings_with_repro
  - bb_payload_draft
output_target: proposal_inbox_human_review
```

### `cyber.bb.triage`

Triagem isolada de findings ja produzidos (validar, severizar, dedupar, formatar).

```yaml
flow_id: cyber.bb.triage
proposed_owner_domain: cyber (futuro) | programming (interim)
extension_of: null
default_runtime: super_tool_runtime
autonomy_default: low
background_allowed: false
surfaces: [mac_cli, app]
primary_skill: cyber-bb-triage
auxiliary_skills: [comunicador-claro]
required_gates:
  - severity_justified
  - dedup_against_program
  - pii_redacted_in_evidence
  - human_review_before_submission
refusal_matrix_ref: cyber-security/refusal-matrix.md
evidence_required:
  - finding_validated
  - bb_payload_draft
output_target: proposal_inbox_human_review
```

### `cyber.purple.validate`

Atlas testa Atlas: Red executa playbook contra ambiente Purple, Detection observa, gap analysis.

```yaml
flow_id: cyber.purple.validate
proposed_owner_domain: cyber (futuro) | self_improvement (interim)
extension_of: null
default_runtime: engineering_harness + super_tool_runtime
autonomy_default: low
background_allowed: true
surfaces: [mac_cli]
primary_skill: cyber-purple-runner
auxiliary_skills: [cyber-recon, cyber-pentest-webapp, security-review]
required_gates:
  - purple_target_isolation
  - refusal_matrix_check
  - detection_pairing_required
refusal_matrix_ref: cyber-security/refusal-matrix.md
evidence_required:
  - purple_run_log
  - detection_outcomes
  - gap_analysis
  - curator_proposal_drafts
output_target: curator_proposal_review
```

### `cyber.ir.investigate`

Extensao do `security.incident_review` para investigacao ofensiva (forense, IOC hunt).

```yaml
flow_id: cyber.ir.investigate
proposed_owner_domain: security (extension) | cyber (futuro)
extension_of: security.incident_review
default_runtime: super_tool_runtime + engineering_harness
autonomy_default: low
background_allowed: false
surfaces: [mac_cli]
primary_skill: cyber-purple-runner
auxiliary_skills: [security-review, cyber-recon]
required_gates:
  - human_review_required
  - evidence_chain_of_custody
  - refusal_matrix_check
refusal_matrix_ref: cyber-security/refusal-matrix.md
evidence_required:
  - incident_timeline
  - ioc_hunt_results
  - postmortem_draft
output_target: incident_review_inbox
```

## Promotion path para flow individual

Flow vai de `proposal` para `implemented` quando:

1. Adicionar entry em `app/Services/Ai/AtlasDomainProfileRegistry.php` no array de flows do domain.
2. Adicionar config em `config/atlas_ai.php` se runtime preference exige.
3. Migration `YYYY_MM_DD_HHMMSS_register_<flow_id>_flow.php` se profile precisa entrar no banco.
4. Skill primaria com status `candidate` no minimo (eval passando).
5. Refusal matrix referenciada injetada como Policy via mecanismo canonico.
6. Test em `tests/Feature/Ai/Flows/<FlowName>Test.php`.
7. `php artisan atlas:ai:domains --json` mostra flow ready.
8. `php artisan atlas:ai:architecture-validate --json` passa.

## Anti-padroes

1. Skill primaria de flow ainda em `draft` — flow nao pode ser promovido.
2. Flow sem refusal matrix referenciada — Cyber-specific gate falha.
3. Output target diferente de `proposal_inbox_human_review` em flow BB final — quebra "Atlas observa, nao corrige".
4. Autonomia default `high` em flow Cyber — nunca; exige operador para confirmar acao mutativa.

## Resumo

Flow profiles propostos para a Cyber Security extension; nao registrados em AtlasDomainProfileRegistry ate onboarding formal de cada flow ou promocao a domain proprio.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
