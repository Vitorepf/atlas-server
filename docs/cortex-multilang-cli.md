# Cortex MultiLang CLI

`atlas:loop:cortex:multilang` surfaces the multi-language FACT extractors
(`AtlasCortexLanguageRegistry`, `AtlasCortexTypeScriptParserFacts`,
`AtlasCortexYamlConfigFactExtractor`, `AtlasCortexApiSurfaceExtractor`) to the operator.

## Subcommands

| Command | Effect |
| --- | --- |
| `atlas:loop:cortex:multilang list` | Prints the deterministic table of supported languages (php, typescript, yaml) with bound parser/extractor class-strings. |
| `atlas:loop:cortex:multilang extract --path=<abs>` | Resolves the language via extension, invokes the bound extractor, writes a byte-deterministic JSON under `storage/atlas/cortex/multilang/<lang>/<sha>.json`, and emits the FACT payload. |
| `atlas:loop:cortex:multilang history --language=<lang> --limit=N` | Tails the last N persisted extractions for the language. |

## Loop scope guard

`extract` refuses paths outside the Loop scope roots
(`app/`, `tests/`, `docs/`, `config/`). A path outside the scope yields a non-zero
exit and zero filesystem side effects.

## Idempotency

Re-extracting the same path with the same FACTS produces the same sha256 → the same
output filename → byte-identical re-write. Two consecutive `extract` runs leave the
language directory with the same single file.

## Anti-Goodhart

FACTS only. No scores, no rankings, no qualitative summary. Receipts in the language
directory are `<sha>.json` filenames so consumers can hash-diff over time without
collapsing observations into a scalar.
