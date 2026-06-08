# 01 — Corpo vs Alma

> **Propósito:** estabelecer a separação fundamental do Embodiment — o que vive no robô (corpo) e o que vive no Atlas (alma). Esta separação é a decisão arquitetural mais importante do projeto. Tudo abaixo dela depende dela.
>
> **Pré-requisitos:** [README](../README.md), [00-introducao/01-visao-geral.md](../00-introducao/01-visao-geral.md), [01-hardware/02-limitacoes-fisicas.md](../01-hardware/02-limitacoes-fisicas.md).
>
> **Fora do escopo:** detalhes de protocolo (vai pra `04-protocolos/`), implementação de firmware (vai pra `06-firmware-stackchan/`).

---

## 1. A pergunta que esta separação responde

> *"Se o robô quebrar amanhã e eu comprar outro, o Atlas continua o mesmo Atlas?"*

Se a resposta é **sim**, a arquitetura está certa. Se é **não**, há algo crítico vivendo no lugar errado.

Toda decisão deste documento é justificada por essa pergunta.

---

## 2. Definição

```
┌─────────────────────────────┐         ┌──────────────────────────────────┐
│         CORPO               │         │            ALMA                  │
│   (StackChan / hardware)    │ ◄────►  │      (Atlas / kernel)            │
│                             │  WS/    │                                  │
│   Efêmero, trocável         │  MQTT   │   Persistente, único             │
│   Sem identidade própria    │         │   Identidade vive aqui           │
│                             │         │                                  │
│   • Captura sinais          │         │   • Decide                       │
│   • Renderiza saídas        │         │   • Lembra                       │
│   • Reage em ms (reflex)    │         │   • Aprende                      │
│   • NUNCA decide cognição   │         │   • Audita                       │
│   • Tem políticas locais    │         │   • Define policies              │
│     mínimas (mute, panic)   │         │                                  │
└─────────────────────────────┘         └──────────────────────────────────┘
       Hardware específico                  Independente de hardware
```

### O corpo

É o **conjunto de capacidades físicas** disponíveis para o Atlas se manifestar no mundo real:

- Sensores de captura (mic, câmera, IMU, touch, NFC, proximity)
- Atuadores de saída (display, voz, servos, LEDs, IR)
- Conectividade (Wi-Fi, BLE, USB, Grove)
- Reflex layer — comportamento mínimo independente, sem cognição
- Políticas locais de proteção (modo privado físico, panic mute)

O corpo **não tem identidade**. Não tem memória de longo prazo. Não tem preferências. Não acumula histórico relacional. Liga, conecta na alma, recebe instruções, executa, registra, desliga.

### A alma

É o **Atlas Kernel completo**:

- Surface Layer (com adapter para o corpo)
- Operation Envelope, Intent/Routing, Atlas Decide, Decision Receipt
- Domain Plane (Programming, Finance, Personal Dev, Marketing, Self-Improvement)
- Context Builder, Policy/Profile, Runtime/Executor
- Quality Gates, Repair Loop, Output Renderer
- Evidence Ledger (memória de longo prazo, identidade, relacionamento)
- Learning/Memory Signals, Curator
- Human Knowledge Surface (AtlasVault/Obsidian)

A alma **tem identidade persistente**. Lembra. Aprende. Acumula. É a coisa que importa.

---

## 3. O que vive onde — tabela definitiva

### Vive no corpo (StackChan)

