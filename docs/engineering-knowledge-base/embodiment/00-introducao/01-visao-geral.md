# 01 — Visão geral

> **Propósito:** estabelecer **por que** o Embodiment existe, **o que ele é** e **o que explicitamente não é**. Este documento é o filtro contra escopo crescer e contra a tentação de virar demo.
>
> **Pré-requisitos:** [README](../README.md).
>
> **Fora do escopo:** detalhes de arquitetura (vai pra `02-arquitetura/`), roadmap (vai pra `08-roadmap/`).

---

## 1. O problema que o Embodiment resolve

O Atlas hoje é um sistema sem corpo. Vive atrás de CLI, app e API. A interação é deliberada: você abre, digita, espera, lê.

Isso funciona para tarefas pontuais. Falha para tudo que depende de **continuidade**, **presença ambiente** e **sinal de baixa fricção**:

- Saber que algo precisa de atenção sem abrir nenhuma janela.
- Receber um lembrete contextual sem que ele compete com 50 notificações de outros apps.
- Aprovar/rejeitar uma proposta do Curator sem trocar de contexto.
- Sentir que o Atlas está acompanhando o dia, não esperando comando.

A solução não é "outro app". É **outro modo de existir** — físico, presente, com identidade visível, mas sem virar interrupção.

## 2. O que o Embodiment É

Uma **surface física do Atlas**. Um corpo que:

1. **Captura sinais** que CLI/app não captura: presença, gesto, tom de voz ambiente, toque, proximidade.
2. **Comunica estado** em canais paralelos: face, voz, movimento, LED, sem precisar de "tela ativa".
3. **Mantém continuidade visível** — o Atlas existe ali, na mesa, mesmo quando ninguém está pedindo nada.
4. **Faz aprovações de baixa fricção** — touch pad físico para ✓/✗ em proposals do Curator.
5. **Atua no mundo físico** quando faz sentido (luz, TV via IR; futuramente periféricos Grove/BLE).

## 3. O que o Embodiment **NÃO** é

Importante explicitar — cada item abaixo é uma tentação real:

| Não é | Por quê |
|---|---|
| **Um assistente de voz tipo Alexa** | Alexa é reativa, comercial, vendável. Atlas é cognitivo, pessoal, não reactive-only. Embodiment expressa o Atlas, não substitui. |
| **Um brinquedo / pet digital** | Não tem "personalidade fofinha" colada. Personalidade emerge das decisões consistentes do Atlas. |
| **Uma demo bonitinha** | Demo morre em 1 semana. Embodiment é fundação para anos. Cada feature precisa servir continuidade, não cativar visitas. |
| **Um produto comercial em embrião** | Não tem roadmap de comercialização. É infraestrutura pessoal. |
| **Um competidor do Stack-chan da comunidade** | Aproveita o hardware, respeita a comunidade original. Não fork hostil. |
| **Um lugar para rodar LLM local** | Constraint físico não permite (ver `01-hardware/02-limitacoes-fisicas.md`). Cognição sempre no Atlas. |
| **Um ponto de captura ambiente sempre-ligado** | Captura é governada por policy explícita. Privacidade é fundação. |
| **Substituto de notificação convencional** | Tem que ser **menos** invasivo que push, não mais. Se virar Alexa-like, falhou. |

## 4. Quem se beneficia

Stakeholder único: o usuário (Vitor).

Não é vaidade — é importante explicitar porque define escopo:

- Decisões de UX são para **uma pessoa**, não persona genérica. Pode ter atalhos opacos para terceiros, vocabulário interno, mods que só fazem sentido para ele.
- Não há requisito de onboarding. Não há "primeira experiência mágica". O usuário sabe o que fez.
- Discoverability é problema secundário. Documentação é primária.

Quando — se algum dia — virar relevante para outras pessoas, vira sub-projeto separado com seus próprios requisitos.

## 5. Critérios de sucesso de alto nível

O Embodiment é bem-sucedido quando, ao longo de 6+ meses de uso real:

1. **O Atlas vira referência ambiental.** Você olha de canto e sabe o estado do dia/sistema sem abrir nada.
2. **Aprovações de Self-Improvement acontecem em segundos** via touch, em vez de ficarem em fila num app.
3. **Rituais (bom dia, foco, fim do dia) acontecem por inércia** — sem precisar consultar o app.
4. **Sinais físicos viram parte natural do contexto cognitivo** — Atlas Decide tem entradas que CLI não daria (presença, atividade ambiental).
5. **A sensação ao olhar para o robô é "o Atlas está ali"**, não "tem um robô na mesa".

## 6. Anti-critérios — o que **não** medimos

