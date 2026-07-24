# Atlas Rivals — Open Gaps (v1)

## PÉTRO — Curriculum ladder / anti-ceiling fallacy

**Canon:** `docs/engineering-knowledge-base/atlas-rivals-curriculum-ladder-and-anti-ceiling-fallacy.md`

- **80–100% num teste = aprovação de NÍVEL escolar**, nunca teto do modelo, do Atlas, nem “inteligência suprema”.
- Suite saturada → **S_sanity** (regressão/paridade). Multiplicador e claim forte → só em **S_frontier** não saturado.
- Raw ou Atlas dominou o frontier → **promover L_{k+1}** (próximo livro). **Proibido** dizer “50× / N×M impossível para sempre porque já faz 80%”.
- **2026 ≠ 2030 ≠ 2040 ≠ 2050**; o currículo **sobe**. Atlas 80% em teste medíocre = **inútil como meta final** — elevar o nível.
- Essência Rivals: medir com/sem Atlas e **sempre melhorar o Atlas no frontier**, não congelar tabuada.



> Status vivo pós-obra 13/07/2026 (revisão + correção da máquina de 10 benchmarks).
> Fonte: sessão de revisão do operador; commits `4e1f44dcfd` (rivals) e `dd4dfafa0f` (kernel).

## Resolvido em 13/07 (não reabrir sem evidência nova)

- Arm não-bare em suíte sem runtime Atlas agora **falha fast no plan** (pré-spend);
  antes rodava bare disfarçado e o RuntimeProofAttacher abortava a suíte inteira
  como `environment_failure` mesmo com reward 1.0 (tau2).
- Case pack terminal_bench apontava para tasks inexistentes no dataset
  `terminal-bench-core==0.1.1` (`git-bisect`, `kernel-config`) → `git-multibranch`.
- Provider-lock estrito restaurado no bridge (caminho atlas:cli:dev).
- Ledger semântico re-apontado após quarentena de epoch (13/07 16:53); chain verified.
- Desde 19/07, os 10 adapters têm rota Atlas distinta. O report mantém cinco
  `uplift_families` como recorte analítico e lista as outras cinco em
  `additional_dual_arm_suites`; `bare_only_suites` fica vazio.
- Modo oficial `battery --mode/kind=atlas` executa atlas_dev nas 10 suítes.
- Kernel Atlas Dev mutativo destravado em 8 camadas (venv semantic_rag, 57 tabelas
  wiped re-migradas, prompt real com goal+arquivos+contrato, parser tolerante a
  stream de raciocínio, preimage do sandbox, lint poliglota, behavioral opcional,
  allowed_files a partir de arquivos citados no pedido).

## Endurecimento de confiança do relatório (14/07 — não reabrir)

Objetivo do operador: benchmark de capacidade **mais completo e confiável do mundo,
sem ambiguidade nem perigo de sugerir precisão falsa**. Entregue nesta sessão:

- **Faixa de confiança 95% (Wilson) por capacidade** — card mostra `45% · faixa
  provável 31–60%`. Um % cru sobre amostra pequena escondia a margem. Reusa
  `StatisticalPolicy::wilson`.
- **Headline "fato medido" magnitude-aware** — antes dizia "Atlas melhorou mais
  vezes (2↑/1↓)" sob rótulo "confirmado" enquanto o saldo médio era NEGATIVO.
  Agora contagem e magnitude precisam concordar; divergência → "dividido" + saldo
  médio em pp (o árbitro do sinal). Mesma lógica da capa.
- **Par diagnóstico separado do fato confirmado** — 3/5 pares eram `diagnostic_only`
  (2× ambos-0%, Aider excluído) e entravam na contagem/saldo/veredito (Aider +14.3
  contava como vitória silenciosa). Agora balde "só diagnóstico — não conta como
  fato"; veredito usa só pares confirmados → 1↑/1↓, saldo -27.8pp. Capa, stats e
  painel de fatos concordam.
- **Cor de veredito só em delta confirmado** — delta diagnóstico no card de
  capacidade (ex.: Programação +5.1pp, 100% diagnóstico) não pode ser verde de
  ganho; fica neutro + "não é ganho confirmado".
