<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Intelligence;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\FocusedTestCommand;
use App\Services\Ai\Programming\AtlasDev\Schemas\PatchIntelligenceReceipt;
use App\Services\Ai\Programming\AtlasDev\Schemas\TestSelectionReceipt;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;

/**
 * Produces a TestSelectionReceipt: explicit, focused test commands tied to
 * each changed file with a reason and a confidence bucket.
 *
 * Selection rules:
 *   1. If a changed file is itself a test (`tests/...`), schedule it
 *      directly — confidence high.
 *   2. For each production file, look for `tests/Unit/<basename>Test.php`
 *      and `tests/Feature/<basename>Test.php`. Surface only those that
 *      exist — confidence high.
 *   3. For each production file with no matching test, fall through to a
 *      reasoned skip note.
 *   4. When risk_level is high/critical and at least one production file
 *      was changed, also include the module-suite command(s) with
 *      confidence medium so high-risk patches always have a wider safety
 *      net even when convention tests exist.
 *
 * The service never returns a "run the whole suite" command unsupervised —
 * the operator can still do that, but Atlas Dev's receipt records the
 * focused selection separately, which is the point of the intelligence.
 */
final class TestSelectionIntelligenceService
{
    public function select(TestSelectionInput $input): TestSelectionReceipt
    {
        $changed = AtlasDevStringListNormalizer::uniqueTrimmedStrings($input->changedFiles);
        $expected = AtlasDevStringListNormalizer::uniqueTrimmedStrings($input->expectedTests);
        $fileExists = $input->fileExists ?? static fn (string $path): bool => is_file($path);

        /** @var list<FocusedTestCommand> $commands */
        $commands = [];
        /** @var list<string> $unmatchedProductionFiles */
        $unmatched = [];
        /** @var list<string> $seenCommandKey */
        $seenCommandKey = [];

        foreach ($changed as $file) {
            if (str_starts_with($file, 'tests/')) {
                $key = 'direct:'.$file;
                if (in_array($key, $seenCommandKey, true)) {
                    continue;
                }
                $seenCommandKey[] = $key;
                $commands[] = new FocusedTestCommand(
                    command: '/opt/homebrew/bin/php artisan test '.$file,
                    reason: 'changed_test_file:'.$file,
                    confidence: FocusedTestCommand::CONFIDENCE_HIGH,
                );

                continue;
            }

            $candidates = $this->conventionCandidates($file);
            $matched = false;
            foreach ($candidates as $candidate) {
                if (! $fileExists($candidate)) {
                    continue;
                }
                $key = 'convention:'.$candidate;
                if (in_array($key, $seenCommandKey, true)) {
                    $matched = true;

                    continue;
                }
                $seenCommandKey[] = $key;
                $commands[] = new FocusedTestCommand(
                    command: '/opt/homebrew/bin/php artisan test '.$candidate,
                    reason: 'matches_changed_production_file:'.$file,
                    confidence: FocusedTestCommand::CONFIDENCE_HIGH,
                );
                $matched = true;
            }
            if (! $matched) {
                $unmatched[] = $file;
            }
        }

        if (in_array($input->riskLevel, [
            PatchIntelligenceReceipt::RISK_HIGH,
            PatchIntelligenceReceipt::RISK_CRITICAL,
        ], true) && $this->hasProductionFile($changed)) {
            $moduleSuite = $this->moduleSuiteCommand($changed);
            $key = 'module:'.$moduleSuite;
            if (! in_array($key, $seenCommandKey, true)) {
                $seenCommandKey[] = $key;
                $commands[] = new FocusedTestCommand(
                    command: $moduleSuite,
                    reason: 'risk_level_'.$input->riskLevel.'_wider_safety_net',
                    confidence: FocusedTestCommand::CONFIDENCE_MEDIUM,
                );
            }
        }

        $skippedReason = $this->skippedReason($changed, $commands, $unmatched);
        $confidence = $this->overallConfidence($commands, $unmatched, $input->riskLevel);

        return TestSelectionReceipt::issue(
            runId: $input->runId,
            taskContractHash: $input->taskContractHash,
            expectedTests: $expected,
            focusedTestCommands: $commands,
            skippedTestsReason: $skippedReason,
            overallConfidence: $confidence,
            evidenceRefs: array_values($input->evidenceRefs),
        );
    }

