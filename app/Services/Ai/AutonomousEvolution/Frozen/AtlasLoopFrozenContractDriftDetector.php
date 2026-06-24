<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Frozen;

final class AtlasLoopFrozenContractDriftDetector
{
    public const BEGIN_MARKER = '// FROZEN-CONTRACT:BEGIN';

    public const END_MARKER = '// FROZEN-CONTRACT:END';

    public function __construct(
        private readonly ?string $basePath = null,
    ) {}

    /**
     * @return list<array{fqcn:string,test_path:string,expected_sha:string,actual_sha:string,drift:bool}>
     */
    public function detect(AtlasLoopFrozenContractRegistry $registry): array
    {
        $facts = [];

        foreach ($this->contracts($registry) as $fqcn => $contract) {
            $expectedSha = (string) $contract['assertions_sha256'];
            $testPath = (string) $contract['test_path'];
            $assertionsBlock = $this->assertionsBlock($this->resolveTestPath($testPath));
            $actualSha = hash('sha256', $assertionsBlock ?? '');

            $facts[] = [
                'fqcn' => (string) $fqcn,
                'test_path' => $this->normalizePath($testPath),
                'expected_sha' => $expectedSha,
                'actual_sha' => $actualSha,
                'drift' => $assertionsBlock === null || $actualSha !== $expectedSha,
            ];
        }

        usort($facts, static fn (array $a, array $b): int => $a['fqcn'] <=> $b['fqcn']);

        return $facts;
    }

    /**
     * @return array<string, array{assertions_sha256:string,registered_at:string,test_path:string}>
     */
    private function contracts(AtlasLoopFrozenContractRegistry $registry): array
    {
        /** @var array<string, array{assertions_sha256:string,registered_at:string,test_path:string}> $contracts */
        $contracts = json_decode($registry->toJson(), true, flags: JSON_THROW_ON_ERROR);
        ksort($contracts);

        return $contracts;
    }

    private function assertionsBlock(string $path): ?string
    {
        $contents = @file_get_contents($path);
        if (! is_string($contents)) {
            return null;
        }

        $contents = str_replace(["\r\n", "\r"], "\n", $contents);
        $beginOffset = strpos($contents, self::BEGIN_MARKER);
        if ($beginOffset === false) {
            return null;
        }

        $blockStart = $beginOffset + strlen(self::BEGIN_MARKER);
        $endOffset = strpos($contents, self::END_MARKER, $blockStart);
        if ($endOffset === false) {
            return null;
        }

        return substr($contents, $blockStart, $endOffset - $blockStart);
    }

    private function resolveTestPath(string $testPath): string
    {
        $rawPath = trim(str_replace('\\', '/', $testPath));
        $rawPath = preg_replace('#/+#', '/', $rawPath) ?? '';
        if (str_starts_with($rawPath, '/')) {
            return $rawPath;
        }

        $testPath = $this->normalizePath($rawPath);

        return rtrim($this->basePath ?? base_path(), '/').'/'.$testPath;
    }

    private function normalizePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        $path = preg_replace('#/+#', '/', $path) ?? '';

        return ltrim($path, './');
    }
}
