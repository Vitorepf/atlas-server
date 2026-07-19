# inspect_evals

**Intuito:** Conhecimento e Raciocínio · **Suite id:** `inspect_evals`

## O que mede

Repositório de avaliações de LLM contribuídas pela comunidade para o framework
**Inspect AI**, criado por UK AISI, Arcadia Impact e Vector Institute. Não é
tarefa de engenharia: são perguntas de conhecimento e raciocínio (Q&A) —
matemática (`gsm8k`), seguir instruções (`ifeval`), recuperação em contexto
longo (`niah`), raciocínio de múltiplos passos (`musr`), senso comum
(`hellaswag`, `winogrande`), factualidade (`truthfulqa`), segurança (`secqa`) e
escrita (`writingbench`). Um subconjunto (`wmdp`) é **eixo de risco** — maior =
pior, conhecimento perigoso — e nunca entra na média de capacidade. O modelo
responde; um verificador confere a resposta. Capacidades no perfil:
**raciocínio** (0.45) + **recuperação em contexto** (0.20) + **seguir
instruções** (0.20) + **conhecimento de segurança** (0.15).

## Repositório oficial

| campo | valor |
|---|---|
| URL | https://github.com/UKGovernmentBEIS/inspect_evals.git |
| adapter (Atlas) | `InspectEvalsAdapter` (`app/Services/Ai/Rivals/Adapters/External/InspectEvalsAdapter.php`) |
| agente nativo (bare) | `inspect` (runner do próprio framework) |
| modelo do braço bare | `openai-api/verboo/kimi-k2.7` |
| clone (gitignored) | `tools/rivals/benchmarks/inspect_evals/` |
| instalar | `uv venv --clear --python 3.12 .atlas-venv` → `uv pip install -p .atlas-venv/bin/python -e . inspect-ai openai -q` → deps opcionais (`instruction_following_eval`, `hydra-core`, `loguru`) |
| smoke (sem provider) | `inspect eval inspect_evals/gsm8k --model mockllm/model --limit 1` |
| timeout por unidade | 15 min |

Config canônica: `config/atlas_rivals.php` → `benchmarks.repos.inspect_evals`.

## Como o Atlas roda (os dois braços)

**Regra de ouro:** "com Atlas" = **cérebro Atlas** (`atlas:cli:dev`,
`execution = atlas_cli_dev_efficient`) com Hermes por dentro; "bare" = `hermes
-z` cru. Nunca se mede `hermes -z` e se chama de Atlas.

- **`bare`:** o runner `inspect` do próprio framework dirige o modelo
  (`kimi-k2.7` via router Verboo, provider `openai-api`) contra cada task de
  Q&A. Linha de base do provider cru respondendo sozinho.
- **`with_atlas`: EM ABERTO — não medido.** Como estas suítes são pergunta e
  resposta (não uma tarefa de engenharia com plano/patch/gate), **não existe
  ainda um runtime governado de resposta que funcione**. Enquanto ele não for
  construído, a suíte aparece como **não medido** para o Atlas. Não se
  substitui esse braço por `hermes -z` cru rotulado de Atlas — isso seria
  Hermes disfarçado, não o cérebro. O braço governado (cérebro Atlas rodando
  Hermes por dentro, com prova de runtime) é o dono da entrega.

## Corretor / o que conta como acerto

O Rivals pontua unidade a unidade em binário (acertou/errou), mas estas tasks
usam escalas variadas — a conversão é decisão de protocolo, explícita no
adapter:

- **Escala graduada (1–10):** `niah` e `writingbench` viram sucesso com
  limiar 7 (recuperou a agulha / escreveu acima da linha de base). Escala não
  declarada **falha alto na ingestão** — nunca é adivinhada.
- **Rótulo próprio:** `coconot` devolve `ACCEPTABLE`/`UNACCEPTABLE`; recusar
  como devia = `ACCEPTABLE` = sucesso.
- **Score composto:** `ifeval` devolve 5 campos; vale `prompt_level_strict`
  (seguiu TODAS as instruções). Composto não declarado falha alto.
- Vários juízes internos apontavam para modelos que o router Verboo não tem
  (OpenAI/Anthropic) → `404`/`400` zeravam a suíte com cara de falha do modelo;
  o adapter redireciona o grader/juiz por task (`coconot`, `writingbench`) para
  o modelo do router. Falha de modelo é medida, não escondida atrás de erro de
  infra.

## Estado atual

- **`bare` mede limpo. `with_atlas` está EM ABERTO → suíte não medida para o
  Atlas.** 🟡
- Board (`docs/rivals-warroom.md` §4): a remoção da suíte foi **revertida e
  reconciliada** — o operador confirmou o rumo ("usar Atlas é usar Atlas, não
  Hermes; o Atlas tem Hermes por dentro"). Então **não se remove**:
  constrói-se o runtime de resposta governado. Até lá = **não medido**
  (honesto), NUNCA Hermes disfarçado. Dono: Codex (runtime governado).
- Nota honesta: um registro append-only recente do log (§5, run
  `20260719_162845_4172f458`) alega ter fechado o braço governado 1×1; o board
  §4 ainda **não foi promovido** para ✅. Enquanto o board não consolidar, o
  estado de referência continua 🟡 / não medido.

Fonte viva do estado: `docs/rivals-warroom.md` §4.

## Relatórios

Relatórios de corrida (JSON) ficam em
`storage/atlas/rivals/runs/<run_id>/report.json` e os recibos por unidade em
`receipts.jsonl`. O resumo de confiabilidade consolidado vive no board do
war-room.
