---
id: atlas-super-tool-runtime-core
type: engineering_knowledge
title: Atlas Super Tool Runtime Core
status: active
category: architecture
priority: 98
summary: Fundacao transversal para registrar, governar, executar, normalizar e persistir evidencias de ferramentas locais ou project-local usadas pelo Atlas.
tags:
  - atlas
  - tools
  - harness
  - evidence
capabilities:
  - tool_registry
  - tool_policy_engine
  - tool_executor
  - result_normalizer
  - evidence_store
decisions:
  - Ferramentas entram pelo registry canonico antes de virarem automacao recorrente.
  - Ferramentas ausentes geram estado auditavel em vez de silencio.
  - Quality Scan registra evidencias tambem no runtime generico sem remover os artifacts existentes do Engineering Harness.
  - Sensores internos do Atlas tambem sao tools registradas quando produzem evidencia operacional.
maintenance:
  - Rode atlas tools doctor --workspace=<repo> depois de adicionar uma ferramenta ao catalogo.
  - Rode atlas tools run <tool> --dry-run antes de habilitar execucao nova em fluxo automatico.
  - Rode atlas engineering knowledge sync --prune e atlas engineering knowledge index-code --prune depois de alterar esta camada.
related_paths:
  - app/Services/Tools/AtlasToolRegistryService.php
  - app/Services/Tools/AtlasToolPolicyEngine.php
  - app/Services/Tools/AtlasToolExecutor.php
  - app/Services/Tools/AtlasToolApprovalService.php
  - app/Services/Tools/AtlasToolEvidenceStore.php
  - app/Services/Tools/AtlasToolResultNormalizer.php
  - app/Services/Engineering/EngineeringQualityScanService.php
  - app/Services/Engineering/EngineeringCodeIntelligenceService.php
  - app/Console/Commands/AtlasToolsCommand.php
  - app/Console/Commands/AtlasEngineeringVisualSmokeCommand.php
  - app/Http/Controllers/AtlasToolRuntimeController.php
  - database/migrations/2026_05_02_011000_create_atlas_tool_runtime_tables.php
  - tests/Feature/AtlasToolRuntimeCoreTest.php
---

# Atlas Super Tool Runtime Core

Esta camada e a base generica abaixo do Engineering Harness para ferramentas
locais, gratuitas, project-local ou Atlas-managed. Ela evita integrar cada
ferramenta como fluxo isolado.

## Fase 0 Implementada

O bloco inicial entrega:

- `atlas_tool_definitions` como catalogo persistente de ferramentas;
- `atlas_tool_installations` como estado detectado por workspace e camada;
- `atlas_tool_policies` para overrides por escopo;
- `atlas_tool_runs`, `atlas_tool_artifacts` e `atlas_tool_findings` como Evidence Store transversal;
- catalogo inicial para Git, ripgrep, Composer, Pint, PHPStan, Psalm, TypeScript, Biome, ESLint, ShellCheck, Hadolint, Gitleaks, Semgrep, Playwright, Cypress, Docker, Trivy, Syft, Grype, OSV-Scanner e sensores internos `atlas_code_intelligence` e `atlas_visual_smoke`;
- `AtlasToolPolicyEngine` com decisoes auditaveis `allowed`, `denied`, `requires_approval` e `skipped`;
- `AtlasToolExecutor` com cwd controlado, argv array, timeout, dry-run, output redigido e rejeicao de argumentos inseguros;
- `AtlasToolResultNormalizer` com contrato comum de status, findings, metrics, artifacts, recommendations e blocking failures;
- normalizacao estruturada compartilhada para outputs JSON de Gitleaks, Semgrep, ESLint, PHPStan, Psalm, ShellCheck, Trivy e OSV-Scanner;
- `AtlasToolEvidenceStore` para persistir runs, artifacts, hashes e findings normalizados;
- `AtlasToolEvidenceQueryService` para consultar evidencias recentes com filtros por workspace, tool, surface, status, policy decision, required e contexto;
- CLI `atlas tools doctor|list|status|run|evidence|approve|revoke|policies`;
- API `GET /tools`, `GET /tools/doctor`, `GET /tools/evidence`, `GET /tools/policies`, `GET /tools/{tool}`, `POST /tools/{tool}/run`, `POST /tools/{tool}/approval` e `DELETE /tools/{tool}/approval`;
- `AtlasToolApprovalService` para aprovacoes auditaveis por workspace/global, TTL, motivo, operador, permissao de rede e revogacao sem apagar historico;
- integracao do `EngineeringQualityScanService` gravando evidencias no runtime generico;
- integracao do `AtlasEngineeringVisualSmokeCommand` como sensor interno `atlas_visual_smoke`, com manifest, route artifacts e findings para falha de rota/baseline/screenshot;
- integracao do `EngineeringCodeIntelligenceService` como analyzer interno `atlas_code_intelligence`, registrando index/audit, metricas de modulos/simbolos/doc links e findings de drift.

