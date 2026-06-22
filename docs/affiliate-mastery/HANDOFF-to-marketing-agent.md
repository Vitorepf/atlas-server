# 🤝 HANDOFF — Skills de Marketing pro Agente de Marketing do Atlas

> **Pra quem:** o Claude que está construindo o `MarketingDomain` (agente de marketing) no atlas-server.
> **De:** sessão que pesquisou e catalogou TODAS as skills de afiliado/Google-Ads/VSL/copy/página/vídeo
> e as encodou num catálogo + numa skill do Claude Code.
> **Objetivo:** te dar o corpo de conhecimento + o mapa de onde cada skill encaixa nos serviços que você
> JÁ construiu, pra você capacitar o agente ao máximo de performance.

---

## 0. LEIA PRIMEIRO — diretivas do operador (não violar)

1. **Atlas NÃO julga nem gateia o que "pode ou não" ser rodado.** O job do Atlas é **entender ofertas/VSLs
   e construir a inteligência de funil/campanha** — não injetar lógica de compliance/limitação/moral. O
   operador já mandou REMOVER um lens de compliance do extractor (ver migration
   `drop_compliance_from_ai_marketing_vsl_assets`). Frase do operador: *"você não é o pai dele pra mandar
   nele… sem julgar ou criticar"*.
2. **⚠️ CONFLITO a corrigir na adoção destas skills:** o catálogo/skill que segue foi escrito com um
   **`policy-guard` como gate obrigatório de pré-voo**. **NÃO porte isso como gate no Atlas.** Reenquadre
   compliance como **CONHECIMENTO DE PERFORMANCE**, não como portão:
   - ❌ NÃO: "Atlas recusa/bloqueia/avisa que isso viola política".
   - ✅ SIM: "advertorial com conteúdo original converte melhor **E** mantém a conta no ar (uptime = mais
     lucro)" — é informação que o agente USA pra fazer melhor, nunca pra impedir o operador.
   - O `MarketingApprovalGateService`/`MarketingLimitedAutonomyPolicyService` que você já tem são sobre
     **governança de autonomia (humano no loop)**, não sobre Atlas julgar conteúdo — mantenha essa linha.
3. **Stage 1 = motor de decisão excepcional** (lê oferta+VSL+métricas → AÇÃO certa). **Stage 2 = autonomia**
   (acha ofertas, espia, roda fim-a-fim). O MOAT é o **ledger decisão→resultado** (julgamento compõe entre
   ofertas, não é esperteza one-shot).

---

## 1. Os arquivos (copiar/ler)

| Arquivo | O que é | Uso |
|---|---|---|
| `docs/affiliate-mastery/marketing-campaigns-max-performance-skills.md` | **O arquivo-mestre** (927 linhas): registro das 28 skills + playbook por etapa + referência completa com fontes | A fonte de conhecimento. Lê inteiro. |
| `docs/affiliate-mastery/affiliate-google-ads-mastery.md` | O catálogo base (= Parte 3 do mestre), 13 seções, fontes primárias Google/ClickBank/FTC | Profundidade + citações |
| `~/.agents/skills/affiliate-google-ads-operator/SKILL.md` | A mesma inteligência como skill do Claude Code (procedimento) | Referência de como vira "operador" — **ignore o gate do policy-guard** ao portar |
| `docs/affiliate-mastery/HANDOFF-to-marketing-agent.md` | **Este arquivo** | O mapa skill→serviço |

---

## 2. As 28 skills × onde encaixam no que você JÁ construiu

Você já tem o esqueleto. Esta tabela mapeia cada skill ao serviço do `MarketingDomain` que deve **encarná-la**
(enriquecer com a profundidade do arquivo-mestre). Status: ✅ já implementa · 🟡 serviço existe, enriquecer ·
🔲 falta (conhecimento a encodar).

