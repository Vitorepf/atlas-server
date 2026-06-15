<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;

/**
 * Builds the task spec for an EXTRACT-CLASS (ENORMOUS) refactor: move a cohesive cluster of methods
 * off an over-complex class into a NEW class, driving the worst method's cyclomatic down IN PLACE.
 *
 * The contract is a `refactor_*` task (so the framework materializer exempts it from forced
 * diff-earned — a behavior-preserving refactor stays green when reverted) carrying BOTH
 * complexity_proof (so the existing complexity branch fires) AND structural_proof (so the judge +
 * certifier route the verdict to the per-method-identity gate, which supersedes the new-file-lock so
 * the legitimate new class file is provable). allowed_globs covers the target AND the exact new class
 * path; the production target stays editable but no sibling/test/config can be touched. The
 * anti-relocation invariant lives in the structural verdict — a method moved INTACT earns nothing.
 */
final class AtlasLoopExtractClassObjectiveBuilder
{
    public const OBJECTIVE_KIND = 'refactor_extract_class';

    /**
     * @return array{objective:string, payload:array<string,mixed>, acceptance_hash:string}
     */
    public function build(string $targetRel, string $siblingTestRel, string $worstMethod, int $cyclomatic, ?string $provider = null): array
    {
        $targetRel = ltrim(str_replace('\\', '/', $targetRel), '/');
        $siblingTestRel = ltrim(str_replace('\\', '/', $siblingTestRel), '/');
        $newClassRel = $this->newClassPath($targetRel);
        $newClassName = $this->classNameFromPath($newClassRel);

        $command = './vendor/bin/phpunit '.escapeshellarg($siblingTestRel);
        $acceptance = [
            'commands' => [$command],
            // Provider edits the TARGET and CREATES the new class; the sibling test + config are FROZEN.
            'allowed_globs' => [$targetRel, $newClassRel],
            'frozen_globs' => [$siblingTestRel, 'tests/**', 'phpunit.xml', 'phpunit.xml.dist', 'composer.json'],
            'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_MINIMIZE,
            'complexity_proof' => true,   // fires the existing complexity branch in judge + certifier
            'structural_proof' => true,   // swaps the verdict to the per-method-identity (anti-relocation) gate
            'revert_recheck' => false,    // behavior-preserving: stays green when reverted (proof is the AST drop)
            'timeout_seconds' => max(60, (int) config('atlas.loop.framework_refactor_timeout_seconds', 300)),
        ];

        $payload = [
            'materializer' => 'framework',
            'objective_kind' => self::OBJECTIVE_KIND,
            'target_relative_path' => $targetRel,
            'target_repo_path' => $targetRel,
            'acceptance' => $acceptance,
            'allowed_files' => [$targetRel, $newClassRel],
            'validation_commands' => [$command],
            'extract_class_new_path' => $newClassRel,
        ];
        if ($provider !== null && $provider !== '') {
            $payload['provider'] = $provider;
        }

        $acceptanceHash = hash('sha256', json_encode([
            'objective_kind' => self::OBJECTIVE_KIND,
            'commands' => $acceptance['commands'],
            'allowed_globs' => $acceptance['allowed_globs'],
            'structural_proof' => true,
            'target' => $targetRel,
        ], JSON_THROW_ON_ERROR));

        return [
            'objective' => $this->objectiveText($targetRel, $newClassRel, $newClassName, $this->namespaceFromPath($newClassRel), $worstMethod, $cyclomatic),
            'payload' => $payload,
            'acceptance_hash' => $acceptanceHash,
        ];
    }

