# AP-186 - AP Number Registry Audit

Status: `foundation-audit-implemented`  
Owner: Atlas Documentation Operating System  
Last updated: 2026-05-08

## 1. Proposito

AP-186 cria uma auditoria read-only para a numeracao de APs.

O objetivo e impedir duplicacao silenciosa de contratos como `AP-176` aparecer duas vezes com sentidos diferentes.

## 2. Escopo Implementado

- `AtlasApNumberRegistryAudit`
- teste unitario contra `docs/ap`
- teste unitario com fixtures temporarias duplicadas
- deteccao de numero duplicado
- deteccao de slug duplicado
- deteccao de filename fora do padrao

## 3. Autoridade

Schema: `atlas.ap_number_registry_audit.v1`  
Modo: `read_only_audit`  
Autoridade: `documentation_number_registry_only_no_file_writes`

O auditor nao renumera arquivos, nao escreve docs, nao altera scanner e nao cria AP automaticamente.

## 4. Regras

- filename deve seguir `AP-<number>-<slug>.md`
- cada numero deve ser unico
- cada slug deve ser unico
- gaps sao permitidos, mas o auditor informa ranges e proximo numero sugerido
- duplicata ou filename malformado gera status `attention`

## 5. Uso Futuro

O Codex principal pode plugar esta auditoria em Architecture Readiness, docs-health ou Architecture Operations.

Antes disso, ela permanece isolada como fundacao testada para evitar fluxo paralelo.

## 6. Nao Escopo

- nao cria comando
- nao cria API
- nao altera docs automaticamente
- nao altera `KernelArchitectureStaticScanner`
- nao bloqueia migrations ou deploys sozinho

## 7. Definition of Done

- diretorio real `docs/ap` passa sem duplicatas
- fixture temporaria com duplicata falha
- fixture temporaria com filename malformado falha
- resultado declara guardrails sem escrita
