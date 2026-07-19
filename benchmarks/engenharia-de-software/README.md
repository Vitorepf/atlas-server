# Engenharia de Software

O **coração** do perfil do Atlas. São os benchmarks onde o Atlas opera como o que
ele é — um executor de engenharia: abre o projeto, planeja, edita arquivos,
corrige bug, roda teste, entrega patch. É aqui que o braço `with_atlas` mede o
Atlas de verdade (cérebro `atlas:cli:dev` com Hermes por dentro).

> **Expandindo além das 8:** [`CATALOGO-EXPANSAO.md`](CATALOGO-EXPANSAO.md) reúne
> ~58 benchmarks reais (com fonte) para cobrir o **fluxo inteiro** de um agente de
> programação — geração, issue em repo real, multi-arquivo, terminal/DevOps,
> testes, depuração, ferramentas, longo horizonte, ML e segurança — com uma ordem
> de prioridade de integração.

## Benchmarks

| benchmark | mede | repo oficial |
|---|---|---|
| [terminal_bench](terminal_bench/) | operação de terminal + edição de código em tarefas de shell | laude-institute/terminal-bench |
| [bfcl](bfcl/) | function calling / uso de ferramentas em código | ShishirPatil/gorilla |
| [senior_swe_bench](senior_swe_bench/) | tarefas de SWE sênior (harbor) | snorkel-ai/senior-swe-bench |
| [swe_bench_live](swe_bench_live/) | correção de bug em repos reais e vivos | microsoft/SWE-bench-Live |
| [live_code_bench](live_code_bench/) | programação competitiva (edição + raciocínio) | LiveCodeBench/LiveCodeBench |
| [hal_harness](hal_harness/) | agente generalista de longo horizonte | princeton-pli/hal-harness |
| [aider_polyglot](aider_polyglot/) | edição de código multi-linguagem | Aider-AI/aider |
| [swe_marathon](swe_marathon/) | tarefas SWE longas (maratona), via harness harbor | abundant-ai/swe-marathon |

## Capacidades cobertas (perfil arena)

`terminal_operation`, `code_editing`, `bug_fixing`, `tool_use`, `reasoning`,
`long_horizon` — todas de engenharia. Pesos em `config/atlas_arena.php`.
