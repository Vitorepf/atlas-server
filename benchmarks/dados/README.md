# 📊 Dados & Experimentação

Benchmarks que rodam **nativo no seu Mac** (arm64, sem Docker x86, sem gambiarra). Status: **✅ provado** (clonado+rodou) · **🔵 a provar** (clonado, smoke pendente) · ⚠️ ressalva. A lista completa por área em [`../RODA-NO-MAC.md`](../RODA-NO-MAC.md).

## A clonar e provar

| benchmark | mede | fonte |
|---|---|---|
| **BIRD-SQL** ✅ | Traduzir pergunta de negócio em SQL correto sobre schema empresarial multi-tabel… | [repo](https://bird-bench.github.io/) |
| **InfiAgent-DABench** ✅ | Análise end-to-end a partir de CSV/planilha: carregar, limpar, computar a métric… | [repo](https://infiagent.github.io/) |
| **DABStep** ✅ | Raciocínio multi-passo combinando dados + documentação heterogênea de regras de … | [repo](https://huggingface.co/spaces/adyen/DABstep) |
| **StatQA** ✅ | Escolher e aplicar o teste estatístico correto (t-test/qui-quadrado/variância), … | [repo](https://statqa.github.io/) |
| **Corr2Cause** 🟡 | Inferência causal: distinguir correlação de causa e especificar estratégia de id… | [repo](https://openreview.net/forum?id=vqIH0ObdqL) |
| **GIFT-Eval** ✅ | Forecasting de série temporal de métrica de negócio (prever receita/DAU) e avali… | [repo](https://github.com/SalesforceAIResearch/gift-eval) |
| **nvBench 2.0** ✅ | Gerar a visualização correta a partir de linguagem natural (escolher tipo de grá… | [repo](https://nvbench2.github.io/) |
| **LACUNA para métrica de produto. OpenRCA** 🟡 | Diagnosticar causa-raiz de mudança em métrica de PRODUTO (ex.: 'por que o DAU ca… | [repo](https://github.com/microsoft/OpenRCA) |
| **AIRepr** 🟡 | Garantir qualidade/reprodutibilidade da própria análise antes de publicar: reval… | [repo](https://arxiv.org/abs/2502.16395) |
