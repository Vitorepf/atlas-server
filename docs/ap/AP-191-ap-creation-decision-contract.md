# AP-191 - AP Creation Decision Contract

Status: `foundation-contract-implemented`  
Owner: Atlas Documentation Operating System  
Last updated: 2026-05-08

## 1. Proposito

AP-191 cria um contrato read-only para decidir se um novo AP pode ser iniciado.

Ele evita numero duplicado, salto de numeracao e slug fora de padrao antes que outro agente escreva documentacao.

## 2. Escopo Implementado

- `AtlasApCreationDecisionContract`
- consumo exclusivo do AP-188 governance registry
- decisao `allowed` ou `blocked`
- `recommended_doc_path` quando numero e slug sao validos
- testes para fluxo permitido, numero incorreto, slug invalido e blockers

## 3. Autoridade

Schema: `atlas.ap_creation_decision_contract.v1`  
Modo: `read_only_decision_contract`  
Autoridade: `ap_creation_decision_only_no_file_writes`

O contrato nao cria AP, nao renumera arquivos e nao corrige documentacao.

## 4. Regras

- se AP-188 retorna blockers, criacao fica bloqueada
- se `requestedNumber` for informado, deve ser igual ao proximo numero sugerido
- `proposedSlug`, quando informado, deve ser lowercase kebab-case
- `recommended_doc_path` so aparece quando o slug e valido

## 5. Beneficio

Antes de qualquer agente adicionar AP novo, ele pode consultar este contrato e receber a rota segura.

Isso reduz duplicacao, drift documental e AP criado fora da sequencia canonica.

## 6. Nao Escopo

- nao cria comando
- nao cria API
- nao altera Architecture Operations
- nao altera scanner estatico
- nao escreve arquivo em `docs/ap`

## 7. Definition of Done

- fluxo real permite o proximo AP sugerido
- fixture com numero pulado bloqueia criacao
- fixture com slug invalido bloqueia criacao
- fixture com blocker de governanca bloqueia criacao
