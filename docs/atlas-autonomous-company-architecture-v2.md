# Atlas — Arquitetura v2 de Empresas Autônomas (criar · gerir · escalar · vender com extrema qualidade)

> **Status:** arquitetura canônica de referência. Fonte de verdade do DESENHO (a implementação deriva daqui). Autor: sessão de arquitetura 2026-06-17.
> **Diagramas:** (A) pilha completa, (B) loop autônomo por empresa, (C) escada de promoção de autonomia.
> **Relacionado:** `atlas-company-success-engine-buildout.md` (a meta ≥70% e os slices), `polsia-dissection`, `venture-foundry-sector-built`.
> **Tese do desenho:** autonomia de extrema qualidade vem de **cérebro-antes-do-músculo + loop fechado de aprendizado + qualidade gated por ação + fronteira de autonomia que se move com prova**. A v1 (pilha) estava certa na fundação; a v2 adiciona 7 componentes de primeira classe que faltavam para a autonomia ser *contínua, auto-melhorável e segura*.

---

## 0. Veredito de arquitetura

A estrutura v1 (Operador → Control Plane → Holding+Domains / Venture Foundry → NightShift → substrato) é a **base correta** — composição, reúso, validação antes de executar, evidência. Mas, sozinha, produz autonomia **estática e segura**, não **extrema qualidade**. A v2 fecha isso com 7 camadas. Com elas, a autonomia de extrema qualidade é **100% atingível no espaço bounded / reversível / medível**, e a fronteira (novo, alto-risco) **gradua com o tempo** — não fica nem travada nem aberta de forma irresponsável.

---

## 1. As 7 camadas de primeira classe (o que a v2 adiciona)

Cada camada: **papel · contrato · onde pluga · falha que evita · reúso vs novo.**

### ① Loop fechado de aprendizado (outcome → política)  — a keystone
- **Papel:** transformar resultado observado em mudança da decisão seguinte. É o que faz cada ciclo deixar o próximo melhor (compounding como espinha, não nota de rodapé).
- **Contrato:** dado `{decisão, ação, resultado_observado}`, atualiza a política de decisão (pesos/heurísticas/prioridades) de forma auditável e reversível. Só aprende de evidência persistida.
- **Pluga em:** Control Plane (cérebro) ← Resultado (MRR real). Diagrama A: a seta roxa de retorno.
- **Evita:** autonomia que nunca melhora (o platô). 
- **Reúso:** Compounding runtime + Evidence Ledger já existem; falta wirá-los como o *laço de controle* do ciclo de empresa.

### ② Motor de decisão por empresa (a política de operação)
- **Papel:** a cada ciclo, ler o estado da empresa → escolher a **próxima melhor ação** entre os domínios → despachar. É o "o que fazer agora" explícito.
- **Contrato:** `decide(venture_state) -> próxima_ação governada` (determinístico + grounded; sem alucinar prioridade). Reusa o `VentureFocusDecider` (que já crava o foco nº1) como núcleo, estendido para emitir ações, não só diagnóstico.
- **Pluga em:** entre Control Plane e a camada de Operação.
- **Evita:** autonomia por improviso; substitui a ponte suggest-only por decisão de engenharia.
- **Reúso:** `VentureFocusDecider` + `VentureAssessmentService` existem; falta o despachante de ação.

### ③ Orquestração cross-domínio + contratos de handoff (a cadeia de valor)
- **Papel:** fazer a empresa *fluir* ponta-a-ponta: lead → venda → onboarding → suporte → cobrança, coordenando Marketing/Vendas/Finanças/Suporte.
- **Contrato:** processo declarado com handoffs tipados entre domínios (cada handoff = artefato + dono + critério de aceite).
- **Pluga em:** camada de Operação, sobre os Domain Company Runtimes.
- **Evita:** silos que funcionam isolados mas não compõem uma empresa.
- **Reúso:** `DomainHandoffService` já faz handoff pontual; falta o orquestrador de processo end-to-end.

### ④ Gate de qualidade por ação (verifica antes de publicar)
- **Papel:** toda saída (tweet, email, mudança de preço, commit, campanha) passa por verificação **antes de sair** — não só na admissão e no resultado.
- **Contrato:** `quality_gate(ação) -> pass | iterate | block`, no padrão iterate-to-green do ADEP, por domínio.
- **Pluga em:** dentro da Operação, antes de cada ação externa. Diagrama B passo 3.
- **Evita:** o "junk" da Polsia; output medíocre que envergonha a marca.
- **Reúso:** ADEP/iterate-to-green já existe **para código**; a v2 generaliza o padrão para todos os domínios.

### ⑤ Escada de promoção de autonomia (suggest → approve → auto)
- **Papel:** definir **como cada classe de ação ganha/perde autonomia** com prova. É o que torna "tudo autônomo" um processo, não um interruptor.
- **Contrato:** por classe de ação: promove de suggest→approve→auto após N execuções sem falha dentro do blast-radius; rebaixa um degrau na 1ª falha/anomalia. Ações irreversíveis/alto-risco **nunca passam de approve sem mandato**.
- **Pluga em:** Operação (governa cada ação) + cockpit (operador vê/ajusta a fronteira). Diagrama C.
- **Evita:** autonomia binária (tudo-ou-nada) e a autonomia cega da Polsia.
- **Reúso:** `AtlasChangeClassTrustLadder` + o receipt NS-v1→v2 são instâncias; a v2 os generaliza num padrão único.

