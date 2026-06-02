# Dissecação completa — `karpathy/autoresearch`

> Fonte: clone real de `https://github.com/karpathy/autoresearch` (commit `228791f`, 2026‑03‑25). 10 arquivos, 1.252 linhas. Dissecado linha a linha, não de memória de treino.

---

## 0. TL;DR (uma respiração)

`autoresearch` é a **menor org de pesquisa autônoma que funciona de verdade**. Você dá a um agente de código (Claude/Codex/etc.) um setup **real** de pré‑treino de LLM (single‑GPU, derivado do nanochat) e o deixa **experimentar a noite toda**: ele edita o código, treina por **5 minutos fixos**, mede `val_bpb`, **mantém se melhorou / reverte via `git reset` se não**, e repete (~12 experimentos/hora, ~100 enquanto você dorme). De manhã você acorda com um log de experimentos e um modelo melhor.

**O ponto não‑óbvio e mais importante:** *você não programa o Python.* Você programa o **`program.md`** — o arquivo Markdown que é o "código da org de pesquisa" (as instruções/skill do agente). O agente é o **músculo**; o `program.md` é o **cérebro que você molda**. Karpathy chegou, de forma independente, **exatamente na tese do Atlas.**

---

## 1. Anatomia — as 5 peças

| Peça | Quem edita | Papel |
|---|---|---|
| **`prepare.py`** (389 ln) | **ninguém** (read‑only) | Harness fixo = *ground truth*: download de dados, tokenizer BPE, dataloader, e **`evaluate_bpb`** (a métrica imutável). |
| **`train.py`** (630 ln) | **o agente** | GPT + otimizador MuonAdamW + loop de treino. Hiperparâmetros como constantes no topo. **Tudo é jogo aberto.** |
| **`program.md`** (114 ln) | **o humano** | A "research org code" — instruções do agente. É uma skill leve. |
| **`results.tsv`** | o agente (não‑commitado) | O **ledger** append‑only: `commit  val_bpb  memory_gb  status  description`. |
| **`analysis.ipynb`** | o humano | Review: lê o tsv → contagem keep/discard/crash, curva de `val_bpb`, stats. |

A assimetria é a alma do projeto: **um arquivo de código editável, uma métrica de verdade, um ciclo barato.**

---

## 2. O loop autônomo (o coração — `program.md`)

```
SETUP (uma vez, com o humano):
  branch autoresearch/<tag>  •  ler os arquivos in‑scope  •  results.tsv com header  •  baseline primeiro

LOOP FOREVER:
  1. olhar o git state (branch/commit atual)
  2. editar train.py com uma ideia experimental (hackear o código direto)
  3. git commit
  4. uv run train.py > run.log 2>&1   (redireciona TUDO — nunca floodar o contexto)
  5. grep "^val_bpb:" run.log
  6. vazio? crashou → tail -50 run.log, tenta consertar; se a ideia é quebrada, log "crash" e segue
  7. registra no results.tsv
  8. val_bpb melhorou (menor) → AVANÇA a branch (mantém o commit)
  9. igual ou pior → git reset de volta ao ponto de partida
```

Três regras que **são a disciplina inteira**:

- **"NEVER STOP"** — *"não pause pra perguntar ao humano se deve continuar. Não pergunte 'devo continuar?'. O humano pode estar dormindo. Você é autônomo... Se ficar sem ideias, pense mais: leia os papers referenciados no código, releia os arquivos, combine quase‑acertos, tente mudanças arquiteturais mais radicais. O loop roda até o humano interromper, ponto."*
- **Critério de simplicidade** — *"Tudo igual, mais simples é melhor. Uma melhora de 0.001 que adiciona 20 linhas de código feio? Provavelmente não vale. Uma melhora de 0.001 que vem de DELETAR código? Definitivamente mantém. Melhora ~0 mas código muito mais simples? Mantém."*
- **Keep/discard via git** — antifragilidade por construção. Tenta → mede → mantém se melhor, reverte se pior. O downside é limitado (um `git reset`), o upside compõe.

