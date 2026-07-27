# A superfície de refatoração/fusão, medida — 2026-07-27

Pergunta do operador: *"chegamos à conclusão da refatoração, otimização, fundição,
padronização, limpeza de todo Atlas Server?"*

Resposta curta: **o eixo de limpeza mecânica está muito mais perto de esgotado do
que o tamanho do corpus sugere.** O que resta não é refatoração — são decisões.

## O corpus

| | |
|---|---|
| arquivos php em `app/` | 6.678 |
| linhas em `app/` | 1.586.012 |
| arquivos > 800 linhas | 362, somando 420.250 linhas (26%) |
| maior arquivo | 3.597 linhas |

## O experimento

5 lentes independentes mapearam a superfície (god files, famílias de classes,
navegação, padronização, código morto). Cada alvo passou por um refutador com
instrução de derrubar em caso de dúvida, tendo como critério a regra anti-Goodhart
do próprio operador: *"refactor que preserva comportamento = melhoria ZERO"*.

**26 alvos propostos, somando 133.864 linhas. Zero sobreviveu.**

## Por que caíram — quatro padrões

**1. Deadness falsa.** O alvo de 8.456 linhas listava 29 classes "mortas"; a
verificação achou 4 com chamadores vivos, uma delas — `NativeResultNormalizer`,
1.034 linhas — chamada de `scripts/`, **fora de `app/`**, que é justamente onde o
método de varredura não olhava. Apagar teria quebrado o caminho vivo do músculo.

**2. Contagens infladas.** 977 envelopes viraram 802 ao contar blocos reais em vez
de ocorrências de grep; "646 métodos opacos" não existem; "272 sites com a tripla
de flags JSON" contava o token, não a tripla. Fator de inflação típico: 2× a 7×.

**3. Faxina que preserva comportamento.** Colapsar 53 traits HubDelegators com 1.011
forwarders de uma linha é o caso-livro: −2.400 linhas, zero capacidade nova, e a
premissa de que atrapalhava certificação era falsa.

**4. Órfão confundido com morto.** O último sobrevivente — a ilha AP em
`Kernel/Architecture`, 20 classes / 2.828 linhas com **zero referências** fora do
próprio diretório — caiu na minha verificação: `docs/ap/AP-193` e `AP-198` declaram
`status: foundation-read-model-implemented` e listam esses arquivos como
`related_paths`. São **entregáveis documentados de APs marcados como implementados**.
Apagar falsificaria o status. Canon do próprio Atlas:
`loop-orphans-are-unwired-organs-not-deadcode`.

## O que a repetição realmente é

O corpus é repetitivo por **geração**, não por descuido. `Readiness/` (171 arquivos,
110.186 linhas) é uma tabela gerada vestindo 2.954 corpos de método;
`AgenticEngineeringOs/` tem 624 métodos `*ContractObserve` que nunca leem `$input`.
Isso parece fusão óbvia — e é exatamente onde os refutadores foram mais duros,
porque colapsar tabela gerada em código parametrizado troca linhas por indireção
sem ganhar capacidade, e quebra o que hoje é grep-ável.

## O que sobra, e não é refatoração

1. **Navegação:** `app/Console/Commands` tem 965 arquivos / 137.121 linhas sem
   índice de nenhum tipo. O `ZoneCodeMapBuilder` já **lê** as 1.586.012 linhas de
   `app/` a cada execução e emite sobre apenas 1.267.137 — 344.627 linhas (21,7%)
   são tokenizadas e descartadas. Estender a emissão é barato; a lente propôs, o
   refutador derrubou o ganho alegado, mas o desperdício medido é real e vale sua
   decisão.
2. **Órfãos com AP:** wire ou aposentar o AP. Decisão de escopo, não de limpeza.
3. **Os itens de governança** já documentados em `SOVEREIGN-FLOOR-UNMEASURED.md`.

## A lição que vale mais que os números

Uma superfície de refatoração estimada por leitura fica inflada entre 2× e 7×, e
inclui código vivo. Estimar sem refutação adversarial teria produzido uma obra de
133 mil linhas cuja primeira entrega quebraria o músculo.
