# AP-188 - AP Documentation Governance Registry

Status: `foundation-registry-implemented`  
Owner: Atlas Documentation Operating System  
Last updated: 2026-05-08

## 1. Proposito

AP-188 agrega os auditores AP-186, AP-187, AP-189, AP-190 e AP-197 em um unico registry read-only.

Ele responde uma pergunta operacional simples: a pasta `docs/ap` esta segura para receber o proximo AP?

## 2. Escopo Implementado

- `AtlasApDocumentationGovernanceRegistry`
- agregacao do audit de numeracao
- agregacao do audit de `related_paths`
- agregacao do audit de `line_limit`
- agregacao do audit de frontmatter declarativo
- agregacao do audit de taxonomia de status
- blockers canonicos
- teste com diretorio real
- teste com APs temporarios quebrados

## 3. Autoridade

Schema: `atlas.ap_documentation_governance_registry.v1`  
Modo: `read_only_registry`  
Autoridade: `ap_documentation_governance_only_no_file_writes`

O registry nao escreve arquivo, nao renumera AP, nao cria path ausente e nao altera scanner.

## 4. Blockers

- `duplicate_ap_numbers`
- `duplicate_ap_slugs`
- `malformed_ap_filenames`
- `missing_ap_related_paths`
- `ap_line_limit_overflow`
- `ap_frontmatter_shape_violation`
- `ap_status_taxonomy_violation`

Quando nao ha blockers, o proximo passo e `continue_ap_development_with_next_suggested_number`.

## 5. Uso Futuro

Pode ser embutido em Architecture Readiness, docs-health ou Architecture Operations.

Antes disso, fica isolado para nao criar fluxo paralelo.

## 6. Nao Escopo

- nao cria comando
- nao cria API
- nao altera `KernelArchitectureStaticScanner`
- nao exige `related_paths` em todos os APs
- nao corrige docs automaticamente

## 7. Definition of Done

- registry real retorna `ok`
- fixture com duplicata/missing path retorna `attention`
- blockers indicam fonte e contagem
- guardrails provam ausencia de escrita
