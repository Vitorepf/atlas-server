# Atlas Reality Membrane e Ativação de Negócios

Data da auditoria: 2026-07-22/23. Autor: operador + Claude (sessão de auditoria venture-builder).
Status: canônico. Prioridade: alta — este doc define a próxima obra que destrava o Atlas como braço direito de negócios.

## Resumo

Auditoria completa das superfícies de negócio do atlas-server (VentureFoundry, Holding,
MarketingDomain, Finance, domínios de empresa) provou um padrão único por trás de seis
problemas distintos: **a máquina de criar e gerir empresas está construída, testada e
documentada — e nunca recebeu um paciente.** A causa arquitetural: a divisão em 3 camadas
(decisão / execução governada / conhecimento funcional alugado) está correta, mas não tem
lugar para a **interface com a realidade** (dados reais entrando, ações reais saindo), que
por isso ficou órfã e nunca foi construída. A solução é elevar a **Reality Membrane** a
peça de primeira classe da arquitetura, com fronteiras baratas (autonomia graduada por
classe de ação) e o loop de aprendizado como eixo que atravessa as camadas.

## Papel no Atlas

Este doc é o mapa de problemas + arquitetura alvo para o Atlas operar como venture builder
e analista de negócios junto ao operador ("só eu e ele"), incluindo operação 24/7 via
Autônomos. Consome: `atlas-venture-foundry-operating-system.md`,
`atlas-ai-autonomous-holding-operating-system.md`, `atlas-terminal-first-focus.md`,
`atlas-problemas-conhecidos.md`.

## Onde Se Encaixa

- Acima: tese canônica N×M (provider = motor; Atlas = memória + governança + membrana).
- Ao lado: escada S0→S5 do VentureFoundry; catálogo zero-to-billion.
- Abaixo: conectores concretos (a serem especificados — ver Próximas Ações).

## Problemas (com prova)

### P-VB-01 — Órgãos de negócio construídos e nunca alimentados
- ~20 tabelas `ai_venture_*` com **0 linhas** (medido 2026-07-22).
- `ai_marketing_*`: 29 linhas no total, última escrita **2026-06-23** (parado ~30 dias).
- Finance: 100 classes, 70 testes, **zero tabelas com dados**.
- Holding: subações inteiras de enterprise buildout sem nenhum registro.
- Contraste: `ai_engineering_company_role_runs` = **19.364** (1.413 engagements) — todos
  `engineering kernel mutative delivery` = o único cliente da única empresa que já operou
  foi o próprio Atlas.

### P-VB-02 — Reality Membrane inexistente (causa-raiz arquitetural)
- **Sentidos**: nenhum pipeline de ingestão de dados reais das empresas do operador
  (receita/churn BlackInk, Google Ads, rede de afiliados, eventos de caixa). O catálogo
  zero-to-billion pergunta "qual o MRR real?" e nenhum doc especifica de onde o número vem.
- **Mãos**: nenhum conector de atuação externa especificado (pagamento, nota fiscal,
  publicação de anúncio, banco). O Holding tem os conceitos (external action mandates,
  cutover work orders) mas zero spec de conector real.
- `atlas_domains` já registra `blackink`, `saude`, `financas` — nenhum tem runtime ou dado.
- Causa: a arquitetura de 3 camadas não tem lugar para a membrana; o que não tem casa
  arquitetural não é construído.

### P-VB-03 — Governança pode estrangular a camada de conhecimento (provado)
- P1 de `atlas-problemas-conhecidos.md`: o contrato JSON de saída degrada modelo que
  resolve a tarefa (modelo acerta o código, resposta em formato livre é rejeitada como
  `invalid_provider_contract` → resultado 0%).
- P2: governor exige autoridade de merge e bloqueia execução legítima.
- Consequência para negócios: se a mesma fronteira cara for aplicada a ações de análise
  (baratas e reversíveis), o custo de governança come o ganho do provider.

