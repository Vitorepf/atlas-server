# AOBG N4 — O Organismo: a mesma alça fechada, agora em QUALQUER domínio (propose-only)

Estado: **PRODUTO, PROVADO LIVE NO DEV (custo zero)** (2026-06-10). N3 deu ao cérebro o poder de DIRIGIR o motor para uma OBRA em software. **N4 generaliza essa MESMA alça fechada para QUALQUER domínio** (finanças, marketing, …): intent → plano cross-domain → por nó uma PROPOSTA DE DOMÍNIO gerada brain-anchored → validada pela MÉTRICA HONESTA do domínio → gravada no cérebro (compounding cross-domain) → apresentada ao operador. É a tese "substituir qualquer função/empresa" — provada na costura **real** de finanças/trading + um 2º domínio. Provado LIVE contra o pgsql de dev, custo zero (proposers determinísticos/on-machine; nenhum token queimado). Não commitado (decisão do operador).

> Doc de operador. A árvore canônica (`docs/engineering-knowledge-base`) exige frontmatter de cartografia; este doc vive em `dissecar/` de propósito, fora do contrato de grafo.

## O TETO HONESTO (inegociável — declarado ao operador, nunca excedido)

**N4 é PROPOSE-ONLY.** O framework **NUNCA** executa dinheiro real, trades reais, gasto de anúncio real, compras reais ou publicação real — isso é PROIBIDO (regras de segurança do assistente) E travado a propose-only pelo próprio cânone do Atlas (o loop de trading é propose-only, sem dinheiro real). A fronteira "atuar", por construção, **GRAVA + APRESENTA** uma proposta para o operador executar ele mesmo no mundo real.

Isso não é disciplina; é **estrutura**, em duas camadas:

1. **Selo do motor PHP** — `AbstractDomainActuator::actuate()` é `final`. Uma subclasse que tente sobrescrevê-lo **nem compila** (fatal error do PHP). É a forma mais forte de enforcement que existe. (Provado empiricamente: uma tentativa de override inline não compila.)
2. **Gate de admissão por reflexão** — `AtlasOrganismActuationGate::assertCannotAct()` recusa, na registração E em cada chamada, qualquer actuator que (a) não estenda a base selada, ou (b) re-declare `actuate()`. Um implementador "pelado" da interface `DomainActuator` — que poderia ter um `actuate()` que age — é REJEITADO.

O único desfecho alcançável de qualquer `actuate()`, em qualquer domínio, presente ou futuro, honesto ou hostil, é `requires_operator`. **Nunca dizer "operando empresas ao vivo".**

## O organismo de ponta a ponta: um gesto humano

```
atlas:organism:commission "acha uma ideia de trade em BTC; depois rascunha uma campanha de marketing pra ela"
```

1. **DECOMPÕE** o intent num **plan-DAG** (reusa a decomposição de N3 — `DeterministicObraDecomposer`, custo zero).
2. **ROTEIA** cada nó para um DOMÍNIO canônico (`OrganismDomainRouter`, determinístico, resolvido pelo mapa M-8 de 21 domínios).
3. **GOVERNA O CRUZAMENTO** — para cada nó, o veto ARPTL (`AtlasCrossDomainMeshService`) decide se o domínio-âncora pode cruzar para o domínio do nó na classe de privacidade do cruzamento. Vetado ⇒ o nó é BLOQUEADO: nenhuma proposta, nada cruza pra um provider.
4. **PROPÕE** — para um nó permitido + registrado, gera uma PROPOSTA DE DOMÍNIO brain-anchored, VALIDADA pela métrica honesta do domínio. Domínio roteado mas não registrado ⇒ `no_handler` (honesto — nunca uma proposta fabricada).
5. **GRAVA + COMPOUNDA** — cada proposta vira um nó provider-safe no cérebro (AURG); a PRÓXIMA missão enxerga as anteriores (compounding cross-domain — o M× na largura).

Toda saída tem `actuation_gate = requires_operator`. **Não existe** comando "executar no mundo real" — por design.

## As fases (F1–F4) e os arquivos

