# Benchmarks do Atlas

Casa canônica dos benchmarks que o Atlas usa para se medir. Organizada **por
intuito** — o Atlas é para ser de tudo, então cada área de capacidade tem sua
pasta, e cada benchmark dentro dela é documentado por inteiro.

> **Regra de ouro (não esquecer):** *usar o Atlas é usar o Atlas — não é o
> Hermes.* O Atlas é o **cérebro** (workflow governado, gate, memória); dentro
> dele um **runtime Hermes** executa; embaixo, o **provider** (Verboo/kimi). O
> braço "com Atlas" de qualquer benchmark **tem** que rodar o cérebro Atlas
> (`atlas:cli:dev` → `execution: atlas_cli_dev_efficient`) com o Hermes por
> dentro. Medir `hermes -z` cru e rotular "Atlas" é fraude — e o
> `RuntimeProofAttacher` barra.

## Intuitos (pastas)

| intuito | pasta | benchmarks | mede |
|---|---|---|---|
| Engenharia de Software | [`engenharia-de-software/`](engenharia-de-software/) | terminal_bench · bfcl · senior_swe_bench · swe_bench_live · live_code_bench · hal_harness · aider_polyglot · swe_marathon | o Atlas como executor de engenharia (o coração do perfil) |
| Conhecimento e Raciocínio | [`conhecimento-e-raciocinio/`](conhecimento-e-raciocinio/) | inspect_evals | Q&A de conhecimento, raciocínio, recusa apropriada |
| Agentes e Ferramentas | [`agentes-e-ferramentas/`](agentes-e-ferramentas/) | tau2_bench | diálogo agêntico com uso de ferramentas (atendimento) |

*No futuro:* cyber, finanças, marketing, trading, e qualquer função que um humano
executa — cada uma vira uma pasta irmã aqui.

## Os dois braços de cada medição

Todo benchmark roda em **dois braços** para isolar o ganho do Atlas:

- **`bare` (baseline):** o modelo sozinho — `hermes -z` direto no provider. É a
  linha de base "provider cru".
- **`with_atlas`:** o **cérebro Atlas** (`atlas:cli:dev`, com preflight, plano,
  patch, gate de qualidade, worktree isolada) usando o Hermes como runtime
  interno. É o que mede o **multiplicador** do Atlas sobre o mesmo modelo.

A prova de que o braço `with_atlas` de fato rodou o Atlas vive no recibo, em
`metadata.runtime_bridge` (`execution: atlas_cli_dev_efficient`,
`real_provider: true`, `provider: hermes_cli`). Sem essa prova, a unidade vira
`environment_failure` (não medida) — nunca um número falso.

## Onde ficam os repositórios que rodam

Cada benchmark é um **clone git do repo oficial** (upstream), grande e volátil,
por isso **gitignored** e mantido num root único de trabalho — não versionado e
não reorganizado por intuito (a config assume um root plano):

- Root: `tools/rivals/benchmarks/<suite>/` (override por
  `ATLAS_RIVALS_BENCHMARKS_ROOT`).
- Cada README de suíte aqui aponta a URL oficial, o commit, o instalador e o
  smoke. Para (re)clonar/instalar, use o pipeline do Rivals — nunca commite o
  clone.

Ou seja: **esta pasta documenta e organiza; os clones executam no root de
trabalho.** As duas coisas são separadas de propósito.

## Como uma medição roda (visão de cima)

1. App (Mobile/Desktop) → `POST /arena/runs` enfileira `suite × engine × braço`.
2. `php artisan atlas:arena:drain --approve-provider-spend` drena a fila.
3. Pipeline Rivals: plan → `rivals-native-runner.php` por unidade → import →
   verify → adjudicate → report.
4. Resultado agrega em scoreboard / composto / capacidades — o que o app mostra.

## Mapa de fontes

- Config dos repos (URL/adapter/agente/smoke): `config/atlas_rivals.php` → `benchmarks.repos`
- Perfil, pesos e capacidades: `config/atlas_arena.php`
- Catálogo canônico das suítes: `docs/engineering-knowledge-base/atlas-rivals-external-suites-v1.md`
- Coordenação viva Claude⇄Codex e estado por suíte: `docs/rivals-warroom.md`
- Braço com-Atlas (bridge/endpoint): `scripts/rivals-atlas-dev-bridge.php`

## Estado por suíte

O estado de confiabilidade atual (quais braços medem limpo, causas-raiz abertas)
vive no board de `docs/rivals-warroom.md` §4 e no bloco "Estado atual" de cada
README de suíte.
