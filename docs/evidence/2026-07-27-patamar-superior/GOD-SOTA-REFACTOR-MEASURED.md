# Obra GOD SOTA — o que rendeu, o que caiu, e a régua que sobrou

Data: 2026-07-27 · 1.586.012 linhas em 6.678 arquivos

## Entregue

| | linhas |
|---|---|
| imports mortos, 543 arquivos | **−9.034** |
| registro de 219 comandos que o Laravel descobre sozinho | **−441** |
| envelopes review-merge, 23 sites → 1 construtor | **−503** |
| `emit()`/`json()` duplicados → trait | **−254** |
| 10 wrappers de alias → `$aliases` nativo | **−384** |
| `SpecialistFlows/` (implementação paralela superseded) | **−1.036** |
| **total** | **−11.652** |

Mais: índice de 946 comandos (não existia), codemap de 107→120 zonas e
2.449→2.900 façades, e a colisão de `stringOption()` resolvida em 638 sites.

## Caiu — sete famílias, cada uma por motivo verificado

| alvo | prometido | medido |
|---|---|---|
| varredura inicial | 133.864 linhas | **falso-morto**: classe viva chamada de `scripts/`, contagens infladas 2–7× |
| 26 gates `AgentCodexRealInvokerPostStart*` | 2.700 | similaridade **0,37**; corpos de 800 a 5.698 chars |
| 1.014 envelopes de readiness | 12.034 | **432 variantes**; núcleo exato em 6% |
| preset completo do Pint | padronização | **+3.351 linhas líquidas** |
| `RuntimeBoundary/Contracts` | 849 | validação executável real, **sem rival** |
| `BaselineSignature` + 3 ilhas | 2.358 | órgão não-fiado; uma traz `@unwired-until 2026-08-05` |
| padronizar o verbo dos gates | — | **`evaluate` já tem 29 assinaturas em 97 métodos** |

## As duas réguas que esta obra produziu

**1. Um bloco só é deletável quando existe um rival vivo do mesmo contrato.**
Sem rival, órfão é órgão não-fiado, não código morto. Foi o que separou
`SpecialistFlows/` — apagado, porque `Router/AtlasAiSpecialistFlowRuntimeService`
declara os mesmos dois schemas e tem 7 chamadores — de
`RuntimeBoundary/Contracts`, mantido por não ter rival. Na superfície os dois
casos eram indistinguíveis: zero chamadores de produção, só testes-espelho.

**2. Nome uniforme sobre contrato divergente é pior que sinônimo honesto.**
32 métodos de gate usam 8 sinônimos de `evaluate`. Renomeá-los pareceria
padronização — mas `evaluate` já carrega 29 assinaturas distintas. O nome único
esconderia a variância em vez de resolvê-la.

## Os dois quase-acidentes

**Config de Pint.** `preset: empty` com só `no_unused_imports` não deixa regra de
espaçamento para recompor a linha: emitiu `use A;use B;` e `namespace X;/**` em
2 arquivos. Meu guard reprovou — e eu quase não vi, porque li `$?` depois de um
pipe pela terceira vez na sessão.

**Projector de envelope.** Minha primeira versão derivava tudo de UM slug. O diff
mostrou o `status` original sem o prefixo que o schema tem; medi: **187 de 189
sites usam dois slugs independentes**. Teria reescrito 46 strings de status sem
nenhum teste notar. A segunda versão prova cada site antes de tocar — por isso 23
passaram e 29 foram recusados.

## O teto

O eixo mecânico está esgotado. O que resta exige decidir **qual comportamento
vence** quando as cópias divergem — qual dos dois slugs sobrevive, qual
`normalize` de 800 ou 5.698 chars é o certo, se `receipt_signed` vem antes ou
depois de `receipt_persisted`. Com 4.800 landings em produção, essa régua é do
operador.
