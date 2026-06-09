---
id: atlas-embodiment-08-roadmap-01-fase-0-espelho
type: engineering_knowledge
title: "Fase 0 — Espelho"
status: source_material
authority_class: design_reference
implementation_state: design_reference_no_runtime
category: physical-surface
summary: "Source material for the Atlas Embodiment/StackChan physical surface design; not current runtime authority."
canonical_owner: docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md
---
# Fase 0 — Espelho

> **Propósito:** especificar a **primeira fase implementável** do Embodiment. O StackChan é um **display passivo** do Atlas — sem interação, sem voz, sem cognição própria. Apenas reflete eventos do Evidence Ledger no display e LEDs.
>
> **Pré-requisitos:** [README](../README.md), [00-introducao/01-visao-geral.md](../00-introducao/01-visao-geral.md), [02-arquitetura/01-corpo-vs-alma.md](../02-arquitetura/01-corpo-vs-alma.md), [04-protocolos/01-interaction-envelope.md](../04-protocolos/01-interaction-envelope.md).
>
> **Fora do escopo:** voz, touch, NFC, qualquer interação ativa. Tudo isso vem em fases seguintes.

---

## 1. Por que começar com "Espelho"

### O risco que estamos mitigando

Pular direto para "voz + cognição" é a tentação do entusiasmo. Mas é onde 90% dos projetos falham — começa-se com a feature mais sexy, descobrem-se problemas estruturais, refatora-se tudo, projeto morre.

Espelho é proposital**mente** chato. É a fase onde provamos a **fundação invisível**:

1. Conexão estável corpo↔alma (WebSocket persistente, reconexão, telemetria).
2. Output Renderer físico do Atlas funcionando.
3. Evidence Ledger emitindo eventos consumíveis pelo robô.
4. Modo degradado existindo de verdade (não só promessa).
5. Configuração de Wi-Fi + credenciais minimalista.

Se a Fase 0 está sólida, Fases 1+ são cosméticas em cima dela. Se a Fase 0 está frágil, as demais herdam a fragilidade.

### O critério ácido

> **Se você ligar o StackChan e deixar na mesa por uma semana sem tocar nele, ele tem que mostrar coisas úteis e nunca travar.**

Esse é o teste. Não é demo de 5 minutos. É operação contínua com zero atenção do usuário.

---

## 2. Definição funcional

Ao final da Fase 0, o StackChan na mesa:

| Comportamento | Acontece quando |
|---|---|
| Display mostra "rosto" neutro do Atlas | Sempre que ligado e conectado |
| Display alterna com info-cards | Periódico (config — ex: a cada 60s) |
| Info-cards refletem dados reais do Atlas | Sempre que há dado novo |
| LEDs RGB indicam saúde do sistema | Continuamente |
| Servos fazem micro-movimentos idle | Aleatório (com baixa frequência para economia) |
| Robô reconecta sozinho se Wi-Fi cai | Automaticamente |
| Modo degradado é visível | Quando alma fica inacessível |
| Telemetria sai do corpo (battery, thermal) | A cada N segundos |

### O que info-cards mostram (exemplos)

- Próximo evento do calendário (se Atlas tem acesso a Personal Dev domain)
- Status do build atual (se Atlas tem evento ativo do Programming domain)
- Métrica do dia (commits, tarefas, tempo de foco)
- Última proposta do Curator (visualização — não aprovação ainda; isso é Fase 4)
- Hora atual em fonte grande (fallback quando não há nada melhor)
- Status do Atlas: "ouvindo", "processando", "ocioso"

### O que **NÃO** acontece nesta fase

- Sem captura de áudio (mic desabilitado).
- Sem detecção de presença (câmera desabilitada).
- Sem touch ativo (touch pads ignorados).
- Sem NFC (módulo desabilitado).
- Sem voz (TTS off; speaker apenas para sinais sonoros operacionais — boot, modo degradado).
- Sem aprovações (Curator proposals exibidas, mas só lidas via outro surface).