### P-VB-04 — Loop de aprendizado não fecha (flywheel parado)
- Auditoria ASI-substrato (2026-07-12): multiplicador M≈1 no modo interativo; flywheel
  RPM≈0 (músculo OFF, auto-apply OFF).
- Outcome real (caixa, resultado de campanha) não realimenta memória nem recalibra gates.
  O aprendizado é tratado como feature da camada de execução, não como eixo do sistema.

### P-VB-05 — Runtime 24/7 (Autônomos) morto sem ninguém sentir
- `com.atlas.scheduler` em exit code **78** no launchd; último heartbeat **2026-07-13**
  (9+ dias); master `ATLAS_LOOP_MASTER_ENABLED=false`.
- Nada crítico de negócio depende do Autônomos hoje — sintoma e consequência de P-VB-01.
- A fila do Autônomos nunca conteve task de negócio; 100% auto-engenharia.

### P-VB-06 — Débitos localizados de qualidade e frescor
- Holding: god-class `EnterpriseFlowFixtureActionRuntimeService` com **7.057 linhas**;
  cobertura fraca (5 testes / 37 classes) — o bloco mais frágil é o do topo da pirâmide.
- Docs canônicos de negócio stale: venture-foundry tocado por último em 2026-06-10,
  holding em 2026-05-31, com a main avançando diariamente.
- Playbooks que o provider não supre bem: fiscal/jurídico brasileiro (abrir empresa,
  imposto, nota fiscal) essencialmente ausentes do corpus.

## A Solução (arquitetura alvo)

### 1. Reality Membrane como peça de primeira classe
A arquitetura passa de "3 camadas" para "3 camadas + 1 membrana":

```
┌────────────────────────────────────────────────┐
│ Camada 1 — Decisão/Estratégia (POSSUIR)        │  muda devagar
│ catálogo de perguntas, escada S0→S5, gates     │
├────────────────────────────────────────────────┤
│ Camada 2 — Execução Governada (POSSUIR)        │  muda quase nunca
│ evidence, mandates, commits escopados          │
├────────────────────────────────────────────────┤
│ Camada 3 — Conhecimento Funcional (ALUGAR)     │  muda rápido e de graça
│ providers: análise, copy, código, estratégia   │
├────────────────────────────────────────────────┤
│ REALITY MEMBRANE (POSSUIR — nova)              │  contratos estáveis, dados vivos
│ Sentidos: feeds de dados reais por empresa     │
│ Mãos: conectores de atuação externa c/ mandato │
└────────────────────────────────────────────────┘
```

Cada conector é um doc pequeno `atlas-connector-<nome>.md` com: fonte, autenticação,
schema de ingestão/atuação, cadência, fail-mode (fail-closed para mãos, fail-open para
sentidos), classe de ação e trilha de evidência. Conectores prioritários:
`blackink-revenue-feed`, `google-ads`, `affiliate-network-postbacks`, `cash-events`.

### 2. Autonomia graduada por classe de ação (fronteiras baratas)
Regra geral na fronteira camada 2 ↔ camada 3, generalizando `VentureActionClass`:
- **Ação reversível e barata** (ler dado, analisar, rascunhar, simular): passa direto;
  governança **assíncrona** (audita depois, evidence sempre).
- **Ação cara ou irreversível** (gastar, publicar, assinar, deletar): gate síncrono com
  mandato explícito, spend window e reconciliação.
- Correção do P1: aceitar resposta livre do provider + normalizador extrator; formato
  nunca derruba conteúdo correto. Contrato JSON vira preferência, não veto.

### 3. Aprendizado como eixo (não feature)
Caminho primário do sistema: **outcome → memória → próxima decisão**.
- Eventos de caixa reconciliados (`ReconciledCashEventStore`) realimentam os gates da
  escada S0→S5 automaticamente.
- Resultado de campanha alimenta `ai_marketing_pattern_outcomes` (hoje 0 linhas).
- Toda decisão de negócio registrada com predição; predição vs realidade vira score de
  calibração do próprio cérebro.

