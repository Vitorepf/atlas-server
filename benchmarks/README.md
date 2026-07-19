# Benchmarks do Atlas

Organizados **por área** — você escolhe uma área e roda só os dela. Todos aqui
rodam **nativo no Mac** (arm64, sem Docker x86, sem gambiarra). Os que precisam de
x86 ficam separados em [`_estacionados-precisa-x86/`](_estacionados-precisa-x86/).

> Status de cada benchmark: **✅ provado** (clonado + rodou smoke) · **🔵 a provar**
> (clonado, smoke pendente) · **⚠️** ressalva. A garantia é o smoke — nada é dado
> como "roda" sem ter rodado. Lista transversal: [`RODA-NO-MAC.md`](RODA-NO-MAC.md).

## As 7 áreas

| área | pasta | benchmarks nativos |
|---|---|---|
| 🧭 Produto & Estratégia | [`produto/`](produto/) | 9 |
| 🎨 Design & UX | [`design/`](design/) | 8 |
| ⚙️ Engenharia | [`engenharia/`](engenharia/) | 17 |
| 🔒 Qualidade & Segurança | [`qualidade/`](qualidade/) | 4 |
| 📊 Dados & Experimentação | [`dados/`](dados/) | 9 |
| 📈 Crescimento & GTM | [`crescimento/`](crescimento/) | 7 |
| 💼 Negócio & Operações | [`negocio/`](negocio/) | 11 |
| 🧠 Conhecimento & raciocínio | [`conhecimento-e-raciocinio/`](conhecimento-e-raciocinio/) | inspect, tau2 (cru) |

## Os dois braços

- **`bare`:** o modelo sozinho no provider.
- **`with_atlas`:** o cérebro Atlas (`atlas:cli:dev`) com o Hermes por dentro —
  *usar o Atlas é usar o Atlas, não o Hermes cru.*

Cada benchmark cospe o **relatório dele mesmo**; o Atlas junta todos.

## Mapas
- [`RODA-NO-MAC.md`](RODA-NO-MAC.md) — os 42 nativos por área (o que roda no Mac)
- [`EMPRESA-AUTONOMA.md`](EMPRESA-AUTONOMA.md) — as 95 capacidades p/ empresa autônoma + as 19 lacunas
- [`CATALOGO-EXPANSAO.md`](CATALOGO-EXPANSAO.md) — o landscape inteiro (~58 de código)
- [`_estacionados-precisa-x86/`](_estacionados-precisa-x86/) — os que precisam de x86

## Clones
Repos oficiais (gitignored) no root `tools/rivals/benchmarks/`. Esta pasta
documenta e organiza; os clones executam no root de trabalho.
