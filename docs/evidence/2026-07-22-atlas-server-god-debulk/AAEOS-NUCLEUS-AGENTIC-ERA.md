# AAEOS → Núcleo da era agentica  
## Dev · Forge · Autônomos sob um OS de engenharia (humano fora do loop)

> **Status:** `design-v1` · **partially implemented 2026-07-23** (Control Plane + spine seam + quarantine catalog) · lane ARQUITETURA / Nucleus  
> **Pergunta do operador:** como o AAEOS *deveria* ser? Como estruturar? Os 3 executores (Dev, Forge, Autônomos) cobrem do trivial ao software mais difícil do mundo — com o humano **fora do loop**.  
> **Relação:** complementa `ATLAS-NUCLEUS-GOD-SOTA.md` (órgãos N9 Delivery + N10 Muscle + N11 Evidence).  
> **Não é:** a pasta `app/Services/Ai/Aaeos/Quarantine` (~132k). Isso é cemitério. Este doc é a **forma viva**.

---

## 0. Tese em uma frase

**AAEOS não é um executor nem uma pasta inchada — é o sistema operacional que faz qualquer executor elite (Dev · Forge · Autônomos) transformar intenção em software verificado, sem humano no loop de engenharia.**

```text
Intenção (mínima ou zero)
  → AAEOS (org + policy + plan + proof)
    → Executor elite (Dev | Forge | Autônomos)  ← mesma barra, modos diferentes
      → Nucleus (context · decide · provider · tools · delivery · evidence · learning)
        → Código + evidência + memória
```

O humano **não** é co-programador.  
O humano é, no máximo:

1. **Soberano de direção** (raro: “o que o Atlas deve maximizar”), e  
2. **Árbitro de risco irreversível** (só quando policy exige — deploy destrutivo, spend, legal).

Tudo o que hoje ainda é “review humano no meio” vira **gate de evidência + promoção automática** ou **halt com packet de decisão**.

---

## 1. O engano que o disco criou (e a verdade)

| Camada | Verdade |
|---|---|
| **AAEOS (conceito)** | OS da **organização** de engenharia agentica — departamentos, contratos, certificação, memória organizacional |
| **Pasta `Aaeos/` viva (~10k)** | Fragmento fino: maturity, phase router, quality bar, choreography… |
| **`Aaeos/Quarantine` (~132k)** | Cemitério de specs/planos materializados em PHP — **não** é o OS |
| **Programming / SelfConstruction / RealExecution** | Onde a **força** de engenharia realmente roda hoje |
| **Dev · Forge · Autônomos** | Três **modos de executor elite** — mesma barra, diferem por operador/escala/duração |

**Engano clássico:** “AAEOS = a fábrica de código”.  
**Correto:** “AAEOS = o governo + a orquestração da fábrica; Dev/Forge/Autônomos = os braços; Nucleus = o sistema nervoso”.

O doc antigo ainda diz “humano continua operador soberano no dia a dia”.  
A **nova era** (pedido do operador) sobe o patamar:

```text
LEGADO mental:  humano no loop de engenharia (review, prioriza, corrige a cada passo)
NOVO alvo:      humano FORA do loop de engenharia; só soberania + risco irreversível
```

Isso **não** apaga AAEOS — **define** o AAEOS verdadeiro.

---

## 2. Como AAEOS *deveria* ser (forma extraordinária)

### 2.1 Uma frase de produto

```text
AAEOS = o OS que faz o Atlas substituir uma área tech inteira em modo 24/7,
         com engenharia elite em qualquer escala, sem humano no loop.
```

### 2.2 O que AAEOS **é**

| Papel | Significado |
|---|---|
| **Governo** | Constituição de engenharia: o que pode mutar, sob quais gates, com que evidência |
| **Organização** | Departamentos virtuais (PM, Arch, Impl, QA, Sec, SRE, Release, Docs, Knowledge) — **papéis**, não 300 pastas |
| **Orquestração** | Escolhe **modo executor** (Dev/Forge/Autônomos) e **intensidade** (trivial → frontier) |
| **Prova** | Nada é “done” sem evidence pack + certification |
| **Memória org** | O que a org aprendeu (N12) sem reaprender a cada obra |
| **Admissão** | O que entra no loop autônomo vs o que exige soberano |

