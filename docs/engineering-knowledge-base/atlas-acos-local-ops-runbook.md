# Atlas ACOS — Runbook de operação local (ELEV-24)

> **Escopo:** os quatro casos nomeados de continuidade num MBP real: **rotação
> de ledger**, **disco cheio**, **cold-start** e **power-loss** no meio de um
> append. Nada de HA, replicação ou orquestração distribuída — o Atlas roda
> **local**, e este runbook cobre só o que uma máquina do operador precisa.

## 1. Política de rotação/retenção por ledger

Cada série JSONL/tabela declarada em `AcosMaxMeasureSeriesRegistry` (ELEV-20s)
tem política de rotação **explícita** em `AcosMaxLedgerRotationRegistry`:
`{max_size_mb, max_age_days, mode}`. Modos válidos:

| Mode | Semântica |
|---|---|
| `append_forever` | Não rotaciona; ledger de auditoria/integridade (ex.: `atlas.evidence_ledger.hash_chain.v1`). |
| `rotate_size` | Rotaciona quando o arquivo passa `max_size_mb`. |
| `rotate_age` | Arquivos com `> max_age_days` são rotacionados. |
| `rotate_hybrid` | O que estourar primeiro (tamanho OU idade). |

O **guard arquitetural** `Elev24RotationRegistryTest` recusa qualquer série
ELEV-20s sem política declarada. Novos ledgers precisam declarar política
no MESMO commit do slice que os cria (regra ELEV-20).

## 2. Disco cheio

`DiskFreeWatchdogCheck` (`elev-24.disk_free`) lê `disk_free_space(storage/atlas)`
a cada rodada de WDG. Threshold padrão: **`ATLAS_DISK_FREE_FLOOR_GB=5`** (bump
via `config('atlas_resource_budget.disk_free_floor_gb')`).

Contrato de comportamento **por classe de tráfego**:

- **Background** (produtores autônomos: brain seed, arena runs, digest,
  scanner) → consulta `DiskFreeWatchdogCheck::isBelowFloor()` antes do append;
  se `true`, **pausa** com contador `background_disk_pause_count`. O turno vivo
  segue.
- **Interactive** (turno humano/AI ativo com o operador) → **NUNCA** é
  bloqueado por disco cheio; o append tenta e falha honestamente com o erro
  do FS. A latência de resposta ao operador é sagrada.

## 3. Cold-start (ordem de boot)

Depois de reboot/power-loss/manutenção:

1. **PostgreSQL 16** — `sudo pg_ctlcluster 16 main start` (config
   `docker/postgres/16/acos-max-asi-03.conf`; ASI-03 tuning).
2. **Semantic RAG daemon** — inicia sob demanda ao primeiro embed (MAXA-01);
   verifique com `atlas:semantic:embedding-info --json` (`daemon_alive=true`).
   Manifest sha256 é verificado (ELEV-19) antes de servir.
3. **MCP `atlas-open-brain`** — o cliente (Cursor/Claude Code/Codex) sobe
   sob demanda; verifique com `atlas open-brain mcp --describe`.
4. **Hooks Claude Code / Cursor** — inertes até o próximo turno; sem step de
   boot dedicado.

## 4. Power-loss durante append

A **cadeia de hash** (MAXL-02, `atlas_ledger_events`) já detecta linhas
malformadas: `AtlasLedgerHashChainVerifier` marca gap/tamper e a próxima
gravação é sob `prev_event_hash=legacy_unchained`. **Nenhuma linha meia-escrita
quebra o parse** — o `MessageStreamJsonlReader` usa `fgets` linha-a-linha e o
`json_decode` de linha inválida retorna `null` (skip, não crash).

Para JSONLs fora da cadeia (arena runs, latency ledger, capture receipts):
o padrão é `fopen(..., 'ab')` + `fwrite($line."\n")`. O flush é do OS. Uma
linha parcial no boot seguinte:

- É ignorada pelos leitores (`json_decode` falha → skip).
- O produtor next-append escreve na linha seguinte; **nenhum leitor confunde
  parcial com dado real**.

Nenhum caminho vivo depende de rotação atômica com fsync — a auditoria vive
na cadeia hash da tabela SQL (MAXL-02), e todo receipt fora da cadeia é
observação, não decisão.

## 5. O que NÃO cobrimos (por decisão pétrea)

- HA/replicação — Atlas é local-first; não roda em cluster.
- Backup automático — o operador roda `atlas:substrate:restore-drill` como
  drill de rollback (ELEV-17); não há daemon de backup contínuo.
- Rotação de credenciais — `X-Atlas-Token` é rotacionado à mão via `.env`.

## Referências

- Registry de séries: `App\Services\Ai\AcosMax\AcosMaxMeasureSeriesRegistry`.
- Registry de rotação: `App\Services\Ai\AcosMax\AcosMaxLedgerRotationRegistry`.
- Cadeia hash: `App\Services\Ai\EvidenceLedger\AtlasLedgerHashChainVerifier`.
- Restore drill: `atlas:substrate:restore-drill --json` (ELEV-17).
- Budget conjunto: `App\Services\Ai\AcosMax\AtlasResourceBudgetService` (ELEV-27).
- Integridade de modelo: `App\Services\Ai\AcosMax\AtlasLocalModelIntegrityService` (ELEV-19).
