---
id: atlas-ai-cyber-compliance-mapping
type: engineering_knowledge
title: Atlas AI Cyber Compliance Mapping
status: scaffold
category: knowledge-base
priority: 82
summary: Mapping de regulamentacoes (LGPD, GDPR, HIPAA, PCI-DSS, DFARS/NIST 800-171, SOC2, ISO 27001) aplicaveis quando programa BB ou alvo Cyber tocar dados regulados; constraints de runtime injetadas via Policy do kernel.
tags:
  - atlas-ai
  - cyber-security
  - compliance
  - lgpd
  - gdpr
  - hipaa
  - pci-dss
  - dfars
  - soc2
  - iso27001
capabilities:
  - cyber_compliance_mapping_kb
decisions:
  - Compliance vertical aplica quando programa BB OU contrato de pentest declara escopo regulado.
  - Constraints injetados como Policy do kernel via mecanismo canonico (Atlas Policy Engine), nao via codigo Cyber paralelo.
  - Skill cyber-bb-runner consulta este doc na fase scoping para determinar compliance_profile do envelope.
maintenance:
  - Atualize quando regulamentacao publicar versao nova (e.g., PCI-DSS v4.1, GDPR re-emendas).
  - Atualize quando programa BB de cliente regulado adicionar requirement.
related_paths:
  - atlas-server/docs/engineering-knowledge-base/cyber-security-extension.md
  - atlas-server/docs/engineering-knowledge-base/cyber-security/refusal-matrix.md
  - atlas-server/docs/engineering-knowledge-base/atlas-ai-policy-engine.md
owner: atlas-ai
layer: extension
line_limit: 240
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-cyber-compliance-mapping

graph_title: Atlas AI Cyber Compliance Mapping

graph_world: atlas

graph_layer: module

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: building

graph_source: repo

repo_paths:
  - docs/engineering-knowledge-base/cyber-security/compliance-mapping.md

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
  - docs/engineering-knowledge-base/cyber-security/compliance-mapping.md

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
# Atlas AI Cyber Compliance Mapping

Quando aplicar e quais constraints injetar como Policy do kernel.

## Decisao "aplica ou nao"

Skill cyber-bb-runner na fase scoping verifica:

| Sinal | Compliance aplicavel |
|---|---|
| Programa BB lista "PCI-DSS scope" ou alvo e payment processor | PCI-DSS |
| Programa lista "PHI" ou alvo e healthcare | HIPAA + state-level |
| Alvo opera em UE OU coleta dados de UE residents | GDPR |
| Alvo opera no Brasil OU coleta dados de cidadaos BR | LGPD |
| Alvo e DoD contractor ou base militar EUA | DFARS + NIST 800-171 |
| Alvo declara SOC 2 Type II em postura | SOC 2 |
| Alvo declara ISO/IEC 27001 em postura | ISO 27001 |

Multiplos sinais = multiplos compliance_profiles cumulativos.

## LGPD (Brasil)

Aplicavel sempre que coleta de PII de cidadao brasileiro for possivel.

Constraints:

- Art. 7: bases legais para tratamento.
- Art. 9: categorias especiais (sensitive PII) — proibido capturar sem consentimento expresso + proposito especifico.
- Art. 48: notificacao ANPD em janela legal se Atlas comprometido.
- Coleta minima: exfiltration_proof_max_bytes baixo (default 512).
- `must_redact`: CPF, nome+sobrenome, endereco, telefone, email pessoal.

## GDPR (UE)

Aplicavel se alvo opera em UE ou coleta dados de UE residents.

Constraints:

- Art. 6: lawful basis — Atlas opera sob "legitimate interest" do programa BB autorizado.
- Art. 9: special categories (raca, etnia, politica, religiao, saude, sexo, biometric, genetic) — proibido sem clausula explicita.
- Art. 32: security of processing — TLS in-transit, encryption at rest.
- Art. 33-34: breach notification 72h ao DPA + sujeitos.
- Data residency: evidence_storage_location DEVE ser UE-region quando dado e UE-resident.

## HIPAA (US health)

Aplicavel para healthcare providers / health plans / business associates.

Constraints:

- BAA (Business Associate Agreement) obrigatorio se Atlas processar PHI.
- ePHI handling: encryption at rest + in-transit, access control, audit log.
- Breach notification 60d ao HHS + indivividuos afetados.
- Refusal absoluta em comprometer disponibilidade de equipamento medico (cyber-ref-050).
- `must_redact`: PHI identifiers (HIPAA 18 identifiers).

## PCI-DSS (cartao)

Aplicavel para merchants, payment processors, service providers que tocam CHD.

Constraints v4.0:

- CHD nunca armazenado pos-auth.
- SAD (CVV, track) nunca capturado.
- Network segmentation respeitada — Atlas nao expande scope alem do CDE declarado.
- Crypto: AES-128+, TLS 1.2+, etc.
- `must_redact`: card numbers (Luhn-validated patterns), CVV.
- Refusal absoluta em comprometer settlement core (cyber-ref-052).

Requirements relevantes:

| Req | Atlas relevancia |
|---|---|
| 1 | Network segmentation testing |
| 2 | No vendor defaults — hardening review |
| 3 | Stored CHD protection — crypto review |
| 4 | Encrypted transmission — TLS audit |
| 6 | Develop secure systems — SAST + source review |
| 7 | Restrict access by need — authz testing |
| 8 | Identify users — auth testing |
| 10 | Log and monitor — detection engineering |
| 11 | Test security regularly — this engagement |

## DFARS / NIST 800-171 (US DoD)

Aplicavel para contractors DoD ou base militar.

Constraints:

- Operadores: cidadania US ou autorizada por programa.
- Storage: US-only data residency.
- CUI handling per NIST 800-171 (110 controles).
- DFARS 252.204-7012: cyber incident reporting em 72h ao DoD via DIBNet.
- No exfil real (apenas demonstracao minima).
- No availability impact (cyber-ref-050, cyber-ref-051 hard).
- FedRAMP-compliant tooling preferred.

Subset NIST 800-171 essentials:

| Family | Controle exemplo | Atlas |
|---|---|---|
| 3.1 Access Control | 3.1.1 Limit access to authorized | RoE client_authorizer validation |
| 3.3 Audit & Accountability | 3.3.1 Create audit records | Atlas Ledger nativo |
| 3.4 Configuration Mgmt | 3.4.1 Baseline configs | Hardening baselines |
| 3.6 Incident Response | 3.6.1 IR plan | security.incident_review extension |
| 3.13 Sys Comm Protection | 3.13.11 FIPS-validated crypto | Crypto whitelist Atlas |

## SOC 2

Aplicavel para SaaS B2B com SOC 2 Type II.

Trust Service Criteria:

| TSC | Atlas relevancia |
|---|---|
| Security (CC) | core |
| Availability (A) | engagement evita impacto disponibilidade |
| Processing Integrity (PI) | testar logica de business |
| Confidentiality (C) | data handling |
| Privacy (P) | LGPD/GDPR overlap |

Common Criteria essenciais:

| CC | Descricao | Atlas |
|---|---|---|
| CC6.1 | Logical access | authn/authz testing |
| CC6.6 | Boundary protection | network segmentation |
| CC6.7 | Restricted info | data handling |
| CC7.1 | System monitoring | detection engineering |
| CC7.2 | Anomaly detection | detection engineering |
| CC7.3 | Incident response | IR runbooks |
| CC7.5 | Identify, classify | finding triage |

## ISO/IEC 27001:2022

Aplicavel para clientes ISO 27001 certified ou em processo.

Annex A controles relevantes (subset):

| Theme | Controle exemplo | Atlas |
|---|---|---|
| Organizational | A.5.7 Threat intelligence | TI feed source |
| People | A.6.1 Screening | operator vetting |
| Technological | A.8.2 Privileged access | authz testing |
| Tech | A.8.6 Capacity mgmt | sem DoS |
| Tech | A.8.16 Monitoring | detection engineering |
| Tech | A.8.21 Use of crypto | crypto review |
| Tech | A.8.25 Secure dev lifecycle | source review |
| Tech | A.8.26 App security req | webapp playbook |
| Tech | A.8.27 Secure system arch | threat modeling |
| Tech | A.8.28 Secure coding | source review |
| Tech | A.8.29 Security testing | this engagement |
| Tech | A.8.30 Outsourced dev | supply chain |

## Cross-jurisdicao

Quando programa BB toca multiplas jurisdicoes:

- Aplicar constraints cumulativos (mais restritivo vence).
- Data residency: residencia mais restritiva (e.g., dados UE+BR -> storage UE).
- Notification SLA: prazo mais curto (e.g., GDPR 72h vs HIPAA 60d -> 72h).
- Refusal Matrix: sempre cumulativa.

## Anti-padroes

- Compliance ignorado "porque programa BB nao mencionou explicitamente" (verifica jurisdicao real).
- Capturar PII brasileiro com storage em US-only sem clausula adequada.
- "PCI-DSS scope" ignorado por achar que nao toca CHD (verifica fluxo).
- DFARS Atlas-side ignorado por considerar "trabalho da Vitor entity, nao DoD" (verifica chain).

## Resumo

Mapping de regulamentacoes (LGPD, GDPR, HIPAA, PCI-DSS, DFARS/NIST 800-171, SOC2, ISO 27001) aplicaveis quando programa BB ou alvo Cyber tocar dados regulados; constraints de runtime injetadas via Policy do kernel.

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