| Item | Por quê fica aqui |
|---|---|
| Drivers de hardware | Não tem alternativa |
| Animações idle (blink, micro-movimento) | Latência impede que venham da nuvem |
| Wake word offline | Privacidade — áudio não sai antes de ativação |
| Streaming de áudio para nuvem | Pipe direto sensor→rede |
| LEDs de status do pipeline | Estado é informação, mas renderização é local |
| Animações faciais | Sprites pré-projetados; estado vem da alma |
| Reflex sonoros (~100ms) | Cobre janela cognitiva enquanto Atlas pensa |
| Modo privado físico (mute hardware) | Tem que funcionar mesmo com Atlas inacessível |
| Panic stop (touch combinado para "pare tudo") | Garantia local não-negociável |
| Cache de assets (sprites, samples curtos) | Performance |
| Configuração de Wi-Fi/credenciais | Só fica aqui o necessário pra conectar na alma |
| Logs locais rotacionais (<500KB) | Diagnóstico de modo degradado |

### Vive na alma (Atlas)

| Item | Por quê fica aqui |
|---|---|
| Decisões cognitivas | Princípio: corpo não decide |
| Identidade do Atlas | Pergunta original do documento |
| Memória relacional ("já te disse X há 3 dias") | Sobrevive a troca de hardware |
| Histórico completo de interações | Evidence Ledger — fonte da verdade |
| Estado emocional / afetivo agregado | Derivação do Ledger |
| Mapa Domain → expressão facial | Configuração da alma; corpo só renderiza |
| Vocabulário-assinatura do Atlas | Personalidade vive aqui |
| Policy/Profile (privacidade, autonomia, custo) | Auditável e versionável |
| Curator e suas proposals | Auto-melhoria não pode estar no corpo |
| Quality Gates | Auditoria centralizada |
| Calendário de rituais (heartbeat) | Disparado pela alma; corpo recebe e renderiza |
| Configuração de personalidade vocal (voz, cadência) | Ledger |
| Mapeamento NFC tag → fluxo | Tabela na alma; corpo lê tag e reporta UID |
| Voice/Persona Adapter do StackChan | **Alma** | Preserva o jeito do StackChan sem deixar o corpo decidir |
| TTS local/voz sonora padrão | **Corpo ou Alma, por capability** | Se for local, corpo só toca; se for cloud, Atlas chama provider e envia stream |

### Casos ambíguos — decisões explícitas

| Item | Onde fica | Por quê |
|---|---|---|
| Brilho automático do display | **Corpo** | Sensor de luz → ajuste é loop fechado de baixo nível |
| Volume base do speaker | **Alma** | Configuração; mas modo "abaixar agora" pode ser local |
| Decisão de virar pra fonte de som (head tracking) | **Corpo** | Reflex; mas pode ser desabilitado por policy da alma |
| Detecção de presença (binária) | **Corpo** | Sinal local; envia evento para alma |
| Identificação de quem está presente | **Alma** | Se um dia for implementada — decisão cognitiva |
| Animação de "pensando" enquanto LLM responde | **Corpo** | Reflex; alma manda sinal "thinking_started" e "thinking_done" |
| Gesto de "olhei pro display ao mostrar info" | **Corpo** | Reflex de movimento; alma comanda o conteúdo do display |
| Calibração de servos | **Corpo** | Hardware-specific |
| Calibração de personalidade (tom, ritmo) | **Alma** | Identidade |
| Modelos "grátis" do app M5/Xiaozhi | **Alma, como provider opcional** | Firmware nunca escolhe modelo; Atlas Policy Router decide se pode usar |

### 3.1 Decisão específica: preservar o jeito StackChan

O StackChan tem valor de produto porque já possui uma presença própria: voz, rosto, cadência, expressões e reação física. Preservar isso **não** significa manter o cérebro M5/Xiaozhi. A separação correta é:

```text
StackChan preserva:
  voz sonora, face, expressões, mouth sync, reflexos, timing físico

Atlas preserva:
  raciocínio, memória, ferramentas, providers, política, receipts, auditoria

Bridge preserva a fronteira:
  traduz entrada/saída sem criar cognição paralela
```

O mecanismo canônico fica em [07-integracao-atlas/06-stackchan-bridge-persona.md](../07-integracao-atlas/06-stackchan-bridge-persona.md).

---

## 4. Implicações práticas

