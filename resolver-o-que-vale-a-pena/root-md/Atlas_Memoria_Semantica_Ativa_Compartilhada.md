> Cleanup status: human_vault_only.
> Canonical replacement: docs/engineering-knowledge-base/obsidian-atlas-vault.md; docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md; docs/engineering-knowledge-base/memory-core-security-privacy.md.
> Cleanup note: Human/personal memory source. Preserve, redact and promote excerpts; never inject raw into providers.
> Authority warning: body-level "fonte de verdade" claims are historical human source material; operational authority is the canonical replacement set above.

# ATLAS

**Memoria Semantica Ativa Compartilhada**

*Especificacao arquitetural do segundo cerebro compartilhado entre Vitor e Atlas*

---

| | |
|---|---|
| **Operador** | Vitor Emanuel |
| **Sistema** | Atlas |
| **Nome conceitual** | Memoria Semantica Ativa Compartilhada |
| **Implementacao primaria** | Vault Obsidian em Markdown + PostgreSQL + IA |
| **Documento relacionado** | Atlas Documento Mestre v6.0 |
| **Data** | 28 de abril de 2026 |
| **Status** | Documento arquitetural de estado-alvo |
| **Decisao constitucional** | Annual Review janeiro 2027 |

---

## Status Deste Documento

Este documento define o estado final buscado para a Memoria Semantica Ativa Compartilhada do Atlas. Ele nao e um plano de sprint, nao e uma lista de features soltas e nao deve ser tratado como experimento estetico com Obsidian.

Ele descreve a tese, os principios, a arquitetura e os limites que devem guiar a implementacao por anos. Quando houver duvida futura entre "apenas guardar notas" e "construir memoria ativa", este documento prevalece como referencia.

A implementacao pode comecar de forma incremental, mas a direcao final e esta:

> **Atlas deve se tornar uma memoria externa compartilhada, capaz de preservar, reativar, treinar, confrontar, testar e amadurecer conhecimento pessoal de Vitor no momento em que esse conhecimento se torna util.**

Obsidian e a implementacao inicial mais adequada para a camada semantica. A tese nao e Obsidian. A tese e memoria semantica ativa.

---

## 1. Tese Central

Vitor aprende coisas importantes e frequentemente as perde por limitacoes humanas reais: memoria fraca, TDAH, hiperfoco, abandono, variacao de energia e dificuldade de recuperacao contextual. O problema nao e apenas capturar conhecimento. O problema e recuperar conhecimento no momento certo, com forma aplicavel.

Atlas existe, em parte, para virtualizar cerebros auxiliares. A Memoria Semantica Ativa Compartilhada e o cerebro semantico do sistema: um acervo curado de conceitos, modelos mentais, principios, filosofias, teses, praticas e aprendizados que Vitor e Atlas usam juntos.

Ela nao e uma biblioteca passiva. Ela deve funcionar como uma memoria viva:

- lembra o que Vitor esqueceu;
- detecta quando um conceito esquecido se tornou relevante;
- transforma leitura em pratica;
- transforma pratica em principio;
- transforma hipotese em experimento;
- transforma experimento em regra validada;
- confronta incoerencia entre identidade declarada e comportamento real;
- reduz o custo cognitivo de voltar a acessar o proprio pensamento.

O estado final buscado e um sistema onde Vitor nao depende apenas da memoria biologica para acessar o que aprendeu. Atlas deve conseguir consultar o segundo cerebro semantico, cruzar com dados episodicos reais e devolver conhecimento no momento de aplicacao.

---

## 2. O Problema Real

### 2.1 Memoria humana nao e suficiente

O operador le livros, conversa com IAs, constrói filosofias, aprende tecnicas, formula teses e identifica principios. Sem sistema externo, grande parte disso evapora em semanas. O problema se agrava pelo perfil cognitivo do operador:

- **TDAH.** Acesso inconsistente ao que ja foi aprendido. A memoria existe, mas nao aparece quando precisa.
- **Hiperfoco.** Produz muito em janelas intensas, mas depois abandona ou perde continuidade.
- **Memoria de trabalho limitada.** Conceitos importantes competem com urgencias do dia.
- **Recuperacao contextual fraca.** Mesmo quando algo foi aprendido, o operador pode nao lembrar que aquilo se aplica a uma situacao atual.
- **Variacao de energia.** Em estado ruim, o operador nao vai procurar voluntariamente uma nota antiga.

Portanto, a arquitetura nao pode depender de "Vitor lembrar de revisar". O sistema deve ir ate Vitor quando houver contexto suficiente.

### 2.2 Internet nao e acervo

A internet e ampla, mas nao e uma memoria pessoal. Ela tem baixa curadoria, pouca continuidade e nenhum compromisso com a identidade intelectual do operador. Buscar algo na internet nao equivale a consultar o proprio cerebro expandido.

Atlas precisa de um acervo de qualidade, acumulado ao longo de anos, com linguagem, prioridades, experiencias e criterios de Vitor. A internet e fonte externa. A Memoria Semantica Ativa e corpus interno.

### 2.3 Notas passivas tambem nao bastam

Um vault cheio de notas pode parecer inteligencia, mas ser apenas deposito. Se uma nota nao volta ao uso, nao treina comportamento, nao informa decisao e nao amadurece em principio, ela e custo cognitivo arquivado.

Para o Atlas, uma boa nota precisa ter pelo menos uma destas funcoes:

- explicar um conceito relevante;
- preservar uma tese do operador;
- ensinar uma pratica aplicavel;
- servir como gatilho de comportamento;
- gerar uma hipotese testavel;
- conectar areas diferentes;
- corrigir um padrao recorrente;
- virar principio, regra, modelo mental ou decisao estruturada.

