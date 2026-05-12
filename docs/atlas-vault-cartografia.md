# AtlasVault · Cartografia · Manual canônico

> Manual canônico da visualização viva do AtlasVault. Descreve o **objetivo**, a **filosofia**, o **DNA estético**, os **princípios de design**, o **vocabulário visual**, a **arquitetura técnica**, os **modos de navegação** e o **roadmap** da cartografia.
>
> Mockup canon: [`public/atlas-vault-cockpit-mockup.html`](../public/atlas-vault-cockpit-mockup.html)
>
> Versão: v1 · 12 mai 2026

---

## 0. Sumário executivo

A **Cartografia do AtlasVault** é a interface visual canônica que **substitui o graph view do Obsidian** para o segundo cérebro do Atlas. Em vez de uma nuvem de 150 bolinhas sem semântica, oferece um **mapa único navegável** com pan + zoom livres, peças posicionadas em coordenadas curadas, trilhas reais entre elas, e LOD (level of detail) progressivo: macro → fluxo → engrenagem → subcomponentes — tudo no mesmo plano, sem trocar de tela.

O usuário **lê, observa, decide**. A IA edita o Vault; o usuário navega, entende e pede evolução. Cada peça tem **ficha canônica de 7 campos** (Entrada · Saída · Depende de · Alimenta · Evidência · Gargalo · Próxima ação) que aparece no inspector lateral em hover/click. Nada de cliques modais, nada de telas trocando, nada de fricção.

Existe em **dois modos** explícitos: `Mapa` (visão geral, todas as peças visíveis em LOD por zoom) e `Foco` (uma peça vira protagonista com spotlight visual, vinheta no canvas e ficha completa). O canvas é um único `<div class="world">` de **1800×1380px** com `transform: translate scale` controlado por JS — pan via drag, zoom via scroll, sem libraries externas.

---

## 1. Contexto · por que existe

### 1.1. O problema do graph view do Obsidian

O graph view nativo do Obsidian é o estado da arte do "Vault em forma visual" — mas falha em três eixos críticos para o uso real do Atlas:

| Problema | Sintoma | Custo |
|---|---|---|
| **Caos sem semântica** | 150+ bolinhas iguais, posicionadas por física, sem distinguir setor/fluxo/papel | Impossível achar onde estou |
| **Sem hierarquia** | Tudo no mesmo plano — princípio filosófico e tarefa de programação coexistem como pontos | Importância visual = aleatória |
| **Sem ação** | Clique → abre nota. Sem ficha, sem "o que esta peça faz, com quem conecta, qual o próximo passo" | Não substitui leitura serial |

Resultado: o graph view é bonito mas **inerte**. Útil pra contemplar, inútil pra operar.

### 1.2. O que precisamos

> "Eu quero conseguir olhar para essa tela e sentir: agora eu entendo onde estou dentro do Atlas, o que essa peça faz, com quem ela conversa e para onde eu posso ir depois."

— Brief original do Vitor

A Cartografia é a resposta a esse brief. Substitui graph view por **cartografia editorial navegável**, mantendo as vantagens (cava livre, pan/zoom, todo o mapa visível) e adicionando:

- **Semântica forte**: cada peça tem posição curada (não-aleatória), tipo (engrenagem/lateral/sistema/continente), grupo (lane), e ficha de 7 campos.
- **Hierarquia editorial**: tipografia Cormorant Garamond italic + JetBrains Mono caps + hairlines deliberadas carregam peso por importância, não por dot size.
- **Operacionalidade**: hover em qualquer peça acende suas conexões + popula inspector com a ficha. Click entra em modo Foco com spotlight e ficha completa visível.

---

## 2. Objetivo

A cartografia tem **um único objetivo operacional** e três objetivos secundários derivados.

### 2.1. Objetivo primário

**Permitir ao Vitor compreender visualmente onde uma peça do Atlas está, o que ela faz, de quem depende, a quem alimenta, e qual a próxima ação — sem fricção de navegação.**

Qualquer decisão de design que aumente fricção (clique pra abrir, tela trocando, animação demorada, modal cobrindo contexto) é **anti-canon**.

### 2.2. Objetivos derivados

| # | Objetivo | Métrica de sucesso |
|---|---|---|
| ii. | **Substituir o graph view do Obsidian** como visualização canônica do AtlasVault | Vitor abre a cartografia, não o graph view, ao querer ver o todo |
| iii. | **Servir como gabarito mental** do Atlas AI Kernel Pipeline (espelho da `fluxoatlasaiv3.png`) | Pipeline canônico de 17 etapas + 4 lanes + loop de evidência sempre visualmente preciso |
| iv. | **Estender pra todo o Vault** (não só Atlas) — Memória, Obras, Forge, Filosofia, Gargalos | Cada continente tem mapa próprio navegável, mesma gramática visual |

### 2.3. Não-objetivos (explicitamente fora de escopo)

- **Não é landing page**. Não tem texto introdutório dentro da interface explicando o que é. A interface é a explicação.
- **Não é dashboard**. Não tem KPI cards uniformes, gráficos de barra, métricas de "uso da semana".
- **Não é editor**. O AtlasVault canônico é Obsidian — a cartografia visualiza. A IA edita o Vault; o usuário lê/decide.
- **Não é genérica**. Cada peça e cada conexão é curada à mão pelo Vitor (ou pela IA com aprovação). Não há render automático "graph view do filesystem".

---

## 3. Filosofia · 7 princípios

Os princípios abaixo são canon. Qualquer decisão de design futura deve passar pelo teste de cada um deles.

### i. Cartografia, não dashboard

A tela é um **mapa**, não um painel. Mapa tem geografia (peça tem lugar curado), tem trilhas (conexões reais), tem zoom (LOD). Dashboard tem widgets uniformes e métricas. Recusamos dashboard.

### ii. Sem fricção de navegação

Hover já mostra detalhe (inspector + trilhas). Click entra em Foco (não troca de tela). ESC ou click no fundo sai. Não há modal, não há tela carregando. **Toda informação está acessível com no máximo um gesto.**

### iii. Hierarquia tipográfica, não color-coding

Cores semânticas SaaS (verde = bom, vermelho = ruim, azul = info) são proibidas. **Tipografia carrega significado**: Cormorant italic 14 protagonista, Mono caps 9.5 metadata, hairline 1px @18% separa. Cores só em pontos canônicos:
- **bronze** = signature Atlas em ato cognitivo (peça ativa, glyphs ↑↓←→, eyebrows)
- **ink/ink2/ink3** = densidade textual (protagonista/secundário/whisper)
- **prussian** = timestamps, counts mono
- **moss** = positivo raro
- **rec-red** = gargalo, destrutivo

