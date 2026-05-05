<?php

namespace Tests\Unit\Ai\Kernel\Failure;

use App\Services\Ai\Kernel\Failure\FailureClassifier;
use App\Services\Ai\Kernel\Failure\FailureDomain;
use App\Services\Ai\Kernel\Failure\FailureHandler;
use App\Services\Ai\Kernel\Failure\FailureHandlerRegistry;
use RuntimeException;
use Tests\TestCase;

class FailureClassifierTest extends TestCase
{
    public function test_classifier_rule_catalog_covers_every_non_unknown_failure_domain(): void
    {
        $domainsWithRules = FailureClassifier::ruleDomains();

        foreach (FailureDomain::cases() as $domain) {
            if ($domain === FailureDomain::Unknown) {
                continue;
            }

            $this->assertContains($domain->value, $domainsWithRules, "{$domain->name} must have at least one classifier rule.");
        }
    }

    public function test_classifier_compliance_report_is_green_and_auditable(): void
    {
        $classifier = app(FailureClassifier::class);
        $report = $classifier->complianceReport();
        $catalog = FailureClassifier::ruleCatalog();
        $statusCodeMap = FailureClassifier::statusCodeMap();

        $this->assertTrue($report['ok'], implode("\n", [
            ...$report['missing_domains'],
            ...$report['duplicate_signals'],
        ]));
        $this->assertSame([], $report['missing_domains']);
        $this->assertSame([], $report['duplicate_signals']);
        $this->assertSame(count($catalog), $report['rule_count']);
        $this->assertSame(count($statusCodeMap), $report['status_code_count']);
        $this->assertSame(FailureDomain::ProviderTimeout->value, $statusCodeMap[504]);
        $this->assertSame(FailureDomain::PolicyDenied->value, $statusCodeMap[403]);

        foreach ($catalog as $rule) {
            $this->assertArrayHasKey('domain', $rule);
            $this->assertArrayHasKey('domain_name', $rule);
            $this->assertArrayHasKey('signal', $rule);
            $this->assertArrayHasKey('needles', $rule);
            $this->assertNotEmpty($rule['needles']);
        }
    }

    public function test_classifier_maps_required_failure_domains(): void
    {
        $classifier = app(FailureClassifier::class);

        $cases = [
            'provider request timeout after 30s' => FailureDomain::ProviderTimeout,
            'rate limit exceeded by provider' => FailureDomain::ProviderUnavailable,
            'provider unavailable: service unavailable' => FailureDomain::ProviderUnavailable,
            'auth permission denied by policy' => FailureDomain::PolicyDenied,
            'tool permission denied by workspace policy' => FailureDomain::ToolPolicyDenied,
            'quality gate failed during release check' => FailureDomain::GateFailed,
            'missing evidence for final answer' => FailureDomain::EvidenceMissing,
            'privacy redaction leak detected in provider payload' => FailureDomain::PrivacyViolation,
            'security finding: high vulnerability detected' => FailureDomain::SecurityFinding,
            'compliance violation in generated output' => FailureDomain::ComplianceViolation,
            'tool execution failed while running command' => FailureDomain::ToolExecutionFailed,
            'runtime failed while rendering output' => FailureDomain::RuntimeFailed,
            'engineering harness failed on visual smoke' => FailureDomain::HarnessFailed,
        ];

        foreach ($cases as $message => $domain) {
            $classification = $classifier->classifyStatus('failed', $message);

            $this->assertSame($domain, $classification->domain, $message);
            $this->assertSame($domain->value, $classification->toArray()['failure_domain']);
            $this->assertSame($domain->name, $classification->toArray()['failure_domain_name']);
            $this->assertNotEmpty($classification->signals);
        }
    }

