<?php

namespace App\Services\Ai\ToolRuntime;

class ToolSeedDefinitions
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public static function all(): array
    {
        return [
            self::filesystemRead(),
            self::commandLocalReadonly(),
            self::docsSearch(),
            self::githubReadonly(),
            self::browserReadonly(),
            self::apiReadonly(),
            self::artifactWriteLocal(),
            self::testLocalCommand(),
            self::evidenceAttach(),
            self::policyEvaluate(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function filesystemRead(): array
    {
        return [
            'tool_id' => 'filesystem.read',
            'name' => 'Filesystem read',
            'description' => 'Read files and list directories under the operator workspace, read-only.',
            'tool_type' => 'filesystem',
            'authority_group' => 'read_only',
            'risk_level' => 'low',
            'input_schema' => ['type' => 'object', 'required' => ['path']],
            'output_schema' => ['type' => 'object', 'required' => ['content_excerpt']],
            'auth_requirements' => null,
            'cost_profile' => ['unit' => 'fs_read', 'estimated_cost' => 0.0],
            'side_effects' => ['mutates' => false, 'external_network' => false],
            'evidence_emitted' => ['command', 'artifact'],
            'capabilities' => [
                [
                    'capability_id' => 'filesystem.read.file',
                    'name' => 'Read file',
                    'input_schema' => ['type' => 'object', 'required' => ['path']],
                    'output_schema' => ['type' => 'object', 'required' => ['content_excerpt']],
                    'required_policy_gates' => [],
                    'required_evidence' => ['artifact'],
                    'maturity_level' => 2,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function commandLocalReadonly(): array
    {
        return [
            'tool_id' => 'command.local_readonly',
            'name' => 'Local shell read-only commands',
            'description' => 'Run read-only local commands (ls, cat, grep) inside the operator workspace.',
            'tool_type' => 'cli',
            'authority_group' => 'read_only',
            'risk_level' => 'low',
            'input_schema' => ['type' => 'object', 'required' => ['command']],
            'output_schema' => ['type' => 'object', 'required' => ['stdout']],
            'cost_profile' => ['unit' => 'exec_seconds', 'estimated_cost' => 0.0],
            'side_effects' => ['mutates' => false, 'external_network' => false],
            'evidence_emitted' => ['command', 'receipt'],
            'capabilities' => [
                [
                    'capability_id' => 'command.local_readonly.run',
                    'name' => 'Run read-only command',
                    'input_schema' => ['type' => 'object', 'required' => ['command']],
                    'output_schema' => ['type' => 'object', 'required' => ['stdout', 'exit_code']],
                    'required_policy_gates' => [],
                    'required_evidence' => ['command'],
                    'maturity_level' => 2,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function docsSearch(): array
    {
        return [
            'tool_id' => 'docs.search',
            'name' => 'Atlas docs search',
            'description' => 'Search the Atlas engineering knowledge base for relevant docs and snippets.',
            'tool_type' => 'internal',
            'authority_group' => 'read_only',
            'risk_level' => 'low',
            'input_schema' => ['type' => 'object', 'required' => ['query']],
            'output_schema' => ['type' => 'object', 'required' => ['matches']],
            'cost_profile' => ['unit' => 'request', 'estimated_cost' => 0.0],
            'side_effects' => ['mutates' => false, 'external_network' => false],
            'evidence_emitted' => ['doc', 'source'],
            'capabilities' => [
                [
                    'capability_id' => 'docs.search.query',
                    'name' => 'Query docs index',
                    'input_schema' => ['type' => 'object', 'required' => ['query']],
                    'output_schema' => ['type' => 'object', 'required' => ['matches']],
                    'required_policy_gates' => [],
                    'required_evidence' => ['source'],
                    'maturity_level' => 2,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function githubReadonly(): array
    {
        return [
            'tool_id' => 'github.readonly',
            'name' => 'GitHub read-only',
            'description' => 'Read GitHub repos, issues, PRs and metadata. No writes.',
            'tool_type' => 'github',
            'authority_group' => 'read_only',
            'risk_level' => 'low',
            'input_schema' => ['type' => 'object', 'required' => ['endpoint']],
            'output_schema' => ['type' => 'object', 'required' => ['payload']],
            'auth_requirements' => ['kind' => 'github_token', 'scope' => 'read'],
            'cost_profile' => ['unit' => 'api_call', 'estimated_cost' => 0.0],
            'side_effects' => ['mutates' => false, 'external_network' => true],
            'evidence_emitted' => ['source', 'receipt'],
            'capabilities' => [
                [
                    'capability_id' => 'github.readonly.get',
                    'name' => 'Read GitHub resource',
                    'input_schema' => ['type' => 'object', 'required' => ['endpoint']],
                    'output_schema' => ['type' => 'object', 'required' => ['payload']],
                    'required_policy_gates' => ['network_egress'],
                    'required_evidence' => ['source'],
                    'maturity_level' => 2,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function browserReadonly(): array
    {
        return [
            'tool_id' => 'browser.readonly',
            'name' => 'Browser read-only fetch',
            'description' => 'Fetch and parse public URLs (no form posts, no logins, no scripts).',
            'tool_type' => 'browser',
            'authority_group' => 'read_only',
            'risk_level' => 'low',
            'input_schema' => ['type' => 'object', 'required' => ['url']],
            'output_schema' => ['type' => 'object', 'required' => ['title', 'text_excerpt']],
            'cost_profile' => ['unit' => 'http_get', 'estimated_cost' => 0.0],
            'side_effects' => ['mutates' => false, 'external_network' => true],
            'evidence_emitted' => ['source', 'screenshot'],
            'capabilities' => [
                [
                    'capability_id' => 'browser.readonly.fetch',
                    'name' => 'Fetch URL',
                    'input_schema' => ['type' => 'object', 'required' => ['url']],
                    'output_schema' => ['type' => 'object', 'required' => ['title']],
                    'required_policy_gates' => ['network_egress'],
                    'required_evidence' => ['source'],
                    'maturity_level' => 2,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function apiReadonly(): array
    {
        return [
            'tool_id' => 'api.readonly',
            'name' => 'External API read-only',
            'description' => 'Issue HTTP GET against an allow-listed external API.',
            'tool_type' => 'api',
            'authority_group' => 'read_only',
            'risk_level' => 'low',
            'input_schema' => ['type' => 'object', 'required' => ['endpoint']],
            'output_schema' => ['type' => 'object', 'required' => ['payload']],
            'cost_profile' => ['unit' => 'api_call', 'estimated_cost' => 0.0],
            'side_effects' => ['mutates' => false, 'external_network' => true],
            'evidence_emitted' => ['source', 'receipt'],
            'capabilities' => [
                [
                    'capability_id' => 'api.readonly.get',
                    'name' => 'GET API endpoint',
                    'input_schema' => ['type' => 'object', 'required' => ['endpoint']],
                    'output_schema' => ['type' => 'object', 'required' => ['payload']],
                    'required_policy_gates' => ['network_egress'],
                    'required_evidence' => ['source'],
                    'maturity_level' => 2,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function artifactWriteLocal(): array
    {
        return [
            'tool_id' => 'artifact.write_local',
            'name' => 'Write artifact to local workspace',
            'description' => 'Persist a generated artifact (report, JSON, markdown) into the operator workspace.',
            'tool_type' => 'filesystem',
            'authority_group' => 'local_mutation',
            'risk_level' => 'medium',
            'input_schema' => ['type' => 'object', 'required' => ['path', 'content']],
            'output_schema' => ['type' => 'object', 'required' => ['written_path']],
            'cost_profile' => ['unit' => 'fs_write', 'estimated_cost' => 0.0],
            'side_effects' => ['mutates' => true, 'external_network' => false, 'scope' => 'workspace'],
            'evidence_emitted' => ['artifact', 'receipt'],
            'capabilities' => [
                [
                    'capability_id' => 'artifact.write_local.markdown',
                    'name' => 'Write markdown artifact',
                    'input_schema' => ['type' => 'object', 'required' => ['path', 'content']],
                    'output_schema' => ['type' => 'object', 'required' => ['written_path']],
                    'required_policy_gates' => ['workspace_write'],
                    'required_evidence' => ['artifact'],
                    'maturity_level' => 2,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function testLocalCommand(): array
    {
        return [
            'tool_id' => 'test.local_command',
            'name' => 'Local test runner',
            'description' => 'Run local test command (phpunit/pest filter) within operator workspace.',
            'tool_type' => 'cli',
            'authority_group' => 'local_mutation',
            'risk_level' => 'medium',
            'input_schema' => ['type' => 'object', 'required' => ['command']],
            'output_schema' => ['type' => 'object', 'required' => ['exit_code', 'summary']],
            'cost_profile' => ['unit' => 'exec_seconds', 'estimated_cost' => 0.0],
            'side_effects' => ['mutates' => false, 'external_network' => false, 'scope' => 'workspace'],
            'evidence_emitted' => ['test', 'receipt'],
            'capabilities' => [
                [
                    'capability_id' => 'test.local_command.run',
                    'name' => 'Run local tests',
                    'input_schema' => ['type' => 'object', 'required' => ['command']],
                    'output_schema' => ['type' => 'object', 'required' => ['exit_code']],
                    'required_policy_gates' => ['workspace_command'],
                    'required_evidence' => ['test'],
                    'maturity_level' => 2,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function evidenceAttach(): array
    {
        return [
            'tool_id' => 'evidence.attach',
            'name' => 'Evidence attach bridge',
            'description' => 'Attach an evidence ref to a mission/work order via Evidence Runtime.',
            'tool_type' => 'internal',
            'authority_group' => 'draft',
            'risk_level' => 'low',
            'input_schema' => ['type' => 'object', 'required' => ['evidence_type', 'evidence_ref']],
            'output_schema' => ['type' => 'object', 'required' => ['evidence_ref_id']],
            'cost_profile' => ['unit' => 'request', 'estimated_cost' => 0.0],
            'side_effects' => ['mutates' => true, 'external_network' => false, 'scope' => 'evidence_ledger'],
            'evidence_emitted' => ['receipt', 'certification'],
            'capabilities' => [
                [
                    'capability_id' => 'evidence.attach.ref',
                    'name' => 'Attach evidence ref',
                    'input_schema' => ['type' => 'object', 'required' => ['evidence_type', 'evidence_ref']],
                    'output_schema' => ['type' => 'object', 'required' => ['evidence_ref_id']],
                    'required_policy_gates' => [],
                    'required_evidence' => ['receipt'],
                    'maturity_level' => 2,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function policyEvaluate(): array
    {
        return [
            'tool_id' => 'policy.evaluate',
            'name' => 'Policy bridge evaluate',
            'description' => 'Evaluate a request against the Atlas Policy / Permission / Budget / Safety layer.',
            'tool_type' => 'internal',
            'authority_group' => 'read_only',
            'risk_level' => 'low',
            'input_schema' => ['type' => 'object', 'required' => ['requested_action']],
            'output_schema' => ['type' => 'object', 'required' => ['decision']],
            'cost_profile' => ['unit' => 'request', 'estimated_cost' => 0.0],
            'side_effects' => ['mutates' => false, 'external_network' => false],
            'evidence_emitted' => ['receipt'],
            'capabilities' => [
                [
                    'capability_id' => 'policy.evaluate.request',
                    'name' => 'Evaluate policy request',
                    'input_schema' => ['type' => 'object', 'required' => ['requested_action']],
                    'output_schema' => ['type' => 'object', 'required' => ['decision']],
                    'required_policy_gates' => [],
                    'required_evidence' => ['receipt'],
                    'maturity_level' => 2,
                ],
            ],
        ];
    }
}