### 2.3 O que AAEOS **não é**

- Não é Dev  
- Não é Forge  
- Não é Autônomos  
- Não é Claude/Codex  
- Não é 132k de `*ContractService` na Quarantine  
- Não é dashboard de tickets  
- Não é “mais um OS” paralelo a Programming/SelfConstruction  

### 2.4 Estrutura mental (4 camadas, zero floresta)

```text
┌─────────────────────────────────────────────────────────────┐
│  AAEOS CONTROL PLANE  (órgão fino — o “governo”)             │
│  Intent→Objective · Mode select · Dept plan · Admission     │
│  Risk class · Autonomy ladder · Halt/escalation policy      │
└───────────────────────────┬─────────────────────────────────┘
                            │
┌───────────────────────────▼─────────────────────────────────┐
│  EXECUTOR MODES  (mesma barra elite · 3 modos)               │
│  DEV  |  FORGE  |  AUTÔNOMOS                                 │
└───────────────────────────┬─────────────────────────────────┘
                            │
┌───────────────────────────▼─────────────────────────────────┐
│  SHARED ENGINEERING SPINE  (= Nucleus N2–N12 no domínio eng) │
│  Spec · Context · Policy · Decide · Provider · Tools         │
│  Delivery · Evidence · Learning · Code Intelligence          │
└───────────────────────────┬─────────────────────────────────┘
                            │
┌───────────────────────────▼─────────────────────────────────┐
│  SURFACES (coroa)                                            │
│  CLI · MCP · HTTP Code · (review cockpit = verificação)      │
└─────────────────────────────────────────────────────────────┘
```

**Regra de ouro:** departamentos e modos **compartilham a spine**.  
Proibido copiar pipeline por executor (foi assim que nasceram OS gêmeas e cemitérios).

---

## 3. Os três executores — redefinição pétrea

### 3.1 Lei elite (já no AGENTS.md — reforçada)

```text
Dev · Forge · Autônomos = TRÊS EXECUTORES DE ENGENHARIA ELITE
  · mesma barra de qualidade (do trivial ao frontier)
  · diferença = presença humana + escala/duração + cadência
  · NUNCA "Dev = fast patch" · NUNCA "Autônomos = lixo barato"
```

### 3.2 Matriz de modos

| | **Dev** | **Forge** | **Autônomos** |
|---|---|---|---|
| **Operador no loop de engenharia** | Presente (dirige intenção viva) | Só no **planejamento / gates soberanos** | **Zero** (default da nova era) |
| **Cadência** | Minutos–horas · sessão | Horas–dias · obra | Contínuo 24/7 · fila |
| **Origem do trabalho** | Humano fala / cola / “faz X” | Spec-mãe / obra / packet | Brain `atlas:brain:next/seed` |
| **Músculo** | Session programming runtime | Multi-agent / multi-packet obra | Task serving + scoped commit |
| **Superfície típica** | `atlas dev` / ask | Forge Continuum / obra CLI | `atlas:brain` + `atlas:task` |
| **Quando AAEOS escolhe** | Intent interativo · ambiguidade alta · pairing | Escopo grande · multi-dept · longo horizonte | Fila seca de valor · self-improve · night |
| **Barra de qualidade** | Elite | Elite | Elite |
| **Teto de dificuldade** | Frontier | Frontier | Frontier |

**Crítico:** os três sobem a **mesma** ladder de dificuldade:

```text
L0  fix tipográfico / rename honest
L1  bug local com teste
L2  feature bounded 1 módulo
L3  cross-module + migration
L4  obra multi-dia · multi-agent
L5  frontier (kernel, correctness hard, distributed, security)
```

AAEOS **classifica L\*** e escolhe modo; o modo **não** limita L\*.  
Autônomos pode e **deve** fazer L5 quando policy + evidence permitem.

### 3.3 O que unifica os três (Shared Spine)

Tudo abaixo é **um** código path (Núcleo), não três cópias:

| Etapa | Órgão Nucleus | Conteúdo |
|---|---|---|
| Intent / domain | N2 | `engineering.*` profiles |
| Context | N3 | pack + codegraph + file delta |
| Policy / admission | N5 + AAEOS Control | autonomy, tools, budget, risk |
| Decide provider | N6–N7 | receipt + pipe único |
| Tools / host | N8 | file/shell/git/test |
| Delivery | N9 | patch → test → certify |
| Evidence | N11 | ledger + scorecard |
| Learning | N12 | proposals compostas |
| Muscle self-mod | N10 | só Autônomos (e Dev/Forge quando self-edit) |

Dev/Forge/Autônomos = **adapters de intake + scheduling + presença humana**,  
não reimplementações do cérebro.

---

## 4. AAEOS Control Plane — o núcleo fino (o que implementar de verdade)

Hoje a pasta Aaeos mistura governo fino + cemitério. O alvo:

```text
app/Services/Ai/Aaeos/   # OU re-home para EngineeringMuscle/AaeosControl/
  Control/
    AaeosIntentCompiler.php       # linguagem ambígua → Objective canônico
    AaeosModeSelector.php         # Dev | Forge | Autonomos  (Policy pura)
    AaeosDifficultyClassifier.php # L0–L5
    AaeosDepartmentPlanner.php    # papéis necessários (não 50 classes)
    AaeosAdmissionPolicy.php      # o que pode rodar sem soberano
    AaeosHaltPolicy.php           # quando parar e emitir Decision Packet
    AaeosCertificationGate.php    # done = evidence pack OK
  Projectors/
    AaeosOrgStateProjector.php    # read-only: o que a org está fazendo
  Runtime/
    AaeosCycleRuntime.php         # único mutador: abre ciclo, despacha modo, fecha
```

**Teto:** Control Plane inteiro preferencialmente **< 5–8k LOC** bem fatiado (não 142k).  
Quarantine **não** volta. Material reutilizável vira **dados/contratos** em docs ou catalogs, não 300 services.

### 4.1 Ciclo AAEOS (humano fora do loop)

```text
1. SENSE      telemetria · fila · incidents · goals · debt · rivals gaps
2. INTEND     Objective canônico (mesmo se origem = brain, não humano)
3. CLASSIFY   difficulty L* · risk · domain profile engineering.*
4. ADMIT      Autonomy ladder: auto | auto+notify | halt-sovereign
5. SELECT     mode ∈ {dev, forge, autonomos}   (policy, não feeling)
6. PLAN       dept graph mínimo (só papéis necessários)
7. DISPATCH   executor mode + spine (N3…N12)
8. PROVE      gates + evidence pack + certification
9. LEARN      N12 proposals · N4 promote se gated
10. LOOP      de volta a SENSE — sem humano
```

### 4.2 Admission (o coração do “out of loop”)

| Classe de risco | Comportamento default nova era |
|---|---|
| Reversível, testado, scoped | **Auto** — Autônomos ou Dev sem wait |
| Alto blast radius mas com gates verdes | **Auto + evidence obrigatória** |
| Irreversível / legal / $ alto / prod data wipe | **Halt-sovereign** — packet, não “pede review de código” |
| Ambiguidade de **objetivo de negócio** | Halt-sovereign **só no objetivo**, não em cada PR |

O humano **não** revisa diff por hábito.  
O humano revisa **só** quando a policy de admissão classifica soberania.

### 4.3 Departamentos = papéis, não pastas

```text
Product · Architecture · Implementation · QA · Security
SRE · Release · Docs · Knowledge · Review(verification)
```

Cada papel = **skill + policy + evidence contract** no spine,  
não `Aaeos/Quarantine/AtlasXyzDepartmentService.php` × 200.

---

## 5. Como os 3 modos plugam no Control Plane

### 5.1 Dev (operador presente — ainda elite, não “leve”)

```text
Humano: intenção viva / correção de rumo
AAEOS: classifica L*, admite, monta context pack, decide provider
Spine: delivery + evidence
Humano: pode interromper; default de qualidade = igual Autônomos
```