---

## 3. `train.py` dissecado — o que o agente realmente edita

### 3.1 O modelo (GPT moderno, denso de truques 2024‑2026)

Não é um GPT de tutorial. É uma destilação de SOTA:

- **Pre‑norm + RMSNorm** em tudo (`norm()` = `F.rms_norm`), sem bias em lugar nenhum.
- **Rotary embeddings (RoPE)** pré‑computadas; **QK‑norm** (normaliza q e k *depois* do rotary) — estabiliza o treino.
- **GQA** (grouped‑query attention): `n_kv_head ≤ n_head`.
- **Value Embeddings estilo ResFormer** — embedding de valor por‑token, misturada em `v` com um **gate dependente da entrada por‑head** (`2*sigmoid(...)`), só em camadas alternadas (`has_ve`, última sempre incluída). É "value residual learning".
- **Sliding‑window attention** com padrão **`SSSL`** (S = meia‑janela, L = janela cheia), última camada sempre cheia — via janelas do FlashAttention‑3. Barateia atenção sem perder visão global.
- **MLP ReLU²** (`F.relu(x).square()`) com expansão 4×.
- **Logit softcap** (`15 * tanh(logits/15)`) — evita logits explosivos.
- **Skip‑connections aprendidas pro embedding**: cada camada faz `x = resid_λ[i]·x + x0_λ[i]·x0`, onde `x0` é o embedding inicial. `resid_λ` começa em 1.0, `x0_λ` em 0.1 — "hyper‑connection" leve.
- **Init cirúrgico** (uniforme `±√3·d^-0.5` pras matrizes, zeros nos `c_proj`/gates, `lm_head` std 0.001), embeddings em **bf16**.
- **FlashAttention‑3** via `kernels.get_kernel` (Hopper vs não‑Hopper).

### 3.2 O otimizador `MuonAdamW` (a parte mais sofisticada)

Dois otimizadores num só, por tipo de parâmetro:

- **AdamW (fundido, `@torch.compile`)** para embeddings, `lm_head`, value‑embeds e escalares — cada grupo com LR e betas próprios.
- **Muon (fundido)** para as **matrizes 2D** dos blocos transformer, agrupadas por shape. Muon =
  1. **momentum de Nesterov**,
  2. **ortogonalização "Polar Express"** — iteração tipo Newton‑Schulz com 5 conjuntos de coeficientes tunados (ortogonaliza o gradiente → passos mais "iguais" em todas as direções),
  3. **NorMuon** — redução de variância por normalização do segundo momento,
  4. **cautious weight decay** — só aplica decay onde `g·param ≥ 0` (não luta contra o próprio update).
- **LR escala ∝ 1/√dmodel** (tunado em 768). Truque fino: **tensores 0‑D na CPU** (`_adamw_lr_t` etc.) pra evitar recompilação do `torch.compile` quando os valores mudam a cada passo.

### 3.3 O loop de treino — **orçamento de TEMPO, não de passos**

- Roda `while True` até **`total_training_time >= TIME_BUDGET` (300s)**, ignorando os 10 primeiros passos (compilação).
- **Todos os schedules são função de `progress = tempo/orçamento`**: LR warmup/warmdown, ramp do momentum do Muon, decay do weight‑decay.
- **Gradient accumulation** (`TOTAL_BATCH_SIZE / (device_batch·seq_len)`).
- **Fast‑fail**: aborta em NaN ou `loss > 100`.
- **GC freeze/disable** (o GC do Python causa stalls de ~500ms) + cálculo de **MFU**.

Os botões que o agente mexe: `DEPTH`, `ASPECT_RATIO`, `WINDOW_PATTERN`, LRs, `WEIGHT_DECAY`, betas, `TOTAL_BATCH_SIZE`, `DEVICE_BATCH_SIZE`, warmup/warmdown — e **a arquitetura inteira** se quiser.

---

## 4. `prepare.py` dissecado — o harness imutável (ground truth)

