> Cleanup status: archived.
> Canonical replacement: docs/engineering-knowledge-base/atlas-ai-master-architecture.md; docs/engineering-knowledge-base/atlas-ai-operating-system.md.
> Cleanup note: Historical V1 implementation plan. Preserve for history; not current architecture.

# Atlas V1 — Documento de Implementação Técnica

> **Briefing técnico para implementação por Claude Code**
>
> **Codinome do projeto:** Atlas
> **Nome público do app:** Atlas
> **Versão:** V1 (Sprint 1)
> **Data:** 27 de abril de 2026
> **Operador:** Vitor (uso pessoal exclusivo)
> **Documento mestre relacionado:** Atlas Documento Mestre v3.0

---

## INSTRUÇÕES PARA CLAUDE CODE

Você é o agente responsável por implementar o Atlas V1 conforme este documento. Antes de começar:

1. **Leia o Documento Mestre v3.0 inteiro** antes de escrever qualquer código. Ele contém as 7 Leis Fundacionais que governam decisões arquiteturais. Quando este documento de implementação omitir detalhe, recorra ao mestre.

2. **Respeite a hierarquia constitucional:** Capítulos 0-3 do mestre são imutáveis. Toda decisão técnica deve respeitá-los.

3. **Pergunte antes de desviar:** se algo neste documento parecer subótimo tecnicamente, pergunte ao operador antes de mudar. Não otimize sem consulta.

4. **Construa em ordem:** os mini-sprints (1A → 1E) são sequenciais. Cada um valida antes do próximo. Não pule.

5. **Valide cada mini-sprint com o operador** antes de avançar. Mostre o que foi feito, peça aprovação.

6. **Princípios não-negociáveis durante implementação:**
   - Captura sub-10s (Princípio 1)
   - Soberania de dados — markdown local + PostgreSQL local (Princípio 5, Lei 6)
   - Captura passiva > ativa (Princípio 11, Lei 2)
   - operator_id em todas as tabelas desde dia 1 (Lei 6)
   - Schema versionado, append-only timeline (Lei 6)
   - Sistema mensura próprio custo cognitivo (Lei 3, tabela `system_cost`)

7. **Stack técnica é decisão fechada.** Não substitua componentes (ex: não troque PostgreSQL por SQLite, não troque Expo por React Native CLI). Mudança de stack só com consulta explícita ao operador.

8. **Tudo em português brasileiro** quando interagir com operador. Código e comentários técnicos em inglês.

---

## Índice

