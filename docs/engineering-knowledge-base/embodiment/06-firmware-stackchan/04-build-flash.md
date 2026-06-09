---
id: atlas-embodiment-06-firmware-stackchan-04-build-flash
type: engineering_knowledge
title: "04 — Build & Flash (firmware)"
status: source_material
authority_class: design_reference
implementation_state: design_reference_no_runtime
category: physical-surface
summary: "Source material for the Atlas Embodiment/StackChan physical surface design; not current runtime authority."
canonical_owner: docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md
---
# 04 — Build & Flash (firmware)

> **Propósito:** especificar a **pipeline de build, flash e OTA** do firmware. Setup do ambiente PlatformIO, estrutura do `platformio.ini`, geração de imagens, mecanismos de flash (USB cabeado e OTA), provisioning via microSD, versioning, e considerações de CI.
>
> **Pré-requisitos:** [README](../README.md), [01-escolha-stack.md](01-escolha-stack.md), [02-reflex-layer.md](02-reflex-layer.md), [03-renderers.md](03-renderers.md).
>
> **Fora do escopo:** monitoramento em runtime (vai pra `05-monitoramento.md`); logica de OTA detalhada (deixa pra fase futura).

---

## 1. Estratégia geral

```
[Source code] ── PlatformIO build ── [firmware.bin]
       │                                    │
       ├─ Tests (CI)                        │
       │                                    │
       ▼                                    ▼
 [git commit]                       ┌─────────────┐
                                    │ Flash modes │
                                    ├─────────────┤
                                    │ a) USB-C    │ (desenvolvimento)
                                    │ b) OTA      │ (produção, futuro)
                                    │ c) microSD  │ (recovery, Fase 0)
                                    └─────────────┘
```

Pipeline simples desde Fase 0; OTA entra como melhoria depois.

---

## 2. Setup do ambiente

### 2.1 Ferramentas

| Ferramenta | Uso |
|---|---|
| **PlatformIO Core** | Build system principal |
| **PlatformIO IDE (VSCode extension)** | Desenvolvimento |
| **esptool.py** | Flash USB |
| **M5Burner** | Flash via UI (opcional, para usuários casual) |
| **OpenOCD** | JTAG debugging (opcional) |
| **Git** | Source control |

### 2.2 Setup inicial

```bash
# Instala PlatformIO Core (Python)
pip install platformio

# Verifica instalação
pio --version

# Clone projeto
git clone <repo>
cd <repo>/firmware

# Build inicial (descarrega frameworks)
pio run -e stackchan
```

Toolchain (compilers ESP32, frameworks Arduino-ESP32) é descarregado automaticamente pelo PlatformIO no primeiro build.

---

## 3. Estrutura do projeto

```
firmware/
├── platformio.ini              # Config PlatformIO
├── partitions.csv              # Partition table custom
├── src/                        # Código C++
├── include/                    # Headers públicos
├── lib/                        # Libs locais (se houver)
├── data/                       # Vai pra LittleFS (config, certs)
├── assets/                     # Sprites, sons (vão pra microSD)
├── test/                       # Testes
├── scripts/                    # Build helpers
│   ├── pre_build.py
│   ├── post_build.py
│   └── flash_microsd.sh
└── .github/workflows/          # CI (se aplicável)
    └── build.yml
```

---

## 4. `platformio.ini`

```ini
[platformio]
default_envs = stackchan

[env]
framework = arduino
platform = espressif32@^6.6.0    ; pinned major
monitor_speed = 115200
upload_speed = 921600
build_flags =
    -Os
    -DCORE_DEBUG_LEVEL=2
    -DCONFIG_ARDUHAL_LOG_COLORS

[env:stackchan]
board = m5stack-cores3            ; CoreS3 board definition
board_build.partitions = partitions.csv
board_build.filesystem = littlefs
lib_deps =
    m5stack/M5Unified@^0.2.0
    m5stack/M5GFX@^0.2.0
    ; StackChan-BSP oficial M5 — pin por tag/commit validado no bring-up
    https://github.com/m5stack/StackChan-BSP.git
    gilmaimon/ArduinoWebsockets@^0.5.4
    bblanchon/ArduinoJson@^7.0.0
    ; ... outras
extra_scripts =
    pre:scripts/pre_build.py
    post:scripts/post_build.py

[env:stackchan-debug]
extends = env:stackchan
build_type = debug
build_flags =
    ${env.build_flags}
    -DDEBUG_LEVEL=4

[env:stackchan-release]
extends = env:stackchan
build_type = release
build_flags =
    ${env.build_flags}
    -DDEBUG_LEVEL=1
    -DNDEBUG

[env:stackchan-test]
extends = env:stackchan
test_framework = unity
build_flags =
    ${env.build_flags}
    -DUNIT_TEST
```

⚠️ **DECISÃO PENDENTE:** Versões exatas das libs serão fixadas após avaliação inicial.

### 4.2 Dependência oficial StackChan-BSP

O BSP oficial da M5 é a base de hardware para Fase 0:

- display via M5Unified/M5GFX;
- servos via `Motion`;
- RGB;
- touch sensor;
- battery voltage/current;
- servo power.

Política:

- Pin por commit/tag validado.
- Não depender de `main` sem lock.
- Qualquer patch local deve ficar em wrapper `hardware/stackchan_board.*`, não em fork imediato do BSP.
- Limites de servo devem seguir os limites conservadores do firmware oficial M5 quando divergirem do BSP.

### 4.1 Partition table custom (`partitions.csv`)

ESP32-S3 com 16MB Flash. Particionamento sugerido:

```csv
# Name,    Type,  SubType, Offset,    Size,   Flags
nvs,       data,  nvs,     0x9000,    0x5000,
otadata,   data,  ota,     0xe000,    0x2000,
app0,      app,   ota_0,   0x10000,   0x300000,
app1,      app,   ota_1,   0x310000,  0x300000,
spiffs,    data,  spiffs,  0x610000,  0x9F0000,
```

- 3 MB por slot OTA (firmware + assets esticados).
- 9.7 MB para LittleFS/SPIFFS (asset cache, certs, config).
- 2 OTA slots para upgrade seguro (rollback se falhar).

---

## 5. Targets de build

### 5.1 Debug
- `-Og` ou `-O0` para debug.
- Debug level máximo de logs.
- Símbolos preservados.
- Tamanho maior — não cabe em release flash.

### 5.2 Release
- `-Os` (otimizar tamanho).
- Logs apenas críticos.
- Strip de símbolos.
- Tamanho-target: <1.5MB para sobrar em OTA slot.

### 5.3 Test
- Compila com Unity (test framework).
- Roda em PIO native (host) quando possível para unit tests.
- Para hardware-in-the-loop tests: target especial.

---

## 6. Pre-build / post-build hooks

### 6.1 Pre-build (`pre_build.py`)

Tarefas:
- Gera arquivo de versão (`version.h` com `FIRMWARE_VERSION`, `BUILD_DATE`, `GIT_COMMIT`).
- Valida que assets necessários estão presentes em `assets/`.
- Compila sprites/sons em formato consumível pelo firmware.

### 6.2 Post-build (`post_build.py`)

Tarefas:
- Calcula checksum SHA-256 do binário.
- Gera arquivo `firmware.bin.json` com metadata (versão, checksum, tamanho).
- Opcionalmente: assina binário (chave de OTA, futuro).

---

## 7. Versionamento

### 7.1 Esquema

`fw-MAJOR.MINOR.PATCH` (ex: `fw-0.1.0`).

- **Patch** (0.1.x): bug fixes, sem mudança de protocol.
- **Minor** (0.x.0): nova capability, novo comando suportado.
- **Major** (x.0.0): mudança incompatível de protocolo.

`surface.version` no Interaction Envelope reporta `fw-0.1.0`.

### 7.2 Geração via git

```python
# pre_build.py extrai
version = git_describe(--tags --always)
commit = git_rev_parse(--short HEAD)
build_date = now()
```

Embute em `version.h`. Firmware reporta no boot e em telemetria.

---

## 8. Modos de flash

### 8.1 Cabeado (USB-C OTG)

Modo padrão de desenvolvimento.

```bash
# Flash com PlatformIO
pio run -e stackchan -t upload

# Flash assets para LittleFS
pio run -e stackchan -t uploadfs

# Monitor serial
pio device monitor
```

Procedimento:
1. Conecte StackChan via USB-C.
2. Pressione RST por 3s até LED verde (download mode).
3. `pio run -t upload`.
4. Boot automático após upload.

### 8.2 microSD (recovery / provisioning inicial)

Para Fase 0 e cenário de recovery.

Procedimento:
1. Copie `firmware.bin` + `provisioning.json` para SD.
2. Insere no StackChan.
3. Boot detecta arquivos especiais; flasha firmware (se diferente do atual) + aplica provisioning.
4. Move arquivos para `processed/` na SD para não re-aplicar.

⚠️ **DECISÃO PENDENTE:** automatic update via SD pode ser feature de risco (atacante com SD). Provavelmente: SD update apenas no primeiro boot ou via comando explícito.

### 8.3 OTA (Over-The-Air)

Para depois da Fase 0 (não bloqueia início). Requer:

- Endpoint HTTP/HTTPS servindo binários.
- Comando `system.firmware_update_available` da alma com URL + checksum.
- Cliente OTA no firmware (Arduino-ESP32 tem builtin).

Workflow:
1. Alma anuncia versão nova disponível.
2. Corpo notifica usuário (LED amarelo + card "update disponível").
3. Usuário aprova (touch).
4. Corpo descarrega para slot OTA inativo.
5. Verifica checksum.
6. Marca como boot target.
7. Reboot.
8. Boot validação: se não conectar à alma em N tentativas, **rollback** automático para slot anterior.

---

## 9. Provisionamento

Detalhes em `04-protocolos/04-transporte.md` (auth, tokens). Aqui o **fluxo concreto**:

### 9.1 Conteúdo do `provisioning.json`