---

## 3. Definicao

**Memoria Semantica Ativa Compartilhada** e a camada do Atlas responsavel por preservar e operar conhecimento significativo do operador em formato textual, linkavel, revisavel, portavel e legivel por IA.

Ela e composta por:

1. **Vault semantico em Markdown.** Conteudo narrativo, filosofico, conceitual e reflexivo.
2. **Frontmatter estruturado.** Campos que tornam a nota acionavel por Atlas.
3. **Indice em PostgreSQL.** Path, tipo, metadados, embeddings, estado, historico de ativacao.
4. **Motor executivo de IA.** Decide quando relembrar, treinar, conectar, confrontar, testar e promover.
5. **Governanca automatica.** Mantem a memoria viva, evita acumulo e detecta apodrecimento.
6. **Rituais de pratica.** Jogos cognitivos e revisoes que transformam conhecimento em competencia.

### 3.1 O que ela nao e

| Nao e | Motivo |
|---|---|
| Deposito de notas | Depositos acumulam custo. A memoria precisa voltar ao uso. |
| Wiki pessoal generica | Atlas precisa de ativacao contextual, nao apenas organizacao. |
| Substituto do PostgreSQL | Dados episodicos e metricas continuam estruturados no banco. |
| Ferramenta de produtividade | O objetivo e raciocinio, identidade e aplicacao, nao organizar tarefas. |
| Diario emocional | Capturas e reflexoes podem gerar notas, mas a camada semantica exige curadoria. |
| Sistema automatico sem operador | Atlas propoe, Vitor ratifica. Lei 10 permanece. |
| Biblioteca de internet | So entra conhecimento que passou por filtro de qualidade e uso esperado. |

---

## 4. Os Cinco Cerebros do Atlas

A arquitetura final do Atlas deve ser entendida como cinco cerebros complementares. Cada um tem responsabilidade propria e nao deve invadir a funcao dos outros.

### 4.1 Cerebro episodico

**Implementacao:** PostgreSQL.

**Funcao:** registrar o que aconteceu.

Exemplos:

- capturas;
- audios;
- transcricoes;
- HealthKit;
- Rize;
- check-ins;
- energia;
- mood;
- estado;
- Bitacula;
- sessoes digitais;
- snapshots de prontidao;
- logs de sincronizacao.

O cerebro episodico responde: **quando, onde, quanto, com que estado, em qual contexto.**

### 4.2 Cerebro semantico

**Implementacao:** Obsidian / Markdown.

**Funcao:** registrar o que significa.

Exemplos:

- modelos mentais;
- ideias filosoficas;
- notas de livros;
- principios;
- teses;
- praticas;
- aprendizados;
- decisoes amadurecidas;
- hipoteses vivas;
- identidade intelectual;
- mapas conceituais;
- conexoes explicadas.

O cerebro semantico responde: **o que isso quer dizer, por que importa, quando usar e como aplicar.**

### 4.3 Cerebro executivo

**Implementacao:** Atlas + LLMs + regras + contexto.

**Funcao:** decidir quando um conhecimento deve voltar.

Exemplos:

- antes de uma call, reativar tecnica de oratoria;
- diante de captura ansiosa, reativar principio de decisao;
- apos padrao de procrastinacao, reativar modelo mental sobre input digital;
- durante review, propor teste de uma hipotese;
- quando Vitor violar um principio recorrente, confrontar de forma informacional.

O cerebro executivo responde: **qual conhecimento precisa aparecer agora.**

### 4.4 Cerebro de pratica

**Implementacao:** Bitacula, rituais, jogos cognitivos, reviews.

**Funcao:** transformar conhecimento em comportamento e competencia.

Exemplos:

- treino de recall;
- aplicacao de modelo mental em situacao real;
- jogo de conexao forcada;
- desafio adversarial;
- pratica de oratoria;
- revisao semanal de hipoteses;
- microexperimentos pessoais.

O cerebro de pratica responde: **como incorporar isso na vida.**

### 4.5 Cerebro de governanca

**Implementacao:** regras do Atlas + metricas de saude do vault.

**Funcao:** impedir que a memoria apodreca.

Exemplos:

- detectar notas sem uso;
- detectar hipoteses sem teste;
- detectar principios sem aplicacao;
- sugerir arquivamento;
- sugerir promocao;
- medir valor real do vault;
- manter a relacao sinal/ruido alta.

O cerebro de governanca responde: **esta memoria esta viva ou virou acumulo.**

---

## 5. Principios Nao-Negociaveis

### Principio 1 - Recuperacao contextual acima de armazenamento

Guardar nao basta. Uma nota valiosa precisa poder voltar quando houver contexto. Para TDAH, o valor esta menos em "ter salvo" e mais em "ser lembrado no momento de aplicacao".

Toda nota madura deve responder:

- quando usar;
- em qual contexto;
- qual sinal ativa;
- que pratica sugere;
- qual comportamento espera mudar.

### Principio 2 - Obsidian e corpo, nao tese

Obsidian e ferramenta. Markdown e formato. A tese e Memoria Semantica Ativa Compartilhada.

Se um dia Obsidian deixar de servir, o sistema deve preservar a tese usando outra ferramenta compativel com Markdown, links, portabilidade e leitura por IA.

### Principio 3 - PostgreSQL nao duplica narrativa

O corpo narrativo das notas vive em Markdown. PostgreSQL guarda indice, frontmatter parseado, embeddings, historico de ativacao, status, links e metricas. O banco nao vira copia completa do vault, exceto onde tecnicamente necessario para busca ou auditoria.

### Principio 4 - Direcao de fluxo por tipo de dado