Essa restrição é deliberada: **Fase 0 é "alma fala, corpo escuta". Sem caminho de volta.**

---

## 3. Arquitetura mínima implementada

### 3.1 No corpo (firmware)

Componentes que precisam existir:

| Componente | Estado em Fase 0 |
|---|---|
| Boot + Wi-Fi connect | ✅ Implementado |
| WebSocket client persistente | ✅ Implementado |
| Telemetria (heartbeat a cada 30s) | ✅ Implementado |
| Output Renderer parcial: display + LED + servo idle | ✅ Implementado |
| Reflex L0 — animação idle, blink, micro-movimento | ✅ Implementado |
| Modo degradado — detecção e visualização | ✅ Implementado |
| Asset cache no microSD | ✅ Mínimo (apenas sprites de avatar) |
| Configuração via web ou microSD | ✅ Mínimo |
| Mic, câmera, touch, NFC | ⬜ Desabilitados (mas hardware presente) |
| TTS local | ⬜ N/A nesta fase |
| OTA | ⬜ Pode aguardar Fase 1 (deploy via cabo serve por enquanto) |

### 3.2 Na alma (Atlas backend)

Componentes que precisam existir:

| Componente | Estado em Fase 0 |
|---|---|
| Surface adapter `stackchan` registrado | ✅ |
| Output Renderer especializado (formata Decision Receipt em comandos físicos) | ✅ Mínimo: render de info-card e estado |
| Subscriber do Evidence Ledger → push para corpo | ✅ |
| Heartbeat L4: agenda info-cards | ✅ Mínimo |
| Tracking de surfaces conectadas (presença do corpo) | ✅ |
| `interruption_policy` | ⬜ Não necessário ainda (sem voz, sem interrupção) |
| Curator integration | ⬜ Lê proposals para mostrar; não aprova ainda |

### 3.3 Protocolo

Subset do `Interaction Envelope` ativado:

- `trigger.type = system` (boot, low_battery, thermal, connection_state)
- `trigger.type = scheduled` (gerado na alma)

Comandos físicos da alma para o corpo (definidos em `04-protocolos/03-comandos-fisicos.md` — escopo da Fase 0):

| Comando | Efeito |
|---|---|
| `display.show_face` | Renderiza face neutra com paleta indicada |
| `display.show_card` | Renderiza info-card (título + corpo + ícone) |
| `led.set_pattern` | Padrão de LED (cor + animação) |
| `servo.set_target` | Move pan/tilt para posição (com easing) |
| `servo.idle_pattern` | Inicia loop de micro-movimento |
| `system.degraded_mode` | Entra em modo degradado (LED roxo, face triste, sem cards) |

---

## 4. Critérios de aceitação

Cada critério tem que ser testável. Falha de qualquer um = fase não está pronta.

### 4.1 Conexão e estabilidade

- [ ] Boot até conectado: ≤ 15s em rede normal.
- [ ] WebSocket reconecta automaticamente após queda de Wi-Fi.
- [ ] Reconexão exponencial com backoff (1s, 2s, 4s, 8s, max 30s).
- [ ] Após 7 dias contínuos: sem crash, sem reboot involuntário, sem leak de memória visível.
- [ ] Heartbeat enviado a cada 30s ± 1s.
- [ ] Atlas detecta perda de heartbeat em ≤ 90s.

### 4.2 Modo degradado

- [ ] Atlas inacessível → corpo entra em modo degradado em ≤ 90s.
- [ ] Modo degradado: LED roxo respirando, face triste/neutra, sem info-cards.
- [ ] Reconexão sai do modo degradado em ≤ 5s após restabelecida.
- [ ] Se Wi-Fi cai mas RTC ainda válido: hora pode ser exibida em info-card de fallback.

### 4.3 Output Renderer