```json
{
  "schema_version": "0.1",
  "surface_id": "stackchan_main",
  "atlas_endpoint": "wss://atlas.local:8443/atlas/surface/stackchan",
  "auth_token": "<bearer_token>",
  "ca_cert_pem": "-----BEGIN CERTIFICATE-----\n...",
  "wifi": {
    "ssid": "...",
    "password": "..."
  },
  "voice_profile": "stackchan_default",
  "initial_mode": "ambient"
}
```

### 9.2 Geração na alma

Comando administrativo na alma gera arquivo:

```
atlas surface provision --new --output ~/Downloads/provisioning.json
```

Token único, registrado no Surface Registry da alma.

### 9.3 Aplicação no corpo

1. Boot detecta `provisioning.json` em SD.
2. Valida schema.
3. Migra dados sensíveis (token, wifi password) para NVS encrypted no flash interno.
4. CA cert vai para LittleFS.
5. Move `provisioning.json` para `provisioning_applied.json` (não re-aplica).
6. Reboot para aplicar.

---

## 10. CI / CD

### 10.1 GitHub Actions (sugestão)

```yaml
name: Firmware build

on:
  push:
    branches: [main]
  pull_request:

jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Setup Python
        uses: actions/setup-python@v5
        with:
          python-version: '3.11'
      - name: Install PlatformIO
        run: pip install platformio
      - name: Cache PlatformIO
        uses: actions/cache@v4
        with:
          path: ~/.platformio
          key: ${{ runner.os }}-pio-${{ hashFiles('**/platformio.ini') }}
      - name: Build (release)
        run: pio run -e stackchan-release
      - name: Run tests
        run: pio test -e stackchan-test
      - name: Archive binary
        uses: actions/upload-artifact@v4
        with:
          name: firmware-${{ github.sha }}
          path: .pio/build/stackchan-release/firmware.bin
```

### 10.2 Smoke tests
- Build sem warning.
- Tamanho dentro do limite.
- Unit tests passando.

### 10.3 Hardware-in-the-loop (opcional, futuro)
- Build → flash em StackChan dedicado de CI.
- Suite de testes contra hardware real.

---

## 11. Debugging

### 11.1 Serial monitor
```bash
pio device monitor --baud 115200
```
Logs do firmware via UART. Default em desenvolvimento.

### 11.2 JTAG (avançado)
ESP32-S3 suporta JTAG. Requer adapter (J-Link, etc.) e setup OpenOCD. Útil para debug de crashes/race conditions difíceis.

### 11.3 Crash dumps
ESP32 captura stack trace em crashes. Configurar para salvar em microSD para análise posterior.

```c
// No setup()
esp_register_shutdown_handler(save_crash_dump_to_sd);
```

---

## 12. Versionamento de assets

Assets (sprites, sons) também são versionados:

```
assets/
├── version.txt          # Versão do bundle de assets
├── faces/
│   ├── neutral.png
│   ├── attentive.png
│   └── ...
├── icons/
└── sounds/
```

Mudanças em assets:
- Patch de assets sem patch de firmware: aceitável (diferentes ciclos).
- Firmware reporta `surface.assets_version` em telemetria.
- Atlas pode comandar atualização de assets via OTA dedicado (futuro).

---

## 13. Distribuição

### 13.1 Workflow típico (release de patch)

1. Bump versão em `version.h` (ou via tag git).
2. Build release.
3. Tag git.
4. CI gera artefato.
5. Manual: Flash via USB ou push para servidor OTA.
6. Atlas backend anuncia versão para corpos.

### 13.2 Rollback

Se update OTA quebra:
- Boot validation falha em N tentativas (default: 3).
- Bootloader marca slot atual como inválido.
- Boot pelo slot anterior automaticamente.
- Notifica alma: `physical.system.ota_rollback`.

---

## 14. Anti-padrões

| Anti-padrão | Por quê |
|---|---|
| Versões de libs flutuantes (sem pin) | Build não reproduzível |
| Hardcode de credenciais no source | Vazamento se repo público |
| Flash sem verificação de checksum | Corrupção silenciosa |
| OTA sem rollback automático | Brick após update ruim |
| Apagar partition table sem necessidade | Provisioning perdido |
| Versão de firmware não reportada em telemetria | Audit difícil |
| `auto_apply: true` em OTA | Update silencioso, ruim para confiança |
| Build com warnings ignorados | Tech debt acumula |

---

## 15. Decisões pendentes

| Decisão | Bloqueia |
|---|---|
| ⚠️ Lib de WebSocket exata | Implementação |
| ⚠️ Cert pinning details | Setup |
| ⚠️ NVS encrypted setup | Provisioning |
| ⚠️ OTA endpoint design | Pós-Fase 0 |
| ⚠️ CI hardware-in-the-loop | Investimento futuro |
| ⚠️ Política de assinatura de OTA | Segurança |

---

## Próximos passos de leitura

- `05-monitoramento.md` — telemetria e logs em runtime.
- `04-protocolos/04-transporte.md` — provisioning detalhado.
- `08-roadmap/01-fase-0-espelho.md` — primeira fase a implementar.
