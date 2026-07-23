<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlow;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusProviderNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;

/**
 * AP-786 owner-flow INTENT / command-building section: owner + repair + review intents
 * and the allowlisted atlas_dev / minimax-worker argv commands. Bodies moved verbatim
 * from Ap786OwnerFlowExecutor (GOD-DEBULK surgical split).
 */
final class Ap786OwnerFlowIntentSection
{
    /**
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $validationCommands
     */
    public function buildOwnerIntent(array $finding, array $allowedFiles, array $validationCommands, string $worktree): string
    {
        $title = trim((string) ($finding['title'] ?? ''));
        $detail = trim((string) ($finding['detail'] ?? $finding['why_it_matters'] ?? ''));
        $nextAction = trim((string) ($finding['proposed_next_action'] ?? ''));
        $tests = $this->testsRequiredForHandoff($finding, $allowedFiles);
        $acceptance = $this->acceptanceForHandoff($finding);
        $scopeFiles = $allowedFiles !== [] ? $allowedFiles : AreaFocusStringListNormalizer::trimmedStrings($finding['affected_files'] ?? []);
        $primaryTest = $this->primaryTestPath($tests, $validationCommands);
        $patchMandate = $this->patchMandate($primaryTest, $scopeFiles, $worktree);

        $segments = array_filter([
            'Implement the smallest correct scoped repair now inside allowed_files only.',
            'PATCH_MANDATE: '.$patchMandate,
            $tests !== [] ? 'TESTS_REQUIRED: '.implode(', ', $tests) : null,
            $title !== '' ? 'OBJECTIVE: '.$title : null,
            $detail !== '' ? 'WHY: '.$detail : null,
            $nextAction !== '' ? 'NEXT: '.$nextAction : null,
            $scopeFiles !== [] ? 'ALLOWED_FILES: '.implode(', ', $scopeFiles) : null,
            $acceptance !== [] ? 'ACCEPTANCE: '.implode(' | ', array_slice($acceptance, 0, 3)) : null,
            'Must edit an allowed file or cite exact proof (file:line plus passing focused test output).',
            'no_patch_needed is invalid unless the focused test already proves this exact improvement.',
        ], static fn (?string $line): bool => is_string($line) && trim($line) !== '');

        $intent = $this->sanitizeIntentForExecutableRouting(implode(' ', $segments));

        return $intent === '' ? 'Implement the smallest correct fix inside the allowed files only.' : mb_substr($intent, 0, 2400);
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @return list<string>
     */
    public function testsRequiredForHandoff(array $finding, array $allowedFiles): array
    {
        $tests = AreaFocusStringListNormalizer::trimmedStrings(data_get($finding, 'spec_seed.tests_required', []));
        foreach ($allowedFiles as $file) {
            if (str_starts_with($file, 'tests/') || str_ends_with($file, 'Test.php')) {
                $tests[] = $file;
            }
        }

        return AreaFocusStringListNormalizer::uniqueStringValues($tests);
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return list<string>
     */
    public function acceptanceForHandoff(array $finding): array
    {
        $acceptance = AreaFocusStringListNormalizer::trimmedStrings(data_get($finding, 'spec_seed.acceptance', []));
        if ($acceptance !== []) {
            return $acceptance;
        }

        $title = trim((string) ($finding['title'] ?? ''));

        return $title !== '' ? ['Given the selected finding, '.$title.' is implemented and proven by the focused test.'] : [];
    }

    /**
     * @param  list<string>  $tests
     * @param  list<string>  $validationCommands
     */
    public function primaryTestPath(array $tests, array $validationCommands): string
    {
        foreach ($tests as $test) {
            if (str_starts_with($test, 'tests/') && str_ends_with($test, '.php')) {
                return $test;
            }
        }

        foreach ($validationCommands as $command) {
            if (preg_match('/php artisan test\s+(\S+\.php)/', $command, $matches) === 1) {
                return (string) $matches[1];
            }
        }

        return '';
    }

    /**
     * @param  list<string>  $scopeFiles
     */
    public function patchMandate(string $primaryTest, array $scopeFiles, string $worktree): string
    {
        if ($primaryTest !== '' && ! $this->testFileExists($primaryTest, $worktree)) {
            return 'CREATE focused test '.$primaryTest.' with a failing assertion that proves the gap, then implement the minimal runtime fix in '.($scopeFiles !== [] ? implode(', ', $scopeFiles) : 'allowed_files').'.';
        }
        if ($primaryTest !== '') {
            return 'HARDEN '.$primaryTest.' with a specific assertion that fails before the fix and passes after the minimal change in '.($scopeFiles !== [] ? implode(', ', $scopeFiles) : 'allowed_files').'.';
        }

        return 'Apply a minimal code change in '.($scopeFiles !== [] ? implode(', ', $scopeFiles) : 'allowed_files').' and prove it with the declared validation_command.';
    }

    public function testFileExists(string $relativePath, string $worktree): bool
    {
        $candidates = [];
        if ($worktree !== '') {
            $candidates[] = rtrim($worktree, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($relativePath, '/');
        }
        if (function_exists('base_path')) {
            $candidates[] = base_path($relativePath);
        }

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return true;
            }
        }

        return false;
    }

    public function sanitizeIntentForExecutableRouting(string $intent): string
    {
        $intent = (string) preg_replace('/[;&|<>`$\r\n]+/', ' ', $intent);
        $intent = trim((string) preg_replace('/\s+/', ' ', $intent));
        $replacements = [
            '/\bforge promotion preview\b/i' => 'factory runtime preview',
            '/\bmulti-?agent\b/i' => 'governed workcell',
            '/\batlas dev\s*\/\s*forge flow\b/i' => 'AAEOS software-development flow',
            '/\bdev\s*\/\s*forge flow\b/i' => 'software-development flow',
            '/\batlas dev and forge flow\b/i' => 'AAEOS software-development flow',
            '/\bdev and forge flow\b/i' => 'software-development flow',
            '/\bforge obra\b/i' => 'factory obra',
            '/\bobra de\b/i' => 'factory work packet',
            '/\bwhole system\b/i' => 'scoped factory module',
            '/\bentire codebase\b/i' => 'scoped codebase slice',
            '/\bprovider[-_\s]+auth[-_\s]+mode\b/i' => 'provider readiness mode',
            '/\bprovider[-_\s]+auth(?:entication|orization)?\b/i' => 'provider readiness',
            '/\batlas forge\b/i' => 'atlas factory runtime',
            '/\bforge runtime\b/i' => 'factory runtime',
            '/\bforge\b/i' => 'factory',
            '/\bcouncil\b/i' => 'review group',
            '/authorization:\s*bearer\s+[A-Za-z0-9._-]*/i' => 'authorization redacted',
            '/\bbearer\s+ey[A-Za-z0-9._-]*/i' => 'bearer token redacted',
            '/\bsk-ant-[A-Za-z0-9._-]*/i' => 'provider token redacted',
            '/\b[A-Z0-9_]*API[_ -]?KEY[A-Z0-9_]*\b/i' => 'provider token name redacted',
            '/\bAWS_SECRET_ACCESS_KEY\b/i' => 'provider token name redacted',
            '/\bpassword\s*=\s*[^\s,;]+/i' => 'password redacted',
            '/\bsecret\s*=\s*[^\s,;]+/i' => 'secret redacted',
            '/\bprivate_key\b/i' => 'private key label redacted',
            '/(^|[\s,;:])\.env($|[\s,;:.])/i' => '$1environment configuration file$2',
        ];
        foreach ($replacements as $pattern => $replacement) {
            $intent = (string) preg_replace($pattern, $replacement, $intent);
        }

        return trim($intent);
    }

    public function providerSafeRepairIntent(string $intent): string
    {
        return mb_substr($this->sanitizeIntentForExecutableRouting($intent), 0, 2400);
    }

    public function artisanPath(string $worktree = ''): string
    {
        $worktreeArtisan = $worktree !== ''
            ? rtrim($worktree, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'artisan'
            : '';
        if ($worktreeArtisan !== '' && is_file($worktreeArtisan)) {
            return $worktreeArtisan;
        }

        return function_exists('base_path') ? base_path('artisan') : 'artisan';
    }

    /**
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $validationCommands
     * @return list<string>
     */
    public function atlasDevCommand(string $worktree, string $intent, array $allowedFiles, array $validationCommands, string $provider, string $model, int $providerTimeout = 600): array
    {
        $command = [
            PHP_BINARY,
            $this->artisanPath($worktree),
            'atlas:dev:senior-loop:run',
            '--workspace='.$worktree,
            '--intent='.$intent,
            '--surface-id=atlas_cli_dev',
            '--flow-origin=atlas_ai_router',
            '--operator-explicit',
            '--provider-choice='.$provider,
            '--composer-model='.$model,
            '--provider-timeout-seconds='.max(60, min(1200, $providerTimeout)),
            '--json',
        ];

        foreach ($allowedFiles as $file) {
            $file = $this->safeCliValue($file);
            if ($file !== '') {
                $command[] = '--allowed-file='.$file;
            }
        }

        foreach ($validationCommands as $validationCommand) {
            $validationCommand = $this->safeCliValue($this->worktreeValidationCommand($validationCommand));
            if ($validationCommand !== '') {
                $command[] = '--validation-command='.$validationCommand;
            }
        }

        return $command;
    }

    public function atlasMinimaxWorkerCommand(string $worktree, array $finding, array $allowedFiles, array $validationCommands): array
    {
        // Aligns with the real atlas:dev:minimax-worker:run signature: it has NO --json
        // flag (it always emits JSON), takes ONE comma-separated --allowed-files, and ONE
        // JSON-array --validation-commands. The command is executed as an argv array via
        // Symfony Process (no shell), so JSON payloads are passed RAW — running them through
        // safeCliValue truncated the finding JSON at 240 chars (invalid_finding_json) and
        // split allowed-files/validation-commands into per-item flags the worker ignored.
        $command = [
            PHP_BINARY,
            $this->artisanPath($worktree),
            'atlas:dev:minimax-worker:run',
            '--repo-root='.$worktree,
            '--worktree='.$worktree,
            // Explicitly arm the bounded repair loop. A single MiniMax syntax/validation
            // error must get repair attempts before the cycle is failed — never a
            // blocked-without-repair (which wastes the provider spend already made).
            '--max-repairs=2',
        ];

        $findingJson = $finding !== [] ? json_encode($finding, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
        if ($findingJson !== '' && $findingJson !== false) {
            $command[] = '--finding-json='.$findingJson;
        }

        $files = AreaFocusStringListNormalizer::trimmedStrings($allowedFiles);
        if ($files !== []) {
            $command[] = '--allowed-files='.implode(',', $files);
        }

        $worktreeValidation = AreaFocusStringListNormalizer::trimmedStrings(
            array_map(fn (string $c): string => $this->worktreeValidationCommand($c), $validationCommands),
        );
        if ($worktreeValidation !== []) {
            $validationJson = json_encode($worktreeValidation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($validationJson !== false) {
                $command[] = '--validation-commands='.$validationJson;
            }
        }

        return $command;
    }

    public function worktreeValidationCommand(string $command): string
    {
        $command = trim($command);
        if (preg_match('/^php artisan test\s+(\S+\.php)$/', $command, $matches) === 1) {
            return './vendor/bin/phpunit --configuration=phpunit.xml '.(string) $matches[1];
        }

        return $command;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public function providerChoice(array $input): string
    {
        return AreaFocusProviderNormalizer::providerId(
            (string) ($input['provider'] ?? $input['provider_choice'] ?? 'cursor_cli'),
            'cursor_cli',
        );
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public function modelFamily(array $input, string $provider): string
    {
        $model = trim((string) ($input['model'] ?? $input['model_family'] ?? ''));
        if ($model !== '') {
            return $model;
        }

        if ($provider === 'minimax_m27_cli') {
            return $model !== '' ? $model : 'MiniMax-M3';
        }

        if ($provider === 'codex_cli') {
            $configured = function_exists('config') ? config('atlas.ai.providers.codex_cli.model') : null;

            return is_string($configured) && trim($configured) !== ''
                ? trim($configured)
                : 'gpt-5.3-codex-spark';
        }

        return $provider === 'cursor_cli' ? 'composer-2.5-fast' : 'sonnet';
    }

    public function safeCliValue(string $value): string
    {
        $value = trim((string) preg_replace('/[;&|<>`$\r\n]+/', ' ', $value));
        $value = (string) preg_replace('/\s+/', ' ', $value);

        return mb_substr($value, 0, 240);
    }
}
