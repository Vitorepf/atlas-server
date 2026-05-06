# AP-141 — Ledger Projection Registry Contract

## Problema

A arquitetura mae define que `ai_traces`, `atlas_engineering_runs` e
`atlas_tool_runs` sao projecoes operacionais derivaveis do Evidence Ledger. Antes
deste AP, essa regra vivia como prosa: nao havia um contrato executavel dizendo
quais tabelas sao projections, quais eventos alimentam cada uma e como validar a
prontidao do ambiente.

Sem esse registro, um worker futuro poderia projetar dados com regras paralelas,
ou uma tabela operacional poderia voltar a parecer fonte primaria.

## Contrato

`LedgerProjectionRegistry` deve declarar:

- `ai_traces`;
- `atlas_engineering_runs`;
- `atlas_tool_runs`.

Cada projection deve publicar:

- `id`;
- `table`;
- `model`;
- `domain`;
- `projection_role`;
- `source_events`;
- `identity_keys`;
- `required_columns`;
- `ready`.

`architecture-validate` deve expor `kernel.ledger_projections` com schema
`atlas.ledger_projection_registry.v1`.

## Regras

- `valid` mede contrato: models existem e `source_events` pertencem a
  `LedgerEventType`.
- `ready` mede ambiente: tabela existe e contem as colunas requeridas.
- Falta de tabela/coluna vira warning de readiness, nao falha de contrato. Isso
  permite rodar architecture validate em ambientes parciais sem perder o sinal
  sobre o worker/projection que ainda precisa ser ativado.
- Quando a tabela nao existe, o registry emite apenas `table_missing`; warnings
  de `column_missing` so aparecem para tabelas existentes com schema incompleto.

## Enforcement

`LedgerProjectionRegistryTest` cobre:

- existencia das tres projections canonicas;
- source events de provider, repair e tool evidence;
- identity keys;
- readiness positiva quando as tabelas e colunas requeridas existem.
- readiness negativa sem ruido de colunas quando a tabela inteira ainda nao
  existe.

O scanner `ap141_ledger_projection_registry_contract` valida registry,
architecture validate, testes e documentacao.

## Status

Implementado. Este AP cria a fundacao para o worker incremental que projetara o
Evidence Ledger para as tabelas operacionais sem transformar essas tabelas em
fonte primaria.