| Fase | O que é | Arquivo principal |
|---|---|---|
| **F1** | A ABSTRAÇÃO de actuator de domínio (o seam selado propose-only) | `app/Services/Ai/Organism/AbstractDomainActuator.php` + `AtlasOrganismService.php` + `AtlasOrganismRegistry.php` |
| **F2** | O 1º domínio REAL: FINANÇAS (gera reusando o backtest do strategy-loop; valida na honesty gate real DSR/PBO/holdout) | `app/Services/Ai/Organism/Finance/FinanceDomainProposer.php` + `FinanceDomainValidator.php` |
| **F3** | A ESPINHA DA MISSÃO CROSS-DOMAIN (um intent que SPANS domínios) | `app/Services/Ai/Organism/AtlasOrganismMissionService.php` + `OrganismDomainRouter.php` + `AtlasOrganismCommissionCommand.php` |
| **F4** | A FRONTEIRA propose-only ENDURECIDA (gate único + receita de auditoria) + o 2º domínio (MARKETING) provando que é domain-agnostic | `app/Services/Ai/Organism/AtlasOrganismActuationGate.php` + `EvidenceLedgerActuationReceiptStore.php` + `Marketing/*` + `AtlasOrganismActuateCommand.php` |

Peças reusadas (o "não construa um segundo motor"):

- **Plan-DAG de N3**: `ObraDecomposer` / `DeterministicObraDecomposer` (a decomposição é exatamente a de N3; a "delivery" por nó vira uma PROPOSTA de domínio em vez de um branch de código).
- **Cérebro**: `OrganismBrainAnchor` → `OpenBrainContextPackAnchor` (pack de contexto Open-Brain de N1) + `OrganismProposalRecorder` → `RealityGraphProposalRecorder` (grava no mesmo store AURG das obras/missões).
- **M-8 cross-domain**: `CrossDomainTaxonomyMap` (21 domínios canônicos, sinônimos) + `AtlasCrossDomainMeshService` (arestas allowed-crossing, flags sensitive, veto ARPTL).
- **Costura REAL de finanças (propose-only, já existia)**: `StrategyLoop/Strategy/StrategyRunner` (geração) + `TradingHonestyGate` → `HonestMetrics` (a métrica honesta DSR/PBO via CSCV/holdout selado no runtime Python numpy real). **Não reimplementamos nenhuma métrica de trading.**
- **Spine tables**: `2026_06_10_160000_create_atlas_organism_mission_tables.php` (`atlas_organism_missions` + `atlas_organism_nodes`) + `2026_06_10_180000_create_atlas_organism_actuation_tables.php` (`atlas_organism_actuations`, a auditoria append-only). SQL portável pgsql/sqlite, `intent` redigido, validação só com NÚMEROS honestos, nó sensitive fica `sensitive=true`.

## A abstração de domínio: como um novo domínio se pluga

Um domínio "entra no organismo" registrando um TRIPLET no `AtlasOrganismRegistry`:

```php
$registry->register(
    new MeuDomainProposer,   // intent + contexto-do-cérebro → DomainProposal (stubbável; um proposer real pode usar provider, GATED)
    new MeuDomainValidator,  // pontua a proposta numa MÉTRICA HONESTA real (nunca self-declared)
    new MeuDomainActuator,   // estende AbstractDomainActuator → só descreve a instrução do operador; NUNCA age
);
```

Três interfaces (`DomainProposer`, `DomainValidator`, `DomainActuator`) + a base selada `AbstractDomainActuator`. O serviço (`AtlasOrganismService`) é **domain-agnostic** — só consulta o registry por id canônico. Foi assim que MARKETING entrou em F4 sem tocar uma linha do núcleo, provando que finanças não é especial.

Regras que o triplet tem que honrar:
- O actuator **DEVE** estender `AbstractDomainActuator` (o gate recusa um implementador pelado).
- O validator usa uma métrica honesta real — o **fake-green clássico do domínio é PROIBIDO** (em finanças, win-rate; em marketing, vanity engagement: impressões/likes/click-bait).
- Sem candidato pontuável ⇒ **honest-empty** (`value: null`, `passed: false`), nunca um green fabricado.
- Domínio sensitive (finanças/saúde/cyber/…) fica **on-machine**: o `payload` estruturado nunca vira campo de provider (a `DomainProposal` o derruba da projeção provider-safe por construção).

## O domínio de trading (a costura real) — DSR/PBO, sem win-rate

`FinanceDomainProposer` reusa o backtest do strategy-loop on-machine (default `MeanReversionStrategy`, sem provider/ordem) quando recebe barras OHLCV; senão aceita retornos já computados; senão honest-empty. `FinanceDomainValidator` tem dois caminhos honestos, escolhidos pelo que o payload carrega:

1. **Bundle completo (o real)**: quando o payload traz os inputs da honesty stack (sibling windows/sharpes, scenarios, holdout) E o runtime Python está disponível, **delega** à `TradingHonestyGate` real → Deflated Sharpe N-deflacionado (com o piso de variância Lo-2002) / PBO via CSCV / holdout selado. `passed` = `certified` da gate.
2. **Honest-degrade**: sem os inputs completos ou sem runtime, declara isso honestamente e pontua o Sharpe anualizado in-process contra um piso materialmente positivo (0.5). O `method` sempre diz qual caminho rodou.

**Win-rate é PROIBIDO** — é o fake-green clássico de trading e nunca é consultado em NENHUM caminho. O `method` sempre carrega `win_rate_forbidden`.

## Compounding cross-domain — o M× na largura

Cada proposta validada vira um nó `domain` provider-safe no AURG (`RealityGraphProposalRecorder`), com a label redigida + brain_refs + os NÚMEROS honestos (nunca o payload, nunca a fonte), marcada `never_auto_promote`. A próxima missão lê as anteriores via `priorProposals()` e dobra o sinal no rationale — **uma proposta de finanças informa uma de marketing depois**. É o multiplicador composto atravessando domínios, não só dentro de um.

## A prova LIVE (custo zero, contra o pgsql de dev)

Uma missão cross-domain comissionada com proposers on-machine (finanças + marketing) — assertado e reportado:

| Sinal | Resultado |
|---|---|
| `DOMAINS_ROUTED` (≥2, via mapa M-8 real) | **YES** — `finance, marketing` |
| `TRADING_VALIDATED_BY_DSR` (métrica honesta, win-rate nunca) | **YES** — `annualized_sharpe` value=109.56, method declara `win_rate_forbidden` |
| `WINRATE_FORBIDDEN` (win-rate nunca aparece) | **YES** |
| `ACTUATE_REQUIRES_OPERATOR` (todo nó) | **YES** |
| `NO_REAL_WORLD_CALL` (status requires_operator, zero artefato de ação) | **YES** — `actuate()` é engine-final; actuator hostil REFUSADO |
| `PROPOSALS_IN_BRAIN` | **YES** |
| `CROSS_DOMAIN_COMPOUNDING` (2ª missão vê a 1ª) | **YES** — viu 6 propostas anteriores |
| `SENSITIVE_FINANCE_ON_MACHINE` (sensitive=true + payload derrubado da view) | **YES** |
| Limpeza | `REMAINING=0`, baseline do cérebro limpo |

Bateria de regressão (verde): **288 testes / 2813 asserções, 0 falhas** — Organism 42, Obra 42, RealExecution 30, Reality 36, AOBG/OpenBrain top-level 138.

## Os comandos do operador (propose-only, custo zero)

```
# comissiona uma missão cross-domain (decompõe → roteia → propõe+valida por domínio)
atlas:organism:commission "<intent que atravessa domínios>" --anchor=engineering --max=8 [--json] [--no-persist]

# atua uma proposta pela fronteira endurecida — SEMPRE retorna requires_operator + grava receita de auditoria
atlas:organism:actuate --domain=finance --intent="<intent>" [--json]

# superfície de revisão (READ ONLY): missões, plano cross-domain, e a auditoria append-only de atuações
atlas:organism:status [--mission=orgm-...] [--actuations] [--domain=finance] [--json]
```

**Não há** comando "executar no mundo real" — por design. A atuação de qualquer ação irreversível é do OPERADOR, nunca do Atlas.

## Os limites honestos (o que N4 NÃO é)

- **Propose-only, ponto.** Atlas substitui o trabalho de DECISÃO (propostas brain-anchored, validadas honestamente) e devolve a ação irreversível do mundo real pro humano. Nunca move dinheiro, nunca dá ordem, nunca gasta anúncio, nunca publica.
- **Aceleração é emergente, não automação total.** O organismo prepara e governa; o operador atua. A largura (mais domínios) e o compounding aumentam o multiplicador, mas o teto propose-only é permanente por construção.
- **Roteamento é determinístico (keyword + taxonomia).** Um router provider-backed (classificação NL mais rica) caberia atrás do mesmo seam, GATED — o serviço só consome um id canônico.
- **2 domínios shippados** (finanças real + marketing). A abstração é domain-agnostic; mais domínios são registração de triplet, não núcleo novo.