    /**
     * @return list<string>
     */
    private function conventionCandidates(string $file): array
    {
        $basename = pathinfo($file, PATHINFO_FILENAME);
        if ($basename === '') {
            return [];
        }

        return [
            'tests/Unit/'.$basename.'Test.php',
            'tests/Feature/'.$basename.'Test.php',
        ];
    }

    /**
     * @param  list<string>  $changed
     */
    private function hasProductionFile(array $changed): bool
    {
        foreach ($changed as $file) {
            if (! str_starts_with($file, 'tests/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $changed
     */
    private function moduleSuiteCommand(array $changed): string
    {
        // Pick the broadest top-level directory we touched so the module suite
        // is the smallest superset that still covers the patch — falling back
        // to the full unit/feature command groups when we cannot localize it.
        $namespaces = [];
        foreach ($changed as $file) {
            if (str_starts_with($file, 'app/Services/Ai/Programming/AtlasDev/')) {
                $namespaces['atlas_dev'] = true;
            } elseif (str_starts_with($file, 'app/Services/Ai/Programming/')) {
                $namespaces['programming'] = true;
            } elseif (str_starts_with($file, 'app/Services/')) {
                $namespaces['services'] = true;
            }
        }
        if (array_key_exists('atlas_dev', $namespaces)) {
            return '/opt/homebrew/bin/php artisan test tests/Unit/Ai/Programming/AtlasDev';
        }
        if (array_key_exists('programming', $namespaces)) {
            return '/opt/homebrew/bin/php artisan test tests/Unit/Ai/Programming';
        }
        if (array_key_exists('services', $namespaces)) {
            return '/opt/homebrew/bin/php artisan test tests/Unit';
        }

        return '/opt/homebrew/bin/php artisan test';
    }

    /**
     * @param  list<string>  $changed
     * @param  list<FocusedTestCommand>  $commands
     * @param  list<string>  $unmatched
     */
    private function skippedReason(array $changed, array $commands, array $unmatched): ?string
    {
        if ($commands === [] && $changed === []) {
            return 'no_changed_files:no_tests_required_caller_must_attach_no_test_reason';
        }
        if ($commands === [] && $changed !== []) {
            return 'no_convention_match:'.implode(',', $unmatched);
        }
        if ($unmatched !== []) {
            return 'partial_convention_match:no_tests_found_for:'.implode(',', $unmatched);
        }

        return null;
    }

    /**
     * @param  list<FocusedTestCommand>  $commands
     * @param  list<string>  $unmatched
     */
    private function overallConfidence(array $commands, array $unmatched, string $riskLevel): string
    {
        if ($commands === []) {
            return TestSelectionReceipt::CONFIDENCE_LOW;
        }
        if ($unmatched !== []) {
            // Some production files had no convention test — we don't know if
            // the focused commands cover them, so we cap confidence below high.
            return TestSelectionReceipt::CONFIDENCE_MEDIUM;
        }
        if (in_array($riskLevel, [
            PatchIntelligenceReceipt::RISK_HIGH,
            PatchIntelligenceReceipt::RISK_CRITICAL,
        ], true)) {
            // High-risk patches always carry residual uncertainty regardless of
            // perfect convention matching, so we cap at medium too. Operators
            // who want "high" must show the wider suite green first.
            return TestSelectionReceipt::CONFIDENCE_MEDIUM;
        }

        return TestSelectionReceipt::CONFIDENCE_HIGH;
    }
}
