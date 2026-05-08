---
title: AP-201 AP Governance Repair Proposal Contract
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApGovernanceRepairProposalContract.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApGovernanceRepairProposalContractTest.php
depends_on:
  - AP-188
  - AP-198
---

# AP-201 - AP Governance Repair Proposal Contract

## Proposito

AP-201 cria um contrato read-only para transformar achados da governanca documental dos APs em propostas revisaveis.

Ele existe para impedir que auditorias fiquem apenas como sinais soltos. Quando AP-188 ou AP-198 detectam problema, AP-201 devolve uma lista compacta de reparos humanos.

## Posicao no Atlas

Este bloco pertence ao Documentation Operating System da estrutura mae.

Ele fica depois dos auditores e antes de qualquer automacao de reparo.

## Entradas

- `AtlasApDocumentationGovernanceRegistry`
- `AtlasApDependencyMap`
- caminho opcional de `docs/ap` para testes ou auditorias isoladas

## Saida

Schema: `atlas.ap_governance_repair_proposal_contract.v1`  
Modo: `proposal_only`  
Autoridade: `ap_governance_repair_proposals_only_no_file_writes`

Cada proposta declara:

- `proposal_id` estavel
- tipo de reparo
- motivo
- severidade
- recomendacao humana
- `source_refs`

## Casos Cobertos

- numero AP duplicado
- slug AP duplicado
- filename malformado
- `related_paths` ausente no filesystem
- `line_limit` estourado
- frontmatter declarado e malformado
- status fora da taxonomia
- referencia AP inexistente

## Guardrails

- nao escreve arquivos
- nao aplica reparos
- nao cria APs
- nao remove referencias
- nao renumera arquivos
- exige revisao humana

## Beneficio

O Atlas passa a ter uma ponte limpa entre "auditoria detectou problema" e "humano sabe exatamente o que revisar".

Isso reduz bagunca documental sem criar automacao perigosa.

## Criterios de Aceite

- contrato retorna `ok` quando docs temporarios estao limpos
- contrato retorna `attention` quando ha reparo pendente
- cada proposal tem `proposal_id`, reason, recommendation e source_refs
- missing AP reference vira `repair_missing_ap_reference`
- validacao confirma que nao ha escrita automatica
