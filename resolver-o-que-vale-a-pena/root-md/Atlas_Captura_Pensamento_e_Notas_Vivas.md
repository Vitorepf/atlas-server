> Cleanup status: human_vault_only.
> Canonical replacement: docs/engineering-knowledge-base/obsidian-atlas-vault.md; docs/engineering-knowledge-base/atlas-ai-governed-backlog.md.
> Cleanup note: Human Knowledge Surface source. Preserve for AtlasVault/Obsidian concepts; do not treat as runtime source.

# ATLAS

**Captura de Pensamento e Notas Vivas**

*Documento operacional definitivo do ciclo de captura, curadoria e ativação cognitiva*

---

| | |
|---|---|
| **Operador** | Vitor Emanuel |
| **Sistema** | Atlas |
| **Nome conceitual** | Captura de Pensamento e Notas Vivas |
| **Documento superior** | Atlas Documento Mestre v6.0 |
| **Documento irmão** | Memória Semântica Ativa Compartilhada |
| **Implementação primária** | App mobile + PostgreSQL + Whisper.cpp + Vault Markdown + IA |
| **Data** | 29 de abril de 2026 |
| **Status** | Documento canônico operacional |
| **Escopo** | Captura de pensamento, áudio, foto, texto, Inbox, curadoria, nota viva e ativação |

---

## Status Deste Documento

Este documento define a parte do Atlas responsável por transformar pensamentos frágeis em memória ativa, decisões melhores, ideias reutilizáveis, projetos, princípios testados e capacidade humana acumulada.

Ele tem autoridade operacional sobre qualquer decisão envolvendo:

- captura de pensamento;
- gravação e transcrição de áudio;
- captura textual ou fotográfica;
- Inbox;
- curadoria de capturas;
- promoção de capturas para notas vivas;
- ativação futura de conhecimento;
- agentes especializados ligados à captura;
- métricas de retorno cognitivo das notas.

Este documento não substitui a Constituição do Atlas. Ele a operacionaliza no domínio específico da captura de pensamento.

Em caso de conflito:

1. **Atlas Documento Mestre v6.0** prevalece em matéria constitucional.
2. **Este documento** prevalece em matéria de captura, Inbox, curadoria e notas vivas.
3. **Documentos técnicos de implementação** devem se ajustar a este documento.
4. **Documentos históricos V1/V3/V5** não devem comandar implementação quando divergirem do sistema atual.

---

## 1. Tese Central

Atlas não é um app de notas.

Atlas é um sistema de conversão de pensamentos frágeis em capacidade humana acumulada.

Uma captura de pensamento não existe para ser armazenada. Ela existe para preservar um fragmento mental antes que desapareça e permitir que, depois, esse fragmento seja aclarado, conectado, testado, ativado e aplicado.

> **Capturar pensamento é converter momentos mentais frágeis em objetos semânticos vivos.**

O valor final do Atlas não está em ter muitas capturas. Está em transformar algumas capturas certas em:

- decisões melhores;
- ideias reutilizáveis;
- princípios testados;
- projetos concretos;
- aprendizados retidos;
- perguntas mais fortes;
- autoconhecimento aplicável;
- padrões detectados;
- ações executadas no momento certo.

O Inbox é apenas a primeira estação. Ele não é conhecimento. Ele é a área de staging onde pensamento bruto aguarda destino.

---

## 2. Regra De Ouro

> **A IA captura, transcreve, organiza, pergunta, conecta e propõe. Vitor interpreta, valida, decide, ratifica e assume autoria.**

Atlas pode sugerir que uma captura virou ideia, hipótese, decisão, princípio ou projeto. Mas Atlas não deve tornar uma interpretação verdadeira sem validação humana quando houver julgamento, valor, estratégia, crença, identidade, relacionamento, saúde, finanças ou decisão relevante.

A IA deve reduzir fricção operacional e aumentar fricção cognitiva inteligente.