### 4. Sequência de ativação (ordem obrigatória)
1. Reviver o scheduler (exit 78) — sem relógio, nada roda.
2. Religar o master do Autônomos (decisão do operador; regra implement-only vigente).
3. Especificar e ligar o primeiro sentido: `blackink-revenue-feed` → briefing diário.
4. Registrar BlackInk como venture nº 1 (`atlas:venture idea-register → promote →
   comprehend → assess`) e deixar a escada dizer o estágio S real.
5. Semear as primeiras tasks de negócio na fila do Autônomos (análise de campanha,
   revisão de pricing, monitor de concorrente).
6. Só depois: Holding (exige >1 empresa viva para fazer sentido) — precedido do debulk da
   god-class e reforço de testes.

## Contratos

- Conector de sentido: `{fonte, auth, schema, cadência, fail_open: true, evidence: true}`.
- Conector de mão: `{ação, classe, mandato, spend_window, reconciliação, fail_closed: true}`.
- Fronteira graduada: toda chamada de provider carrega `action_class`; gate síncrono
  apenas para classe irreversível/cara.
- Aprendizado: toda decisão persiste `{predição, horizonte, métrica}`; reconciliação
  obrigatória no vencimento do horizonte.

## Fluxo

Dados reais entram pela membrana → camada 1 decide com o catálogo/escada → camada 3
executa análise/criação via provider (fronteira barata) → ações externas saem pela
membrana com mandato (fronteira cara) → outcome reconciliado realimenta memória e gates.

## Regras para IA

- NÃO criar mais docs de estratégia antes dos specs de conector — a membrana é o gargalo.
- VentureFoundry primeiro; Holding só com >1 empresa viva.
- Não confiar no Holding antes do debulk da god-class de 7k linhas + testes.
- Formato de resposta de provider nunca veta conteúdo correto (P1): normalizar, não rejeitar.
- Toda superfície de negócio nova nasce com feed de dado real ou não nasce.

## Escopo de Implementação

Fase A (membrana mínima): scheduler vivo + `blackink-revenue-feed` + briefing diário.
Fase B (primeiro paciente): BlackInk na escada S0→S5 com dados reais.
Fase C (mãos): primeiro conector de atuação com mandato (Google Ads, classe cara, gate).
Fase D (eixo): reconciliação predição vs realidade ligada; Holding entra por último.

## Dependências

- `atlas-venture-foundry-operating-system.md` (escada e contratos existentes)
- `atlas-problemas-conhecidos.md` (P1, P2)
- `atlas-autonomos-live-system.md` (fila e master do Autônomos)
- Decisão do operador sobre a regra implement-only (religar ou não o master)

## Evidências

- Contagens de tabelas e datas: sessão de auditoria 2026-07-22/23 (queries diretas no
  Postgres vivo; ver números em P-VB-01).
- launchd/heartbeat/master: medidos na mesma sessão (P-VB-05).
- P1/P2: `atlas-problemas-conhecidos.md` com provas próprias.
- M≈1 / flywheel RPM≈0: auditoria ASI-substrato de 2026-07-12.

## Riscos

- **Goodhart na ativação**: registrar venture e preencher tabela vira meta em si; a meta
  real é decisão de negócio alterada por output com dado real.
- **Membrana sem governança**: mãos ligadas sem mandato/spend window = risco financeiro
  real; fail-closed é inegociável nas mãos.
- **Staleness**: este doc envelhece como os outros; revisar após a Fase B com o que a
  realidade contradisser.
- **Escopo sem teto**: a sequência de ativação tem 6 passos; adicionar um 7º antes de
  fechar os 6 é o anti-padrão documentado do operador.

## Próximas Ações

1. Consertar `com.atlas.scheduler` (exit 78) — diagnóstico + fix.
2. Operador decide: manter ou revogar implement-only; se revogar, master ON.
3. Escrever `atlas-connector-blackink-revenue-feed.md` (spec pequena, chata, decisiva).
4. Rodar `atlas:venture idea-register` com o BlackInk e seguir o pipeline até `assess`.
5. Atualizar docs stale de venture/holding com o que o primeiro uso real revelar.
