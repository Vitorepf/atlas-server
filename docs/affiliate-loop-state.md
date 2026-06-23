# Affiliate Funnel Improvement Loop — estado

Loop: Claude Code evolui o motor de marketing de afiliado do Atlas (escopo `app/Services/Ai/MarketingDomain/**`) até os funis venderem.
Métrica: VENDAS/CONVERSÃO. Caso de teste: VSL OT169 (retatrutide / triple-hormone drops; avatar = mulher 40+).
Régua atual (piso): **80** / teto 95.

## Recursos do funil (nota = julgamento de painel brutal: avatar cético + copywriter elite + media buyer)

| Recurso | Nota atual | Maior fraqueza | Status |
|---|---|---|---|
| Bridge — headline/above-fold | 62 | jornalística e distante ("women are talking about"), sem número real, gancho morno | EM MELHORIA (ciclo 1) |
| Bridge — lead/corpo | 65 | bom início, mas vira "reportagem sobre fenômeno", repetitivo, faltam números/cenas | pendente |
| Bridge — prova/depoimentos | 50 | depoimentos genéricos (-34 lbs sem história); ignora os números reais (90/120/63 lbs) da VSL | pendente |
| VSL sales page | 60 | determinística mas crua; oferta/garantia ok, falta agressividade na headline | pendente |
| Thumbnail | 70 | tipográfica forte, mas sem rosto/antes-depois; 1 ângulo só | pendente |
| RSA (15 títulos / 4 descrições) | 0 | NÃO EXISTE — recurso a criar | pendente |
| Keywords (clusters/intenção/match/negativas) | 55 | extrai clusters da VSL, mas sem match types nem lista de negativas explícita | pendente |
| Email follow-up | 0 | NÃO EXISTE | pendente |

## Diagnóstico-raiz (por que parece IA)
A copy é gerada pelo hermes (GLM/MiniMax — fraco). Resultado: tom jornalístico-distante, abstrato, repetitivo. **Correção estrutural: cristalizar a voz de copy de elite no MOTOR (templates/forges determinísticos parametrizados pela munição da VSL) para não depender do LLM fraco nas partes que decidem a venda (headline, lead, prova).**

## Ciclos
- **Ciclo 1 (em curso):** Alvo = HEADLINE/above-fold (menor nota, maior impacto no "fica ou fecha"). Foco = MOTOR: `BridgeHeadlineForge` determinístico (fórmulas de headline de elite parametrizadas por número real + autoridade + inimigo + mecanismo + dor), injetado como âncora obrigatória no gerador. Objetivo: 62 → ≥80.
