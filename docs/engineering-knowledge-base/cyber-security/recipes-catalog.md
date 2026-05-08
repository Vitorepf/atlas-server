---
id: atlas-ai-cyber-recipes-catalog
type: engineering_knowledge
title: Atlas AI Cyber Recipes Catalog
status: scaffold
category: knowledge-base
priority: 81
summary: Recipes ofensivas propostas para registro no Super Tool Runtime canonico; nao cria runtime paralelo, apenas estende registry com tools de pentest, recon, AD, mobile, cloud, AI red-team.
tags:
  - atlas-ai
  - cyber-security
  - recipes
  - super-tool-runtime
  - tools
capabilities:
  - cyber_recipes_proposal
decisions:
  - Recipes ofensivas sao propostas para registro no Super Tool Runtime canonico via migrations + entries em atlas_tool_definitions; nao cria sistema paralelo.
  - Cada recipe declara argv, dry_run_default, creates_evidence, blocking_capable, execution_tier, sandbox, privacy_level, task_type.
  - Tools de security defensiva (gitleaks, semgrep, trivy, osv-scanner, syft, checkov) JA EXISTEM no registry canonico; nao recriar.
maintenance:
  - Adicione recipe nova quando skill cyber-* precisar de tool nao registrada.
  - Promocao para registry canonico = migration + entry em atlas_tool_definitions.
related_paths:
  - atlas-server/docs/engineering-knowledge-base/super-tool-runtime-core.md
  - atlas-server/docs/engineering-knowledge-base/programming-power-tools-catalog.md
  - atlas-server/docs/engineering-knowledge-base/cyber-security/refusal-matrix.md
owner: atlas-ai
layer: extension
line_limit: 240
---

# Atlas AI Cyber Recipes Catalog

Recipes ofensivas propostas para Super Tool Runtime canonico.

## Tools JA EXISTENTES no registry canonico

Skills cyber-* podem usar IMEDIATAMENTE (verificar via `atlas tools list`):

- `gitleaks` (recipe `detect-redacted`) — secret scan.
- `semgrep` (recipe `scan-json`) — SAST.
- `osv_scanner` (recipe `recursive-json`) — dep CVEs.
- `trivy` (recipe `fs-json`) — multi-purpose: image, fs, repo, k8s.
- `syft` (recipe `sbom-json`) — SBOM.
- `checkov` (recipe `directory-sarif`) — IaC scan.
- `phpstan`, `typescript`, `eslint`, `biome`, `hadolint` — code quality (uso em remediation).

**Nao re-criar.** Skill cyber-pentest-webapp / cyber-source-reviewer usa esses recipes diretamente.

## Recipes ofensivas a propor

### Recon

```yaml
- tool_slug: nuclei
  recipe_name: scan-templates-json
  category: vulnerability_scan
  argv: [scan, -t, "{templates}", -u, "{target}", -severity, "{severity}", -rate-limit, "{rate_limit}", -j]
  dry_run_default: true
  creates_evidence: true
  blocking_capable: false
  execution_tier: T1
  sandbox: true
  privacy_level: provider_safe
  task_type: vulnerability_scan
  authority_group: vuln_scan
  used_by_skills: [cyber-recon, cyber-pentest-webapp]

- tool_slug: ffuf
  recipe_name: dir-fuzz-json
  category: discovery
  argv: [-u, "{target_with_FUZZ}", -w, "{wordlist}", -mc, "200,301,403", -of, json, -o, "{output}"]
  dry_run_default: true
  creates_evidence: true
  blocking_capable: false
  execution_tier: T1
  sandbox: true
  privacy_level: provider_safe
  task_type: discovery
  used_by_skills: [cyber-recon]

- tool_slug: amass
  recipe_name: enum-passive-json
  category: osint
  argv: [enum, -passive, -d, "{domain}", -json, "{output}"]
  dry_run_default: true
  creates_evidence: true
  execution_tier: T1
  sandbox: true
  used_by_skills: [cyber-recon]

- tool_slug: subfinder
  recipe_name: passive-enum-json
  category: osint
  argv: [-d, "{domain}", -json, -o, "{output}"]
  dry_run_default: true
  creates_evidence: true
  execution_tier: T0
  sandbox: true
  used_by_skills: [cyber-recon]
```

### Webapp / API

