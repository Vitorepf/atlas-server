---
id: atlas-ai-cyber-recipes-offensive-families
type: engineering_knowledge
title: Atlas AI Cyber Offensive Recipe Families
status: scaffold
category: knowledge-base
priority: 80
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
