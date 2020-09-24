<?php
require __DIR__ . '/vendor/autoload.php';
require_once("Trader.php");

$trader = New Trader($argv[1], $argv[2], $argv[3], $argv[4]);

if (isset($argv[5]) and $argv[5] == "open_only") {
    return $trader->trade_open();
}
if (isset($argv[5]) and $argv[5] == "take_profit") {
    return $trader->take_profit();
}
if (isset($argv[5]) and $argv[5] == "manage") {
    return $trader->trade_manage();
}
if (isset($argv[5]) and $argv[5] == "limit") {
    return $trader->create_limit();
}
return $trader->scalp_open_and_manage();
?>
