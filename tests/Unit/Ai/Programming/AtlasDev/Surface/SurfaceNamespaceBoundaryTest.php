<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Surface;

use App\Services\Ai\Programming\AtlasDev\Surface\DesktopUiHintsBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Boundary test — Desktop-specific UI shape may live only in
 * `AtlasDev/Surface/`. Core namespaces (Schemas, Discovery, Pipeline, Gate,
 * Provider, Repair, Escalation, PromptProjection, Persistence, Telemetry)
 * can reference canonical surface_id enum values (per contracts doc 4.1) for
 * tier and risk routing, BUT they must never carry Desktop UI vocabulary
 * (panel names, hint structure, tab ids).
 *
 * This is enforced statically by greping the source tree. It runs fast and
 * catches regressions on CI before they reach the live system.
 */
final class SurfaceNamespaceBoundaryTest extends TestCase
{
    private const FORBIDDEN_SUBDIRS = [
        'Discovery',
        'Escalation',
        'Gate',
        'Persistence',
        'Pipeline',
        'PromptProjection',
        'Provider',
        'Repair',
        'Schemas',
        'Telemetry',
    ];

    /**
     * Desktop-specific UI vocabulary that must never appear in core. These
     * are the contract names of the Desktop cockpit panels, hints structure
     * and inline indicators owned by {@see DesktopUiHintsBuilder}.
     */
    private const DESKTOP_UI_VOCABULARY = [
        'panel_contexto',
        'panel_plano',
        'ui_hints',
        'inline_indicators',
        'execution_placeholders',
    ];

    public function test_core_namespaces_do_not_carry_desktop_ui_vocabulary(): void
    {
        $root = $this->coreRoot();
        $offending = [];

        foreach (self::FORBIDDEN_SUBDIRS as $subdir) {
            $dir = $root.'/'.$subdir;
            if (! is_dir($dir)) {
                continue;
            }
            foreach ($this->phpFilesIn($dir) as $file) {
                $contents = (string) file_get_contents($file);
                foreach (self::DESKTOP_UI_VOCABULARY as $needle) {
                    if (str_contains($contents, $needle)) {
                        $offending[] = $file.': mentions Desktop UI vocabulary `'.$needle.'`';
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offending,
            "Core namespaces leaked Desktop UI vocabulary:\n - ".implode("\n - ", $offending),
        );
    }

    /**
     * @return list<string>
     */
    private function phpFilesIn(string $dir): array
    {
        $files = [];
        $stack = [$dir];
        while ($stack !== []) {
            $cur = array_pop($stack);
            foreach (scandir($cur) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $cur.'/'.$entry;
                if (is_dir($path)) {
                    $stack[] = $path;

                    continue;
                }
                if (str_ends_with($entry, '.php')) {
                    $files[] = $path;
                }
            }
        }

        return $files;
    }

    private function coreRoot(): string
    {
        return dirname(__DIR__, 6).'/app/Services/Ai/Programming/AtlasDev';
    }
}
