<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Differential\Shadow;

use App\Services\Ai\Programming\AtlasDev\Differential\Shadow\PureFunctionDetector;
use PHPUnit\Framework\TestCase;

/**
 * E4 -- PureFunctionDetector unit tests.
 *
 * VAL-E4-007 (conservative pure-function detection): impure code (I/O, DB,
 * mutation of external/global state, randomness, time) is conservatively
 * NOT classified pure. The detector MUST have zero false negatives: any
 * impure indicator => impure. False positives (flagging a pure function as
 * impure) are acceptable.
 */
final class PureFunctionDetectorTest extends TestCase
{
    public function test_simple_arithmetic_is_pure(): void
    {
        $detector = new PureFunctionDetector;

        $this->assertTrue($detector->isLikelyPure('return $x + $y;'));
        $this->assertTrue($detector->isLikelyPure('$z = $x * $y; return $z;'));
    }

    public function test_string_manipulation_is_pure(): void
    {
        $detector = new PureFunctionDetector;

        $this->assertTrue($detector->isLikelyPure('return strtoupper($s);'));
        $this->assertTrue($detector->isLikelyPure('return substr($s, 0, 3);'));
        $this->assertTrue($detector->isLikelyPure('return str_repeat($s, $n);'));
    }

    public function test_array_manipulation_is_pure(): void
    {
        $detector = new PureFunctionDetector;

        $this->assertTrue($detector->isLikelyPure('return array_map(fn($x) => $x + 1, $arr);'));
        $this->assertTrue($detector->isLikelyPure('return array_filter($arr, fn($x) => $x > 0);'));
        $this->assertTrue($detector->isLikelyPure('return array_merge($a, $b);'));
    }

    public function test_empty_body_is_pure(): void
    {
        $detector = new PureFunctionDetector;

        $this->assertTrue($detector->isLikelyPure(''));
    }

    // -- Impure indicators (VAL-E4-007) --------------------------------------

    public function test_echo_is_impure(): void
    {
        $detector = new PureFunctionDetector;

        $this->assertFalse($detector->isLikelyPure('echo $x;'));
        $this->assertNotNull($detector->impureIndicator('echo $x;'));
    }

    public function test_print_is_impure(): void
    {
        $detector = new PureFunctionDetector;

        $this->assertFalse($detector->isLikelyPure('print($x);'));
    }

    public function test_file_io_is_impure(): void
    {
        $detector = new PureFunctionDetector;

        $this->assertFalse($detector->isLikelyPure('return file_get_contents($path);'));
        $this->assertFalse($detector->isLikelyPure('file_put_contents($path, $data);'));
        $this->assertFalse($detector->isLikelyPure('$f = fopen($path, "r");'));
        $this->assertFalse($detector->isLikelyPure('unlink($path);'));
    }

    public function test_db_is_impure(): void
    {
        $detector = new PureFunctionDetector;

        $this->assertFalse($detector->isLikelyPure('DB::table("users")->insert($data);'));
        $this->assertFalse($detector->isLikelyPure('$model->save();'));
        $this->assertFalse($detector->isLikelyPure('$model->delete();'));
        $this->assertFalse($detector->isLikelyPure('Schema::create("users", $cb);'));
    }

    public function test_network_is_impure(): void
    {
        $detector = new PureFunctionDetector;

        $this->assertFalse($detector->isLikelyPure('$ch = curl_init($url);'));
        $this->assertFalse($detector->isLikelyPure('Http::post($url, $data);'));
    }

    public function test_randomness_is_impure(): void
    {
        $detector = new PureFunctionDetector;

        $this->assertFalse($detector->isLikelyPure('return rand(1, 100);'));
        $this->assertFalse($detector->isLikelyPure('return mt_rand(1, 100);'));
        $this->assertFalse($detector->isLikelyPure('return random_int(1, 100);'));
        $this->assertFalse($detector->isLikelyPure('shuffle($arr);'));
    }

    public function test_time_is_impure(): void
    {
        $detector = new PureFunctionDetector;

        $this->assertFalse($detector->isLikelyPure('return time();'));
        $this->assertFalse($detector->isLikelyPure('return date("Y-m-d");'));
        $this->assertFalse($detector->isLikelyPure('return microtime(true);'));
        $this->assertFalse($detector->isLikelyPure('return Carbon::now();'));
    }

    public function test_superglobals_are_impure(): void
    {
        $detector = new PureFunctionDetector;

        $this->assertFalse($detector->isLikelyPure('return $_POST["x"];'));
        $this->assertFalse($detector->isLikelyPure('return $_GET["y"];'));
        $this->assertFalse($detector->isLikelyPure('return $_SESSION["z"];'));
    }

    public function test_global_keyword_is_impure(): void
    {
        $detector = new PureFunctionDetector;

        $this->assertFalse($detector->isLikelyPure('global $config; return $config;'));
    }

    public function test_static_local_state_is_impure(): void
    {
        $detector = new PureFunctionDetector;

        $this->assertFalse($detector->isLikelyPure('static $count = 0; $count++; return $count;'));
    }

    public function test_subprocess_is_impure(): void
    {
        $detector = new PureFunctionDetector;

        $this->assertFalse($detector->isLikelyPure('return exec("ls");'));
        $this->assertFalse($detector->isLikelyPure('return shell_exec("whoami");'));
        $this->assertFalse($detector->isLikelyPure('return system("pwd");'));
    }

    public function test_this_receiver_is_impure(): void
    {
        // A method that touches $this has object state => impure.
        $detector = new PureFunctionDetector;

        $this->assertFalse($detector->isLikelyPure('return $this->value;'));
        $this->assertFalse($detector->isLikelyPure('$this->value = $x;'));
    }

    public function test_throw_is_impure(): void
    {
        // A throwing function uses exception as a side channel.
        $detector = new PureFunctionDetector;

        $this->assertFalse($detector->isLikelyPure('throw new \InvalidArgumentException("bad");'));
    }

    public function test_object_construction_is_conservatively_impure(): void
    {
        $detector = new PureFunctionDetector;

        $this->assertFalse($detector->isLikelyPure('return new \stdClass();'));
    }

    public function test_impure_indicator_label_is_carried(): void
    {
        // VAL-CROSS-016: the skip reason carries WHICH indicator tripped.
        $detector = new PureFunctionDetector;

        $indicator = $detector->impureIndicator('return rand(1, 10);');
        $this->assertNotNull($indicator);
        $this->assertStringContainsString('random', $indicator);
    }
}