```yaml
- tool_slug: sqlmap
  recipe_name: scan-batch-json
  category: webapp_exploit
  argv: [-u, "{target}", --batch, --level, "{level}", --risk, "{risk}", --output-dir, "{output_dir}"]
  dry_run_default: true
  creates_evidence: true
  blocking_capable: true
  execution_tier: T2
  sandbox: true
  privacy_level: scope_only
  task_type: webapp_exploit
  authority_group: webapp_pentest
  requires_extra_approval: true   # exploit ativo
  used_by_skills: [cyber-pentest-webapp]

- tool_slug: zap
  recipe_name: active-scan-json
  category: webapp_scan
  argv: [zap-baseline.py, -t, "{target}", -J, "{output}"]
  dry_run_default: true
  creates_evidence: true
  execution_tier: T2
  sandbox: true
  used_by_skills: [cyber-pentest-webapp]

- tool_slug: wapiti
  recipe_name: scan-json
  category: webapp_scan
  argv: [-u, "{target}", -f, json, -o, "{output}"]
  dry_run_default: true
  creates_evidence: true
  execution_tier: T1
  sandbox: true
  used_by_skills: [cyber-pentest-webapp]

- tool_slug: mitmproxy
  recipe_name: capture-replay
  category: proxy
  argv: [-w, "{output}", -m, "transparent"]
  dry_run_default: true
  creates_evidence: true
  execution_tier: T1
  sandbox: true
  used_by_skills: [cyber-pentest-webapp]
```

### Mobile (futuro skill cyber-pentest-mobile)

```yaml
- tool_slug: mobsf
  recipe_name: static-scan-json
  category: mobile_static
  argv: [scan, "{apk_or_ipa}", --json, "{output}"]
  dry_run_default: true
  creates_evidence: true
  execution_tier: T1
  sandbox: true
  privacy_level: scope_only

- tool_slug: frida
  recipe_name: hook-instrument
  category: mobile_dynamic
  argv: [-U, -f, "{package}", -l, "{hook_script}"]
  dry_run_default: true
  creates_evidence: true
  execution_tier: T2
  sandbox: vm
  requires_extra_approval: true

- tool_slug: objection
  recipe_name: explore-runtime
  category: mobile_dynamic
  argv: [explore, --gadget, "{gadget}"]
  dry_run_default: true
  creates_evidence: true
  execution_tier: T2
  sandbox: vm
```

### Cloud (futuro skill cyber-pentest-cloud)

```yaml
- tool_slug: prowler
  recipe_name: aws-readonly-json
  category: cloud_audit
  argv: [-M, json-asff, -o, "{output_dir}"]
  dry_run_default: true
  creates_evidence: true
  execution_tier: T1
  sandbox: true
  privacy_level: cred_safe
  authority_group: cloud_audit

- tool_slug: scout-suite
  recipe_name: multi-cloud-json
  category: cloud_audit
  argv: ["{provider}", --report-dir, "{output_dir}", --no-browser]
  dry_run_default: true
  creates_evidence: true
  execution_tier: T1
  sandbox: true

- tool_slug: kube-bench
  recipe_name: cis-check-json
  category: k8s_audit
  argv: [run, --json, --benchmark, "{benchmark}"]
  dry_run_default: true
  creates_evidence: true
  execution_tier: T1
  sandbox: true

- tool_slug: kube-hunter
  recipe_name: hunt-passive-json
  category: k8s_offensive
  argv: [--remote, "{target}", --report, json]
  dry_run_default: true
  creates_evidence: true
  execution_tier: T2
  sandbox: true
  requires_extra_approval: true
```

### Network / AD (futuro skill cyber-pentest-network-ad)

```yaml
- tool_slug: nmap
  recipe_name: service-detect-xml
  category: network_scan
  argv: [-sV, -sC, "{target}", -oX, "{output}"]
  dry_run_default: true
  creates_evidence: true
  execution_tier: T1
  sandbox: true

- tool_slug: bloodhound
  recipe_name: collect-sharphound
  category: ad_enum
  argv: [-c, "{collection_method}", -d, "{domain}", -o, "{output_dir}"]
  dry_run_default: true
  creates_evidence: true
  execution_tier: T2
  sandbox: vm
  requires_extra_approval: true

- tool_slug: impacket
  recipe_name: getuserspns-kerberoast
  category: ad_attack
  argv: [-request, "{domain}/{user}:{pass}"]
  dry_run_default: true
  creates_evidence: true
  execution_tier: T2
  sandbox: vm
  requires_extra_approval: true

- tool_slug: crackmapexec
  recipe_name: smb-enum
  category: ad_enum
  argv: [smb, "{target}", -u, "{user}", -p, "{pass}"]
  dry_run_default: true
  creates_evidence: true
  execution_tier: T2
  sandbox: vm
```

### AI/ML red-team (futuro skill cyber-llm-redteamer)

```yaml
- tool_slug: promptfoo
  recipe_name: redteam-eval
  category: llm_redteam
  argv: [eval, -c, "{config}"]
  dry_run_default: false
  creates_evidence: true
  execution_tier: T1
  sandbox: true

- tool_slug: garak
  recipe_name: scan-llm
  category: llm_redteam
  argv: [--model_type, "{type}", --model_name, "{model}", --probes, "{probes}", --report_prefix, "{prefix}"]
  dry_run_default: false
  creates_evidence: true
  execution_tier: T1
  sandbox: true
```

