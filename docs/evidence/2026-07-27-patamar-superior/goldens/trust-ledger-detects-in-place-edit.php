<?php
require '/Users/vitorepf/develop/Atlas/atlas-server/vendor/autoload.php';
$app = require '/Users/vitorepf/develop/Atlas/atlas-server/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Ai\Foundry\Rsi\EarnedAutonomy\TrustLedgerService;

$root = sys_get_temp_dir().'/trustledger-'.bin2hex(random_bytes(4));
$svc = new TrustLedgerService;
$svc->setStorageRootForTesting($root);

// grava dois ciclos legitimos
for ($i = 0; $i < 2; $i++) {
    $svc->recordCycle(
        ['area_id' => 'area-1', 'focus' => 'foco-1'],
        ['outcome_proven' => true, 'red_team_survived' => true, 'drift_clean' => true, 'auto_applied' => false, 'cycle_id' => 'c'.$i],
    );
}
$path = $svc->ledgerPath('area-1', 'foco-1');

$replay = function (TrustLedgerService $s): string {
    try { $ev = $s->replay('area-1', 'foco-1'); return 'ok ('.count($ev).' eventos)'; }
    catch (Throwable $e) { return 'REJEITADO: '.$e->getMessage(); }
};
echo "A. ledger intacto            -> ".$replay($svc)."\n";

// adultera o payload NO LUGAR, preservando event_hash e prev_hash
$lines = array_values(array_filter(explode("\n", file_get_contents($path)), fn ($l) => trim($l) !== ''));
$row = json_decode($lines[0], true);
$row['qualifies'] = true;               // forja a qualificacao
$row['forged'] = 'editado sem tocar os hashes';
$lines[0] = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
file_put_contents($path, implode("\n", $lines)."\n");
echo "B. payload ADULTERADO no lugar -> ".$replay($svc)."\n";

shell_exec('rm -rf '.escapeshellarg($root));
