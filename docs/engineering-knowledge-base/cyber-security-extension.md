---
id: atlas-ai-cyber-security-extension
type: engineering_knowledge
title: Atlas AI Cyber Security Extension
status: building
category: architecture
priority: 88
implementation_state: cyber_security_scaffold_not_runtime_promoted
summary: Spec scaffold da extensao Cyber Security do Atlas para automacao de bug bounty, pentest e auditoria ofensiva, alocada como flows de programming + skills no Vault, sem criar Domain Plane novo enquanto onboarding formal nao for aprovado.
tags:
  - atlas-ai
  - cyber-security
  - bug-bounty
  - pentest
  - red-blue-purple
  - extension
capabilities:
  - cyber_security_extension_spec
  - bb_runner_orchestration_proposal
  - red_team_skill_catalog
  - purple_validation_loop_proposal
  - refusal_matrix_extension
decisions:
  - Cyber Security e EXTENSAO de programming + cruzamento com security domain, nao Domain Plane novo. Confirmado por `php artisan atlas:ai:place-feature "bug bounty pentest automation cyber security domain"` que retornou layer=domain, domain=programming, flow=programming.dev, gate_status=blocked.
  - Security Domain canonico e defensive-only (revisao de risco, privacidade, compliance, incidente). Bug bounty / pentest ofensivo / red team NAO sao escopo de security domain.
  - Programming.security flow canonico cobre security review de codigo proprio com Engineering Harness. Pentest contra alvo externo autorizado precisa de flow novo dentro de programming OU dominio futuro `cyber` apos onboarding formal.
  - Skills cyber-* vivem em `AtlasVault/_skills/` no formato canonico de skill (frontmatter atlas_ai_skill, ring 2, status draft inicialmente).
  - Recipes de tools ofensivas (nuclei, ffuf, mitmproxy, frida, bloodhound, etc.) sao registradas no Super Tool Runtime canonico, nao em sistema paralelo.
  - Refusal matrix Cyber e EXTENSAO de policy do kernel; nao bypassa policy nem cria policy paralela.
  - Bug bounty fluxo end-to-end (recon -> exploit -> triage -> inbox) e orquestrado por skill `cyber-bb-runner` que monta um OperationEnvelope com sub-flows; output final vai ao Proposal Inbox / Human Review canonico.
  - MCP servers externos com capacidade ofensiva (e.g., HexStrike AI) sao integrados via skill wrapper canonica (e.g., `cyber-hexstrike-runner`) que aplica Receipt + scope_proof + refusal matrix + sandbox VM + evidence ledger antes de cada chamada; NUNCA conectados crus ao Atlas Decide via mcpServers config. Preserva governanca enquanto absorve capacidade autonoma validada (HexStrike resolveu CTF YesWeHack real). Ver cyber-security/recipes-catalog.md secao "External MCP Tooling".
  - Encadeamento de findings (chains de exploit) e responsabilidade da skill `cyber-exploit-chainer` — ela analisa findings + asset map e propoe escalation de severity (Low+Low+Medium = Critical via composicao). Cada chain critica EXIGE checkpoint humano antes de executar elo escalando severidade. Atlas raciocina sobre encadeamento; operador autoriza execucao. Quebra de checkpoint = cyber-ref-103 derivada.
  - Attack boxes efemeras (VPS provisionada via Terraform/Pulumi para engagement) sao orquestradas pela skill `cyber-infra-runner`. Refusal automatica se programa BB exige IP fixo allowlisted do tester (provisao com IP variavel quebra RoE — cyber-ref-030 derivada). Auto-destroy obrigatorio com orphan check pos-engagement.
  - Supply chain detection e DETECT-ONLY via `cyber-supply-chain-scanner`. NUNCA publica package em registry, NUNCA registra typosquat, NUNCA submete PR malicioso (cyber-ref-020..023 hard, sem excecao). Skill detecta reachability + alerta operador; operador decide se reporta ao programa.
  - BB Payload pode incluir como artifact opcional: (a) Sigma rule package pareada com a tecnica do finding (vendable como consultoria defensiva separada se operador preferir), (b) Negative PoC standalone (`check_<finding_id>.py`) entregue ao programa como gate de regression test perpetuo.
  - Patch verification automatica: quando programa BB anuncia "fix released", `cyber-bb-triage` re-testa variants conhecidas do pattern aplicavel. Atualiza disclosure.verified_fixed_at em sucesso; reabre como "incomplete fix" se variant ainda passa.
  - cyber.recon.continuous tem auto-triggers em (a) novo asset com priority_score >= 7 detectado, (b) CVE novo afetando tech do asset map (via recipe cve-monitor). Auto-trigger gera Inbox alert SEM auto-pentest — operador autoriza execucao.
