# Provider Choice — App Implementation Plan (Plano B)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Quando um job no `mobile-thread.tsx` está em `awaiting_user_choice`, o app abre um modal central com as opções (vindas do backend), o operador escolhe via tap, o POST resume o job, o modal fecha, o polling continua e a conversa retoma — tudo sem sair do app.

**Architecture:** Hook `useProviderChoice(traces)` detecta um job em `awaiting_user_choice` no array de traces que o `mobile-thread` já polla. Quando detecta, abre overlay `'providerChoice'` (registrado em `lib/overlays.ts`). O componente `ProviderChoiceModal` lê o estado do overlay, renderiza opções, faz POST `/ai/jobs/:id/resume-choice` ao tap e fecha. Sem mudar o motor de polling existente.

**Tech Stack:** React Native + Expo Router, zustand (overlays), design tokens custom (`design/Type`, `usePalette`), fetch via `apiRequest` em `lib/api/client.ts`.

**Depends on:** Backend implementado em `docs/plans/2026-05-01-provider-choice-cli.md` (Plano A) — endpoint `POST /ai/jobs/:id/resume-choice` e campos top-level no `AiJobResource` já existem.

**Out of scope:**
- Push notification quando job pausa em background (separado).
- Suporte multi-thread (3 threads, 2 paused) — comportamento por design: mostra o sheet do thread aberto.
- App de tablet/desktop — só mobile (vertical).
- Tests unitários do componente — RN testing setup do projeto é incipiente; coberto via smoke test manual.

**Deviation do spec original:** O design dizia "Sheet Tamagui". A realidade do app é um registry de overlays em `lib/overlays.ts` via zustand. Nenhum componente atual usa Tamagui Sheet. Vou plugar no padrão existente (registrar `'providerChoice'` como `OverlayKey` e renderizar via Modal RN) — coerente com os outros overlays do app.

---

## File Structure

**Create:**
- `atlas-app/components/ProviderChoiceModal.tsx` — modal central com opções
- `atlas-app/lib/hooks/useProviderChoice.ts` — hook que detecta pausa e dispara overlay

**Modify:**
- `atlas-app/lib/api/client.ts` — adicionar `AtlasAiStatus` extension, novos campos em `AtlasAiJob`, função `resumeAiJobChoice`
- `atlas-app/lib/overlays.ts` — registrar `'providerChoice'` como `OverlayKey` e os campos de estado
- `atlas-app/app/mobile-thread.tsx` — chamar o hook e renderizar o modal
- `atlas-app/components/AtlasShell.tsx` — montar `ProviderChoiceModal` no mesmo lugar onde os outros overlays são mostrados (ou onde o overlay registry é consumido)

---

### Task 1: Estender tipos no client.ts

**Files:**
- Modify: `atlas-app/lib/api/client.ts:1593-1635`

- [ ] **Step 1: Adicionar `'awaiting_user_choice'` ao `AtlasAiStatus`**

Localizar (linha 1594):
```ts
export type AtlasAiStatus = 'queued' | 'processing' | 'succeeded' | 'failed' | 'cancelled'
```

Substituir por:
```ts
export type AtlasAiStatus = 'queued' | 'processing' | 'succeeded' | 'failed' | 'cancelled' | 'awaiting_user_choice'
```

- [ ] **Step 2: Adicionar tipos novos para choice options**

Logo após o type `AtlasAiStatus` (~linha 1595, antes de outros types relacionados a Ai):
```ts
export type AtlasAiChoiceAction = 'switch_provider' | 'downgrade_model' | 'wait' | 'fail' | 'cancel' | 'retry_same'

export interface AtlasAiChoiceOption {
  id: string
  label: string
  description?: string
  action: AtlasAiChoiceAction
  provider?: string
  model?: string | null
  available_at_iso?: string
  cli_command?: string
  reason?: string
}
```

- [ ] **Step 3: Estender `AtlasAiJob` com os campos top-level**