    /**
     * The PSR-4 namespace for a repo-relative path under app/ (composer maps App\ => app/). Deterministic
     * — `app/Services/Ai/X/Foo.php` => `App\Services\Ai\X`. Passing the EXACT namespace to the provider
     * removes the #1 extract-class failure: a new class whose namespace does not match its PSR-4 path
     * fails to autoload -> fatal -> the sibling test goes RED -> the cert (correctly) rejects it.
     */
    public function namespaceFromPath(string $rel): string
    {
        $dir = trim(str_replace('\\', '/', dirname(ltrim($rel, '/'))), '/');
        if ($dir === '' || $dir === '.' || ! str_starts_with($dir.'/', 'app/')) {
            return 'App';
        }
        $segments = array_slice(explode('/', $dir), 1); // drop the leading "app"

        return 'App'.($segments === [] ? '' : '\\'.implode('\\', array_map('ucfirst', $segments)));
    }

    /**
     * The new class file path — same directory as the target, name = <Target>Support.php — so PSR-4
     * resolves it under the target's namespace. Must be passed to the provider VERBATIM so its diff
     * lands inside allowed_globs.
     */
    public function newClassPath(string $targetRel): string
    {
        $dir = trim(str_replace('\\', '/', dirname($targetRel)), '/');
        $base = basename($targetRel, '.php');

        return ($dir === '' || $dir === '.') ? $base.'Support.php' : $dir.'/'.$base.'Support.php';
    }

    private function classNameFromPath(string $rel): string
    {
        return basename($rel, '.php');
    }

    private function objectiveText(string $targetRel, string $newClassRel, string $newClassName, string $newClassNamespace, string $worstMethod, int $cyclomatic): string
    {
        return "Refactor {$targetRel} by EXTRACTING a cohesive cluster of its logic into a NEW class, to "
            ."drive the worst method {$worstMethod} (cyclomatic {$cyclomatic}) strictly SIMPLER IN PLACE.\n\n"
            ."⚠️ STEP 1 — CREATE THE NEW FILE FIRST (the #1 reason this task fails: the target is edited "
            ."to delegate to a class that was NEVER created, so the test dies with "
            ."\"Class \\\"{$newClassNamespace}\\\\{$newClassName}\\\" not found\"). You MUST create a brand-new file "
            ."at EXACTLY this path: {$newClassRel}\n"
            ."Write it with EXACTLY this skeleton, then fill in the moved methods:\n"
            ."    <?php\n\n    declare(strict_types=1);\n\n    namespace {$newClassNamespace};\n\n    final class {$newClassName}\n    {\n        // moved public methods go here\n    }\n"
            ."The namespace MUST be exactly {$newClassNamespace} so PSR-4 autoloads it — a wrong namespace or a "
            ."missing file makes it unloadable and the test goes RED.\n\n"
            ."STEP 2 — MOVE LOGIC: pick a cohesive group of private helpers/branches currently in {$targetRel} "
            ."(ideally the logic that bloats {$worstMethod}) and MOVE them verbatim into {$newClassName} as public methods.\n"
            ."STEP 3 — DELEGATE: in {$targetRel}, instantiate {$newClassName} and replace the moved bodies with "
            ."simple calls to it. Keep the public API of {$targetRel} identical.\n\n"
            ."BEFORE YOU FINISH, VERIFY: the file {$newClassRel} EXISTS, declares `namespace {$newClassNamespace};` "
            ."and `final class {$newClassName}`, and every symbol you delegate to is defined there. If the target "
            ."references {$newClassName} but that file does not exist, your change is WRONG.\n\n"
            ."HARD RULES: Edit ONLY {$targetRel} and CREATE ONLY {$newClassRel}. Do NOT touch any test, phpunit "
            ."config, or composer.json. Do NOT add new branches/conditionals/loops/&&/|| — only RELOCATE existing "
            ."ones; the total decision count must stay flat or fall. PRESERVE behavior EXACTLY: pass the "
            ."same arguments through to the extracted methods and return their results unchanged. A method "
            ."merely MOVED intact (same complexity, just re-homed) is REJECTED — {$worstMethod} itself must "
            ."get smaller, and each extracted helper must be simpler than the original cyclomatic {$cyclomatic}.\n\n"
            ."Your change is correct ONLY when the new file exists AND the frozen sibling test for {$targetRel} stays GREEN.";
    }
}
