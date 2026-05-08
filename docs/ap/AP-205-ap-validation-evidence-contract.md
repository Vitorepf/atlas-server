---
title: AP-205 AP Validation Evidence Contract
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApValidationEvidenceContract.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApValidationEvidenceContractTest.php
depends_on:
  - AP-196
  - AP-203
---

# AP-205 - AP Validation Evidence Contract

## Proposito

AP-205 declara o formato canonico da evidencia de validacao usada para fechar trabalho em APs.

Ele nao decide se o trabalho esta completo. Essa decisao continua no AP-196.

## Posicao

Este contrato fica entre o agente que executou validacoes e o AP-203.

Ele ajuda o agente a montar evidencia consistente antes de chamar o completion report.

## Saida

Schema: `atlas.ap_validation_evidence_contract.v1`  
Modo: `read_only_validation_evidence_contract`  
Autoridade: `ap_validation_evidence_shape_only_no_command_execution`

## Chaves Obrigatorias

Booleanas:

- `code_or_doc_changes_scoped`
- `focused_tests_passed`
- `docs_health_ok`
- `architecture_validate_ok`
- `git_diff_check_passed`
- `ap_doc_updated`

Array:

- `uncovered_changed_paths`

## Chaves Opcionais

- `uncovered_paths_reviewed`
- `commands`
- `notes`

## Regras

- booleanos precisam ser booleanos reais
- arrays precisam ser arrays reais
- chave desconhecida invalida o shape
- completion status e delegado para AP-196

## Guardrails

- nao escreve arquivos
- nao executa comandos
- nao marca completo
- nao aceita chaves desconhecidas
- exige valores booleanos explicitos

## Beneficio

O Atlas passa a ter um contrato de evidencia antes da conclusao.

Isso reduz "validei" sem comando declarado, chave inventada e evidencia ambigua entre agentes.

## Criterios de Aceite

- template declara todos os campos esperados
- evidencia completa retorna `valid_shape`
- chave ausente retorna erro
- tipo errado retorna erro
- chave desconhecida retorna erro