- **Dados**: `karpathy/climbmix-400b-shuffle` (6.543 shards parquet); **val shard fixado** (`06542`) — separado do treino, nunca contamina.
- **Tokenizer**: treina BPE com `rustbpe` → exporta `tiktoken.Encoding`. **vocab 8192**, split pattern estilo GPT‑4 (com `\p{N}{1,2}`). Constrói um lookup `token_bytes` (quantos bytes UTF‑8 cada token representa) — necessário pra métrica.
- **Dataloader**: **BOS‑aligned + best‑fit packing**. Cada linha começa com BOS; empacota documentos achando o **maior que cabe** no espaço restante; se nenhum cabe, **corta o menor** pra preencher exato → **100% de utilização, zero padding**. Pinned memory + cópia GPU non‑blocking + prefetch.
- **A MÉTRICA — `evaluate_bpb` (NÃO MUDÁVEL)**: **bits‑por‑byte**. Soma cross‑entropy por‑token (em nats) só nos tokens com byte‑length > 0 (exclui especiais), soma os bytes, converte nats/byte → bits/byte (`/ log(2)`). Avalia em ~21M tokens (`40·524288`) do val shard fixo.

**Por que BPB e não loss/perplexity?** Porque é **independente do vocab/tokenizer**. Se o agente trocar o tokenizer, mudar vocab_size, ou a arquitetura, o BPB ainda compara *justo* — é "quão bem você comprime os mesmos bytes de texto". **Essa escolha é o que torna a busca arquitetural honesta.**

---

## 5. As decisões de design (onde mora a genialidade)

1. **Um arquivo editável.** Escopo gerenciável, diffs revisáveis. O agente não se perde.
2. **Orçamento de tempo fixo (5 min).** Dois ganhos enormes: (a) todo experimento é **diretamente comparável** independente do que mudou (tamanho, batch, arquitetura — todos competem no mesmo tempo); (b) **auto‑otimiza pro SEU hardware** (acha o melhor modelo treinável em 5 min na sua GPU). Custo: seus números não comparam com os de outras pessoas.
3. **Uma métrica barata, objetiva e vocab‑independente.** Sem isso, o loop não fecha.
4. **Keep/discard via `git reset`.** Antifragilidade trivial: downside limitado, upside composto. O ledger (`results.tsv`) é a memória.
5. **"NEVER STOP".** A autonomia só funciona se o agente não fica pedindo permissão.
6. **Critério de simplicidade explícito.** Impede o agente de empilhar hacks por ganhos marginais — combate o "code slop" que a IA naturalmente produz.

---

## 6. A filosofia (a frase que importa)

> *"You're not touching any of the Python files like you normally would as a researcher. Instead, you are programming the `program.md` Markdown files that provide context to the AI agents and set up your autonomous research org."*

Traduzindo: **o trabalho do humano subiu uma camada.** Você não escreve a solução — você escreve **a org que descobre a solução**. O `program.md` default é "bare bones de propósito"; o jogo real é **iterar o `program.md`** ("research org code") até achar a configuração que produz progresso de pesquisa mais rápido — adicionar mais agentes, dividir trabalho, etc. É meta‑pesquisa: pesquisar *como pesquisar*.

---

## 7. Lente Atlas — o que isso confirma, e o que dá pra roubar

### 7.1 Convergência: Karpathy chegou na tese do Atlas, sozinho

| autoresearch | Atlas (já existente) |
|---|---|
| "Você programa o `program.md`, não o Python" | "Atlas é o cérebro, providers são músculo; você programa a memória do Atlas" |
| `program.md` = research org code | Memory Core + Context Pack + skills |
| Loop autônomo edit→eval→keep/discard | AAEOS / o loop |
| **"NEVER STOP"** | teu `/goal` "não para enquanto não arrumar 100%" (Stop hook) |
| `results.tsv` (ledger append‑only) | Evidence Ledger + Decision Receipt v2 |
| keep/discard via `git reset` | "nunca faz merge na main / certify‑for‑review" |
| Critério de simplicidade | a disciplina loopany que você adotou |