- [ ] Info-cards renderizam em ≤ 200ms após receber comando.
- [ ] Transições entre face neutra e info-card são suaves (fade ou slide curto).
- [ ] Display nunca fica em branco mais que 100ms (exceto durante boot).
- [ ] LED muda de cor sem flicker visível.
- [ ] Servo idle não chama atenção — micro-movimentos espaçados (>30s entre).

### 4.4 Telemetria

- [ ] `battery_pct`, `thermal_state`, `is_charging`, `current_mode` no heartbeat.
- [ ] Atlas registra heartbeat no Evidence Ledger (sample rate reduzido — não todos).
- [ ] `current_mode` reflete fielmente o estado: `ambient` em uso normal, `degraded` em queda.

### 4.5 Comportamento físico

- [ ] Em mesa real, 24h ligado: ruído audível < silêncio de fundo (servo barulhento = falha).
- [ ] Em ambiente escuro: brilho do display ajusta automaticamente.
- [ ] Em ambiente quente (>30°C): se thermal throttling acontece, modo de operação reduz frame rate de animação e mostra estado.

### 4.6 Configuração

- [ ] Wi-Fi configurável sem rebuild de firmware (microSD com `wifi.txt` ou portal AP no primeiro boot).
- [ ] Endpoint do Atlas configurável.
- [ ] Identificador do corpo (`surface.id`) configurável (default: random na primeira execução, persistido).

### 4.7 Auditoria

- [ ] Boot, conexão, perda de conexão, modo degradado, modo recuperado: todos registrados no Evidence Ledger.
- [ ] Logs locais no microSD (rotacional, <500KB) para diagnóstico de modo degradado.

### 4.8 Anti-critérios

Garantir que **não** acontece:

- [ ] Mic captando áudio.
- [ ] Câmera lendo frame.
- [ ] Touch disparando ação.
- [ ] NFC ativo.
- [ ] TTS reproduzindo.
- [ ] Qualquer comportamento "tipo Alexa".

Verificação: revisão de firmware antes de declarar fase pronta. Hardware desabilitado em código, não só por config.

---

## 5. Trabalho concreto envolvido

### 5.1 Hardware/firmware (corpo)

1. **Setup do projeto firmware** — PlatformIO + Arduino-ESP32 + M5Unified + StackChan-BSP, conforme `06-firmware-stackchan/01-escolha-stack.md`.
2. **Driver minimalista do hardware** — display, LED, servo via BSP e wrappers Atlas.
3. **WebSocket client** — implementação ou lib leve.
4. **Reflex L0** — loop de animação idle, blink, micro-movimento aleatório.
5. **Output Renderer** — receber comandos JSON e renderizar.
6. **Modo degradado** — detector + transição visual.
7. **Telemetria** — coletar e enviar heartbeat.
8. **Asset pipeline** — sprites de avatar pre-renderizados, copiados pro microSD.

### 5.2 Atlas backend (alma)

1. **Surface adapter `stackchan`** — registro de surface, autenticação, gestão de conexão.
2. **Output Renderer especializado** — formata Decision Receipts em comandos físicos.
3. **Heartbeat L4 mínimo** — agenda info-cards (todo dia 9h: bom dia card; etc.).
4. **Subscriber do Evidence Ledger** — para empurrar atualizações de info ao corpo.
5. **Tracking de presença do corpo** — sabe quando corpo conecta/desconecta.
6. **Endpoint de WebSocket** — público pra LAN ou via VPN, decisão em `04-protocolos/04-transporte.md`.

### 5.3 Documentação adicional necessária antes de codar

- `04-protocolos/03-comandos-fisicos.md` — definir todos os comandos físicos da Fase 0 com schema.
- `04-protocolos/04-transporte.md` — WebSocket details, autenticação, reconexão.
- `06-firmware-stackchan/01-escolha-stack.md` — decisão final de stack e uso do BSP oficial M5.
- `07-integracao-atlas/01-surface-adapter.md` — como o adapter se registra no Atlas.

---