Uso legítimo na nova era: **pairing de intenção**, não microgerenciar engenharia.

### 5.2 Forge (obras longas — operador no plano, não no loop)

```text
Input: spec-mãe / obra / multi-packet
AAEOS: decompõe dept graph + packets
Forge: multi-agent sob spine única
Operador: só se admission = halt-sovereign no plano
```

### 5.3 Autônomos (default da era — zero operador)

```text
Brain: origina valor (anti-Goodhart)
Seed: gate ~50% recusa lixo
Task: implementa + commit escopado main
AAEOS: envolve o ciclo com admission + cert + learn
```

**Autônomos não é o AAEOS.**  
Autônomos é o **modo zero-operador** do AAEOS.

### 5.4 Diagrama de escolha de modo

```text
                 ┌─ intenção interativa humana? ──yes──► DEV
                 │
Objective ───────┼─ obra multi-packet / longo horizonte? ──yes──► FORGE
                 │
                 └─ fila / self-evolve / night / no human ──► AUTÔNOMOS
```

Empates resolvidos por **AdmissionPolicy** (budget, risk, L\*, SLA).

---

## 6. Relação com o Nucleus GOD (onde encaixa)

| Nucleus | Papel no AAEOS |
|---|---|
| N2 IntentDomain | profiles `engineering.dev` / `.forge` / `.autonomos` / `.qa`… |
| N3 ContextFabric | pack de obra/task |
| N5 PolicyPlane | tools/budget; AAEOS Admission é **policy de org** acima |
| N6–N7 Decide+Pipe | motores |
| N8 Tools | execução real |
| N9 Delivery | patch/certify — **um** lineage |
| N10 Muscle | Autônomos + control plane SC; AAEOS **orquestra**, não duplica queue |
| N11 Evidence | done |
| N12 Learning | memória da org de eng |

**AAEOS Control Plane** é uma **façade fina** que **compõe** N2–N12 no domínio engineering.  
Não recria Context/Memory/Provider.

Mapeamento de pastas hoje → alvo:

| Hoje | Alvo |
|---|---|
| `Aaeos/` vivo fino | AAEOS Control Plane |
| `Aaeos/Quarantine` | archive / nunca operate path |
| `Programming/` | N9 Dev/Forge muscle de código |
| `SelfConstruction/` + Brain | N10 Autônomos |
| `SoftwareCompanyStewardship/` | fiscal do operate path (AAEOS steward) |
| `RealExecution/` · `Product/` · `Obra/` | N9 delivery |
| `AgenticEngineeringOs` godfile gates | fundir em N11/N5 após split — não 3ª casa de gates |

---

## 7. Do estado atual → forma extraordinária (programa)

### Fase A — Honestidade (já em curso no GOD-DEBULK)

1. Nomear Quarantine como **não-AAEOS**  
2. Re-home do 1 fio vivo (Brain) fora da Quarantine  
3. Purga/archive do cemitério com prova  
4. Documentar: AAEOS ≠ pasta 142k  

### Fase B — Control Plane fino

1. Extrair/implementar os 7 componentes §4 (Intent, Mode, Difficulty, Dept, Admission, Halt, Cert)  
2. Characterization de `selectMode` + `admit`  
3. **Proibir** novo `*Service` em Aaeos sem 2º consumidor + OWNERSHIP  

### Fase C — Spine única

1. Dev e Forge **chamam** o mesmo DeliveryRuntime (N9)  
2. Autônomos **já** usa TaskServing — AAEOS só envolve admission/cert  
3. Matar pipelines paralelos (2 ledgers, 2 gates houses, 2 provider registries)  

### Fase D — Human-out-of-loop default

1. Autonomy ladder: default **auto** para L0–L3 com evidence  
2. Halt-sovereign só L4/L5 irreversível ou objetivo de negócio ambíguo  
3. Superfície humana prioritária = **verificação sob demanda** (terminal-first moat), não pair-programming contínuo  

### Fase E — Frontier sem humano

