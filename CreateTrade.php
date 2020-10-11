<?php
require __DIR__ . '/vendor/autoload.php';
require_once("Trader.php");

$trader = New Trader($argv[1], $argv[2], $argv[3], $argv[4]);

return $trader->scalp_open_and_manage();
?>