### iv. Peso, não sussurro

Inspirado no DNA Atlas (Don Corleone, Patek Philippe, whisky com peso). Motion = settle com peso, nunca fade apologético. Hairlines deliberadas, não translúcidas. Hover é **180ms ease-instinct**; focus é **320ms ease-considered settle scale 1.06**. Nada bouncy, nada PowerPoint.

### v. Vault canônico é fonte; cartografia é projeção

A cartografia **não** edita o Vault. Cada peça tem um campo `vault: 'path/no/obsidian.md'` que aponta pra fonte canônica. A cartografia é uma **read-model** visual do AtlasVault. Mudou no Vault, regenera a projeção.

### vi. Curadoria humana, sem auto-render

Não fazemos "graph view automático do filesystem". Coordenadas das peças, conexões, fichas de 7 campos — tudo é **escrito à mão** pelo Vitor (ou pela IA com aprovação humana). Isso garante:
- Posição com semântica geográfica (Atlas centro, Memória oeste, etc)
- Conexões reais (não inferidas por wikilinks)
- Fichas com nuance editorial

### vii. LOD progressivo, não overlay de detalhes

Detalhe aparece **por zoom**, não por click. Zoom-far mostra só nomes. Zoom-mid mostra nomes + deck. Zoom-close revela subcomponentes inline. Modal pra "ver mais" é anti-canon.

---

## 4. DNA estético · cream editorial Don Corleone

A Cartografia herda o DNA do app mobile Atlas (referência: `memory/project_atlas_editorial_grid.md` + `project_atlas_motion_principle.md`), adaptado pra cockpit desktop denso.

### 4.1. Família de referência

| Sim | Não |
|---|---|
| Patek Philippe Calatrava (sobriedade) | Apple "tech-luxury" performado |
| Pappy Van Winkle, Macallan 25 (whisky com peso) | Aesop / Hermès (luxo frágil "filho de rico") |
| Brioni, Kiton (alfaiataria patriarcal) | Saint Laurent skinny |
| Smythson, Dunhill 1893 (papel e couro) | Linear / Notion / SaaS-premium |
| Vito Corleone, Logan Roy (autoridade não-performada) | Wellness, minimalism aesthetic |

### 4.2. Vocabulário tátil

