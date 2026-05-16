<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class BoundaryEnforcementTest extends TestCase
{
    private const SEED_ROOT = __DIR__.'/../..';

    private const LEAK_FILES = ['InboxLeak1.php', 'InboxLeak2.php', 'InboxLeak3.php'];

    public function test_deptrac_yaml_exists_and_declares_inbox_captures_layers(): void
    {
        $deptrac = self::SEED_ROOT.'/deptrac.yaml';
        $this->assertFileExists($deptrac, 'deptrac.yaml must exist');
        $content = (string) file_get_contents($deptrac);
        $this->assertMatchesRegularExpression('/Inbox/i', $content);
        $this->assertMatchesRegularExpression('/Captures/i', $content);
    }

    public function test_deptrac_forbids_inbox_to_captures(): void
    {
        $content = (string) file_get_contents(self::SEED_ROOT.'/deptrac.yaml');
        // Either negation in ruleset (-Captures) or explicit forbidden block.
        $this->assertTrue(
            (bool) preg_match('/Inbox:\s*\n\s*-\s*-Captures/i', $content)
            || stripos($content, 'forbidden') !== false,
            'deptrac.yaml must explicitly forbid Inbox -> Captures',
        );
    }

    public function test_ingest_port_exists(): void
    {
        $port = self::SEED_ROOT.'/IngestPort.php';
        $this->assertFileExists($port, 'IngestPort.php must exist');
    }

    public function test_no_legacy_leak_imports_capture_repository_directly(): void
    {
        foreach (self::LEAK_FILES as $relative) {
            $path = self::SEED_ROOT.'/'.$relative;
            $this->assertFileExists($path);
            $body = (string) file_get_contents($path);
            $this->assertDoesNotMatchRegularExpression(
                '/use\s+App\\\\Domain\\\\Captures\\\\CaptureRepository;/',
                $body,
                "{$relative} still imports CaptureRepository directly",
            );
            $this->assertMatchesRegularExpression(
                '/use\s+App\\\\Domain\\\\Inbox\\\\Port\\\\IngestPort;/',
                $body,
                "{$relative} must import IngestPort",
            );
        }
    }
}
