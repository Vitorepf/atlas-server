---
id: atlas-ai-cyber-detection-engineering
type: engineering_knowledge
title: Atlas AI Cyber Detection Engineering
status: scaffold
category: knowledge-base
priority: 83
summary: Detection-as-code para Cyber extension; pareamento Red-Blue, Sigma rules, false positive budget, lifecycle draft->staged->live, integracao com Purple validation.
tags:
  - atlas-ai
  - cyber-security
  - detection-engineering
  - sigma
  - blue-team
capabilities:
  - cyber_detection_engineering_kb
decisions:
  - Detection rules em formato Sigma para ser vendor-neutral; conversao para SIEM cliente via sigmac/pySigma.
  - Pareamento Red-Blue obrigatorio: tecnica em playbooks-techniques.md tem Detection pareada antes de skill cyber-* virar candidate.
  - False Positive Budget explicito por severity.
  - Lifecycle draft -> staged (7d shadow) -> live (apos Purple validation).
maintenance:
  - Atualize quando regras Sigma forem promovidas.
  - Atualize pareamento quando tecnica nova for adicionada em playbooks-techniques.md.
related_paths:
  - atlas-server/docs/engineering-knowledge-base/cyber-security/playbooks-techniques.md
  - atlas-server/docs/engineering-knowledge-base/cyber-security/refusal-matrix.md
  - AtlasVault/_skills/cyber-purple-runner/SKILL.md
owner: atlas-ai
layer: extension
line_limit: 240
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-cyber-detection-engineering

graph_title: Atlas AI Cyber Detection Engineering

graph_world: atlas

graph_layer: module

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: building

graph_source: repo

repo_paths:
  - docs/engineering-knowledge-base/cyber-security/detection-engineering.md

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
  - docs/engineering-knowledge-base/cyber-security/detection-engineering.md

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
# Atlas AI Cyber Detection Engineering

Detection-as-code para Cyber extension.

## Princípios

| # | Principio |
|---|---|
| DE1 | Detection e hipotese testavel. Toda regra tem TP cases e TN cases. |
| DE2 | False Positive Budget explicito; medido em prod; tune ou deprecate. |
| DE3 | Pareamento Red-Blue obrigatorio. |
| DE4 | Detection-as-code. Versionada, testada em CI, deployada via pipeline. |
| DE5 | Layered detection. Multiplas regras por tecnica em data sources diferentes. |
| DE6 | Severity = priority. Nao inflar. |
| DE7 | Lifecycle visivel: draft -> staged -> live -> deprecated. |
| DE8 | **Verdict-First / Anomaly over Confirmation**. Detection nao e so signature match — privilegia desvio comportamental sobre assinatura conhecida. Endpoint que comeca a enviar trafego criptografado para dominio nao categorizado dispara alert mesmo sem CVE conhecido. Reduz blind-spot a 0-day; aumenta requisito de baseline estavel. Trade-off explicito: anomaly tem FP higher; pareado com behavioral correlation (multipla sinal) reduz. Alvo: 30%+ das regras `live` em categoria `behavioral` ate fim do ano de promotion. |

## Tipos de Detection

| Kind | Quando | FP rate tipico |
|---|---|---|
| Signature | Padrao estavel conhecido (UA de tool) | baixo |
| Behavioral | Sequencia de eventos | medio |
| Anomaly | Desvio de baseline | alto se baseline instavel |
| IOC matching | Hash, IP, domain (TI feeds) | baixo |
| Heuristic | Regras compostas | medio |

Mix recomendado por tecnica (DE5).

## Data sources canonicos

| Source | Coverage |
|---|---|
| HTTP access logs | webapp, api |
| Auth logs (auth0, IdP, AD) | auth bypass, brute force, lateral |
| Audit logs (cloud, K8s) | misconfig, IAM abuse |
| EDR telemetry | endpoint compromise, lateral, persistence |
| DNS logs | DGA, tunneling, exfil |
| Network flow | lateral, exfil |
| Process exec logs | command injection, persistence |
| FIM | web shell, persistence |
| Email logs | phishing |
| Atlas Evidence Ledger | Cyber events (self-audit) |

## Anatomia de Sigma rule canonica

```yaml
title: <titulo humano>
id: cyber-det-<slug>
description: |
  O que detecta + tecnica Red pareada (referencia secao em playbooks-techniques.md).
status: experimental | test | stable
date: YYYY-MM-DD
author: atlas-ai
tags:
  - attack.<tactic>
  - attack.<technique>
  - cwe.<id>
  - atlas.cyber.<sub-categoria>
references:
  - cyber-security/playbooks-techniques.md#<secao>

logsource:
  product: <produto>
  service: <servico>
  category: <categoria>

detection:
  selection:
    <field>: <value>
  filter:
    <field>: <value>
  condition: selection and not filter

fields:
  - <campos para investigacao>

falsepositives:
  - <cenario 1>
  - <cenario 2>

level: low | medium | high | critical
```

## Pareamento Red-Blue (subset essencial)