## Como Registrar Nova Ferramenta

1. Adicione a definition em `AtlasToolDefinitionCatalog`.
2. Declare tipo, categoria, capacidades, camadas, riscos, timeout e failure policy.
3. Garanta que `atlas tools doctor --workspace=<repo> --json` mostre `ready`, `missing` ou `skipped` com motivo claro.
4. Se a ferramenta executa comando novo, comece por `atlas tools run <slug> --command=<argv> --dry-run --json`.
5. Adicione normalizacao especifica apenas quando o output estruturado justificar; ate la, use o contrato generico.
6. Escreva teste com binary fake em PATH ou no workspace.

## Aprovacoes Auditaveis

Ferramentas `high`/`critical`, ferramentas nao gratuitas ou ferramentas que podem
usar rede nao devem ser liberadas por flag solta em automacao recorrente. O fluxo
operacional e:

```bash
atlas tools approve gitleaks --workspace=<repo> --reason="release scan" --ttl-hours=24 --json
atlas tools policies --workspace=<repo> --json
atlas tools run gitleaks --workspace=<repo> --command=gitleaks --command=detect --json
atlas tools revoke gitleaks --workspace=<repo> --json
```

A policy fica em `atlas_tool_policies.metadata` com `approved_at`,
`approved_until`, `approved_by`, `approval_reason`, `network_allowed` e
`workspace_hash`. A decisao emitida em `atlas_tool_runs.policy_decision_json`
inclui `approval_status`, preservando auditoria mesmo quando a aprovacao expira
ou e revogada.

## Consulta De Evidencias

O Evidence Store deve ser consultavel por automacoes, app e CLI sem varrer runs
globais. Use filtros estreitos quando estiver analisando um workspace ou run:

```bash
atlas tools evidence --workspace=<repo> --status=failed --json
atlas tools evidence semgrep --workspace=<repo> --surface=engineering_quality_scan --json
atlas tools evidence --workspace=<repo> --context-type=engineering_run --context-id=<run-id> --json
```

A API equivalente aceita `workspace`, `tool_slug`, `surface`, `status`,
`policy_decision`, `run_context_type`, `run_context_id`, `required` e `limit` em
`GET /tools/evidence`. O app Engineering usa o mesmo filtro de workspace do
doctor para evitar misturar evidencias de repositorios diferentes.

## Normalizacao De Output

O parser canonico vive em `AtlasToolResultNormalizer`. Fluxos internos como
Quality Scan e execucoes diretas por `atlas tools run` devem chamar o mesmo
normalizer para evitar divergencia de findings, severidade, fingerprints e
blocking failures.

## Regra Operacional

Ferramenta opcional ausente nao bloqueia conclusao. Ferramenta requerida ausente
ou bloqueada por policy vira evidencia auditavel e deve impedir `resolved` quando
o consumidor declarar esse requisito.
