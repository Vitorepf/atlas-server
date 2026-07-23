<?php

namespace App\Services\Ai\Programming\Sdd\Mcp;


/**
 * Read-only MCP resource catalog for the SDD pipeline.
 *
 * Per agents-and-mcp-contract.md:150-162: MCP exposes specs, requirements,
 * evidence, traceability, decision-receipts as read-only resources, with
 * secrets redacted. The actual transport (HTTP REST and/or JSON-RPC) is
 * served by AtlasSddController.
 *
 * Hard law (agents-and-mcp-contract.md:205): "MCP is a surface, not authority."
 * Resources are read-only; mutations always go through Decision Receipts.
 */
class SddResourceCatalog
{
    private const RESOURCES = [
        [
            'uri' => 'mcp:atlas/operations',
            'name' => 'Operations',
            'description' => 'Routed SDD operations (envelopes that entered the pipeline).',
            'http_path' => '/atlas-code/sdd/operations',
            'read_only' => true,
            'redacts_secrets' => true,
        ],
        [
            'uri' => 'mcp:atlas/specs',
            'name' => 'Specs',
            'description' => 'Compiled, critiqued and approved SDD specs.',
            'http_path' => '/atlas-code/sdd/specs',
            'detail_path' => '/atlas-code/sdd/specs/{id}',
            'read_only' => true,
            'redacts_secrets' => true,
        ],
        [
            'uri' => 'mcp:atlas/requirements',
            'name' => 'Requirements',
            'description' => 'Spec requirements and acceptance criteria (returned inside spec detail).',
            'http_path' => '/atlas-code/sdd/specs/{id}',
            'read_only' => true,
            'redacts_secrets' => true,
        ],
        [
            'uri' => 'mcp:atlas/traceability',
            'name' => 'Spec Traceability',
            'description' => 'Trace links spec → requirement → AC → task → file → test → evidence.',
            'http_path' => '/atlas-code/sdd/specs/{id}/traceability',
            'read_only' => true,
            'redacts_secrets' => true,
        ],
        [
            'uri' => 'mcp:atlas/decision-receipts',
            'name' => 'Decision Receipts',
            'description' => 'Signed authorization receipts (autonomy level, allowed/forbidden, gates).',
            'http_path' => '/atlas-code/sdd/decision-receipts',
            'detail_path' => '/atlas-code/sdd/decision-receipts/{receipt_id}',
            'read_only' => true,
            'redacts_secrets' => true,
        ],
        [
            'uri' => 'mcp:atlas/evidence',
            'name' => 'Evidence Ledger',
            'description' => 'Append-only evidence events linked to receipts and traceability.',
            'http_path' => '/atlas-code/programming/work-items/{code}',
            'read_only' => true,
            'redacts_secrets' => true,
        ],
        [
            'uri' => 'mcp:atlas/drift-reports',
            'name' => 'Drift Reports',
            'description' => 'Spec drift reports per `atlas.sdd_drift.v1`.',
            'http_path' => '/atlas-code/sdd/drift-reports',
            'read_only' => true,
            'redacts_secrets' => true,
        ],
        [
            'uri' => 'mcp:atlas/learning-proposals',
            'name' => 'Learning Proposals',
            'description' => 'Proposal-only learning records awaiting human review.',
            'http_path' => '/atlas-code/sdd/learning-proposals',
            'read_only' => true,
            'redacts_secrets' => true,
        ],
    ];

    /**
     * @return list<array<string,mixed>>
     */
    public function resources(): array
    {
        return self::RESOURCES;
    }

    /**
     * @return array<string,mixed>
     */
    public function manifest(): array
    {
        return [
            'schema_version' => 'atlas.sdd_mcp_resource_catalog.v1',
            'authority' => 'mcp_is_a_surface_not_authority',
            'mutation_policy' => 'mutations_only_via_decision_receipt',
            'secret_policy' => 'never_emit_api_keys_tokens_or_credentials',
            'resources' => self::RESOURCES,
            'governed_prompts' => [
                'mcp:generate_spec',
                'mcp:critique_spec',
                'mcp:create_plan',
                'mcp:create_tasks',
                'mcp:review_security',
                'mcp:validate_drift',
            ],
        ];
    }
}