    public function test_classifier_maps_all_kernel_failure_domains_from_canonical_phrases(): void
    {
        $classifier = app(FailureClassifier::class);
        $cases = [
            'input malformed payload' => FailureDomain::InputMalformed,
            'input attachment unavailable' => FailureDomain::InputAttachmentUnavailable,
            'intent ambiguous for operation' => FailureDomain::IntentAmbiguous,
            'domain unsupported by router' => FailureDomain::DomainUnsupported,
            'domain profile missing' => FailureDomain::ProfileMissing,
            'policy denied by operator policy' => FailureDomain::PolicyDenied,
            'budget exceeded for token budget' => FailureDomain::BudgetExceeded,
            'decision expired before execution' => FailureDomain::DecisionExpired,
            'decision invalid signature' => FailureDomain::DecisionInvalid,
            'provider unavailable during request' => FailureDomain::ProviderUnavailable,
            'provider refused content' => FailureDomain::ProviderRefused,
            'provider timeout waiting for response' => FailureDomain::ProviderTimeout,
            'context pack failed while building prompt' => FailureDomain::ContextPackFailed,
            'memory unavailable during recall' => FailureDomain::MemoryUnavailable,
            'tool unavailable in workspace' => FailureDomain::ToolUnavailable,
            'tool policy denied workspace write' => FailureDomain::ToolPolicyDenied,
            'tool execution failed process' => FailureDomain::ToolExecutionFailed,
            'runtime unsupported for flow' => FailureDomain::RuntimeUnsupported,
            'runtime failed during execution' => FailureDomain::RuntimeFailed,
            'harness failed visual smoke' => FailureDomain::HarnessFailed,
            'gate failed release check' => FailureDomain::GateFailed,
            'evidence missing from answer' => FailureDomain::EvidenceMissing,
            'repair exhausted after attempts' => FailureDomain::RepairExhausted,
            'output invalid schema' => FailureDomain::OutputInvalid,
            'privacy violation provider safe leak' => FailureDomain::PrivacyViolation,
            'security finding secret detected' => FailureDomain::SecurityFinding,
            'compliance violation policy compliance failed' => FailureDomain::ComplianceViolation,
            'ledger unavailable for write' => FailureDomain::LedgerUnavailable,
            'replay mismatch detected' => FailureDomain::ReplayMismatch,
            'surface contract violation in adapter' => FailureDomain::SurfaceContractViolation,
        ];

        foreach ($cases as $message => $domain) {
            $classification = $classifier->classifyStatus('failed', $message);

            $this->assertSame($domain, $classification->domain, $message);
            $this->assertFalse($classification->isUnknown(), $message);
        }
    }

    public function test_classifier_maps_decision_receipt_runtime_error_codes(): void
    {
        $classifier = app(FailureClassifier::class);

        $expired = $classifier->classify([
            'error_code' => 'decision_receipt_expired',
            'message' => 'DecisionReceipt expirado antes da execucao do provider.',
        ]);
        $dryRun = $classifier->classify([
            'error_code' => 'decision_receipt_dry_run',
            'message' => 'DecisionReceipt de preview/dry-run nao pode ser consumido pelo Data Plane.',
        ]);
        $invalid = $classifier->classify([
            'error_code' => 'decision_receipt_invalid',
            'message' => 'DecisionReceipt invalido: schema_version ausente.',
        ]);
        $providerMismatch = $classifier->classify([
            'error_code' => 'decision_receipt_provider_mismatch',
            'message' => 'DecisionReceipt provider mismatch before runtime.',
        ]);
        $modelMismatch = $classifier->classify([
            'error_code' => 'decision_receipt_model_mismatch',
            'message' => 'DecisionReceipt model mismatch before runtime.',
        ]);

        $this->assertSame(FailureDomain::DecisionExpired, $expired->domain);
        $this->assertSame(FailureDomain::DecisionInvalid, $dryRun->domain);
        $this->assertSame(FailureDomain::DecisionInvalid, $invalid->domain);
        $this->assertSame(FailureDomain::DecisionInvalid, $providerMismatch->domain);
        $this->assertSame(FailureDomain::DecisionInvalid, $modelMismatch->domain);
    }

