<?php

require __DIR__ . '/vendor/autoload.php';
require_once("BitMex.php");
require_once("log.php");


class Trader {

    const TICKER_PATH = '.ticker';
    const SCALP_PATH = '_scalp_info.json';
    private $log;
    private $bitmex;

    private $strategy = 'scalp';
    private $symbol;
    private $stopLossInterval;
    public $marketStop;
    private $targets;
    private $amount;
    private $env;
    private $tradeFile;
    private $timeFrame;
    private $startTime;
    private $leap;
    private $stopPx;


    public function __construct($symbol, $side, $amount, $stopPx = null) {
        $this->tradeFile = $this->strategy."_".$side.'_'.$symbol;
        if (file_exists($this->tradeFile)) {
            return;
        }
        $this->startTime = microtime(true);
        $config = include('config.php');

        $this->bitmex = new BitMex($config['key'], $config['secret'], $config['testnet']);
        $this->timeFrame = $config['timeframe'];
        $this->leap = $config['leap'][$symbol];
        $this->leap = $side == "Sell" ? -1 * $this->leap:$this->leap;
        $this->log = create_logger(getcwd().'/scalp.log');
        $this->priceRounds = $config['priceRounds'];
        $this->symbol = $symbol;
        $this->stopPx = $stopPx;
        $scalpInfo = json_decode(file_get_contents($this->symbol.self::SCALP_PATH));
        $scalpInfo = json_decode(json_encode($scalpInfo), true);
        $this->stopLossInterval = intval($config['stopLoss'][$this->symbol]);
        $lastTicker = $scalpInfo['last'];

        if ($side == "Buy") {
            $this->marketStop = $lastTicker['low'] -2*$this->stopLossInterval;
            $this->stopLoss = array($lastTicker['close'] - $this->stopLossInterval, $lastTicker['close'] + $this->stopLossInterval/2);
        }
        else {
            $this->marketStop = $lastTicker['high'] + 2*$this->stopLossInterval;
            $this->stopLoss = array($lastTicker['close'] + $this->stopLossInterval, $lastTicker['close'] - $this->stopLossInterval/2);
        }
        $this->side = $side;
        $this->amount = intval($amount);

        if ($config['testnet']) {
            $this->env = 'test';
        }
        else {
            $this->env = 'prod';
        }
        $this->targets = array();
        $this->log->info("Finished Trade construction, proceeding",[]);
    }
    public function __destruct() {
    }


    public function is_buy() {
        return $this->side == 'Buy' ? 1:0;
    }

    public function get_opposite_trade_side() {
        return $this->side == "Buy" ? "Sell": "Buy";
    }

    public function get_ticker() {
        do {
            try {
                sleep(1);
                $ticker = $this->bitmex->getTicker($this->symbol);
            } catch (Exception $e) {
                $this->log->error("failed to sumbit", ['error'=>$e]);
                sleep(2);
            }
        } while ($ticker['last'] == null);
        return $ticker;
    }
    public function true_cancel_all_orders() {
        $result = False;
        $this->log->info("cancelling all open orders.", ["limit orders"=>$this->is_limit()]);
        do {
            try {
                $result = $this->bitmex->cancelAllOpenOrders($this->symbol);
                sleep(3);
            } catch (Exception $e) {
                $this->log->error("Failed to sumbit", ['error'=>$e]);
                break;
            }
        } while (!is_array($result));
        $this->log->info("open orders canceled.", ["limit orders"=>$this->is_limit()]);
    }

     public function true_edit($orderId, $price, $amount, $stopPx) {
        $result = False;
        $this->log->info("editing order.", ["orderId" => $orderId]);
        do {
            try {
                $result = $this->bitmex->editOrder($orderId, $price, $amount, $stopPx);
                sleep(3);
            } catch (Exception $e) {
                $this->log->error("Failed to sumbit", ['error'=>$e]);
                break;
            }

        } while (!is_array($result));
    }


