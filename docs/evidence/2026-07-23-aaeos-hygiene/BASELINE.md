# AAEOS Hygiene Baseline — 2026-07-23

## Live `app/Services/Ai/Aaeos`

| Bucket | Files | LOC |
|--------|------:|----:|
| (root) maturity/truth | 26 | 6577 |
| Cores | 8 | 2245 |
| Generated | 3 | 1183 |
| Control | 18 | 1144 |
| Support | 3 | 583 |
| Spine | 2 | 178 |
| **TOTAL** | **60** | **11910** |

## Archive

- `archive/app/Services/Ai/Aaeos/Quarantine`: ~306 PHP / ~132k LOC (frozen)

## Tests

- Orphan Generated tests: **39** (import archive-only classes)
- Keep: AtlasLearningProposalsTest, AtlasMemoryCognitiveImmuneLearningKernelTest, AtlasCodexReviewChainContractTest

## Target

- Live Aaeos = Control + Spine only (~20 files / ~1.3k LOC)
- Zero Generated/Cores/root under Aaeos
- AAEOS vocabulary = org control plane only