**Isso é validação forte:** as primitivas que você vem construindo (loop governado, evidence, NEVER‑STOP, simplicidade) são as mesmas que o Karpathy destilou pro mínimo que funciona. Você não está inventando — está construindo a versão **empresarial e multi‑domínio** do que ele provou no mínimo.

### 7.2 Onde o Atlas já é MAIOR

- autoresearch é **um domínio só** (pré‑treino de LLM, single‑GPU) e **um agente**. Atlas é multi‑domínio, multi‑provider, com meta‑provider + Atlas Decide + self‑construction + memória governada.
- autoresearch usa "seu Claude/Codex com permissões desligadas". Atlas **governa** o provider (o que você acabou de provar com o Hermes em Dev+Forge).

### 7.3 Onde o autoresearch é uma LIÇÃO direta pro Atlas (o ouro)

1. **A métrica é a keystone, não o loop.** O loop do autoresearch fecha porque existe `val_bpb`: **barato (5 min), objetivo, vocab‑independente.** O loop do Atlas (memória: "Tier‑0 scan‑only, não executa") trava porque **falta o equivalente do `val_bpb` por‑tarefa** — uma métrica de verdade, barata, que diga "melhorou ou não". *Antes de mais loop, defina a métrica.*
2. **Orçamento fixo = harness de comparação justa.** Atlas podia ter um "5‑min harness" genérico: qualquer otimização que o loop tente roda num orçamento fixo contra uma métrica fixa → experimentos auto‑comparáveis. Isso é construível como primitivo do AAEOS.
3. **Escopo RUTHLESS.** Um arquivo, uma métrica, um ciclo. O AAEOS espalha. A lição: pra o loop **compor de verdade**, cada tarefa precisa da clareza do autoresearch — uma superfície editável única + uma métrica‑verdade barata + um gate keep/discard rápido. O autoresearch é o **loop executável mínimo que de fato roda** — exatamente o que falta no teu (que "escaneia mas não executa").
4. **`results.tsv` > Evidence Ledger pesado, pra o loop interno.** Pra o ciclo de experimentação, um ledger TSV de 5 colunas basta e é legível pelo próprio agente. O Evidence Ledger pesado do Atlas é certo pra auditoria, mas o **loop quente** quer algo barato como o tsv.
5. **O `program.md` iterável = teu maior alavanca.** O insight "itere o program.md, não o código" é literalmente "itere a skill/memória do Atlas". Vale ter, no Atlas, um artefato de primeira‑classe = "o program.md da org" que você versiona e melhora — separado do código que ele governa.

---

## 8. Veredito honesto (sem hype)

- **O que é:** um **seed/demo brilhante e deliberadamente mínimo** — e ao mesmo tempo um substrato **real e rodável** de pesquisa autônoma de pré‑treino. A moldura sci‑fi ("10.205ª geração", "binário auto‑modificável além da compreensão humana") é **aspiracional, não uma afirmação de capacidade atual**.
- **A genialidade não é o código de ML** (embora seja SOTA‑compacto) — **é o recorte**: a métrica certa + o orçamento fixo + a disciplina keep/discard + o NEVER‑STOP + "programe a org, não a solução". Tudo o resto é consequência.
- **O que NÃO é:** não é um produto, não é multi‑domínio, não governa providers, não tem memória composta. É o **átomo** do que o Atlas quer ser a molécula.
- **Pro Atlas:** trate isso como **prova de conceito da tua própria tese** + um **checklist de disciplina**. A pergunta que ele força: *"qual é o `val_bpb` de cada tarefa que o loop do Atlas tenta?"* — se você não consegue responder pra uma tarefa, o loop não vai compor nela. Resolva a métrica primeiro.

---

*Arquivos lidos integralmente: `README.md`, `program.md`, `train.py` (630 ln), `prepare.py` (389 ln), `pyproject.toml`, `analysis.ipynb`. Clone em `/tmp/autoresearch-dissect` (commit `228791f`).*