    public function true_create_order($type, $side, $amount, $price, $stopPx = null) {
        $this->log->info("Sending a Create Order command", ['side'=>$side.' '.$amount.' contracts, Price=>'.$price]);
        $order = False;
        do {
            try {
                $order = $this->bitmex->createOrder($this->symbol, $type, $side, $price, $amount, $stopPx);
            } catch (Exception $e) {
                if (strpos($e, 'Invalid orderQty') !== false) {
                    $this->log->error("Failed to sumbit, Invalid quantity", ['error'=>$e]);
                    return false;
                }
                if (strpos($e, 'insufficient Available Balance') !== false) {
                    $this->log->error("Failed to sumbit, insufficient Available Balance", ['error'=>$e]);
                    return false;
                }
                if (strpos($e, 'Invalid API Key') !== false) {
                    $this->log->error("Failed to sumbit, Invalid API Key", ['error'=>$e]);
                    return false;
                }
                $this->log->error("Failed to create/close position retrying in 3 seconds", ['error'=>$e]);
                sleep(3);
                continue;
            }
            $this->log->info("Position has been created on Bitmex.", ['Strategy'=>$this->strategy.' '.$this->side]);
            $this->log->info("Position successful, OrderId:".$order['orderID'], ['price'=>$order['price']]);
            break;
        } while (1);
        return $order;
    }
    public function get_open_positions() {
        $openPositions = null;
        do {
            try {
                sleep(2);
                $openPositions = $this->bitmex->getOpenPositions();
            } catch (Exception $e) {
                $this->log->error("failed to sumbit", ['error'=>$e]);
                sleep(2);
            }
        } while (!is_array($openPositions));
        return $openPositions;
    }

    public function are_open_positions() {
        $openPositions = $this->get_open_positions();
        foreach($openPositions as $pos) {
            if ($pos["symbol"] == $this->symbol) {
                return $pos;
            }
        }
        return False;
    }
    public function get_open_orders() {
        $openOrders = null;
        do {
            try {
                sleep(1);
                $openOrders = $this->bitmex->getOpenOrders($this->symbol);
            } catch (Exception $e) {
                $this->log->error("failed to sumbit", ['error'=>$e]);
                sleep(2);
            }
        } while (!is_array($openOrders));
        return $openOrders;
    }

    public function get_order_book() {
        $orderBook = null;
        do {
            try {
                sleep(1);
                $orderBook = $this->bitmex->getOrderBook(1, $this->symbol);
            } catch (Exception $e) {
                $this->log->error("failed to sumbit", ['error'=>$e]);
                sleep(2);
            }
        } while (!is_array($orderBook));
        return $orderBook;
    }

    public function get_limit_price() {
        $orderBook = $this->get_order_book();
        foreach ($orderBook as $book) {
            if ($book["side"] == $this->side) {
                return $book['price'];
            }
        }
        return False;
    }

    public function are_open_orders() {
        $openOrders = $this->get_open_orders();
        $return = empty($openOrders) ? False:$openOrders;
        return $return;
    }

    public function is_stop() {
        $openOrders= $this->get_open_orders();
        foreach($openOrders as $order) {
            if ($order["ordType"] == "Stop") {
                return $order["orderID"];
            }
        }
        return False;
    }
    public function is_limit() {
        $openOrders = $this->get_open_orders();
        if (!is_array($openOrders)) {
            return False;
        }
        foreach($openOrders as $order) {
            if ($order["ordType"] == "Limit") {
                return True;
            }
        }
        return False;
    }
    public function sum_limit_orders() {
        $openOrders= $this->get_open_orders();
        $sum = 0;
        foreach($openOrders as $order) {
            if ($order["ordType"] == "Limit") {
                $sum += $order["orderQty"];
            }
        }
        return $sum;
    }

