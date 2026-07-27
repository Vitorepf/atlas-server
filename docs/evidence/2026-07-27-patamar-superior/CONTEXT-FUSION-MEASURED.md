# Fusão de contexto do ACOS — medida, não opinada

Data: 2026-07-27 · probe: `goldens/context-runtime-mode-delta.php`

## O que se queria saber

O operador pediu um "bloco supremo de contexto" fundindo os vários blocos do
ACOS. Antes de escrever qualquer fusão nova: ela já existe? Está ligada? Vale?

## Topologia real

Há dois compositores, e eles **já estão fundidos em um sentido**:

| bloco | quem usa | papel |
|---|---|---|
| `AtlasContextRuntime::compose()` | AtlasCliDevCommand, AiPromptBuilder, AiWorker, PipelineRunExecutor | composição ligada ao provider |
| `AtlasOpenBrainContextPackService::packFor()` | MCP `atlas_context_pack`, hooks, `atlas:context-pack` | pack voltado ao agente/operador |

`compose()` chama `packFor()` internamente como `$fused` — o AOBG **é** o núcleo
de retrieval do runtime. O sentido inverso (`atlas.aobg.include_runtime_compose`)
fica `false` de propósito, para o AOBG seguir byte-idêntico; o próprio runtime
passa `include_runtime_compose => false` ao descer, para não recursar.

## Estado real (não os defaults do config)

`config/atlas.php` nasce `enabled=false, mode=offline`. O `.env` do operador diz
o contrário — e é o `.env` que vale:

```
ATLAS_CONTEXT_RUNTIME_UNIFIED_RETRIEVAL_ENABLED=true
ATLAS_CONTEXT_RUNTIME_UNIFIED_RETRIEVAL_MODE=default
```

Portanto o caminho `unified` está **vivo hoje**. Ler só o config leva à conclusão
oposta — foi o erro que este documento corrige.

## O delta, medido

Mesma task, processo novo por modo, storage redirecionado para tmp (o storage
vivo não foi tocado — verificado antes e depois). Injeção ligada de verdade via
sinal `programming.dev`; sem esse sinal a injeção sai `policy_off` e os três
modos parecem idênticos — foi assim que a primeira medição se enganou.

| modo | latência | context_refs | prompt_section |
|---|---|---|---|
| legacy | 534 ms | 23 | 15.142 |
| shadow | 2.259 ms | 23 | 15.152 |
| unified | 2.344 ms | **45** | **20.039** |

- `shadow` é idêntico a `legacy` a menos do relógio (só `manifest.created_at`,
  `manifest.expires_at` e o `age_days` derivado). Seguro por construção.
- `unified` quase dobra os refs e paga ~4,4× de latência.
- `summary.retrieval_core` sai `{"mode":"legacy_parallel"}` em legacy e
  `{"mode":"precomputed_aobg", ...}` em unified.

## O que continua sem instrumento

Nada no repo decide se 45 refs são **melhores** que 23. O
`atlas:context:evaluate-retrieval` (AREBA) não toca `compose()`: avalia o
freshness gate contra 3 casos sintéticos hardcoded. Mais contexto não é
melhor contexto, e essa pergunta segue aberta — é medição de qualidade, e
escrever a fórmula do próprio placar do Atlas é decisão do operador.

`atlas:intelligence:rollout-promote unified_retrieval --to=...` é o caminho
governado para mexer no modo; os gates `context_quality` e `slo` passam hoje.
