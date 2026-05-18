<?php

namespace App\Services\Ai\Evidence;

use App\Models\AiTestResult;
use Illuminate\Support\Str;

class TestResultService
{
    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_FLAKY = 'flaky';

    public const ALLOWED_STATUSES = [
        self::STATUS_PASSED,
        self::STATUS_FAILED,
        self::STATUS_SKIPPED,
        self::STATUS_BLOCKED,
        self::STATUS_FLAKY,
    ];

    public function __construct(private readonly AuditEventService $auditEvents) {}

    /**
     * @param  array<string,mixed>  $args
     */
    public function record(array $args): AiTestResult
    {
        $scope = (string) ($args['test_scope'] ?? '');
        if ($scope === '') {
            throw new \InvalidArgumentException('test_scope cannot be empty.');
        }
        $status = (string) ($args['status'] ?? '');
        if (! in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new \InvalidArgumentException("invalid test result status [{$status}]");
        }

        $outputRef = $args['output_ref'] ?? null;
        $outputHash = $args['output_hash'] ?? null;
        if ($outputHash === null && is_string($outputRef) && $outputRef !== '') {
            $outputHash = EvidenceCanonicalHash::sha256($outputRef);
        }

        $testResult = AiTestResult::query()->create([
            'uuid' => (string) Str::uuid(),
            'test_scope' => $scope,
            'command' => $args['command'] ?? null,
            'status' => $status,
            'output_ref' => $outputRef,
            'output_hash' => $outputHash,
            'metadata' => $args['metadata'] ?? null,
            'mission_id' => $args['mission_id'] ?? null,
        ]);

        $this->auditEvents->record(
            AuditEventService::EVENT_TEST_RESULT,
            'test_result',
            (string) $testResult->id,
            [
                'test_result_id' => $testResult->id,
                'test_scope' => $scope,
                'status' => $status,
                'output_hash' => $outputHash,
            ],
            missionId: $testResult->mission_id,
        );

        return $testResult;
    }
}
