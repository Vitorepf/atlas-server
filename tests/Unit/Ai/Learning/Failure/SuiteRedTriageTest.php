<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Learning\Failure;

use App\Services\Ai\Learning\Failure\SuiteRedTriage;
use App\Services\Ai\Learning\Failure\SuiteRedTriageHelper;
use PHPUnit\Framework\TestCase;

final class SuiteRedTriageTest extends TestCase
{
    public function test_missing_path_is_not_supplied(): void
    {
        $out = (new SuiteRedTriage)->triage(null);
        $this->assertSame('not_supplied', $out['status']);
        $this->assertTrue($out['read_only']);
    }

    public function test_helper_subclass_resolves(): void
    {
        $this->assertInstanceOf(SuiteRedTriage::class, new SuiteRedTriageHelper);
    }
}