Reduzir fricção operacional significa:

- gravar rápido;
- transcrever;
- limpar ruído;
- sugerir título;
- separar ideias;
- encontrar conexões;
- recuperar contexto.

Aumentar fricção cognitiva inteligente significa perguntar:

- o que você quis dizer?
- qual é a tese real?
- isso é decisão, ideia, hipótese ou desabafo?
- onde isso pode ser falso?
- quando isso deve voltar?
- que ação concreta nasce disso?
- isso merece virar nota viva ou deve ficar arquivado?

---

## 3. O Ciclo C.A.P.T.A.R.

O ciclo oficial da captura de pensamento no Atlas é:

```text
Capturar -> Aclarar -> Promover -> Tecer -> Ativar -> Revisar
```

### 3.1 Capturar

Capturar é externalizar um pensamento antes que ele desapareça.

Princípios:

- deve acontecer em menos de 10 segundos;
- deve funcionar em áudio, texto, foto e futuramente outras modalidades;
- deve preservar horário, timezone, domínio, origem, estado técnico e contexto disponível;
- deve funcionar local-first quando possível;
- não deve exigir classificação pesada no momento da captura.

O objetivo da captura é preservar material bruto, não finalizar raciocínio.

### 3.2 Aclarar

Aclarar é transformar captura bruta em entendimento inicial.

Aclaramento responde:

- qual é a ideia principal?
- existe mais de uma ideia atômica?
- há uma tensão, dúvida ou pergunta?
- isso é ideia, decisão, hipótese, aprendizado, prática, princípio, projeto ou registro?
- há evidência ou contexto suficiente?
- vale voltar no futuro?
- que pergunta Vitor precisa responder antes de promover?

Aclarar não é ainda criar nota permanente. É preparar a captura para decisão humana.

### 3.3 Promover

Promover é decidir que uma captura merece virar objeto semântico.

Destinos possíveis:

- nota viva;
- hipótese;
- princípio;
- prática;
- modelo mental;
- decisão;
- projeto;
- tarefa;
- anexar a nota existente;
- arquivar;
- descartar;
- manter apenas como registro bruto.

Promoção relevante exige ratificação humana.

### 3.4 Tecer

Tecer é conectar a nova nota ao restante da memória.

Relações devem ser explicadas. Link sem explicação é ruído.

Tipos de relação:

- reforça;
- contradiz;
- exemplifica;
- aplica;
- deriva de;
- depende de;
- tensiona;
- substitui;
- atualiza;
- evidencia;
- gera projeto;
- gera prática.

### 3.5 Ativar

Ativar é fazer uma nota voltar quando ela se torna útil.

Uma nota viva não deve depender de Vitor lembrar de procurar por ela. Atlas deve reativar conhecimento quando houver contexto suficiente e baixa chance de ruído.

Ativações possíveis:

- lembrar;
- praticar;
- conectar;
- confrontar;
- testar;
- promover;
- revisar arquivo.

### 3.6 Revisar

Revisar é medir se a captura virou valor real.

Revisão responde:

- essa nota foi útil?
- gerou decisão melhor?
- gerou ação?
- voltou no momento certo?
- precisa amadurecer?
- deve virar princípio?
- deve ser arquivada?
- está obsoleta?

---

## 4. Unidades Do Sistema

### 4.1 Capture

`Capture` é o registro bruto de uma entrada mental ou perceptiva.

Pode ser:

- `audio`;
- `text`;
- `photo`;
- futuramente outros tipos.

Campos conceituais mínimos:

- identificador local e servidor;
- tipo;
- domínio;
- texto ou transcrição;
- arquivo original, quando existir;
- status de transcrição;
- data e hora de captura;
- timezone;
- localização, quando disponível;
- contexto digital anterior, quando disponível;
- metadados técnicos;
- origem;
- estado de curadoria;
- relação com proposta, nota ou objeto promovido.

