# Atlas Loop Task-Class Discovery CLI

`atlas:loop:task-class` is the operator surface over the task-class discovery loop.

## Subcommands

### `mine --facts=PATH`

Calls `AtlasLoopTaskClassMiner::mine($campaign_id, $records)` to derive candidate clusters from
historical task outcomes. Input via `--facts` JSON: `{campaign_id, records[]}`. Output:
`{action: mine, clusters: [...]}`.

### `propose --facts=PATH`

Calls `AtlasLoopTaskClassProposer::propose($cluster)` to turn a cluster into a candidate
proposal. Input `--facts` JSON: `{cluster: {...}}`. Output: `{action: propose, proposal: ...}`.

### `register --proposal-id=ID --operator-token=TOKEN`

Calls `AtlasLoopTaskClassRegistry::approve($proposal_id, $operator_token)`. Refuses without
`--operator-token`. Returns the resulting registry entry.

### `history --class-id=ID`

Dumps every `AtlasLoopTaskClassReceiptLedger` row for the supplied class id (in append order).

## Output contract

`--json` emits a canonical, key-sorted, byte-deterministic JSON envelope. The CLI itself contains
NO business logic — every decision lives in the four services above.

## Operator workflow

1. **Mine**: run `mine` on a recent task batch to surface candidate clusters.
2. **Propose**: take a cluster you trust and run `propose` to produce a proposal object.
3. **Register**: with operator authority, run `register --proposal-id=… --operator-token=…` to
   create the registry entry.
4. **Audit**: any time, run `history --class-id=…` to view the append-only receipt chain.
