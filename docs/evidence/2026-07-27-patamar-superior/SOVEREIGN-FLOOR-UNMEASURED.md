# O que o piso soberano ainda decide sem medir

Data: 2026-07-27 · origem: varredura adversarial (6 lentes, 84 agentes, 26 candidatas)

Seis gates do caminho de aterrissagem autônoma não podiam reprovar. Cinco foram
corrigidos nesta sessão. O que sobra está aqui, com o motivo de cada um **não**
ter sido tocado — para a decisão ser sua, com o quadro completo.

## Corrigidos (para referência)

| gate | defeito | commit |
|---|---|---|
| `security_scan` no committer vivo | quatro literais | `0f35bee1d` |
| `securityFromScan` | verde com zero scanners instalados | `cf0e181e8` |
| boot-smoke diferencial | worktree sem `vendor/` → baseline sempre falho | `a980da553` |
| scope guard | `git diff` não lista arquivo novo | `b544879e3` |
| `atlas:pregate` | SUCCESS sobre paths descartados | `b544879e3` |
| verificador de ledger | pulava todo `full_envelope_v2` | `4ba774729` |

## Aberto 1 — os juízes que o próprio chamador fornece

`AtlasTaskScopedCommitter::certifyLanding()` passa:

```php
'judges' => [
    ['name' => 'task-verify-gate',    'provider_family' => 'atlas_harness', 'approved' => true],
    ['name' => 'task-landing-certify','provider_family' => 'atlas_verify',  'approved' => true],
],
```

Nenhum objeto com esses nomes é consultado em lugar algum; as famílias são
strings que só existem nesta linha. `judgeDiversity()` conta famílias distintas
entre juízes aprovadores e exige 2 — os dois literais dão exatamente 2, sempre.

**Por que não mexi:** decidir quem são os juízes reais de uma aterrissagem
autônoma é design de governança. Há um caminho alternativo já previsto no piso
(`non_functional.judge_diversity.deterministic_courts`), mas escolher entre
cortes determinísticas e verdicts de provider é sua chamada, não minha.

## Aberto 2 — três invariantes decididos por constante

No mesmo bundle:

| campo | literal | efeito no piso |
|---|---|---|
| `changed_public_symbols` | `[]` | o censo itera nada e passa |
| `mutation_report.decision_surface_added` | `false` | `pass('no_decision_surface_added_mutation_waived')` |
| `context_sufficiency` | `85` | `SOVEREIGN_CONTEXT_FLOOR` é 80 |

**Por que não mexi, e isto mudou meu diagnóstico:** o caso é diferente do
`security_scan`. Lá existia um produtor real (`EngineeringQualityScanService`) e
o caminho Dev já o usava — ligar tornou o check verdadeiro e aprovável.

Aqui **não existe produtor neste caminho**: `AtlasTaskServingService` nunca põe
esses campos em `$verification`. Ler o valor ausente não tornaria o check real,
apenas o faria reprovar para sempre — o mesmo defeito na direção oposta.

Fechar de verdade significa **construir os produtores**: diferenciar o escopo e
extrair assinaturas públicas alteradas, computar suficiência de contexto, rodar
mutation testing por landing. Isso é capacidade nova com custo real (mutation
testing a cada aterrissagem é decisão de orçamento), não conserto de fiação.

O modelo a copiar existe: `AtlasLoopAutoMergeService` lê os quatro campos do
envelope threaded pelo grinder, e o docblock dele declara a regra —
*"Nada aqui é fabricado: evidência ausente permanece ausente e o piso recusa com
invariante nomeado."*

Os três literais estão anotados no código como `NOT MEASURED`, com o efeito
exato no piso, para ninguém os confundir com medição.

## Aberto 3 — 78 rotas de API sem autenticação

De 694 rotas `api/`, **78 não têm middleware de auth**, entre elas as cinco de
`atlas-cartography` — incluindo `note/{graph_id}` com `.*`, que serve o corpo de
qualquer nota do vault.

**Por que não mexi:** `AuthenticateAtlasToken` é fail-closed e correto
(`hash_equals`, exige 24+ chars). Adicioná-lo devolve **401 a todo cliente sem o
header**, e não consigo verificar quais superfícies consomem a cartografia — o
Desktop é candidato provável. Quebrar um cliente seu sem saber é pior que o
buraco num sistema ligado em localhost. Precisa do seu inventário de clientes.
