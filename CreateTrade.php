<?php
require __DIR__ . '/vendor/autoload.php';
require_once("Trader.php");

$trader = New Trader($argv[1], $argv[2], $argv[3], $argv[4]);

if (isset($argv[5]) and $argv[5] == "only_open") {
    return $trader->trade_open();
}
return $trader->scalp_open_and_manage();
?>
