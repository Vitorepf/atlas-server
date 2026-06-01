---
id: atlas-domain-cyber-runtime
type: engineering_knowledge
title: Atlas Cyber Security Company Runtime
status: active
category: atlas-ai
priority: 100
summary: Runtime canonico do dominio Cyber Security Company. Implementa engagement intake, scope/RoE, AppSec, GRC, remediation, defensive security review, authorized bug bounty intake (com gates de authorization+scope+RoE+legal+privacy) e evidence chain auditavel. Defensivo-first. NUNCA executa exploit, scan, coleta de segredo ou acao ofensiva automatica. Meta cyber backend entregue 2026-05-18.
tags:
  - atlas-ai
  - cyber-security
  - appsec
  - grc
  - remediation
  - defensive-security
  - authorized-bug-bounty
  - evidence-chain
capabilities:
  - cyber_engagement_intake
  - scope_and_rules_of_engagement
  - appsec_review
  - grc_mapping
  - remediation_plan
  - defensive_security_review
  - authorized_bug_bounty_intake
  - cyber_evidence_chain
  - offensive_refusal_matrix
decisions:
  - Cyber Company Runtime e DEFENSIVO-first. Nao executa exploit, scan, coleta de credencial nem qualquer acao ofensiva.
  - Bug bounty / pentest sao INTAKE/PLANNING only. Execucao real fica em mao de profissional externo autorizado com infra propria.
  - Authorized bug bounty intake exige authorization_present=true, authorization_doc completo, scope_parsed, roe_documented, legal_gate_passed e privacy_gate_passed. Sem todos os gates verdes o intake fica `blocked` com blockers explicitos.
  - Engagement de tipo authorized_bug_bounty NAO pode ser criado sem authorization_present=true.
  - Evidence chain e append-only com SHA-256 encadeado (entry_hash = sha256(payload + previous_hash)). Integridade verificada por CyberEvidenceChainService::verify().
  - CyberRuntimeService::refuseOffensive() e o canonical refusal gate. Lista FORBIDDEN_OFFENSIVE_VERBS cobre execute_exploit/scan, collect_credentials, disable_control, unauthorized_pentest/recon, supply_chain_publish, register_typosquat, submit_malicious_pr.
maintenance:
  - Atualize este doc antes de adicionar novo framework GRC, kind de defensive review ou refusal matrix entry.
  - Sincronize FORBIDDEN_OFFENSIVE_VERBS com refusal-matrix.md em cyber-security/ antes de aceitar novo PR.
  - Nunca relaxar os gates de bug bounty intake; novo gate vai SOMENTE como check adicional, jamais substituicao.
related_paths:
  - docs/engineering-knowledge-base/atlas-domain-company-runtimes.md
  - docs/engineering-knowledge-base/atlas-domain-runtime-contract.md
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
  - docs/engineering-knowledge-base/atlas-permission-budget-safety-layer.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - docs/engineering-knowledge-base/domains/security.md
  - docs/engineering-knowledge-base/cyber-security-extension.md
  - docs/engineering-knowledge-base/cyber-security/refusal-matrix.md
  - docs/engineering-knowledge-base/cyber-security/compliance-mapping.md
  - docs/engineering-knowledge-base/cyber-security/remediation-patterns.md
  - database/migrations/2026_05_18_050000_create_ai_cyber_runtime_tables.php
  - app/Models/AiCyberEngagement.php
  - app/Models/AiCyberScopeRules.php
  - app/Models/AiAppSecReview.php
  - app/Models/AiGrcMapping.php
  - app/Models/AiRemediationPlan.php
  - app/Models/AiDefensiveSecurityReview.php
  - app/Models/AiBugBountyIntake.php
  - app/Models/AiCyberEvidenceChainEntry.php
  - app/Services/Ai/Cyber/CyberCanonicalHash.php
  - app/Services/Ai/Cyber/CyberDomainException.php
  - app/Services/Ai/Cyber/CyberDomainManifestSeeder.php
  - app/Services/Ai/Cyber/CyberEngagementIntakeService.php
  - app/Services/Ai/Cyber/CyberScopeRulesOfEngagementService.php
  - app/Services/Ai/Cyber/AppSecReviewService.php
  - app/Services/Ai/Cyber/GRCMappingService.php
  - app/Services/Ai/Cyber/RemediationPlanService.php
  - app/Services/Ai/Cyber/DefensiveSecurityReviewService.php
  - app/Services/Ai/Cyber/AuthorizedBugBountyIntakeService.php
  - app/Services/Ai/Cyber/CyberEvidenceChainService.php
  - app/Services/Ai/Cyber/CyberRuntimeService.php
  - app/Services/Ai/Cyber/CyberReadinessService.php
  - app/Services/Ai/Cyber/CyberControlPlaneProjection.php
  - app/Console/Commands/AtlasAiCyberDomainCommand.php
  - tests/Concerns/CreatesCyberRuntimeTables.php
  - tests/Feature/Ai/Cyber/CyberDomainReadinessTest.php
  - tests/Feature/Ai/Cyber/CyberDomainSmokeTest.php
  - tests/Feature/Ai/Cyber/CyberDomainEngagementTest.php
  - tests/Feature/Ai/Cyber/CyberDomainScopeRoeTest.php
  - tests/Feature/Ai/Cyber/CyberDomainAppSecTest.php
  - tests/Feature/Ai/Cyber/CyberDomainBugBountyIntakeTest.php
  - tests/Feature/Ai/Cyber/CyberDomainEvidenceChainTest.php
  - tests/Feature/Ai/Cyber/CyberDomainOffensiveGuardsTest.php
  - tests/Feature/Ai/Cyber/CyberDomainControlPlaneTest.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-domain-cyber-runtime