    public function test_classifier_accepts_throwable_payload_and_status_message_inputs(): void
    {
        $classifier = app(FailureClassifier::class);

        $throwable = $classifier->classify(new RuntimeException('Request timed out while waiting for provider.'));
        $payload = $classifier->classify([
            'error' => [
                'type' => 'tool_call_failed',
                'message' => 'Tool failed with process failed exit code.',
            ],
        ]);
        $status = $classifier->classify('blocked', 'Gate blocked because evidence required.');

        $this->assertSame(FailureDomain::ProviderTimeout, $throwable->domain);
        $this->assertSame('throwable', $throwable->source);
        $this->assertSame(RuntimeException::class, data_get($throwable->metadata, 'throwable_class'));

        $this->assertSame(FailureDomain::ToolExecutionFailed, $payload->domain);
        $this->assertSame('payload', $payload->source);
        $this->assertContains('error', data_get($payload->metadata, 'keys'));

        $this->assertSame(FailureDomain::EvidenceMissing, $status->domain);
        $this->assertSame('status_message', $status->source);
    }

    public function test_throwable_status_code_classifies_when_message_is_generic(): void
    {
        $classification = app(FailureClassifier::class)->classify(new RuntimeException('Gateway failed.', 504));

        $this->assertSame(FailureDomain::ProviderTimeout, $classification->domain);
        $this->assertSame('throwable', $classification->source);
        $this->assertSame(['http_status_code', '504'], $classification->signals);
        $this->assertSame(504, data_get($classification->metadata, 'status_code'));
    }

    public function test_throwable_previous_chain_contributes_to_classification_metadata(): void
    {
        $previous = new RuntimeException('Tool policy denied by workspace write guard.');
        $throwable = new RuntimeException('Worker failed.', 0, $previous);

        $classification = app(FailureClassifier::class)->classify($throwable);

        $this->assertSame(FailureDomain::ToolPolicyDenied, $classification->domain);
        $this->assertSame(RuntimeException::class, data_get($classification->metadata, 'throwable_class'));
        $this->assertCount(2, data_get($classification->metadata, 'chain'));
        $this->assertSame(RuntimeException::class, data_get($classification->metadata, 'chain.1.class'));
    }

    public function test_explicit_payload_failure_domain_is_preserved(): void
    {
        $classification = app(FailureClassifier::class)->classify([
            'failure_domain' => FailureDomain::ComplianceViolation->value,
            'message' => 'Some ambiguous downstream text.',
        ]);

        $this->assertSame(FailureDomain::ComplianceViolation, $classification->domain);
        $this->assertSame(['explicit_failure_domain'], $classification->signals);
    }

    public function test_explicit_payload_failure_domain_accepts_enum_name_and_aliases(): void
    {
        $classifier = app(FailureClassifier::class);

        $this->assertSame(FailureDomain::ProviderTimeout, $classifier->classify([
            'failure_domain' => 'ProviderTimeout',
        ])->domain);
        $this->assertSame(FailureDomain::ProviderTimeout, $classifier->classify([
            'failure_domain' => 'provider_timeout',
        ])->domain);
        $this->assertSame(FailureDomain::ProviderTimeout, $classifier->classify([
            'failure_domain' => 'provider.timeout',
        ])->domain);
    }

    public function test_nested_explicit_payload_failure_domain_is_preserved(): void
    {
        $classification = app(FailureClassifier::class)->classify([
            'error' => [
                'failureDomain' => 'ToolExecutionFailed',
                'message' => 'Generic failure text.',
            ],
        ]);

        $this->assertSame(FailureDomain::ToolExecutionFailed, $classification->domain);
        $this->assertSame(['explicit_failure_domain'], $classification->signals);
        $this->assertSame('error.failureDomain', data_get($classification->metadata, 'explicit_domain_path'));
    }

