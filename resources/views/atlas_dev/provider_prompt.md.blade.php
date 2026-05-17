{{-- Atlas Dev :: ProviderPromptProjection v1 :: deterministic Blade template.
     Never add datetime, random ids, absolute paths or provider-sensitive content here.
     Input is fully trusted (typed DTOs) — output is raw markdown, no HTML escaping. --}}
# Atlas Dev Provider Prompt

Run: {!! $runId !!}
Provider: {!! $provider !!} ({!! $modelFamily !!})

## Atlas Dev Flow
- flow_id: {!! $flow['flow_id'] !!} (workspace-dev)
- flow_origin: {!! $flow['flow_origin'] !!}
- command_intent: {!! $flow['command_intent'] !!}
- workspace_hash: {!! $flow['workspace_hash'] !!}
- scope: Atlas Dev workspace-dev — NOT Router global, NOT Research/Conversation/Explain.

## Provider Lock
- provider: {!! $providerLock['provider'] !!}
- model_family: {!! $providerLock['model_family'] !!}
- fallback_allowed: {!! $providerLock['fallback_allowed'] !!}

## Objective
{!! $sections['objective'] !!}

## Operating Rules
@foreach ($sections['operating_rules'] as $rule)
- {!! $rule !!}
@endforeach

## Non-Goals
@if ($sections['non_goals'] === [])
- (no non-goals declared — read-only or escalation preview)
@else
@foreach ($sections['non_goals'] as $nonGoal)
- {!! $nonGoal !!}
@endforeach
@endif

## References
- mini_spec: {!! $sections['mini_spec_ref'] !!}
- task_contract: {!! $sections['task_contract_ref'] !!}
- code_discovery: {!! $sections['code_discovery_ref'] !!}

## Context Refs
@if ($sections['context_refs'] === [])
- (no context refs provided)
@else
@foreach ($sections['context_refs'] as $ref)
- {!! $ref !!}
@endforeach
@endif

## Focused File Excerpts
@if ($fileExcerpts === [])
- (no focused file excerpts provided; use allowed_files and context refs only)
@else
@foreach ($fileExcerpts as $excerpt)
### {!! $excerpt['path'] !!}
- sha256: {!! $excerpt['sha256'] !!}
- truncated: {!! $excerpt['truncated'] !!}

```text
{!! $excerpt['content'] !!}
```
@endforeach
@endif

## Allowed Files
@if ($sections['allowed_files'] === [])
- (no allowed files — read-only run)
@else
@foreach ($sections['allowed_files'] as $path)
- {!! $path !!}
@endforeach
@endif

## Forbidden Files
@if ($sections['forbidden_files'] === [])
- (none declared)
@else
@foreach ($sections['forbidden_files'] as $path)
- {!! $path !!}
@endforeach
@endif

## Expected Tests
@if ($sections['expected_tests'] === [])
- (no test expected — see no_test_reason in task contract)
@else
@foreach ($sections['expected_tests'] as $test)
- {!! $test !!}
@endforeach
@endif

## Acceptance Criteria
@foreach ($sections['acceptance_criteria'] as $criterion)
- {!! $criterion !!}
@endforeach

## Stop Conditions
@foreach ($sections['stop_conditions'] as $condition)
- {!! $condition !!}
@endforeach

## Escalation Conditions
@foreach ($sections['escalation_conditions'] as $condition)
- {!! $condition !!}
@endforeach

## Output Contract
@foreach ($sections['output_contract'] as $clause)
- {!! $clause !!}
@endforeach

## Upstream Artifacts
@foreach ($upstreamHashes as $name => $hash)
- {!! $name !!}: {!! $hash !!}
@endforeach