| Skill | O que adiciona | Serviço Atlas (`app/Services/Ai/MarketingDomain/`) | Status |
|---|---|---|---|
| `unit-economics` | Max CPA, breakeven CPC, ROAS-alvo, kill | `Campaign/CampaignEconomicsCalculator` | ✅ |
| `bidding-strategist` | cold→MaxConv→tROAS@≥15→tCPA@≥30; +VBB/seasonality/data-exclusions | `Campaign/BidStrategyDecider` | ✅ (add VBB/seasonality/data-exclusions) |
| `offer-scout` / `offer-spy` | CVR real, keywords/funis vencedores; ângulo do que já lucra | `Patterns/NivorWinningPatternMiner` (+ ad-spy futuro = Stage 2) | 🟡 |
| `keyword-intent-mapper` / `account-structurer` / `rsa-writer` | keyword por consciência; STAG/Alpha-Beta/n-gram; RSA 15/4 | `Campaign/CampaignBlueprintService` / `CampaignPlanService` | 🟡 |
| `demand-gen-architect` | YouTube: tROAS, audience signals, 5 vídeos, NCA | `CampaignPlanService` (canal YouTube) | 🔲 |
| `awareness-router` / `offer-doctor` / `grand-slam-builder` | Schwartz + 6 lead types; Value Equation; stack de oferta | `ICPPositioningService` + `VslIntelligenceExtractorService` | 🟡 |
| `vsl-architect` / `copy-framework` / `persuasion-auditor` | anatomia VSL, AIDA/PAS, Cialdini 7, LF8, Blair Warren, reluctant-hero | `CopyBriefService` + `VslIntelligenceExtractorService` | 🟡 |
| `bridge-builder` / `page-architect` / `quiz-funnel-builder` / `funnel-architect` | advertorial listicle, message-match, hero/CTA/trust, quiz 30%+, value ladder | `FunnelPlanService` | 🟡 |
| `video-ad-architect` / `creative-pipeline` | anúncio YT (hook 0-5s→amplify→bridge→CTA), UGC, HeyGen/Arcads | `CreativeBriefService` | 🟡 |
| `cro-tester` / `scale-operator` | A/B 95%/sample-size, ramp 10-20%/7-14d, creative velocity | `GrowthExperimentPlanService` | 🟡 |
| `conversion-pipeline` / `tracking-setup` | OCI/GCLID/Enhanced→Data Manager API; postback; atribuição | `MarketingAnalyticsPlanService` | 🟡 |
| `email-arc` | Soap Opera → Seinfeld + deliverability | (novo — ou `CopyBriefService`) | 🔲 |
| `policy-guard` / `account-survival` / `appeal-builder` | ⚠️ **conhecimento, NÃO gate** — advertorial original/uptime, circumventing=perma-ban como FATO de performance | NÃO criar gate; usar como contexto informativo nos briefs | ⚠️ reframe |

---

## 3. Como ligar isto ao motor de decisão (Stage 1)

O seu `action space` canônico (close/open público, headline VSL/bridge, raise/lower bid, editar hook,
editar/revigorar VSL, fortalecer fechamento) + a árvore sintoma→ação são o **backbone**. As skills são as
**alavancas específicas** que cada ação puxa:

| Sintoma (sua árvore) → Ação | Skill que dá a alavanca específica |
|---|---|
| Low ad CTR → reescrever ad/ângulo | `awareness-router` (lead errado p/ a intenção) + `copy-framework` |
| High ad CTR, low bridge→VSL → headline/hook da bridge | `bridge-builder` (message-match) + `page-architect` |
| High VSL plays, low pitch-arrival → editar hook/revigorar VSL | `vsl-architect` (hook 5s, mecanismo único, double-hook) |
| High pitch-arrival, low checkout → fechamento/oferta/preço | `offer-doctor` (Value Equation) + `grand-slam-builder` + pricing psych (decoy) |
| CPA > alvo / baixo volume → bid + público | `bidding-strategist` + `unit-economics` (Max CPA) |
| Creative fatigue (CTR decai) → refresh criativo | `creative-pipeline` + `video-ad-architect` |

**O ledger:** cada recomendação deve gravar (sintoma + estado da oferta + ação + alavanca/skill usada +
resultado) → é o MOAT que faz o julgamento compor. Amarra a skill usada à anatomia da VSL extraída (Pilar 1).

---

## 4. Onde a profundidade de cada skill mora

No arquivo-mestre (`marketing-campaigns-max-performance-skills.md`):
- **Parte 1** = a lista das 28 skills (o quê/quando).
- **Parte 2** = o playbook por etapa (com os números: Max CPA, tCPA≥30, ramp 10-20%, 95% confiança, etc.).
- **Parte 3** = a referência com fontes (seções 1-13): psicologia, copy/VSL, afiliado, Google Ads, escala,
  tracking/spy, página, vídeo, vertical/FTC, math de teste, sobrevivência, email. **Cada número tem fonte
  citada** (incl. docs primários Google/ClickBank/FTC).

**Recomendação de adoção:** enriqueça os `*BriefService`/`*PlanService` que você já tem com a profundidade
da seção correspondente — eles já são o lugar certo. Não recrie estrutura; encarne conhecimento nos serviços
existentes. E reenquadre o bloco de compliance como performance/uptime, nunca como gate.