graph_title: Atlas Cyber Security Company Runtime
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-domain-company-runtimes
graph_status: active
graph_source: repo
human_name: Atlas Cyber Security Company Runtime
canonical_name: Atlas Cyber Security Company Runtime
technical_name: atlas-domain-cyber-runtime
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-domain-cyber-runtime.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-domain-cyber-runtime.md
allowed_changes:
  - Adicionar novos frameworks GRC, kinds de defensive review ou owasp categories com testes sincronizados.
  - Estender FORBIDDEN_OFFENSIVE_VERBS adicionando entradas (nunca remover).
  - Adicionar gates extras em AuthorizedBugBountyIntakeService como check adicional (nunca substituicao).
forbidden_changes:
  - Permitir execucao de exploit, scan, coleta de credencial ou qualquer acao ofensiva direta.
  - Remover qualquer dos cinco gates de bug bounty intake (authorization_present, scope_parsed, roe_documented, legal_gate_passed, privacy_gate_passed).
  - Remover engagement.authorization_present obrigatorio para authorized_bug_bounty.
  - Quebrar evidence chain (entry_hash NUNCA pode ser mutavel; chain NUNCA pode pular previous_hash).
depends_on:
  - atlas-domain-runtime-contract
  - atlas-autonomous-intelligence-operating-system
  - atlas-permission-budget-safety-layer
flows_to:
  - atlas-evidence-certification-runtime
  - atlas-domain-company-runtimes
unlocks:
  - defensive-cyber-runtime-with-evidence-chain
  - authorized-bug-bounty-intake-gating
governs:
  - atlas_ai.cyber.engagement
  - atlas_ai.cyber.scope_and_roe
  - atlas_ai.cyber.appsec_review
  - atlas_ai.cyber.grc_mapping
  - atlas_ai.cyber.remediation_plan
  - atlas_ai.cyber.defensive_review
  - atlas_ai.cyber.authorized_bug_bounty_intake
  - atlas_ai.cyber.evidence_chain
evidence:
  - tests/Feature/Ai/Cyber/CyberDomainSmokeTest.php
  - tests/Feature/Ai/Cyber/CyberDomainOffensiveGuardsTest.php
  - tests/Feature/Ai/Cyber/CyberDomainBugBountyIntakeTest.php
  - tests/Feature/Ai/Cyber/CyberDomainEvidenceChainTest.php
evidence_refs:
  - test: CyberDomainSmokeTest
  - symbol: AiCyberEngagement
  - command: atlas:ai:cyber-domain
required_tests:
  - "/opt/homebrew/bin/php artisan test --filter=CyberDomain"
  - "/opt/homebrew/bin/php artisan atlas:ai:cyber-domain --action=readiness --json"
  - "/opt/homebrew/bin/php artisan atlas:ai:cyber-domain --action=smoke --json"
  - "/opt/homebrew/bin/php artisan atlas:ai:cyber-domain --action=control-plane --json"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: critical
