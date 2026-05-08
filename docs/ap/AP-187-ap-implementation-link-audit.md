# AP-187 - AP Implementation Link Audit

Status: `foundation-audit-implemented`  
Owner: Atlas Documentation Operating System  
Last updated: 2026-05-08

## 1. Proposito

AP-187 cria uma auditoria read-only para os `related_paths` declarados nos APs.

O objetivo e garantir que um AP nao aponte para servico, teste, comando ou controller inexistente.

## 2. Escopo Implementado

- `AtlasApImplementationLinkAudit`
- teste unitario contra `docs/ap`
- teste unitario com AP temporario apontando para arquivo ausente
- parsing simples do frontmatter local
- status `attention` quando algum related path quebra

## 3. Autoridade

Schema: `atlas.ap_implementation_link_audit.v1`  
Modo: `read_only_audit`  
Autoridade: `ap_related_paths_only_no_file_writes`

O auditor nao cria arquivos, nao altera docs, nao mexe no scanner e nao corrige paths automaticamente.

## 4. Regras

- somente APs com `related_paths` sao avaliados
- cada path precisa existir relativo a raiz do repo
- path ausente entra em `missing_paths`
- AP sem `related_paths` nao falha neste bloco

## 5. Uso Futuro

Pode ser conectado futuramente ao Architecture Readiness, docs-health ou Architecture Operations.

Antes disso, fica como fundacao isolada e testada para melhorar a higiene documental.

## 6. Nao Escopo

- nao cria comando
- nao cria API
- nao exige que todo AP tenha `related_paths`
- nao altera `KernelArchitectureStaticScanner`
- nao modifica docs automaticamente

## 7. Definition of Done

- APs reais com `related_paths` passam
- fixture temporaria com arquivo ausente falha
- resultado declara guardrails sem escrita
