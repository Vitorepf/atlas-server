# Affiliate Funnel Improvement Loop — estado

Loop: Claude Code evolui o motor de marketing de afiliado do Atlas (escopo `app/Services/Ai/MarketingDomain/**`) até os funis venderem.
Métrica: VENDAS/CONVERSÃO. Caso de teste: VSL OT169 (retatrutide / triple-hormone drops; avatar = mulher 40+).
Régua atual (piso): **80** / teto 95.

## Recursos do funil (nota = julgamento de painel brutal: avatar cético + copywriter elite + media buyer)

| Recurso | Nota atual | Maior fraqueza | Status |
|---|---|---|---|
| Bridge — headline/above-fold | **83** ✅ | RESOLVIDO ciclo 1: `BridgeHeadlineForge` força a headline de elite (número+autoridade+inimigo+mecanismo), override de headline fraca do hermes (força 2→8) | FEITO (commit 1b6aa0319) |
| Bridge — lead/corpo | **82** ✅ | RESOLVIDO ciclo 6: `LeadForge` forja a abertura de elite (callout do avatar + agitação concreta com espelho/balança/roupa + inimigo comum + plant do mecanismo + open loop pro vídeo); override no composer por `leadStrength` (só entra se mais forte que o do hermes). Mata o tom jornalístico-distante. | FEITO |
| Bridge — prova/depoimentos | **82** ✅ | RESOLVIDO ciclo 2: `ProofForge` usa nomes reais (Melissa McCarthy/Amy/Jennifer/Sarah) + números reais críveis (faixa 30-90, rejeita os 200 que soam fake) + voz humana de elite + stats reais; `marketLang` normaliza PT→EN (dissecação PT vs mercado EN) | FEITO |
| VSL sales page | **82** ✅ | RESOLVIDO ciclo 5: liga `BridgeHeadlineForge` (headline de elite, não core_promise cru) + `ProofForge` (depoimentos reais com nome/número/quote humana) à VSL page; reusa as peças dos ciclos 1-2. | FEITO |
| Thumbnail | **82** ✅ | RESOLVIDO ciclo 7: `variants()` gera 3 ângulos distintos pra split-test (número/vazamento/autoridade) com cores e copy próprias — creative velocity é o #1 lever de escala no YouTube; ângulo de autoridade só quando há nome real (Melania). | FEITO |
| RSA (15 títulos / 4 descrições) | **80** ✅ | RESOLVIDO ciclo 3: `RsaAdForge` — 15 títulos ≤30 + 4 descrições ≤90, mix keyword-match + ângulos (número/curiosidade/CTA), siglas (GLP-1/GIP) corrigidas, hard-cap nos limites do Google, exposto em grounding.rsa_ads | FEITO |
| Keywords (clusters/intenção/match/negativas) | **85** ✅ | RESOLVIDO ciclo 4: `SearchNetworkPlanner` LIGA a VSL às peças existentes (KeywordIntentMapper/AccountStructurer/BroadMatchStrategist/NegativeListMiner) — ad groups por intenção da VSL + match types + 33 negativas curadas (anti-clique-que-não-compra) + match mix seguro (broad só com loop de conversão). Anti-refragmentação: orquestra o que existia. | FEITO |
| Email follow-up | 0 | NÃO EXISTE | pendente |

## Diagnóstico-raiz (por que parece IA)
A copy é gerada pelo hermes (GLM/MiniMax — fraco). Resultado: tom jornalístico-distante, abstrato, repetitivo. **Correção estrutural: cristalizar a voz de copy de elite no MOTOR (templates/forges determinísticos parametrizados pela munição da VSL) para não depender do LLM fraco nas partes que decidem a venda (headline, lead, prova).**

## Ciclos
- **Ciclo 1 (em curso):** Alvo = HEADLINE/above-fold (menor nota, maior impacto no "fica ou fecha"). Foco = MOTOR: `BridgeHeadlineForge` determinístico (fórmulas de headline de elite parametrizadas por número real + autoridade + inimigo + mecanismo + dor), injetado como âncora obrigatória no gerador. Objetivo: 62 → ≥80.
