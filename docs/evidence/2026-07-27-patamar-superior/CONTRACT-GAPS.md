# Contratos declarados sem dono de produção

Interfaces com **zero** classes concretas em `app/`. Consumidores existem —
logo não é código morto: é **capacidade projetada e não ligada**.

A coluna que importa é *como* o consumidor pega a porta. `?Nullable`/`instanceof`
degrada quando ausente (dormente por design). `required` seria fatal latente.

| Contrato | Consumidores | Modo de consumo | Só teste implementa |
|---|---:|---|---|
| `AtlasNativeWorkerProductionRuntime` | 4 | `?Nullable = null` + `instanceof` guard | sim |
| `ClarificationSink` | 1 | `?Nullable = null` | sim |
| `ConfigReader` | 1 | `?Nullable = null` | não |
| `DistillerAuthorAdapter` | 2 | `?Nullable = null` | não |
| `ObraNodeGate` | 1 | `?Nullable = null` + `instanceof` guard | sim |
| `ProductIntentUncertaintyProbe` | 1 | `?Nullable = null` + `instanceof` guard | sim |
| `RepairAttemptEvaluator` | 2 | required — mas **parâmetro de método**, não do construtor (ver nota) | sim |
| `RepairDiagnosisAdvisor` | 1 | `?Nullable = null` | sim |
| `ScopeRuntimeFacts` | 1 | `?Nullable = null` + `instanceof` guard | não |
| `SymbolLookup` | 2 | `?Nullable = null` | sim |

## Leitura

**Nota sobre `RepairAttemptEvaluator`** — é o único `required`, e mesmo assim não
é fatal: entra como **parâmetro de `RepairOrchestrator::run()`**, não do
construtor, então quem chama fornece. E `AtlasDev\Repair\RepairOrchestrator` não
tem caller de produção — o orquestrador vivo é `Kernel\Repair\
AtlasRepairOrchestrator`, outra classe. Ou seja: porta sem dono, dentro de um
orquestrador sem caller.

Nenhum é fatal latente: todos os consumidores degradam. O custo real é que a
capacidade prometida pela porta não existe em produção — o caso mais pesado é
`AtlasNativeWorkerProductionRuntime`, a execução nativa dos Autônomos, cujo
único implementador hoje é classe anônima de teste.

Fechar cada um é trabalho de capacidade (precisa de decisão de design do
operador), não de higiene. Registrado aqui para não voltar a ser descoberto.