Regra: uma captura é fonte primária. Mesmo quando vira nota viva, a origem bruta deve continuar rastreável, salvo exclusão deliberada.

### 4.2 Inbox Item

`Inbox Item` é a representação de uma captura bruta na interface.

Ele não é nota. Ele não é decisão. Ele não é conhecimento validado.

O Inbox deve mostrar:

- o que foi capturado;
- quando foi capturado;
- tipo;
- domínio;
- status local, sincronizado, transcrevendo, falhou ou pronto;
- ações de triagem.

### 4.3 Curation Proposal

`Curation Proposal` é uma sugestão estruturada feita por Atlas.

Ela responde:

- por que esta captura pode importar?
- que tipo de objeto ela pode virar?
- qual seria o título?
- qual é a tese?
- qual é a pergunta aberta?
- quais notas ou temas se relacionam?
- quando isso deveria voltar?
- que confirmação Vitor precisa dar?

Proposta não é verdade. Proposta é convite de decisão.

### 4.4 Semantic Note

`Semantic Note` é uma nota viva aprovada ou editada por Vitor.

Ela pertence ao cérebro semântico do Atlas.

Ela deve ter uso futuro, contexto, limites e maturidade.

### 4.5 Activation

`Activation` é o retorno contextual de uma nota viva.

Uma ativação só é boa quando aumenta a qualidade de uma decisão, prática, revisão, criação ou percepção.

### 4.6 Principle

`Principle` é uma nota testada, recorrente, útil e generalizável.

Princípio não nasce pronto. Ele amadurece a partir de capturas, experiências, ativações e revisões.

---

## 5. Contrato Do Inbox

O Inbox é a fila de capturas brutas.

Ele existe para reduzir perda de pensamento, não para virar arquivo permanente.

### 5.1 O Que O Inbox Deve Fazer

O Inbox deve:

- mostrar as capturas recentes;
- preservar capturas locais ainda não sincronizadas;
- mostrar status de áudio e transcrição;
- permitir edição de texto e domínio;
- permitir ouvir áudio original;
- permitir arquivar ou excluir;
- permitir promover para curadoria;
- deixar claro o que está pendente, pronto, falho ou já promovido.

### 5.2 O Que O Inbox Não Deve Ser

O Inbox não deve ser:

- memória semântica;
- lista infinita sem destino;
- métrica de produtividade;
- espaço onde tudo parece igualmente importante;
- substituto da revisão;
- prova de que Vitor pensou melhor.

### 5.3 Inbox Saudável

A meta não é `inbox zero` rígido.

A meta é `inbox saudável`.

Sinais de Inbox saudável:

- capturas recentes estão visíveis;
- capturas densas recebem proposta;
- capturas antigas têm destino;
- falhas de transcrição aparecem claramente;
- capturas locais não ficam presas sem sync;
- ideias valiosas não somem por serem curtas;
- ruído pode ser arquivado sem culpa.

Métricas úteis:

- idade média das capturas pendentes;
- capturas sem destino;
- capturas com transcrição falha;
- capturas promovidas;
- capturas arquivadas;
- capturas ativadas depois;
- capturas que geraram ação.

---

## 6. Contrato Do Áudio

Áudio é a modalidade premium de captura porque respeita o fluxo natural do pensamento.

Falar é mais rápido do que escrever, especialmente quando a ideia ainda está instável.

### 6.1 Fluxo Esperado

```text
Abrir captura -> gravar -> salvar local -> coletar contexto -> enfileirar sync -> enviar arquivo -> salvar no servidor -> criar job -> transcrever -> atualizar capture -> exibir no Inbox -> avaliar curadoria
```

### 6.2 Regras Do Áudio