Cada tipo de informacao tem uma fonte de verdade primaria.

| Tipo | Fonte primaria | Destino secundario |
|---|---|---|
| Metricas e eventos | PostgreSQL | Export markdown opcional |
| Capturas brutas | PostgreSQL | Promocao para Markdown quando valiosas |
| Conhecimento curado | Markdown | Indice em PostgreSQL |
| Hipoteses | Markdown | Teste estruturado em PostgreSQL |
| Resultados de teste | PostgreSQL | Retorno para Markdown |
| Principios validados | Markdown | Registro de ativacao em PostgreSQL |

Sem direcao clara, o sistema vira conflito de sincronizacao.

### Principio 5 - Atlas propoe, Vitor ratifica

Atlas pode sugerir criar, promover, arquivar, conectar e testar notas. Mas a entrada de conhecimento no acervo curado exige ratificacao do operador.

Excecao: Atlas pode criar drafts em `_inbox/` sem ratificacao previa. Draft nao e conhecimento curado.

### Principio 6 - Curadoria minima, nao curadoria perfeita

O sistema deve aceitar que Vitor tem TDAH. Curadoria que exige horas semanais morre. O modelo correto e:

- Atlas faz trabalho pesado;
- Vitor ratifica com baixa friccao;
- refinamento profundo acontece em rituais agendados;
- notas fracas permanecem em `_inbox/` ou `_laboratorio/`;
- apenas notas com uso esperado entram no acervo.

### Principio 7 - Toda nota importante precisa de uso pretendido

Nota sem uso pretendido nao entra nas camadas maduras. Pode ficar em `_inbox/` ou `_laboratorio/`, mas nao deve poluir modelos mentais, principios ou hipoteses.

Uso pretendido pode ser:

- aplicar em vendas;
- lembrar antes de call;
- testar contra metricas;
- treinar semanalmente;
- confrontar em revisao;
- orientar decisao;
- compor identidade intelectual;
- gerar pratica.

### Principio 8 - Link explicado e melhor que link abundante

Links nao sao ornamento. Cada link relevante deve ter motivo. Atlas deve preferir poucos links explicados a grafo grande e vazio.

Exemplo de link ruim:

> Relacionado a [[Decisao]]

Exemplo de link bom:

> Relaciona com [[Decisao irreversivel]] porque ambos tratam de reduzir pressa quando custo de reversao e alto.

### Principio 9 - Conhecimento precisa amadurecer

Nem toda nota tem o mesmo peso. O vault precisa de gradiente de maturidade:

1. Draft;
2. Curada;
3. Aplicada;
4. Testada;
5. Validada;
6. Promovida;
7. Arquivada ou substituida.

Atlas deve tratar uma nota validada de modo diferente de uma anotacao recem-importada.

### Principio 10 - Portabilidade por decadas

O conhecimento de Vitor nao pode ficar preso a fornecedor. Markdown, Git, YAML e PostgreSQL sao escolhidos porque sobrevivem melhor ao tempo do que formatos proprietarios.

Lei 6 se aplica: este acervo e parte do Dataset Sagrado.

---

## 6. Estado Final Da Arte

O estado supremo buscado nao e "ter um vault bonito". O estado supremo e um sistema onde a memoria semantica atua sobre a vida real.

### 6.1 O que deve acontecer no estado final

#### 1. Atlas lembra por Vitor

Vitor aprendeu uma tecnica de oratoria ha 8 meses. Antes de uma call importante, Atlas detecta contexto por calendario, Rize, missao do dia ou captura recente. Ele traz uma intervencao curta:

> Antes de explicar preco: pausa de 2 segundos, tese em uma frase, depois detalhe.

O conhecimento nao fica enterrado. Ele volta quando e util.

#### 2. Atlas treina conhecimento esquecido

Atlas detecta que um modelo mental importante nao foi praticado ha 45 dias. No ritual ou em momento de baixa carga, propõe exercicio de 3 minutos:

> Reconstrua de memoria o modelo "custo de reversao". Depois comparo com sua nota original.

Isso combate esquecimento e cria transferencia sem IA.

#### 3. Atlas conecta eventos recentes com conhecimento antigo

Vitor captura audio sobre dificuldade com cliente. Atlas encontra nota antiga sobre "clareza antes de escopo" e sugere:

> Esta captura parece repetir um padrao descrito na nota "Escopo antes de entusiasmo". Quer abrir como hipotese de processo BlackInk?

#### 4. Atlas confronta incoerencia sem controlar

Vitor tem principio validado: "decisoes grandes exigem noite de sono". Atlas detecta uma captura em que Vitor quer decidir algo grande em estado disperso e sono ruim.

Intervencao correta:

> Seu principio "decisoes grandes exigem noite de sono" parece aplicavel aqui. Sono: 5h38, estado: disperso. Quer marcar decisao para revisao amanha?

Intervencao incorreta:

> Voce nao deve decidir agora.

Atlas informa e convida. Nao controla.

#### 5. Atlas transforma hipotese em teste

Nota em `04-hipoteses/`:

> "Terere melhora energia subjetiva mas talvez piore sono se tomado tarde."

Atlas cruza Bitacula, HealthKit e sono. Depois de dados suficientes:

> Hipotese pronta para teste: dias com terere apos 15h tiveram sono 22min menor em media. Dataset ainda fraco. Quer rodar experimento de 14 dias?

#### 6. Atlas promove conhecimento por evidencia

Um insight aparece em capturas, revisoes e aplicacoes reais. Atlas sugere promover:

> Este padrao apareceu 7 vezes e gerou 3 decisoes melhores. Promover para principio?

Se Vitor aceita, a nota muda de estado. O conhecimento sobe de maturidade.

