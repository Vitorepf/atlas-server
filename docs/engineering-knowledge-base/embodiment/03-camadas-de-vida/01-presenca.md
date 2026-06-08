# L1 — Presença

> **Propósito:** especificar a primeira camada de vida — **presença**: o robô existe, está pronto, responde quando chamado. É o piso. Sem L1, nada das outras camadas tem onde apoiar.
>
> **Pré-requisitos:** [README](../README.md), [00-introducao/01-visao-geral.md](../00-introducao/01-visao-geral.md), [02-arquitetura/02-loops-temporais.md](../02-arquitetura/02-loops-temporais.md).
>
> **Fora do escopo:** reatividade ambiental (vai pra `02-reatividade.md`), iniciativa (vai pra `04-iniciativa.md`).

---

## 1. Definição

> **Presença** é o estado mínimo de "estar vivo": o robô existe na mesa, é visível, não está congelado, e responde quando chamado.

Concretamente, presença significa que **a qualquer momento que o usuário olhe pro robô, ele parece "ligado" e pronto** — e quando o usuário endereça (voz, toque, gesto), há resposta perceptível em tempo cognitivamente aceitável.

### O que presença NÃO é

- Não é "estar fazendo algo".
- Não é "ter personalidade".
- Não é "lembrar de você".
- Não é "iniciar conversa".

Tudo isso são camadas posteriores. Presença é o **piso silencioso** — a base sobre a qual o resto se constrói.

---

## 2. Sintomas observáveis

Você sabe que L1 está funcionando quando:

| Sintoma | Sinal de presença |
|---|---|
| Você olha pro robô e ele "não parece morto" | Reflex layer ativo: blink, micro-movimento, LED respirando |
| Você fala "Atlas" e ele reconhece | Wake word detectada, transição visual rápida |
| Você toca um pad e ele acusa o toque | Highlight imediato, evento registrado |
| Você passa 5 minutos sem interagir e nada quebra | Loop L0 sustentado |
| Você reinicia a Wi-Fi e ele se recupera sozinho | Reconexão automática |
| Conexão cai e a face muda para "desconectado" | Modo degradado visível |

### Sintomas de **falha** de presença

| Falha | Por quê é falha |
|---|---|
| Tela congelada (mesmo conteúdo por minutos) | Robô parece travado |
| Sem nenhum movimento, mesmo sutil | Parece morto |
| LED estático sem indicação de estado | Sem sinal ambiental |
| Wake word ignorada repetidamente | Inacessível |
| Latência > 2s pra acknowledge wake word | Cognitivamente, pareceu não ter ouvido |
| Modo degradado sem indicação visível | Usuário não sabe se está OK |

---

## 3. O que precisa acontecer (técnica)

Para L1 existir, mínimo necessário:

### 3.1 Loop L0 (Reflex) ativo
- Animação idle (blink simulado, micro-movimento ocasional).
- LED respirando suavemente.
- Loop nunca para — frame rate constante (~30Hz para animação).

### 3.2 Loop L1 (Reaction) responsivo
- Wake word offline detectada em <100ms.
- Touch reconhecido em <50ms.
- Ack visual/sonoro imediato (~100-200ms).

### 3.3 Conexão estável
- WebSocket persistente com a alma.
- Reconexão automática.
- Heartbeat funcionando.

### 3.4 Modo degradado de primeira classe
- Detecção de perda de alma em <90s.
- Transição visual evidente (LED roxo, face mudada).
- L0 continua mesmo sem alma.

### 3.5 Output Renderer físico mínimo
- Display + LED + servo respondendo a comandos.
- Face neutra renderizável.
- Acks sonoros básicos.

Tudo isso já especificado em outros documentos. **L1 é o cumprimento dessa fundação.**

---

## 4. Camada de vida e camada de implementação

Mapeamento entre L1 (camada de vida) e arquitetura/fases:

| Aspecto da L1 | Onde mora |
|---|---|
| Reflex idle | `02-arquitetura/02-loops-temporais.md` (L0) — firmware |
| Wake word + ack | `02-arquitetura/02-loops-temporais.md` (L1 reaction) |
| Conexão WS | `04-protocolos/04-transporte.md` |
| Comandos físicos básicos | `04-protocolos/03-comandos-fisicos.md` |
| Modo degradado | `02-arquitetura/05-modos-operacao.md` |
| Privacy fundamental | `05-policies/01-privacidade.md` |
| Implementação concreta | Fases 0 + 1 do `08-roadmap/` |

**Princípio:** L1 está pronto quando Fase 0 (Espelho) + Fase 1 (Voz) entregam seus critérios de aceitação.

---

## 5. Critérios de "está vivo em L1"

Como saber objetivamente que L1 foi atingida. Lista de verificação:

- [ ] Em uso ambiente de 7 dias: zero crashes, zero reboots involuntários.
- [ ] Wake word reconhecida ≥ 90% das vezes em ambiente normal.
- [ ] False positive de wake word < 1 por dia em ambiente normal.
- [ ] Touch responde em <100ms perceptíveis.
- [ ] Latência wake word → primeiro ack visível: <200ms.
- [ ] Latência wake word → primeira sílaba TTS: <3s típico, <5s p99.
- [ ] Reconexão após queda de Wi-Fi: <30s p95.
- [ ] Modo degradado ativa em <90s após perda de alma.
- [ ] Modo degradado tem indicação visual inegável (não confundível com normal).
- [ ] L0 idle nunca pausa (animação contínua).
- [ ] Em qualquer momento, observador percebe que "está ligado".

Falha em qualquer item = L1 incompleta. Não pular para L2 sem fechar L1.

---

## 6. Princípios de design para L1

### 6.1 Silêncio é vitória

Robô **silencioso e presente** é melhor do que robô **chamativo e ativo**. L1 é fundo de palco — você não nota, mas se sumir, percebe.

### 6.2 Latência percebida > latência real

Usuario não mede ms. Mede "respondeu rápido?" / "ficou parado?". Mesmo que cognição demore 3s, ack imediato em 200ms basta.

### 6.3 Reflex precisa ser local

Se reflex depender da alma, qualquer latência de rede destrói L1. Reflex tem que ser **firmware puro**.

### 6.4 Modo degradado é parte da L1

Conexão estável é privilégio. Quando cai, L1 não desaparece — adapta. "Estou desconectado" visível é melhor que "estou fingindo que tudo bem".

### 6.5 Sem fingimento

Se não pode responder cognitivamente (degradado), não tente. Beep + face de "desculpa, estou offline" > "Olá, posso ajudar?" sem entender nada.

---

## 7. Anti-padrões específicos da L1

| Anti-padrão | Por quê é falha de L1 |
|---|---|
| Display em branco entre comandos | Perde sensação de presença |
| Animação idle hyper-ativa (movendo todo segundo) | Vira distração; presença vira ruído |
| Som ambiente "vivo" (zumbidos, beeps casuais) | Mesma coisa em áudio |
| Wake word com false positive alto | Quebra confiança fundamentalmente |
| Reflex que faz "interpretação" do som ambiente | L0 não pensa — só reage a estímulo bruto |
| Ack sonoro idêntico a wake word | Confunde — usuário não sabe se foi reconhecido ou ignorado |
| Face "neutra" sem nenhuma vida (totalmente estática) | Boneco congelado |
| Modo degradado idêntico a modo normal (visual) | Quebra contrato de honestidade |
| Servo idle barulhento | Presença vira incômodo audível |

---

## 8. Riscos específicos da L1

### 8.1 Drift para "Alexa"

Risco: começar a otimizar pra "responde rápido" e esquecer presença passiva.

**Sinal de drift:** robô parece morto entre comandos, animado só durante interação.

**Mitigação:** L0 reflex tem mesmo orçamento (atenção, qualidade) que L1 reaction. Não é "feature secundária".

### 8.2 Drift para mascote

Risco: presença fica fofa, animada, "personagem" — vira brinquedo.

**Sinal de drift:** usuário começa a tratar robô como pet, não como Atlas.

**Mitigação:** vocabulário visual contido. Não há "olhinhos curiosos" cute. Face neutra é neutra.

### 8.3 Latência cognitiva exposta

Risco: cognição demora, robô fica em silêncio aguardando, vira awkward.