    public function verify_limit_order() {
        $this->log->info("verifying limit order to be accepted",[]);
        $start = microtime(true);
        do {
            sleep(2);
            if (date('i', microtime(true)-$start) >= 3*$this->timeFrame) {
                return False;
            }
        } while(!$this->are_open_positions());
        return True;
    }
     public function limitCloseOrElse() {
         $this->log->info("Decided to limit close the position",[$this->stopLoss]);
         $ticker = False;

         $lastTicker = $this->get_ticker()['last'];
         $order = $this->true_create_order('Limit', $this->get_opposite_trade_side(), $this->amount, $this->get_limit_price() + $this->leap);
         sleep(5);
         if (!$this->are_open_positions()) {
             return;
         }
         sleep(10);
         do {
             if (!$this->are_open_positions()["currentQty"]) {
                 return;
             }
             sleep(2);
             $ticker = $this->get_ticker()['last'];
             if ($ticker < $lastTicker and $this->side == "Buy" or $ticker > $lastTicker and $this->side == "Sell") {
                 $lastTicker = $ticker;
                 $this->true_edit($order["orderID"], $this->get_limit_price() + $this->leap, null, null);
             }
             sleep(15);
             if (!$this->are_open_positions()) {
                 return;
             }
             sleep(2);
         } while ($ticker < $this->marketStop and $this->side == "Sell" or $ticker > $this->marketStop and $this->side == "Buy");
         $this->log->info("attemting Market order as limit was not filled",["marketStop"=>$this->marketStop]);
         $this->true_cancel_all_orders();
         sleep(2);
         $this->amount = $this->are_open_positions();
         sleep(2);
         $this->true_create_order('Market', $this->get_opposite_trade_side(), $this->amount, null);
     }