1. Autônomos + Forge competem em L5 com mesma cert bar  
2. Rivals/internal benchmarks medem uplift do **sistema**, não do chat  
3. Learning fecha loop: falha → proposal → (gated) promote → próxima origem Brain  

---

## 8. Invariantes (pétreas)

1. **Mesma barra elite** nos 3 modos.  
2. **Um spine** de engenharia (Nucleus).  
3. **AAEOS governa; não implementa patch.**  
4. **Done = evidence**, não narrativa de agente.  
5. **Humano fora do loop** de engenharia; soberano só em admissão/risco.  
6. **Autônomos = default 24/7**, não exceção.  
7. **Dev não é “leve”**; é elite com humano na intenção.  
8. **Forge não é “outro cérebro”**; é modo de escala.  
9. **Quarantine nunca é runtime.**  
10. **Anti-Goodhart:** Brain origina valor real, não proxy (LOC, landing rate cosmético).  
11. **Commit escopado na main** no músculo Autônomos.  
12. **Terminal-first:** moat = verificação; sem casca própria.  

---

## 9. API canônica (alvo)

```text
AaeosControlPlane::
  compileIntent(raw) -> Objective
  classifyDifficulty(Objective) -> L0..L5
  selectMode(Objective, WorldState) -> dev|forge|autonomos
  admit(Objective, Mode) -> auto|notify|halt_sovereign
  planDepartments(Objective, Mode) -> RoleGraph
  runCycle(Objective) -> CycleReceipt   # único mutador de ciclo
  projectOrgState() -> OrgProjection    # read-only
  certify(DeliveryEvidence) -> CertVerdict
```

Executor modes implementam **só**:

```text
ExecutorMode::
  accept(CyclePlan) -> WorkHandle
  progress(WorkHandle) -> Progress
  # delivery real sempre via N9/N10 spine
```

---

## 10. Imagem final

```text
                    HUMANO (raro)
                 direção · risco soberano
                           │
                           ▼
              ┌────────────────────────┐
              │   AAEOS CONTROL PLANE  │  ← núcleo organizacional fino
              │   sense→admit→dispatch │
              └───────────┬────────────┘
            ┌─────────────┼─────────────┐
            ▼             ▼             ▼
         DEV           FORGE       AUTÔNOMOS
      (intenção)      (obra)      (24/7 default)
            └─────────────┬─────────────┘
                          ▼
              SHARED ENGINEERING SPINE
         (Nucleus N2–N12 · proof-first)
                          │
                          ▼
              software verificado + memória
```

**O extraordinário não é ter 3 produtos.**  
**O extraordinário é um governo fino + uma spine + três modos elite que sobem ao frontier sem humano no loop.**

---

## 11. Resposta direta ao operador

| Pergunta | Resposta |
|---|---|
| AAEOS deveria ser a engenharia de software? | **Deveria ser o OS que *governa* a engenharia** — não a pasta de 142k nem um 4º executor |
| Como estruturar? | Control Plane fino + 3 modos + spine Nucleus única + coroa de verificação |
| Dev/Forge/Autônomos? | Mesma barra L0–L5; diferem por presença humana e escala; Autônomos = default out-of-loop |
| Humano fora do loop? | Sim: default auto; humano só soberania de objetivo e risco irreversível |
| O que fazer com o cemitério? | Não reanimar; extrair 0–N contratos úteis para **dados/docs**; resto archive |
| Relação com Nucleus GOD? | AAEOS compõe N9+N10+N11(+…); não duplica N3–N7 |

---

## 12. Próximos passos sugeridos

1. **Aceitar** este design como norte AAEOS da era agentica.  
2. Atualizar doc-mãe `atlas-agentic-engineering-os.md` com a tese **humano fora do loop** (hoje ainda descreve soberano cotidiano — drift).  
3. GOD-DEBULK: fechar re-home + archive Quarantine.  
4. Implementar `AaeosModeSelector` + `AaeosAdmissionPolicy` como Policy puras (characterization first).  
5. Ligar Autônomos cycle → `AaeosControlPlane::runCycle` (orquestração, sem copiar queue).  

---

*Fim design-v1 · AAEOS Nucleus Agentic Era*
