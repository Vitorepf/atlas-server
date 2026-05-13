---
id: atlas-ai-cyber-recipes-existing-tools
type: engineering_knowledge
title: Atlas AI Cyber Existing Tools
status: scaffold
category: knowledge-base
priority: 80
summary: Defensive and quality tools already available in the canonical Atlas Super Tool Runtime for cyber-security flows.
tags:
  - atlas-ai
  - cyber-security
  - tools
capabilities:
  - cyber_recipes_proposal
decisions:
  - Cyber skills must reuse existing registered tools before proposing new recipes.
maintenance:
  - Update when the canonical registry adds or retires security tools.
related_paths:
  - docs/engineering-knowledge-base/cyber-security/recipes-catalog.md
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
  - docs/engineering-knowledge-base/programming-power-tools-catalog.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-cyber-recipes-existing-tools

graph_title: Atlas AI Cyber Existing Tools

graph_world: atlas

graph_layer: module

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: building

graph_source: repo

owner: cyber-security

repo_paths:
  - docs/engineering-knowledge-base/cyber-security/recipes-existing-tools.md

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
  - docs/engineering-knowledge-base/cyber-security/recipes-existing-tools.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - module
  - module
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
# Atlas AI Cyber Existing Tools

Cyber flows can use these canonical registry tools immediately, subject to policy
and recipe availability.

| Tool | Use |
|---|---|
| `gitleaks` | Secret scanning. |
| `semgrep` | SAST and pattern-based source review. |
| `osv_scanner` | Dependency CVE scan. |
| `trivy` | Image, filesystem, repository and Kubernetes security scan. |
| `syft` | SBOM generation. |
| `checkov` | IaC scan. |
| `phpstan` | PHP static analysis in remediation flows. |
| `typescript` | TypeScript correctness in remediation flows. |
| `eslint` | JavaScript/TypeScript lint in remediation flows. |
| `biome` | JS/TS formatting and lint in remediation flows. |
| `hadolint` | Dockerfile lint and remediation evidence. |

## Rule

Do not create a new cyber recipe when an existing registry tool can produce the
same evidence. Add a new recipe only when the tool, mode or evidence shape is
missing.

## Verification

```bash
atlas tools list
atlas tools doctor
```

## Resumo

Defensive and quality tools already available in the canonical Atlas Super Tool Runtime for cyber-security flows.

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
