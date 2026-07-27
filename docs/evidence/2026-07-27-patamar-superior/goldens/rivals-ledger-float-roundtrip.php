<?php
// Prova: um verdict com float inteiro sobrevive ao round-trip disco->verify.
require '/Users/vitorepf/develop/Atlas/atlas-server/tests/bootstrap.php';
$app = require '/Users/vitorepf/develop/Atlas/atlas-server/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$dir = sys_get_temp_dir().'/atlas-ledger-probe-'.bin2hex(random_bytes(6));
mkdir($dir.'/rivals', 0o755, true);
config(['atlas_rivals.storage_root' => $dir]);

$ledger = new App\Services\Ai\Rivals\Core\ResultLedger;
$ledger->append('run_probe', [
    'verdict' => 'valid',
    'success_rate' => 1.0,   // float inteiro — o caso que quebrava
    'wilson_low' => 0.0,
    'width' => 0.299145,
]);

$chain = $ledger->verifyChain();
printf("entries=%d verified=%s failures=%s\n", $chain['entries'], var_export($chain['verified'], true), json_encode($chain['failures']));

$ok = $chain['entries'] === 1 && $chain['verified'] === true && $chain['failures'] === [];
echo $ok ? "PASS: float inteiro sobrevive ao round-trip\n" : "FAIL\n";
exit($ok ? 0 : 1);