### 4.1 Cenário: o robô quebra

- Compra outro StackChan.
- Flasha o firmware do Embodiment (mesmo binário, qualquer unidade).
- Configura Wi-Fi e credencial de conexão à alma.
- **Acaba.** Identidade volta intacta. Histórico, preferências, rituais, vocabulário — tudo no Ledger.
- Único "luto": calibração específica de servos do hardware antigo (microsegundos de personalidade física que se foram). Negligenciável.

### 4.2 Cenário: o Atlas backend quebra

- Robô detecta perda de conexão dentro de N segundos.
- Entra em modo degradado **explícito**: LED roxo, face triste/neutra, voz declara "estou desconectado".
- Reflex layer continua: responde a presença com micro-movimento, aceita touch para "modo silencioso", mostra hora se microSD tem assets.
- **NÃO improvisa cognição.** Não tenta "responder com o que tem". Silêncio digno é melhor que invenção.
- Quando alma volta, sincroniza: sem replay automático de eventos perdidos (geram entrada no Ledger como "robô esteve offline de X até Y", e Curator pode revisar se algo precisava resposta).

### 4.3 Cenário: existem 2 corpos

Hipotético. Um StackChan na mesa de casa, outro no escritório. Ambos conectados ao mesmo Atlas:

- **Mesma alma, mesma identidade.** Ambos são "o Atlas".
- Coordenação fica na alma — `Surface` registry sabe que existem 2 corpos. Decision Receipt pode roteamento por proximidade ou explicitamente ("fala no robô do escritório").
- Cada corpo tem reflex layer próprio. Estado de alto nível (ritual de bom dia) acontece em qual? Decisão da alma baseada em presença detectada.

Não é objetivo da fase inicial — mas a arquitetura **não pode impedir**. Por isso o protocolo entre corpo e alma identifica `surface_id` único por corpo.

### 4.4 Cenário: alma evolui, corpo fica

- Atualizar firmware do StackChan: ocasional, planejado.
- Atualizar Atlas backend: contínuo, frequente.
- Contrato entre os dois (interaction_envelope, comandos físicos) é **versionado**. Corpo declara qual versão suporta; alma adapta ou rejeita.

---

## 5. O risco principal: vazamento de cognição para o corpo

A tentação é real. Vai surgir frequente. Sintomas:

- "Vamos colocar uma resposta hardcoded para 'que horas são?'"
- "Que tal o robô lembrar das últimas 3 mensagens caso a alma caia?"
- "Vamos cachear personalidade no corpo pra latência ficar menor."
- "E se ele responder a saudação localmente sem chamar o Atlas?"

**Cada um desses é cognição vazando para o corpo.** Cada um, individualmente, parece pequeno. Junto, viram uma alma paralela no firmware — incompleta, divergente, não-auditável.

### Regra dura

> **Se a resposta depende de conhecimento sobre o usuário, sobre o histórico, sobre preferências ou sobre o estado do sistema cognitivo, ela vem da alma. Sempre.**

Resposta a "que horas são?" é um caso interessante:
- Se for puro relógio (hora local): **pode** ser corpo, porque RTC é hardware. Mas precisa registrar evento "user asked time" para a alma.
- Se for "que horas são? estou atrasado pra reunião X" — alma. Cognição entrou.

Fronteira: a resposta depende **só de hardware** ou de **estado**? Hardware = corpo. Estado = alma.

---

## 6. Por que essa separação é o certo

Alternativas que rejeitamos e por quê:

### Alternativa A: alma roda no robô (autonomia total)
- ❌ Hardware não suporta LLM.
- ❌ Atualização é OTA pesado por unidade.
- ❌ Privacy mais difícil — credenciais de provider no firmware.
- ❌ Backup/replay do Ledger no microSD: lento, frágil.
- ❌ Múltiplos corpos seriam múltiplas almas divergentes.