#### 7. Atlas arquiva sem culpa

Nota de livro sem uso, sem link, sem ativacao e sem aplicacao por 12 meses:

> Esta nota nunca foi usada e nao tem gatilhos. Arquivar ou completar uso pretendido?

Arquivar nao e falha. E higiene cognitiva.

### 6.2 Como saber que chegamos perto

O sistema esta funcionando quando:

- Vitor recebe lembretes que parecem precisos, nao genericos;
- conhecimento esquecido volta em momento util;
- notas viram comportamento;
- comportamento vira evidencia;
- evidencia muda principios;
- principios mudam decisoes;
- Vitor sente que pensa melhor porque Atlas preserva e reativa partes do proprio pensamento;
- o vault fica menor em ruido e maior em utilidade ao longo do tempo.

### 6.3 O que seria fracasso

Fracasso nao e ter poucas notas. Fracasso e:

- muitas notas sem uso;
- links decorativos;
- zero ativacoes contextuais;
- zero praticas;
- zero hipoteses testadas;
- Vitor evitando abrir o vault;
- Atlas citando notas irrelevantes;
- curadoria virando tarefa pesada;
- Obsidian virando projeto paralelo de organizacao.

---

## 7. Estrutura Do Vault

Estrutura inicial recomendada:

```txt
atlas-vault/
├── 00-constituicao/
├── 01-acervo/
│   ├── livros/
│   ├── filosofia/
│   ├── blackink/
│   ├── saude/
│   ├── comunicacao/
│   ├── investimento/
│   └── vida/
├── 02-modelos-mentais/
├── 03-principios/
├── 04-hipoteses/
├── 05-praticas/
├── 06-jogos-cognitivos/
├── 07-decisoes-e-identidade/
├── 08-sinteses/
├── _inbox/
├── _laboratorio/
├── _arquivo/
└── _templates/
```

### 7.1 `00-constituicao/`

Guarda documentos mestres do Atlas, adendos, leis, compromissos e decisoes constitucionais. Conteudo de alta autoridade.

Regra: so entra documento que orienta o sistema por meses ou anos.

### 7.2 `01-acervo/`

Guarda conhecimento curado por dominio. E onde vivem notas de livros, ideias de autores, conceitos filosoficos, tecnicas, frameworks e aprendizados ainda nao promovidos.

Regra: nota de acervo precisa ter fonte e uso pretendido minimo.

### 7.3 `02-modelos-mentais/`

Guarda formas reutilizaveis de pensar. Um modelo mental deve ser aplicavel em multiplos contextos.

Exemplos:

- custo de reversao;
- velocidade versus direcao;
- clareza antes de escopo;
- tese antes de detalhe;
- energia como capital decisorio.

Regra: modelo mental sem exemplo de aplicacao fica incompleto.

### 7.4 `03-principios/`

Guarda regras de identidade operacional. Principio e algo que Vitor escolhe usar como norte.

Exemplos:

- "Decisao grande nao nasce em sono ruim."
- "Capturar antes de otimizar."
- "Preco e explicado depois da tese de valor."

Regra: principio deve ter campo `violation_signals`.

### 7.5 `04-hipoteses/`

Guarda teses testaveis. Hipotese sem possibilidade de teste pode ser ensaio, filosofia ou modelo mental, mas nao hipotese operacional.

Regra: toda hipotese deve ter `test_plan` ou `test_status: not_ready`.

### 7.6 `05-praticas/`

Guarda treinos concretos. Pratica e conhecimento executavel.

Exemplos:

- pausa antes da tese;
- explicar preco em uma frase;
- revisar premissa antes de decisao;
- fazer recall de conceito sem olhar;
- leitura ativa com aplicacao imediata.

Regra: pratica precisa caber em tempo definido.

### 7.7 `06-jogos-cognitivos/`

Guarda rituais de raciocinio estruturado entre Vitor e Atlas. Jogos existem para treinar recall, conexao, aplicacao, adversarialidade e sintese.

Regra: cada jogo precisa ter objetivo, duracao, entrada, procedimento e criterio de saida.

### 7.8 `07-decisoes-e-identidade/`

Guarda decisoes estruturadas maduras e notas sobre identidade intelectual, valores e escolhas de vida.

Regra: nota de identidade nao deve ser criada por impulso. Deve nascer de padrao recorrente ou revisao profunda.

### 7.9 `08-sinteses/`

Guarda sinteses transversais. Uma sintese cruza multiplas notas, dominios ou experiencias.

Regra: sintese precisa citar as notas que combina e declarar qual entendimento novo emergiu.

### 7.10 `_inbox/`

Entrada temporaria. Atlas pode criar drafts aqui automaticamente. Vitor nao precisa sentir culpa por inbox cheia. Nada aqui e conhecimento maduro.

### 7.11 `_laboratorio/`

Espaco para exploracao, pensamento incompleto, testes de escrita e notas baguncadas. Pode ser limpo sem perda constitucional.

### 7.12 `_arquivo/`

Notas preservadas, mas fora do uso ativo. Arquivar e sinal de governanca saudavel.

---

## 8. Frontmatter Padrao

Toda nota madura deve ter frontmatter YAML. O corpo da nota continua livre. O frontmatter torna a nota acionavel por Atlas.

### 8.1 Campos comuns

```yaml
id:
type:
title:
status:
created_at:
updated_at:
domains: []
source_type:
source_refs: []
confidence:
maturity:
summary:
when_to_use: []
trigger_signals: []
do_not_use_when: []
practice_prompt:
related_notes: []
postgres_refs: []
last_activated_at:
last_practiced_at:
review_after:
archive_after:
```

