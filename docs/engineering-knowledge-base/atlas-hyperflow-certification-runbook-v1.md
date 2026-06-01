---
id: atlas-hyperflow-certification-runbook-v1
type: engineering_knowledge
title: Atlas Hyperflow Certification Runbook v1
status: active
category: atlas-ai
priority: 100
summary: Runbook canonico para preparar, executar, exportar, importar e certificar a rivals battery Hyperflow contra Claude Code/Codex sem destravar claim sem evidencia externa real.
tags:
  - atlas-ai
  - hyperflow
  - certification
  - rivals-battery
  - evidence-pack
capabilities:
  - hyperflow_certification
  - hyperflow_rivals_battery
  - external_evidence_export
  - external_evidence_import
decisions:
  - Certificacao Hyperflow consome evidencias persistidas; nao chama provider externo.
  - Export de evidence pack deve aceitar apenas runs Forge/Rivals reais e comparaveis.
  - Import de evidence pack deve falhar fechado para local fake, dry run ou replay-only.
maintenance:
  - Atualize este runbook quando endpoints, comandos ou evidence pack schema mudarem.
  - Mantenha o doc principal Hyperflow como mapa executivo; detalhes operacionais ficam aqui.
related_paths:
  - docs/engineering-knowledge-base/atlas-hyperflow-operation.md
  - docs/engineering-knowledge-base/atlas-hyperflow-completion-audit-v1.md
  - app/Console/Commands/AtlasAiHyperflowCommand.php
  - app/Http/Controllers/AtlasAiHyperflowRivalsBatteryController.php
  - app/Services/Ai/Router/AtlasAiHyperflowRivalsBatteryService.php
  - tests/Feature/Ai/AtlasAiHyperflowCertificationApiTest.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-hyperflow-certification-runbook-v1
graph_title: Atlas Hyperflow Certification Runbook v1
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-hyperflow-operation
graph_status: active
graph_source: repo
human_name: Atlas Hyperflow Certification Runbook v1
canonical_name: Atlas Hyperflow Certification Runbook v1
technical_name: atlas-hyperflow-certification-runbook-v1
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/atlas-hyperflow-certification-runbook-v1.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-hyperflow-certification-runbook-v1.md
allowed_changes:
  - Adicionar endpoints e comandos que reforcem evidencia verificavel.
  - Atualizar payloads quando os contratos de API mudarem.
forbidden_changes:
  - Transformar replay interno em evidencia externa real.
  - Permitir import/export sem aprovacao do operador e receipts verificaveis.
depends_on:
  - atlas-hyperflow-operation
  - atlas-hyperflow-completion-audit-v1
flows_to:
  - atlas-hyperflow-completion-audit-v1
unlocks:
  - hyperflow_external_provider_execution
  - hyperflow_final_certification
governs:
  - AtlasAiHyperflowCommand
  - AtlasAiHyperflowRivalsBatteryController
  - AtlasAiHyperflowRivalsBatteryService
evidence:
  - php artisan atlas:ai:hyperflow certify --json
  - GET /ai/hyperflow/certification
evidence_refs:
  - command: atlas:ai:hyperflow
  - symbol: AtlasAiHyperflowRivalsBatteryController
  - test: AtlasAiHyperflowCertificationApiTest
required_tests:
  - tests/Feature/Ai/AtlasAiHyperflowCertificationApiTest.php
requires_evidence: true
risk_level: high
next_actions:
  - Rodar/importar evidencia externa real Claude Code/Codex para destravar certificacao.
---
# Atlas Hyperflow Certification Runbook v1

## Resumo

Este runbook define como operar a certificacao backend Hyperflow. Ele cobre
prepare, run, template, export, import e certify.

## Papel no Atlas

O runbook e o caminho operacional entre battery interna verde e claim final
contra Claude Code/Codex. Ele existe para que operador, CI ou outra IA saibam
como produzir evidencia externa sem inventar contrato.

## Onde Se Encaixa

```text
Router/flows prontos -> rivals battery interna -> evidencia externa -> certification
```

## Contratos

- `atlas.ai.hyperflow_rivals_battery.v1`
- `atlas.ai.hyperflow_rivals_battery_receipt.v1`
- `atlas.ai.hyperflow_external_evidence_pack_template.v1`
- `atlas.hyperflow.external_rivals_evidence_pack.v1`
- `atlas.ai.hyperflow_external_rivals_evidence_receipt.v1`
- `atlas.ai.hyperflow_certification.v1`

## Fluxo

```text
prepare -> run -> template/export -> import -> certify
```

## Regras para IA

- Nao chamar Claude Code/Codex dentro da certificacao.
- Nao declarar substituicao usando apenas contract replay interno.
- Nao importar packs locais, fake, dry run, diagnosticos ou sem provider real.
- Sempre exigir baselines `claude_code` e `codex`/`codex_cli`.

## Escopo de Implementacao

API canonica:

- `POST /ai/hyperflow/rivals-battery/prepare`
- `GET /ai/hyperflow/rivals-battery`
- `POST /ai/hyperflow/rivals-battery/run`
- `POST /ai/hyperflow/rivals-battery/external-evidence`
- `GET /ai/hyperflow/rivals-battery/external-evidence/template`
- `GET /ai/hyperflow/rivals-battery/external-evidence/candidates`
- `GET /ai/hyperflow/rivals-battery/external-evidence/runbook`
- `POST /ai/hyperflow/rivals-battery/external-evidence/preflight`
- `POST /ai/hyperflow/rivals-battery/external-evidence/export`
- `POST /ai/hyperflow/rivals-battery/external-evidence/import`
- `GET /ai/hyperflow/certification`

CLI canonico:

- `php artisan atlas:ai:hyperflow prepare --json`
- `php artisan atlas:ai:hyperflow rivals --json`
- `php artisan atlas:ai:hyperflow run --prepare --json`
- `php artisan atlas:ai:hyperflow evidence-template --json`
- `php artisan atlas:ai:hyperflow evidence-template --output-path=<path> --json`
- `php artisan atlas:ai:hyperflow evidence-candidates --provider=codex_cli --json`
- `php artisan atlas:ai:hyperflow evidence-runbook --json`
- `php artisan atlas:ai:hyperflow evidence-preflight --forge-run-ids=<claude_run>,<codex_run> --json`
- `php artisan atlas:ai:hyperflow export-evidence --forge-run-ids=<claude_run>,<codex_run> --operator-approved --approved-by=<operator> --json`
- `php artisan atlas:ai:hyperflow import-evidence --evidence-pack=<path> --operator-approved --approved-by=<operator> --json`
- `php artisan atlas:ai:hyperflow certify --json`

## Dependencias

- Tabelas `atlas_engineering_benchmark_*`.
- Router Runtime readiness verde.
- Specialist flow execution contracts.
- Runs Forge/Rivals reais quando usar export.
- Evidence pack aprovado quando usar import.

## Evidencias

O gate final exige:

- `external_provider_call=true`
- `provider_tokens_spent=true`
- `evidence_source=atlas_forge_rivals_export`
- `source_provenance_valid=true`
- `protocol_valid=true`
- `comparable=true`
- `operator_approved=true`
- `evidence_receipt_hash` SHA-256 de 64 chars
- `provider_results.claude_code.status=passed`
- `provider_results.codex` ou `provider_results.codex_cli` com `status=passed`
- `evidence_hash` SHA-256 por provider

## Runbook De Providers Externos

Use `php artisan atlas:ai:hyperflow evidence-runbook --json` como fonte
canonica dos comandos de provider externo. O payload deve expor:

- `candidate_summary.latest_setup_runs`: setups Forge/Rivals detectados.
- `candidate_summary.setup_run_allocation`: setup escolhido por provider.
- `commands.run_missing_providers.<provider>.command`: comando real com flags
  explicitas de custo e provider call.
- `operator_action_packet`: pacote auditavel para o operador, com blocker atual,
  comandos reais pendentes, passos pos-execucao e condicao objetiva de pronto.

Regra critica: nao reutilizar o mesmo setup/run para `claude_code` e
`codex_cli` quando ambos estao faltando. Cada baseline externa precisa de
workspace, receipt e evidence pack proprios. Se faltar setup para um provider,
rode `php artisan atlas:forge:rivals setup --source-ref=HEAD --json` antes do
`run-real`.

Regra de export fail-closed: `export-evidence` só pode escrever arquivo quando
o pack for elegivel. Quando o export estiver bloqueado, a resposta deve retornar
`external_evidence_export_blocked`, `ready=false`, `writes=false`,
`evidence_pack=null` e `rejected_evidence_pack.output_path_not_written`.

Regra de claim final: registro manual pode persistir evidencias operacionais,
mas nao certifica substituicao de Claude Code/Codex. A certificacao final exige
provenance `atlas_forge_rivals_export` validada por evidence pack real.

## Riscos

- Confundir run interno Atlas com baseline rival externa.
- Exportar run Forge/Rivals fake como evidencia real.
- Importar pack manual sem receipts e hashes verificaveis.

## Exemplos

Gerar template:

```bash
php artisan atlas:ai:hyperflow evidence-template --output-path=storage/app/hyperflow-template.json --json
```

Exportar runs reais:

```bash
php artisan atlas:ai:hyperflow evidence-preflight --forge-run-ids=<claude_run>,<codex_run> --json
php artisan atlas:ai:hyperflow export-evidence --forge-run-ids=<claude_run>,<codex_run> --operator-approved --approved-by=operator --json
```

Importar e certificar:

```bash
php artisan atlas:ai:hyperflow import-evidence --evidence-pack=<path> --operator-approved --approved-by=operator --json
php artisan atlas:ai:hyperflow certify --json
```

## Proximas Acoes

Rodar/importar evidencia externa real de Claude Code/Codex. Sem isso,
`rivals_battery.external_provider_execution` deve permanecer bloqueado.