Localizar interface `AtlasAiJob` (linha 1605) e adicionar ANTES do `metadata: Record<string, unknown>`:
```ts
  awaiting_user_choice?: boolean
  choice_options?: AtlasAiChoiceOption[]
  provider_choice_state?: 'pending' | 'resolved' | null
  provider_choice_error_code?: string | null
  provider_reset_at?: string | null
  reset_hint?: string | null
```

- [ ] **Step 4: Adicionar função `resumeAiJobChoice`**

No final do arquivo `client.ts`, antes do `}` final (se existir) ou junto com outras funções `aiJob*` se houverem. Use `apiRequest` (não `mobileApiRequest`):

```ts
export async function resumeAiJobChoice(
  jobId: string,
  optionId: string,
): Promise<{ job: AtlasAiJob }> {
  return apiRequest<{ job: AtlasAiJob }>(
    `/ai/jobs/${encodeURIComponent(jobId)}/resume-choice`,
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ option_id: optionId }),
    },
  )
}
```

- [ ] **Step 5: Verificar typecheck**

Run: `cd /Users/vitorepf/Develop/atlas/atlas-app && npm run typecheck`
Expected: zero erros (pode ter avisos pré-existentes — só novos do seu código bloqueiam).

- [ ] **Step 6: Commit cirúrgico**

```bash
git add atlas-app/lib/api/client.ts
git commit -m "feat(app): types and resume-choice API for provider choice"
```

---

### Task 2: Registrar overlay `'providerChoice'` no zustand store

**Files:**
- Modify: `atlas-app/lib/overlays.ts`

- [ ] **Step 1: Adicionar `'providerChoice'` ao tipo `OverlayKey`**

Localizar:
```ts
export type OverlayKey =
  | 'detail'
  | 'domain'
  | 'confirmDelete'
  | 'edit'
  | 'settings'
  | 'mic'
  | 'atlasAi'
  | 'inboxDomainFilter'
  | 'captureSettings'
```

Adicionar `'providerChoice'` no fim:
```ts
export type OverlayKey =
  | 'detail'
  | 'domain'
  | 'confirmDelete'
  | 'edit'
  | 'settings'
  | 'mic'
  | 'atlasAi'
  | 'inboxDomainFilter'
  | 'captureSettings'
  | 'providerChoice'
```

- [ ] **Step 2: Adicionar estado para o overlay providerChoice**

LEIA o arquivo `lib/overlays.ts` inteiro pra entender o pattern do `interface OverlayState` e do `create<...>(...)` zustand.

Adicionar à `interface OverlayState`:
```ts
  // Provider choice — job paused awaiting operator decision
  providerChoiceJobId: string | null
  providerChoiceErrorCode: string | null
  providerChoiceResetHint: string | null
  providerChoiceOptions: import('./api/client').AtlasAiChoiceOption[]
```

E ao `create<...>(...)` initial state (na chamada `set` ou state inicial):
```ts
  providerChoiceJobId: null,
  providerChoiceErrorCode: null,
  providerChoiceResetHint: null,
  providerChoiceOptions: [],
```

Adicionar 2 actions ao store (ao lado de outras actions tipo `openOverlay`/`closeOverlay`):
```ts
  openProviderChoice: (input: {
    jobId: string
    errorCode: string | null
    resetHint: string | null
    options: import('./api/client').AtlasAiChoiceOption[]
  }) => set({
    open: 'providerChoice',
    providerChoiceJobId: input.jobId,
    providerChoiceErrorCode: input.errorCode,
    providerChoiceResetHint: input.resetHint,
    providerChoiceOptions: input.options,
  }),
  closeProviderChoice: () => set({
    open: null,
    providerChoiceJobId: null,
    providerChoiceErrorCode: null,
    providerChoiceResetHint: null,
    providerChoiceOptions: [],
  }),
```

> **Atenção:** se o `interface OverlayState` declarar as actions como propriedades de função (`openOverlay: (key: OverlayKey) => void`), siga o mesmo padrão para `openProviderChoice` e `closeProviderChoice` na interface, e implemente no `create`. Leia o arquivo inteiro antes pra não desviar do estilo.

- [ ] **Step 3: Verificar typecheck**

Run: `cd /Users/vitorepf/Develop/atlas/atlas-app && npm run typecheck`
Expected: zero novos erros.

