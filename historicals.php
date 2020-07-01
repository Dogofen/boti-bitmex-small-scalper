<?php
require __DIR__ . '/vendor/autoload.php';
require_once("BitMex.php");
$config = include('config.php');
file_put_contents('conf.json', json_encode($config));
require_once('log.php');


$logPath =  getcwd().'/boti.log';
$log = create_logger($logPath);
file_put_contents('historicalsPid.json',  json_encode(getmypid()));

$historicalFile ="_historical.json";
$bitmex = new BitMex($config['key'],$config['secret'], $config['testnet']);
$symbols = $config['pairs'];

$timeFrame = $config['timeframe'];
$result = array();
foreach($symbols as $symbol) {
    $result[$symbol] = $bitmex->getCandles($timeFrame.'m', 30, $symbol);
    file_put_contents($symbol.$historicalFile,  json_encode($result[$symbol]));
    sleep(1);
}
while(1) {
    $errors=0;
    $time = microtime(true);
    $minutes = date('i', $time);
    if($minutes%$timeFrame == 0) {
        foreach($symbols as $symbol) {
            do {
                try {
                    $res = $bitmex->getCandles($timeFrame.'m', 30, $symbol);
                    $log->info("New candle fetched", ["candle"=>$res[0]]);
                } catch (Exception $e) {
                    $log->error("Failed retrieving ticker, sleeping few seconds", ['error'=>$e]);
                    $errors = ++$errors;
                    continue;
                }
                if (!$res) {
                    $log->error("Failed retrieving ticker, Bitmex servers have blocked communication, sleeping few seconds before retrying...", ['result'=>$result]);
                    $errors = ++$errors;
                    continue;
                }
                sleep(2);
            } while ($res[0] == $result[$symbol][0]);
            $result[$symbol] = $res;
            file_put_contents($symbol.$historicalFile,  json_encode($result[$symbol]));
        }
        sleep(10*$timeFrame);
        $log->info("finished getting historicals.", ['errors'=>$errors]);
    }
    sleep(1);
}
?>
