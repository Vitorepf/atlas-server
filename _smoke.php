<?php
require '/Users/vitorepf/develop/Atlas/atlas-server/vendor/autoload.php';

use App\Services\Ai\AutonomousEvolution\AtlasLoopBroaderRegressionGate;

$gate = new AtlasLoopBroaderRegressionGate;
$base = '/Users/vitorepf/develop/Atlas/atlas-server';

$selected = $gate->selectTestPaths($base, ['app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php']);
$assert = function ($name, $sel, $expected) {
    echo "  $name: " . (in_array($expected, $sel, true) ? "OK" : "MISSING ($expected)") . "\n";
};
echo "[1] loop engine file:\n";
$assert('NEVER_MERGE_INVARIANT_TEST', $selected, AtlasLoopBroaderRegressionGate::NEVER_MERGE_INVARIANT_TEST);
$assert('tests/Feature/Loop', $selected, 'tests/Feature/Loop');
$assert('tests/Unit/Ai/AutonomousEvolution', $selected, 'tests/Unit/Ai/AutonomousEvolution');

$selected2 = $gate->selectTestPaths($base, ['app/Services/Ai/Obra/AtlasObraExecutor.php']);
echo "[2] obra file:\n";
$assert('NEVER_MERGE_INVARIANT_TEST', $selected2, AtlasLoopBroaderRegressionGate::NEVER_MERGE_INVARIANT_TEST);
$assert('tests/Feature/Ai/Obra', $selected2, 'tests/Feature/Ai/Obra');

$selected3 = $gate->selectTestPaths($base, ['tests/Feature/Loop/AtlasLoopAutoMergeServiceTest.php']);
echo "[3] test file:\n";
$assert('test file', $selected3, 'tests/Feature/Loop/AtlasLoopAutoMergeServiceTest.php');

$selected4 = $gate->selectTestPaths($base, ['app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php', 'tests/Feature/Loop/AtlasLoopAutoMergeServiceTest.php']);
echo "[4] dedup NEVER_MERGE: " . (count(array_filter($selected4, fn($s) => $s === AtlasLoopBroaderRegressionGate::NEVER_MERGE_INVARIANT_TEST)) === 1 ? "OK" : "DUP") . "\n";
echo "[4] dedup tests/Feature/Loop: " . (count(array_filter($selected4, fn($s) => $s === 'tests/Feature/Loop')) === 1 ? "OK" : "DUP") . "\n";

$selected5 = $gate->selectTestPaths($base, ['app/NonExistent/Foo.php', 'tests/Unit/NonExistent/FooTest.php']);
echo "[5] non-existent files (selection): " . json_encode($selected5) . "\n";

$selected6 = $gate->selectTestPaths($base, ['', '   ', 'app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php']);
echo "[6] whitespace skipped: count=" . count($selected6) . " NEVER_MERGE=" . (in_array(AtlasLoopBroaderRegressionGate::NEVER_MERGE_INVARIANT_TEST, $selected6, true) ? 'yes' : 'no') . "\n";

$selected7 = $gate->selectTestPaths($base, ['app\\Services\\Ai\\AutonomousEvolution\\AtlasLoopTaskGrinder.php']);
echo "[7] backslash normalized: tests/Feature/Loop=" . (in_array('tests/Feature/Loop', $selected7, true) ? 'yes' : 'no') . "\n";

$selected8 = $gate->selectTestPaths($base, ['/app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php']);
echo "[8] leading-slash: tests/Feature/Loop=" . (in_array('tests/Feature/Loop', $selected8, true) ? 'yes' : 'no') . "\n";

$selected9a = $gate->selectTestPaths($base, ['app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php']);
$selected9b = $gate->selectTestPaths($base, ['app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php']);
echo "[9] deterministic: " . ($selected9a === $selected9b ? "OK" : "DIFFERS") . "\n";