- **Papel**: cream puro (#f3ecda), cream-paper (#f7f1e3), cream-deep (#ebe2cb)
- **Tinta**: ink (#1a1714) protagonista, ink2/ink3 hierarquia
- **Signature**: bronze (#8a6a35), bronze-deep (#5e4520), bronze-soft (@28%), bronze-veil (@7%), bronze-glow (@18%)
- **Acento raro**: prussian timestamps, moss positivo, rec-red destrutivo

### 4.3. Tipografia canon

```
Cormorant Garamond  italic 400/500/600 — protagonista (nomes, decks, ledes)
JetBrains Mono       300/400/500       — folio, eyebrow, counts, romanos, labels SVG
Inter                400/500/600       — sans medium em pontos raros
```

Proxy editorial: Cormorant Garamond é proxy livre de Frau Halbfett (canon mobile não é Google Font). Fallback: 'IM Fell English', Georgia, serif.

### 4.4. Geometria editorial

- **Radius**: 2px (manuscript minimal). Nunca 8/12/16 (vibe SaaS).
- **Borders**: 1px `hair` (@13–18% ink) base; 1.5–2px `bronze` em ativo.
- **Box-shadow**: zero por padrão. Sombra só em estado `active` (`0 24px 52px bronze@26%` + ring `0 0 0 8px bronze-glow`).
- **Hairlines deliberadas**: separadores 1px @8–18% ink, sempre intencionais, nunca auto.
- **Background grid**: `radial-gradient` 1px @5% bronze a cada 32px — sussurro cartográfico, não régua de alinhamento.

---

## 5. Anti-canon · o que recusamos

Lista honesta do que tentamos e descartamos no caminho até o canon atual (v1 · 12 mai 2026).

### 5.1. Recusado: dark cockpit SaaS

Primeira iteração foi escura (#101113, Inter sans). Bateu com "sala de servidor" e fugiu do DNA Atlas. Substituído por cream editorial.

### 5.2. Recusado: sistema de 5 estados separados

Iteração intermediária tinha 5 telas (Universo / Sistema / Fluxo / Engrenagem radial / Subfluxo) que trocavam por click. Cada click recarregava a tela. Vitor: "ainda parece um dashboard com modos". Pivotado para **canvas único pan/zoom**.

### 5.3. Recusado: gear stage radial com órbitas N/S/E/W

Tentativa intermediária: ao clicar em uma peça, ela ia pro centro com 4 satélites (Entrada/Saída/Depende/Alimenta) orbitando. Bonito mas exigia reorganização da tela e perdia o contexto cartográfico. Substituído por modo Foco com spotlight + ficha no inspector.

### 5.4. Recusado: graph view automático

Renderizar automaticamente todas as notas do Vault como nós com conexões via wikilinks. Resultado: o problema original do Obsidian. Canon agora exige **coordenadas curadas + conexões explícitas**.

### 5.5. Recusado: princípios e subflow strip permanentes no canvas

Iteração tinha rodapé sempre visível com 17 chips + 5 princípios. Visualmente denso demais. Substituído por toggles que abrem como overlay slide-up sob demanda, e em v1 simplesmente integrado como regiões opcionais no rodapé do mundo (visível só em zoom-out completo).

### 5.6. Recusado: emoji / ícones decorativos

Glyphs Unicode só em pontos canônicos (`↑ ↓ ← → · ✦ ☷ △ ↗ ↩ ↻`). Ícones figurativos (📊 🔧 🧠) são proibidos — vibe SaaS gamificado.

### 5.7. Recusado: stagger PowerPoint na entrada

Animações com `transform: translateY(8px → 0)` + delays escalonados em cada peça. Apologético, "filho de rico no Hermès". Canon: zero animação de entrada da tela (página já está lá). Único motion: hover (180ms) e focus settle (320ms scale 1.06).

---

## 6. Vocabulário visual canon

Cada elemento da UI tem nome e classe CSS canônica. Mantenha o vocabulário em código.

### 6.1. Containers macro

| Classe | Função |
|---|---|
| `.app` | Grid raiz 56px topbar + 1fr work |
| `.topbar` | Barra superior fixa: brand · mode-toggle · search · folio |
| `.work` | Grid 1fr 320px: viewport + inspector |
| `.viewport` | Container do canvas com overflow:hidden, pan cursor |
| `.world` | O mundo 1800×1380px com `transform: translate scale` |
| `.world-svg` | SVG overlay com paths das trilhas |
| `.inspector` | Painel direito 340w com ficha 7 campos |

### 6.2. Floaters (sempre presentes, leves)

| Classe | Função |
|---|---|
| `.minimap-floater` | Lista de 6 continentes top-left |
| `.breadcrumb-floater` | Trilha de navegação top-center |
| `.back-to-map` | CTA top-right em modo foco |
| `.canvas-controls` | Botões `− + ⊟ ◉` bottom-right |
| `.zoom-indicator` | "135%" Mono caps bottom-left |

### 6.3. Peças (atoms) e regiões

| Classe | Função |
|---|---|
| `.region` | Lane/grupo de peças com header Mono caps |
| `.region-head` | Título da lane: Mono 10.5 caps + deck italic |
| `.atom` | Peça base (lateral, sistema, nota): name Cormorant 500 13px + deck italic 11px |
| `.atom-pipe` | Peça do pipeline central: + numeral romano Mono caps esquerda |
| `.atom .a-subs` | Subcomponentes inline (visíveis só em zoom-close) |
| `.ribbon` | Faixa ink "Atlas Kernel Pipeline" no topo da lane central |
| `.you-are-here` | Faixa bronze Mono caps "Você está aqui" **dentro** do atom focado |

### 6.4. Estados

| Classe | Significado |
|---|---|
| `.atom:hover` | Background cream-deep + border bronze-soft |
| `.atom.active` | **Focado**: border 2px bronze + box-shadow + ring + scale 1.06 |
| `.atom.kin` | **Relacionado** ao focado: opacity 0.92 sem blur |
| `.world.dim-others .atom:not(.active):not(.kin)` | Não-relacionado: opacity 0.04 + blur 2px + pointer-events none |
| `.world.mode-focus` | Modo Foco ativo (esmaece regions, ribbon, principles) |
| `.viewport.mode-focus` | Background cream-deep + vinheta radial spotlight |

### 6.5. Trilhas (SVG)

| Classe | Função |
|---|---|
| `.trail` | Path base: bronze@45%, 1.2px |
| `.trail.dashed` | Stroke-dasharray 5,3 — usado em feedback loop |
| `.trail.path-active` | Trilha ativa: bronze-deep sólido, 2.2px |
| `.trail-label` | Texto SVG Mono caps no meio da trilha |
| `.trail-label-bg` | Rect background do label, cream com border bronze 0.8px |
| `.label-active` | Aplicado em label de trilha relacionada ao foco |

### 6.6. Zoom LOD

| Classe | Aplicada quando | Comportamento |
|---|---|---|
| `.zoom-far` | scale < 0.65 | Esconde decks e subs; nomes em 12.5–13px |
| `.zoom-mid` | 0.65 ≤ scale < 1.15 | Mostra decks; esconde subs |
| `.zoom-close` | scale ≥ 1.15 | Tudo visível: nomes + decks + subs inline |

---

## 7. Arquitetura técnica

### 7.1. Stack

- **HTML/CSS/JS puro**, sem dependências externas além de Google Fonts.
- Standalone single-file: [`atlas-vault-cockpit-mockup.html`](../public/atlas-vault-cockpit-mockup.html).
- Sem build, sem bundler, sem framework. Recarrega no navegador.

### 7.2. Servir o arquivo

```bash
# Via Laravel (recomendado)
cd /Users/vitorepf/develop/Atlas/atlas-server
php artisan serve
# → http://127.0.0.1:8000/atlas-vault-cockpit-mockup.html

# Ou direto via file://
open public/atlas-vault-cockpit-mockup.html
```

### 7.3. Estrutura do arquivo

```
<head>
  <link href="…fonts.googleapis.com?family=Cormorant+Garamond+JetBrains+Mono+Inter">
  <style>
    :root { --tokens-cream-bronze-ink-… }
    .app, .topbar, .work, .viewport, .world, .atom, .trail, …
    @media (max-width: 1080px) { /* collapse responsivo */ }
  </style>
</head>
<body>
  <div class="app">
    <header class="topbar">brand · mode-toggle · search · folio</header>
    <div class="work">
      <div class="viewport">
        <aside class="minimap-floater">continentes</aside>
        <div class="breadcrumb-floater">trilha</div>
        <button class="back-to-map">← Voltar</button>
        <div class="world">
          <svg class="world-svg">defs + paths + labels</svg>
          <!-- conteúdo do mundo: ribbon, regions, atoms -->
        </div>
        <div class="zoom-indicator">135%</div>
        <div class="canvas-controls">− + ⊟ ◉</div>
      </div>
      <aside class="inspector">ficha 7 campos</aside>
    </div>
  </div>
  <script>
    /* dados (universo, pipeline, regions, otherWorlds, principles)
       + render functions
       + pan/zoom mechanics
       + focus mode
       + inspector
       + search */
  </script>
</body>
```

### 7.4. Pan + zoom (sem libraries)

```js
const view = { scale: 0.8, x: 0, y: 0, min: 0.35, max: 2.4 };

function applyTransform(animate) {
  $('#world').style.transform =
    `translate(${view.x}px, ${view.y}px) scale(${view.scale})`;
  // aplica classes LOD: zoom-far / zoom-mid / zoom-close
}

// pan: mousedown em viewport (não em .atom/.controls/.minimap) → set panning
// mousemove: view.x += dx; view.y += dy; applyTransform(false)
// mouseup: panning = false

// zoom: wheel event → zoomBy(factor, cursorX, cursorY)
// zoom relativo ao cursor: view.x = cx - (cx - view.x) * ratio
```

Performance: `will-change: transform` no `.world`. Transitions desligadas durante pan ativo (classe `.no-transition`). LOD classes evitam renderizar texto invisível.

### 7.5. SVG trails (estáticas no mundo)

Trilhas são desenhadas **uma vez** no mount, em coordenadas do mundo (não do viewport). Não recalculam em pan/zoom — escalam junto via o `transform` do `.world`.

```js
function drawAllTrails() {
  const svg = $('#worldSvg');
  // limpa paths/text/rect
  buildConnections().forEach(c => {
    const fr = rectCenter(document.getElementById(c.from));
    const tr = rectCenter(document.getElementById(c.to));
    let d;
    if (c.kind === 'sequence') d = `M ${fr.x} ${fr.b} L ${tr.x} ${tr.t}`;
    else if (c.kind === 'feedback') d = `M ${fr.r} ${fr.y} C …`;
    else d = `M ${sx} ${sy} C ${midX} ${sy}, ${midX} ${ey}, ${ex} ${ey}`;
    // cria <path> + opcional <rect class="trail-label-bg"> + <text class="trail-label">
  });
}
```

Tipos de conexão (`c.kind`):
- `sequence` — pipeline vertical entre etapas i → i+1. Linha reta.
- `feed` — lateral alimenta pipeline (Domain→vii, Cap→xii, HKS→viii, Biz→vi). Bézier C.
- `feedback` — loop de retorno (Evidence Ledger → Atlas Decide). Bézier longa, dashed.
- `governance` — saída do pipeline pra lateral (Learning→Proposal Inbox).

Label de cada conexão é uma string Mono caps: `ALIMENTA`, `CONTEXTO`, `MANAGED SYNC`, `CAPABILITY`, `EVIDÊNCIA`, `EVENTOS`, `PROPOSTAS`, `GUIA`.

---

## 8. Modelo de dados

Todos os dados vivem **em-arquivo no JS**, escritos à mão. Não há fetch, não há mock framework — é canon escrito como o Vault é escrito.

### 8.1. Universo

```js
const universe = [
  { id:'atlas', name:'Atlas', count:41 },
  { id:'memory', name:'Memória', count:26 },
  { id:'works', name:'Obras', count:18 },
  { id:'forge', name:'Forge', count:15 },
  { id:'philosophy', name:'Filosofia', count:22 },
  { id:'risks', name:'Gargalos', count:7 }
];
```

### 8.2. Pipeline (continente Atlas)

Cada etapa do pipeline tem 17 etapas com ficha completa:

```js
{ i:1, name:'Surface Plane', deck:'Usuário · App · Mobile · CLI · API · MCP',
  role:'Plano canônico de entrada. Cinco canais, um envelope único.',
  input:'Pedido humano cru em qualquer canal autorizado.',
  output:'Operation Envelope normalizado e assinado.',
  depends:['hks-vault'], unblocks:['pipe-2'],
  evidence:'Headers do envelope, hash do canal, tenant.',
  risk:'Surface tentar decidir — anti-canon documentado.',
  next:'Cobrir os 5 canais com mesma test suite.',
  vault:'docs/architecture/surface-plane.md',
  subs:[['App web','TS'],['App mobile','RN'],['CLI','Bun'],['API REST','PHP'],['MCP','SDK']] }
```

**Campos canônicos**: `i, name, deck, role, input, output, depends, unblocks, evidence, risk, next, vault, subs`.

### 8.3. Lanes laterais (regions)

```js
const regions = {
  domain: { id:'region-domain', side:'left', head:'Domain Plane',
    deck:'conecta domain/profile/flow',
    nodes:[
      { id:'dom-prog', name:'Programming', deck:'dev, forge, fix, review, QA' },
      …
    ]
  },
  cap: { … }, biz: { … }, hks: { … }, evi: { … }, doc: { … }
};
```

### 8.4. Outros continentes (otherWorlds)

```js
const otherWorlds = {
  memory: {
    title:'Memória',
    sub:'segundo cérebro · livros · filosofia · histórias',
    sections: [
      { id:'m-books', head:'Livros', deck:'leituras e marginalia', nodes:[
        { id:'mb-letters-stoic', name:'Cartas a Lucílio',
          deck:'Sêneca · 124 cartas',
          role:'Tronco filosófico do uso pessoal do Atlas.',
          input:'Leitura linear ou consulta.',
          output:'Princípios destilados em notes.',
          depends:['mb-medit'], unblocks:['principle-now'],
          evidence:'12 marginalias, 4 princípios extraídos.',
          risk:'Risco de citação descontextualizada.',
          next:'Mapear cartas → princípios canônicos.',
          vault:'memoria/livros/cartas-luclio.md' }
      ]},
      { id:'m-philo', head:'Filosofia', … },
      { id:'m-stories', … },
      { id:'m-principles', … }
    ]
  },
  works: { … }, forge: { … }, philosophy: { … }, risks: { … }
};
```

Cada nó de continente não-Atlas usa o **mesmo modelo de 7 campos** do pipeline. Conexões entre nós dentro de um continente são inferidas de `depends` / `unblocks` (ids que existem na mesma `otherWorlds[contId]`).

### 8.5. Coordenadas (LAYOUT_ATLAS)

```js
const LAYOUT_ATLAS = {
  pipeline:    { x: 660, y: 80,  w: 480 },
  pipelineStep: 64,
  pipelineRuntimeHeight: 220,  // step 12 (Runtime) é mais alto pra mostrar 7 subs
  domain:      { x: 40,  y: 100, w: 240 },
  cap:         { x: 40,  y: 620, w: 240 },
  biz:         { x: 340, y: 380, w: 260 },
  hks:         { x: 1200, y: 100, w: 280 },
  evi:         { x: 1200, y: 440, w: 280 },
  doc:         { x: 1200, y: 1040, w: 280 }
};
```

Continentes não-Atlas usam layout horizontal automático: title + sections em fila com largura fixa 380px e gap 60px.

---

## 9. Modos de navegação

A cartografia tem **exatamente dois modos** mutuamente exclusivos. O toggle no topbar mostra qual está ativo.

### 9.1. Modo Mapa (default no boot)

- `state.mode === 'map'`
- Background: cream + grid sutil 32px
- Sem vinheta, sem blur
- Hover em peça: trilhas conectadas viram bronze sólido + atom kin classe + inspector atualiza
- Mouseleave: limpa hover, inspector volta ao default
- Click em peça: **entra em modo Foco**

Comportamento esperado: usuário escaneia visualmente, identifica o que quer, hover pra confirmar, click pra mergulhar.

### 9.2. Modo Foco

- `state.mode === 'focus'`, `state.focusedId` setado
- Background: cream-deep com vinheta radial (escurece bordas)
- Atoms não-relacionados: opacity 0.04 + blur 2px + pointer-events none
- Atom focado: scale 1.06 + ring 8px bronze-glow + shadow forte + z-index 20
- Atom kin: opacity 0.92 sem blur
- Trail labels relacionadas: visíveis com background cream + border bronze
- Ribbon "Você está aqui" dentro do atom focado
- Zoom: animado pra 1.55 centrado na peça
- Inspector: ficha completa 7 campos + ações
- Breadcrumb: `AtlasVault › [Continente] › [Sistema] › [Peça]`
- CTA "← Voltar ao mapa" top-right

**Sair do modo Foco**: ESC, click no fundo do viewport, botão `⊟` (fit), botão `← Voltar`, ou click no toggle "Mapa".

### 9.3. Tabela de gestos

| Gesto | Em Mapa | Em Foco |
|---|---|---|
| Hover em peça | acende trilhas + atualiza inspector | (nenhum efeito; foco já manda) |
| Click em peça | entra em Foco daquela peça | troca foco pra nova peça |
| Click no fundo | nada | sai do Foco |
| Scroll wheel | zoom in/out no cursor | zoom in/out no cursor |
| Drag no fundo | pan livre | pan livre |
| ESC | nada | sai do Foco |
| Botão `⊟` (fit) | reajusta zoom pra ver tudo | sai do Foco + fit |
| Botão `◉` (kernel) | zoom em `pipe-1` (Surface Plane) | mesmo |
| Click no minimap | troca continente | sai do Foco + troca continente |

---

## 10. Sistema de zoom (LOD)

Level of detail progressivo. Detalhe aparece por zoom, nunca por click.

### 10.1. Limiares

```js
if (view.scale < 0.65)        world.classList.add('zoom-far');
else if (view.scale < 1.15)   world.classList.add('zoom-mid');
else                           world.classList.add('zoom-close');
```

### 10.2. O que cada nível mostra

| Nível | Faixa | Mostra | Esconde |
|---|---|---|---|
| **zoom-far** | <65% | nomes apenas, padding compacto 8/10, name 12.5–13px | decks, subs, region-head decks |
| **zoom-mid** | 65–115% | nomes + 1 linha deck italic, region-head com deck | subs |
| **zoom-close** | ≥115% | tudo visível: nome + deck + grid de subcomponentes inline | — |

### 10.3. Por que LOD em vez de modal

- **Continuidade visual**: a peça nunca "abre" — você só chega mais perto dela.
- **Contexto preservado**: vizinhos ainda visíveis mesmo em zoom-close.
- **Sem fricção**: scroll resolve, click não é necessário.

### 10.4. Range de zoom

- **min: 0.35** (35% — vê o mundo inteiro com folga)
- **max: 2.4** (240% — peça e seus subs ocupam quase o viewport)
- **focus default: 1.55** (zoom alvo em modo Foco)
- **boot: fit()** automático (calculado: `Math.min(vw/1800, vh/1380)`)

---

## 11. Modo Foco · spotlight protocol

Detalhamento do que acontece visualmente quando uma peça entra em foco.

### 11.1. Sequência de eventos (em ~420ms)

```
t=0       click numa peça
t+0       state.mode = 'focus'; state.focusedId = id
t+0       cockpit recebe class 'mode-focus' (CSS aciona vinheta + blur)
t+0       activatePathsFor(id):
          - paths conectados: classe path-active (stroke bronze-deep 2.2px)
          - labels conectados: classe label-active (opacity 1)
          - atoms conectados: classe kin
          - atom alvo: classe active
t+0       placeYouAreHere(id): ribbon "Você está aqui" inserido no atom
t+0       focusOnAtomVisual(id):
          - view.scale = 1.55
          - view.x/y centrados na peça
          - applyTransform(animate=true) → CSS transition 420ms ease-considered
t+0       renderInspector(meta): popula painel direito com ficha 7 campos
t+0       setBreadcrumb(meta): atualiza trail
t+0       modeToggle: ativa "Foco"
t+0       backToMap: classe 'show' (CTA aparece)
t+420     transition do world completa; usuário está em foco
```

### 11.2. Saída do foco (exitFocus)

Reverso de tudo acima:
- `clearActivePaths` remove `path-active`, `label-active`, `kin`, `active`
- `removeYouAreHere` deleta o ribbon
- `fit()` reajusta zoom pra mostrar mundo todo
- `renderInspectorDefault` repopula inspector com identidade do continente
- Classes `mode-focus` removidas; vinheta some

### 11.3. Por que vinheta + blur

Anteriormente (v0): atoms não-focados ficavam com `opacity 0.15`. Vitor observou que o texto fantasma ainda era legível — cérebro lia "Repair / Escalation" atrás da peça focada → ruído.

Canon v1: `opacity: 0.04 + filter: blur(2px) + pointer-events: none` torna o resto **visualmente ausente** sem fazer disappear (mantém contexto cartográfico vagamente). Vinheta radial reforça o spotlight.

Performance: blur em ~30 atoms é tranquilo em GPU moderno. Se virar gargalo, podemos pré-render num canvas estático.

---

## 12. Ficha 7 campos canônicos

A ficha é o **contrato editorial** de cada peça. Aparece no inspector lateral em hover (modo Mapa) e click (modo Foco).

### 12.1. Os 7 campos

| # | Campo | Glyph | Significado |
|---|---|---|---|
| 1 | **Entrada** | ↑ | O que entra nessa peça (input) |
| 2 | **Saída** | ↓ | O que ela produz (output) |
| 3 | **Depende de** | ← | Lista de peças prerequisitas (ids resolvidos para nomes) |
| 4 | **Alimenta** | → | Lista de peças que ela desbloqueia (ids resolvidos) |
| 5 | **Evidência** | ☷ | Como provamos que ela funcionou |
| 6 | **Gargalo** | △ | Risco / bloqueio conhecido (rec-red se preenchido) |
| 7 | **Próxima ação** | ✦ | Próximo passo concreto (destacado em bronze-veil) |

### 12.2. Por que esses 7

Foi explicitamente pedido pelo Vitor:

> "Quero ver claramente: você está aqui, isso recebe X, transforma em Y, depende de Z, alimenta W, e o próximo passo é K."

Mapeamento:
- "Você está aqui" → ribbon no atom + breadcrumb
- "Recebe X" → Entrada
- "Transforma em Y" → Saída
- "Depende de Z" → Depende de
- "Alimenta W" → Alimenta
- "Próximo passo é K" → Próxima ação
- +Evidência (pra fechar o loop: como provo que funcionou?)
- +Gargalo (pra honestidade operacional: o que não está bem?)

### 12.3. Renderização

```js
const fields = [
  { label:'Entrada',      glyph:'↑', key:'input' },
  { label:'Saída',        glyph:'↓', key:'output' },
  { label:'Depende de',   glyph:'←', key:'depends', map:true },
  { label:'Alimenta',     glyph:'→', key:'unblocks', map:true },
  { label:'Evidência',    glyph:'☷', key:'evidence' },
  { label:'Gargalo',      glyph:'△', key:'risk',  riskRow:true },
  { label:'Próxima ação', glyph:'✦', key:'next',  nextRow:true }
];
```

`map:true` significa que o valor é uma array de ids — passa por `humanizeId()` que resolve `pipe-7` → `'Domain / Profile / Flow'`.

### 12.4. Estados de exibição

- **Valor preenchido**: italic 14, color ink
- **Valor vazio (`—`)**: italic 14, color ink3 (whisper)
- **Risco com valor**: italic 14, color rec-red
- **Próxima ação**: background bronze-veil, padding interno, ressalta visualmente

---

## 13. Trilhas SVG · sistema de conexões

### 13.1. Definição

Trilhas são `<path>` SVG dentro de `<svg id="worldSvg" class="world-svg">`. Coordenadas em sistema do mundo (não viewport) — escalam junto com `transform` do `.world`.

### 13.2. Tipos de path

| `kind` | Geometria | Quando usar |
|---|---|---|
| `sequence` | Linha reta vertical: `M sx sy L ex ey` | Pipeline pai i → i+1 |
| `feed` | Bézier cúbica horizontal: `M sx sy C cp1 sy, cp2 ey, ex ey` | Lateral alimenta pipeline (ou vice-versa) |
| `feedback` | Bézier longa curva pela direita | Loop de retorno (Evidence → Decide) — dashed |
| `governance` | Bézier curta | Pipeline → lateral (saídas: Learning → Proposal Inbox) |

### 13.3. Labels SVG

Cada path tem **opcionalmente** um label Mono caps no meio:

```js
const labelText = c.label.toUpperCase();   // "ALIMENTA"
// rect background cream + border bronze
// text Mono caps bronze-deep, font-size 9.5px, letter-spacing 1.5px
```

Labels aparecem só quando classe `.label-active` (= trilha conectada ao atom focado). Em modo Mapa todas escondidas — canvas limpo.

### 13.4. Construção (buildConnections)

```js
function buildConnections() {
  if (state.continent === 'atlas') {
    // sequência pipeline
    for (i=1..16) push({ from:`pipe-${i}`, to:`pipe-${i+1}`,
                          kind:'sequence', label:'alimenta' });
    // laterais
    push({ from:'region-domain', to:'pipe-7', kind:'feed', label:'alimenta' });
    push({ from:'region-biz',    to:'pipe-6', kind:'feed', label:'contexto' });
    push({ from:'region-cap',    to:'pipe-12', kind:'feed', label:'capability' });
    push({ from:'region-hks',    to:'pipe-8', kind:'feed', label:'managed sync' });
    // loop
    push({ from:'region-evi',    to:'pipe-10', kind:'feedback', label:'evidência' });
    // saídas
    push({ from:'pipe-15', to:'region-evi', kind:'feed', label:'eventos' });
    push({ from:'pipe-16', to:'region-evi', kind:'feed', label:'propostas' });
    push({ from:'region-doc', to:'pipe-8', kind:'feed', label:'guia' });
  } else {
    // outros continentes: inferir de node.unblocks dentro de otherWorlds[contId]
    nodes.forEach(n => n.unblocks?.forEach(t => push({ from:n.id, to:t,
                                                       kind:'sequence', label:'alimenta' })));
  }
}
```

### 13.5. Markers (setas)

```svg
<marker id="tip" viewBox="0 0 8 8" markerWidth="8" markerHeight="8"
        refX="7" refY="4" orient="auto">
  <path d="M 0 0 L 8 4 L 0 8 Z" fill="currentColor"/>
</marker>
```

Triângulo cheio. Aplicado em `feed`, `sequence`, `governance`. **Não** em `feedback` (dashed sem seta, pra leitura "curva contínua").

---

## 14. Continentes do Vault

Seis continentes canônicos. Apenas Atlas tem mapa cheio em v1; outros têm mapa raso mas funcional.

### 14.1. Atlas (centro)

**Conteúdo curado**: Pipeline 17 etapas + 6 lanes laterais. É o continente operacional.

**Lanes**:
- Domain Plane (esquerda alta) · 8 domínios
- Capabilities / Harnesses (esquerda baixa) · 7 capabilities
- Business / Product (centro entre lane esq e pipeline) · 1 bloco
- Human Knowledge Surface (direita alta) · 4 blocos
- Evidence + Learning Loop (direita meio) · 5 blocos
- Documentation OS (direita baixa) · 1 bloco

### 14.2. Memória

**Conteúdo curado v1**: 4 sections com livros, filosofia, histórias, princípios destilados.

- **Livros**: Cartas a Lucílio, Meditações, O Padrinho, Xógum
- **Filosofia**: Estoicismo, Lindy
- **Histórias**: Vito Corleone
- **Princípios destilados**: Agora, Esforço, Canon, Peso

Conexões reais (`depends`/`unblocks`): livros → princípios → filosofia. Ficha 7 campos preenchida em cada nota.

### 14.3. Obras

Atlas App, ObraOS, Foundry, Sovereign OS. Dependências entre si.

### 14.4. Forge

SDD Core, Execution Workspace, Quality Gates, Work Splitter.

### 14.5. Filosofia

Princípios, Modelos mentais, Estética, Identidade.

### 14.6. Gargalos

4 gargalos abertos com severidade: Visual QA manual, Sandbox não-uniforme, Pruning do Ledger, Policies sem versionamento.

### 14.7. Roadmap pra continentes

Cada continente cresce no seu ritmo. Roteiro:
1. **Memória** — adicionar Podcasts (Lex Fridman, Tim Ferriss, Joe Rogan curated), Cursos, Artigos.
2. **Obras** — adicionar narrativas e empresas concretas.
3. **Forge** — expandir com mais harnesses e tools quando estabilizarem.
4. **Filosofia** — adicionar marginalia e cruzamentos.
5. **Gargalos** — manter rotativo (resolvido sai, novo entra).

---

## 15. Inspector · painel lateral

Coluna direita 340w sempre presente. Painel de leitura, não de edição.

### 15.1. Seções

```
┌─ INSPECTOR ─────────────────────┐
│ ENGRENAGEM · XIII. ATLAS AI…   │  ← ins-kind (Mono caps bronze)
│ Quality Gates                   │  ← title (Cormorant 500 24px)
│ Filtros antes do resultado…    │  ← ins-lede (italic 14.5)
│                                 │
│  ↑ ENTRADA                      │  ← ficha-7 .row
│     Resultado runtime…          │
│  ↓ SAÍDA                        │
│     Resultado validado…         │
│  ← DEPENDE DE                   │
│     Runtime / Executor          │
│  → ALIMENTA                     │
│     Repair · Evidence · Output  │
│  ☷ EVIDÊNCIA                    │
│     Gate results, failure modes │
│  △ GARGALO                      │  ← .risk → rec-red
│     Gates ainda manuais em VQA  │
│  ✦ PRÓXIMA AÇÃO                 │  ← .next → bronze-veil bg
│     Automatizar gates de VQA    │
│                                 │
│ AÇÕES                           │
│  Abrir nota no Vault         ↗  │
│  Ver loop de evidência       ↻  │
│  Gerar packet de evolução    ✦  │
│  Marcar gargalo              △  │
│                                 │
│ TAGS                            │
│  ENGRENAGEM  ATLAS AI  QUALITY  │
└─────────────────────────────────┘
```

### 15.2. Comportamento

- **Boot**: mostra identidade do continente (Atlas AI Kernel para o default).
- **Hover em modo Mapa**: troca pra ficha da peça em hover. Mouseleave: volta ao default.
- **Click em modo Mapa**: entra em Foco, ficha persiste do clicado.
- **Click em peça diferente em modo Foco**: troca foco, ficha atualiza.
- **Exit Foco**: volta ao default do continente.

### 15.3. Ações

Ações são botões editoriais (não submit). Em v1 são stubs visuais; em v2 conectam a:
- "Abrir nota no Vault" → URL Obsidian `obsidian://open?vault=AtlasVault&file=...`
- "Ver loop de evidência" → filtra trilhas pra apenas conexões com Evidence Ledger
- "Gerar packet de evolução" → cria Atlas AI proposal via MCP
- "Marcar gargalo" → flag no Vault + atualiza count em Gargalos

---

## 16. Decisões de design · log de pivôs

Cronologia das decisões. Cada pivô tem motivo e ganho.

### v0 · dark cockpit (descartado)
- **Era**: dark `#101113`, Inter, cards uniformes, sidebar+main+inspector 3-col com mode switcher.
- **Pivot por**: feedback Vitor "parece dashboard SaaS, não Atlas".
- **Ganho**: aplicação do DNA cream editorial Atlas no desktop.

### v1 · 4 estados separados (descartado)
- **Era**: Universo / Sistema / Fluxo / Engrenagem como 4 telas trocando por click.
- **Pivot por**: cada click recarregava tela; perdia contexto.
- **Ganho**: navegação sem fricção via canvas único.

### v2 · gear stage radial (descartado)
- **Era**: ao clicar peça, ela ia pro centro com 4 satélites N/S/W/E.
- **Pivot por**: reorganizava a tela toda; cartografia se perdia.
- **Ganho**: modo Foco mantém posição cartográfica; só aplica spotlight.

### v3 · canvas pan/zoom + 5 estados internos (intermediário)
- **Era**: canvas pan/zoom + history stack com 5 estados + breadcrumb com voltar nível + overlays subflow/princípios.
- **Pivot por**: usuário queria "uma cava como Obsidian graph view, sem fricção de estados".
- **Ganho**: canvas único com LOD, sem state machine.

### v4 · canvas + modos Mapa/Foco (canon v1)
- **Atual**: canvas único + apenas 2 modos (Mapa, Foco) + toggle explícito + spotlight + ficha 7 campos + vinheta + blur agressivo.
- **Justificativa**: simplest model que entrega "vejo o todo / entendo a peça" sem fricção.

### v5 (futuro) · ainda em aberto
- Adicionar transição de mundo (continente → continente) com animação editorial: páginas viram.
- Adicionar minimap real (não só lista de continentes) — pequeno overview do mundo atual no canto.
- Conectar ações do inspector a Obsidian/MCP real.
- Persistir state de zoom/pan via URL (`?continent=memory&focused=mb-letters-stoic&scale=1.55`).

---

## 17. Como adicionar conteúdo novo

### 17.1. Adicionar uma nota nova em um continente existente

Exemplo: adicionar um livro novo em Memória.

1. Abrir [`atlas-vault-cockpit-mockup.html`](../public/atlas-vault-cockpit-mockup.html)
2. Localizar `const otherWorlds = { memory: { sections: [...] } }`
3. Em `m-books.nodes`, adicionar novo objeto:

```js
{ id:'mb-meditation-art', name:'A Arte da Meditação', deck:'Matthieu Ricard',
  role:'Tronco de prática contemplativa.',
  input:'Leitura + 30min/dia.', output:'Estado mental treinado.',
  depends:[], unblocks:['principle-now'],
  evidence:'180 dias de streak no Atlas App.', risk:'Inflação espiritualista.',
  next:'Cruzar com Estoicismo.',
  vault:'memoria/livros/meditacao-arte.md' }
```

4. Salvar. Recarregar o navegador. A nota aparece como atom na section Livros, conectada a `principle-now` via path SVG.

### 17.2. Adicionar uma nova lane lateral no Atlas

1. Localizar `const regions = { … }`
2. Adicionar nova entrada com `id, side, head, deck, nodes[]`
3. Em `LAYOUT_ATLAS`, definir coordenadas `{ x, y, w }` pra ela
4. Em `renderAtlasWorld`, garantir que loop `Object.entries(regions)` renderiza
5. Em `buildConnections`, adicionar trilhas dela pro pipeline (ex: `{ from:'region-novalane', to:'pipe-X', kind:'feed', label:'…' }`)

### 17.3. Adicionar um continente novo

Exemplo: adicionar continente "Pessoas" (rede de contatos cogntivos).

1. Em `const universe = […]`, adicionar `{ id:'people', name:'Pessoas', count:N }`
2. Em CSS, adicionar `.mf-row[data-id="people"] .mf-dot { background: <cor>; }`
3. Em `const otherWorlds = { … }`, adicionar entrada `people: { title, sub, sections: […] }`
4. Recarregar. Aparece no minimap, click abre o mapa.

### 17.4. Adicionar nova etapa no pipeline Atlas

1. Em `const pipeline = [...]`, adicionar item com `i` próximo número (cuidado: numerais romanos vão até xx; expandir `ROMAN[]` se passar)
2. Em `LAYOUT_ATLAS.pipelineRuntimeHeight` ou `pipelineStep`, ajustar se altura é especial
3. `stepY[]` recalcula automaticamente via reduce
4. `buildConnections` adiciona sequência automaticamente
5. Conexões com laterais precisam ser adicionadas explicitamente

---

## 18. Roadmap conhecido

Lista honesta do que falta. Não é compromisso de prazo — é radar.

### 18.1. UX

- [ ] **Mini-mapa do mundo atual** no canto inferior esquerdo (não só lista de continentes) — mostra silhueta do mundo + posição do viewport, igual Figma.
- [ ] **URL state**: `?continent=atlas&focused=pipe-10&scale=1.55&x=…&y=…` — compartilhável e bookmarkable.
- [ ] **Keyboard navigation**: tab/shift-tab entre atoms; setas pra pan; +/- pra zoom.
- [ ] **Double-click** pra "mergulhar em subcomponentes" (cria mini-mundo só com os subs).
- [ ] **Transição entre continentes** com animação editorial (página vira) em vez de cut seco.
- [ ] **Modo apresentação** (full-screen, sem inspector, hide controls) pra screenshare.

### 18.2. Dados

- [ ] Expandir continente **Memória**: adicionar Podcasts, Cursos, Artigos como sections.
- [ ] Expandir **Obras** com narrativas em curso.
- [ ] **Filosofia**: adicionar marginalia + cruzamentos com obras.
- [ ] **Gargalos**: rotativo (resolver → arquivar → adicionar novo).

### 18.3. Integração com Atlas real

- [ ] **Ações do inspector** conectadas:
  - "Abrir nota no Vault" → `obsidian://open?...`
  - "Gerar packet de evolução" → Atlas AI MCP proposal
  - "Marcar gargalo" → escrever entry em `gargalos/<id>.md`
- [ ] **Pipeline live**: cores ou badges indicando estado real (etapas em produção vs beta vs canon) sincronizados com Evidence Ledger.
- [ ] **Search global semântico** via Atlas Memory Recall MCP.

### 18.4. Performance

- [ ] Pré-renderizar trilhas SVG como canvas estático se houver gargalo em mundos grandes (>50 atoms).
- [ ] Lazy-render de atoms fora do viewport (virtual list) se mundo passar de 200 peças.

---

## 19. Glossário

| Termo | Significado |
|---|---|
| **AtlasVault** | Segundo cérebro canônico em Obsidian, fonte de verdade humana do Atlas |
| **Atlas AI Kernel Pipeline** | 17 etapas canônicas que toda decisão do Atlas atravessa |
| **Continente** | Setor macro do Vault: Atlas / Memória / Obras / Forge / Filosofia / Gargalos |
| **Sistema** | Subdivisão dentro de um continente (ex: Atlas AI Kernel é um sistema do continente Atlas) |
| **Engrenagem** | Peça operacional dentro de um sistema (ex: Surface Plane é uma engrenagem do Kernel Pipeline) |
| **Lane** | Plano lateral que alimenta ou recebe do pipeline (Domain Plane, Capabilities, HKS, Evidence Loop) |
| **Atom** | Termo técnico no código pra qualquer peça clicável (engrenagem ou lateral) |
| **Region** | Container CSS pra um grupo de atoms (uma lane ou uma section) |
| **Trilha** | Path SVG conectando duas peças, com tipo (sequence/feed/feedback/governance) |
| **Ficha 7 campos** | Modelo canônico de leitura de cada peça: Entrada · Saída · Depende · Alimenta · Evidência · Gargalo · Próxima ação |
| **Modo Mapa** | Estado default: mundo livre, hover acende, click entra em Foco |
| **Modo Foco** | Spotlight em uma peça: zoom 1.55 + vinheta + blur no resto + ficha completa no inspector |
| **LOD** | Level of Detail — quanto detalhe cada peça mostra por nível de zoom (far/mid/close) |
| **Kin** | Peça relacionada ao foco (em depends/unblocks) — opacity 0.92 em modo Foco |
| **Spotlight** | Conjunto de classes CSS que destaca foco: vinheta radial + blur + opacity 0.04 |
| **Você está aqui** | Ribbon Mono caps bronze inserido **dentro** do atom focado |
| **Folio** | Identificador editorial no canto: `vol I · no 004 · 12 mai 2026` |
| **Eyebrow** | Texto Mono caps acima de um título (ex: "ENGRENAGEM · XIII. ATLAS AI KERNEL") |
| **Hairline** | Linha 1px fina, deliberada, com cor @8–18% (separadores editoriais) |
| **Numeral romano** | Sistema de numeração de etapas e sections (`i ii iii iv v vi vii viii ix x xi xii xiii xiv xv xvi xvii`) |

---

## 20. Apêndice · referências canônicas

### 20.1. Referência visual de origem

A imagem `fluxoatlasaiv3.png` (em `~/Downloads/`) é o **gabarito conceitual** da visualização. Replicada com vocabulário Atlas (cream + Cormorant + bronze + hairlines) em vez do estilo enterprise-diagram da original.

### 20.2. Canon Atlas relacionado

- **Schema canônico do frontmatter da cartografia**: [`docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema.md`](engineering-knowledge-base/vault/atlas-vault-cartography-schema.md) — contrato visual `graph_*` que coexiste com a camada semântica do Living Architecture Graph
- **Living Architecture Graph (L1)**: [`docs/engineering-knowledge-base/system-graph/living-architecture-graph-contract.md`](engineering-knowledge-base/system-graph/living-architecture-graph-contract.md)
- **Node catalog (canon do frontmatter semântico)**: [`docs/engineering-knowledge-base/system-graph/node-catalog-and-build-contract.md`](engineering-knowledge-base/system-graph/node-catalog-and-build-contract.md)
- **Vault contracts (gerenciamento de notas)**: [`docs/engineering-knowledge-base/vault/contracts.md`](engineering-knowledge-base/vault/contracts.md)
- Mockup mobile canônico: `atlas-app/atlas-home-editorial-mockup.html`
- DNA estético: `memory/project_atlas_motion_principle.md`
- Editorial grid: `memory/project_atlas_editorial_grid.md`
- TDAH design: `memory/feedback_atlas_tdah_design.md`
- No mock data: `memory/feedback_atlas_no_mock.md`

### 20.3. Inspirações operacionais

- **Obsidian Canvas / Graph View** — referência de cava navegável (pan/zoom livre)
- **Figma** — referência de canvas com LOD por zoom
- **Miro** — referência de mind-map cartográfico
- **Tufte / Edward Tufte** — referência de densidade editorial sem ruído

### 20.4. Filosofia operacional

- "A cartografia é leitura, não escrita."
- "Detalhe aparece por zoom, não por click."
- "Tipografia carrega significado; cor é signature, não código."
- "Cada peça tem ficha; cada conexão tem nome; nada é decoração."
- "O mapa não é o território — é a projeção curada dele."

---

_Documento canônico v1 · 12 mai 2026 · mantido por Vitor + Atlas AI_