### ⑥ Contenção por empresa (blast-radius · circuit-breaker · rollback)
- **Papel:** limitar o estrago de qualquer decisão autônoma ruim operando dinheiro/clientes reais.
- **Contrato:** teto de gasto/ação por venture, checagem de reversibilidade, circuit-breaker que **para a venture** em anomalia, rollback de ação.
- **Pluga em:** Operação (envolve cada ação) + cockpit (mostra/aciona).
- **Evita:** uma decisão ruim afundar a empresa; risco sistêmico.
- **Reúso:** primitivos de blast-radius existem no Loop; falta elevá-los a camada de segurança por venture.

### ⑦ Cockpit do operador (control tower de primeira classe)
- **Papel:** dar ao operador estado em tempo real + **por que** de cada decisão (receipts) + alavancas de intervenção, num lugar só. É o que torna confiar em autonomia total razoável.
- **Contrato:** read-model ao vivo (estado por venture, ações pendentes/auto, scorecard de sucesso) + decision receipts + controles (pausar venture, rebaixar autonomia, aprovar mandato).
- **Pluga em:** topo, ao lado do operador. Diagrama A.
- **Evita:** autonomia opaca em que o operador perde controle/confiança.
- **Reúso:** `enterprise-control-tower` da Holding + Evidence Ledger; falta consolidar como cockpit único.

---

## 2. O loop autônomo por empresa (diagrama B)

O ciclo que roda continuamente (via NightShift) para cada venture:

```
1 sentir estado → 2 decidir próxima ação (②) → 3 gate de qualidade (④)
→ 4 agir sob gates (⑤ trust-ladder + ⑥ contenção) → 5 observar resultado (MRR real)
→ 6 aprender e promover (① política + ⑤ autonomia) → volta ao 1
```
Invariante: **cada volta deixa a próxima melhor** (compounding). Passos 2/3/6 são cérebro/gate (decidir, verificar, aprender); 1/4/5 tocam o mundo (sentir, agir, observar). Nenhuma ação externa sai sem passar por ④ e dentro de ⑤/⑥.

---

## 3. A fronteira irredutível (honestidade de desenho)

Mesmo com a v2 perfeita, dois limites NÃO são falha de arquitetura:
- **Por soberania (escolha):** ações de alto risco/irreversíveis ficam atrás de **mandato do operador** — de propósito.
- **Por capacidade:** julgamento greenfield genuinamente novo tem teto do modelo.

Por isso a v2 não promete "tudo autônomo para sempre" — promete **autonomia de extrema qualidade no espaço bounded/reversível, com a fronteira graduando por prova** (⑤ alimentada por ①). É a forma honesta e mais poderosa de "tudo autônomo".

---

## 4. Mapa de reúso (anti-refragmentação)

| Camada v2 | Já existe (reusar) | Falta construir |
|---|---|---|
| ① aprendizado | Compounding runtime, Evidence Ledger | o laço de controle do ciclo de venture |
| ② decisão | VentureFocusDecider, Assessment | despachante de ação por ciclo |
| ③ orquestração | DomainHandoffService, Domain Runtimes | orquestrador de processo end-to-end |
| ④ qualidade | ADEP iterate-to-green (código) | generalizar para todos os domínios |
| ⑤ escada | AtlasChangeClassTrustLadder, NS-receipt | padrão único de promoção/demoção |
| ⑥ contenção | blast-radius do Loop | camada de segurança por venture |
| ⑦ cockpit | enterprise-control-tower, Evidence Ledger | cockpit consolidado |

**Cada camada tem um primitivo no Atlas — mas "majoritariamente wiring" foi OVERCLAIM** (corrigido após a revisão adversarial, ver `atlas-v2-sufficiency-review.md`). Split honesto:
- **Wiring/generalização (rápido e seguro):** ③ orquestração (sobre DomainHandoff), ⑤ trust-ladder (irmão do AtlasChangeClassTrustLadder), ⑥ contenção (estende blast-radius), ⑦ cockpit (consolida control-tower), ② despachante (sobre FocusDecider) — **mas ② NÃO existe no disco hoje (Fase 2 = 0%)**.
- **Invenção (exige cuidado, NÃO é wiring):** ① loop de aprendizado (precisa de teoria de controle: credit-assignment sob delay de 3 meses, exploração/exploração, anti-oscilação, recompensa multi-sinal) e ④ oráculo de qualidade não-código (não há ground-truth fora de testes). A keystone ① está subespecificada, não só não-construída.

---

## 5. Ordem de construção (deriva para a implementação)

1. **Medição primeiro** (do buildout doc: evaluator sustentado + scorecard triplo + ledger de admissão) — sem régua, nada acima é honesto.
2. **② motor de decisão** + **③ orquestração** — a empresa passa a agir coordenada.
3. **④ gate de qualidade por ação** — a ação passa a ser de extrema qualidade.
4. **⑤ escada + ⑥ contenção** — a autonomia passa a ser segura e graduável.
5. **① loop de aprendizado** — o sistema passa a melhorar sozinho (a keystone vem depois que há o que medir e do que aprender).
6. **⑦ cockpit** — em paralelo, cresce com o resto.

Prova sempre primeiro na **Blackink** (gerida, sub-coorte mais dura), antes de criar venture nova — disciplina NightShift "prova em si antes de escalar".