### 8.2 Valores esperados

`type`:

- `book_note`
- `concept`
- `mental_model`
- `principle`
- `hypothesis`
- `practice`
- `cognitive_game`
- `decision`
- `identity`
- `synthesis`

`status`:

- `draft`
- `curated`
- `active`
- `testing`
- `validated`
- `promoted`
- `archived`

`confidence`:

- `low`
- `medium`
- `high`
- `validated`

`maturity`:

- `seed`
- `useful`
- `applied`
- `tested`
- `canonical`

### 8.3 Template de modelo mental

```yaml
id:
type: mental_model
title:
status: curated
created_at:
updated_at:
domains: []
source_type:
source_refs: []
confidence: medium
maturity: seed
summary:
when_to_use:
  - 
trigger_signals:
  - 
do_not_use_when:
  - 
practice_prompt:
related_notes: []
postgres_refs: []
last_activated_at:
last_practiced_at:
review_after:
archive_after:
```

Corpo recomendado:

```md
## Ideia central

## Por que importa

## Quando usar

## Exemplo real

## Erros comuns

## Como Atlas deve me lembrar

## Links explicados
```

### 8.4 Template de principio

```yaml
id:
type: principle
title:
status: active
created_at:
updated_at:
domains: []
confidence: medium
maturity: useful
summary:
when_to_use: []
trigger_signals: []
violation_signals: []
do_not_use_when: []
operator_commitment:
practice_prompt:
related_notes: []
postgres_refs: []
last_activated_at:
last_violated_at:
review_after:
```

Corpo recomendado:

```md
## Principio

## Origem

## O que protege

## Como eu violo isso

## Sinais de ativacao

## Exemplo de aplicacao

## Exemplo de violacao

## Links explicados
```

### 8.5 Template de hipotese

```yaml
id:
type: hypothesis
title:
status: testing
created_at:
updated_at:
domains: []
confidence: low
maturity: seed
summary:
claim:
why_it_might_be_true:
counterarguments: []
test_status: not_ready
test_plan:
metrics_needed: []
minimum_dataset:
postgres_refs: []
result:
decision_after_test:
review_after:
```

Corpo recomendado:

```md
## Hipotese

## Por que acredito nisso

## O que poderia provar que esta errado

## Como testar

## Dados necessarios

## Resultado

## Decisao
```

### 8.6 Template de pratica

```yaml
id:
type: practice
title:
status: active
created_at:
updated_at:
domains: []
confidence: medium
maturity: useful
summary:
duration_minutes:
when_to_use: []
trigger_signals: []
practice_prompt:
success_criteria:
failure_modes: []
last_practiced_at:
practice_count:
related_notes: []
```

Corpo recomendado:

```md
## Objetivo

## Quando praticar

## Procedimento

## Criterio de sucesso

## Erros comuns

## Variações
```

### 8.7 Template de jogo cognitivo

```yaml
id:
type: cognitive_game
title:
status: active
created_at:
updated_at:
domains: []
duration_minutes:
trains:
  - recall
  - synthesis
input_notes: []
output_type:
frequency:
success_criteria:
last_played_at:
```

Corpo recomendado:

```md
## Objetivo

## Entrada

## Regras

## Procedimento

## Saida esperada

## Como Atlas avalia

## Quando promover resultado
```

---

## 9. Motor De Ativacao

O motor de ativacao e a peca que transforma nota em memoria ativa.

### 9.1 Inputs de contexto

Atlas pode ativar notas a partir de:

- horario;
- calendario;
- local;
- missao do dia;
- captura recente;
- transcricao de audio;
- estado subjetivo;
- energia;
- mood;
- sono;
- HRV;
- Rize;
- app/site atual;
- modo de foco;
- rotina semanal;
- review;
- violacao de principio;
- conversa recorrente;
- decisao pendente;
- padrao de procrastinacao;
- baixa aplicacao de conhecimento relevante.

### 9.2 Tipos de ativacao

| Tipo | Funcao | Exemplo |
|---|---|---|
| Relembrar | Trazer nota esquecida | "Este conceito se aplica agora." |
| Treinar | Praticar competencia | "Reconstrua de memoria." |
| Conectar | Ligar evento atual a nota antiga | "Esta captura parece relacionada." |
| Confrontar | Mostrar incoerencia | "Seu principio X parece violado." |
| Testar | Transformar hipotese em experimento | "Ja ha dados suficientes." |
| Promover | Subir maturidade | "Este insight virou principio?" |
| Arquivar | Remover ruido | "Esta nota nunca foi usada." |

### 9.3 Regras de intervencao

Atlas nao deve ativar conhecimento indiscriminadamente. A intervencao precisa respeitar:

- relevancia;
- momento;
- energia do operador;
- custo cognitivo;
- repeticao recente;
- risco de irritacao;
- modo de assistencia selecionado;
- Lei 7, confronto informacional, nao controlador.

### 9.4 Formato de intervencao

Intervencao boa:

> Antes dessa call, lembre do principio "tese antes de detalhe": uma frase de valor, pausa, depois exemplo.

Intervencao ruim:

> Voce deveria usar melhor suas notas.

Intervencao boa:

> Esta decisao parece grande e reversibilidade baixa. Seu principio "dormir antes de decidir" se aplica. Quer marcar para amanha?

Intervencao ruim:

> Nao tome essa decisao.

---

## 10. Governanca Automatica Do Vault

Sem governanca, a memoria apodrece. Atlas deve medir a saude do vault como mede corpo, sono e atividade digital.

### 10.1 Metricas de saude

