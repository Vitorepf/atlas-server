---
id: atlas-ai-cyber-recipes-offensive-families
type: engineering_knowledge
title: Atlas AI Cyber Offensive Recipe Families
status: building
category: knowledge-base
priority: 80
implementation_state: cyber_security_scaffold_not_runtime_promoted
summary: Focused list of proposed offensive cyber recipe families and tool candidates for future Super Tool Runtime promotion.
tags:
  - atlas-ai
  - cyber-security
  - recipes
capabilities:
  - cyber_recipes_proposal
decisions:
  - These are candidate recipes; promotion requires the runbook and policy gates.
  - Exact historical argv examples live in archived source material until promoted.
maintenance:
  - Add candidates here only as family-level proposals; detailed registry entries belong in APs or migrations.
related_paths:
  - docs/engineering-knowledge-base/cyber-security/recipes-catalog.md
  - docs/engineering-knowledge-base/cyber-security/recipes-promotion-runbook.md
  - docs/engineering-knowledge-base/archive/source-material/cyber-security/recipes-catalog-full-2026-05-08.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-cyber-recipes-offensive-families

graph_title: Atlas AI Cyber Offensive Recipe Families

graph_world: atlas

graph_layer: module

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: building

graph_source: repo
human_name: Atlas AI Cyber Offensive Recipe Families
canonical_name: Atlas AI Cyber Offensive Recipe Families
technical_name: atlas-ai-cyber-recipes-offensive-families
cartography_type: module
canonical_source: docs/engineering-knowledge-base/cyber-security/recipes-offensive-families.md

owner: cyber-security

repo_paths:
  - docs/engineering-knowledge-base/cyber-security/recipes-offensive-families.md

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
  - docs/engineering-knowledge-base/cyber-security/recipes-offensive-families.md

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
# Atlas AI Cyber Offensive Recipe Families

These tools are candidates for future registry promotion. They are not active
runtime authority until implemented through the promotion runbook.

## Recon

| Tool | Candidate use | Risk |
|---|---|---|
| `nuclei` | Template-based vulnerability scan. | T1, scoped, dry-run default. |
| `ffuf` | Directory/API fuzzing. | T1, scoped. |
| `amass` | Passive subdomain enumeration. | T1. |
| `subfinder` | Passive subdomain enumeration. | T0. |
| `httpx` | HTTP probing and tech detect. | T0. |
| `bbot` | Recursive recon graph. | T1, scope-only. |
| `cve-monitor` | Realtime stack CVE watch. | T0, inbox proposal only. |
| `axiom` | Distributed recon. | T2, cost gate and extra approval. |

## Webapp And API

| Tool | Candidate use | Risk |
|---|---|---|
| `sqlmap` | SQL injection validation in approved scope. | Exploit-capable; approval required. |
| `zap` | Web/API scan. | T1/T2 by mode. |
| `wapiti` | Web vulnerability scan. | T1. |
| `mitmproxy` | Traffic inspection in owned environments. | Privacy-sensitive. |
| `smuggler-custom` | HTTP request smuggling checks. | Active exploit; approval required. |
| `js-dynamic-analyzer` | Client-side JS dynamic analysis. | T1. |
| `caido` | Manual/proxy-assisted review. | Human-in-the-loop. |

## Mobile

| Tool | Candidate use |
|---|---|
| `mobsf` | Mobile static/dynamic security review. |
| `frida` | Runtime instrumentation in owned apps/devices. |
| `objection` | Mobile runtime exploration in approved scope. |

## Cloud And Kubernetes

| Tool | Candidate use |
|---|---|
| `prowler` | Cloud security posture. |
| `scout-suite` | Cloud audit. |
| `kube-bench` | Kubernetes CIS checks. |
| `kube-hunter` | Kubernetes attack-surface checks. |
| `cloudsplaining` | IAM policy risk analysis. |
| `pacu` | AWS exploitation simulation in approved lab/scope. |

## Red Team C2

These require a dedicated `red-team-c2` clause, VM sandbox, explicit approval and
strict kill-switch:

- `sliver`
- `havoc`
- `invisibility-cloak`

## Network And AD

| Tool | Candidate use |
|---|---|
| `nmap` | Network discovery in approved ranges. |
| `bloodhound` | AD relationship graph. |
| `impacket` | AD protocol tooling. |
| `crackmapexec` | AD assessment in lab/approved scope. |
| `certipy-ad` | AD CS review. |
| `gpp-decrypt` | Legacy GPP credential finding. |

## AI/ML And Supply Chain

| Tool | Candidate use |
|---|---|
| `promptfoo` | LLM behavior tests. |
| `garak` | LLM red-team testing. |
| `lakera` | Defensive LLM prompt/security checks. |
| `cosign` | Image attestation verification. |
| `grype` | Image/package vulnerability scan. |

## Promotion Note

Exact historical YAML examples are preserved in the archived full catalog. When
promoting one candidate, copy only the needed recipe into an AP/migration and
revalidate policy, scope, sandbox and evidence.

## Resumo

Focused list of proposed offensive cyber recipe families and tool candidates for future Super Tool Runtime promotion.

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