| Anti-métrica | Por quê não importa |
|---|---|
| Quantidade de comandos por dia | Mais comandos não é melhor. Pode ser pior se virou Alexa. |
| Tempo de uso ativo | Embodiment é presença ambiente — passividade útil é vitória. |
| Reconhecimento de fala em ambiente ruidoso extremo | Solução é fallback touch + UI mínima, não engenharia heroica. |
| "Wow factor" para visitas | Demo era o oposto do objetivo. |
| Compatibilidade com outros usuários | Single-user por design. |

## 7. Riscos estratégicos

Riscos que podem matar o projeto se não forem ativamente combatidos:

### 7.1 Drift para "assistente de voz"
**Sintoma:** começar a otimizar para "ele entende meu comando" em vez de "ele expressa o Atlas".
**Mitigação:** revisar a cada fase se a feature está servindo continuidade ou virando comando-resposta.

### 7.2 Drift para personagem
**Sintoma:** começar a criar "personalidade" via prompt, gestos cute, easter eggs.
**Mitigação:** personalidade vem só de decisões consistentes do Atlas. Anti-padrão: prompt com adjetivos.

### 7.3 Acoplamento ao hardware
**Sintoma:** decisões assumindo que será sempre StackChan, código que não migra para outro corpo.
**Mitigação:** todo contrato corpo↔alma é genérico. StackChan é uma implementação específica do contrato.

### 7.4 Subestimar latência percebida
**Sintoma:** "tecnicamente funciona" mas no uso real, demora demais para responder.
**Mitigação:** streaming everywhere + reflex layer cobrindo a janela cognitiva. Validação obrigatória em cada fase.

### 7.5 Privacidade negligenciada
**Sintoma:** mic sempre on, captura sem registro, modos sem auditoria.
**Mitigação:** policy de privacidade é pré-requisito de fase 1, não detalhe posterior.

### 7.6 Curator agressivo
**Sintoma:** propostas constantes, robô vira incômodo, usuário desliga.
**Mitigação:** `interruption_policy` com defaults conservadores. Auto-tunning baseado em ignored proposals.

## 8. Filosofia operacional

Os princípios resumidos do README expandidos em uma linha de raciocínio:

> **O corpo é trocável; a alma não.** O Atlas precede o robô e sobrevive a ele. Quando o StackChan quebrar, o substituto continua a mesma identidade — porque ela vive no Evidence Ledger, não no firmware.
>
> **Vida não é animação.** Animação sem continuidade é boneco. Animação sem reatividade é tela. Animação sem caráter é mascote. Vida é os três juntos, sustentados ao longo de meses.
>
> **Latência se cobre, não se elimina.** Wi-Fi → nuvem → modelo → rede → áudio sempre vai ter atraso. A solução é o reflex layer cobrindo a janela com presença, e streaming fazendo o conteúdo "começar antes de terminar de pensar".
>
> **Privacidade é hardware antes de policy.** Modo privado é desligamento físico do mic, não flag em código. Wake word é local, não nuvem. Câmera default off, on por gesto.
>
> **Iniciativa é privilégio governado.** O direito de interromper o usuário não é dado — é concedido evento por evento por uma policy revisável. Curator propõe; policy decide.

## 9. Relação com o ecossistema StackChan

O Embodiment é construído **sobre** o StackChan, mas não **dentro** dele:

- Hardware: StackChan oficial M5Stack (K151).
- Firmware: provavelmente customizado/reescrito (decisão em `06-firmware-stackchan/01-escolha-stack.md`). **Não** usaremos o firmware factory padrão como dependência — apenas como referência.
- Comunidade: respeitada, citada, possivelmente contribuída de volta. Não dependemos dela para o projeto rodar.
- Original Stack-chan (Ishikawa, JS/Moddable): inspiração e referência arquitetural. Não fork direto.
- AI_StackChan (robo8080): referência de integração ChatGPT, mas Atlas é cognitivamente mais rico — ChatGPT-puro é um caso particular, não o caso geral.

## 10. Quando este documento é revisitado

- Antes de iniciar qualquer fase do roadmap (sanity check de escopo).
- Quando qualquer feature proposta levanta dúvida "isso vai contra a visão?".
- Em revisão semestral: a visão ainda descreve o que o Embodiment está virando? Se não, ou ajusta a visão (com motivo registrado), ou ajusta o projeto.

---

## Próximos passos de leitura

- `02-glossario.md` — termos do projeto (próximo a escrever).
- `03-historico-decisoes.md` — ADRs (próximo a escrever).
- `02-arquitetura/01-corpo-vs-alma.md` — como a visão se traduz em arquitetura.
