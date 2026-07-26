# SCOREBOARD S-* (seal — real owners under test)

| ID | status | exercised shipped entry |
|---|---|---|
| S-ENG-SPINE | **wired_qos_law** | `EngineeringSpine::{resolveDepth,blockers,evaluate}` → `AgentQosExcellenceLaw` |
| S-CONTEXT | **wired_pack_for** | `BrainMembrane::assemble` → `AtlasOpenBrainContextPackService::packFor` |
| S-LAND-LEARN | **wired_report** | `LandLearnSpine::report` → `AtlasTaskServingService::report` (real intake) |
| S-OPERATOR | **wired_snapshot** | `OperatorTruth::snapshot` → `AtlasControlPlaneSnapshotService::snapshot` |
| S-PROVIDER-FABRIC | **wired_capability_route** | `ProviderFabric::responseContract` + `liftToolCallsFromProviderText` |
| S-AUTH-BOUNDARY | **path_core_contract** | admit/recheck contract; cutover LIVE NOT_CLAIMED |
| S-EVIDENCE | **path_core_contract** | second_ledger=false |
| S-AMBITION | **path_core_contract** | acde_allowed=false |
| S-WORLD | **NOT_CLAIMED** | |
| S-KERNEL-MAP | **docs_green** | CODEMAP-KERNEL.md |

Proof: `tests/Unit/Architecture/AsddSpineContractsTest.php` (pack/report/snapshot drive real services).