- O áudio original deve ser preservado enquanto a captura existir.
- A transcrição é derivada, não fonte única.
- Falha de transcrição não deve apagar o áudio.
- A interface deve mostrar status de transcrição.
- O usuário deve poder ouvir o áudio original.
- A transcrição pode ser editada, mas a edição deve preservar rastreabilidade.
- Capturas curtas podem ser valiosas e não devem ser descartadas apenas por tamanho.
- A conclusão semântica não deve acontecer antes de o áudio ser transcrito ou validado.

### 6.3 Estados De Transcrição

Estados mínimos:

- `na`: não se aplica;
- `pending`: aguardando transcrição;
- `processing`: transcrevendo;
- `done`: transcrição disponível;
- `failed`: transcrição falhou.

Estados de produto derivados:

- `local_queued`: áudio existe só no aparelho;
- `syncing`: envio em andamento;
- `synced`: servidor recebeu;
- `transcribing`: worker processando;
- `transcribed`: texto pronto;
- `curation_candidate`: pode ser aclarado;
- `curation_proposed`: há proposta;
- `promoted`: virou objeto semântico;
- `archived`: saiu da fila ativa.

### 6.4 Exemplo Real

Captura:

> "Preciso de ideias de cruz para a Black Ink. Ideias que possam transformar a Black Ink em uma ferramenta única."

Leitura correta:

- tipo: áudio;
- domínio: BlackInk;
- estágio atual: captura bruta transcrita;
- valor potencial: ideação estratégica de produto;
- destino provável: proposta de nota ou projeto;
- pergunta de aclaramento: "que tipo de cruz: marca, símbolo, mecânica de produto, ritual, interface ou posicionamento?";
- possível nota viva: "Black Ink como ferramenta única: símbolos, rituais e diferenciação";
- possível ativação futura: quando Vitor estiver revisando roadmap, branding ou diferenciação da Black Ink.

Leitura incorreta:

- considerar que a transcrição já é uma ideia desenvolvida;
- criar automaticamente um princípio;
- gerar dezenas de ideias sem perguntar o critério;
- arquivar porque a captura é curta.

---

## 7. Captura Textual E Fotográfica

### 7.1 Texto

Texto é melhor para:

- pensamento já formulado;
- decisão curta;
- frase exata;
- insight técnico;
- pergunta;
- anotação rápida quando áudio não é possível.

O texto deve seguir o mesmo ciclo do áudio, exceto transcrição.

### 7.2 Foto

Foto é melhor para:

- quadro branco;
- papel;
- livro;
- ambiente;
- evidência visual;
- referência de design;
- objeto físico;
- contexto que seria lento descrever.

Foto deve gerar OCR ou descrição quando útil, mas o arquivo visual original deve permanecer como fonte primária.

---

## 8. Aclaramento Semântico

Aclaramento é a etapa que falta entre transcrição e conhecimento.

Sem aclaramento, Atlas vira apenas gravador com histórico.

### 8.1 Saída Mínima Do Aclaramento

Toda captura candidata deve poder gerar:

- resumo fiel;
- tese principal;
- ideias atômicas, se houver mais de uma;
- tensão ou pergunta;
- tipo sugerido;
- domínio;
- densidade;
- possível destino;
- nível de confiança;
- pergunta de autoria para Vitor;
- gatilhos futuros possíveis.

### 8.2 Pergunta De Autoria

Quando a captura envolver julgamento, decisão, valor, crença, projeto ou estratégia, Atlas deve perguntar antes de concluir.

Perguntas boas:

- "Qual é a tese que você quer preservar aqui?"
- "O que você quis dizer com isso?"
- "Qual parte merece voltar no futuro?"
- "Isso é ideia, decisão, hipótese ou tarefa?"
- "Qual critério separa uma ideia boa de uma ideia ruim aqui?"
- "O que tornaria essa ideia falsa?"

### 8.3 Capturas Curtas

Captura curta não significa baixa densidade.

Muitas ideias importantes nascem em uma frase.

O Atlas pode usar tamanho como sinal, mas não como filtro absoluto.

Sinais de densidade em captura curta:

- contém decisão;
- contém pergunta estratégica;
- contém tensão;
- contém nome de projeto;
- contém "preciso", "decidi", "percebi", "hipótese", "princípio", "não posso esquecer";
- conecta domínio importante;
- parece gerar ação.

---

## 9. Promoção Para Nota Viva

Uma captura só vira nota viva quando ganha forma de uso futuro.

### 9.1 Template Mínimo De Nota Viva

Toda nota viva criada a partir de captura deve buscar estes campos:

- título;
- tese;
- contexto;
- interpretação própria de Vitor;
- evidência ou origem;
- relações explicadas;
- quando usar;
- quando não usar;
- próxima ação;
- maturidade;
- fonte bruta;
- data de origem.

### 9.2 Maturidade

Estados de maturidade:

- `seed`: ideia inicial;
- `draft`: nota estruturada, ainda frágil;
- `useful`: já foi útil ao menos uma vez;
- `tested`: passou por aplicação ou confronto;
- `principle`: generalizável e confiável;
- `archived`: preservada, mas não ativa.

### 9.3 Critérios Para Promover

Promover quando a captura:

- pode informar decisão futura;
- contém ideia reutilizável;
- representa aprendizado;
- gera prática;
- articula princípio;
- abre hipótese testável;
- conecta áreas;
- revela padrão pessoal;
- cria projeto ou próximo passo;
- merece voltar em contexto específico.

Não promover quando:

- é ruído momentâneo;
- é apenas logística trivial;
- não há uso futuro claro;
- Vitor não consegue validar o significado;
- já existe nota suficiente;
- a captura é emocionalmente relevante, mas ainda precisa repousar antes de virar tese.

---

## 10. Tecer: Relações Explicadas

Atlas deve evitar grafo decorativo.

Uma relação entre notas precisa dizer por que existe.

Formato conceitual:

```text
Nota A --[tipo de relação + explicação]--> Nota B
```

Exemplos:

- "Esta hipótese contradiz o princípio X porque assume que velocidade importa mais que robustez."
- "Esta captura reforça o modelo mental Y porque mostra o mesmo padrão em BlackInk."
- "Esta prática aplica a nota Z em contexto de saúde."

Links sem explicação devem ser tratados como incompletos.

---

## 11. Ativação

Ativação é o coração da nota viva.

Uma nota que nunca volta é apenas arquivo.

### 11.1 Tipos De Ativação

- `remember`: relembrar algo relevante;
- `practice`: praticar ou recuperar ativamente;
- `connect`: conectar com situação atual;
- `confront`: desafiar incoerência ou padrão;
- `test`: transformar hipótese em experimento;
- `promote`: sugerir amadurecimento;
- `archive_review`: revisar nota fria.

### 11.2 Bons Momentos De Ativação

Atlas pode ativar notas:

- antes de reunião;
- durante revisão semanal;
- após captura relacionada;
- ao detectar padrão recorrente;
- quando projeto entra em foco;
- quando saúde/energia mudam;
- quando comportamento contradiz princípio declarado;
- quando uma decisão parece repetir erro antigo;
- quando uma captura nova conecta com nota antiga.

### 11.3 Critérios De Qualidade

Uma ativação boa é:

- rara o suficiente para ser respeitada;
- contextual;
- explicada;
- acionável;
- calibrada ao estado de Vitor;
- fácil de descartar;
- medida por utilidade posterior.

Ativação ruim é:

- genérica;
- frequente demais;
- moralizante;
- sem contexto;
- baseada em conexão fraca;
- impossível de agir.

---

## 12. Agentes Especializados

O pipeline de captura deve ser pensado como uma equipe de agentes especializados. Eles podem começar como prompts, serviços simples ou jobs internos, mas a responsabilidade conceitual deve ser clara.

### 12.1 Transcritor / Normalizador

Responsável por:

- transcrever áudio;
- aplicar OCR em imagem;
- limpar ruído sem alterar significado;
- preservar arquivo original;
- detectar idioma;
- marcar baixa confiança.

Não deve:

- interpretar intenção;
- criar tese própria;
- apagar ambiguidades importantes.

### 12.2 Aclarador

Responsável por:

- extrair tese;
- separar ideias atômicas;
- identificar tensão;
- gerar pergunta de autoria;
- estimar densidade;
- sugerir destino.

Não deve:

- promover sozinho capturas sensíveis;
- transformar dúvida em certeza.

### 12.3 Curador

Responsável por:

- gerar proposta de nota viva;
- sugerir título;
- preencher template mínimo;
- apontar lacunas;
- sugerir quando usar e quando não usar.

Não deve:

- criar memória ativa sem aprovação humana quando houver julgamento relevante.

### 12.4 Linker

Responsável por:

- buscar notas relacionadas;
- propor relações explicadas;
- identificar reforço, contraste, aplicação ou tensão.

Não deve:

- criar grafo automático sem explicação.

### 12.5 Adversário

Responsável por:

- testar hipótese;
- procurar contraexemplo;
- revelar suposição;
- reduzir excesso de confiança;
- proteger pensamento crítico.

Não deve:

- bloquear criação;
- virar cinismo automático.

### 12.6 Ativador

Responsável por:

- decidir quando uma nota deve voltar;
- explicar por que ela voltou;
- respeitar fadiga e contexto;
- pedir feedback de utilidade.

Não deve:

- ativar por volume;
- empurrar nota sem contexto.

### 12.7 Treinador

Responsável por:

- transformar nota em pergunta;
- gerar recall ativo;
- propor prática;
- revisar retenção;
- fortalecer transferência sem IA.

Não deve:

- substituir pensamento de Vitor por resposta pronta.

### 12.8 Governança

Responsável por:

- medir saúde do Inbox;
- medir saúde do vault;
- detectar notas frias;
- sugerir arquivo;
- preservar privacidade;
- reconciliar exclusão, soft delete e retenção.

---

## 13. Métrica Norte: Cognitive Return On Notes

A métrica principal não é número de capturas.

A métrica principal é retorno cognitivo.

`Cognitive Return on Notes` mede quantas capturas e notas produziram valor real.

Sinais de retorno:

- decisão melhor;
- ação tomada;
- projeto iniciado;
- ideia reutilizada;
- escrita melhor;
- aprendizado lembrado;
- prática repetida;
- padrão detectado;
- princípio testado;
- comportamento ajustado;
- erro evitado;
- conversa melhor conduzida.

Métricas secundárias:

- taxa de promoção;
- tempo entre captura e proposta;
- tempo entre proposta e aprovação;
- ativações úteis;
- notas sem gatilho;
- notas sem relação explicada;
- capturas antigas sem destino;
- capturas curtas promovidas;
- propostas recusadas;
- princípios derivados de experiência real;
- notas arquivadas por baixa utilidade.

---

## 14. Anti-Padrões

### 14.1 Inbox Infinito

Capturar muito sem curar transforma Atlas em depósito.

Correção: Inbox saudável, curadoria leve, ações de destino e métricas de idade.

### 14.2 Transcrição Passiva

Áudio virar texto não significa pensamento melhor.

Correção: aclaramento, pergunta de autoria e proposta.

### 14.3 IA Autopiloto

IA resumir, classificar e concluir sem Vitor.

Correção: IA propõe; Vitor ratifica.

### 14.4 Grafo Decorativo

Links automáticos sem explicação.

Correção: relação só entra com tipo e justificativa.

### 14.5 Métrica Errada

Celebrar capturas por dia.

Correção: medir retorno cognitivo, ativação útil e promoção real.

### 14.6 Promover Cedo Demais

Toda captura virar nota importante.

Correção: maturidade explícita e arquivo sem culpa.

### 14.7 Curadoria Pesada Demais

