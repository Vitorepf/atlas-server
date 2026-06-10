<?php

declare(strict_types=1);

namespace App\Services\Ai\Organism;

use App\Services\Ai\CrossDomain\AtlasCrossDomainMeshService;
use App\Services\Ai\Obra\DeterministicObraDecomposer;
use App\Services\Ai\Obra\ObraDecomposer;
use App\Services\Ai\Obra\ObraNodeDraft;
use App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * AOBG N4.F3 — the CROSS-DOMAIN MISSION SPINE: an operator INTENT SPANS domains.
 *
 * N3 gave the brain the power to DRIVE the engine for an OBRA in software (intent → plan-DAG
 * → per-node certified delivery). N4.F1/F2 generalized the SAME closed loop to ANY domain via
 * the ORGANISM seam ({@see AtlasOrganismService}: propose → validate-on-honest-metric → record
 * → requires_operator). N4.F3 closes the generalization: a single intent that SPANS domains
 * becomes a cross-domain MISSION —
 *
 *   1) DECOMPOSE — reuse the N3 plan-DAG decomposition ({@see ObraDecomposer}; the default
 *      {@see DeterministicObraDecomposer} is cost-free) to break the intent into nodes.
 *   2) ROUTE — route each node to a canonical DOMAIN ({@see OrganismDomainRouter}; deterministic,
 *      resolved via {@see CrossDomainTaxonomyMap} — the M-8 reconciled 21-domain superset).
 *   3) GOVERN THE CROSSING — for each node, ask the ARPTL veto ({@see AtlasCrossDomainMeshService})
 *      whether the mission's ANCHOR domain may cross to the node's domain at the node's privacy
 *      class. A VETOED crossing (e.g. a secret-domain crossing to a non-trusted target) BLOCKS
 *      the node: NO proposal is generated, NOTHING crosses to a provider — it is recorded vetoed.
 *   4) PROPOSE — for an allowed, registered node, call {@see AtlasOrganismService::propose()} →
 *      a brain-anchored DOMAIN PROPOSAL (a trade idea, a campaign draft, …), VALIDATED by the
 *      domain's HONEST metric (finance = DSR/PBO; win-rate FORBIDDEN), with the actuation gate
 *      ALWAYS 'requires_operator'. A routed-but-unregistered domain is recorded no_handler
 *      (honest — never a fabricated proposal).
 *   5) RECORD + COMPOUND — propose() records each proposal into the brain; the NEXT mission's
 *      per-domain propose() reads prior proposals (cross-domain COMPOUNDING — the M× across
 *      width: a finance proposal informs a later marketing one).
 *
 * HONEST CEILING (declared, NON-NEGOTIABLE): the whole mission is PROPOSE-ONLY. It NEVER
 * executes a real-world side effect — no real money, no trade/order, no ad spend, no purchase,
 * no publish, no outbound HTTP that acts. The actuate boundary is sealed in
 * {@see AbstractDomainActuator} (final method) and only ever returns 'requires_operator'. The
 * operator executes any real-world action themselves. Never claim "operating companies live".
 *
 * COST: cost-free by construction. The decomposer is deterministic; the router is deterministic;
 * the mesh veto is pure logic; the proposers are on-machine/stubbable (finance reuses the
 * on-machine strategy-loop backtest, no provider/order). The only spend a real proposer could
 * incur is GATED behind its own flag + cost guard, and tests stub it. No tokens are ever burned.
 *
 * SENSITIVE: a sensitive/secret/cyber domain (finance is sensitive) stays ON-MACHINE — the
 * organism never provider-serializes a sensitive proposal, and the persisted node carries
 * sensitive=true so the status presenter keeps it local.
 */
final class AtlasOrganismMissionService
{
    public const SCHEMA = 'atlas.organism.mission.v1';

    public const STATUS_COMMISSIONED = 'commissioned';

    public const OUTCOME_PROPOSED = 'proposed';

