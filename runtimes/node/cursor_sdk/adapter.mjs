#!/usr/bin/env node
/**
 * Atlas Cursor SDK adapter.
 *
 * This process translates an already-authorized Atlas manifest into a single
 * @cursor/sdk agent run. Atlas remains the authority for model, scope, policy,
 * evidence and completion claims.
 */

import { createHash } from 'node:crypto';
import { existsSync, statSync } from 'node:fs';
import { readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const started = Date.now();

function sha256(value) {
  return createHash('sha256').update(String(value)).digest('hex');
}

function jsonHash(value) {
  return sha256(JSON.stringify(value ?? null));
}

function stringList(value) {
  if (!Array.isArray(value)) return [];
  return value.filter((item) => typeof item === 'string').map((item) => item.trim()).filter(Boolean);
}

function plainObject(value) {
  return value && typeof value === 'object' && !Array.isArray(value) ? value : undefined;
}

function emit(payload, exitCode = 0) {
  process.stdout.write(JSON.stringify(payload));
  process.exit(exitCode);
}

function durationMs() {
  return Date.now() - started;
}

function performanceSignal(manifest, status, blockers, changedFiles) {
  const metadata = typeof manifest.metadata === 'object' && manifest.metadata !== null ? manifest.metadata : {};
  return {
    schema_version: 'atlas.provider.cursor_sdk.performance_signal.v1',
    provider: 'cursor_sdk',
    model_observed: manifest.model ?? null,
    domain: metadata.domain ?? 'programming',
    flow: metadata.flow ?? 'programming.forge',
    task_type: metadata.task_type ?? null,
    status,
    duration_ms: durationMs(),
    changed_files_count: changedFiles.length,
    required_gates_passed: false,
    completion_claim_promoted: false,
    routing_effect: 'none',
    advisory_only: true,
    blockers,
  };
}

function blocked(manifest, blockers, note, providerCalled = false) {
  emit({
    schema_version: 'atlas.provider.cursor_sdk.invocation_result.v1',
    provider: 'cursor_sdk',
    model_observed: manifest.model ?? null,
    runtime_mode: manifest.cursor?.runtime_mode ?? 'local',
    billing_mode: manifest.billing?.billing_mode ?? process.env.ATLAS_CURSOR_SDK_BILLING_MODE ?? 'cursor_account_usage_bucket',
    quota_bucket: manifest.billing?.quota_bucket ?? process.env.ATLAS_CURSOR_SDK_QUOTA_BUCKET ?? 'cursor_account_default',
    provider_called: providerCalled,
    external_provider_call: providerCalled,
    provider_tokens_spent: providerCalled ? 'unknown' : false,
    artifacts: [],
    changed_files: [],
    tool_events: [],
    blockers,
    failure_type: blockers[0] ?? null,
    duration_ms: durationMs(),
    performance_signal: performanceSignal(manifest, 'failed', blockers, []),
    note,
  }, 2);
}

function workspacePath(manifest) {
  const raw = manifest.workspace?.path ?? manifest.workspace_path ?? manifest.cwd;
  if (typeof raw !== 'string' || raw.trim() === '') return null;
  return path.resolve(raw);
}

function normalizeRel(rel) {
  return String(rel).replaceAll('\\', '/').replace(/^\.\/+/, '');
}

function resolveInWorkspace(workspace, rel) {
  const root = path.resolve(workspace);
  const absolute = path.resolve(root, rel);
  if (absolute !== root && !absolute.startsWith(root + path.sep)) {
    throw new Error('path_outside_workspace:' + rel);
  }
  return absolute;
}

async function fileDigestIfPresent(workspace, rel) {
  const absolute = resolveInWorkspace(workspace, rel);
  if (!existsSync(absolute)) return null;
  const stat = statSync(absolute);
  if (!stat.isFile()) return { kind: 'not_file', sha256: null };
  const data = await readFile(absolute);
  return { kind: 'file', sha256: createHash('sha256').update(data).digest('hex'), size: data.length };
}

async function snapshotFiles(workspace, files) {
  const out = {};
  for (const rel of files) {
    out[normalizeRel(rel)] = await fileDigestIfPresent(workspace, rel);
  }
  return out;
}

function changedFromSnapshots(before, after) {
  const changed = [];
  const keys = new Set([...Object.keys(before), ...Object.keys(after)]);
  for (const key of keys) {
    if (JSON.stringify(before[key] ?? null) !== JSON.stringify(after[key] ?? null)) {
      changed.push(key);
    }
  }
  return changed.sort();
}

function gitStatus(workspace) {
  const result = spawnSync('git', ['status', '--porcelain=v1', '--untracked-files=all'], {
    cwd: workspace,
    encoding: 'utf8',
    timeout: 5000,
    maxBuffer: 1024 * 1024,
  });
  if (result.status !== 0 || typeof result.stdout !== 'string') return [];
  return result.stdout
    .split('\n')
    .map((line) => line.trimEnd())
    .filter(Boolean)
    .map((line) => normalizeRel(line.slice(3).replace(/^"|"$/g, '')))
    .filter(Boolean)
    .sort();
}

function allowedMatch(rel, allowed) {
  const normalized = normalizeRel(rel);
  return allowed.some((item) => {
    const allowedRel = normalizeRel(item).replace(/\/+$/, '');
    return normalized === allowedRel || normalized.startsWith(allowedRel + '/');
  });
}

function eventSummary(message) {
  const type = message?.type ?? 'unknown';
  const base = { type };
  if (message?.name) base.name = String(message.name);
  if (message?.status) base.status = String(message.status);
  if (message?.model?.id) base.model = String(message.model.id);
  if (message?.message?.content) {
    const text = JSON.stringify(message.message.content);
    base.content_hash = sha256(text);
    base.content_chars = text.length;
  }
  if (message?.args !== undefined) {
    base.args_hash = jsonHash(message.args);
  }
  if (message?.result !== undefined) {
    base.result_hash = jsonHash(message.result);
  }
  return base;
}

async function run() {
  if (process.argv.length !== 3) {
    emit({ schema_version: 'atlas.provider.cursor_sdk.invocation_result.v1', blockers: ['manifest_path_required'] }, 2);
  }

  const manifest = JSON.parse(await readFile(process.argv[2], 'utf8'));
  const scope = typeof manifest.scope_contract === 'object' && manifest.scope_contract !== null ? manifest.scope_contract : {};
  const allowed = stringList(scope.allowed_files);
  const forbidden = stringList(scope.forbidden_files);
  const blockers = [];
  const workspace = workspacePath(manifest);
  const modelId = typeof manifest.model === 'string' ? manifest.model.trim() : '';

  if (!manifest.decision_receipt_id || !manifest.decision_receipt_hash) blockers.push('decision_receipt_required');
  if (!workspace) blockers.push('cursor_sdk_workspace_required');
  if (!modelId) blockers.push('cursor_sdk_model_required');
  if (allowed.length === 0) blockers.push('cursor_sdk_allowed_files_required');
  if (blockers.length > 0) {
    blocked(manifest, blockers, 'Atlas manifest failed Cursor SDK adapter preflight.');
  }

  const runtimeMode = manifest.cursor?.runtime_mode ?? 'local';
  const sandboxEnabled = manifest.cursor?.sandbox_enabled !== false;
  const settingSources = stringList(manifest.cursor?.setting_sources).length > 0
    ? stringList(manifest.cursor.setting_sources)
    : ['project'];

  const moduleName = process.env.ATLAS_CURSOR_SDK_MODULE || '@cursor/sdk';
  let sdk;
  try {
    sdk = await import(moduleName);
  } catch {
    blocked(manifest, ['cursor_sdk_module_missing'], `Could not import ${moduleName}.`);
  }

  const { Agent } = sdk;
  if (!Agent || typeof Agent.create !== 'function') {
    blocked(manifest, ['cursor_sdk_entrypoint_missing'], 'Cursor SDK Agent.create entrypoint missing.');
  }

  const apiKey = process.env.CURSOR_API_KEY || manifest.cursor?.api_key;
  if (typeof apiKey !== 'string' || apiKey.trim() === '') {
    blocked(manifest, ['cursor_sdk_auth_required'], 'CURSOR_API_KEY is required for governed Cursor SDK runs.');
  }

  const beforeAllowed = await snapshotFiles(workspace, allowed);
  const beforeForbidden = await snapshotFiles(workspace, forbidden);
  const beforeGit = gitStatus(workspace);
  const toolEvents = [];
  const streamEvents = [];
  let providerCalled = false;
  let agent = null;

  const task = {
    atlas_contract: {
      decision_receipt_id: manifest.decision_receipt_id,
      decision_receipt_hash: manifest.decision_receipt_hash,
      dispatch_id: manifest.dispatch_id ?? null,
      completion_claim_promoted: false,
      completion_claim_allowed: false,
      provider_authority: 'atlas_decide',
    },
    system_instructions: [
      'You are an executor inside Atlas, not an authority.',
      'Atlas Decide already selected this provider/model; do not change provider policy.',
      'Never write memory, mutate policy, bypass gates, or claim completion.',
      'Operate only inside allowed files and respect forbidden files/actions.',
      'Return concise implementation output with changed files, blockers, tests, and evidence.',
    ],
    prompt: manifest.prompt ?? {},
    scope_contract: scope,
    completion_criteria: manifest.completion_criteria ?? [],
  };

  try {
    const options = {
      name: `Atlas cursor_sdk ${manifest.dispatch_id ?? Date.now()}`,
      apiKey,
      model: { id: modelId },
      mcpServers: plainObject(manifest.cursor?.mcp_servers),
      agents: plainObject(manifest.cursor?.subagents),
    };

    if (runtimeMode === 'cloud') {
      options.cloud = typeof manifest.cursor?.cloud === 'object' && manifest.cursor.cloud !== null ? manifest.cursor.cloud : {};
    } else {
      options.local = {
        cwd: workspace,
        settingSources,
        sandboxOptions: { enabled: sandboxEnabled },
      };
    }

    agent = await Agent.create(options);
    providerCalled = true;

    const runHandle = await agent.send(JSON.stringify(task), {
      onDelta: ({ update }) => {
        if (toolEvents.length < 80) toolEvents.push(eventSummary(update));
      },
    });

    if (typeof runHandle?.stream === 'function' && (!runHandle.supports || runHandle.supports('stream'))) {
      try {
        for await (const message of runHandle.stream()) {
          if (streamEvents.length < 80) streamEvents.push(eventSummary(message));
        }
      } catch {
        // Some SDK runtimes only support wait/conversation. wait() remains authoritative.
      }
    }

    const result = typeof runHandle?.wait === 'function'
      ? await runHandle.wait()
      : { status: 'finished', result: String(runHandle ?? '') };

    const afterAllowed = await snapshotFiles(workspace, allowed);
    const afterForbidden = await snapshotFiles(workspace, forbidden);
    const afterGit = gitStatus(workspace);
    const allowedChanged = changedFromSnapshots(beforeAllowed, afterAllowed);
    const forbiddenChanged = changedFromSnapshots(beforeForbidden, afterForbidden);
    const gitChanged = afterGit.filter((rel) => !beforeGit.includes(rel));
    const scopeViolations = gitChanged.filter((rel) => !allowedMatch(rel, allowed));
    const finalBlockers = [];
    if (forbiddenChanged.length > 0) finalBlockers.push('cursor_sdk_forbidden_file_changed');
    if (scopeViolations.length > 0) finalBlockers.push('cursor_sdk_scope_violation');

    const output = String(result?.result ?? '');
    const changedFiles = Array.from(new Set([...allowedChanged, ...gitChanged.filter((rel) => allowedMatch(rel, allowed))])).sort();
    const artifacts = [
      {
        kind: 'text',
        name: 'cursor_sdk_result',
        sha256: sha256(output),
        chars: output.length,
      },
    ];
    const payload = {
      schema_version: 'atlas.provider.cursor_sdk.invocation_result.v1',
      provider: 'cursor_sdk',
      model_observed: result?.model?.id ?? runHandle?.model?.id ?? modelId,
      runtime_mode: runtimeMode,
      billing_mode: manifest.billing?.billing_mode ?? process.env.ATLAS_CURSOR_SDK_BILLING_MODE ?? 'cursor_account_usage_bucket',
      quota_bucket: manifest.billing?.quota_bucket ?? process.env.ATLAS_CURSOR_SDK_QUOTA_BUCKET ?? 'cursor_account_default',
      provider_called: true,
      external_provider_call: true,
      provider_tokens_spent: 'unknown',
      run_id: result?.id ?? runHandle?.id ?? null,
      run_status: result?.status ?? runHandle?.status ?? null,
      duration_ms: durationMs(),
      output_hash: sha256(output),
      output_excerpt: output.slice(0, 4000),
      artifacts,
      changed_files: changedFiles,
      tool_events: [...toolEvents, ...streamEvents].slice(0, 120),
      blockers: finalBlockers,
      failure_type: finalBlockers[0] ?? null,
      performance_signal: performanceSignal(manifest, finalBlockers.length === 0 ? 'succeeded' : 'failed', finalBlockers, changedFiles),
      note: finalBlockers.length === 0
        ? 'Cursor SDK Agent completed under Atlas adapter.'
        : 'Cursor SDK Agent returned but Atlas scope verification blocked promotion.',
    };

    emit(payload, finalBlockers.length === 0 ? 0 : 2);
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    const failure = message.includes('busy') ? 'cursor_sdk_agent_busy' : 'cursor_sdk_runtime_error';
    blocked(manifest, [failure], message, providerCalled);
  } finally {
    if (agent && typeof agent.close === 'function') {
      try {
        agent.close();
      } catch {
        // best effort cleanup
      }
    }
  }
}

run().catch(async (error) => {
  const message = error instanceof Error ? error.stack ?? error.message : String(error);
  await writeFile('/tmp/atlas-cursor-sdk-adapter-last-error.log', message).catch(() => {});
  emit({
    schema_version: 'atlas.provider.cursor_sdk.invocation_result.v1',
    provider: 'cursor_sdk',
    provider_called: false,
    external_provider_call: false,
    provider_tokens_spent: false,
    blockers: ['cursor_sdk_adapter_uncaught_error'],
    failure_type: 'cursor_sdk_adapter_uncaught_error',
    stderr_hash: sha256(message),
    duration_ms: durationMs(),
    performance_signal: performanceSignal({}, 'failed', ['cursor_sdk_adapter_uncaught_error'], []),
    note: 'Cursor SDK adapter crashed before completing.',
  }, 2);
});
