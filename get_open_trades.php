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
file_put_contents('get_open_trades.pid',  serialize(getmypid()));
while(1) {
    foreach($symbols as $symbol) {
        try {
            $result = $bitmex->getTicker($symbol);
        } catch (Exception $e) {
            $log->error("Failed retrieving ticker, sleeping for 360 seconds", ['error'=>$e]);
            sleep(4);
            continue;
        }
        if (!$result) {
            $log->error("Failed retrieving ticker, Bitmex servers have blocked communication, sleeping for 360 seconds before retrying...", ['result'=>$result]);
            sleep(4);
            continue;
        }
        file_put_contents($ticker.$symbol.'.txt',  serialize($result));
        sleep(2);
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
        sleep(2);
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
        sleep(2);
    }
}
?>
