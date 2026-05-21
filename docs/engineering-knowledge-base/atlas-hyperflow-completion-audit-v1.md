---
id: atlas-hyperflow-completion-audit-v1
type: engineering_knowledge
title: Atlas Hyperflow Completion Audit v1
status: active
category: atlas-ai
priority: 100
summary: Contrato canonico que transforma a Operacao Atlas Hyperflow em checklist auditavel de requisitos, evidencias, artefatos e blockers antes de qualquer claim contra Claude Code/Codex.
tags:
  - atlas-ai
  - hyperflow
  - certification
  - audit
  - rivals-battery
capabilities:
  - hyperflow_completion_certification_audit
  - hyperflow_completion_audit
  - evidence_contracts
  - rivals_battery
  - external_evidence_gate
decisions:
  - Completion audit deve mapear cada item do escopo obrigatorio para checks concretos.
  - Completion audit deve bloquear claims sem evidencia externa aprovada contra Claude Code/Codex.
  - Completion audit deve ser exposto pela API e pelo CLI de certificacao Hyperflow.
maintenance:
  - Atualize este doc quando o escopo obrigatorio da Operacao Atlas Hyperflow mudar.
  - Mantenha requirements e ready criteria alinhados com AtlasAiHyperflowCertificationService.
related_paths:
  - docs/engineering-knowledge-base/atlas-hyperflow-operation.md
  - docs/engineering-knowledge-base/atlas-ai-router-runtime-enterprise-upgrade.md
  - app/Services/Ai/Router/AtlasAiHyperflowCertificationService.php
  - app/Services/Ai/Router/AtlasAiHyperflowRivalsBatteryService.php
  - tests/Feature/Ai/AtlasAiHyperflowCertificationApiTest.php
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-hyperflow-completion-audit-v1
graph_title: Atlas Hyperflow Completion Audit v1
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-hyperflow-operation
graph_status: active
graph_source: repo
human_name: Atlas Hyperflow Completion Audit v1
canonical_name: Atlas Hyperflow Completion Audit v1
technical_name: atlas-hyperflow-completion-audit-v1
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-hyperflow-completion-audit-v1.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-hyperflow-completion-audit-v1.md
allowed_changes:
  - Ajustar requisitos quando a meta Hyperflow canonica mudar.
  - Adicionar artefatos de evidencia novos sem enfraquecer blockers.
forbidden_changes:
  - Permitir claim de substituicao sem external provider execution aprovada.
  - Remover rastreabilidade entre requisito, check e artefato.
depends_on:
  - atlas-hyperflow-operation
flows_to:
  - atlas-hyperflow-operation
  - atlas-ai-router-runtime-enterprise-upgrade
unlocks:
  - hyperflow_final_certification
  - replacement_claim_governance
governs:
  - AtlasAiHyperflowCertificationService
  - Hyperflow completion claim policy
evidence:
  - GET /ai/hyperflow/certification
  - php artisan atlas:ai:hyperflow certify --json
required_tests:
  - tests/Feature/Ai/AtlasAiHyperflowCertificationApiTest.php
requires_evidence: true
risk_level: high
next_actions:
  - Manter o audit alinhado ao escopo obrigatorio da Operacao Atlas Hyperflow.
---
# Atlas Hyperflow Completion Audit v1

## Resumo

`atlas.ai.hyperflow_completion_audit.v1` e o contrato de auditoria final da
Operacao Atlas Hyperflow. Ele transforma o escopo do operador em itens
verificaveis: requisito, evidencia, artefato e blocker.

Esse contrato existe para impedir conclusao falsa. O Hyperflow so pode declarar
pronto quando todos os itens de `requirements` e `ready_criteria` estiverem
`passed`.

## Papel no Atlas

O completion audit e o guardrail final entre backend funcional e claim de
substituicao de Claude Code/Codex. Ele nao executa flows; ele verifica se as
evidencias persistidas sustentam a conclusao.

`atlas.ai.hyperflow_external_evidence_gate.v1` e o contrato operacional que
explica como destravar o unico blocker externo: execucao comparavel e aprovada
de Claude Code/Codex.

## Onde Se Encaixa

`completion_audit` deve aparecer em toda resposta de:

- `GET /ai/hyperflow/certification`
- `php artisan atlas:ai:hyperflow certify --json`

## Contratos

Campos obrigatorios:

- `schema_version`: sempre `atlas.ai.hyperflow_completion_audit.v1`.
- `status`: `passed` ou `blocked`.
- `summary`: totais de requirements, ready criteria e blockers.
- `requirements`: lista dos 10 itens obrigatorios da operacao.
- `ready_criteria`: lista dos criterios finais de pronto.
- `remaining_blockers`: ids de itens ainda bloqueados.

Cada item em `requirements` ou `ready_criteria` deve conter:

- `id`
- `requirement`
- `status`
- `evidence_check_ids`
- `artifact_refs`
- `evidence`
- `blockers`

`external_evidence_gate` deve conter:

- `schema_version`: `atlas.ai.hyperflow_external_evidence_gate.v1`
- `status`: `passed` ou `blocked`
- `check_id`: `rivals_battery.external_provider_execution`
- `accepted_surfaces`: API manual, API de template, API de importacao, CLI de
  template, CLI de importacao e CLI de certificacao