maintenance:
  - Atualize quando place-feature mudar a decisao canonica (e.g., Cyber promovido a domain proprio).
  - Atualize quando flows propostos forem promovidos a flow registrado em AtlasDomainProfileRegistry.
  - Atualize quando skills cyber-* mudarem de status (draft -> experimental -> candidate -> default).
  - Rodar docs-health, architecture-validate, sync apos alteracoes.
related_paths:
  - atlas-server/docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
  - atlas-server/docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
  - atlas-server/docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - atlas-server/docs/engineering-knowledge-base/domains/programming.md
  - atlas-server/docs/engineering-knowledge-base/domains/security.md
  - atlas-server/docs/engineering-knowledge-base/super-tool-runtime-core.md
  - atlas-server/docs/engineering-knowledge-base/cyber-security/README.md
  - atlas-server/docs/engineering-knowledge-base/cyber-security/playbooks-techniques.md
  - atlas-server/docs/engineering-knowledge-base/cyber-security/remediation-patterns.md
  - atlas-server/docs/engineering-knowledge-base/cyber-security/detection-engineering.md
  - atlas-server/docs/engineering-knowledge-base/cyber-security/refusal-matrix.md
  - atlas-server/docs/engineering-knowledge-base/cyber-security/compliance-mapping.md
  - atlas-server/docs/engineering-knowledge-base/cyber-security/flow-profiles-proposal.md
  - atlas-server/docs/engineering-knowledge-base/cyber-security/recipes-catalog.md
  - AtlasVault/_skills/cyber-bb-runner/SKILL.md
  - AtlasVault/_skills/cyber-recon/SKILL.md
  - AtlasVault/_skills/cyber-pentest-webapp/SKILL.md
  - AtlasVault/_skills/cyber-bb-triage/SKILL.md
  - AtlasVault/_skills/cyber-purple-runner/SKILL.md
  - AtlasVault/_skills/cyber-hexstrike-runner/SKILL.md
  - AtlasVault/_skills/cyber-exploit-chainer/SKILL.md
  - AtlasVault/_skills/cyber-infra-runner/SKILL.md
  - AtlasVault/_skills/cyber-supply-chain-scanner/SKILL.md
owner: atlas-ai
layer: extension
line_limit: 260
related:
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/programming-power-tools-catalog.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-cyber-security-extension

graph_title: Atlas AI Cyber Security Extension

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: building

graph_source: repo
human_name: Atlas AI Cyber Security Extension
canonical_name: Atlas AI Cyber Security Extension
technical_name: atlas-ai-cyber-security-extension
cartography_type: module
canonical_source: docs/engineering-knowledge-base/cyber-security-extension.md

repo_paths:
  - docs/engineering-knowledge-base/cyber-security-extension.md

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
  - architecture

evidence:
  - docs/engineering-knowledge-base/cyber-security-extension.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - system
  - module
  - architecture

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
# Atlas AI Cyber Security Extension

Cyber Security e a extensao Atlas AI para automacao de bug bounty, pentest autorizado e auditoria ofensiva. Ela nao e Domain Plane novo; e composta por flows de programming + skills no Vault + KB tecnica + recipes do Super Tool Runtime + refusal matrix de policy.

## Status

Status canonico: `scaffold`.

A extensao existe como conjunto de skills draft, KB tecnica consolidada e flow profiles propostos. Nao tem orchestrator dedicado nem entry em `AtlasDomainProfileRegistry`. Promocao a Domain proprio (`cyber`) exige `AtlasDomainOnboardingScorecard` completo — ver `Promotion Path`.

## Non-Confusion Rules

1. Cyber Security extension nao e `security` domain. Security domain e defensive-only (threat review, privacy review, compliance review, incident review) sem execucao de exploit, scan, secrets ou acao operacional autonoma.
2. Cyber Security extension nao e `programming.security` flow. Programming.security cobre security review de codigo proprio do Vitor com Engineering Harness; Cyber estende para pentest contra alvo externo autorizado (programa BB).
3. Cyber Security extension nao substitui Super Tool Runtime canonico. Recipes ofensivas adicionais sao propostas em `cyber-security/recipes-catalog.md` para promocao ao registry canonico.
4. Cyber Security extension nao cria Quality Gates paralelos. Reusa gates do kernel (security_scope, defensive_only quando aplicavel, scope_proof, refusal_matrix_check) e declara gates novos como flow gates dentro do flow profile.
5. Cyber Security extension nao inventa Inbox. Output final do flow BB vai para `Proposal Inbox / Human Review` canonico.

