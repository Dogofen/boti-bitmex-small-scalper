<?php
require __DIR__ . '/vendor/autoload.php';
require_once("BitMex.php");
$config = include('config.php');
require_once('log.php');


$logPath =  getcwd().'/boti.log';
$log = create_logger($logPath);

$ticker =".ticker";
$bitmex = new BitMex($config['key'],$config['secret'], $config['testnet']);
$symbols = $config['pairs'];
while(1) {
    foreach($symbols as $symbol) {
        try {
            $result = $bitmex->getOpenPositions();
        } catch (Exception $e) {
            $log->error("Failed retrieving positions, sleeping for a few seconds", ['error'=>$e]);
            sleep(4);
            continue;
        }
        if (is_array($result)) {
            file_put_contents('openPositions.txt',  serialize($result));
        }
        try {
            $result = $bitmex->getOpenOrders($symbol);
        } catch (Exception $e) {
            $log->error("Failed retrieving orders, sleeping for a few seconds", ['error'=>$e]);
            sleep(4);
            continue;
        }
        if (is_array($result)) {
            file_put_contents($symbol.'_openOrders.txt',  serialize($result));
        }
        sleep(4);
    }
}
?>
