<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Support;

use App\Models\AiJob;
use App\Services\Ai\Support\CliInvocationModel;
use PHPUnit\Framework\TestCase;

final class CliInvocationModelTest extends TestCase
{
    public function test_provider_default_identity_suppresses_model(): void
    {
        $job = new AiJob;
        $job->model = 'should-not-use';
        $job->payload = ['model_identity_source' => 'provider_default_identity'];
        $job->metadata = [];

        $this->assertNull(CliInvocationModel::resolve($job, ['model' => 'cfg']));
    }

    public function test_explicit_model_is_returned(): void
    {
        $job = new AiJob;
        $job->model = 'claude-opus';
        $job->payload = [];
        $job->metadata = [];

        $this->assertSame('claude-opus', CliInvocationModel::resolve($job, []));
    }

    public function test_default_suffix_is_suppressed(): void
    {
        $job = new AiJob;
        $job->model = 'foo_default';
        $job->payload = [];
        $job->metadata = [];

        $this->assertNull(CliInvocationModel::resolve($job, []));
    }
}