## 6. O que esta fase prova / valida

Sucesso da Fase 0 valida:

| Hipótese | Como é validada |
|---|---|
| Separação corpo/alma funciona em prática | Corpo trocado mantém identidade; modo degradado é digno |
| Latência de rede não impede UX | Info-cards aparecem rápido, transições são suaves |
| WebSocket é transporte adequado | 7 dias contínuos sem instabilidade |
| Output Renderer físico é viável | Display + LED + servo coordenados a partir da alma |
| Evidence Ledger é fonte adequada | Eventos viram conteúdo de display sem retrabalho |
| Modo degradado é construtível | Corpo tem identidade visual de "desconectado" |
| Hardware aguenta operação contínua | Térmico OK, sem crash, sem leak |

Falha em qualquer hipótese = revisitar arquitetura, **não** seguir pra Fase 1.

---

## 7. Riscos da fase

### 7.1 Subestimar o tempo

Espelho parece simples. Não é. O grosso do trabalho é a **fundação invisível** — conexão, telemetria, modo degradado, reflex layer, asset pipeline. Estimativa razoável: 2-4 semanas de trabalho focado.

### 7.2 Cair em "vamos só adicionar X"

Tentação de adicionar voz/touch/etc "porque está ali". Resistir. Cada adição contamina os critérios de validação.

### 7.3 Ignorar modo degradado

Implementar só o caminho feliz. Depois descobrir que perda de Wi-Fi trava o robô. **Modo degradado é entregável da Fase 0**, não polish posterior.

### 7.4 Hardware barulhento

Servo girando frequente em micro-movimento idle pode incomodar. Calibrar **muito** conservadoramente. Em dúvida, menos movimento.

### 7.5 Asset pipeline frágil

Sprites de avatar precisam ser produzidos. Se isso virar bloqueio, simplificar (face com primitivas geométricas em vez de sprites — válido para Fase 0).

---

## 8. Saída da fase / entrada da Fase 1

A Fase 0 termina quando todos os critérios de aceitação estão satisfeitos **em uso contínuo de no mínimo 7 dias**.

Antes de iniciar Fase 1 (Voz):
- ✅ Critérios da Fase 0 satisfeitos.
- ✅ Documentação atualizada com lições aprendidas.
- ✅ ADR escrito caso alguma decisão arquitetural tenha sido revisitada.
- ✅ Decisões pendentes da Fase 1 resolvidas (wake word engine, STT/TTS local vs remoto, etc.).

---

## 9. Anti-padrões específicos desta fase

| Anti-padrão | Como evitar |
|---|---|
| "Vamos deixar o mic ligado mas só captura quando…" | Não. Mic desabilitado em código. |
| "Reaproveitar firmware factory, mudar só X" | Não. Firmware factory tem comportamentos não auditáveis. |
| "Modo degradado pode esperar" | Não. É critério de aceitação. |
| "Servo idle aleatório" | Não. Padrão calibrado, conservador, espaçado. |
| "Info-card formato livre" | Não. Schema definido em `03-comandos-fisicos.md`. |
| Validar com 1h de uso | Não. 7 dias contínuos. |

---

## 10. Resumo

**Objetivo:** StackChan reflete o Atlas, sem interagir.
**Duração estimada:** 2-4 semanas.
**Crítico de validar:** fundação invisível (conexão, degradação, telemetria, output renderer).
**Saída:** próxima fase (Voz) tem fundação confiável para construir em cima.
**Tom da fase:** disciplina sobre entusiasmo. Resista à tentação de adicionar features.

---

## Próximos passos de leitura

- `02-fase-1-voz.md` — quando Espelho está pronto, próxima fase.
- `04-protocolos/03-comandos-fisicos.md` — schema dos comandos da Fase 0.
- `06-firmware-stackchan/01-escolha-stack.md` — decisão de stack do firmware.
- `09-testes/01-criterios-aceitacao.md` — critérios formalizados em testes.