- [ ] **Step 4: Commit**

```bash
git add atlas-app/lib/overlays.ts
git commit -m "feat(app): register providerChoice overlay state"
```

---

### Task 3: Hook `useProviderChoice`

**Files:**
- Create: `atlas-app/lib/hooks/useProviderChoice.ts`

O hook recebe o array de traces (que `mobile-thread.tsx` já mantém em estado) e detecta o primeiro job em `awaiting_user_choice`. Quando detecta, abre o overlay. Quando o job sai do estado (resolved → queued/cancelled/failed), fecha o overlay.

- [ ] **Step 1: Criar o hook**

```ts
import { useEffect } from 'react'
import { useOverlayStore } from '../overlays'
import type { AtlasAiTrace, AtlasAiJob } from '../api/client'

function findPausedJob(traces: AtlasAiTrace[]): AtlasAiJob | null {
  for (const trace of traces) {
    const candidates: AtlasAiJob[] = []
    if (trace.job) candidates.push(trace.job)
    if (Array.isArray(trace.jobs)) candidates.push(...trace.jobs)

    for (const job of candidates) {
      if (job.status === 'awaiting_user_choice') {
        return job
      }
    }
  }
  return null
}

export function useProviderChoice(traces: AtlasAiTrace[]): void {
  const open = useOverlayStore((state) => state.open)
  const currentJobId = useOverlayStore((state) => state.providerChoiceJobId)
  const openProviderChoice = useOverlayStore((state) => state.openProviderChoice)
  const closeProviderChoice = useOverlayStore((state) => state.closeProviderChoice)

  useEffect(() => {
    const paused = findPausedJob(traces)

    if (paused) {
      if (currentJobId !== paused.id) {
        openProviderChoice({
          jobId: paused.id,
          errorCode: paused.provider_choice_error_code ?? null,
          resetHint: paused.reset_hint ?? null,
          options: paused.choice_options ?? [],
        })
      }
      return
    }

    // Sem job pausado e overlay ainda aberto → fechar
    if (open === 'providerChoice') {
      closeProviderChoice()
    }
  }, [traces, currentJobId, open, openProviderChoice, closeProviderChoice])
}
```

- [ ] **Step 2: Verificar typecheck**

Run: `cd /Users/vitorepf/Develop/atlas/atlas-app && npm run typecheck`
Expected: zero novos erros.

- [ ] **Step 3: Commit**

```bash
git add atlas-app/lib/hooks/useProviderChoice.ts
git commit -m "feat(app): hook detects paused job and toggles provider choice overlay"
```

> Se o diretório `lib/hooks` não existir, crie com `mkdir -p atlas-app/lib/hooks` antes do commit.

---

### Task 4: Componente `ProviderChoiceModal`

**Files:**
- Create: `atlas-app/components/ProviderChoiceModal.tsx`

Modal central (não bottom sheet) usando `Modal` do React Native. Renderiza header + lista de opções como botões + estado de loading durante o POST.

- [ ] **Step 1: Criar o componente**