Revisão exige tanto esforço que o sistema morre.

Correção: perguntas pequenas, decisões rápidas e curadoria assistida.

### 14.8 Offloading Que Atrofia

Atlas guarda tudo e Vitor pensa menos.

Correção: recall ativo, adversário, autoria e transferência sem IA.

---

## 15. Privacidade, Retenção E Exclusão

Capturas podem conter pensamentos íntimos, dados de saúde, finanças, relacionamentos, localização e estratégia.

Regras:

- captura bruta deve ser rastreável;
- áudio original deve ser protegido;
- exclusão deve ser deliberada e respeitada;
- arquivar não é o mesmo que apagar;
- soft delete não substitui política de remoção física quando o operador pedir exclusão real;
- domínios sensíveis podem exigir regras específicas;
- IA externa não deve receber conteúdo sensível sem política explícita;
- Vitor deve poder saber quais dados originaram uma nota.

O princípio "preservar memória" não pode anular soberania do operador.

---

## 16. Estado Atual Da Implementação

Em 29 de abril de 2026, o Atlas implementa o ciclo P0-P5 como contrato operacional vivo:

- tela de captura premium com áudio, texto e foto no mesmo fluxo;
- seleção rápida de domínio e sensibilidade;
- normalização de privacidade por domínio e captura;
- armazenamento local-first;
- fila de sincronização resiliente;
- upload multipart para `/captures`;
- persistência em PostgreSQL;
- preservação de arquivo;
- job de transcrição;
- Whisper.cpp;
- retry de transcrição;
- verificação de integridade de arquivo;
- Inbox com capturas locais, servidor, status, filtros e saúde;
- edição de texto e domínio;
- infraestrutura de memória semântica;
- evento `capture_ready_for_curation`;
- agente Aclarador;
- propostas de curadoria;
- template de nota viva com ratificação humana;
- embeddings semânticos reais quando provedor estiver configurado;
- links explicados entre notas;
- ativações contextuais com fadiga;
- feedback de utilidade;
- vault Markdown;
- tela de memória;
- auditoria de propostas, ativações e IA;
- métricas de `Cognitive Return on Notes`.

O que continua deliberadamente fora do contrato atual:

- OCR/visão multimodal profunda para foto;
- política completa de exclusão física de arquivos;
- edição humana avançada de proposta antes da ratificação;
- importação histórica massiva;
- automação externa de tarefas/projetos fora do Atlas.

Portanto, a implementação atual deve ser entendida como:

```text
captura rápida + transcrição + Inbox profissional + aclaramento + curadoria viva + ativação + auditoria
```

O estado-alvo deste documento é:

```text
captura -> transcrição/OCR -> aclaramento -> proposta -> ratificação -> nota viva -> ativação -> feedback -> maturidade
```

### 16.1 Nota Sobre V1 Antiga

Qualquer referência antiga a `V1` que descreva `captures` como tabela genérica sem curadoria semântica está obsoleta para esta frente do produto.

O contrato atual é:

- `Capture` preserva fonte bruta;
- `capture_ready_for_curation` dispara aclaramento;
- `CurationProposal` é proposta de transformação;
- Vitor ratifica antes de virar `SemanticNote`;
- `SemanticNote` recebe links, gatilhos, ativações e feedback;
- auditoria explica por que Atlas sugeriu algo;
- privacidade determina escopo de processamento, especialmente uso de IA externa.

---

## 17. Roadmap Operacional

### 17.1 Fase 1: Fechar O Pipeline Bruto

Prioridades:

- status claro da captura;
- retry de transcrição;
- verificação de arquivo;
- limpeza de arquivos órfãos;
- captura textual real;
- captura fotográfica real;
- tags e metadados mínimos;
- política de exclusão.

### 17.2 Fase 2: Aclaramento

Prioridades:

- evento `capture_ready_for_curation`;
- análise semântica ao fim da transcrição;
- tese, tensão, pergunta e tipo;
- suporte a captura curta densa;
- pergunta de autoria.

