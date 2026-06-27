# Atlas Brain — Research Source Registry (path frontier-harvest)

> Config do **path de pesquisa** do [Motor de Meta-Melhoramento](../../../.claude/projects/-Users-vitorepf-develop-Atlas-atlas-server/memory/brain-meta-improvement-engine.md).
> Fontes seedadas pelo operador (26/06/2026). Registry é DADO, per-escopo (estas são as de **engenharia de software**; marketing/cyber/finanças/trading recebem as suas).

## MANDATO (o ponto pétreo do operador)

**Atlas TEM QUE BUSCAR.** A pesquisa é comportamento ATIVO e PERMANENTE do cérebro, não passivo. Quando um escopo está verde/ocioso, o cérebro **vai e procura** — harvest proativo de técnicas novas da fronteira, não esperar alguém trazer. "Buscar" é o verbo: o cérebro sai, olha, traz, e porta a de maior alavanca pelos gates. Isso é parte do "sempre buscar melhorar partes diferentes de si dentro do escopo".

## PIPELINE (3 tiers — descobrir → ler → aterrar)

1. **DESCOBRIR** o que está em alta (trendshift) — sinal de onde a fronteira está investindo agora.
2. **LER A FUNDO** o repo real (github.com) — a técnica/código de verdade, não o resumo.
3. **ATERRAR** na pesquisa (arxiv.org) — o paper por trás da técnica, pra entender o mecanismo e generalizar.

## FONTES — escopo Engenharia de Software (seed operador)

**Trending / descoberta (trendshift.io):**
- `https://trendshift.io/` — home (geral)
- `https://trendshift.io/yearly` — trending do ano
- `https://trendshift.io/weekly` — trending da semana
- `https://trendshift.io/topics/ai-agent` — por tópico (e outros tópicos conforme o escopo)
- `https://trendshift.io/github-trending-repositories?trending-limit=100` — top 100 repos em alta
- `https://trendshift.io/trending/developers` — devs em alta (pessoas, não só repos)

**Deep-read / código:**
- `https://github.com/*` — o repo real (README, código, issues, releases)

**Grounding / pesquisa:**
- `https://arxiv.org/*` — papers de fronteira (o mecanismo por trás)

## COMO VIVE (shape de dado)

`research_sources` per escopo (o escopo SE seedado acima). Cada fonte = `{url_pattern, tier: discover|read|ground, cadence}`. O frontier-harvest path itera: para cada fonte `discover` → coletar candidatos → para os top-N, `read` o github → `ground` no arxiv → emitir como CANDIDATO de evolução.

## DISCIPLINA DE HARVEST (anti-Goodhart)

- **Dedup** contra o catálogo de métodos (`brain-self-improvement-method-catalog.md`) — não re-portar o que já temos.
- Toda técnica colhida entra como **CANDIDATO**, gated como qualquer originação (**author≠judge**); NUNCA auto-adotada.
- **Tag de leverage** (esquerda > direita, pela tese 70/20) — priorizar o que engrossa compreensão/aprendizado, não mais um gate.
- WebSearch/WebFetch são allowlisted pro path; sensitive/secret/cyber não saem da máquina (soberania local-first).