```tsx
import { useState } from 'react'
import { ActivityIndicator, Modal, Pressable, ScrollView, StyleSheet, View } from 'react-native'
import { Frau, Label, Sans } from '../design/Type'
import { usePalette } from '../design/theme'
import { useShell } from './AtlasShell'
import { useOverlayStore } from '../lib/overlays'
import { resumeAiJobChoice, type AtlasAiChoiceOption } from '../lib/api/client'

export function ProviderChoiceModal() {
  const c = usePalette()
  const { showToast } = useShell()
  const open = useOverlayStore((state) => state.open)
  const jobId = useOverlayStore((state) => state.providerChoiceJobId)
  const errorCode = useOverlayStore((state) => state.providerChoiceErrorCode)
  const resetHint = useOverlayStore((state) => state.providerChoiceResetHint)
  const options = useOverlayStore((state) => state.providerChoiceOptions)
  const closeProviderChoice = useOverlayStore((state) => state.closeProviderChoice)

  const [submittingId, setSubmittingId] = useState<string | null>(null)
  const isOpen = open === 'providerChoice' && jobId !== null

  const handlePick = async (option: AtlasAiChoiceOption) => {
    if (!jobId || submittingId) return
    setSubmittingId(option.id)
    try {
      await resumeAiJobChoice(jobId, option.id)
      showToast(`Escolhido: ${option.label}`)
      closeProviderChoice()
    } catch (err) {
      showToast(err instanceof Error ? err.message : 'Falha ao resolver escolha.')
    } finally {
      setSubmittingId(null)
    }
  }

  const headerText =
    errorCode === 'auth_expired'
      ? 'Login expirado'
      : `Sem créditos${resetHint ? ` até ${resetHint}` : ''}`

  return (
    <Modal visible={isOpen} transparent animationType="fade" onRequestClose={closeProviderChoice}>
      <View style={[styles.scrim]}>
        <View style={[styles.card, { backgroundColor: c.surface, borderColor: c.border }]}>
          <Label color={c.muted} size={11} style={styles.eyebrow}>
            ATLAS PRECISA DE UMA DECISÃO
          </Label>
          <Frau size={22} lineHeight={28} color={c.ink}>
            {headerText}
          </Frau>

          <ScrollView style={styles.options} contentContainerStyle={styles.optionsContent}>
            {options.map((option) => {
              const isSubmitting = submittingId === option.id
              return (
                <Pressable
                  key={option.id}
                  onPress={() => handlePick(option)}
                  disabled={Boolean(submittingId)}
                  style={({ pressed }) => [
                    styles.option,
                    {
                      backgroundColor: pressed ? c.premium : c.surface,
                      borderColor: c.border,
                      opacity: submittingId && !isSubmitting ? 0.5 : 1,
                    },
                  ]}
                >
                  <View style={styles.optionRow}>
                    <Sans size={15} lineHeight={20} color={c.ink}>
                      {option.label}
                    </Sans>
                    {isSubmitting ? <ActivityIndicator size="small" color={c.ink} /> : null}
                  </View>
                  {option.description ? (
                    <Sans size={13} lineHeight={18} color={c.muted} style={styles.optionDesc}>
                      {option.description}
                    </Sans>
                  ) : null}
                </Pressable>
              )
            })}
          </ScrollView>

          <Pressable
            onPress={closeProviderChoice}
            disabled={Boolean(submittingId)}
            style={({ pressed }) => [
              styles.footerButton,
              { backgroundColor: pressed ? c.premium : 'transparent', borderColor: c.border },
            ]}
          >
            <Sans size={13} color={c.muted}>
              Fechar (decidir depois)
            </Sans>
          </Pressable>
        </View>
      </View>
    </Modal>
  )
}

const styles = StyleSheet.create({
  scrim: {
    flex: 1,
    backgroundColor: 'rgba(0,0,0,0.55)',
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 18,
  },
  card: {
    width: '100%',
    maxWidth: 420,
    borderRadius: 14,
    borderWidth: StyleSheet.hairlineWidth,
    padding: 22,
  },
  eyebrow: {
    letterSpacing: 1.2,
    marginBottom: 6,
  },
  options: {
    marginTop: 18,
    maxHeight: 360,
  },
  optionsContent: {
    gap: 10,
    paddingBottom: 4,
  },
  option: {
    borderRadius: 10,
    borderWidth: StyleSheet.hairlineWidth,
    paddingHorizontal: 14,
    paddingVertical: 12,
  },
  optionRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
  },
  optionDesc: {
    marginTop: 4,
  },
  footerButton: {
    marginTop: 16,
    borderRadius: 8,
    borderWidth: StyleSheet.hairlineWidth,
    paddingVertical: 10,
    alignItems: 'center',
  },
})
```

- [ ] **Step 2: Verificar typecheck**

Run: `cd /Users/vitorepf/Develop/atlas/atlas-app && npm run typecheck`
Expected: zero novos erros.

- [ ] **Step 3: Commit**

```bash
git add atlas-app/components/ProviderChoiceModal.tsx
git commit -m "feat(app): provider choice modal component"
```

---

### Task 5: Wire-up — montar o modal na shell e usar o hook no thread