**Sinal de drift:** "Atlas, ..." → 3s de silêncio → resposta. Constrangedor.

**Mitigação:** ack imediato (~100-200ms), face de "te ouvindo", micro-confirmações sonoras durante deliberação. **Cobrir a janela cognitiva**.

### 8.4 Modo degradado constante

Risco: rede instável faz robô passar mais tempo degradado que ambient.

**Sinal de drift:** LED roxo é visto mais que LED azul.

**Mitigação:** investigar root cause (Wi-Fi, alma, infra) — não disfarçar com retry mais agressivo.

---

## 9. Critério de "L1 sólida"

Você considera L1 atingida quando:

> Em uso real de 1+ mês, **você confia no robô estar lá**. Olha pra ele e sabe se algo está OK ou não. Fala "Atlas" e sabe que vai ouvir. Acidentalmente derruba ele e ele continua. Wi-Fi cai e ele te avisa em vez de "fingir bem".

Esse é o teste de presença. Não é benchmark — é sensação acumulada.

---

## 10. L1 e privacy

L1 não captura conteúdo (apenas wake word + sinais estruturais). Isso simplifica privacy enormemente:

- Captura cognitiva acontece em camadas posteriores.
- L1 só precisa de: wake word local (sem egress), sinais binários (presença).
- Indicação visual só relevante quando câmera/mic streaming entram (L2+).

L1 é a camada de privacy mais "fácil" — mas anti-padrões aqui criam débito que infecta o resto.

---

## 11. Como L1 se manifesta nos modos

| Modo | Manifestação de L1 |
|---|---|
| `ambient` | L1 plena: animação idle, ack rápido, presença constante |
| `interaction` | L1 + mais expressivo (face informativa, gestos) |
| `dnd` | L1 contida: animação reduzida, sem som, dim |
| `private` | L1 com captura desligada: face neutra, LED amarelo, sem voz |
| `private_physical` | L1 mínima: face vermelha estática, LED vermelho, garantia |
| `degraded` | L1 dignamente reduzida: face triste, LED roxo, beep ao wake word |
| `standby` | L1 latente: tudo off, mas wake e touch funcionam |

L1 não some em nenhum modo. Apenas reduz expressão. Mesmo standby é "L1 dormindo" — pronto a despertar.

---

## 12. L1 → L2

L1 sólida é pré-requisito para L2 (reatividade). Especificamente:

- Sensores funcionando (touch, IMU, presence) — L1 já garante.
- Reflex layer responsivo — L1 já garante.
- Adapter rotando eventos pra alma — L1 já garante.

L2 adiciona: **reagir a estímulos ambientais sem ser endereçado**. Mas isso só faz sentido se L1 estiver sólida — caso contrário, reatividade vira bug em vez de vida.

---

## 13. Decisões pendentes

Lista vai pra `10-anexos/C-decisoes-pendentes.md`. Específicas de L1:

| Decisão | Bloqueia |
|---|---|
| ⚠️ Frequência ideal de blink/micro-movimento idle | Calibração |
| ⚠️ Volume/timbre do ack sonoro | Não-confundível com wake word |
| ⚠️ Threshold conservador exato de wake word | Implementação |
| ⚠️ "Face neutra" — ela é mesmo neutra ou tem traços sutis de "ficou ali"? | Design visual |

---

## 14. Resumo

| Item | Detalhe |
|---|---|
| **Camada** | L1 — Presença |
| **Definição** | Existe, está pronto, responde quando chamado |
| **Sintoma resumo** | Robô parece "ligado", reage rápido a chamado |
| **Construído sobre** | L0 reflex (firmware) + transporte estável + reflex layer |
| **Pré-requisito de** | L2, L3, L4, L5 |
| **Cobertura nas fases** | Fases 0 + 1 |
| **Risco principal** | Drift para Alexa ou mascote |
| **Critério de sucesso** | Confiança acumulada após 1+ mês |

---

## Próximos passos de leitura

- `02-reatividade.md` — L2.
- `02-arquitetura/02-loops-temporais.md` — fundação técnica.
- `08-roadmap/01-fase-0-espelho.md` — primeira fase para L1.
