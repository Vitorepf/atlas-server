---
id: atlas-ai-cyber-playbooks-techniques
type: engineering_knowledge
title: Atlas AI Cyber Playbooks and Techniques
status: building
category: knowledge-base
priority: 85
implementation_state: cyber_security_scaffold_not_runtime_promoted
summary: Tecnicas Red consolidadas por categoria (webapp, api, mobile, cloud, network/AD, source review, crypto, supply chain, AI/ML, IoT, threat modeling, red team coord); referenciadas por skills cyber-* via cross-link, nunca copiadas inline em skill.
tags:
  - atlas-ai
  - cyber-security
  - playbooks
  - red-team
  - techniques
capabilities:
  - cyber_playbooks_kb
decisions:
  - Tecnicas listadas em formato compacto (categoria -> sub-tecnica -> CWE/OWASP/ATT&CK ref); nao expandir cada tecnica em sub-doc.
  - Skills cyber-* referenciam secao desta doc, nao copiam.
  - Profundidade de execucao = decisao da skill em runtime, nao da KB.
maintenance:
  - Atualize quando OWASP/MITRE ATT&CK lancarem versao nova.
  - Mantenha line_limit < 280.
related_paths:
  - atlas-server/docs/engineering-knowledge-base/cyber-security/refusal-matrix.md
  - atlas-server/docs/engineering-knowledge-base/cyber-security/remediation-patterns.md
  - atlas-server/docs/engineering-knowledge-base/cyber-security/recipes-catalog.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
owner: atlas-ai
layer: extension
line_limit: 280
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-cyber-playbooks-techniques

graph_title: Atlas AI Cyber Playbooks and Techniques

graph_world: atlas

graph_layer: module

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: building

graph_source: repo
human_name: Atlas AI Cyber Playbooks and Techniques
canonical_name: Atlas AI Cyber Playbooks and Techniques
technical_name: atlas-ai-cyber-playbooks-techniques
cartography_type: module
canonical_source: docs/engineering-knowledge-base/cyber-security/playbooks-techniques.md

repo_paths:
  - docs/engineering-knowledge-base/cyber-security/playbooks-techniques.md

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
  - docs/engineering-knowledge-base/cyber-security/playbooks-techniques.md

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
# Atlas AI Cyber Playbooks and Techniques

Tecnicas Red consolidadas. Skills cyber-* referenciam secao especifica.

## Cobertura de frameworks

| Framework | Versao | Aplicavel a |
|---|---|---|
| OWASP Top 10 Web | 2021 | webapp |
| OWASP API Top 10 | 2023 | api |
| OWASP MASTG/MASVS | v2 | mobile |
| OWASP LLM Top 10 | 2024 | ai-ml |
| OWASP IoT Top 10 | latest | iot |
| OWASP WSTG | 4.2 | webapp methodology |
| MITRE ATT&CK Enterprise | v15+ | red team |
| MITRE ATT&CK Mobile | v15+ | mobile |
| MITRE ATLAS | v4+ | ai-ml |
| CWE | v4.13+ | cross |
| CIS Benchmarks | latest | hardening reference |
| NIST 800-115 | current | pentest methodology |

## Webapp

OWASP Top 10 + WSTG. Sub-tecnicas:

- **Config / Headers** (CWE-693, CWE-200, CWE-209, CWE-319): missing security headers, .git/.env exposed, stack traces, default admin paths, version disclosure.
- **Authentication** (CWE-287, CWE-307, CWE-204, CWE-384): user enumeration via timing, brute force, JWT alg none, weak secret, OAuth redirect_uri lax, token leakage, password reset abuse, MFA bypass.
- **Authorization / IDOR / BOLA** (CWE-639, CWE-862, CWE-863): cross-tenant access via direct ID, encoded ID, sub-resource, vertical privesc, mass assignment of role.
- **Session** (CWE-352, CWE-613): cookie attrs (HttpOnly/Secure/SameSite), entropy, fixation, timeout, CSRF, logout invalidation, concurrent sessions.
- **Input Validation** (CWE-79, CWE-89, CWE-78, CWE-22, CWE-918, CWE-94, CWE-502, CWE-611, CWE-601): XSS (reflected/stored/DOM/mutation), SQLi (union/blind/time/error/second-order), SSRF (cloud metadata, DNS rebind, file://), command injection, path traversal/LFI/RFI, deserialization, XXE, open redirect.
- **Mass Assignment** (CWE-915): role escalation via body, account_balance, email_verified.
- **Business Logic**: workflow ordering, race conditions (cupom, transfer), limit bypass (negative qty, >100% discount), insecure direct purpose, rate limit semantic.
- **Single-Packet Race Conditions** (Kettle 2023-2024 state-of-the-art): envio de N requests em uma unica janela TCP/HTTP2 (TCP segmentation + last-byte sync) elimina jitter de rede que mascara race conditions classicas. Explora limit bypass em pagamento (claim cupom 2x), votacao (vote 2x), saldo (transfer simultanea), MFA (consumir token N vezes). Tool canonica: `turbo-intruder` (Burp extension de Kettle) com engine `--engine=enginemodes`. Detection raro — bug paga premium em BB enterprise.
- **Client-side** (CWE-1021, CWE-352): CORS misconfig, postmessage, CSP bypass, service worker, websocket origin.
- **HTTP Request Smuggling** (CWE-444): descompasso de parsing entre proxy frontend e backend de origem na delimitacao da request. Variantes canonicas: **CL.TE** (frontend usa Content-Length, backend usa Transfer-Encoding), **TE.CL** (inverso), **TE.TE** (ofuscacao do header TE para um lado ignorar). Impacto: cache poisoning, request hijacking, bypass de WAF/auth frontend, atingir endpoints internos. Tools: `smuggler.py`, Burp Repeater com header manipulation, Caido workflows. Referencia obrigatoria: trabalho do James Kettle (PortSwigger albinowax) — papers "HTTP Desync Attacks" 2019/2022/2023 e Browser-Powered Desync Attacks. 99% dos scanners genericos ignoram; payload alto.

Skill primaria: `cyber-pentest-webapp`.

## API (REST / GraphQL / gRPC)

OWASP API Top 10 (2023):

- **API1 BOLA** (CWE-639): cross-tenant via {id}.
- **API2 Broken Auth** (CWE-287): token sem expiracao, JWT manipulation.
- **API3 Object Property Level**: over-exposure (response inclui campos privilegiados), mass assignment.
- **API4 Resource Consumption** (CWE-770): sem rate limit, bulk fetch sem cap, query depth.
- **API5 Function Level Auth** (CWE-862): /admin/* alcancavel, HTTP method tampering.
- **API6 Business Flow Abuse**: comprar fora de janela, spam, re-resgate cupom.
- **API7 SSRF**: ver webapp.
- **API8 Misconfig**: CORS, headers, version.
- **API9 Inventory**: v1 deprecada online, endpoints "internal" externos.
- **API10 Unsafe 3rd Party**: API consome resposta sem validar.
- **GraphQL**: introspection prod, depth/complexity, aliasing rate-limit bypass, batching.
- **gRPC**: reflection prod, sem TLS, metadata abuse, streaming sem timeout.

Skill primaria: `cyber-pentest-webapp` (atendendo API tambem; futuro `cyber-pentest-api`).

## Mobile (iOS / Android)

OWASP MASTG + MASVS. Sub-tecnicas:

- **Static**: manifest permissions excessivas, exported components, hardcoded URLs/keys, weak crypto (`MD5`/`DES`/`ECB`), insecure storage (SharedPrefs plain), WebView config.
- **Network**: cert pinning bypass via Frida, TLS version, sensitive data em logs.
- **Auth & Session**: token storage (Keystore vs SharedPrefs), biometric (Cipher-bound), session timeout.
- **IPC / Deep links**: exported components consuming intent, deep link XSS in WebView, custom scheme confusion.
- **Runtime tampering**: root/jailbreak detection bypass, anti-debugging, code obfuscation quality.
- **Backup**: allowBackup, iTunes backup com data sensivel.
- **Local data abuse** (rooted/jailbroken): /data/data/<pkg>/, Keychain dump.

Skill primaria: futuro `cyber-pentest-mobile`; interim `cyber-pentest-webapp` para sub-tecnicas web em WebView.

## Cloud (AWS / GCP / Azure / K8s)

CIS + ATT&CK Cloud. Sub-tecnicas:

- **AWS**: IAM (Action:*, Resource:*, root keys, last-used > 90d), S3 (public buckets, sem encryption, sem block public ACL), Security Groups (0.0.0.0/0), IMDSv1, CloudTrail/GuardDuty disabled, Lambda env secrets.
- **GCP**: SA com Editor/Owner, SA keys, GCS public, SSH keys project-wide.
- **Azure**: RBAC Owner amplo, Storage public, NSGs abertos, Key Vault frouxo.
- **Kubernetes**: API server externo, RBAC cluster-admin, Network Policies ausentes, pods root/privileged, hostNetwork/hostPID, ConfigMaps com secrets, container escapes.
- **Container images**: CVEs em packages, secrets em layers, Dockerfile misconfig.
- **Supply chain cloud**: registries publicos, untrusted images, Helm sem verify.
- **IaC ofensivo** (Terraform/CFN/Pulumi/Helm/Kustomize): templates com `*` policy, secrets hardcoded, modulos publicos nao verificados, drift entre IaC declarado e estado real (recursos orfaos = surface invisivel). Tools: `checkov` (canonico, ja em registry), `tfsec`, `kics`, `terrascan`.
- **Attack boxes efemeras** (pattern ofensivo): provisionar VM/container atacante via IaC (Terraform + cloud-init) que: (a) instala toolset (`nuclei`, `ffuf`, etc.), (b) executa scope-bound, (c) exfiltra evidence redacted via Atlas Evidence Ledger, (d) auto-destroi. Reduz attribution e cleanup manual. Constraint: scope_proof obrigatorio + cost gate; viola refusal cyber-ref-052 se atinge sistema critico em prod.

Skill primaria: futuro `cyber-pentest-cloud`.

## Network / Active Directory

MITRE ATT&CK. Sub-tecnicas:

- **Discovery passivo**: Responder analise, LLMNR/NBNS, mDNS, DHCP traffic.
- **LDAP/AD enum**: BloodHound + SharpHound, password policy, ASREPRoast candidates, Kerberoast SPNs.
- **Kerberoasting** (T1558.003): GetUserSPNs + crack.
- **ASREPRoasting** (T1558.004): GetNPUsers + crack.
- **NTLM Relay** (T1187): apenas com `red-team-c2` clausula.
- **Pass-the-Hash / Ticket** (T1550.002).
- **Privesc**: BloodHound shortest path, DCSync, delegation abuse, ACL abuse.
- **Lateral**: WMI exec, WinRM, SMB exec, RDP.
- **Credential dumping**: LSASS (apenas sandbox), SAM offline, DPAPI.
- **GPP Decryption** (CVE-2014-1812): cpassword exposto em SYSVOL Group Policy Preferences; key publica conhecida da MS, decrypt offline com gpp-decrypt/PowerSploit `Get-GPPPassword`. Lega quando dominio nao migrou para LAPS.
- **AD CS abuse** (Certified Pre-Owned ESC1-15): template de certificado com `EnrolleeSuppliesSubject=true` permite request com SAN arbitrario (ESC1); CA com `EDITF_ATTRIBUTESUBJECTALTNAME2` (ESC6); access control fraco em template (ESC4); enroll via vulnerable web endpoints HTTP (ESC8). Tools: `certipy-ad`, `Certify`, BloodHound com `+CertCollector`.
- **Persistence**: apenas com clausula explicita.

Skill primaria: futuro `cyber-pentest-network-ad`.

## Source Review

OWASP ASVS + CWE Top 25 + SANS Top 25. Sub-tecnicas:

- Setup: clone immutable, identify entry points + boundary functions + trust boundaries.
- SAST: semgrep, custom rules, triagem manual obrigatoria.
- Secret scan: gitleaks, trufflehog.
- Dep scan: osv-scanner, grype.
- Manual taint: identificar sources (request input) + sinks (SQL, exec, eval, redirect, render); buscar paths sem sanitizacao.
- Auth/authz manual review.
- Crypto review (ver secao Crypto).
- Logging review: PII em logs, log injection, audit log de events criticos.

Skill primaria: `cyber-pentest-webapp` (estende para source); futuro `cyber-source-reviewer`.

## Crypto Review

NIST 800-131A + OWASP Cryptographic Storage. Sub-tecnicas:

- Algoritmos: whitelist (AES-GCM, ChaCha20-Poly1305, SHA-256+, HMAC-SHA-256+, Ed25519, X25519, Argon2id, bcrypt cost ≥12). Bandeira vermelha: MD5/SHA-1, DES/3DES, RC4, ECB, RSA PKCS#1v1.5 encryption, RSA <2048, PBKDF2 iter <100k.
- Modes & padding: ECB nunca, CBC sem MAC = padding oracle, GCM IV/nonce nao reusado.
- Randomness: `crypto.randomBytes` ok, `Math.random()` nao.
- Key management: hardcoded ban, derivation com salt, KMS/HSM/Vault, rotation.
- Nonce/IV: unicos por uso.
- AEAD preferido, encrypt-then-MAC.
- TLS: 1.2+ (1.3 preferido), forward secrecy, AEAD ciphers, HSTS, OCSP stapling.
- JWT: alg whitelist, secret entropy, expiracao, audience/issuer.
- Custom protocols: replay protection, forward secrecy, downgrade resistance.
- Side channels: constant-time compare em MAC/token.

Skill primaria: futuro `cyber-crypto-reviewer`; interim `cyber-pentest-webapp`.

## Supply Chain Security

CycloneDX + SLSA + ATT&CK Supply Chain. Sub-tecnicas:

- SBOM completa via syft.
- Dep vulnerabilities via grype/osv-scanner/trivy.
- Outdated/abandoned deps.
- Typosquatting / dependency confusion check.
- Build pipeline review (CI files, secrets, cache poison, reproducibility, signing).
- Attestations (SLSA Provenance, VEX).
- Container base image (maintained, minimal, pin por digest).
- Verify signatures (cosign).

Skill primaria: futuro `cyber-supply-chain-auditor`; interim `cyber-pentest-webapp`.

## AI/ML Security (LLM red team)

OWASP LLM Top 10 + MITRE ATLAS. Sub-tecnicas:

- **LLM01 Prompt Injection**: direct + indirect (payload em conteudo lido), multi-turn degradation, cross-language.
- **LLM02 Insecure Output Handling**: output -> eval/SQL/HTML sem escape.
- **RAG Poisoning / Vector Store Contamination**: injetar documentos maliciosos no corpus RAG do alvo (via web crawl, upload features, public scraping) que serao indexados e influenciam respostas a queries futuras. Sub-tecnicas: (a) prompt injection escondido em conteudo plain (LLM lê e segue), (b) embedding inversion attacks (queries craftadas que extraem chunks proximos a target embedding), (c) vector index manipulation (se index e re-treinado com input do atacante), (d) cross-tenant leak via shared vector store sem isolation por tenant. Detection: monitorar diff de respostas a mesma query ao longo do tempo + audit de novos documentos indexados.
- **LLM03 Training Data Poisoning** (apenas se modelo treinado pelo cliente).
- **LLM04 Model DoS**: infinite loop, recursive function calls em agents.
- **LLM05 Supply Chain**: HF model verified, pickle unsafe, model signing.
- **LLM06 Sensitive Info Disclosure**: system prompt revelation, other users' data, PII memorizada.
- **LLM07 Insecure Plugin Design**: agent tools com permissoes amplas.
- **LLM08 Excessive Agency**: agent encadeia acoes irreversiveis sem confirmation.
- **LLM09 Overreliance**: sistema produz info critica sem human-in-the-loop.
- **LLM10 Model Theft**: extracao massiva sem rate limit.
- **Jailbreaks**: DAN, AIM, evil-confidant, persona override, encoded payloads.
- **Agent misuse**: pedir acao que viola scope/refusal, confused deputy.

**Recursos de aprendizado validados** (treinamento progressivo entry -> pro):

| Recurso | Nivel | Onde |
|---|---|---|
| Lakera Gandalf | iniciante (prompt injection 101) | `https://gandalf.lakera.ai` |
| Arcanum AI Security Resource Hub (Agent Breaker) | intermediario (apps reais com guardrails) | GitHub `arcanum-sec` |
| Auto Parts CTF | profissional (LLMs encadeados, validado em consultoria real) | recursos Arcanum |

Skill primaria: futuro `cyber-llm-redteamer`.

## IoT / Embedded

OWASP IoT Top 10 + ATT&CK ICS. Sub-tecnicas:

- Firmware extraction: vendor download, UART/JTAG/SPI/eMMC.
- Firmware static: binwalk, filesystem extraction, hardcoded creds, default services.
- Firmware dynamic: qemu emulation, web UI (aplica webapp).
- Hardware: UART shell, JTAG debug, SPI/I2C, glitching (apenas test devices).
- RF/wireless: WiFi (deauth, evil twin com clausula), Bluetooth/BLE, Zigbee/Z-Wave/LoRa.
- Companion app (delega para mobile).
- Cloud backend (delega para cloud).
- Update mechanism: assinado, rollback protection, HTTPS.
- Boot security: secure boot, bootloader unlocked, encryption at rest.

Skill primaria: futuro `cyber-pentest-iot`.

## Threat Modeling

STRIDE + PASTA + Attack Trees + LINDDUN. Sub-tecnicas:

- Scoping: macro vs micro, boundary, stakeholders.
- Data Flow Diagram: entities, processes, data stores, data flows, trust boundaries.
- STRIDE em cada elemento.
- Attack Trees para objetivos criticos (e.g., compromete pagamento).
- Mitigation mapping (controle existente OU proposto).
- Risk rating (likelihood x impact).
- Versionamento (`_assets/threat-models/<system>/v<n>.md`).

Skill primaria: futuro `cyber-threat-modeler`; interim integrado em `cyber-pentest-webapp`.

## Red Team / Adversary Emulation

MITRE ATT&CK Enterprise. Sub-tecnicas (apenas com clausula `red-team-c2`):

- Initial Access: phishing, web exploit, VPN cred stuffing.
- Execution + Persistence: C2 channel, persistence per actor.
- Defense Evasion: AMSI bypass, ETW patching, process injection.
- Credential Access: LSASS dump, DPAPI, Kerberoast.
- Discovery + Lateral: BloodHound, pivot via SMB/WMI/WinRM.
- Collection + Exfil: limitado a exfiltration_proof_max_bytes.
- Impact: simulado em test env apenas.
- Cleanup obrigatorio: persistence removida, artifacts removidos.
- White cell informada, crawl-walk-run em OPSEC.

Skill primaria: futuro `cyber-red-teamer`.

## Reference Library

Recursos canonicos indexados pelo Atlas Open Brain (consulta antes de gerar payload novo). Skills cyber-* podem referenciar sem copiar conteudo.

| Recurso | Tipo | Funcao no Atlas |
|---|---|---|
| **PayloadsAllTheThings** (swisskyrepo/PayloadsAllTheThings) | Repo GitHub — payloads + bypass por categoria | Atlas indexa para gerar variacoes (WAF bypass, encoding tricks, polyglot). Skills consultam antes de testar variantes manuais. |
| **Real-World Bug Hunting** (Peter Yaworski) | Livro / metodologia | Mentalidade logica para BOLA/IDOR/business logic. Skill `cyber-pentest-webapp` usa o framework "what did the dev forget?" como heuristica. |
| **HackTricks** (carlospolop/hacktricks) | Wiki tecnica massiva | Cobertura per-tecnica com snippets executaveis; Atlas usa como deep-dive quando KB interna nao cobre subcategoria. |
| **OWASP Cheat Sheet Series** | Defensivo | Pareado com remediation-patterns.md — Atlas valida fix proposto contra cheat sheet aplicavel. |
| **PortSwigger Web Security Academy** | Treinamento + papers | Fonte primaria para HTTP Smuggling, prototype pollution, browser exploitation. Papers do James Kettle indexados para Atlas raciocinar sobre desync. |
| **Bug Bounty Reports Templates** (jhaddix/tbhm) | Templates + war stories | Skill `cyber-bb-triage` usa para format de payload por plataforma. |

Atlas Open Brain mantem indice atualizado destes recursos via `atlas engineering knowledge sync --prune`. Recursos novos entram via change-protocol canonico.

## Anti-padroes cross-categoria

- Rodar so scanner C1 e fechar engagement como pentest.
- Reportar finding sem reproducao em sub-tecnica canonica desta KB.
- Pular Business Logic "porque e dificil".
- Test em prod sem clausula explicita do programa BB.
- Mass assignment ignorado "porque API parece ok".
- Reportar XSS reflected sem testar persistence variant.
- Cobertura 100% como metrica de sucesso (profundidade vira vitima).

## Resumo

Tecnicas Red consolidadas por categoria (webapp, api, mobile, cloud, network/AD, source review, crypto, supply chain, AI/ML, IoT, threat modeling, red team coord); referenciadas por skills cyber-* via cross-link, nunca copiadas inline em skill.

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
