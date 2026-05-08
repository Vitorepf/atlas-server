# AP-189 - AP Line Limit Audit

Status: `foundation-audit-implemented`  
Owner: Atlas Documentation Operating System  
Last updated: 2026-05-08

## 1. Proposito

AP-189 cria uma auditoria read-only para `line_limit` declarado no frontmatter dos APs.

O objetivo e impedir que um contrato documentado como curto cresca silenciosamente alem do limite prometido.

## 2. Escopo Implementado

- `AtlasApLineLimitAudit`
- teste unitario contra `docs/ap`
- teste unitario com AP temporario acima do limite
- integracao read-only no AP-188 governance registry

## 3. Autoridade

Schema: `atlas.ap_line_limit_audit.v1`  
Modo: `read_only_audit`  
Autoridade: `ap_line_limit_only_no_file_writes`

O auditor nao trunca docs, nao reescreve APs, nao altera scanner e nao exige limite em todos os arquivos.

## 4. Regras

- somente APs com `line_limit` sao avaliados
- `line_count <= line_limit` passa
- estouro gera status `attention`
- resultado informa `excess_lines`

## 5. Uso Futuro

Pode ser plugado em Architecture Readiness, docs-health ou Architecture Operations.

Antes disso, fica isolado dentro da governanca documental de APs.

## 6. Nao Escopo

- nao cria comando
- nao cria API
- nao corrige docs automaticamente
- nao exige que todo AP declare `line_limit`
- nao mexe no `KernelArchitectureStaticScanner`

## 7. Definition of Done

- APs reais com `line_limit` passam
- fixture acima do limite falha
- AP-188 passa a incluir o status de line limits