    public function scalp_open_and_manage() {
        if (file_exists($this->tradeFile)) {
            return false;
        }
        shell_exec('touch '.$this->tradeFile);
        $this->log->info('---------------------------------- New Order ----------------------------------', ['Sepparator'=>'---']);
        $percentage = 3;
        $wallet = False;
        $stop = $this->stopLoss[0];

        try {
            $wallet = $this->bitmex->getWallet();
        }  catch (Exception $e) {
            $this->log->error("Falied to et wallet.",[]);
        }
        $wallet = end($wallet);
        $walletAmout = $wallet['walletBalance'];
        $this->log->info("wallet has ".$walletAmout." btc in it", ["wallet"=>$walletAmout]);


        $scalpInfo = json_decode(file_get_contents($this->symbol.self::SCALP_PATH));
        $scalpInfo = json_decode(json_encode($scalpInfo), true);
        $lastCandle = $scalpInfo['last'];
        $emas = $scalpInfo['emas'];
        $this->targets = $emas;
        $ticker = $this->get_ticker()['last'];
        if ($this->side == "Buy" and $ticker <= $lastCandle['close']) {
            $price = $this->get_limit_price();
        }
        elseif ($this->side == "Sell" and $ticker >= $lastCandle['close']) {
            $price = $this->get_limit_price();
        }
        else {
            $price = $lastCandle['close'];
        }

        if (!$this->true_create_order('Limit', $this->side, $this->amount, $price)) {
            shell_exec("rm ".$this->tradeFile);
            $this->log->error("Failed to create order", ['side'=>$this->side]);
            return;
        }
        if (!$this->verify_limit_order()) {
            $this->log->error("limit order was not filled thus canceling",["timeframe"=>$this->timeFrame]);
            $this->true_cancel_all_orders();
            shell_exec('rm '.$this->tradeFile);
            return;
        }
        $this->log->info("limit Order got filled",['fill'=>True]);

        $this->log->info("Targets are: ", ['targets'=>$this->targets]);
        $this->log->info("stopLoss are", ['stopLoss'=>$this->stopLoss]);
        sleep(2);
        foreach ($emas as $key=>$target) {
            $order = $this->true_create_order('Limit', $this->get_opposite_trade_side(), intval($this->amount/$percentage), round($target, $this->priceRounds[$this->symbol]));
            $this->targets[$key] = $order['orderID'];
            sleep(5);
        }
        do {
            if (!$this->is_limit()) {
                $this->log->info("No Limit orders found", ["limit"=>$this->is_limit()]);
                break;
            } else {
                if ($this->amount != $this->sum_limit_orders()) {
                    $this->amount = $this->sum_limit_orders();
                    $stop = $this->stopLoss[1];
                }
            }
            sleep(2);
            $ticker = $this->get_ticker()['last'];
            if ($ticker > $stop and $this->side == "Sell" or $ticker < $stop and $this->side == "Buy") {
                $this->true_cancel_all_orders();
                sleep(2);
                $this->limitCloseOrElse();
                sleep(2);
            }
            sleep(2);

        } while ($this->is_limit());
        try {
            $wallet = $this->bitmex->getWallet();
        }  catch (Exception $e) {
            $this->log->error("Failed to get wallet.",[]);
        }
        $wallet = end($wallet);
        $currentWalletAmout = $wallet['walletBalance'];
        $this->log->info("wallet has ".$currentWalletAmout." btc in it", ["previouswallet"=>$walletAmout]);
        $res = ($currentWalletAmout-$walletAmout) < 0 ? "Loss":"Win";
        $this->log->info("Trade made ".($currentWalletAmout-$walletAmout), ["result"=>$res]);


        if (microtime(true) - $this->startTime < $this->timeFrame*60) {
            $this->log->info("waiting the remaining of the timeframe to finish",['timeframe'=>$this->timeFrame]);
            do {
                sleep(1);
            } while (microtime(true) - $this->startTime < $this->timeFrame*60);
        }
        $this->log->info("Trade have finished removing trade File", ["tradeFile"=>file_exists($this->tradeFile)]);
        shell_exec("rm ".$this->tradeFile);
    }
    public function trade_open() {
        if (file_exists($this->tradeFile)) {
            return false;
        }
        shell_exec('touch '.$this->tradeFile);
        $this->log->info('---------------------------------- New Order ----------------------------------', ['Sepparator'=>'---']);
        $scalpInfo = json_decode(file_get_contents($this->symbol.self::SCALP_PATH));
        $scalpInfo = json_decode(json_encode($scalpInfo), true);
        $lastCandle = $scalpInfo['last'];

        $ticker = $this->get_ticker()['last'];
        if ($this->side == "Buy" and $ticker <= $lastCandle['close']) {
            $price = $ticker;
        }
        elseif ($this->side == "Sell" and $ticker >= $lastCandle['close']) {
            $price = $ticker;
        }
        else {
            $price = $lastCandle['close'];
        }

        if (!$this->true_create_order('Limit', $this->side, $this->amount, $price)) {
            shell_exec("rm ".$this->tradeFile);
            $this->log->error("Failed to create order", ['side'=>$this->side]);
            return;
        }
        if (!$this->verify_limit_order()) {
            $this->log->error("limit order was not filled thus canceling",["timeframe"=>$this->timeFrame]);
            $this->true_cancel_all_orders();
            return;
        }
        sleep(10);
        $position = $this->are_open_positions();
        sleep(2);
        if ($stopOrder = $this->is_stop()) {
            $this->true_edit($stopOrder, null, $position['currentQty'], $this->stopPx);
        } else {
            $this->true_create_order('Stop', $this->get_opposite_trade_side(), $this->amount, null, $this->stopPx);
        }
        if (microtime(true) - $this->startTime < $this->timeFrame*60) {
            $this->log->info("waiting the remaining of the timeframe to finish",['timeframe'=>$this->timeFrame]);
            do {
                sleep(1);
            } while (microtime(true) - $this->startTime < $this->timeFrame*60);
        }
        $this->log->info("Trade have finished removing trade File", ["tradeFile"=>file_exists($this->tradeFile)]);
        shell_exec("rm ".$this->tradeFile);
    }
}

?>