    public const OUTCOME_VETOED = 'vetoed';

    public const OUTCOME_NO_HANDLER = 'no_handler';

    public function __construct(
        private readonly AtlasOrganismService $organism,
        private readonly AtlasOrganismRegistry $registry,
        private readonly ObraDecomposer $decomposer = new DeterministicObraDecomposer,
        private readonly OrganismDomainRouter $router = new OrganismDomainRouter,
        private readonly CrossDomainTaxonomyMap $taxonomy = new CrossDomainTaxonomyMap,
        private readonly AtlasCrossDomainMeshService $mesh = new AtlasCrossDomainMeshService,
    ) {}

    /**
     * Commission a cross-domain mission from one intent.
     *
     * @param  array<string,mixed>  $opts {
     *     workspace?: string,            // workspace id/path (label only, for the mission header)
     *     id?: string,                   // explicit mission id (else derived from intent+workspace)
     *     max_nodes?: int,               // plan-DAG node cap (default atlas.obra.max_nodes)
     *     anchor_domain?: string,        // the mission's "from" domain for the ARPTL crossing (default engineering)
     *     fallback_domain?: string,      // domain for an unroutable node (default = anchor_domain)
     *     node_opts?: array<string,array>, // per-node-text opts forwarded to propose() (e.g. payload for finance)
     *     persist?: bool,                // persist the mission/nodes (default true; false ⇒ in-memory plan only)
     *     ...                            // forwarded to each propose() call (e.g. prior_limit)
     * }
     * @return array<string,mixed> {
     *     schema, mission_id, workspace_id, anchor_domain, status,
     *     node_count, domains_spanned: list<string>,
     *     nodes: list<{id, seq, title, request, domain, sensitive, outcome,
     *                  veto_reason?, routed_by, proposal?, validation?, actuation_gate, depends_on}>,
     *     ceiling: string
     * }
     *
     * @throws InvalidArgumentException on empty intent / empty or over-cap decomposition
     */
    public function commission(string $intent, array $opts = []): array
    {
        $intent = trim($intent);
        if ($intent === '') {
            throw new InvalidArgumentException('intent cannot be empty');
        }

        $workspaceId = isset($opts['workspace']) && trim((string) $opts['workspace']) !== ''
            ? trim((string) $opts['workspace'])
            : 'default';
        $anchor = $this->taxonomy->canonical((string) ($opts['anchor_domain'] ?? 'engineering')) ?? 'engineering';
        $fallback = $this->taxonomy->canonical((string) ($opts['fallback_domain'] ?? $anchor)) ?? $anchor;
        $maxNodes = max(1, (int) ($opts['max_nodes'] ?? config('atlas.obra.max_nodes', 12)));
        $persist = (bool) ($opts['persist'] ?? true);
        $nodeOpts = is_array($opts['node_opts'] ?? null) ? $opts['node_opts'] : [];

        // --- 1) DECOMPOSE — reuse the N3 plan-DAG decomposition (cost-free deterministic). ---
        $drafts = $this->decomposer->decompose($intent, ['workspace' => $workspaceId, 'max_nodes' => $maxNodes]);
        if ($drafts === []) {
            throw new InvalidArgumentException('decomposition produced no steps');
        }
        if (count($drafts) > $maxNodes) {
            throw new InvalidArgumentException('decomposition exceeds max_nodes ('.count($drafts).' > '.$maxNodes.')');
        }
        $drafts = array_values($drafts);

        $missionId = $this->missionId($intent, $workspaceId, $opts);

        // Forward per-call opts to propose() (e.g. prior_limit), minus the mission-only keys.
        $forward = $opts;
        foreach (['workspace', 'id', 'max_nodes', 'anchor_domain', 'fallback_domain', 'node_opts', 'persist'] as $k) {
            unset($forward[$k]);
        }

        $nodes = [];
        $domainsSpanned = [];
        foreach ($drafts as $seq => $draft) {
            $nodeId = $missionId.':n'.$seq;
            $nodeText = trim($draft->request !== '' ? $draft->request : $draft->title);

            // --- 2) ROUTE the node to a canonical domain (deterministic, never invented). ---
            $routed = $this->router->route($nodeText, $fallback);
            $domain = $routed['domain'];
            $sensitive = $this->taxonomy->isSensitive($domain);
            $domainsSpanned[$domain] = true;

            $dependsOn = $this->dependsNodeIds($draft, $drafts, $missionId);

            $base = [
                'id' => $nodeId,
                'seq' => $seq,
                'title' => $draft->title,
                'request' => $draft->request,
                'domain' => $domain,
                'sensitive' => $sensitive,
                'routed_by' => $routed['by'],
                'depends_on' => $dependsOn,
                'actuation_gate' => 'requires_operator',
            ];

            // --- 3) GOVERN THE CROSSING — ARPTL veto: anchor → node domain at the crossing's
            //        privacy class (derived from BOTH endpoints' sensitivity). A vetoed
            //        crossing BLOCKS the node: no proposal, nothing crosses. Honest.
            $veto = $this->vetoCrossing($anchor, $domain);
            if ($veto !== null) {
                $nodes[] = $base + [
                    'outcome' => self::OUTCOME_VETOED,
                    'veto_reason' => $veto,
                    'validation' => ['metric' => null, 'value' => null, 'passed' => false, 'method' => 'arptl_veto'],
                ];

                continue;
            }

            // --- 4) PROPOSE — only for a registered domain. An unregistered (but allowed)
            //        domain is recorded no_handler — never a fabricated proposal. propose()
            //        also RECORDS the proposal into the brain (step 5: compounding).
            if (! $this->registry->has($domain)) {
                $nodes[] = $base + [
                    'outcome' => self::OUTCOME_NO_HANDLER,
                    'validation' => ['metric' => null, 'value' => null, 'passed' => false, 'method' => 'no_domain_handler'],
                ];

                continue;
            }

            $proposeOpts = $forward;
            // Per-node-text payload (e.g. supply finance candidate returns/bars cost-free).
            if (isset($nodeOpts[$nodeText]) && is_array($nodeOpts[$nodeText])) {
                $proposeOpts = $nodeOpts[$nodeText] + $proposeOpts;
            }

            try {
                $result = $this->organism->propose($domain, $nodeText, $proposeOpts);
            } catch (Throwable $e) {
                // An honest per-node failure never aborts the whole mission or fabricates a
                // proposal — record it as no_handler with the reason.
                $nodes[] = $base + [
                    'outcome' => self::OUTCOME_NO_HANDLER,
                    'validation' => ['metric' => null, 'value' => null, 'passed' => false, 'method' => 'propose_error'],
                ];

                continue;
            }

            $nodes[] = $base + [
                'outcome' => self::OUTCOME_PROPOSED,
                'proposal' => $result['proposal'] ?? [],
                'validation' => $result['validation'] ?? [],
                'proposal_ref' => $result['recorded']['node_ref'] ?? null,
                'brain' => $result['brain'] ?? [],
                // The proposal carries its own sensitive flag; honour the stronger of the two.
                'sensitive' => $sensitive || (bool) ($result['proposal']['sensitive'] ?? false),
                'actuation_gate' => (string) ($result['actuation_gate'] ?? 'requires_operator'),
            ];
        }

        $domainsSpannedList = array_keys($domainsSpanned);

        $envelope = [
            'schema' => self::SCHEMA,
            'mission_id' => $missionId,
            'workspace_id' => $workspaceId,
            'anchor_domain' => $anchor,
            'status' => self::STATUS_COMMISSIONED,
            'node_count' => count($nodes),
            'domains_spanned' => $domainsSpannedList,
            'decomposer' => $this->decomposer->label(),
            'nodes' => $nodes,
            'actuation_gate' => 'requires_operator',
            'ceiling' => AbstractDomainActuator::CEILING,
        ];

        if ($persist) {
            $this->persist($envelope, $intent);
        }

        return $envelope;
    }