### 17.3 Fase 3: Triagem No Inbox

Prioridades:

- promover para nota;
- anexar a nota existente;
- transformar em tarefa ou projeto;
- arquivar;
- adiar;
- descartar;
- mostrar proposta vinculada à captura.

### 17.4 Fase 4: Nota Viva Completa

Prioridades:

- template mínimo completo;
- `when_to_use`;
- `do_not_use_when`;
- interpretação própria;
- evidência;
- próxima ação;
- maturidade.

### 17.5 Fase 5: Tecer E Ativar

Prioridades:

- embeddings semânticos reais;
- relações explicadas;
- ativação por contexto;
- feedback de utilidade;
- fadiga de ativação;
- revisão semanal.

### 17.6 Fase 6: Métricas De Retorno Cognitivo

Prioridades:

- taxa de promoção;
- ativações úteis;
- capturas sem destino;
- notas sem gatilho;
- tempo até uso;
- princípios testados;
- projetos gerados por clusters;
- decisões melhoradas por notas.

### 17.7 Fase 7: Polimento Premium

Prioridades:

- captura sub-10s com áudio, texto e foto na mesma superfície;
- Inbox visualmente limpo com status, destino, privacidade e ação óbvios;
- Memória mostrando propostas, notas vivas, ativações, saúde do vault e CRON;
- logs de auditoria para explicar propostas, ativações e jobs de IA;
- privacidade por domínio e sensibilidade;
- documentação técnica sem ambiguidade com a V1 histórica.

---

## 18. Contrato De Implementação

Toda implementação futura ligada à captura deve respeitar estes contratos:

1. Captura deve ser rápida.
2. Captura deve ser preservada antes de ser interpretada.
3. Áudio original não deve ser descartado por causa da transcrição.
4. Inbox deve mostrar estado real, não esconder fila ou falha.
5. Transcrição não é conhecimento.
6. Aclaramento é etapa obrigatória antes de promoção relevante.
7. IA propõe; Vitor ratifica.
8. Captura curta pode ser densa.
9. Nota viva precisa de uso futuro.
10. Link sem explicação é incompleto.
11. Ativação é mais importante que armazenamento.
12. Métrica principal é retorno cognitivo, não volume.
13. Privacidade e soberania prevalecem sobre retenção.
14. Código deve refletir lifecycle explícito.
15. Documentação técnica deve citar este documento quando mexer em captura, Inbox ou notas vivas.

---

## 19. Glossário

**Captura bruta:** entrada original, ainda sem interpretação validada.

**Inbox:** fila visual de capturas brutas.

**Aclaramento:** análise inicial que extrai tese, tensão, tipo e pergunta.

**Proposta de curadoria:** sugestão estruturada de destino semântico.

**Nota viva:** nota aprovada, contextualizada, ativável e revisável.

**Ativação:** retorno contextual de uma nota quando ela pode ser útil.

**Maturidade:** estágio de evolução de uma nota, de semente a princípio.

**Cognitive Return on Notes:** valor cognitivo real gerado por capturas e notas.

**Autoria humana:** responsabilidade de Vitor sobre interpretação, decisão e significado.

**Offloading generativo:** descarregar memória para o sistema sem atrofiar pensamento, usando revisão, recall, confronto e prática.

---

## 20. Formulação Final

Atlas deve capturar pensamentos rápido o suficiente para não perdê-los, mas deve amadurecê-los devagar o suficiente para não falsificá-los.

O sistema ideal não é aquele que transforma toda fala em nota. É aquele que sabe preservar tudo, perguntar melhor, promover pouco, conectar com explicação, ativar no momento certo e medir se aquilo realmente aumentou a capacidade de Vitor.

> **O objetivo da captura de pensamento no Atlas é transformar fragmentos mentais em memória ativa, e memória ativa em capacidade humana real.**
