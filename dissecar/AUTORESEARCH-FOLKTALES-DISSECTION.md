# Dissecação completa — `thu-vu92/autoresearch_folktales`

> Fonte: clone real (`a626586`, 2026‑04‑24). 1.602 linhas. Dissecado por **diff** contra o base do Karpathy (já dissecado em `AUTORESEARCH-KARPATHY-DISSECTION.md`), focando no que mudou + nos **resultados reais commitados**.

---

## 0. TL;DR

É o **autoresearch do Karpathy portado pra MacBook Apple Silicon**, treinando num dataset minúsculo de **contos folclóricos** em vez do climbmix de 400B. Mesma alma (loop autônomo, métrica `val_bpb`, `program.md`, orçamento de 5 min), mas **roda no MPS (Metal) do Mac sem GPU NVIDIA**. Por Thu Vu, construído sobre o port macOS do `miolini/autoresearch-macos` + dataset de folclore.

**Por que importa pra você:** é a versão **local‑first, rodável na sua máquina exata** (M1‑M4). É um substrato concreto e funcional de um loop de pesquisa autônoma **no teu hardware** — exatamente a tese do Atlas (local, soberano, no MacBook).

---

## 1. O que foi PRESERVADO (o esqueleto é idêntico)

- As **5 peças** (prepare.py / train.py / program.md / results.tsv / analysis.ipynb).
- O **loop** (hack→commit→treina 5 min→grep val_bpb→keep/discard via git).
- A **métrica** `val_bpb` (bits‑per‑byte, vocab‑independente) — `evaluate_bpb` intacto.
- O **`program.md`** — byte‑idêntico (114 ln). A "research org code" não mudou: o port é só de *substrato*, não de *org*.
- O modelo: mesmo GPT moderno (RMSNorm, RoPE, QK‑norm, value embeddings, MLP ReLU², softcap, residual lambdas) + otimizador MuonAdamW.

**Lição #1:** o `program.md` ser portável sem mudança prova que a camada "como pesquisar" é **independente do hardware**. Você troca o motor (CUDA→MPS) sem tocar no cérebro. É a separação cérebro/músculo do Atlas, validada por construção.

---

## 2. O que MUDOU — a adaptação Apple Silicon (a parte tecnicamente interessante)

### 2.1 Atenção: FlashAttention‑3 → SDPA nativo + máscara manual

O base usava o kernel fundido **FA3** (`fa3.flash_attn_func`, Hopper‑only) com `window_size` nativo. No Mac não existe FA3, então trocaram por **`F.scaled_dot_product_attention`** (SDPA do PyTorch) + **máscara de sliding‑window construída à mão**:

```python
# folktales train.py (CausalSelfAttention.forward)
window = window_size[0]
if window > 0 and window < T:
    mask = torch.ones(T, T, dtype=torch.bool, device=q.device).tril()   # causal
    mask = mask.triu(diagonal=1 - window)                                # + janela
    y = F.scaled_dot_product_attention(q, k, v, attn_mask=mask)
else:
    y = F.scaled_dot_product_attention(q, k, v, is_causal=True)          # janela cheia
```

Custo: a máscara `T×T` booleana é **O(T²) memória** (FA3 nunca materializa isso) — por isso o Mac fica limitado a seq/batch menores.

### 2.2 GQA explícito

FA3 fazia grouped‑query attention internamente. O SDPA aqui não, então **expandem k e v manualmente**: `k = k.repeat_interleave(n_head // n_kv_head, dim=2)` (e v idem). Reconstrói GQA "na unha".

### 2.3 Guarda de MPS + `torch.compile` desligado

- Verifica `torch.backends.mps.is_available()` e **falha cedo** se não for Apple Silicon.
- **`torch.compile` desabilitado** nos caminhos que o MPS não suporta (o base compilava tudo com `@torch.compile(fullgraph=True)`). Isso sozinho custa muita performance.

### 2.4 Otimizador: cast preciso de device/dtype pro Metal

O base mantinha os escalares (`lr_t`, `beta1_t`, ...) em **tensores 0‑D na CPU** (truque pra evitar recompilação do compile). No Mac, os steps fundidos agora fazem `.to(device=p.device, dtype=p.dtype)` em cada escalar — "optimizer states precisely cast for Metal compatibility". Sem o compile, o truque CPU‑tensor perde sentido e vira incompatibilidade — então casteiam pro device.

### 2.5 Dependências + dados

| | base (Karpathy) | folktales (Mac) |
|---|---|---|
| torch | `2.9.1` + índice CUDA `cu128` | **`2.6.0`** (sem índice CUDA) |
| `kernels` (FA3) | sim | **removido** |
| dataset | `karpathy/climbmix-400b-shuffle` (6.543 shards parquet) | **`merve/folk-mythology-tales`** (1 arquivo de texto) |
| download | multi‑shard paralelo | single‑file |

O dataset de folclore é **low‑entropy de propósito** — é literalmente a recomendação #1 do próprio Karpathy pra rodar em compute pequeno ("use um dataset com muito menos entropia"). Texto narrativo estreito → modelo pequeno aprende padrão com poucos passos.

### 2.6 Modelo encolhido pro Mac

Os defaults do `GPTConfig` ainda dizem 12 camadas / 768 dim, mas o **run real** (results.tsv) usou **4 camadas, 256 dim, 2 heads** — ~50× menor que o base. Necessário pro MPS.

---

## 3. Os RESULTADOS REAIS (o `results.tsv` commitado — ouro raro)

Diferente do base (que deixa `results.tsv` untracked), aqui o **log de uma sessão autônoma real está commitado**. 11 experimentos, **6 keep / 5 discard**:

| # | val_bpb | status | o que tentou |
|---|---|---|---|
| 1 | 5.80 | keep | baseline (4 layers, 256 dim) |
| 2 | — | discard | 5 layers (lento demais: 500 vs 2000 tok/s) |
| 3 | 5.40 | keep | embedding_lr 0.6→0.8 (‑7%) |
| 4 | 5.60 | discard | +matrix_lr 0.05 (pior) |
| 5 | 5.00 | keep | +warmup 0.1 (**‑16.8%**) |
| 6 | 5.50 | discard | warmup 0.15 (pior) |
| 7 | 4.90 | keep | embedding_lr 0.9 (**RECORDE**) |
| 8 | 4.85 | keep | embedding_lr 1.0 (**RECORDE**) |
| 9 | 5.40 | discard | +warmdown 0.4 (pior) |
| 10 | 4.80 | keep | embedding_lr 1.1 (**RECORDE, ‑19%**) |
| 11 | 5.70 | discard | betas (0.9,0.95) (pior) |

**O que o agente descobriu sozinho:** pra esse modelinho de folclore, o ganho vem de **cranking o `embedding_lr` pra cima** (0.6→1.1, quase 2×) + **um pouco de warmup (0.1)**. Tudo o mais que tentou (mais camadas, matrix_lr maior, warmup maior, warmdown, betas) **piorou e foi revertido**. Net: **5.8 → 4.8 val_bpb (~17%) em 11 ciclos.**

Isso é a **prova viva de que o loop funciona**: melhora monotônica, hipóteses testadas e descartadas por evidência, sem humano no meio.

> ⚠️ Os números (5.8, 4.8) são bem mais altos que o ~1.0 do base — porque o modelo é minúsculo e os dados estreitos. **Não compare entre forks** (é exatamente o trade‑off que o Karpathy avisou do orçamento‑fixo). O que importa é o *delta dentro do mesmo fork*.

---

## 4. A pegadinha honesta (que a README admite)

> *"MPS é significativamente mais lento que uma NVIDIA moderna — espere ~85‑90 passos de otimizador em 5 min vs. 1000s numa GPU rápida."*

Ou seja: no Mac, cada experimento de 5 min faz **~10× menos treino**. O loop roda e itera **corretamente**, mas é **compute‑limitado** — as melhorias são reais mas se manifestam devagar. Honestidade rara num README de fork.

---

## 5. Lente Atlas — por que esse fork importa mais que o base, pra você

1. **Roda na TUA máquina.** O base precisa de H100. Este roda no **MacBook Apple Silicon** — teu hardware, teu local‑first. É o substrato concreto pra um loop de pesquisa autônoma *soberano, na tua máquina*, sem nuvem.
2. **Prova a separação cérebro/músculo do Atlas.** O `program.md` (cérebro) foi portado **sem uma linha mudada**; só o motor (CUDA→MPS) mudou. É a tua tese — providers são músculo trocável — demonstrada num caso real.
3. **O `results.tsv` é o teu Evidence Ledger em miniatura, funcionando.** 11 linhas append‑only, keep/discard por evidência, melhora composta. Confirma que o ledger barato + git‑reset é suficiente pra o loop quente (o Evidence Ledger pesado do Atlas é pra auditoria, não pra o ciclo interno).
4. **A realidade compute‑limitada é a TUA realidade.** No Mac, ~85 passos/5min. Se o loop do Atlas rodar local, ele será **igualmente compute‑limitado** — o que muda a estratégia: menos "100 experimentos cegos a noite toda", mais **hipóteses caras e bem escolhidas** (porque cada ciclo custa). Isso reforça por que o *cérebro* (program.md / memória do Atlas) importa mais que a força bruta: você não tem força bruta local.
5. **A métrica continua sendo a keystone.** Mesma lição do base: o loop só fecha porque `val_bpb` existe. O gargalo do loop do Atlas não é o motor (isso aqui prova que roda no Mac) — é **definir o `val_bpb` de cada tarefa**.

---

## 6. Veredito honesto

- **O que é:** um **port competente e honesto** — a contribuição real é de *engenharia de portabilidade* (FA3→SDPA+máscara, GQA manual, compile‑off, cast Metal, dataset trocado), não de pesquisa nova. O loop e o cérebro são do Karpathy.
- **O valor pra você não é o código** (é um fork didático, ligado a um vídeo de YouTube) — **é a existência da prova**: *o loop autônomo do autoresearch RODA num MacBook Apple Silicon, com resultados reais.* Isso transforma "AAEOS local autônomo" de aspiração em **algo demonstrado no teu tipo de máquina**.
- **O que NÃO é:** não é mais capaz que o base (é menos — compute‑limitado), não governa providers, não é multi‑domínio. É o **átomo local** do que o Atlas quer ser.
- **Ação pro Atlas:** se você quiser um dia rodar o loop de auto‑pesquisa do Atlas **na sua máquina**, este fork (ou o `miolini/autoresearch-macos` que ele credita) é o **substrato de referência de MPS** — copie a estratégia de atenção/compile/cast. Mas a pergunta‑mãe não muda: **qual é o `val_bpb` da tarefa?**

---

*Lidos: README, `program.md` (vs base, idêntico), `train.py` (704 ln, via diff), `prepare.py` (420 ln, via diff), `pyproject.toml`, `results.tsv` (log real), `analysis.ipynb`. Clone em `/tmp/folktales-dissect` (`a626586`). Comparado contra `/tmp/autoresearch-dissect` (`228791f`).*