### Alternativa B: tudo na nuvem, robô é só microfone+speaker
- ❌ Sem reflex layer = robô parece morto entre comandos.
- ❌ Latência mata UX.
- ❌ Privacy pior — todo áudio sempre vai pra nuvem.
- ❌ Modo offline é zero.

### Alternativa C: alma híbrida (parte no robô, parte na nuvem)
- ❌ Coordenação fica complexa, divergência inevitável.
- ❌ Auditoria fragmentada.
- ❌ Migração entre corpos quebra (qual parte da alma migra?).

### Nossa escolha: corpo fino + alma centralizada
- ✅ Hardware é trocável, identidade preservada.
- ✅ Cognição auditável num lugar só.
- ✅ Privacy explícita: o que sai do corpo, sai com motivo.
- ✅ Múltiplos corpos triviais (no futuro).
- ✅ Latência mitigada por reflex local + streaming.
- 🟡 Trade-off aceito: dependência forte de Wi-Fi/Atlas online.

---

## 7. Contratos de fronteira

A fronteira corpo↔alma é atravessada por **3 tipos de mensagem**:

```
CORPO ──────────────► ALMA   (sinais)
   1. Interaction Envelope
      (eventos de sensor virando intents)
   2. Heartbeat / Telemetry
      (estado do hardware — bateria, térmica, presença, conectividade)

ALMA ──────────────► CORPO   (comandos)
   3. Decision Receipt → Output Commands
      (instruções para renderers físicos)
```

Detalhes em `04-protocolos/01-interaction-envelope.md`, `04-protocolos/03-comandos-fisicos.md`.

Princípios da fronteira:
- **Corpo nunca envia decisão.** Só sinais e telemetria.
- **Alma nunca envia raciocínio.** Só comandos finais para atuação.
- Tudo atravessa o Atlas Kernel Pipeline. Sem atalhos.
- Tudo é registrado no Evidence Ledger.

---

## 8. Anti-padrões a evitar

| Anti-padrão | Sintoma | Correção |
|---|---|---|
| **Estado de personalidade no firmware** | Robô "lembra" sem alma | Mover para Ledger |
| **Resposta hardcoded local** | "Para latência" — atende sem consultar alma | Cache na alma com TTL, não no corpo |
| **Lógica de domain no corpo** | Reflex que conhece "Programming Domain" | Reflex é cego ao domain — só recebe sinal |
| **Credentials de provider no robô** | API key de OpenAI no microSD | Robô só fala com alma; alma fala com providers |
| **Configuração crítica fora do Ledger** | Mudar voz pelo touch sem registro | Mudança vira evento, vira proposal, vira approval |
| **Replay de Ledger no robô** | "Ele guarda os últimos N eventos pra modo offline" | Modo offline é digno e silencioso, não Ledger fragmentado |
| **Modelo gratuito direto no firmware** | StackChan chama Qwen/DeepSeek/Xiaozhi sem Atlas | Provider routing fica no Atlas; firmware só recebe comandos/áudio |
| **Persona duplicada no prompt local** | StackChan responde com jeito próprio fora do Ledger | Persona Adapter na alma, versionado e auditável |

---

## 9. Resumo em 3 frases

1. **Corpo é capacidade física + reflex; nada cognitivo, nada relacional, nada persistente.**
2. **Alma é Atlas inteiro; identidade vive no Evidence Ledger e sobrevive a qualquer corpo.**
3. **A fronteira é atravessada por sinais (corpo→alma) e comandos (alma→corpo); nunca por raciocínio em nenhuma direção.**

---

## Próximos passos de leitura

- `02-loops-temporais.md` — como reflex (corpo) e cognição (alma) coexistem em latências diferentes.
- `03-modalidades.md` — quais canais atravessam a fronteira.
- `04-integracao-kernel.md` — onde no Atlas Kernel a Surface "stackchan" se registra.
- `04-protocolos/01-interaction-envelope.md` — o contrato concreto.
