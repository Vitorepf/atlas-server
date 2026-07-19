# Benchmarks do Atlas

Casa canônica dos benchmarks que o Atlas usa para se medir. Organizada **por
capacidade**, e separando o que **roda no Mac** do que **precisa de x86**.

> 📋 **A lista completa, verificada, do que roda no seu Mac** (por capacidade) +
> o que está estacionado: **[`LISTA-COMPLETA.md`](LISTA-COMPLETA.md)**.
> O mapa do landscape inteiro (~58 benchmarks, fonte + prioridade):
> [`CATALOGO-EXPANSAO.md`](CATALOGO-EXPANSAO.md).

> **Regra de ouro:** *usar o Atlas é usar o Atlas — não é o Hermes.* O Atlas é o
> **cérebro** (workflow governado, gate, memória); dentro dele um **runtime
> Hermes** executa; embaixo, o **provider** (Verboo/kimi). O braço "com Atlas" tem
> que rodar o cérebro Atlas (`atlas:cli:dev` → `execution: atlas_cli_dev_efficient`)
> com o Hermes por dentro. Medir `hermes -z` cru e rotular "Atlas" é fraude.

## Capacidades (o que roda no Mac, nativo)

| capacidade | pasta | integrados |
|---|---|---|
| Codificação (gerar/editar/depurar/testar) | [`codificacao/`](codificacao/) | live_code_bench · aider_polyglot |
| Uso de ferramentas / function calling | [`uso-de-ferramentas/`](uso-de-ferramentas/) | bfcl |
| Pesquisa / agente geral | [`pesquisa/`](pesquisa/) | — (GAIA candidato) |
| ML & Dados | [`ml-e-dados/`](ml-e-dados/) | — (DS-1000, MLAgentBench candidatos) |
| Conhecimento & raciocínio (modelo cru) | [`conhecimento-e-raciocinio/`](conhecimento-e-raciocinio/) | inspect_evals · tau2_bench |

## Precisa de x86 (estacionado)

[`_estacionados-precisa-x86/`](_estacionados-precisa-x86/) — família SWE-bench,
terminal, hal, e todos os de Docker/GPU. **Não rodam confiável no arm64** (emulação
x86 corrompe o resultado — provado: até o gold do SWE-bench falha aqui). Ficam
prontos para quando houver x86.

## Os dois braços de cada medição

- **`bare`:** o modelo sozinho — `hermes -z` no provider. Linha de base.
- **`with_atlas`:** o cérebro Atlas (`atlas:cli:dev`) usando o Hermes por dentro.

Cada benchmark roda os dois braços, cospe o **relatório dele mesmo**, e o Atlas
junta todos os relatórios num só.

## Onde ficam os repositórios que rodam

Clones dos repos oficiais (gitignored, grandes) no root de trabalho
`tools/rivals/benchmarks/<suite>/` (override por `ATLAS_RIVALS_BENCHMARKS_ROOT`).
Esta pasta documenta e organiza; os clones executam no root de trabalho.

## Mapa de fontes

- Config dos repos: `config/atlas_rivals.php` → `benchmarks.repos`
- Perfil, pesos, capacidades: `config/atlas_arena.php`
- Coordenação e estado por suíte: `docs/rivals-warroom.md`