ai_entrypoints:
  - Leia Estado Atual, Refusal Matrix, Authorization Gates e Evidence Chain antes de implementar.
ai_usage_notes:
  - Cyber runtime e defensivo. Nunca propor codigo que execute exploit/scan.
  - Bug bounty: somente intake/planning, jamais execucao.
quality_gates:
  - engagement-recorded
  - scope-and-roe-defined
  - authorization-evaluated
  - evidence-chain-started
  - no-offensive-action-executed
failure_modes:
  - Permitir engagement de bug bounty sem authorization_present.
  - Liberar bug bounty intake com algum gate (scope/RoE/legal/privacy) faltando.
  - Quebrar a integridade da evidence chain (previous_hash inconsistente).
  - Adicionar capability que execute scan/exploit.
observability_signals:
  - engagement_id
  - engagement_kind
  - authorization_present
  - bug_bounty_intake_status
  - evidence_chain_entry_count
  - evidence_chain_integrity_ok
next_actions:
  - Coordenar com Meta 2 (Domain Runtime) para registrar manifest do dominio cyber via seed-manifest action.
  - Coordenar com Meta 3 (Policy) para policy_profile=cyber.default com forbidden_actions sincronizadas.
  - Coordenar com Meta 9 (Control Plane) para projetar CyberControlPlaneProjection no agregador global.
line_limit: 520
---
# Atlas Cyber Security Company Runtime

## Estado Atual

Meta cyber backend entregue 2026-05-18 como Domain Company Runtime
plugavel sob Domain Runtime Contract (Meta 2). Implementa o ciclo canonico:

```text
Engagement Intake (authorization_present?)
  -> Scope & Rules of Engagement (allowed/forbidden techniques + escalation)
  -> AppSec Review (OWASP categories + findings + risk score 0-10)
  -> GRC Mapping (NIST_CSF/800_53/ISO_27001/SOC2/PCI/HIPAA/LGPD/GDPR/CIS)
  -> Remediation Plan (severity + findings + actions + owners + timeline)
  -> Defensive Security Review (threat_model | network | identity | incident | detection | data_protection)
  -> Authorized Bug Bounty Intake (5 gates: authorization + scope + RoE + legal + privacy)
  -> Evidence Chain (append-only, SHA-256 chain, verifiable)
  -> Evidence Pack + Certification (via Meta 4)
```

- **8 tabelas**: `ai_cyber_engagements`, `ai_cyber_scope_rules`,
  `ai_appsec_reviews`, `ai_grc_mappings`, `ai_remediation_plans`,
  `ai_defensive_security_reviews`, `ai_bug_bounty_intakes`,
  `ai_cyber_evidence_chain`.
- **8 models**: `AiCyberEngagement`, `AiCyberScopeRules`, `AiAppSecReview`,
  `AiGrcMapping`, `AiRemediationPlan`, `AiDefensiveSecurityReview`,
  `AiBugBountyIntake`, `AiCyberEvidenceChainEntry`.
- **13 services** em `app/Services/Ai/Cyber/`: `CyberCanonicalHash`,
  `CyberDomainException`, `CyberDomainManifestSeeder`,
  `CyberEngagementIntakeService`, `CyberScopeRulesOfEngagementService`,
  `AppSecReviewService`, `GRCMappingService`, `RemediationPlanService`,
  `DefensiveSecurityReviewService`, `AuthorizedBugBountyIntakeService`,
  `CyberEvidenceChainService`, `CyberRuntimeService`,
  `CyberReadinessService`, `CyberControlPlaneProjection`.
- **Comando** `atlas:ai:cyber-domain` com actions
  `readiness | smoke | control-plane | seed-manifest`.
- **9 feature tests** (27 tests / 61 assertions verdes) cobrindo readiness,
  smoke end-to-end (chain completa + cert=passed), engagement intake
  (authorization gate), scope/RoE required fields, AppSec review + GRC
  mapping + remediation plan, bug bounty intake (5 gates), evidence chain
  integrity, offensive refusal matrix, control-plane.

### Comandos canonicos

