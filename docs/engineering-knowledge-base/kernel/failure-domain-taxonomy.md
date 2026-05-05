---
id: atlas-ai-failure-domain-taxonomy
type: engineering_knowledge
title: Atlas AI Failure Domain Taxonomy
status: active
category: architecture
priority: 96
summary: Canonical taxonomy for classifying Atlas AI operational failures with FailureDomain, FailureClassifier, FailureClassification, and FailureHandlerRegistry before runtime recovery or learning loops.
tags:
  - atlas-ai
  - kernel
  - failure-domain
  - failure-handler
  - taxonomy
capabilities:
  - failure_domain_taxonomy
  - failure_classifier
  - failure_handler_registry
decisions:
  - FailureDomain is a closed kernel enum with an unknown fallback.
  - FailureClassifier is side-effect free and classifies Throwable, payload array, or status/message inputs.
  - FailureHandlerRegistry must keep a handler for every FailureDomain, including unknown.
  - Runtime integrations should persist classification receipts before retry, repair, escalation, or learning.
maintenance:
  - Update this document when FailureDomain enum cases, classifier rules, or handler coverage expectations change.
  - Keep this document aligned with atlas-ai-kernel-architecture.md section 16.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
---

# Failure Domain Taxonomy

## Objective

The Failure Domain taxonomy gives Atlas AI a pure, executable way to classify errors before any recovery, retry, escalation, or learning loop is wired into real runtime flows. The classifier is intentionally side-effect free: it accepts a `Throwable`, an error payload array, or a status/message pair and returns a `FailureClassification` value object.

This stage does not plug classification into `AiGateway`, `AiWorker`, provider drivers, commands, harness execution, or real runtimes.

## Domains

The classifier maps common operational failures into the closed `FailureDomain` enum. Every non-`unknown` enum case has at least one canonical textual rule, and the most common operational domains include:

- `provider.timeout`: provider call exceeded a deadline or timed out.
- `provider.unavailable`: provider outage, overload, rate limit, or temporary unavailability.
- `policy.denied`: auth, permission, or policy denial outside a specific tool policy.
- `decision.expired`: Decision Receipt TTL expired before the Data Plane could execute.
- `decision.invalid`: Decision Receipt schema, signature, hash, preview/dry-run state, provider mismatch, or model mismatch cannot authorize execution.
- `tool.policy_denied`: tool permission or workspace/tool policy denial.
- `gate.failed`: quality, safety, or execution gate failure.
- `evidence.missing`: required evidence, source, or substantiation is absent.
- `privacy.violation`: privacy, provider-safe, redaction, PII, or secret leak.
- `security.finding`: security finding, vulnerability, malware, or injection issue.
- `compliance.violation`: compliance, regulatory, terms, license, or policy compliance violation.
- `tool.execution_failed`: tool call, command, or process execution failure.
- `runtime.failed`: runtime execution failure.
- `harness.failed`: engineering harness failure.
- `unknown`: stable fallback for anything not classified.

`FailureHandlerRegistry` must continue to provide a handler for every `FailureDomain`, including `unknown`.

## Classification Inputs

`FailureClassifier` accepts three input shapes:

- `Throwable`: class name, message, and code are classified as a throwable source.
- `array`: explicit `failure_domain`/`domain` is preserved when it matches the enum value, enum name, or a normalized alias such as `provider_timeout`; otherwise payload keys and scalar values are flattened for matching.
- `string status/message`: status and message are classified together as a simple boundary receipt.

Payloads can also classify from top-level status code fields such as `status_code`, `statusCode`, `http_status`, `httpStatus`, or `code`, plus common nested shapes under `response.*` and `error.*`. Explicit domains can also be nested under `error.*`, `failure.*`, or `classification.*`. Textual signals take precedence over generic HTTP code fallback, so a `403` with `tool policy denied` still classifies as `tool.policy_denied`, not generic `policy.denied`.

Classifier rule coverage is intentionally tested against every non-`unknown` enum case. When a new `FailureDomain` is added, the classifier should gain at least one canonical phrase and a fixture that proves it is reachable.

Throwable classification also includes the previous-exception chain, capped for safety, so wrapper exceptions can still classify from the more specific cause. Generic throwables with HTTP-like codes such as `504` can fall back to the same status-code mapping used for payloads.

`FailureClassifier::complianceReport()` exposes an internal self-audit for tests and future boot-time checks. It verifies that every non-`unknown` domain has at least one rule and that classifier signals are unique. `ruleCatalog()` and `statusCodeMap()` provide read-only views for documentation, diagnostics, and future integration receipts.

## Future Integration

Future integration points should call `FailureClassifier` at boundaries where failures are first observed:

- `AiGateway`: classify provider exceptions, provider refusal payloads, timeouts, rate limits, and unavailable responses before gateway retry or fallback logic.
- `AiWorker`: classify job/runtime failures before recording terminal job state, proposing repair, or sending review events. Decision receipt runtime blocks such as `decision_receipt_expired`, `decision_receipt_invalid`, `decision_receipt_dry_run`, `decision_receipt_provider_mismatch`, and `decision_receipt_model_mismatch` must map to `decision.expired` or `decision.invalid`, never `unknown`.
- `Engineering Harness`: classify harness run failures, gate failures, missing evidence, security findings, and tool execution failures before scorecard/retry decisions.

Each integration should persist the classification receipt with the existing evidence or telemetry path, then pass the `FailureDomain` to `FailureHandlerRegistry` for policy-neutral handling.

## Unknown Rule

Unclassified errors must become `FailureDomain::Unknown`. `Unknown` is stable and safe, but it is not the end of the story: repeated unknowns should create future improvement work to add a more precise classifier rule, test fixture, or new domain if the closed taxonomy is missing a real operational class.