    public function test_payload_status_codes_classify_when_message_is_not_useful(): void
    {
        $classifier = app(FailureClassifier::class);

        $cases = [
            408 => FailureDomain::ProviderTimeout,
            504 => FailureDomain::ProviderTimeout,
            429 => FailureDomain::ProviderUnavailable,
            503 => FailureDomain::ProviderUnavailable,
            401 => FailureDomain::PolicyDenied,
            403 => FailureDomain::PolicyDenied,
        ];

        foreach ($cases as $statusCode => $domain) {
            $classification = $classifier->classify([
                'status_code' => $statusCode,
                'message' => 'Downstream failed.',
            ]);

            $this->assertSame($domain, $classification->domain, (string) $statusCode);
            $this->assertSame(['http_status_code', (string) $statusCode], $classification->signals);
            $this->assertSame(0.85, $classification->confidence);
            $this->assertSame($statusCode, data_get($classification->metadata, 'status_code'));
        }
    }

    public function test_nested_payload_status_code_classifies_when_message_is_not_useful(): void
    {
        $classification = app(FailureClassifier::class)->classify([
            'response' => [
                'status' => '503',
            ],
            'error' => [
                'message' => 'Downstream failed.',
            ],
        ]);

        $this->assertSame(FailureDomain::ProviderUnavailable, $classification->domain);
        $this->assertSame(['http_status_code', '503'], $classification->signals);
        $this->assertSame(503, data_get($classification->metadata, 'status_code'));
    }

    public function test_status_code_string_classifies_without_message(): void
    {
        $classification = app(FailureClassifier::class)->classify('504');

        $this->assertSame(FailureDomain::ProviderTimeout, $classification->domain);
        $this->assertSame('status_message', $classification->source);
        $this->assertSame(['http_status_code', '504'], $classification->signals);
    }

    public function test_textual_payload_signals_take_precedence_over_generic_status_code(): void
    {
        $classification = app(FailureClassifier::class)->classify([
            'status_code' => 403,
            'message' => 'Tool policy denied for workspace write.',
        ]);

        $this->assertSame(FailureDomain::ToolPolicyDenied, $classification->domain);
        $this->assertSame('tool_policy_denied', $classification->signals[0]);
    }

    public function test_unknown_classification_is_stable(): void
    {
        $classifier = app(FailureClassifier::class);

        $first = $classifier->classifyStatus('failed', 'Something unexpected happened.');
        $second = $classifier->classify(['message' => 'Something unexpected happened.']);

        $this->assertSame(FailureDomain::Unknown, $first->domain);
        $this->assertSame(FailureDomain::Unknown, $second->domain);
        $this->assertSame(['unclassified'], $first->signals);
        $this->assertSame(0.0, $first->confidence);
        $this->assertTrue($first->isUnknown());
    }

    public function test_classification_exposes_handler_ready_failure_payload(): void
    {
        $classification = app(FailureClassifier::class)->classifyStatus('failed', 'runtime failed during execution');
        $payload = $classification->toFailurePayload();

        $this->assertSame(FailureDomain::RuntimeFailed->value, $payload['failure_domain']);
        $this->assertSame('status_message', $payload['source']);
        $this->assertSame($classification->signals, $payload['signals']);
        $this->assertSame($classification->confidence, $payload['confidence']);
        $this->assertArrayHasKey('metadata', $payload);
    }

    public function test_registry_has_handler_for_every_failure_domain(): void
    {
        $registry = app(FailureHandlerRegistry::class);
        $report = $registry->complianceReport();

        $this->assertTrue($report['ok'], implode("\n", $report['missing']));
        $this->assertSame(count(FailureDomain::cases()), $report['count']);

        foreach (FailureDomain::cases() as $domain) {
            $handler = $registry->handlerFor($domain);

            $this->assertInstanceOf(FailureHandler::class, $handler);
            $this->assertSame($domain, $handler->domain());
        }
    }
}