```bash
/opt/homebrew/bin/php artisan atlas:ai:cyber-domain --action=readiness --json
/opt/homebrew/bin/php artisan atlas:ai:cyber-domain --action=smoke --json
/opt/homebrew/bin/php artisan atlas:ai:cyber-domain --action=control-plane --json
/opt/homebrew/bin/php artisan atlas:ai:cyber-domain --action=seed-manifest --json
```

## Resumo

Atlas Cyber Security Company Runtime e o dominio defensivo plugavel do
Autonomous Intelligence OS. Faz upgrade do dominio `security` (review-only)
para uma empresa digital defensiva completa com 8 departamentos: engagement
intake, scope & RoE, AppSec, GRC, remediation, defensive security review,
authorized bug bounty intake e evidence chain. Nunca executa exploit, scan
ou ato ofensivo automatico.

## Papel no Atlas

Plugga sob Domain Runtime Contract (Meta 2) e e o dominio canonico para
defensive cyber: AppSec, GRC e remediation. Bug bounty / pentest sao apenas
INTAKE/PLANNING com 5 gates de autorizacao; execucao real fica em mao de
profissionais externos autorizados. Composivel com `security` (review-only) e
`programming.security` (codigo nivel-repo).

## Onde Se Encaixa

```text
Mission Foundation (Meta 1)
 -> Domain Runtime Contract (Meta 2)
    -> Cyber Domain Manifest (`cyber`)
       -> CyberRuntimeService.driveDefensiveReview()
          -> CyberEngagementIntakeService (authorization gate)
          -> CyberScopeRulesOfEngagementService
          -> AppSecReviewService + GRCMappingService + RemediationPlanService
          -> DefensiveSecurityReviewService
          -> AuthorizedBugBountyIntakeService (5 gates)
          -> CyberEvidenceChainService (SHA-256 chain)
          -> Evidence Runtime adapter (Meta 4)
       -> CyberControlPlaneProjection
```

## Contratos

- `atlas.ai.cyber.engagement.v1`
- `atlas.ai.cyber.scope_rules.v1`
- `atlas.ai.cyber.appsec_review.v1`
- `atlas.ai.cyber.grc_mapping.v1`
- `atlas.ai.cyber.remediation_plan.v1`
- `atlas.ai.cyber.defensive_review.v1`
- `atlas.ai.cyber.bug_bounty_intake.v1`
- `atlas.ai.cyber.evidence_chain.v1`
- `atlas.ai.cyber.control_plane.v1`
- `atlas.ai.cyber.readiness.v1`

Hash determinstico via `CyberCanonicalHash::sha256(...)` em cada artifact
(`engagement_hash`, `rules_hash`, `review_hash`, `mapping_hash`, `plan_hash`,
`intake_hash`, `entry_hash`).

## Refusal Matrix (offensive verbs blocked)

`CyberRuntimeService::FORBIDDEN_OFFENSIVE_VERBS`:

- `execute_exploit`
- `execute_scan`
- `launch_nuclei`, `launch_nmap`, `launch_ffuf`, `launch_metasploit`
- `collect_credentials`
- `dump_secrets`
- `disable_control`
- `unauthorized_pentest`
- `unauthorized_recon`
- `supply_chain_publish`
- `register_typosquat`
- `submit_malicious_pr`

Chamar `refuseOffensive($verb)` dispara `CyberDomainException::forbiddenOffensive`
com mensagem auditavel.

## Authorization Gates (Bug Bounty Intake)

`AuthorizedBugBountyIntakeService::intake()` exige:

1. `engagement.authorization_present === true` (caso contrario throw imediato).
2. `authorization_present === true` + `authorization_doc` como array com URL,
   contatos e datas (caso contrario throw).
3. `scope_parsed === true`.
4. `roe_documented === true`.
5. `legal_gate_passed === true`.
6. `privacy_gate_passed === true`.

Status final:
- todos green -> `authorized` (apto a planejar handoff para profissional externo)
- algum falha -> `blocked` com `blockers` listando os gates pendentes

## Evidence Chain

`CyberEvidenceChainService::append()` produz uma entrada cuja `entry_hash` e
SHA-256 sobre `(payload + previous_hash)`. Recupera-se a integridade via
`verify($engagement)` que percorre as entradas em ordem e checa
`entry.previous_hash === lastSeen.entry_hash`.