**Files:**
- Modify: `atlas-app/components/AtlasShell.tsx`
- Modify: `atlas-app/app/mobile-thread.tsx`

- [ ] **Step 1: Adicionar `<ProviderChoiceModal />` no AtlasShell**

LEIA `atlas-app/components/AtlasShell.tsx` para identificar onde os outros overlays são renderizados (procure por `useOverlayStore` ou imports de outros sheets/modals).

Importar:
```tsx
import { ProviderChoiceModal } from './ProviderChoiceModal'
```

Adicionar `<ProviderChoiceModal />` no JSX, no MESMO lugar onde os outros overlays vivem (provavelmente no fim do JSX da shell, antes do tag de fechamento).

> **Se a shell não montar overlays diretamente** e cada tela monta o seu (verificar lendo o arquivo), então o componente vai pra `mobile-thread.tsx` ao invés de `AtlasShell`. LEIA o arquivo antes de decidir onde montar.

- [ ] **Step 2: Chamar o hook no `mobile-thread.tsx`**

Em `atlas-app/app/mobile-thread.tsx`, adicionar import:
```tsx
import { useProviderChoice } from '../lib/hooks/useProviderChoice'
```

Logo depois de `const activeTraces = useMemo(...)` (linha ~33), adicionar:
```tsx
useProviderChoice(traces)
```

(Usar `traces` cru, não `activeTraces`, porque um job em `awaiting_user_choice` pode não estar em "active" do ponto de vista da semantic do thread.)

- [ ] **Step 3: Verificar typecheck**

Run: `cd /Users/vitorepf/Develop/atlas/atlas-app && npm run typecheck`
Expected: zero novos erros.

- [ ] **Step 4: Commit**

```bash
git add atlas-app/components/AtlasShell.tsx atlas-app/app/mobile-thread.tsx
git commit -m "feat(app): mount provider choice modal and hook into thread polling"
```

---

### Task 6: Smoke test manual no app real

**Files:** nenhum, validação operacional.

Pré-requisitos:
- Backend rodando (containers de Docker up).
- App iOS no simulator OU na rede local pareado com o Mac.
- Atlas token configurado no app (ou usando o default `local-development-atlas-token-change-me`).

- [ ] **Step 1: Subir o app no simulator**

Run:
```bash
cd /Users/vitorepf/Develop/atlas/atlas-app && npm run dev:ios
```

Aguardar o Metro buildar e o simulator abrir.

- [ ] **Step 2: Identificar um thread existente do mobile gateway**

Via psql:
```bash
docker exec atlas-db psql -U atlas -d atlas -c "SELECT id, title FROM ai_threads ORDER BY created_at DESC LIMIT 3;"
```

Anote um `thread_id` que tenha mensagens.

- [ ] **Step 3: Seedar um job em awaiting_user_choice nesse thread**

```bash
cat > /tmp/seed_app_pause.php <<'EOF'
<?php
$threadId = '<COLE_O_THREAD_ID_AQUI>';
$thread = \App\Models\AiThread::findOrFail($threadId);

$trace = \App\Models\AiTrace::create([
    'trace_key' => 'tr_appsmoke_'.time(),
    'agent_slug' => 'orquestrador',
    'thread_id' => $thread->id,
    'session_id' => optional($thread->sessions()->latest()->first())->id,
    'operator_input' => 'app smoke',
    'status' => 'processing',
]);

$builder = app(\App\Services\Ai\AiProviderChoiceBuilder::class);
$options = $builder->build('rate_limited', 'codex_cli', 'gpt-5.5', null);

$job = \App\Models\AiJob::create([
    'trace_id' => $trace->id,
    'kind' => 'interaction',
    'status' => 'awaiting_user_choice',
    'agent_slug' => 'orquestrador',
    'provider' => 'codex_cli',
    'model' => 'gpt-5.5',
    'input_text' => 'app smoke',
    'prompt' => 'app smoke',
    'available_at' => now()->addYear(),
    'metadata' => [
        'provider_choice_state' => 'pending',
        'provider_choice_error_code' => 'rate_limited',
        'reset_hint' => 'May 5th 10:24 AM',
        'choice_options' => $options,
    ],
]);

echo 'TRACE_ID='.$trace->id.PHP_EOL;
echo 'JOB_ID='.$job->id.PHP_EOL;
EOF
docker cp /tmp/seed_app_pause.php atlas-backend:/tmp/seed_app_pause.php
docker exec atlas-backend php artisan tinker --execute="require '/tmp/seed_app_pause.php';"
```

