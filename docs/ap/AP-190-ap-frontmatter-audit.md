# AP-190 - AP Frontmatter Audit

Status: `foundation-audit-implemented`  
Owner: Atlas Documentation Operating System  
Last updated: 2026-05-08

## 1. Proposito

AP-190 cria uma auditoria read-only para frontmatter declarado em APs.

Ela valida somente APs que ja possuem frontmatter, evitando migracao em massa e mantendo a regra incremental.

## 2. Escopo Implementado

- `AtlasApFrontmatterAudit`
- teste unitario contra APs reais
- teste unitario com frontmatter temporario quebrado
- integracao read-only no AP-188 governance registry

## 3. Autoridade

Schema: `atlas.ap_frontmatter_audit.v1`  
Modo: `read_only_audit`  
Autoridade: `ap_frontmatter_shape_only_no_file_writes`

O auditor nao normaliza frontmatter, nao exige frontmatter em todos os APs e nao altera scanner.

## 4. Regras

- AP sem frontmatter nao falha neste bloco
- AP com frontmatter precisa declarar `title`
- AP com frontmatter precisa declarar `status`
- `line_limit`, quando declarado, deve ser inteiro positivo

## 5. Uso Futuro

Pode ser conectado a Architecture Readiness, docs-health ou Architecture Operations.

Antes disso, fica isolado dentro da governanca documental de APs.

## 6. Nao Escopo

- nao cria comando
- nao cria API
- nao corrige metadados automaticamente
- nao exige frontmatter em todos os APs
- nao altera `KernelArchitectureStaticScanner`

## 7. Definition of Done

- APs reais com frontmatter passam
- fixture com frontmatter quebrado falha
- AP-188 passa a incluir status de frontmatter