Kinds suportados: `authorization`, `scope`, `roe`, `legal_review`,
`privacy_review`, `appsec_review`, `grc_evidence`, `defensive_review`,
`remediation`, `bug_bounty_intake`, `operator_decision`.

## Fluxo

1. `CyberEngagementIntakeService::intake()` cria engagement; bloqueia
   `authorized_bug_bounty` sem authorization_present.
2. `CyberScopeRulesOfEngagementService::define()` documenta in/out of scope,
   allowed/forbidden techniques, escalation contacts.
3. `AppSecReviewService::review()` registra OWASP categories + findings +
   recommendations + risk_score (0..10).
4. `GRCMappingService::map()` mapeia controle de framework (NIST CSF/800-53,
   ISO 27001, SOC 2, PCI DSS, HIPAA, LGPD/GDPR, CIS v8).
5. `RemediationPlanService::propose()` exige findings_refs, actions, owners,
   timeline.
6. `DefensiveSecurityReviewService::review()` registra revisao defensiva por
   kind (threat_model, network, identity, incident, detection, data_protection).
7. `AuthorizedBugBountyIntakeService::intake()` aplica os 5 gates.
8. `CyberEvidenceChainService::append()` registra cada passo na chain.
9. `CyberRuntimeService::driveDefensiveReview()` orquestra o ciclo completo +
   `CertificationRuntimeService::certify` via Meta 4 quando presente.

## Regras para IA

- Nao gerar codigo que execute exploit/scan; o runtime recusa por design.
- Bug bounty: somente intake/planning. Execucao real e externa.
- Sempre documentar evidencia: cada passo significativo precisa de entrada
  na evidence chain.
- Engagement sem autorizacao pode rodar como defensive review com `intake`
  status; mas authorized_bug_bounty exige authorization upfront.

## Escopo de Implementacao

Em escopo: defensive review chain end-to-end com evidence + Meta 4 adapter.
Fora de escopo: execucao ofensiva, qualquer integracao com tools de pentest
ativas, supply chain publishing, registro de typosquats. Esses pertencem a
operacao externa autorizada com infra propria.

## Dependencias

- Domain Runtime Contract (Meta 2).
- Evidence Runtime (Meta 4) - opcional, integracao tolerante.
- Laravel 13 + PHP 8.4 + HasUuids.

## Evidencias

- `CyberDomainSmokeTest`: chain completo -> engagement=authorized,
  bounty=authorized, evidence_chain_integrity=true, certification=passed.
- `CyberDomainOffensiveGuardsTest`: refusal matrix bloqueia execute_*.
- `CyberDomainBugBountyIntakeTest`: 5 gates aplicados; qualquer gate ausente
  bloqueia.
- `CyberDomainEvidenceChainTest`: chain encadeado + verify.

## Riscos

- Risco operacional: convencer um operador de que defensive_review autoriza
  scan externo. Mitigacao: FORBIDDEN_OFFENSIVE_VERBS bloqueia explicitamente.
- Risco regulatorio: bug bounty sem authorization. Mitigacao: 5 gates +
  authorization_present obrigatorio.
- Risco de tampering: chain invalidada. Mitigacao: `verify()` deteta.

## Exemplos

```bash
php artisan atlas:ai:cyber-domain --action=smoke --json
# -> engagement_status=authorized, bug_bounty_status=authorized,
#    evidence_chain_integrity_ok=true, certification_status=passed
```

## Proximas Acoes

1. Registrar manifest do dominio via `atlas:ai:cyber-domain --action=seed-manifest`
   apos Meta 2 estabilizar.
2. Conectar `CyberControlPlaneProjection` ao agregador global do Atlas
   Control Plane (Meta 9) como source `cyber`.
3. Coordenar com Meta 3 (Policy) para `policy_profile=cyber.default` antes
   de transitar para qualquer acao com side-effect.

## Definition of Done

Atendido quando:
- engagement de authorized_bug_bounty rejeitado sem authorization_present;
- intake bloqueado se qualquer dos 5 gates faltar;
- chain de evidencia mantem integridade SHA-256 encadeada;
- offensive refusal matrix dispara CyberDomainException;
- `php artisan test --filter=CyberDomain` verde;
- `atlas:ai:cyber-domain readiness/smoke/control-plane` retornam JSON ok.