### AI/ML defensivo (auxiliar a cyber-purple-runner e a fluxos LLM)

```yaml
- tool_slug: lakera
  recipe_name: scan-prompt
  category: llm_defense
  argv: [api, scan, --input, "{input_file}", --policy, "{policy}"]
  dry_run_default: true
  creates_evidence: true
  execution_tier: T0
  sandbox: true
  privacy_level: provider_safe
  task_type: prompt_injection_detection
  authority_group: llm_defense
  used_by_skills: [cyber-purple-runner]
  notes: API comercial (Lakera Guard); util como camada defensiva pareada com promptfoo/garak no ciclo Purple. Conhecida pelo CTF Lakera Gandalf usado em treinamento entry-level.
```

### Supply chain (auxiliar)

```yaml
- tool_slug: cosign
  recipe_name: verify-attestation
  category: supply_chain
  argv: [verify-attestation, "{image}", --key, "{key}"]
  dry_run_default: true
  creates_evidence: true
  execution_tier: T0
  sandbox: true

- tool_slug: grype
  recipe_name: image-scan-json
  category: supply_chain
  argv: ["{image}", -o, json, --file, "{output}"]
  dry_run_default: true
  creates_evidence: true
  execution_tier: T1
  sandbox: true
```

## External MCP Tooling

MCP servers externos com capacidade ofensiva sao integrados como **recipe externa via wrapper canonico** (skill cyber-* especifica), nunca conectados crus ao Atlas Decide. Razao: Atlas Decide perde Receipt + scope_proof + refusal matrix se MCP externo for chamado direto.

### `hexstrike-mcp` (HexStrike AI)

```yaml
- tool_slug: hexstrike-mcp
  recipe_name: pentest-orchestrate
  category: external_pentest_orchestrator
  integration: mcp_server
  source: github HexstrikeAI (auditar antes de integrar)
  argv: [hackstrike_server.py, --mode, "{mode}"]
  dry_run_default: true
  creates_evidence: true
  blocking_capable: true
  execution_tier: T2
  sandbox: vm
  privacy_level: scope_only
  task_type: external_orchestrator
  authority_group: external_mcp_pentest
  requires_extra_approval: true
  used_by_skills: [cyber-hexstrike-runner]
  notes: |
    Validado em CTF YesWeHack real (Halloween Special) — identificou Command Injection
    via Unicode normalization bypass, escreveu exploit Python autonomamente, extraiu flag.
    Limitacoes documentadas: dependencia de OS host (Arch vs Kali), excesso de MCPs causa
    lentidao em UI. Atlas integra via wrapper cyber-hexstrike-runner que aplica Receipt,
    scope_proof, refusal matrix, sandbox VM e evidence ledger antes de cada chamada.
constraints:
  - nunca conectar ao Atlas Decide direto via mcpServers config — sempre via wrapper skill
  - VM dedicada por engagement; sem reuso entre tasks
  - requires_extra_approval sempre true (operador confirma cada launch)
  - kill switch automatico se HexStrike sair do scope.in declarado
```

### Padrao geral pra MCP externo

Toda nova integracao MCP de tooling ofensivo segue:

1. Skill wrapper canonica (`cyber-<tool>-runner`) com mesmo formato das demais cyber-*.
2. Recipe externa registrada nesta secao com `integration: mcp_server` e `requires_extra_approval: true`.
3. Sandbox `vm` obrigatorio (sem container compartilhado).
4. ADR no `cyber-security-extension.md` registrando integracao.
5. Wrapper aplica Receipt + scope_proof + refusal matrix + evidence ledger antes de cada chamada MCP.
6. Rate limit de conexoes MCP simultaneas (limitacao documentada do protocolo).

## Promotion path para recipe individual

1. Confirmar tool nao existe no registry: `atlas tools list | grep <tool>`.
2. Adicionar entry em `database/migrations/YYYY_MM_DD_HHMMSS_register_<tool>_<recipe>.php` ou via seeder.
3. Implementar wrapper class em `app/Services/Ai/Tools/<ToolName>Wrapper.php` que respeita argv schema.
4. Sandbox profile aprovado.
5. Test em `tests/Feature/Ai/Tools/<ToolName>Test.php`.
6. Run `atlas tools doctor` para confirmar tool detectada.
7. Dry-run via `atlas tools run-recipe <tool> --recipe=<name> --dry-run`.

## Anti-padroes

- Recriar wrapper de tool ja existente (gitleaks, semgrep, trivy etc.).
- Recipe sem `dry_run_default: true` em recipe ofensiva.
- Recipe com `sandbox: false`.
- Recipe sem `requires_extra_approval: true` para tool de exploit ativo.
- Recipe sem `creates_evidence: true` (perde audit).