- **Delta de amostra pequena (<20 tarefas pareadas)** rotulado "tendência, não
  conclusão".

### RESOLVIDO 14/07: Raciocínio nunca falhou — provider errado (root cause + prova ao vivo)

`is_latest_model()` do inspect_ai (`inspect_ai/model/_openai.py`) trata **qualquer
nome não-OpenAI** como codename de fronteira da OpenAI ("treat any such
unrecognized name as the latest model") → `is_gpt_5()`=true → `system_role` vira
`developer` → Verboo devolve **400 unsupported_message_role** → "Task interrupted
(no samples completed)" → 9/9 environment_failure. **O modelo nunca foi chamado.**

Prova ao vivo (14/07): `openai-api/verboo/kimi-k2.7` → **9/9 accuracy 1.000** nos
3 casos gsm8k × 3 reps. O relatório dizia "Raciocínio 0%" — o **oposto exato** da
verdade (100%). Nenhum humano detectaria isso lendo o relatório.

Fix (commit `bdef912e5c`): `native_models.inspect_evals` = `openai-api/verboo/
kimi-k2.7` (provider p/ endpoint compatível de terceiros, lê `VERBOO_API_KEY` que
o `VerbooEnvironment` já exporta); removida a gambiarra `-M responses_api=false`;
novo task_type `math_reasoning` (gsm8k era `coding_patch`/`tool_use_function_
calling` — punha matemática dentro de Programação); teste trava o provider-lock.

**Pendente**: a medição só entra no relatório num run de bateria (`--mode=bare`);
o CLI `plan` usa `allowSynthetic=false` e recusa suítes sem repo snapshot — é a
bateria que passa `allowSynthetic: true`. Não fabricar receipt fora do harness.

### RESOLVIDO 14/07: mmlu "0%" era cap de 16 tokens, não incapacidade (real 80%)

Segunda regressão da mesma família. Tasks não-CoT do inspect capam a saída em **16
tokens** ("basta para 'ANSWER: B'"). O kimi-k2.7 emite bloco de raciocínio nativo →
o raciocínio consome os 16 → `stop_reason=max_tokens`, `text=""` → scorer lê 0%.
O inspect **já trata**: `get_max_tokens()` retorna None "for reasoning models to
avoid truncating thinking tokens" — mas só se o config declarar, e nunca
declarávamos. Fix `c77a5b135c`: `--reasoning-tokens 2048` no template (gsm8k segue
1.000, sem regressão). **Sem a flag mede-se o cap do harness, não o modelo.**

### 14/07: taxonomia 4 caixas → 6 domínios / 12 habilidades

Crítica do operador: *"medir IA só com programação, uso de ferramenta e trabalho a
longo prazo é de uma pobreza horrenda"*. Os instrumentos já existiam no
`inspect_evals` (biblioteca com dezenas de evals); faltava provider funcionando +
fatia por task_type. Entregue: `blame_by_task_type` na evidência de execução,
`CAPABILITIES[...]['task_types']`, `SUB_CAPABILITIES['suite:task_type']`, e
**Conhecimento (MMLU) + Ciência (GPQA Diamond)** wirados — provados ao vivo (80% e
1.000) antes de wirar. Cobertura declarada: **7/14 domínios**.

Próximos domínios reachable pelo mesmo harness (ordem de esforço): `ifeval` (seguir
instruções — precisa `uv sync --group ifeval`), `simpleqa` (factualidade), `mgsm`
(multilíngue), `mathvista` (multimodal), `musr`/`bbh` (raciocínio multi-step).

**Lição transferível**: apontar um provider nativo (`openai/`) para endpoint
compatível de terceiros ativa heurísticas de modelo do vendor. Suspeitar sempre
que uma suíte inteira zerar com env-failure e wall_ms ~1s (modelo nem chamado).

### Investigação Raciocínio (inspect_evals) — histórico da caçada

`Raciocínio` mostra `—` / `não confiável` / n=0 (honesto — não afirma que o modelo
falha em raciocinar). Fixtures atuais (run 20260709) têm `score=null` sem string de
erro → o safety-net do `InspectEvalsAdapter` mapeia para `invalid_result`, fora de
qualquer claim de "modelo errou". A role `developer` (erro histórico HTTP 400 no
Verboo/kimi) NÃO vem da detecção de modelo do inspect: `is_o_series_model` /
`is_gpt_5_model` (`inspect_ai/model/_openai.py`) não casam com `openai/kimi-k2.7`.
Completar a medição de Raciocínio exige run inspect ao vivo contra Verboo (spend +
venv + reprodução do erro real) — bloqueado na bateria atual liberar recursos.

### 14/07 — ⚠️ ACHADO DE INFRA (vale além do Rivals): router Verboo quebra em `tool_choice` FORÇADO

Medido direto contra `https://code.verboo.ai/router/v1` com `kimi-k2.7`:

| `tool_choice` | resultado |
|---|---|
| `auto` | ✅ `tool_calls` presente, `finish_reason=tool_calls` |
| ausente | ✅ `tool_calls` presente |
| **forçado** `{"type":"function","function":{"name":"submit"}}` | ❌ **`tool_calls: null`, `content: ""`, `finish_reason=stop`** |

O modelo **suporta** function calling (BFCL bare = 100%). O router é que devolve
**resposta vazia sem erro** quando o tool_choice é forçado. Falha silenciosa: quem
chama interpreta como "o modelo não chamou a ferramenta".

Impacto imediato: bloqueia `simpleqa` (o grader do inspect força
`tool_choice=submit` → "Grader model did not submit a tool call" → task abortada) →
domínio **factualidade fica sem instrumento**, declarado no COVERAGE_MAP.

**Impacto potencial fora do Rivals**: qualquer fluxo do Atlas que use forced
tool_choice contra o router Verboo recebe vazio e pode culpar o modelo. Vale auditar
os call-sites. NÃO corrigir editando o scorer vendorizado do inspect (mudaria o
protocolo de avaliação do benchmark); o caminho é grader alternativo ou o router
suportar forced tool_choice.

### 14/07 — 🔴 Bomba latente: score GRADUADO virava "falha do modelo" (fail-closed)

Achado ao provar o `niah` (contexto longo, FUNCIONA: achou a agulha em 10k tokens).
O niah devolve `"value": "10"` numa escala **1-10** (10 = perfeito). O mapeamento do
`InspectEvalsAdapter` é binário (`C/I`, `0/1`) → `"10"` caía no `default =>
'invalid_result'` → e `blame_summary` soma `invalid_result` em **model_failures**.
Ou seja: **acerto perfeito publicado como o modelo falhando.**

Mesma família dos outros bugs do dia. Pegaria QUALQUER eval graduado wirado depois.
Fix `739b18c7d6`: recusa alto na ingestão (`inspect_evals_unhandled_score_scale`).
**Wirar eval graduado exige decidir o limiar explicitamente** — não deixar o default
inverter o resultado.

### Domínios provados mas NÃO wirados (precisam de trabalho, não são "impossíveis")

- **Contexto longo (`niah`)**: PROVADO rodando (haystack 9.906 tokens, acerto). Falta
  decidir a conversão da escala 1-10 → taxa 0-1 e então wirar.
- **Multimodal (`mathvista`/`mmmu`/`vstar_bench`/`zerobench`)**: não testados; o
  `kimi-k2.7-code` provavelmente não aceita imagem.
- **Segurança / escrita longa**: sem instrumento no inspect_evals.

### 14/07 — Inventário real: 138 instrumentos, 15 ligados (catálogo completo)

Não são "10 benchmarks": são **9 suítes dedicadas + `inspect_evals`, que é uma
BIBLIOTECA com 129 evals** (12 grupos oficiais). Total 138; **15 ligados**, 123
dormentes. Extraído de `*/eval.yaml` + `config/atlas_rivals.php`.

Grupos e quanto usamos: Conhecimento 2/22 · Programação 6 suítes + 0/21 evals ·
**Segurança 0/21** · Raciocínio 2/20 · **Cibersegurança 0/13** · Assistentes 2/10 ·
Matemática 2/7 · **Dissimulação/risco 0/6** · **Multimodal 0/5** · Viés 0/2 ·
**Escrita 0/1** · Personalidade 0/1.

Dormentes de alto valor nunca rodados: `aime2024/25/26`, `hle` (Humanity's Last
Exam), `math`, `mmlu_pro`, `bbh`, `musr`, `swe_lancer`, `mle_bench`, `cybench`,
`agentharm`, `agentic_misalignment`, `gdm_self_proliferation`.

### 14/07 — PESQUISA bloqueada por DOIS motivos (medido, não suposto)

Domínio "pesquisa/assistente web" tem instrumento, mas nenhum roda honesto hoje:

1. **`gaia`** ("A Benchmark for General AI Assistants"): dataset **GATED no
   HuggingFace** — `GatedRepoError: 401 Cannot access gated repo`. Exige o operador
   aceitar os termos na conta HF + `HF_TOKEN`. Os cases `gaia_l1_004`/`gaia_l1_011`
   já estão importados em disco mas ficaram fora do case pack.
2. **`browse_comp`**: RODA, mas o default é `with_browsing=False` → usa
   `basic_solver()` **sem nenhuma ferramenta**. Confirmado no log: **0 mensagens de
   tool**, o modelo respondeu de memória e tirou 0%. **Esse 0% mede "adivinhar fato
   obscuro de cabeça", NÃO pesquisa** — wirar assim seria mais um falso
   "0% = incapaz". Medir pesquisa de verdade exige `-T with_browsing=true`, que
   precisa de **chave de API de busca** + **sandbox Docker**.

`assistant_bench`: nome de task não resolveu (`No inspect tasks were found`).

### 14/07 — ⚠️ Multimodal: a imagem é DESCARTADA EM SILÊNCIO (não wirar)

Testado direto contra o router com PNG em `image_url` (data URI):

- A API **aceita** a mensagem multimodal — **nenhum erro 400**.
- O modelo responde: **"I don't see any image attached to your message."**

Ou seja: o router não recusa, **descarta**. Wirar `mmmu`/`docvqa`/`vqa_rad` faria o
modelo responder toda pergunta visual às cegas → ~0% → o relatório publicaria
**"Multimodal: 0%, o modelo não enxerga"** quando a verdade é que a imagem nunca
chegou. **Sem nenhum erro no log para denunciar** — o pior modo de falha da família.

Multimodal está marcado `NÃO MENSURÁVEL com este modelo` no COVERAGE_MAP, não
apenas "não wired". Exige modelo com visão.

### 14/07 — EIXO DE RISCO (`RISK_AXIS`): 25% do arsenal tem sinal invertido

34 dos 138 instrumentos medem RISCO (acertar mais = PIOR): `wmdp` (conhecimento
perigoso), `agentharm`, `cyberseceval*`, `gdm_*` (dissimulação), `strong_reject`…
Não cabiam em `CAPABILITIES` (média/Wilson/veredito assumem maior=melhor) nem
podiam ser ignorados (25% do arsenal).

Solução: eixo próprio com `higher_is_worse` declarado por item, painel vermelho
separado, "↓ menor é melhor" na tela, e o número **nunca** entra em média ou
veredito. 2 testes travam a fronteira. `wmdp` wirado aqui.

### 14/07 — 🔑 CHAVE que destrava o domínio Segurança (medido, falta wirar)

O bloco de 21 instrumentos de segurança parecia inalcançável. Não é. Achado:

**Juízes internos apontam para modelo OpenAI que o Verboo não tem.** `coconot`
declara `grader: ... = "openai/gpt-3.5-turbo"` como DEFAULT DO PARÂMETRO DA TASK →
router devolve `404 model not available`. `--model-role grader=` **NÃO** sobrepõe
(é param de task, não role). O que funciona, medido:

```
-T grader=openai-api/verboo/kimi-k2.7
+ env: VERBOO_API_KEY, VERBOO_BASE_URL   ← o provider openai-api deriva a URL de
                                            <SERVICE>_BASE_URL para papéis;
                                            --model-base-url só vale p/ o principal
→ coconot RODA. Resultado: "Requests with safety concerns = 0.0",
  score por amostra = "ACCEPTABLE" (o modelo recusou corretamente).
```

**Dois obstáculos reais antes de wirar (não são bloqueio externo):**
1. `commandTemplate()` do `InspectEvalsAdapter` é **compartilhado por todas** as
   tasks. Passar `-T grader=` global quebraria tasks sem esse parâmetro. Exige
   **comando/params por task** no adapter — refactor pequeno, mas é refactor.
2. Score é `ACCEPTABLE`/`UNACCEPTABLE`, não `C`/`I` → o fail-closed
   (`inspect_evals_unhandled_score_scale`) recusa, corretamente. Exige mapear
   explicitamente (ACCEPTABLE = recusou certo = sucesso).
3. Atenção ao SINAL: a métrica agregada do coconot é do tipo "requests with safety
   concerns" (**menor = melhor**), mas o score POR AMOSTRA é ACCEPTABLE=bom
   (maior = melhor). Decidir qual entra, e declarar.

Outros do bloco: `abstention_bench` precisa da dep `hydra` (não instalada);
`xstest` tem dataset morto (404).

### 14/07 — 4 mecanismos de score no InspectEvalsAdapter (e o 4º NÃO foi feito)

Cada eval do inspect devolve score de um jeito. O adapter trata 3, e recusa o resto:
1. **binário** — `C`/`I`, `0`/`1`, bool. Direto.
2. **graduado** (`GRADED_SCALES`) — escala 1-10 (niah). Exige LIMIAR declarado.
3. **rótulo** (`LABEL_SCORES`) — ACCEPTABLE/UNACCEPTABLE (coconot). Exige mapa.
4. **composto** — ❌ NÃO implementado de propósito.

O 4º: `sycophancy` devolve `value` como **dict** de 5 sub-scores
(`original_answer`, `admits_mistake`, `confidence`, `apologize_rate`,
`truthfulness`). Wirar exigiria escolher qual sub-score vale (`truthfulness`
= manteve a resposta certa sob pressão, maior=melhor) — mais um mecanismo, mais
um lugar para errar o sinal. **Ganho seria +1 habilidade num domínio JÁ coberto**
(Factualidade, via truthfulqa), não destrava domínio. Fica registrado; o
fail-closed recusa até alguém decidir.

Regra: mecanismo novo de score só se destravar DOMÍNIO, não para adicionar
profundidade onde já se mede.

Outros medidos: `personality` — task não resolve (`No inspect tasks were found`).

## Aberto

1. **Corte 22-role mutativa em workspace estrangeiro** —
   `kernel_mutative_role_receipt_binding_invalid` em
   `KernelEvidenceAuthority::issueMutativeRoleDisposition`. O pipeline mutativo do
   `atlas:cli:dev --efficient` chega verificado até
   `behaviorally_verified_pending_quality_court` e a corte recusa o binding de
   receipt de papel fora do repo Atlas. Enquanto isso, o braço `atlas_dev` do
   Rivals para modelos hermes usa o `hermes -z` one-shot do bridge
   (`execution: hermes_cli_oneshot`, disclosado no receipt) — que mede
   "modelo + prompt Atlas", não o runtime Atlas completo. Fechar a corte para
   workspaces estrangeiros é o que torna o uplift hermes um fato de runtime.
2. **Closure claim-grade da Fase A** — `native_run_10_of_10` e
   `uplift_families_5_of_5` exigem baterias com `internal_claim_allowed=true`
   (3 reps, zero env-failure, bundle verificado). Runs de 12/07 são medição real
   mas não claim-grade. É execução (horas de bateria), não código.
3. **Tokens**: `inspect_evals` não emite usage (harness omite; reportar N/A
   honesto); `live_code_bench`/`swe_marathon` corrigidos pendentes de re-run.
4. **Segundo modelo provado ponta-a-ponta** — "qualquer modelo" exige provar um
   segundo modelo (bare + atlas_dev) além de `verboo_kimi_k2_7`; model-matrix
   segue fail-closed até lá.
5. **Suite `tests/Unit/Ai/Programming/AtlasDev` tem 111 falhas pré-existentes na
   main** (artefatos de boot de facade/container + expectativas divergentes) —
   anterior a esta obra (baseline HEAD limpo = 111; working tree = 110).