| Metrica | Pergunta |
|---|---|
| Notas ativas | Quanto conhecimento esta em uso? |
| Notas sem ativacao | O que esta morto? |
| Hipoteses sem teste | O que esta preso? |
| Principios sem aplicacao | O que e identidade falsa ou esquecida? |
| Praticas sem treino | O que nao virou competencia? |
| Links explicados | O grafo tem sentido ou e decorativo? |
| Notas promovidas | O conhecimento esta amadurecendo? |
| Notas arquivadas | O sistema remove ruido? |
| Ativacoes aceitas | Atlas esta lembrando bem? |
| Ativacoes rejeitadas | Atlas esta sendo irrelevante? |

### 10.2 Regras de higiene

- Nota em `_inbox/` por mais de 30 dias: sugerir curar, arquivar ou deixar expirar.
- Hipotese ativa por mais de 90 dias sem teste: sugerir plano de teste ou arquivar.
- Principio sem aplicacao detectada por 180 dias: sugerir revisao.
- Modelo mental sem exemplo real: marcar incompleto.
- Nota sem `when_to_use` e sem `trigger_signals`: nao pode virar ativa.
- Jogo cognitivo sem execucao por 60 dias: sugerir pausar.
- Nota rejeitada em 3 ativacoes seguidas: reduzir prioridade.
- Nota aceita em 3 ativacoes relevantes: aumentar confianca.

### 10.3 Estados de saude do vault

**Saudavel**

- poucas notas mortas;
- ativacoes aceitas;
- hipoteses em teste;
- praticas sendo executadas;
- arquivo sendo usado sem culpa.

**Inflado**

- muitas notas novas;
- poucas aplicacoes;
- inbox crescendo;
- links superficiais.

**Frio**

- quase nenhuma ativacao;
- nenhum jogo;
- nenhuma revisao;
- notas nao voltam ao uso.

**Ansioso**

- Atlas intervem demais;
- operador ignora;
- memoria vira cobranca.

**Maduro**

- notas promovidas;
- principios validados;
- conhecimento antigo aparece em decisoes novas;
- pouco ruido;
- alta relevancia contextual.

---

## 11. Jogos Cognitivos

Jogos cognitivos sao rituais estruturados de treino entre Vitor e Atlas. Eles existem para transformar acervo em raciocinio, nao para entretenimento.

### 11.1 Regras gerais

- Duracao curta: 5 a 30 minutos.
- Entrada clara: uma nota, duas notas, uma captura ou uma decisao.
- Saida registrada: insight, nota revisada, hipotese, principio ou pratica.
- Sem perfeccionismo: jogo bom gera movimento, nao tratado academico.
- Atlas pode ser professor, adversario, espelho ou juiz.

### 11.2 Jogo 1 - Recall sem olhar

**Objetivo:** combater esquecimento.

**Entrada:** nota antiga.

**Procedimento:**

1. Atlas mostra apenas titulo e contexto minimo.
2. Vitor reconstrói a ideia de memoria.
3. Atlas compara com a nota original.
4. Atlas destaca lacunas.
5. Vitor atualiza a nota ou agenda novo treino.

**Saida:** nivel de recall e lacunas.

### 11.3 Jogo 2 - Conexao forcada

**Objetivo:** criar links significativos.

**Entrada:** duas notas aparentemente distantes.

**Procedimento:**

1. Atlas escolhe duas notas.
2. Vitor tenta explicar conexao.
3. Atlas critica.
4. Se a conexao sobreviver, vira link explicado.
5. Se nao sobreviver, registra descarte.

**Saida:** link explicado ou rejeicao.

### 11.4 Jogo 3 - Adversario de tese

**Objetivo:** fortalecer pensamento critico.

**Entrada:** principio, hipotese ou tese.

**Procedimento:**

1. Vitor defende a tese.
2. Atlas ataca com contraexemplos.
3. Vitor ajusta, abandona ou reforca.
4. Resultado atualiza maturidade da nota.

**Saida:** tese refinada ou reduzida.

### 11.5 Jogo 4 - Aplicacao antes da resposta

**Objetivo:** treinar transferencia para vida real.

**Entrada:** situacao real de BlackInk, relacao, saude ou decisao.

**Procedimento:**

1. Atlas apresenta situacao.
2. Vitor escolhe modelo mental antes de ver sugestao.
3. Atlas compara escolha com acervo.
4. Vitor aplica ou corrige.

**Saida:** aplicacao registrada.

### 11.6 Jogo 5 - Sintese de tres fontes

**Objetivo:** criar entendimento novo.

**Entrada:** tres notas ou fontes.

**Procedimento:**

1. Atlas seleciona tres notas relacionadas.
2. Vitor formula tese unificadora.
3. Atlas critica e completa.
4. Resultado vira nota em `08-sinteses/`.

**Saida:** sintese nova.

### 11.7 Jogo 6 - Drill de oratoria

**Objetivo:** transformar conhecimento em performance verbal.

**Entrada:** pratica ou modelo de comunicacao.

**Procedimento:**

1. Atlas define contexto: cliente, preco, objeção, explicacao tecnica.
2. Vitor responde em voz ou texto.
3. Atlas avalia clareza, pausa, tese e excesso.
4. Vitor repete.

**Saida:** treino de comunicacao registrado.

### 11.8 Jogo 7 - Hipotese para experimento

**Objetivo:** converter pensamento em dado.

**Entrada:** hipotese do vault.

**Procedimento:**

1. Atlas pede claim claro.
2. Vitor define o que provaria ou refutaria.
3. Atlas mapeia dados necessarios no PostgreSQL.
4. Se possivel, cria plano de teste.

**Saida:** hipotese pronta ou marcada como nao testavel.

---

## 12. Fluxo De Vida Do Conhecimento

### 12.1 Captura