    /**
     * Read back a commissioned mission (header + nodes) for the status command. Provider-safe:
     * sensitive nodes carry sensitive=true; no payload is ever stored or returned.
     *
     * @return array<string,mixed>|null null when the mission/store is absent
     */
    public function status(string $missionId): ?array
    {
        if (! $this->storePresent()) {
            return null;
        }

        $mission = DB::table('atlas_organism_missions')->where('id', $missionId)->first();
        if ($mission === null) {
            return null;
        }

        $rows = DB::table('atlas_organism_nodes')
            ->where('mission_id', $missionId)
            ->orderBy('seq')
            ->get();

        $meta = $this->decodeJson((string) $mission->meta);
        $nodes = [];
        $domainsSpanned = [];
        foreach ($rows as $row) {
            $domainsSpanned[(string) $row->domain] = true;
            $nodes[] = [
                'id' => (string) $row->id,
                'seq' => (int) $row->seq,
                'title' => (string) $row->title,
                'request' => (string) $row->request,
                'domain' => (string) $row->domain,
                'sensitive' => (bool) $row->sensitive,
                'outcome' => (string) $row->outcome,
                'veto_reason' => $row->veto_reason !== null ? (string) $row->veto_reason : null,
                'proposal_ref' => $row->proposal_ref !== null ? (string) $row->proposal_ref : null,
                'validation' => $this->decodeJson((string) $row->validation),
                'actuation_gate' => (string) $row->actuation_gate,
                'depends_on' => $this->decodeJson((string) $row->depends_on),
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'mission_id' => (string) $mission->id,
            'workspace_id' => (string) $mission->workspace_id,
            'anchor_domain' => (string) $mission->anchor_domain,
            'status' => (string) $mission->status,
            'node_count' => count($nodes),
            'domains_spanned' => array_keys($domainsSpanned),
            'meta' => $meta,
            'nodes' => $nodes,
            'actuation_gate' => 'requires_operator',
            'ceiling' => AbstractDomainActuator::CEILING,
        ];
    }

    /**
     * The ARPTL veto for the crossing anchor → node domain. Returns null when the crossing
     * is ALLOWED (or N/A), or the veto reason string when the mesh REFUSES it.
     *
     * PRIVACY CLASS is derived from BOTH endpoints' sensitivity (the mesh's `from` carries
     * the class; the flow is anchor → node, but a sensitive TARGET context is just as
     * leak-prone, so we take the stronger of the two):
     *   - both endpoints sensitive ⇒ SECRET (cross-sensitive-domain flow; only crosses inside
     *     a trusted cluster, e.g. finance→trading allowed, finance→engineering vetoed);
     *   - exactly one endpoint sensitive ⇒ SENSITIVE (never reaches a consumer audience, e.g.
     *     health→marketing vetoed);
     *   - neither sensitive ⇒ NORMAL (always allowed).
     *
     * The crossing is N/A (allowed) when the node domain equals the anchor (same-domain — no
     * bridge), or either side is not a mesh-bridgeable domain (a registry-only id with no mesh
     * alias — those are non-sensitive by the taxonomy and stay on-machine). The mesh evaluates
     * by MESH id, so we resolve canonical → mesh first.
     */
    private function vetoCrossing(string $anchor, string $domain): ?string
    {
        if ($anchor === $domain) {
            return null; // same domain — no cross-domain bridge to govern.
        }

        $fromMesh = $this->meshId($anchor);
        $toMesh = $this->meshId($domain);
        if ($fromMesh === null || $toMesh === null) {
            // A registry-only domain (no mesh edge) — not a mesh-bridgeable audience crossing.
            // These are non-sensitive by the taxonomy; treat as allowed (stays on-machine).
            return null;
        }

        $anchorSensitive = $this->taxonomy->isSensitive($anchor);
        $domainSensitive = $this->taxonomy->isSensitive($domain);
        $privacy = match (true) {
            $anchorSensitive && $domainSensitive => AtlasCrossDomainMeshService::PRIVACY_SECRET,
            $anchorSensitive || $domainSensitive => AtlasCrossDomainMeshService::PRIVACY_SENSITIVE,
            default => AtlasCrossDomainMeshService::PRIVACY_NORMAL,
        };

        try {
            $decision = $this->mesh->evaluate($fromMesh, $toMesh, $privacy);
        } catch (Throwable) {
            // The mesh refuses an unknown domain — be conservative and BLOCK (never cross on error).
            return 'arptl_evaluate_error';
        }

        if (($decision['approved'] ?? false) === true) {
            return null;
        }

        $reasons = array_values(array_filter((array) ($decision['reason'] ?? []), 'is_string'));

        return $reasons === [] ? 'arptl_vetoed' : implode(',', $reasons);
    }

    /** Resolve a canonical domain id to its mesh id (the AtlasCrossDomainMeshService id), or null. */
    private function meshId(string $canonical): ?string
    {
        $meta = $this->taxonomy->all()[$canonical] ?? null;
        $mesh = is_array($meta) ? ($meta['mesh'] ?? null) : null;
        if (! is_string($mesh) || $mesh === '') {
            return null;
        }

        return in_array($mesh, AtlasCrossDomainMeshService::DOMAINS, true) ? $mesh : null;
    }

    /**
     * Map a draft's depends_on (decomposer-local keys) to this mission's node ids by
     * matching against the ordered drafts. Deterministic.
     *
     * @param  list<ObraNodeDraft>  $drafts
     * @return list<string>
     */
    private function dependsNodeIds(ObraNodeDraft $draft, array $drafts, string $missionId): array
    {
        $keyToSeq = [];
        foreach ($drafts as $seq => $d) {
            $keyToSeq[$d->key] = $seq;
        }
        $out = [];
        foreach ($draft->dependsOn as $depKey) {
            if (isset($keyToSeq[$depKey])) {
                $out[] = $missionId.':n'.$keyToSeq[$depKey];
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Persist the mission header + nodes atomically (idempotent re-commission). Provider-safe:
     * the intent is redacted; sensitive nodes are stored sensitive=true; validation is honest
     * NUMBERS only; no payload is ever written.
     *
     * @param  array<string,mixed>  $envelope
     */
    private function persist(array $envelope, string $rawIntent): void
    {
        if (! $this->storePresent()) {
            return; // fail-open: no store ⇒ return the in-memory plan, never throw.
        }

        $missionId = (string) $envelope['mission_id'];
        $meta = [
            'schema' => self::SCHEMA,
            'node_count' => (int) $envelope['node_count'],
            'domains_spanned' => $envelope['domains_spanned'],
            'decomposer' => (string) ($envelope['decomposer'] ?? ''),
            'honesty' => 'propose-only — no execution; cost-free; requires_operator for every node',
            'ceiling' => AbstractDomainActuator::CEILING,
        ];

        $nodeRows = [];
        foreach ((array) $envelope['nodes'] as $node) {
            $validation = is_array($node['validation'] ?? null) ? $node['validation'] : [];
            $nodeRows[] = [
                'id' => (string) $node['id'],
                'mission_id' => $missionId,
                'seq' => (int) $node['seq'],
                'title' => mb_substr((string) $node['title'], 0, 300),
                'request' => (string) $node['request'],
                'domain' => (string) $node['domain'],
                'sensitive' => (bool) $node['sensitive'],
                'outcome' => (string) $node['outcome'],
                'veto_reason' => isset($node['veto_reason']) ? mb_substr((string) $node['veto_reason'], 0, 200) : null,
                'proposal_ref' => isset($node['proposal_ref']) && $node['proposal_ref'] !== null
                    ? mb_substr((string) $node['proposal_ref'], 0, 240) : null,
                // Honest NUMBERS only — strip any non-scalar (no payload echo can leak here).
                'validation' => json_encode($this->safeValidation($validation), JSON_UNESCAPED_SLASHES) ?: '{}',
                'actuation_gate' => (string) ($node['actuation_gate'] ?? 'requires_operator'),
                'depends_on' => json_encode(array_values((array) ($node['depends_on'] ?? [])), JSON_UNESCAPED_SLASHES) ?: '[]',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::transaction(function () use ($missionId, $envelope, $rawIntent, $meta, $nodeRows): void {
            DB::table('atlas_organism_missions')->updateOrInsert(
                ['id' => $missionId],
                [
                    'intent' => $this->redactIntent($rawIntent),
                    'workspace_id' => (string) $envelope['workspace_id'],
                    'anchor_domain' => (string) $envelope['anchor_domain'],
                    'status' => self::STATUS_COMMISSIONED,
                    'meta' => json_encode($meta, JSON_UNESCAPED_SLASHES) ?: '{}',
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            DB::table('atlas_organism_nodes')->where('mission_id', $missionId)->delete();
            if ($nodeRows !== []) {
                DB::table('atlas_organism_nodes')->insert($nodeRows);
            }
        });
    }

    /**
     * Keep only provider-safe honest verdict fields (metric/value/passed/method/reasons +
     * scalar detail numbers). Never persists the on-machine payload, never win-rate.
     *
     * @param  array<string,mixed>  $validation
     * @return array<string,mixed>
     */
    private function safeValidation(array $validation): array
    {
        $out = [];
        foreach (['metric', 'value', 'passed', 'method'] as $k) {
            if (array_key_exists($k, $validation)) {
                $out[$k] = $validation[$k];
            }
        }
        if (isset($validation['reasons']) && is_array($validation['reasons'])) {
            $out['reasons'] = array_values(array_map('strval', $validation['reasons']));
        }
        if (isset($validation['detail']) && is_array($validation['detail'])) {
            $out['detail'] = array_filter(
                $validation['detail'],
                static fn ($v): bool => is_scalar($v) || $v === null,
            );
        }

        return $out;
    }

    /**
     * The stable mission id: an explicit caller id wins; otherwise derived deterministically
     * from intent + workspace, so re-commissioning the same intent upserts the same mission.
     *
     * @param  array<string,mixed>  $opts
     */
    private function missionId(string $intent, string $workspaceId, array $opts): string
    {
        $explicit = isset($opts['id']) && is_string($opts['id']) ? trim($opts['id']) : '';
        if ($explicit !== '') {
            return 'orgm-'.preg_replace('/[^a-z0-9._-]+/', '-', strtolower($explicit));
        }

        return 'orgm-'.substr(hash('sha256', $intent.'|'.$workspaceId), 0, 12);
    }

    /**
     * Provider-safe, bounded summary of the operator's intent for storage (same conservative
     * scrub the obra plan service uses). The column is a LABEL, never the verbatim raw ask.
     */
    private function redactIntent(string $intent): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $intent) ?? $intent);
        $s = preg_replace('/\b(?:sk|pk|ghp|gho|xox[baprs])[-_][A-Za-z0-9]{12,}\b/', '[redacted]', $s) ?? $s;
        $s = preg_replace('/\b[A-Za-z0-9]{32,}\b/', '[redacted]', $s) ?? $s;
        $s = preg_replace('/\b(?:password|secret|token|api[_-]?key)\s*[:=]\s*\S+/i', '$0=[redacted]', $s) ?? $s;

        return mb_substr($s, 0, 1000);
    }

    /** @return array<mixed> */
    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function storePresent(): bool
    {
        try {
            return Schema::hasTable('atlas_organism_missions') && Schema::hasTable('atlas_organism_nodes');
        } catch (Throwable) {
            return false;
        }
    }
}