## Scope

Included:

1. Bug bounty automation (programa publico HackerOne / Bugcrowd / Intigriti / direct security@).
2. Pentest contra alvo externo autorizado (com programa BB ou contrato).
3. Self-audit ofensivo dos repositorios Atlas (atlas-server, atlas-app) via Purple validation.
4. Recon ativo dentro do escopo do programa.
5. Exploit confirmado com PoC minimo (sem exfil real, sem persistence sem clausula).
6. Triage e dedup contra reports anteriores na plataforma BB.
7. Geracao de payload pronto para submissao no programa BB destino.

Excluded:

1. DoS, DDoS, resource exhaustion sem clausula explicita.
2. Mass-targeting (target fora do escopo do programa BB).
3. Supply-chain attack ofensivo (typosquatting, dependency confusion contra alvo real).
4. Exfil de PII real (apenas test accounts ou redacted).
5. Persistence ou exfil alem do PoC minimo sem clausula.
6. Bypass de EDR/WAF contra defensores legitimos sem clausula red-team-c2.
7. Operacao contra alvo sem programa BB publico ou contrato assinado.
8. Submissao automatica ao programa BB sem aprovacao humana (Atlas observa, nao corrige).

## Proposed Flows

Flows propostos para registro em `AtlasDomainProfileRegistry` apos onboarding:

| Flow ID | Owner | Runtime preference | Maturity |
|---|---|---|---|
| `programming.security.audit` | programming | engineering_harness | propose extension of existing `programming.security` |
| `cyber.recon` | new (cyber) ou programming | super_tool_runtime | propose |
| `cyber.bb.full-flow` | new (cyber) | super_tool_runtime + engineering_harness | propose |
| `cyber.bb.triage` | new (cyber) | super_tool_runtime | propose |
| `cyber.purple.validate` | new (cyber) ou self_improvement | engineering_harness + super_tool_runtime | propose |
| `cyber.ir.investigate` | extends `security.incident_review` | super_tool_runtime | propose extension |

Detalhe completo em `cyber-security/flow-profiles-proposal.md`.

## Skills

Skills draft em `AtlasVault/_skills/`:

| Skill | Ring | Status | Trigger principal |
|---|---|---|---|
| `cyber-bb-runner` | 2 | draft | "fazer bug bounty no programa X" |
| `cyber-recon` | 2 | draft | recon, asset discovery, OSINT |
| `cyber-pentest-webapp` | 2 | draft | pentest webapp, OWASP, IDOR/SQLi/XSS |
| `cyber-bb-triage` | 2 | draft | triagem, dedup, severity, formato submissao BB |
| `cyber-purple-runner` | 2 | draft | Atlas testa Atlas — Red ataca Blue |

Promocao alem de `candidate` exige eval (regra canonica `skill_without_eval_cannot_be_promoted_beyond_candidate`). Evals propostos vivem em `AtlasVault/_skills/_evals/cyber-*.yml`.

## Knowledge Base

KB tecnica consolidada em `cyber-security/`:

- `playbooks-techniques.md` — webapp + api + mobile + cloud + network/AD + source review + crypto + supply-chain + AI/ML + IoT + threat modeling.
- `remediation-patterns.md` — patterns por CWE (IDOR, SQLi, XSS, SSRF, etc.) com snippets por stack + anti-fixes + verification.
- `detection-engineering.md` — Sigma rules, detection-as-code, pareamento Red↔Blue.
- `refusal-matrix.md` — regras canonicas de recusa Cyber, integradas com policy do kernel.
- `compliance-mapping.md` — LGPD/GDPR/HIPAA/PCI-DSS/DFARS/SOC2/ISO27001 quando programa BB toca compliance.
- `flow-profiles-proposal.md` — flows propostos detalhados.
- `recipes-catalog.md` — recipes propostas para Super Tool Runtime (nuclei, ffuf, mitmproxy, frida, bloodhound, kube-hunter, etc.).

## Surfaces

Cyber Security extension expoe via:

