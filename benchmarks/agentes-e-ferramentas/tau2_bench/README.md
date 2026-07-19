# tau2_bench

**Intuito:** Agentes e Ferramentas · **Suite id:** `tau2_bench`

## O que mede

τ-bench (Sierra Research) é um framework de **simulação de atendimento** que
avalia agentes conversacionais em domínios reais (airline, retail, banking). Cada
tarefa dá ao agente um conjunto de ferramentas e um usuário simulado por LLM; o
agente precisa conduzir o diálogo multi-turno, chamar as ferramentas certas na
ordem certa e deixar o estado final (base de dados + o que foi comunicado) igual
ao esperado. Não é tarefa de engenharia de software: é **uso de ferramenta em
diálogo agêntico**. Capacidades no perfil: **uso de ferramenta** (0.60) +
**diálogo agêntico** (0.40).

## Repositório oficial

| campo | valor |
|---|---|
| URL | https://github.com/sierra-research/tau2-bench.git |
| adapter (Atlas) | `Tau2BenchAdapter` (`app/Services/Ai/Rivals/Adapters/External/Tau2BenchAdapter.php`) |
| agente nativo (bare) | `tau2` |
| clone (gitignored) | `tools/rivals/benchmarks/tau2_bench/` |
| instalar | `uv venv --clear --python 3.12 .atlas-venv` → `uv pip install -p .atlas-venv/bin/python -e . -q` |
| smoke (sem provider) | `tau2 check-data` |
| timeout por unidade | 15 min |

Config canônica: `config/atlas_rivals.php` → `benchmarks.repos.tau2_bench`.

## Como o Atlas roda (os dois braços)

Regra de ouro: **"com Atlas" = cérebro Atlas** (`atlas:cli:dev`, prova no recibo
`runtime_bridge.execution = atlas_cli_dev_efficient`) **com Hermes como runtime
interno**. **"bare" = `hermes -z` cru**. Nunca medir `hermes -z` e chamar de
Atlas.

- **`bare`:** o agente nativo `tau2` dirige o modelo (`hermes -z` no provider
  Verboo/kimi, `openai/kimi-k2.7`) contra o simulador de usuário e as ferramentas
  do domínio. Linha de base do provider cru — mede o modelo.
- **`with_atlas`: EM ABERTO — hoje não há braço Atlas válido para esta suíte.**
  τ-bench é Q&A/diálogo, não código, e o Atlas **não tem runtime de resposta-pura
  governado que funcione**: `atlas:cli:dev` e `atlas:ai:chat` são cérebros de
  ENGENHARIA e erram o enquadramento numa conversa de atendimento (roteiam pra
  pipeline de programming, devolvem `response_text=null`). O único proxy que já
  existiu (`scripts/rivals-atlas-openai-endpoint.php`) rodava `hermes --yolo -z` —
  ou seja, `hermes -z` cru via proxy —, então o `RuntimeProofAttacher` recusa
  corretamente (é o guard anti-fraude "hermes -z disfarçado"). Enquanto não houver
  runtime governado de resposta, o braço Atlas fica **"não medido"**, jamais
  `hermes -z` rotulado de Atlas.

## Corretor / o que conta como acerto

O harness roda a simulação e calcula um **reward** por tarefa: passa quando o
agente conduziu o diálogo, chamou as ferramentas certas e o estado final (base de
dados + informação comunicada ao usuário) bate com o esperado. Falha de modelo é
medida, não escondida; quando a coleta do reward não fecha, o recibo nomeia a
razão (ex.: `normalization_failed:tau2_results_missing`) em vez de quebrar em
silêncio.

## Estado atual

- 🟡 **RECONCILIADO — braço Atlas ainda não existe de verdade.** O board marca
  τ-bench como "não medido" para o Atlas: o braço legítimo seria o **cérebro Atlas
  governado rodando Hermes por dentro + prova**, e ele ainda não foi construído.
  Até lá, **jamais** se rotula `hermes -z` cru de "Atlas".
- Causa-raiz provada (recibos `20260719_070748`): o braço "atlas" apontava o
  base-url pro endpoint proxy que executa `hermes -z` — modelo-cru-via-proxy —, e o
  `RuntimeProofAttacher` recusou com `atlas_dev_runtime_proof_missing_or_invalid`.
  O gate está **certo**; quem não roda Atlas é o braço.
- Correção de erro anterior (registrada no log): o multi-turno funciona — o log
  prova 12 chamadas multi-turno OK. A causa real em aberto é **reward=N/A + coleta**,
  não o diálogo.
- Aberto (dono: Codex): o endpoint precisa de **tool-calling nativo** (não
  prompt-JSON) pro τ-bench fechar reward; e, mais fundo, o Atlas precisa de um
  runtime de resposta governado que não erre o enquadramento de Q&A. Fork pro
  operador: virar **bare-only rotulado** (mede só o modelo, sem delta Atlas) ou
  liberar a construção desse runtime.

Fonte viva do estado: `docs/rivals-warroom.md` §4 (linha `tau2_bench`), §6 (abertos)
e §7 (decisões).

## Relatórios

Relatórios de corrida (JSON) ficam em
`storage/atlas/rivals/runs/<run_id>/report.json` e os recibos por unidade em
`receipts.jsonl`. O resumo de confiabilidade consolidado vive no board do
war-room.
