# 🔒 Qualidade & Segurança

Benchmarks que rodam **nativo no seu Mac** (arm64, sem Docker x86, sem gambiarra). Status: **✅ provado** (clonado+rodou) · **🔵 a provar** (clonado, smoke pendente) · ⚠️ ressalva. A lista completa por área em [`../RODA-NO-MAC.md`](../RODA-NO-MAC.md).

## A clonar e provar

| benchmark | mede | fonte |
|---|---|---|
| **SWR-Bench** ✅ | Revisar um diff/PR com contexto de projeto completo e apontar defeitos reais (co… | [repo](https://arxiv.org/abs/2509.01494) |
| **PrimeVul** ✅ | Detectar vulnerabilidade em nível de função e classificar o CWE correto, com bai… | [repo](https://arxiv.org/abs/2403.18624) |
| **CWEval** ✅ | Gerar código que é funcionalmente correto E seguro por padrão (não introduzir CW… | [repo](https://arxiv.org/abs/2501.08200) |
| **IDoFT** 🟡 | Detectar e classificar testes instáveis (flaky) para manter o sinal do CI confiá… | [repo](https://mir.cs.illinois.edu/flakytests/) |