1. [Visão Geral do Sprint 1](#1-visão-geral-do-sprint-1)
2. [Pré-Requisitos do Ambiente](#2-pré-requisitos-do-ambiente)
3. [Decisões Arquiteturais Fechadas](#3-decisões-arquiteturais-fechadas)
4. [Repositórios e Estrutura](#4-repositórios-e-estrutura)
5. [Mini-Sprint 1A — Fundação Técnica](#5-mini-sprint-1a--fundação-técnica)
6. [Mini-Sprint 1B — Captura iPhone + HealthKit](#6-mini-sprint-1b--captura-iphone--healthkit)
7. [Mini-Sprint 1C — Action Button iPhone (POLISH ABSOLUTO)](#7-mini-sprint-1c--action-button-iphone-polish-absoluto)
8. [Mini-Sprint 1D — Watch Ultra App](#8-mini-sprint-1d--watch-ultra-app)
9. [Mini-Sprint 1E — Polish Final e TestFlight](#9-mini-sprint-1e--polish-final-e-testflight)
10. [Critérios de Aceite por Mini-Sprint](#10-critérios-de-aceite-por-mini-sprint)
11. [Anexos Técnicos](#11-anexos-técnicos)

---

## 1. Visão Geral do Sprint 1

### 1.1 Objetivo

Construir Atlas V1 — app iOS dedicado (iPhone + Watch Ultra companion) para captura passiva e ativa de pensamentos, dados de saúde e estado cognitivo do operador, com sincronização local via Tailscale para backend Node.js + PostgreSQL no MacBook do operador, integrando com vault Obsidian em markdown.

### 1.2 Escopo do Sprint 1

**Incluso:**
- App iPhone
- Captura de áudio (hold-to-record + Action Button)
- Captura de texto rápido com tag de domínio
- Captura de foto + voice note
- Captura de decisão estruturada (template no app)
- HealthKit sync diário (sono, atividade, FC, HRV, peso)
- Tela de inbox (últimas 50 capturas)
- Watch Ultra app companion com Action Button laranja
- Complication permanente da missão atual
- Backend Node.js com 4 endpoints essenciais
- Whisper.cpp local transcrevendo áudios
- Sincronização com vault filesystem (markdown)
- Sincronização com PostgreSQL (metadados + métricas)
- TestFlight Internal Testing distribuição

**Não incluso (Sprint 2+):**
- Live Activity / Dynamic Island
- Widgets na home screen (Sprint 2)
- Briefing matinal renderizado no app (Sprint 2)
- Dashboard das 4 dimensões (Sprint 3)
- Search semântica (Sprint 3)
- Edição de notas no app (provavelmente nunca)
- Council UI (Sprint 3+)

### 1.3 Tempo Estimado

30-36 horas distribuídas em 5-6 semanas.

### 1.4 Critério de Sucesso Global

Operador captura 5+ pensamentos por dia via Atlas (iPhone + Watch) com fricção <10s, dados aparecem no vault como markdown e no PostgreSQL como métricas, e sistema roda 7 dias sem crashes ou perda de captura.

---

## 2. Pré-Requisitos do Ambiente

Antes do Mini-Sprint 1A começar, verifique:

### 2.1 Mac do Operador

- [ ] macOS 15+ (Sequoia ou superior)
- [ ] Xcode 16+ instalado
- [ ] Node.js 20+ via `nvm` ou Homebrew
- [ ] Homebrew funcionando
- [ ] Git configurado (user.name, user.email)
- [ ] PostgreSQL 16 + pgvector (instalar conforme Manual de Implementação)
- [ ] Vault Obsidian em `~/atlas/` com estrutura completa
- [ ] Schema PostgreSQL aplicado (de `~/atlas-server/db/schema.sql`)
- [ ] Tailscale instalado e ativo
- [ ] Whisper.cpp instalado: `brew install whisper-cpp`

### 2.2 Conta Apple Developer

- [ ] Conta ativa (operador já tem via BlackInk)
- [ ] Acesso ao App Store Connect
- [ ] Acesso ao Certificates, IDs & Profiles
- [ ] 2FA configurado

### 2.3 iPhone do Operador

- [ ] iPhone 16 Pro Max
- [ ] iOS 18+ instalado
- [ ] Tailscale instalado e logado
- [ ] TestFlight instalado (próximo passo após Mini-Sprint 1A)
- [ ] Apple ID logado e mesmo de conta Developer

### 2.4 Apple Watch Ultra

- [ ] Apple Watch Ultra (1ª ou 2ª geração)
- [ ] watchOS 11+
- [ ] Pareado com iPhone do operador

### 2.5 Tools Adicionais

- [ ] EAS CLI: `npm install -g eas-cli`
- [ ] Expo CLI: `npm install -g expo`
- [ ] Conta Expo (gratuita): operador pode criar em expo.dev

### 2.6 Verificação

Antes de começar, rodar:

```bash
# Versões
node --version          # >= v20
npm --version           # >= 10
xcodebuild -version     # >= 16
psql --version          # 16.x
brew list whisper-cpp   # presente

# Conexões
ping -c 3 localhost     # PostgreSQL local
psql -U atlas -d atlas -c "SELECT version();"  # Schema aplicado
ls ~/atlas/_templates/  # Vault existe
```

Se algum check falhar, **pare e reporte ao operador** antes de prosseguir.

---

## 3. Decisões Arquiteturais Fechadas

Estas decisões NÃO são revisitadas durante a implementação. Seguir conforme.

### 3.1 Stack iPhone

| Componente | Escolha | Razão |
|---|---|---|
| Framework | Expo SDK 53 (managed) | Operador domina React Native, OTA updates |
| Linguagem | TypeScript estrito | Type safety obrigatória |
| Navegação | Expo Router (file-based) | Padrão atual Expo |
| Estado global | Zustand | Simples, sem overhead |
| Server state | TanStack Query | Cache + refetch + offline |
| UI library | Tamagui | Performance nativa, themable |
| Storage local | MMKV (`react-native-mmkv`) | Sync, rápido |
| Banco local | Não. Usa MMKV + cache | Sprint 1 não precisa banco mobile |
| Autenticação | Nenhuma | Uso pessoal exclusivo, sem Face ID (Lei 3) |
| Áudio | `expo-av` | Padrão Expo |
| HealthKit | `@kingstinct/react-native-healthkit` | Mais moderno, background delivery |
| Notificações | `expo-notifications` | Padrão |
| Haptics | `expo-haptics` | Feedback tátil |
| HTTP client | `fetch` nativo + TanStack Query | Sem axios |

### 3.2 Stack Watch

| Componente | Escolha | Razão |
|---|---|---|
| Framework | Swift nativo + SwiftUI | Watch app robusto exige nativo |
| Estrutura | Watch app target dentro do projeto Expo | Coexiste, compartilha bundle ID |
| Comunicação iPhone | WatchConnectivity framework | Apple oficial |
| Standalone capability | Sim | Watch Ultra grava sem iPhone próximo |
| Persistência local | SQLite simples | Áudios + metadata até sync |

### 3.3 Stack Backend

| Componente | Escolha | Razão |
|---|---|---|
| Runtime | Node.js 20+ | Operador conhece |
| Framework | Fastify | Mais rápido que Express, schema validation |
| Banco | PostgreSQL 16 + pgvector | Já decidido (Lei 6) |
| Validação | Zod | Type-safe, integra com TS |
| File watching | `chokidar` | Watch vault filesystem |
| Whisper | `whisper-cpp` via shell command | Mais rápido que API |
| Markdown parsing | `gray-matter` | Frontmatter YAML |
| HTTP client | `undici` | Nativo Node.js, rápido |
| Logging | `pino` | Estruturado, performático |

### 3.4 Stack Rede

| Componente | Escolha | Razão |
|---|---|---|
| Túnel | Tailscale | Já configurado pelo operador |
| TLS | Não obrigatório (rede privada) | Tailscale já cifra ponta-a-ponta |
| Hostname | `macbook.tail-net-name.ts.net` | MagicDNS do Tailscale |
| Porta | 3737 (custom, fácil de lembrar) | Evita conflito com 3000/8080 |

### 3.5 Bundle IDs

- **App principal:** `com.vitor.atlas`
- **Watch app:** `com.vitor.atlas.watchkitapp`
- **Watch extension:** `com.vitor.atlas.watchkitapp.watchkitextension` (se necessário)

### 3.6 Capabilities iOS Necessárias

Configurar no Apple Developer Portal e em `app.json`:

- HealthKit (Background Delivery)
- App Groups (para compartilhar dados Watch ↔ iPhone)
- Background Modes (Audio, Background fetch, Background processing)
- Push Notifications (preparar pro Sprint 2, não usado em Sprint 1)
- Siri & Shortcuts (para App Intents do Action Button)

---

## 4. Repositórios e Estrutura

### 4.1 Repositórios a Criar

Operador vai criar manualmente (ou orientar Claude Code a criar):

1. **`atlas-app`** — projeto Expo (iPhone + Watch)
2. **`atlas-server`** — backend Node.js + Fastify
3. **`atlas-server`** — já existe, contém schema.sql, scripts existentes

GitHub privado. Push obrigatório a cada mini-sprint concluído.

### 4.2 Estrutura `atlas-app`

```
atlas-app/
├── app/                              # Expo Router (file-based)
│   
│   ├── (main)/
│   │   ├── _layout.tsx
│   │   ├── index.tsx                 # Home — botões de captura
│   │   ├── capture/
│   │   │   ├── audio.tsx
│   │   │   ├── text.tsx
│   │   │   ├── photo.tsx
│   │   │   └── decision.tsx
│   │   ├── inbox.tsx                 # Lista de capturas recentes
│   │   └── checkin.tsx               # Check-in 3x/dia
│   └── _layout.tsx                   # Root layout
├── components/
│   ├── ui/                           # Primitivos Tamagui
│   │   ├── Button.tsx
│   │   ├── DomainTag.tsx             # 4 botões coloridos
│   │   ├── Card.tsx
│   │   └── ...
│   ├── capture/
│   │   ├── AudioRecorder.tsx
│   │   ├── DomainPicker.tsx
│   │   └── ...

├── lib/
│   ├── api/                          # Client do backend
│   │   ├── client.ts
│   │   ├── captures.ts
│   │   ├── metrics.ts
│   │   └── notes.ts
│   ├── storage/
│   │   └── mmkv.ts                   # Wrapper MMKV
│   ├── healthkit/
│   │   ├── permissions.ts
│   │   ├── sync.ts
│   │   └── types.ts
│   ├── audio/
│   │   ├── recorder.ts
│   │   └── upload.ts

│   ├── intents/                      # App Intents (Action Button, Siri)
│   │   └── CaptureIntent.swift       # Swift bridging
│   └── utils/
│       ├── domain.ts                 # Tags de domínio
│       └── time.ts
├── hooks/
│   ├── useCapture.ts
│   ├── useHealthKit.ts

│   └── useApi.ts
├── stores/                           # Zustand
│   ├── captureStore.ts

│   └── settingsStore.ts
├── types/
│   ├── api.ts
│   ├── capture.ts
│   └── healthkit.ts
├── ios/                              # Gerado por prebuild
│   └── AtlasWatch/                   # Watch app target Swift
│       ├── AtlasWatchApp.swift
│       ├── ContentView.swift
│       ├── AudioRecorder.swift
│       ├── ComplicationProvider.swift
│       ├── ConnectivityManager.swift
│       └── Assets.xcassets/
├── assets/
│   ├── icon.png                      # 1024x1024
│   ├── adaptive-icon.png
│   ├── splash.png
│   └── ...
├── plugins/                          # Expo config plugins
│   └── withWatchApp.js               # Plugin para Watch app
├── app.json                          # Config Expo
├── eas.json                          # Config EAS Build
├── package.json
├── tsconfig.json
├── tamagui.config.ts
├── babel.config.js
├── metro.config.js
└── README.md
```

### 4.3 Estrutura `atlas-server`

```
atlas-server/
├── src/
│   ├── server.ts                     # Entry point
│   ├── routes/
│   │   ├── captures.ts               # POST /captures
│   │   ├── metrics.ts                # POST /metrics/ingest
│   │   ├── notes.ts                  # GET /notes/* 
│   │   └── checkin.ts                # POST /checkin
│   ├── services/
│   │   ├── vault.ts                  # Operações no vault filesystem
│   │   ├── transcription.ts          # Whisper.cpp wrapper
│   │   ├── postgres.ts               # Client PostgreSQL
│   │   └── audioProcessing.ts
│   ├── lib/
│   │   ├── markdown.ts               # gray-matter wrapper
│   │   ├── logger.ts                 # pino
│   │   └── validation.ts             # zod schemas
│   ├── jobs/
│   │   ├── vaultWatcher.ts           # chokidar watching ~/atlas/
│   │   └── healthSyncProcessor.ts
│   └── types/
│       └── index.ts
├── scripts/
│   └── start.sh                      # Inicia servidor + watcher
├── package.json
├── tsconfig.json
├── .env.example
└── README.md
```

---

## 5. Mini-Sprint 1A — Fundação Técnica

**Tempo estimado:** 8 horas
**Objetivo:** Projeto Expo configurado, Apple Developer setup, primeiro build no iPhone, backend Node mínimo respondendo via Tailscale.

### 5.1 Tarefas

#### 5.1.1 Setup Apple Developer (1h)

Operador faz, Claude Code orienta:

1. Apple Developer Portal → Certificates, IDs & Profiles
2. Identifiers → Add → App IDs → App
3. Description: "Atlas"
4. Bundle ID: `com.vitor.atlas`
5. Capabilities: HealthKit, App Groups, Background Modes
6. Continue → Register
7. Em App Groups: criar `group.com.vitor.atlas.shared`

#### 5.1.2 Criar projeto Expo (30min)

```bash
cd ~
npx create-expo-app@latest atlas-app --template blank-typescript
cd atlas-app

# Inicializa Git
git init
git branch -M main
git add .
git commit -m "Atlas V1 — initial Expo project"
```

#### 5.1.3 Instalar dependências core (15min)

```bash
# Core
npm install \
  expo-router \
  expo-av \
  expo-haptics \
  expo-notifications \
   \
  expo-image-picker \
  expo-file-system \
  expo-secure-store \
  expo-device \
  expo-constants

# Estado e dados
npm install \
  zustand \
  @tanstack/react-query \
  react-native-mmkv

# UI
npm install \
  tamagui \
  @tamagui/config \
  @tamagui/babel-plugin

# HealthKit
npm install @kingstinct/react-native-healthkit

# Forms (decisão estruturada)
npm install react-hook-form zod @hookform/resolvers

# Dev
npm install -D \
  @types/react \
  typescript \
  @typescript-eslint/eslint-plugin \
  prettier
```

#### 5.1.4 Configurar `app.json` (30min)

```json
{
  "expo": {
    "name": "Atlas",
    "slug": "atlas",
    "version": "1.0.0",
    "orientation": "portrait",
    "icon": "./assets/icon.png",
    "userInterfaceStyle": "automatic",
    "scheme": "atlas",
    "newArchEnabled": true,
    "ios": {
      "bundleIdentifier": "com.vitor.atlas",
      "buildNumber": "1",
      "supportsTablet": false,
      "infoPlist": {
        "NSHealthShareUsageDescription": "Atlas usa seus dados de saúde para gerar insights pessoais sobre sono, atividade e estado físico.",
        "NSHealthUpdateUsageDescription": "Atlas pode registrar dados de wellness baseados nas suas capturas.",
        "NSMicrophoneUsageDescription": "Atlas grava áudios curtos para você capturar pensamentos rapidamente.",
        "NSCameraUsageDescription": "Atlas captura fotos com anotações para registro visual de momentos.",
        "NSPhotoLibraryUsageDescription": "Atlas acessa fotos quando você quiser anexar uma imagem a uma captura.",
        
        "UIBackgroundModes": ["audio", "fetch", "processing"],
        "ITSAppUsesNonExemptEncryption": false
      },
      "entitlements": {
        "com.apple.developer.healthkit": true,
        "com.apple.developer.healthkit.access": ["health-records"],
        "com.apple.security.application-groups": ["group.com.vitor.atlas.shared"]
      }
    },
    "plugins": [
      "expo-router",
      [
        "@kingstinct/react-native-healthkit",
        {
          "NSHealthShareUsageDescription": "Atlas usa seus dados de saúde para gerar insights pessoais.",
          "NSHealthUpdateUsageDescription": "Atlas pode registrar dados baseados em capturas.",
          "background": true
        }
      ],
      [
        "expo-av",
        {
          "microphonePermission": "Atlas grava áudios curtos para você capturar pensamentos rapidamente."
        }
      ],
      [
        "expo-image-picker",
        {
          "photosPermission": "Atlas acessa fotos para anexar imagens a capturas.",
          "cameraPermission": "Atlas usa câmera para capturas com foto."
        }
      ],

    ],
    "experiments": {
      "typedRoutes": true
    },
    "extra": {
      "eas": {
        "projectId": "WILL_BE_SET_AFTER_EAS_INIT"
      }
    }
  }
}
```

#### 5.1.5 Configurar EAS (45min)

```bash
# Login Expo
eas login

# Inicializa EAS no projeto
eas init

# Aceita criar projeto
# Anote o projectId retornado e cole em app.json > extra.eas.projectId
```

Criar `eas.json`:

```json
{
  "cli": {
    "version": ">= 13.0.0",
    "appVersionSource": "remote"
  },
  "build": {
    "development": {
      "developmentClient": true,
      "distribution": "internal",
      "ios": {
        "simulator": false,
        "resourceClass": "m-medium"
      }
    },
    "preview": {
      "distribution": "internal",
      "ios": {
        "simulator": false,
        "resourceClass": "m-medium"
      }
    },
    "production": {
      "distribution": "store",
      "ios": {
        "resourceClass": "m-medium"
      },
      "autoIncrement": true
    }
  },
  "submit": {
    "production": {
      "ios": {
        "appleId": "WILL_BE_FILLED_BY_OPERATOR",
        "ascAppId": "WILL_BE_FILLED_AFTER_FIRST_SUBMIT",
        "appleTeamId": "WILL_BE_FILLED_BY_OPERATOR"
      }
    }
  }
}
```

#### 5.1.6 Primeiro build dev no iPhone (1.5h)

```bash
# Conecta iPhone via cabo
# Trust this computer no iPhone se primeira vez

# Build dev
eas build --platform ios --profile development

# Aguarda build (15-20min)
# Após pronto, baixa via QR code no iPhone
# Instala app
```

**Validação:** app abre no iPhone, mostra tela em branco "Atlas".

#### 5.1.7 Setup Tamagui + tema (45min)

`tamagui.config.ts`:

```typescript
import { createTamagui } from 'tamagui'
import { config } from '@tamagui/config/v3'

const appConfig = createTamagui(config)

export type Conf = typeof appConfig
declare module 'tamagui' {
  interface TamaguiCustomConfig extends Conf {}
}

export default appConfig
```

Configurar babel.config.js para Tamagui:

```javascript
module.exports = function (api) {
  api.cache(true)
  return {
    presets: ['babel-preset-expo'],
    plugins: [
      [
        '@tamagui/babel-plugin',
        {
          components: ['tamagui'],
          config: './tamagui.config.ts',
          logTimings: true,
        },
      ],
    ],
  }
}
```

#### 5.1.8 Backend Node.js mínimo (1.5h)

```bash
cd ~
mkdir atlas-server
cd atlas-server
npm init -y
npm install fastify @fastify/cors zod pg gray-matter chokidar pino dotenv
npm install -D typescript @types/node @types/pg tsx
npx tsc --init
```

`src/server.ts`:

```typescript
import Fastify from 'fastify'
import cors from '@fastify/cors'
import { Client } from 'pg'

const fastify = Fastify({
  logger: {
    transport: {
      target: 'pino-pretty',
      options: { colorize: true }
    }
  }
})

await fastify.register(cors, { origin: true })

// Health check
fastify.get('/health', async () => {
  return { status: 'ok', service: 'atlas-server', version: '1.0.0' }
})

// DB ping
fastify.get('/db-ping', async () => {
  const client = new Client({
    user: 'atlas',
    database: 'atlas',
    host: 'localhost',
    port: 5432
  })
  await client.connect()
  const result = await client.query('SELECT now()')
  await client.end()
  return { ok: true, time: result.rows[0].now }
})

const PORT = 3737

try {
  await fastify.listen({ port: PORT, host: '0.0.0.0' })
  fastify.log.info(`Atlas backend rodando em http://0.0.0.0:${PORT}`)
} catch (err) {
  fastify.log.error(err)
  process.exit(1)
}
```

`package.json` scripts:

```json
{
  "scripts": {
    "dev": "tsx watch src/server.ts",
    "start": "tsx src/server.ts"
  }
}
```

Rodar:

```bash
npm run dev
```

#### 5.1.9 Tailscale teste (30min)

No Mac, descobrir hostname Tailscale:

```bash
tailscale status
# Anote o hostname tipo: macbook-vitor.tail-XXXX.ts.net
```

No iPhone (com Tailscale ativo), abre Safari:

```
http://macbook-vitor.tail-XXXX.ts.net:3737/health
```

Deve retornar JSON `{"status":"ok",...}`.

#### 5.1.10 Cliente HTTP no app (30min)

`atlas-app/lib/api/client.ts`:

```typescript
import { MMKV } from 'react-native-mmkv'

const storage = new MMKV()

const BACKEND_HOST = storage.getString('backend_host') ?? 'macbook-vitor.tail-XXXX.ts.net'
const BACKEND_PORT = 3737

export const API_BASE = `http://${BACKEND_HOST}:${BACKEND_PORT}`

export async function apiGet<T>(path: string): Promise<T> {
  const r = await fetch(`${API_BASE}${path}`)
  if (!r.ok) throw new Error(`API error ${r.status}`)
  return r.json()
}

export async function apiPost<T>(path: string, body: unknown): Promise<T> {
  const r = await fetch(`${API_BASE}${path}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body)
  })
  if (!r.ok) throw new Error(`API error ${r.status}`)
  return r.json()
}
```

#### 5.1.11 Tela home temporária validando conexão (30min)

`atlas-app/app/index.tsx`:

```tsx
import { useEffect, useState } from 'react'
import { Text, View } from 'react-native'
import { apiGet } from '../lib/api/client'

export default function Home() {
  const [status, setStatus] = useState('Conectando...')

  useEffect(() => {
    apiGet<{ status: string }>('/health')
      .then(r => setStatus(`✓ Backend OK — ${r.status}`))
      .catch(e => setStatus(`✗ Erro: ${e.message}`))
  }, [])

  return (
    <View style={{ flex: 1, justifyContent: 'center', alignItems: 'center' }}>
      <Text style={{ fontSize: 24, fontWeight: 'bold' }}>Atlas</Text>
      <Text style={{ marginTop: 20 }}>{status}</Text>
    </View>
  )
}
```

### 5.2 Critério de Aceite Mini-Sprint 1A

- [ ] Projeto Expo `atlas-app` criado com TypeScript
- [ ] Apple Developer App ID `com.vitor.atlas` registrado com capabilities
- [ ] EAS configurado com projectId
- [ ] Build dev instalado no iPhone físico
- [ ] App abre, mostra tela com "Atlas" e status do backend
- [ ] Backend Node.js + Fastify rodando em `localhost:3737`
- [ ] Tailscale: iPhone acessa backend via hostname Tailscale
- [ ] Endpoint `/health` retorna JSON
- [ ] Endpoint `/db-ping` retorna timestamp do PostgreSQL
- [ ] App mostra status de conexão na tela home
- [ ] Tudo committed e pushed em GitHub privado

**Pause aqui. Operador valida visualmente. Não avance pra 1B sem aprovação.**

---

## 6. Mini-Sprint 1B — Captura iPhone + HealthKit

**Tempo estimado:** 8 horas
**Objetivo:** Captura completa funcionando no iPhone (áudio, texto, foto), HealthKit sincronizando, Whisper transcrevendo no backend.

### 6.1 Tarefas

#### 6.1.1 Schema das tabelas relevantes (já existem em atlas schema.sql)

Confirmar que estas tabelas estão presentes:
- `notes`
- `metrics`
- `timeline_events`
- `cognitive_state`
- `system_cost`

Se não, aplicar `~/atlas-server/db/schema.sql`.

#### 6.1.2 Backend — endpoint de captura de texto (1h)

`atlas-server/src/routes/captures.ts`:

```typescript
import { FastifyInstance } from 'fastify'
import { z } from 'zod'
import { writeFile, mkdir } from 'fs/promises'
import { join } from 'path'
import { Client } from 'pg'

const VAULT_PATH = '/Users/vitorepf/atlas'

const TextCaptureSchema = z.object({
  type: z.literal('text'),
  content: z.string().min(1),
  domain: z.enum(['health', 'blackink', 'finance', 'relationships', 'learning', 'system', 'casal', 'other']),
  mood_before: z.number().int().min(1).max(5).optional(),
  energy_level: z.number().int().min(1).max(5).optional(),
  location: z.enum(['home', 'office', 'external']).optional(),
})

export default async function captureRoutes(fastify: FastifyInstance) {
  fastify.post('/captures', async (request, reply) => {
    const data = TextCaptureSchema.parse(request.body)
    const now = new Date()
    const timestamp = now.toISOString()
    const filename = `${now.toISOString().replace(/[:.]/g, '-')}.md`
    const filepath = join('01-inbox', filename)
    const fullPath = join(VAULT_PATH, filepath)

    // Garante diretório
    await mkdir(join(VAULT_PATH, '01-inbox'), { recursive: true })

    // Cria nota markdown
    const frontmatter = `---
type: capture
created: ${timestamp}
domain: ${data.domain}
mood_before: ${data.mood_before ?? ''}
energy_level: ${data.energy_level ?? ''}
location: ${data.location ?? ''}
source: text
processed: false
---

${data.content}

## Triagem (no inbox processing)
- Categoria: 
- Destino: 
- Ação relacionada: 
`

    await writeFile(fullPath, frontmatter, 'utf-8')

    // Registra no banco
    const client = new Client({
      user: 'atlas', database: 'atlas', host: 'localhost', port: 5432
    })
    await client.connect()

    const opResult = await client.query(
      "SELECT id FROM operators WHERE name = 'Vitor' LIMIT 1"
    )
    const operatorId = opResult.rows[0].id

    await client.query(
      `INSERT INTO notes (operator_id, type, title, frontmatter, area, status, file_path, content_preview)
       VALUES ($1, 'capture', $2, $3, $4, 'pending', $5, $6)`,
      [
        operatorId,
        `Captura ${timestamp}`,
        JSON.stringify({
          domain: data.domain,
          source: 'text',
          mood_before: data.mood_before,
          energy_level: data.energy_level,
        }),
        data.domain,
        filepath,
        data.content.substring(0, 200)
      ]
    )

    await client.query(
      `INSERT INTO timeline_events (operator_id, ts, source, entity_type, payload)
       VALUES ($1, $2, 'capture_text', 'capture', $3)`,
      [operatorId, timestamp, JSON.stringify({ domain: data.domain, length: data.content.length })]
    )

    await client.end()

    return reply.send({ ok: true, path: filepath, created: timestamp })
  })
}
```

Registrar em `server.ts`:

```typescript
import captureRoutes from './routes/captures'
await fastify.register(captureRoutes)
```

#### 6.1.3 Backend — endpoint de captura de áudio com Whisper (2h)

```typescript
// atlas-server/src/routes/captures.ts (continuação)

import { spawn } from 'child_process'

const AUDIO_DIR = '/Users/vitorepf/atlas-server/audio-temp'
const WHISPER_MODEL = '/opt/homebrew/share/whisper-cpp/ggml-base.en.bin'  // ajustar path

async function transcribeAudio(audioPath: string): Promise<string> {
  return new Promise((resolve, reject) => {
    const proc = spawn('whisper-cpp', [
      '-m', WHISPER_MODEL,
      '-f', audioPath,
      '--language', 'pt',
      '--output-txt'
    ])

    let output = ''
    proc.stdout.on('data', (data) => { output += data.toString() })
    proc.stderr.on('data', (data) => { fastify.log.info(data.toString()) })

    proc.on('close', async (code) => {
      if (code !== 0) return reject(new Error(`Whisper failed: ${code}`))

      // whisper-cpp salva .txt no mesmo path
      const txtPath = audioPath.replace(/\.[^.]+$/, '.txt')
      const { readFile } = await import('fs/promises')
      try {
        const text = await readFile(txtPath, 'utf-8')
        resolve(text.trim())
      } catch (err) {
        reject(err)
      }
    })
  })
}

// Endpoint upload áudio
fastify.post('/captures/audio', async (request, reply) => {
  const data = await request.file()  // requires @fastify/multipart
  if (!data) return reply.code(400).send({ error: 'No file' })

  const domain = (data.fields.domain as any)?.value ?? 'other'
  const timestamp = new Date().toISOString()
  const audioFilename = `${timestamp.replace(/[:.]/g, '-')}.m4a`

  await mkdir(AUDIO_DIR, { recursive: true })
  const audioPath = join(AUDIO_DIR, audioFilename)

  const buffer = await data.toBuffer()
  await writeFile(audioPath, buffer)

  // Transcreve
  const transcription = await transcribeAudio(audioPath)

  // Cria nota markdown com transcrição
  const filename = `${timestamp.replace(/[:.]/g, '-')}.md`
  const filepath = join('01-inbox', filename)
  const fullPath = join(VAULT_PATH, filepath)

  const frontmatter = `---
type: capture
created: ${timestamp}
domain: ${domain}
source: voice
audio_path: ${audioPath}
processed: false
---

${transcription}

---

## Áudio Original
[[${audioFilename}]]

## Triagem
- Categoria:
- Destino:
- Ação relacionada:
`

  await writeFile(fullPath, frontmatter, 'utf-8')

  // Registra no banco (igual texto)
  // ...

  return reply.send({ ok: true, path: filepath, transcription })
})
```

Instalar `@fastify/multipart`:

```bash
cd atlas-server
npm install @fastify/multipart
```

#### 6.1.4 Tela de captura de áudio (1.5h)

`atlas-app/app/(main)/capture/audio.tsx`:

```tsx
import { useState, useRef } from 'react'
import { View, Text, Pressable } from 'react-native'
import { Audio } from 'expo-av'
import * as Haptics from 'expo-haptics'
import { router } from 'expo-router'
import { uploadAudio } from '../../../lib/api/captures'

export default function AudioCapture() {
  const [recording, setRecording] = useState<Audio.Recording | null>(null)
  const [isRecording, setIsRecording] = useState(false)
  const [duration, setDuration] = useState(0)
  const [domain, setDomain] = useState<string | null>(null)
  const intervalRef = useRef<NodeJS.Timeout | null>(null)

  async function startRecording() {
    try {
      await Audio.requestPermissionsAsync()
      await Audio.setAudioModeAsync({
        allowsRecordingIOS: true,
        playsInSilentModeIOS: true,
      })

      const { recording } = await Audio.Recording.createAsync(
        Audio.RecordingOptionsPresets.HIGH_QUALITY
      )

      setRecording(recording)
      setIsRecording(true)
      setDuration(0)

      Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Medium)

      intervalRef.current = setInterval(() => {
        setDuration(d => d + 1)
      }, 1000)
    } catch (err) {
      console.error('startRecording', err)
    }
  }

  async function stopRecording() {
    if (!recording) return

    setIsRecording(false)
    if (intervalRef.current) clearInterval(intervalRef.current)

    await recording.stopAndUnloadAsync()
    const uri = recording.getURI()
    setRecording(null)

    Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Heavy)

    if (uri && domain) {
      await uploadAudio(uri, domain)
      router.back()
    }
  }

  if (!domain) {
    return (
      <View style={{ flex: 1, justifyContent: 'center', padding: 20 }}>
        <Text style={{ fontSize: 24, marginBottom: 30, textAlign: 'center' }}>
          Sobre o quê é?
        </Text>
        {(['blackink', 'health', 'finance', 'other'] as const).map(d => (
          <Pressable
            key={d}
            onPress={() => {
              setDomain(d)
              Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light)
              startRecording()
            }}
            style={{
              padding: 30,
              marginBottom: 15,
              backgroundColor: domainColor(d),
              borderRadius: 16,
            }}
          >
            <Text style={{ fontSize: 20, color: 'white', fontWeight: '600', textAlign: 'center' }}>
              {domainLabel(d)}
            </Text>
          </Pressable>
        ))}
      </View>
    )
  }

  return (
    <View style={{ flex: 1, justifyContent: 'center', alignItems: 'center' }}>
      <Pressable
        onLongPress={() => {}}
        onPressOut={stopRecording}
        style={{
          width: 200,
          height: 200,
          borderRadius: 100,
          backgroundColor: isRecording ? '#ff4444' : '#444',
          justifyContent: 'center',
          alignItems: 'center',
        }}
      >
        <Text style={{ color: 'white', fontSize: 48 }}>●</Text>
      </Pressable>
      <Text style={{ marginTop: 30, fontSize: 24 }}>{formatDuration(duration)}</Text>
      <Text style={{ marginTop: 10, opacity: 0.6 }}>Solte para parar</Text>
    </View>
  )
}

function domainColor(d: string): string {
  return {
    blackink: '#0066ff',
    health: '#22c55e',
    finance: '#f59e0b',
    other: '#71717a',
  }[d] ?? '#71717a'
}

function domainLabel(d: string): string {
  return {
    blackink: 'BlackInk',
    health: 'Saúde',
    finance: 'Finanças',
    other: 'Outro',
  }[d] ?? d
}

function formatDuration(s: number): string {
  const m = Math.floor(s / 60)
  const ss = s % 60
  return `${m}:${ss.toString().padStart(2, '0')}`
}
```

`atlas-app/lib/api/captures.ts`:

```typescript
import { API_BASE } from './client'

export async function uploadAudio(uri: string, domain: string) {
  const formData = new FormData()
  formData.append('file', {
    uri,
    name: 'audio.m4a',
    type: 'audio/m4a',
  } as any)
  formData.append('domain', domain)

  const r = await fetch(`${API_BASE}/captures/audio`, {
    method: 'POST',
    body: formData,
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  if (!r.ok) throw new Error(`Upload failed ${r.status}`)
  return r.json()
}

export async function captureText(content: string, domain: string) {
  const r = await fetch(`${API_BASE}/captures`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ type: 'text', content, domain }),
  })
  if (!r.ok) throw new Error(`Capture failed ${r.status}`)
  return r.json()
}
```

#### 6.1.5 Tela de captura de texto (1h)

`atlas-app/app/(main)/capture/text.tsx`:

```tsx
import { useState } from 'react'
import { View, Text, TextInput, Pressable, KeyboardAvoidingView, Platform } from 'react-native'
import { router } from 'expo-router'
import * as Haptics from 'expo-haptics'
import { captureText } from '../../../lib/api/captures'

export default function TextCapture() {
  const [content, setContent] = useState('')
  const [submitting, setSubmitting] = useState(false)

  async function submit(domain: string) {
    if (!content.trim() || submitting) return

    setSubmitting(true)
    Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success)

    try {
      await captureText(content, domain)
      router.back()
    } catch (err) {
      console.error(err)
      setSubmitting(false)
    }
  }

  return (
    <KeyboardAvoidingView
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      style={{ flex: 1 }}
    >
      <View style={{ flex: 1, padding: 20 }}>
        <TextInput
          autoFocus
          multiline
          value={content}
          onChangeText={setContent}
          placeholder="O que tá pensando?"
          style={{
            flex: 1,
            fontSize: 18,
            textAlignVertical: 'top',
            paddingTop: 20,
          }}
        />
        <View style={{ flexDirection: 'row', gap: 10 }}>
          {(['blackink', 'health', 'finance', 'other'] as const).map(d => (
            <Pressable
              key={d}
              onPress={() => submit(d)}
              disabled={!content.trim() || submitting}
              style={{
                flex: 1,
                padding: 16,
                backgroundColor: !content.trim() ? '#ccc' : domainColor(d),
                borderRadius: 12,
              }}
            >
              <Text style={{ color: 'white', textAlign: 'center', fontWeight: '600' }}>
                {domainLabel(d)}
              </Text>
            </Pressable>
          ))}
        </View>
      </View>
    </KeyboardAvoidingView>
  )
}
// helpers idem audio.tsx
```

#### 6.1.6 Tela home com 4 botões grandes (45min)

`atlas-app/app/(main)/index.tsx`:

```tsx
import { View, Text, Pressable } from 'react-native'
import { router } from 'expo-router'
import * as Haptics from 'expo-haptics'

export default function Home() {
  function go(path: string) {
    Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light)
    router.push(path as any)
  }

  return (
    <View style={{ flex: 1, padding: 20, paddingTop: 80 }}>
      <Text style={{ fontSize: 32, fontWeight: 'bold', marginBottom: 30 }}>Atlas</Text>

      <View style={{ flex: 1, gap: 15 }}>
        <CaptureButton label="🎙️ Áudio" onPress={() => go('/capture/audio')} />
        <CaptureButton label="✏️ Texto" onPress={() => go('/capture/text')} />
        <CaptureButton label="📸 Foto" onPress={() => go('/capture/photo')} />
        <CaptureButton label="⚡ Decisão" onPress={() => go('/capture/decision')} />
      </View>

      <View style={{ flexDirection: 'row', gap: 10 }}>
        <Pressable
          onPress={() => router.push('/inbox')}
          style={{ flex: 1, padding: 16, backgroundColor: '#222', borderRadius: 12 }}
        >
          <Text style={{ color: 'white', textAlign: 'center' }}>Inbox</Text>
        </Pressable>
        <Pressable
          onPress={() => router.push('/checkin')}
          style={{ flex: 1, padding: 16, backgroundColor: '#222', borderRadius: 12 }}
        >
          <Text style={{ color: 'white', textAlign: 'center' }}>Check-in</Text>
        </Pressable>
      </View>
    </View>
  )
}

function CaptureButton({ label, onPress }: { label: string; onPress: () => void }) {
  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => ({
        flex: 1,
        backgroundColor: pressed ? '#1a1a1a' : '#000',
        borderRadius: 20,
        justifyContent: 'center',
        alignItems: 'center',
        padding: 30,
      })}
    >
      <Text style={{ color: 'white', fontSize: 28, fontWeight: '600' }}>{label}</Text>
    </Pressable>
  )
}
```

#### 6.1.7 (Removido — Face ID desnecessário para uso pessoal exclusivo, Lei 3)

`atlas-app/app/_layout.tsx`:

```tsx
import { Stack } from 'expo-router'

export default function RootLayout() {
  return <Stack screenOptions={{ headerShown: false }} />
}
```

#### 6.1.8 HealthKit sync diário (1h)

`atlas-app/lib/healthkit/sync.ts`:

```typescript
import HealthKit from '@kingstinct/react-native-healthkit'
import { API_BASE } from '../api/client'

const PERMISSIONS = {
  toRead: [
    'HKCategoryTypeIdentifierSleepAnalysis',
    'HKQuantityTypeIdentifierStepCount',
    'HKQuantityTypeIdentifierActiveEnergyBurned',
    'HKQuantityTypeIdentifierHeartRate',
    'HKQuantityTypeIdentifierHeartRateVariabilitySDNN',
    'HKQuantityTypeIdentifierBodyMass',
    'HKQuantityTypeIdentifierRestingHeartRate',
  ] as const,
}

export async function requestHealthPermissions() {
  const { status } = await HealthKit.requestAuthorization(PERMISSIONS.toRead)
  return status
}

export async function syncDailyHealthData() {
  const today = new Date()
  const yesterday = new Date(today.getTime() - 24 * 60 * 60 * 1000)

  const sleep = await HealthKit.queryCategorySamples(
    'HKCategoryTypeIdentifierSleepAnalysis',
    { from: yesterday, to: today }
  )

  const steps = await HealthKit.queryStatisticsForQuantity(
    'HKQuantityTypeIdentifierStepCount',
    ['cumulativeSum'],
    { from: yesterday, to: today }
  )

  const hrv = await HealthKit.queryStatisticsForQuantity(
    'HKQuantityTypeIdentifierHeartRateVariabilitySDNN',
    ['discreteAverage'],
    { from: yesterday, to: today }
  )

  const restingHR = await HealthKit.queryStatisticsForQuantity(
    'HKQuantityTypeIdentifierRestingHeartRate',
    ['discreteAverage'],
    { from: yesterday, to: today }
  )

  // Envia pro backend
  await fetch(`${API_BASE}/metrics/ingest`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      source: 'apple_health',
      date: yesterday.toISOString().split('T')[0],
      metrics: {
        sleep_minutes: calculateSleepMinutes(sleep),
        step_count: steps.sumQuantity?.quantity ?? 0,
        hrv_ms: hrv.averageQuantity?.quantity ?? null,
        resting_heart_rate: restingHR.averageQuantity?.quantity ?? null,
      }
    })
  })
}

function calculateSleepMinutes(samples: any[]): number {
  return samples
    .filter(s => s.value === 'HKCategoryValueSleepAnalysisAsleep')
    .reduce((acc, s) => {
      const start = new Date(s.startDate).getTime()
      const end = new Date(s.endDate).getTime()
      return acc + (end - start) / 1000 / 60
    }, 0)
}
```

Backend endpoint `POST /metrics/ingest`:

```typescript
// atlas-server/src/routes/metrics.ts

import { FastifyInstance } from 'fastify'
import { z } from 'zod'
import { Client } from 'pg'

const MetricsIngestSchema = z.object({
  source: z.string(),
  date: z.string(),
  metrics: z.record(z.union([z.number(), z.null()])),
})

export default async function metricsRoutes(fastify: FastifyInstance) {
  fastify.post('/metrics/ingest', async (request, reply) => {
    const data = MetricsIngestSchema.parse(request.body)
    const client = new Client({
      user: 'atlas', database: 'atlas', host: 'localhost', port: 5432
    })
    await client.connect()

    const op = await client.query("SELECT id FROM operators WHERE name = 'Vitor'")
    const operatorId = op.rows[0].id

    const ts = new Date(data.date + 'T23:59:00')

    for (const [name, value] of Object.entries(data.metrics)) {
      if (value === null) continue
      await client.query(
        `INSERT INTO metrics (operator_id, ts, source, metric_name, value_numeric)
         VALUES ($1, $2, $3, $4, $5)`,
        [operatorId, ts, data.source, name, value]
      )
    }

    await client.end()
    return reply.send({ ok: true, ingested: Object.keys(data.metrics).length })
  })
}
```

### 6.2 Critério de Aceite Mini-Sprint 1B


- [ ] Tela home com 4 botões grandes
- [ ] Captura de áudio: hold-to-record, upload pro backend, transcrição via Whisper, nota markdown criada em `~/atlas/01-inbox/`
- [ ] Captura de texto: input + 4 botões de domínio, salva markdown
- [ ] Captura de foto: tira foto, opcional voice note, salva
- [ ] HealthKit autorizado, dados aparecem em `metrics` table
- [ ] Vault filesystem recebe arquivos markdown corretos com frontmatter
- [ ] PostgreSQL `notes` table tem registros das capturas
- [ ] Operador valida 5 capturas reais aparecem certinho no Obsidian Mac

---

## 7. Mini-Sprint 1C — Action Button iPhone (POLISH ABSOLUTO)

**Tempo estimado:** 6 horas
**Objetivo:** Action Button do iPhone configurado pra capturar áudio em 1 toque, com qualidade obsessiva. Esta é a feature principal do Sprint 1.

### 7.1 Por que Polish Absoluto Aqui

Conforme decisão fechada: Watch Action Button é a primeira feature de polish absoluto. Mas o iPhone Action Button é o backup primário. Os dois têm que estar perfeitos.

Polish absoluto significa:
- Captura inicia em <500ms
- Haptic feedback calibrado em cada estado
- Indicação visual clara de "gravando"
- Confirmação sonora discreta quando salva
- Edge cases tratados (modo silencioso, áudio interrompido, sem rede)
- Animações suaves em todas as transições
- Captura nunca falha — se backend offline, salva local e sync depois

### 7.2 Tarefas

#### 7.2.1 App Intent em Swift (2h)

iOS 17+ permite App Intents que podem ser disparados por Action Button. Como Expo não expõe App Intents diretamente, precisamos de plugin custom ou código nativo.

`atlas-app/ios/AtlasIntents/CaptureIntent.swift`:

```swift
import AppIntents
import Foundation

@available(iOS 17.0, *)
struct CaptureAudioIntent: AppIntent {
    static var title: LocalizedStringResource = "Capturar Áudio"
    static var description = IntentDescription("Grava áudio rapidamente para o Atlas")
    static var openAppWhenRun: Bool = true

    func perform() async throws -> some IntentResult {
        // Sinaliza ao app que deve iniciar gravação imediatamente
        UserDefaults(suiteName: "group.com.vitor.atlas.shared")?.set(
            true, forKey: "shouldStartCapture"
        )
        UserDefaults(suiteName: "group.com.vitor.atlas.shared")?.set(
            Date(), forKey: "captureRequestedAt"
        )

        return .result()
    }
}

@available(iOS 17.0, *)
struct AtlasShortcuts: AppShortcutsProvider {
    static var appShortcuts: [AppShortcut] {
        AppShortcut(
            intent: CaptureAudioIntent(),
            phrases: [
                "Capturar áudio no \(.applicationName)",
                "Gravar pensamento no \(.applicationName)",
                "Atlas capturar"
            ],
            shortTitle: "Capturar Áudio",
            systemImageName: "mic.fill"
        )
    }
}
```

#### 7.2.2 Detector de intent no app (1h)

App detecta na inicialização se foi disparado por intent e inicia gravação automaticamente.

`atlas-app/lib/intents/detector.ts`:

```typescript
import { MMKV } from 'react-native-mmkv'

// MMKV pode ser configurado para usar App Group container
const sharedStorage = new MMKV({
  id: 'shared',
  path: 'group.com.vitor.atlas.shared'  // App Group container
})

export function shouldStartCapture(): boolean {
  const flag = sharedStorage.getBoolean('shouldStartCapture') ?? false
  if (flag) {
    sharedStorage.delete('shouldStartCapture')
    sharedStorage.delete('captureRequestedAt')
    return true
  }
  return false
}
```

`atlas-app/app/_layout.tsx` (ajuste):

```tsx
import { useEffect } from 'react'
import { Stack, router } from 'expo-router'
import { shouldStartCapture } from '../lib/intents/detector'

export default function RootLayout() {
  // ... auth code

  useEffect(() => {
    if (authenticated && shouldStartCapture()) {
      // Pula direto pra gravação automática
      router.push('/capture/audio?autostart=true')
    }
  }, [authenticated])

  // ...
}
```

#### 7.2.3 Tela de captura modo automático (1h)

Refator de `audio.tsx` pra detectar `autostart` query param:

```tsx
import { useLocalSearchParams } from 'expo-router'

export default function AudioCapture() {
  const { autostart } = useLocalSearchParams<{ autostart?: string }>()

  useEffect(() => {
    if (autostart === 'true') {
      // Vai direto pra gravação, com domínio default 'other'
      // Operador escolhe domínio depois (na tela de confirmação)
      setDomain('other')
      startRecording()
    }
  }, [autostart])

  // ... resto igual
}
```

#### 7.2.4 Polish dos hapticos e feedback visual (1h)

Calibração precisa:

```tsx
// Início da gravação
Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Medium)

// Cada 10 segundos durante gravação
useEffect(() => {
  if (duration > 0 && duration % 10 === 0) {
    Haptics.selectionAsync()  // Tick suave
  }
}, [duration])

// Fim da gravação
Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success)

// Erro
Haptics.notificationAsync(Haptics.NotificationFeedbackType.Error)
```

Animações:
- Botão pulsando suavemente quando gravando (Reanimated)
- Indicador de duração com fade in
- Transição suave de tela quando confirma

#### 7.2.5 Captura offline-first (1h)

Quando backend offline, salva áudio local e sincroniza depois.

```typescript
// atlas-app/lib/api/captures.ts (extensão)

import * as FileSystem from 'expo-file-system'
import { MMKV } from 'react-native-mmkv'

const queue = new MMKV({ id: 'capture-queue' })

const QUEUE_DIR = FileSystem.documentDirectory + 'capture-queue/'

export async function uploadAudio(uri: string, domain: string) {
  try {
    const formData = new FormData()
    formData.append('file', { uri, name: 'audio.m4a', type: 'audio/m4a' } as any)
    formData.append('domain', domain)

    const r = await fetch(`${API_BASE}/captures/audio`, {
      method: 'POST',
      body: formData,
      headers: { 'Content-Type': 'multipart/form-data' }
    })
    if (!r.ok) throw new Error()
    return r.json()
  } catch (err) {
    // Queue local
    await FileSystem.makeDirectoryAsync(QUEUE_DIR, { intermediates: true })
    const queuedPath = QUEUE_DIR + Date.now() + '.m4a'
    await FileSystem.copyAsync({ from: uri, to: queuedPath })

    const queueList = JSON.parse(queue.getString('list') ?? '[]')
    queueList.push({ path: queuedPath, domain, timestamp: Date.now() })
    queue.set('list', JSON.stringify(queueList))

    return { ok: true, queued: true }
  }
}

export async function processQueue() {
  const queueList = JSON.parse(queue.getString('list') ?? '[]')
  if (queueList.length === 0) return

  const remaining = []
  for (const item of queueList) {
    try {
      const formData = new FormData()
      formData.append('file', {
        uri: item.path, name: 'audio.m4a', type: 'audio/m4a'
      } as any)
      formData.append('domain', item.domain)
      formData.append('original_timestamp', String(item.timestamp))

      const r = await fetch(`${API_BASE}/captures/audio`, {
        method: 'POST',
        body: formData,
      })
      if (r.ok) {
        await FileSystem.deleteAsync(item.path, { idempotent: true })
      } else {
        remaining.push(item)
      }
    } catch {
      remaining.push(item)
    }
  }
  queue.set('list', JSON.stringify(remaining))
}
```

Chama `processQueue()` no init do app e quando volta ao foreground.

### 7.3 Critério de Aceite Mini-Sprint 1C

- [ ] App Intent compilado e disponível como Shortcut
- [ ] Action Button do iPhone configurado pra `Capturar Áudio` no Atlas
- [ ] Apertar Action Button → app abre e inicia gravação em <500ms
- [ ] Gravação tem haptic feedback claro em todos os estados
- [ ] Animações suaves
- [ ] Captura offline funciona — sem backend, salva local
- [ ] Sync automático quando backend volta
- [ ] Operador testa 20+ capturas via Action Button em uso real, nenhuma falha
- [ ] Latência média percebida <1s entre apertar botão e estar gravando

---

## 8. Mini-Sprint 1D — Watch Ultra App

**Tempo estimado:** 8 horas
**Objetivo:** Watch app Swift nativo com Action Button laranja capturando áudio + Complication permanente da missão atual.

### 8.1 Tarefas

#### 8.1.1 Adicionar Watch target no Xcode (1h)

Após `eas build` ter gerado `ios/`, abrir `atlas-app/ios/atlas.xcworkspace` no Xcode.

File → New → Target → watchOS → App → "AtlasWatch"

Configurar:
- Bundle ID: `com.vitor.atlas.watchkitapp`
- Embed in companion app: atlas
- Lifecycle: SwiftUI App

#### 8.1.2 Estrutura Watch app (1h)

```
ios/AtlasWatch/
├── AtlasWatchApp.swift          # Entry point
├── ContentView.swift             # Tela principal — botão de gravar
├── AudioRecorder.swift           # Gravação local
├── ConnectivityManager.swift     # WatchConnectivity para sync
├── ComplicationProvider.swift    # Complication da missão
└── Assets.xcassets/
    └── ComplicationIcon.imageset/
```

#### 8.1.3 Tela principal Watch (1.5h)

`AtlasWatchApp.swift`:

```swift
import SwiftUI

@main
struct AtlasWatch_Watch_AppApp: App {
    @StateObject private var connectivity = ConnectivityManager.shared
    @StateObject private var recorder = AudioRecorder()

    var body: some Scene {
        WindowGroup {
            ContentView()
                .environmentObject(connectivity)
                .environmentObject(recorder)
        }
    }
}
```

`ContentView.swift`:

```swift
import SwiftUI
import WatchKit

struct ContentView: View {
    @EnvironmentObject var recorder: AudioRecorder
    @State private var isRecording = false
    @State private var duration: TimeInterval = 0
    @State private var timer: Timer?

    var body: some View {
        VStack(spacing: 12) {
            if isRecording {
                Text(formatDuration(duration))
                    .font(.system(size: 32, weight: .bold, design: .rounded))
                    .monospacedDigit()
                    .foregroundColor(.red)

                Button(action: stopRecording) {
                    Image(systemName: "stop.fill")
                        .font(.system(size: 50))
                        .foregroundColor(.white)
                }
                .frame(width: 100, height: 100)
                .background(Color.red)
                .clipShape(Circle())
            } else {
                Text("Atlas")
                    .font(.title2)
                    .fontWeight(.semibold)

                Button(action: startRecording) {
                    Image(systemName: "mic.fill")
                        .font(.system(size: 50))
                        .foregroundColor(.white)
                }
                .frame(width: 100, height: 100)
                .background(Color.orange)
                .clipShape(Circle())
            }
        }
    }

    func startRecording() {
        WKInterfaceDevice.current().play(.start)
        recorder.startRecording()
        isRecording = true
        duration = 0

        timer = Timer.scheduledTimer(withTimeInterval: 1, repeats: true) { _ in
            duration += 1
        }
    }

    func stopRecording() {
        WKInterfaceDevice.current().play(.stop)
        timer?.invalidate()
        timer = nil

        if let url = recorder.stopRecording() {
            // Envia pro iPhone via WatchConnectivity
            ConnectivityManager.shared.sendAudioFile(url)
        }
        isRecording = false
    }

    func formatDuration(_ seconds: TimeInterval) -> String {
        let m = Int(seconds) / 60
        let s = Int(seconds) % 60
        return String(format: "%d:%02d", m, s)
    }
}
```

#### 8.1.4 Audio Recorder Watch (1h)

`AudioRecorder.swift`:

```swift
import AVFoundation

class AudioRecorder: NSObject, ObservableObject, AVAudioRecorderDelegate {
    private var recorder: AVAudioRecorder?
    private var currentURL: URL?

    func startRecording() {
        let session = AVAudioSession.sharedInstance()
        try? session.setCategory(.playAndRecord, mode: .default)
        try? session.setActive(true)

        let url = FileManager.default
            .urls(for: .documentDirectory, in: .userDomainMask)[0]
            .appendingPathComponent("\(UUID().uuidString).m4a")

        let settings: [String: Any] = [
            AVFormatIDKey: Int(kAudioFormatMPEG4AAC),
            AVSampleRateKey: 16000,  // 16kHz suficiente pra voz
            AVNumberOfChannelsKey: 1,
            AVEncoderAudioQualityKey: AVAudioQuality.medium.rawValue
        ]

        do {
            recorder = try AVAudioRecorder(url: url, settings: settings)
            recorder?.delegate = self
            recorder?.record()
            currentURL = url
        } catch {
            print("Recording failed: \(error)")
        }
    }

    func stopRecording() -> URL? {
        recorder?.stop()
        recorder = nil
        return currentURL
    }
}
```

#### 8.1.5 WatchConnectivity (1.5h)

`ConnectivityManager.swift`:

```swift
import WatchConnectivity

class ConnectivityManager: NSObject, ObservableObject, WCSessionDelegate {
    static let shared = ConnectivityManager()

    private let session: WCSession

    override init() {
        self.session = .default
        super.init()
        session.delegate = self
        session.activate()
    }

    func sendAudioFile(_ url: URL) {
        guard session.activationState == .activated else {
            // Queue local pra enviar quando conectar
            queueAudioForLater(url)
            return
        }

        // transferFile envia em background mesmo se app companion fechado
        session.transferFile(url, metadata: [
            "type": "audio_capture",
            "timestamp": Date().timeIntervalSince1970
        ])
    }

    private func queueAudioForLater(_ url: URL) {
        // Salva path em UserDefaults pra processar depois
        var queued = UserDefaults.standard.stringArray(forKey: "queuedAudios") ?? []
        queued.append(url.path)
        UserDefaults.standard.set(queued, forKey: "queuedAudios")
    }

    // Delegate methods
    func session(_ session: WCSession, activationDidCompleteWith activationState: WCSessionActivationState, error: Error?) {
        if activationState == .activated {
            processQueuedAudios()
        }
    }

    private func processQueuedAudios() {
        let queued = UserDefaults.standard.stringArray(forKey: "queuedAudios") ?? []
        for path in queued {
            let url = URL(fileURLWithPath: path)
            session.transferFile(url, metadata: ["type": "audio_capture"])
        }
        UserDefaults.standard.removeObject(forKey: "queuedAudios")
    }

    // No iPhone, recebe via WCSessionDelegate.session(_:didReceive:)
    // (esse método fica no app principal, não no Watch)
}
```

No iPhone, criar receiver que recebe arquivos do Watch e faz upload:

```swift
// atlas-app/ios/atlas/AppDelegate.swift (ou similar)

func session(_ session: WCSession, didReceive file: WCSessionFile) {
    // file.fileURL é o áudio recebido do Watch
    // file.metadata pode ter "type": "audio_capture"

    // Move pro container compartilhado
    let sharedContainer = FileManager.default.containerURL(
        forSecurityApplicationGroupIdentifier: "group.com.vitor.atlas.shared"
    )!

    let target = sharedContainer.appendingPathComponent("watch_captures")
        .appendingPathComponent(file.fileURL.lastPathComponent)

    try? FileManager.default.createDirectory(at: target.deletingLastPathComponent(), withIntermediateDirectories: true)
    try? FileManager.default.moveItem(at: file.fileURL, to: target)

    // Notifica RN side via UserDefaults
    UserDefaults(suiteName: "group.com.vitor.atlas.shared")?
        .set(target.path, forKey: "newWatchCapture")
}
```

E no React Native lado, polling ou listener pra processar `newWatchCapture` e fazer upload pro backend.

#### 8.1.6 Action Button laranja config (30min)

Operador faz manualmente após app instalado:

1. Settings no Watch Ultra → Action Button
2. Action: Atalho
3. Selecionar: Atlas — Capturar Áudio

Watch app expõe shortcut via:

```swift
// Em AtlasWatchApp.swift
import AppIntents

@available(watchOS 10.0, *)
struct WatchCaptureIntent: AppIntent {
    static var title: LocalizedStringResource = "Capturar Áudio (Watch)"
    static var openAppWhenRun: Bool = true

    func perform() async throws -> some IntentResult {
        UserDefaults.standard.set(true, forKey: "watchAutoStartCapture")
        return .result()
    }
}
```

ContentView detecta a flag no `onAppear` e auto-inicia gravação.

#### 8.1.7 Complication da missão atual (1.5h)

`ComplicationProvider.swift`:

```swift
import WidgetKit
import SwiftUI

struct MissionEntry: TimelineEntry {
    let date: Date
    let missionTitle: String
}

struct MissionProvider: TimelineProvider {
    func placeholder(in context: Context) -> MissionEntry {
        MissionEntry(date: Date(), missionTitle: "Carregando...")
    }

    func getSnapshot(in context: Context, completion: @escaping (MissionEntry) -> ()) {
        let entry = readCurrentMission()
        completion(entry)
    }

    func getTimeline(in context: Context, completion: @escaping (Timeline<MissionEntry>) -> ()) {
        let entry = readCurrentMission()
        // Refresh a cada hora
        let nextRefresh = Calendar.current.date(byAdding: .hour, value: 1, to: Date())!
        let timeline = Timeline(entries: [entry], policy: .after(nextRefresh))
        completion(timeline)
    }

    private func readCurrentMission() -> MissionEntry {
        let shared = UserDefaults(suiteName: "group.com.vitor.atlas.shared")
        let title = shared?.string(forKey: "currentMissionTitle") ?? "Sem missão"
        return MissionEntry(date: Date(), missionTitle: title)
    }
}

struct MissionComplicationView: View {
    let entry: MissionEntry

    var body: some View {
        VStack(alignment: .leading, spacing: 2) {
            Text("Missão")
                .font(.system(size: 9, weight: .medium))
                .foregroundColor(.secondary)
            Text(entry.missionTitle)
                .font(.system(size: 12, weight: .semibold))
                .lineLimit(2)
        }
    }
}

@main
struct AtlasComplications: Widget {
    let kind: String = "AtlasMissionComplication"

    var body: some WidgetConfiguration {
        StaticConfiguration(kind: kind, provider: MissionProvider()) { entry in
            MissionComplicationView(entry: entry)
        }
        .configurationDisplayName("Missão Atual")
        .description("Mostra sua missão ativa")
        .supportedFamilies([
            .accessoryCircular,
            .accessoryRectangular,
            .accessoryInline,
            .accessoryCorner
        ])
    }
}
```

iPhone app atualiza missão atual em UserDefaults compartilhado quando muda missão ativa.

### 8.2 Critério de Aceite Mini-Sprint 1D

- [ ] Watch app instalado no Apple Watch Ultra (via TestFlight ou pareamento dev)
- [ ] App abre no Watch, mostra botão laranja
- [ ] Botão laranja: tap → grava áudio
- [ ] Action Button laranja configurável → abre Atlas e inicia gravação automática
- [ ] Áudio gravado sincroniza com iPhone via WatchConnectivity
- [ ] iPhone faz upload do áudio recebido pro backend
- [ ] Whisper transcreve áudio do Watch igual áudio do iPhone
- [ ] Complication "Missão Atual" disponível em mostradores
- [ ] Operador adiciona complication em mostrador principal
- [ ] Texto da missão aparece e atualiza quando muda no iPhone

---

## 9. Mini-Sprint 1E — Polish Final e TestFlight

**Tempo estimado:** 4 horas
**Objetivo:** Refinamentos finais, primeiro build de produção, distribuição via TestFlight Internal.

### 9.1 Tarefas

#### 9.1.1 Tela de Inbox (1h)

`atlas-app/app/(main)/inbox.tsx`:

```tsx
import { useQuery } from '@tanstack/react-query'
import { ScrollView, View, Text } from 'react-native'
import { apiGet } from '../../lib/api/client'

interface InboxItem {
  id: string
  created: string
  preview: string
  domain: string
  source: string
}

export default function Inbox() {
  const { data, isLoading } = useQuery({
    queryKey: ['inbox'],
    queryFn: () => apiGet<InboxItem[]>('/notes/inbox?limit=50'),
    refetchInterval: 30000,
  })

  return (
    <ScrollView style={{ flex: 1, padding: 20 }}>
      <Text style={{ fontSize: 28, fontWeight: 'bold', marginBottom: 20 }}>
        Inbox
      </Text>
      {data?.map(item => (
        <View
          key={item.id}
          style={{
            padding: 16,
            marginBottom: 10,
            backgroundColor: '#1a1a1a',
            borderRadius: 12,
          }}
        >
          <Text style={{ color: '#888', fontSize: 12 }}>
            {new Date(item.created).toLocaleString('pt-BR')} · {item.domain}
          </Text>
          <Text style={{ color: 'white', marginTop: 6 }}>{item.preview}</Text>
        </View>
      ))}
    </ScrollView>
  )
}
```

Backend `/notes/inbox`:

```typescript
fastify.get('/notes/inbox', async (request) => {
  const { limit = 50 } = request.query as any
  const client = new Client({...})
  await client.connect()
  const r = await client.query(
    `SELECT id, created_at as created, content_preview as preview, area as domain, frontmatter
     FROM notes
     WHERE type = 'capture'
     ORDER BY created_at DESC
     LIMIT $1`,
    [limit]
  )
  await client.end()
  return r.rows
})
```

#### 9.1.2 Check-in tela (45min)

`atlas-app/app/(main)/checkin.tsx`:

```tsx
import { Pressable, View, Text } from 'react-native'
import { router } from 'expo-router'
import * as Haptics from 'expo-haptics'
import { apiPost } from '../../lib/api/client'

const states = [
  { id: 'foco', label: 'Em foco', emoji: '🎯', color: '#22c55e' },
  { id: 'disperso', label: 'Disperso', emoji: '🌀', color: '#f59e0b' },
  { id: 'bloqueado', label: 'Bloqueado', emoji: '🚧', color: '#ef4444' },
  { id: 'pausa', label: 'Pausa', emoji: '☕', color: '#6366f1' },
]

export default function Checkin() {
  async function submit(state: string) {
    Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success)
    await apiPost('/checkin', { state })
    router.back()
  }

  return (
    <View style={{ flex: 1, padding: 20, justifyContent: 'center' }}>
      <Text style={{ fontSize: 28, fontWeight: 'bold', marginBottom: 30, textAlign: 'center' }}>
        Como tá agora?
      </Text>
      {states.map(s => (
        <Pressable
          key={s.id}
          onPress={() => submit(s.id)}
          style={{
            padding: 24,
            marginBottom: 12,
            backgroundColor: s.color,
            borderRadius: 16,
            flexDirection: 'row',
            alignItems: 'center',
            gap: 16,
          }}
        >
          <Text style={{ fontSize: 32 }}>{s.emoji}</Text>
          <Text style={{ color: 'white', fontSize: 22, fontWeight: '600' }}>
            {s.label}
          </Text>
        </Pressable>
      ))}
    </View>
  )
}
```

Backend:

```typescript
fastify.post('/checkin', async (request) => {
  const { state } = request.body as { state: string }
  const client = new Client({...})
  await client.connect()
  const op = await client.query("SELECT id FROM operators WHERE name = 'Vitor'")
  await client.query(
    `INSERT INTO cognitive_state (operator_id, ts, state, source)
     VALUES ($1, now(), $2, 'manual_checkin')`,
    [op.rows[0].id, state]
  )
  await client.end()
  return { ok: true }
})
```

#### 9.1.3 Build de produção (1h)

```bash
# Verifica eas.json correto
# Bumpa version se necessário em app.json

eas build --platform ios --profile production
```

Aguardar build (15-30min).

#### 9.1.4 Submit pro TestFlight (45min)

```bash
eas submit --platform ios --profile production
```

Vai pedir:
- Apple ID do operador
- App-specific password (gerar em appleid.apple.com)
- App Store Connect App ID (criar app no App Store Connect primeiro)

Depois de submit:

1. App Store Connect → Apps → Atlas
2. TestFlight → iOS → adicionar build
3. Internal Testing → adicionar a si mesmo como tester
4. Salvar

#### 9.1.5 Instalar via TestFlight (30min)

No iPhone:
1. App Store → instala TestFlight (se não tiver)
2. Login com mesmo Apple ID
3. Atlas aparece na lista
4. Instala
5. Abre

A partir de agora, cada `eas build && eas submit` vai aparecer como update no TestFlight em ~15 min.

### 9.2 Critério de Aceite Mini-Sprint 1E

- [ ] Tela de inbox mostra capturas reais paginadas
- [ ] Tela de check-in registra estado em `cognitive_state`
- [ ] Build de produção compilado sem erros
- [ ] App submitido pro App Store Connect
- [ ] Build aprovado em TestFlight Internal
- [ ] Operador instala via TestFlight no iPhone real
- [ ] Operador instala app companion no Watch via TestFlight
- [ ] Sistema funciona 7 dias seguidos sem crash
- [ ] Operador captura 5+ pensamentos/dia em uso real

---

## 10. Critérios de Aceite por Mini-Sprint

Resumo:

| Mini-Sprint | Tempo | Critério Final |
|-------------|-------|---------------|
| 1A | 8h | App abre, conversa com backend via Tailscale |
| 1B | 8h | Captura completa funcionando, dados no vault + banco |
| 1C | 6h | Action Button polido, captura offline-first |
| 1D | 8h | Watch app + Action Button laranja + Complication |
| 1E | 4h | TestFlight distribuído, uso real |
| **Total** | **34h** | **Atlas V1 funcional** |

---

## 11. Anexos Técnicos

### 11.1 Variáveis de Ambiente

`atlas-server/.env`:

```env
DATABASE_URL=postgresql://atlas:atlas_local_2026@localhost:5433/atlas
VAULT_PATH=/Users/vitorepf/atlas
WHISPER_MODEL_PATH=/opt/homebrew/share/whisper-cpp/ggml-base.bin
PORT=3737
LOG_LEVEL=info
```

### 11.2 Comandos Úteis

```bash
# Backend
cd ~/atlas-server
npm run dev               # dev mode com auto-reload
npm start                 # produção

# Mobile
cd ~/atlas-app
npx expo start            # dev server
eas build --platform ios --profile development  # build dev
eas build --platform ios --profile production   # build prod
eas submit --platform ios --profile production  # submit TestFlight

# Database
psql -U atlas -d atlas                       # console
pg_dump -U atlas atlas > backup.sql          # backup
```

### 11.3 Troubleshooting Comum

**Build EAS falha:** verifica certificados em Apple Developer, regenera se preciso via `eas credentials`.

**App não conecta backend:** verifica Tailscale ativo, hostname correto em MMKV, firewall do Mac (System Settings → Network → Firewall → Atlas Backend permitido).

**HealthKit não autoriza:** confirma capabilities em app.json + entitlements + provisioning profile com HealthKit habilitado.

**Watch não sincroniza:** confirma App Group ID idêntico em iPhone e Watch targets, WCSession ativado.

**Whisper falha:** verifica modelo presente em path correto, permissões de exec em whisper-cpp.

### 11.4 Backups Obrigatórios

Antes de cada mini-sprint, garantir backup:

```bash
# Vault
cd ~/atlas && git add . && git commit -m "snapshot pre-sprint" && git push

# Database
pg_dump -U atlas atlas > ~/atlas-backups/$(date +%Y%m%d).sql

# Mobile project
cd ~/atlas-app && git push
```

### 11.5 Próximos Passos Pós-Sprint 1

Após Sprint 1 validado em uso real por 2-4 semanas:

- **Sprint 2:** Widgets na home, Live Activity Dynamic Island, briefing matinal renderizado
- **Sprint 3:** Dashboard 4 dimensões, search semântica, Apple Watch app expandido

Antes de cada Sprint, operador valida que Sprint anterior está consolidado em uso e gerando valor real.

---

**Fim do Documento de Implementação Técnica — Atlas V1**

*Este documento é o briefing executável pro Claude Code. Todas as decisões refletem o Documento Mestre v3.0. Quando houver dúvida, mestre prevalece. Quando algo for omitido, perguntar ao operador antes de assumir.*

*Última atualização: 27 de abril de 2026.*