- [ ] **Step 4: Abrir o thread no app**

Via deep link ou navegando até o thread. Em até ~3 segundos (próximo tick do polling), o modal deve aparecer com o título "Sem créditos até May 5th 10:24 AM" e 4 opções (switch_provider, downgrade_model, cancel, retry_same).

- [ ] **Step 5: Tap em "Continuar no codex_cli com gpt-5-4-mini (modelo menor)"**

Comportamento esperado:
- Spinner aparece no botão.
- Após sucesso, modal fecha sozinho.
- Toast: "Escolhido: Continuar no codex_cli com gpt-5-4-mini ..."
- Polling continua e o thread mostra o job no novo estado.

- [ ] **Step 6: Verificar via psql**

```bash
docker exec atlas-db psql -U atlas -d atlas -c "SELECT status, provider, model, metadata->>'provider_choice_state' AS state FROM ai_jobs WHERE id='<JOB_ID>';"
```
Expected: `status=queued|processing|failed`, `model=gpt-5-4-mini`, `state=resolved`.

- [ ] **Step 7: Smoke test do retry_same**

Repetir Steps 3-5, mas no Step 5 tap em "Já liberei (comprei créditos / upgrade) — tentar de novo agora".

Esperado:
- Modal fecha.
- DB: `metadata->>'provider_choice_state'` = `null` (resetado pra permitir nova pausa).

- [ ] **Step 8: Tag final (opcional)**

```bash
git tag provider-choice-app-mvp
```

---

## Self-review checklist (preenchido)

- [x] **Spec coverage:**
  - Sheet/modal Tamagui → adaptado pra Modal RN central com overlays registry. Justificativa documentada.
  - Hook `useProviderChoice` consumindo polling existente — task 3
  - Detecção via job.status — task 3 (encontra primeiro paused entre traces.jobs)
  - POST `/ai/jobs/:id/resume-choice` — task 1 (`resumeAiJobChoice`)
  - Mostrar `cancel` extra no rodapé — não implementado (já está como opção do builder; rodapé vira "Fechar (decidir depois)" que mantém pausa)
  - Auto-fechar overlay quando job não está mais em `awaiting_user_choice` — task 3 (effect compara `currentJobId` e fecha se sumiu)
- [x] **Placeholder scan:** nenhum TODO/TBD no plano.
- [x] **Type consistency:**
  - `AtlasAiChoiceOption.action` é `AtlasAiChoiceAction` em todo lugar
  - `AtlasAiStatus` inclui `'awaiting_user_choice'` em todas as referências
  - `resumeAiJobChoice` retorna `{ job: AtlasAiJob }` consistente com o backend
  - Hook usa `useOverlayStore` selectors atomicamente (não desestrutura)

## Notas finais

- **Nenhum teste automatizado neste plano.** O setup de teste do app é incipiente (`scripts/health-metrics.test.ts` é um script via `tsx`, não Jest). Adicionar test infra é trabalho separado. Smoke test manual cobre o caminho feliz.
- **Branch:** ainda na `main` do `atlas-server` (mas o commit de `atlas-app` é separado se for outro repo). Se `atlas-app` está em git tracking diferente, ajustar `git add` e `cwd` conforme.
- **Performance:** o hook usa `useEffect` que dispara em todo refresh de `traces`. O polling do `mobile-thread` é a cada 1.8-6s, então o effect roda nessa cadência — barato. Não precisa memoize a busca por paused job.
- **Auth:** uso de `apiRequest` (X-Atlas-Token) não exige pareamento mobile. Funciona local. Pra prod-mobile remoto, futuramente o endpoint precisaria espelho via `mobileApiRequest` (Bearer device token) — fora deste plano.