Vitor captura via app, audio, texto, foto, conversa, leitura ou anotacao. Captura bruta entra no PostgreSQL ou `_inbox/`, dependendo da origem.

### 12.2 Proposta

Atlas analisa capturas e sugere:

- criar nota;
- anexar a nota existente;
- transformar em hipotese;
- transformar em pratica;
- ignorar.

### 12.3 Curadoria

Vitor ratifica. Atlas gera draft. Vitor ajusta quando necessario.

### 12.4 Indexacao

Atlas lê frontmatter, calcula embedding, registra path e metadados no PostgreSQL.

### 12.5 Ativacao

Atlas detecta contexto e traz a nota de volta.

### 12.6 Aplicacao

Vitor usa, rejeita, treina ou ajusta. O resultado e registrado.

### 12.7 Teste

Hipoteses descem para PostgreSQL quando mensuraveis.

### 12.8 Promocao

Conhecimento que se prova util sobe de maturidade.

### 12.9 Arquivamento

Conhecimento sem uso sai do caminho ativo, sem culpa.

---

## 13. Modelo De Dados Alvo

Este documento nao substitui migrations futuras, mas define o modelo conceitual.

### 13.1 `semantic_notes`

Indice de notas do vault.

Campos esperados:

- `id`
- `vault_path`
- `title`
- `type`
- `status`
- `maturity`
- `confidence`
- `domains`
- `summary`
- `frontmatter`
- `content_hash`
- `embedding`
- `last_indexed_at`
- `last_modified_at`
- `created_at`
- `updated_at`
- `archived_at`

### 13.2 `semantic_note_links`

Links explicados entre notas.

Campos esperados:

- `id`
- `source_note_id`
- `target_note_id`
- `link_type`
- `explanation`
- `created_by`
- `confidence`
- `created_at`

### 13.3 `semantic_note_activations`

Historico de quando Atlas trouxe conhecimento para Vitor.

Campos esperados:

- `id`
- `note_id`
- `activation_type`
- `context_type`
- `context_payload`
- `trigger_signals`
- `shown_at`
- `operator_response`
- `was_useful`
- `result_note`
- `created_at`

### 13.4 `semantic_hypothesis_tests`

Testes estruturados derivados do vault.

Campos esperados:

- `id`
- `note_id`
- `claim`
- `metrics`
- `window_start`
- `window_end`
- `minimum_dataset`
- `status`
- `result`
- `confidence`
- `created_at`
- `completed_at`

### 13.5 `cognitive_game_runs`

Execucoes de jogos cognitivos.

Campos esperados:

- `id`
- `game_note_id`
- `input_note_ids`
- `started_at`
- `finished_at`
- `duration_seconds`
- `output_type`
- `output_note_id`
- `score`
- `metadata`

### 13.6 `vault_health_snapshots`

Estado de saude do vault.

Campos esperados:

- `id`
- `snapshot_date`
- `total_notes`
- `active_notes`
- `stale_notes`
- `inbox_notes`
- `untested_hypotheses`
- `unpracticed_practices`
- `activation_acceptance_rate`
- `notes_promoted`
- `notes_archived`
- `health_state`
- `metadata`

---

## 14. Regras Para Agentes De IA

Agentes podem trabalhar sobre a Memoria Semantica Ativa, mas precisam obedecer limites.

### 14.1 Agente Curador

Funcao:

- detectar capturas valiosas;
- propor drafts;
- completar frontmatter inicial;
- sugerir pasta.

Limite:

- nao promove sem ratificacao.

### 14.2 Agente Linker

Funcao:

- sugerir links explicados;
- detectar notas relacionadas;
- encontrar contradicoes.

Limite:

- link automatico fica como sugestao ate aceite.

### 14.3 Agente Adversario

Funcao:

- atacar teses;
- procurar contraexemplos;
- reduzir autoengano.

Limite:

- nao deve virar voz punitiva.

### 14.4 Agente Treinador

Funcao:

- propor jogos;
- conduzir recall;
- criar drills;
- medir transferencia.

Limite:

- respeitar energia e custo cognitivo.

### 14.5 Agente Governanca

Funcao:

- medir saude;
- sugerir arquivamento;
- detectar notas mortas;
- sinalizar excesso.

Limite:

- governanca nao pode virar burocracia.

---

## 15. Privacidade E Soberania

A Memoria Semantica Ativa contem identidade intelectual e filosofica de Vitor. Ela e mais sensivel que dado de produtividade comum.

Regras:

- vault local-first;
- Git privado;
- backups criptografados;
- nenhuma sincronizacao para servico externo sem decisao explicita;
- embeddings podem ser armazenados no PostgreSQL local;
- conteudo completo nao deve ser enviado a LLM externa sem necessidade contextual;
- notas sobre terceiros seguem clausula de privacidade relacional;
- exports publicos mascaram notas sensiveis por padrao.

---

## 16. Anti-Padroes

### 16.1 Colecionar notas

Sintoma: muitas notas, pouca aplicacao.

Resposta: Atlas reduz prioridade de acumulacao e aumenta jogos de aplicacao.

### 16.2 Grafo ornamental

Sintoma: muitos links sem explicacao.

Resposta: links sem explicacao nao contam como maturidade.

### 16.3 Curadoria pesada

Sintoma: Vitor evita revisar porque parece trabalho.

Resposta: Atlas reduz escopo, propõe decisoes de 1 toque e arquiva mais.

### 16.4 Intervencao demais

Sintoma: Atlas lembra coisas demais, em momentos ruins.

Resposta: reduzir frequencia, exigir maior confianca e respeitar modo de assistencia.

### 16.5 Vault como procrastinacao