- `required_manual_payload`: campos minimos para registrar evidencia manual
- `required_evidence_pack_fields`: campos minimos para importar evidence pack
- `latest_external_provider_execution`: ultimo estado observado do gate externo

## Fluxo

```text
checks -> requirements -> ready_criteria -> remaining_blockers -> claim_policy
```

## Regras para IA

- Nao declarar Hyperflow completo quando `completion_audit.status=blocked`.
- Nao transformar battery interna em prova externa contra Claude Code/Codex.
- Sempre apontar blockers por check id verificavel.

## Escopo de Implementacao

Requirements canonicos:

- `router_runtime_backend_first`
- `intent_kernel_ambiguous_prompts`
- `specialist_flows_deep_contracts`
- `dev_forge_delegation`
- `contracts_receipts_persistence_telemetry_audit`
- `aggregate_observability`
- `backend_readiness_certification_e2e`
- `rivals_battery_claude_code_codex`
- `canonical_docs`
- `tests_docs_health_pint_gates`

Ready criteria canonicos:

- `ambiguous_prompt_routes_correctly`
- `each_flow_has_own_tested_behavior`
- `atlas_dev_light_medium_with_evidence`
- `atlas_forge_heavy_obra_handoff`
- `receipts_hashes_telemetry`
- `final_certification_exists`
- `no_completion_without_verifiable_evidence`

## Dependencias

- Router runtime readiness
- Specialist flow execution contracts
- Dev/Forge delegation checks
- Hyperflow rivals battery
- External provider evidence receipt
- Canonical docs health

## Evidencias

O requisito `rivals_battery_claude_code_codex` e os criterios dependentes devem
ficar `blocked` ate existir evidencia externa aprovada com:

- `external_provider_call=true`
- `evidence_source=atlas_forge_rivals_export`
- `source_provenance_valid=true`
- baseline `claude_code`
- baseline `codex` ou `codex_cli`
- `protocol_valid=true`
- `comparable=true`
- `operator_approved=true`
- `evidence_receipt_hash` SHA-256 de 64 chars
- provider results aprovados com `evidence_hash` por provider

Sem isso, `claim_policy.ready_to_replace_claude_code_codex` e
`claim_policy.declare_100x_allowed` devem permanecer `false`.

Registro manual pela API pode registrar evidencia operacional, mas nao destrava
claim final de substituicao. O requisito final depende de evidence pack
proveniente do Forge/Rivals com source provenance valida.

O caminho canônico para produzir evidence pack a partir do Forge/Rivals é
`atlas.hyperflow.external_rivals_evidence_pack.v1`. Esse pack só pode ser
marcado elegível quando houver dois source runs reais: um baseline
`claude_code` e um baseline `codex_cli`. O export deve bloquear `local_fake`,
`dry_run`, receipts fake/test mode, hashes ausentes, clean check sujo,
`claim_ready=false` ou ausência de qualquer baseline.

Quando o export bloquear, ele nao deve escrever um evidence pack em disco. A
resposta canonica de bloqueio deve carregar `writes=false`, `evidence_pack=null`
e `rejected_evidence_pack.output_path_not_written`, para deixar claro que nenhum
artefato rejeitado pode ser importado ou usado como prova.

Superficies aceitas:

- `POST /ai/hyperflow/rivals-battery/external-evidence`
- `GET /ai/hyperflow/rivals-battery/external-evidence/template`
- `GET /ai/hyperflow/rivals-battery/external-evidence/candidates`
- `GET /ai/hyperflow/rivals-battery/external-evidence/runbook`
- `POST /ai/hyperflow/rivals-battery/external-evidence/preflight`
- `POST /ai/hyperflow/rivals-battery/external-evidence/export`
- `POST /ai/hyperflow/rivals-battery/external-evidence/import`
- `php artisan atlas:ai:hyperflow evidence-template --json`
- `php artisan atlas:ai:hyperflow evidence-template --output-path=<path> --json`
- `php artisan atlas:ai:hyperflow evidence-candidates --provider=codex_cli --json`
- `php artisan atlas:ai:hyperflow evidence-runbook --json`
- `php artisan atlas:ai:hyperflow evidence-preflight --forge-run-ids=<claude_run>,<codex_run> --json`
- `php artisan atlas:ai:hyperflow export-evidence --forge-run-ids=<claude_run>,<codex_run> --operator-approved --approved-by=<operator> --json`
- `php artisan atlas:ai:hyperflow import-evidence --evidence-pack=<path> --operator-approved --approved-by=<operator> --json`
- `php artisan atlas:ai:hyperflow certify --json`

## Riscos

- Claim falsa por confundir replay interno com comparacao real.
- Drift entre escopo do operador e checks de certificacao.
- Evidencia externa importada sem protocolo comparavel.

## Exemplos

Quando falta execucao externa, `completion_audit.status=blocked` e
`remaining_blockers` inclui itens dependentes de
`rivals_battery.external_provider_execution`.

## Proximas Acoes

Manter este contrato sincronizado com `AtlasAiHyperflowCertificationService` e
com os testes de `AtlasAiHyperflowCertificationApiTest`.