| Sub-categoria Red (em playbooks-techniques.md) | Detection sugerida | Data source |
|---|---|---|
| Webapp - IDOR | cyber-det-idor | http_logs |
| Webapp - SQLi | cyber-det-sqli | http_logs + db_query_logs |
| Webapp - XSS | cyber-det-xss-payload | http_logs (request body + response) |
| Webapp - SSRF | cyber-det-ssrf | egress_logs (DNS + HTTP) |
| Webapp - RCE / command inj | cyber-det-rce | process_exec_logs |
| Webapp - Path traversal | cyber-det-path-traversal | http_logs + fs_access_logs |
| Webapp - Open redirect | cyber-det-open-redirect | http_logs |
| Webapp - Mass assignment | cyber-det-mass-assignment | http_logs (body diff vs whitelist) |
| Auth - User enum timing | cyber-det-user-enum-timing | auth_logs + perf metrics |
| Auth - Brute force | cyber-det-brute-force | auth_logs |
| Auth - JWT alg none | cyber-det-jwt-alg-none | auth_logs / app_logs |
| Auth - OAuth redirect abuse | cyber-det-oauth-redirect-abuse | auth_logs |
| Authz - Vertical privesc | cyber-det-vertical-privesc | auth_logs + audit_logs |
| Session - Fixation | cyber-det-session-fixation | session_store_audit |
| Session - CSRF token missing | cyber-det-csrf-token-missing | http_logs |
| API - Rate limit exhaustion | cyber-det-rate-limit-exhaustion | http_logs (rps anomaly) |
| API - Over-exposure | cyber-det-over-exposure | response body inspection |
| API - HTTP method tamper | cyber-det-http-method-tamper | http_logs |
| API - GraphQL deep query | cyber-det-graphql-deep-query | gql_logs |
| API - GraphQL aliasing | cyber-det-graphql-aliasing | gql_logs |
| API - gRPC reflection | cyber-det-grpc-reflection-hit | grpc_logs |
| Mobile - Cert pinning bypass | cyber-det-cert-pinning-fail | RASP / mobile telemetry |
| Mobile - Frida injection | cyber-det-frida-hook-detected | RASP |
| Mobile - API anomaly | cyber-det-mobile-api-anomaly | http_logs |
| Cloud - IAM open policy | cyber-det-iam-open-policy | CloudTrail / Audit Logs |
| Cloud - S3 public | cyber-det-s3-bucket-public | CloudTrail |
| Cloud - SG open port | cyber-det-sg-open-port | CloudTrail |
| Cloud - K8s privileged pod | cyber-det-k8s-privileged | k8s admission |
| Cloud - GuardDuty/CloudTrail off | cyber-det-cloud-monitoring-off | CloudTrail meta |
| AD - Kerberoast | cyber-det-kerberoast | DC events 4769 |
| AD - ASREPRoast | cyber-det-asreproast | DC events |
| AD - NTLM relay | cyber-det-ntlm-relay | network capture / Windows logs |
| AD - DCSync | cyber-det-dcsync | DC events 4662 |
| AD - BloodHound collection | cyber-det-bloodhound-coll | LDAP volume anomaly |
| AD - PtH | cyber-det-pth | NTLM auth logs |
| AD - LSASS read (Mimikatz) | cyber-det-lsass-read | EDR / Sysmon 10 |
| Red team - Phish attachment | cyber-det-phish-attachment | email logs |
| Red team - PowerShell anomaly | cyber-det-powershell-anomaly | sysmon 1 / 4104 |
| Red team - Process injection | cyber-det-proc-injection | EDR |
| Red team - DNS tunnel | cyber-det-dns-tunnel | DNS logs |
| Red team - Web shell | cyber-det-web-shell | FIM + http_logs |
| Supply chain - Untrusted registry | cyber-det-untrusted-registry | container runtime logs |
| Supply chain - Cosign mismatch | cyber-det-sig-mismatch | admission |
| LLM - Prompt injection (direct) | cyber-det-prompt-injection | prompt logs |
| LLM - Prompt injection (indirect) | cyber-det-pi-indirect | output logs + fingerprint |
| LLM - Tool call flood | cyber-det-tool-call-flood | agent logs |
| LLM - System prompt extraction | cyber-det-sys-prompt-leak | prompt + output diff |

## False Positive Budget canonico

| Severity | FP rate budget |
|---|---|
| critical | < 0.001 (1 em 1000) |
| high | < 0.005 |
| medium | < 0.02 |
| low | < 0.05 |
| informational | < 0.1 |

Medido em janelas de 7d. Excesso -> tune ou deprecate.

## Lifecycle de Detection

```
draft     -> escrita; TP/TN cases definidos; nao em SIEM
staged    -> deploy em SIEM em modo "shadow alert" (vai pro Ledger, nao pro responder)
            mede FP rate por 7d
live      -> alertas ativos; FP rate dentro do budget
deprecated -> substituida ou tecnica nao relevante; nao dispara
```

Transicao `staged -> live` exige Purple validation passada (skill cyber-purple-runner).

## Integracao com Purple validation

Skill `cyber-purple-runner` executa playbook Red contra ambiente Purple, observa Detection ativa, mede:

- TP rate (Detection capturou ataque?).
- FN rate (Detection perdeu? gap).
- FP rate (alertou em acao benigna?).
- MTTD por tecnica.

Output em `cyber.purple.validate` flow vai para Curator proposal.

## Pipeline canonico

```
1. AUTHOR        engineer escreve YAML Sigma + tests TP/TN
2. PR            review humano (security + ops)
3. CI            lint Sigma + run TP/TN cases + check FP budget mock
4. STAGING       deploy em modo silencioso por 7d
5. PURPLE        Purple validation against tecnica Red pareada
6. LIVE          deploy ativo
7. MEASURE       FP rate continuo; Curator alerta se fora do budget
8. DEPRECATE     se tecnica nao relevante OU FP rate inviavel
```

## Anti-padroes

- Regra sem TN cases — FP rate explode.
- Detection sem pareamento Red — especulativa.
- Manter regra `live` com FP rate acima do budget — quebra DE2.
- Severity inflada para garantir alert — ruido desensitiza.
- Edit direto no SIEM cliente — drift vs git.
- Skip staging "porque tem urgencia" — FP em prod = ruido.

## Resumo

Detection-as-code para Cyber extension; pareamento Red-Blue, Sigma rules, false positive budget, lifecycle draft->staged->live, integracao com Purple validation.

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