Sintoma: organizar notas substitui agir.

Resposta: Atlas pergunta qual acao real nasceu da organizacao. Sem acao, limitar sessao.

### 16.6 IA como autora do pensamento

Sintoma: Atlas escreve principios que Vitor nao assumiu.

Resposta: principios exigem ratificacao explicita e exemplo pessoal.

### 16.7 Obsidian como religiao

Sintoma: decisao tecnica vira apego a ferramenta.

Resposta: preservar tese, nao ferramenta.

---

## 17. Roadmap De Implementacao

### 17.1 V0 - Experimento de 30 dias

Objetivo: provar que conhecimento esquecido pode voltar em momento util.

Escopo:

- criar estrutura do vault;
- criar templates;
- indexar frontmatter;
- registrar `semantic_notes`;
- criar ativacao manual/semi-automatica;
- rodar 3 jogos cognitivos;
- curar 10 a 30 notas de qualidade;
- medir ativacoes uteis.

Sucesso:

- pelo menos 3 momentos em que Atlas trouxe conhecimento util esquecido;
- pelo menos 2 jogos cognitivos geraram nota ou decisao;
- Vitor nao sentiu curadoria como peso insustentavel.

### 17.2 V1 - Indexacao e recuperacao

Objetivo: tornar o vault pesquisavel por Atlas.

Escopo:

- watcher de arquivos;
- parser YAML;
- embeddings;
- busca semantica;
- links explicados;
- historico de ativacao.

### 17.3 V2 - Ativacao contextual

Objetivo: Atlas trazer notas com base em contexto real.

Escopo:

- gatilhos por calendario;
- gatilhos por captura;
- gatilhos por check-in;
- gatilhos por HealthKit;
- gatilhos por Rize;
- modo de assistencia;
- feedback de utilidade.

### 17.4 V3 - Hipoteses e pratica

Objetivo: transformar conhecimento em teste e competencia.

Escopo:

- hipoteses descendo para PostgreSQL;
- jogos cognitivos recorrentes;
- praticas com historico;
- metricas de transferencia;
- weekly review semantico.

### 17.5 V4 - Governanca madura

Objetivo: manter memoria viva por anos.

Escopo:

- vault health snapshots;
- arquivamento inteligente;
- promocao de notas;
- revisao de principios;
- deteccao de contradicoes;
- auditoria anual.

---

## 18. Criterios De Sucesso De Longo Prazo

Em 12 meses, a Memoria Semantica Ativa deve demonstrar:

- conhecimento de livros voltando em decisoes reais;
- principios pessoais sendo aplicados e revisados;
- hipoteses testadas contra dados;
- jogos cognitivos produzindo sinteses;
- reducao perceptivel de esquecimento operacional;
- Vitor usando Atlas como parceiro de pensamento, nao apenas registrador;
- vault com mais qualidade do que quantidade;
- intervencoes de Atlas aceitas por relevancia;
- capacidade de revisar evolucao do pensamento via Git e historico.

Em 5 anos, deve ser possivel perguntar:

> "Como meu pensamento sobre vendas, saude, decisao e identidade mudou desde 2026?"

E Atlas deve responder com notas, datas, decisoes, hipoteses, resultados e contradicoes.

Em 10 anos, a memoria deve funcionar como arquivo intelectual vivo de Vitor, capaz de sustentar continuidade de pensamento apesar de mudancas de ferramenta, modelos de IA e fases da vida.

---

## 19. Clausulas Constitucionais Propostas

Estas clausulas devem ser avaliadas no Annual Review de janeiro de 2027.

### 19.1 Clausula da Memoria Semantica

Atlas deve manter uma camada de memoria semantica externa, portavel e legivel por IA, separada da memoria episodica estruturada, destinada a preservar significado, modelos mentais, principios, hipoteses e identidade intelectual do operador.

### 19.2 Clausula de Recuperacao Contextual

Conhecimento salvo so cumpre sua funcao quando pode ser recuperado no momento de uso. Atlas deve priorizar mecanismos de ativacao contextual sobre acumulacao passiva.

### 19.3 Clausula de Curadoria Assistida

Atlas pode propor criacao, promocao, arquivamento e conexao de notas, mas conhecimento maduro exige ratificacao do operador. Draft automatico nao equivale a principio, modelo mental ou verdade validada.

### 19.4 Clausula de TDAH Como Requisito Arquitetural

Atlas deve considerar TDAH, memoria fraca, hiperfoco e abandono como restricoes de design, nao como falhas morais do operador. O sistema deve reduzir dependencia de lembranca voluntaria e aumentar recuperacao contextual de baixo custo.

### 19.5 Clausula de Portabilidade Cognitiva

O acervo semantico do operador deve permanecer exportavel, legivel e utilizavel fora de qualquer fornecedor especifico. Markdown, Git e formatos abertos sao preferidos por padrao.

---

## 20. Decisao Final

Memoria Semantica Ativa Compartilhada e uma das capacidades centrais do Atlas em estado maduro.

Ela nao substitui captura, HealthKit, Rize, Bitacula ou PostgreSQL. Ela os completa. O cerebro episodico registra a vida. O cerebro semantico preserva significado. O cerebro executivo decide quando trazer significado de volta. O cerebro de pratica transforma significado em competencia. O cerebro de governanca impede que tudo vire ruido.

O objetivo final nao e organizar conhecimento. O objetivo final e aumentar a capacidade de Vitor pensar, lembrar, aplicar, corrigir e amadurecer ao longo de decadas.

Frase raiz:

> **Atlas deve lembrar o que Vitor esqueceu, no momento em que lembrar muda a acao.**

Se uma implementacao futura nao servir a essa frase, ela desviou.
