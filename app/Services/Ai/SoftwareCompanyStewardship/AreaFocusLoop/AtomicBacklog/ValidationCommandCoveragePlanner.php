<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

final class ValidationCommandCoveragePlanner
{
    private const SCHEMA_VERSION = 'atlas.validation.command_coverage_plan.v1';

    /**
     * Maps a source-file token (lower-cased extension) to the toolchain tool
     * that validates it and the command that tool runs. The token is also the
     * value pushed into missing_toolchain when that tool is unavailable.
     *
     * @var array<string,array{tool:string,command:string}>
     */
    private const EXTENSION_TOOLS = [
        'php' => ['tool' => 'phpstan', 'command' => 'phpstan analyse'],
        'ts' => ['tool' => 'tsc', 'command' => 'tsc --noEmit'],
        'tsx' => ['tool' => 'tsc', 'command' => 'tsc --noEmit'],
        'js' => ['tool' => 'eslint', 'command' => 'eslint .'],
        'jsx' => ['tool' => 'eslint', 'command' => 'eslint .'],
        'py' => ['tool' => 'pytest', 'command' => 'pytest'],
        'go' => ['tool' => 'go_vet', 'command' => 'go vet ./...'],
        'rs' => ['tool' => 'cargo_check', 'command' => 'cargo check'],
    ];

    private const SECRET_TOOL = 'secret_scan';

    private const SECRET_COMMAND = 'secret-scan --staged';

    /**
     * Computes the validation commands required to cover the changed files,
     * the toolchain tokens that are missing for those files, and an overall
     * coverage status. Pure: no filesystem, process or gate calls.
     *
     * @param  array<int,mixed>  $changedFiles
     * @param  array<int,mixed>  $declaredCommands
     * @param  array<string,mixed>  $toolchain
     * @return array<string,mixed>
     */
    public function plan(array $changedFiles, array $declaredCommands, array $toolchain): array
    {
        $declared = $this->normaliseCommands($declaredCommands);

        $extensions = $this->extensionsOf($changedFiles);
        $sourceFileCount = $this->sourceFileCount($changedFiles);

        $requiredCommands = [];
        $coveredExtensions = [];
        $missingToolchain = [];

        foreach ($extensions as $extension) {
            $rule = self::EXTENSION_TOOLS[$extension] ?? null;

            if ($rule === null) {
                continue;
            }

            if ($this->toolAvailable($toolchain, $rule['tool'])) {
                $this->pushUnique($requiredCommands, $rule['command']);
                $this->pushUnique($coveredExtensions, $extension);
            } else {
                $this->pushUnique($missingToolchain, $extension);
            }
        }

        if ($sourceFileCount > 0 && $this->toolAvailable($toolchain, self::SECRET_TOOL)) {
            $this->pushUnique($requiredCommands, self::SECRET_COMMAND);
        }

        $commands = $this->mergeWithoutDuplicates($declared, $requiredCommands);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'commands' => $commands,
            'missing_toolchain' => $missingToolchain,
            'covered_extensions' => $coveredExtensions,
            'coverage_status' => $this->coverageStatus($sourceFileCount, $requiredCommands, $missingToolchain),
        ];
    }

    /**
     * Ordered, de-duplicated list of source-file extensions in the change set.
     *
     * @param  array<int,mixed>  $changedFiles
     * @return array<int,string>
     */
    private function extensionsOf(array $changedFiles): array
    {
        $extensions = [];

        foreach ($changedFiles as $file) {
            $extension = $this->extensionOf($file);

            if ($extension === null) {
                continue;
            }

            $this->pushUnique($extensions, $extension);
        }

        return $extensions;
    }

    private function extensionOf(mixed $file): ?string
    {
        if (! is_string($file) || $file === '') {
            return null;
        }

        $name = basename($file);
        $position = strrpos($name, '.');

        if ($position === false || $position === 0 || $position === strlen($name) - 1) {
            return null;
        }

        return strtolower(substr($name, $position + 1));
    }

    /**
     * Count of changed entries that are usable source paths (have an extension).
     *
     * @param  array<int,mixed>  $changedFiles
     */
    private function sourceFileCount(array $changedFiles): int
    {
        $count = 0;

        foreach ($changedFiles as $file) {
            if ($this->extensionOf($file) !== null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string,mixed>  $toolchain
     */
    private function toolAvailable(array $toolchain, string $tool): bool
    {
        return ($toolchain[$tool] ?? false) === true;
    }

    private function coverageStatus(int $sourceFileCount, array $requiredCommands, array $missingToolchain): string
    {
        if ($sourceFileCount === 0) {
            return 'full';
        }

        if ($requiredCommands === []) {
            return 'blocked';
        }

        if ($missingToolchain !== []) {
            return 'partial';
        }

        return 'full';
    }

    /**
     * @param  array<int,mixed>  $declaredCommands
     * @return array<int,string>
     */
    private function normaliseCommands(array $declaredCommands): array
    {
        $normalised = [];

        foreach ($declaredCommands as $command) {
            if (! is_string($command) || $command === '') {
                continue;
            }

            $this->pushUnique($normalised, $command);
        }

        return $normalised;
    }

    /**
     * @param  array<int,string>  $declared
     * @param  array<int,string>  $required
     * @return array<int,string>
     */
    private function mergeWithoutDuplicates(array $declared, array $required): array
    {
        $merged = $declared;

        foreach ($required as $command) {
            $this->pushUnique($merged, $command);
        }

        return $merged;
    }

    /**
     * @param  array<int,string>  $list
     */
    private function pushUnique(array &$list, string $value): void
    {
        if (! in_array($value, $list, true)) {
            $list[] = $value;
        }
    }
}