- `atlas dev "fazer bb no programa X"` -> rota via Atlas Decide -> skill cyber-bb-runner -> orquestra flow.
- App mobile: comando "bug bounty <programa>" -> via API -> skill cyber-bb-runner.
- Voz: "Atlas, faz BB do programa X" -> mesma rota.
- MCP: tools cyber-* expostas para Codex/Claude usarem como sub-agents.

Surface coleta input, exibe output, preserva metadata. Decisao operacional fica no skill + flow + Decide.

## Output Contract

Output canonico do flow BB completo:

1. `OperationEnvelope` com domain=programming (ou cyber quando promovido), flow=cyber.bb.full-flow.
2. `DecisionReceipt v2` para cada sub-acao (recon, exploit, triage).
3. `Evidence Ledger` events: `tool_run_started`, `finding_drafted`, `finding_confirmed`, `triage_completed`, `bb_payload_prepared`.
4. `Proposal Inbox / Human Review` recebe payload final: titulo, severity justificada, CVSS vector, reprodution steps, evidencia (HAR, screenshots redacted), recommendation com pattern_ref, pronto para Vitor revisar e clicar "submeter" (ou editar primeiro).
5. Vitor confirma -> Atlas submete via API da plataforma BB OU Vitor copia/cola manual.
6. Disclosure timeline trackado pelo Atlas; notificacao quando publishable.

## Refusal Matrix Integration

Refusal matrix Cyber declarada em `cyber-security/refusal-matrix.md` e injetada como Policy do kernel via mecanismo canonico (nao paralelo). Refusal-with-Receipt: toda recusa emite Receipt + Evidence; nao silencia.

## Promotion Path

Cyber vira Domain proprio (`cyber`) quando:

1. `php artisan atlas:ai:place-feature "cyber security domain"` retorna `gate_status=ok` (sem duplicates de alta sobreposicao).
2. Skills cyber-* atingem `candidate` com evals passando.
3. Existe orchestrator dedicado implementando `AtlasDomainOrchestrator` (ex.: `app/Services/Ai/Cyber/AtlasCyberOrchestrator.php`).
4. Migration de profile registra domain `cyber` em `AtlasDomainProfileRegistry`.
5. `AtlasDomainOnboardingScorecard` retorna `ready 9/9` (charter, profile, context, orchestrator, runtime, gates, learning, surface, maturity_gate).
6. Spec canonica criada em `domains/cyber.md` (formato `priority: 90+`, `line_limit: 260`).
7. Atualiza `domains/README.md` movendo de scaffold/proposed para implemented/ready.
8. Provider projections regeneradas.
9. `php artisan atlas:ai:architecture-validate --json` passa.

Antes desses passos, Cyber permanece como `scaffold` extension dentro de programming.

## Anti-Patterns

1. Tratar Cyber como Domain canonico (nao e — apenas scaffold).
2. Criar Super Tool Runtime paralelo (canonico ja existe; recipes novas vao no registry canonico).
3. Inventar Inbox proprio (Proposal Inbox / Human Review e canonico).
4. Inventar Engagement como unidade (OperationEnvelope e canonico).
5. Bypass de policy via "personas" (skills no Vault sao a superficie canonica).
6. Atlas submete BB report autonomamente (Atlas observa, nao corrige — Vitor revisa e confirma).
7. Skill cyber-* promovida sem eval (`skill_without_eval_cannot_be_promoted_beyond_candidate`).
8. Doc longo paralelo (anti-pattern #2 do governance).

## Validation

Apos qualquer mudanca nesta extensao:

```bash
php artisan atlas:ai:architecture-validate --json
php artisan atlas:ai:doctor --json
atlas engineering knowledge sync --prune
atlas engineering knowledge index-code --prune
atlas engineering knowledge docs-health
```

## Cross-References

- Atlas AI Knowledge Governance: `atlas-ai-knowledge-governance-system.md` (#1 read-first).
- Core vs Domain: `atlas-ai-core-vs-domain.md` (regra de quando criar domain novo).
- Programming Domain: `domains/programming.md` (owner atual dos flows propostos).
- Security Domain: `domains/security.md` (defensive-only, nao confunde com Cyber).
- Super Tool Runtime: `super-tool-runtime-core.md` (recipes registry).
- Skill System: `AtlasVault/_skills/atlas-skills.manifest.json` (skills cyber-* registradas).

## Resumo

Spec scaffold da extensao Cyber Security do Atlas para automacao de bug bounty, pentest e auditoria ofensiva, alocada como flows de programming + skills no Vault, sem criar Domain Plane novo enquanto onboarding formal nao for aprovado.

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
