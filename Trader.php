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
    private $initialAmount;
    private $env;
    private $tradeFile;
    private $timeFrame;
    private $startTime;
    private $leap;
    private $stopPx;
    private $maxCompunds;


    public function __construct($symbol, $side, $amount, $stopPx = null) {
        $this->tradeFile = $this->strategy."_".$symbol;
        if (file_exists($this->tradeFile)) {
            return;
        }
        $this->startTime = microtime(true);
        $config = include('config.php');

        $this->bitmex = new BitMex($config['key'], $config['secret'], $config['testnet']);
        $this->timeFrame = $config['timeframe'];
        $this->leap = $config['leap'][$symbol];
        $this->maxCompunds = $config['maxCompunds'];
        $this->leap = $side == "Sell" ? -1 * $this->leap:$this->leap;
        $this->log = create_logger(getcwd().'/scalp.log');
        $this->log->info('---------------------------------- New Order ----------------------------------', ['Sepparator'=>'---']);
        $this->priceRounds = $config['priceRounds'];
        $this->symbol = $symbol;
        $this->stopPx = $stopPx;
        $scalpInfo = json_decode(file_get_contents($this->symbol.self::SCALP_PATH));
        $scalpInfo = json_decode(json_encode($scalpInfo), true);
        $this->stopLossInterval = intval($config['stopLoss'][$this->symbol]);
        $lastTicker = $scalpInfo['last'];

        if ($side == "Buy") {
            $this->log->info("Calculating Stop loss for Buy.", ['lastCandle'=>$lastTicker]);
            $this->marketStop = $lastTicker['low'] -2*$this->stopLossInterval;
            $this->stopLoss = array($lastTicker['low'] - $this->stopLossInterval, $lastTicker['close']);
        }
        else {
            $this->log->info("Calculating Stop loss for Sell.", ['lastCandle'=>$lastTicker]);
            $this->marketStop = $lastTicker['high'] + 2*$this->stopLossInterval;
            $this->stopLoss = array($lastTicker['high'] + $this->stopLossInterval, $lastTicker['close']);
        }
        $this->side = $side;
        $this->initialAmount = intval($amount);

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
        $this->log->info("Remaining open Trades or orders.", ['OpenPositions => '=>$this->are_open_positions(), "Orders => "=>$this->are_open_orders()]);
        sleep(2);
        shell_exec("rm ".$this->tradeFile);
        $this->log->info("Trade have finished removing trade File", ["tradeFile"=>file_exists($this->tradeFile)]);
        $this->log->info('---------------------------------- End !!!!! ----------------------------------', ['Sepparator'=>'---']);
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
                $this->log->error("failed to submit", ['error'=>$e]);
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
                $this->log->error("Failed to submit", ['error'=>$e]);
                break;
            }
        } while (!is_array($result));
        $this->log->info("open orders canceled.", ["limit orders"=>$this->is_limit()]);
    }
    public function true_cancel_order($orderId) {
        $result = False;
        $this->log->info("cancelling order.", ["order"=>$orderId]);
        do {
            try {
                $result = $this->bitmex->cancelOpenOrder($orderId);
                sleep(3);
            } catch (Exception $e) {
                $this->log->error("Failed to submit", ['error'=>$e]);
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
                $this->log->error("Failed to submit", ['error'=>$e]);
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
                    $this->log->error("Failed to submit, Invalid quantity", ['error'=>$e]);
                    return false;
                }
                if (strpos($e, 'insufficient Available Balance') !== false) {
                    $this->log->error("Failed to submit, insufficient Available Balance", ['error'=>$e]);
                    return false;
                }
                if (strpos($e, 'Invalid API Key') !== false) {
                    $this->log->error("Failed to submit, Invalid API Key", ['error'=>$e]);
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
                $this->log->error("failed to submit", ['error'=>$e]);
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
                $this->log->error("failed to submit", ['error'=>$e]);
                sleep(2);
            }
        } while (!is_array($openOrders));
        return $openOrders;
    }

    public function get_open_order_by_id($id) {
        $openOrders = null;
        do {
            try {
                sleep(1);
                $openOrders = $this->bitmex->getOpenOrders($this->symbol);
            } catch (Exception $e) {
                $this->log->error("failed to submit", ['error'=>$e]);
                sleep(2);
            }
        } while (!is_array($openOrders));
        foreach($openOrders as $order) {
            if($order["orderID"] == $id) {
                return $order;
            }
        }
        return False;
    }
    public function get_order_book() {
        $orderBook = null;
        do {
            try {
                sleep(1);
                $orderBook = $this->bitmex->getOrderBook(1, $this->symbol);
            } catch (Exception $e) {
                $this->log->error("failed to submit", ['error'=>$e]);
                sleep(2);
            }
        } while (!is_array($orderBook));
        return $orderBook;
    }

    public function get_limit_price($side) {
        $orderBook = $this->get_order_book();
        foreach ($orderBook as $book) {
            if ($book["side"] == $side) {
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
    public function num_of_closing_orders() {
        $openOrders= $this->get_open_orders();
        $num = 0;
        foreach($openOrders as $order) {
            if ($order["ordType"] == "Limit" and $order["side"] == $this->get_opposite_trade_side()) {
                $num += 1;
            }
        }
        return $num;
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
         $ticker = False;

         $lastTicker = $this->get_ticker()['last'];
         $this->log->info("Decided to limit close the position",["ticker"=>$lastTicker]);
         $order = $this->true_create_order('Limit', $this->get_opposite_trade_side(), $this->amount, $this->get_limit_price($this->get_opposite_trade_side()) + $this->leap);
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
                 $this->true_edit($order["orderID"], $this->get_limit_price($this->get_opposite_trade_side()) + $this->leap, null, null);
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
         $this->amount = abs($this->are_open_positions()['currentQty']);
         sleep(2);
         $this->true_create_order('Market', $this->get_opposite_trade_side(), $this->amount, null);
     }

    public function scalp_open_and_manage() {
        if (file_exists($this->tradeFile)) {
            return false;
        }
        shell_exec('touch '.$this->tradeFile);
        $wallet = False;
        $numOfLimitOrders = 3;
        $compoundVisit = False;
        $stopCounter = 0;

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
            $price = $this->get_limit_price($this->side);
            if ($ticker <= $lastCandle['low']) {
                $this->marketStop = $price -2*$this->stopLossInterval;
                $this->stopLoss = array($ticker - $this->stopLossInterval, $price);
                $this->log->info("updating Stop loss as low price bigger than last price.",["stop => "=>$this->stopLoss, "marketStop => "=>$this->marketStop]);
            }
        }
        elseif ($this->side == "Sell" and $ticker >= $lastCandle['close']) {
            $price = $this->get_limit_price($this->side);
            if ($ticker >= $lastCandle['high']) {
                $this->marketStop = $price + 2*$this->stopLossInterval;
                $this->stopLoss = array($ticker + $this->stopLossInterval, $price);
                $this->log->info("updating Stop loss as high price smaller than last price",["stop => "=>$this->stopLoss, "marketStop => "=>$this->marketStop]);
            }
        }
        else {
            $price = $lastCandle['close'];
        }

        if (!$this->true_create_order('Limit', $this->side, $this->initialAmount, $price)) {
            shell_exec("rm ".$this->tradeFile);
            $this->log->error("Failed to create order", ['side'=>$this->side]);
            return False;
        }
        if (!$this->verify_limit_order()) {
            $this->log->error("limit order was not filled thus canceling",["timeframe"=>$this->timeFrame]);
            $this->true_cancel_all_orders();
            return False;
        }
        $this->log->info("limit Order got filled",['fill'=>True]);

        $this->log->info("Targets are: ", ['targets'=>$this->targets]);
        $this->log->info("stopLoss are", ['stopLoss'=>$this->stopLoss]);
        $stop = $this->stopLoss[$stopCounter];
        sleep(2);
        foreach ($emas as $key=>$target) {
            $order = $this->true_create_order('Limit', $this->get_opposite_trade_side(), intval($this->initialAmount/$numOfLimitOrders), round($target, $this->priceRounds[$this->symbol]));
            $this->targets[$key] = array($order['orderID'], round($target, $this->priceRounds[$this->symbol]));
            sleep(2);
        }
        $this->amount = abs($this->are_open_positions()['currentQty']);//amount gets updated for the first time.
        $openOrders = $this->are_open_orders();
        sleep(2);
        do {
            if (!$this->is_limit()) { // does the function need to close?
                $this->log->info("No Limit orders found", ["limit"=>$this->is_limit()]);
                break;
            } else {
                if ($numOfLimitOrders > $this->num_of_closing_orders()) { // This checks if a stopLoss point needs to be changed.
                    $numOfLimitOrders = $this->num_of_closing_orders();
                    sleep(2);
                    $ticker = $this->get_ticker()['last'];
                    $this->log->info("Limit order was filled.", ["current ticker"=>$ticker]);
                    if ($stopCounter == 0) {
                        if ($ticker > $this->stopLoss[1] and $this->side == "Sell" or $ticker < $this->stopLoss[1] and $this->side == "Buy") {
                            $this->log->info("cannot change stop point to ".$this->stopLoss[1]." as it will be triggered immidietly.", ["price=>"=>$ticker, "stop=>"=>$stop]);
                            $lastCandle = json_decode(file_get_contents("XBTUSD_historical.json"))[1];
                            if ($lastCandle->high < $this->stopLoss[0] and $this->side == "Sell" or $lastCandle->low > $this->stopLoss[0] and $this->side == "Buy") {
                                $this->stopLoss[0] = $this->is_buy() ? $lastCandle->low:$lastCandle->high;
                                $stop = $this->stopLoss[0];
                                $this->log->info("changing the stop loss to last candle", ["stoploss"=>$this->stopLoss, "lastCandle"=>$lastCandle]);
                            }

                        } else {
                            $stopCounter = 1;
                            $stop = $this->stopLoss[$stopCounter];
                            $this->log->info("stop point has changed", ["new Stop"=>$stop, "stop Counter"=>$stopCounter]);
                        }
                    }
                }
            }
            sleep(2);
            $tmpOrders = $this->are_open_orders();
            if ($tmpOrders != $openOrders) {
                $openOrders = $tmpOrders;
                $this->log->info("Open Orders have changed or updated.", ["openOrders"=>$openOrders]);
            }
            sleep(2);
            $ticker = $this->get_ticker()['last'];
            if ($ticker > $stop and $this->side == "Sell" or $ticker < $stop and $this->side == "Buy") {//closing the trade
                if ($this->maxCompunds == 0 and $numOfLimitOrders != 3 or $numOfLimitOrders < 3) {//stop Counter is the variable that determines if stop loss moved to a closer point with price
                    $this->log->info("closing the position as it reached threshold stop:".$stop,["ticker"=>$ticker]);
                    $this->true_cancel_all_orders();
                    sleep(2);
                    $this->amount = abs($this->are_open_positions()['currentQty']);
                    sleep(2);
                    if ($this->amount != 0) {
                        $this->limitCloseOrElse();
                    } else {
                        $this->log->info("position is already closed exiting",["amount"=>$this->amount]);
                        break;
                    }
                    sleep(2);
                }
                elseif ($compoundVisit == False and $this->maxCompunds > 0) {// or else compounding
                    $this->log->info("position will compound now as it reached threshold stop:".$stop,["ticker"=>$ticker]);
                    $this->true_create_order('Limit', $this->side, $this->initialAmount, $this->get_limit_price($this->side) + $this->leap);
                    $compoundVisit = True;
                    sleep(2);
                }
            }
            sleep(2);
            $scalpInfo = json_decode(file_get_contents($this->symbol.self::SCALP_PATH));
            $scalpInfo = json_decode(json_encode($scalpInfo), true);
            $emas = $scalpInfo['emas'];
            foreach ($emas as $key=>$target) {
                if (!$this->get_open_order_by_id($this->targets[$key][0])) {//Target does not exists
                    continue;
                }
                $target = round($target, $this->priceRounds[$this->symbol]);
                if ($target != $this->targets[$key][1]) {
                    $this->log->info("Updating ".$key." as it has changed",[$target]);
                    $ticker = $this->get_ticker()['last'];
                    sleep(2);
                    $price = $target;
                    if ($ticker < $target and $this->side == "Sell" or $ticker > $target and $this->side == "Buy") {
                        $this->log->info("Traget price below or above ticker.",[$ticker]);
                        $price = $this->get_limit_price($this->get_opposite_trade_side());
                    }
                    $this->true_edit($this->targets[$key][0], $price, null, null);
                    $this->targets[$key][1] = $target;
                    sleep(2);
                }
            }
            $position = $this->are_open_positions();
            $newAmount = abs($position['currentQty']);
            sleep(2);
            if ($newAmount < $this->amount) {
                $this->amount = $newAmount;
            }
            elseif ($newAmount > $this->amount) {
                $this->amount = $newAmount;
                $this->log->info("compound was succesfull, updating the targets and stopLoss.", ["newAmount"=>$newAmount]);
                $price = $position["avgEntryPrice"];
                $lastPrice = $this->get_ticker()['last'];
                sleep(2);
                if ($this->side == "Buy") {
                    $this->marketStop = $lastPrice -2*$this->stopLossInterval;
                    $this->stopLoss = array($lastPrice - $this->stopLossInterval, $price);
                }
                elseif ($this->side == "Sell") {
                    $this->marketStop = $lastPrice +2*$this->stopLossInterval;
                    $this->stopLoss = array($lastPrice + $this->stopLossInterval, $price);
                }
                $this->log->info("new stopLoss been set acording to ticker:".$lastPrice." and interval:".$this->stopLossInterval, ['stopLoss'=>$this->stopLoss]);
                $stop = $this->stopLoss[0];
                $newAmount = intval($this->amount/$numOfLimitOrders);
                $this->log->info("Updating targets as they have changed.",["Target Amount"=>$newAmount]);
                foreach ($this->targets as $target) {
                    $this->true_edit($target[0], null, $newAmount, null);
                    sleep(2);
                }
                $this->maxCompunds -= 1;
                $compoundVisit = False;
            } else {
                continue;
            }
        } while ($this->amount != 0);
        if ($this->is_limit()) { // is there more limits as result of compound.
            $this->log->info("more limit orders were found as a result of compound, canceling them.", ["limit"=>$this->is_limit()]);
            sleep(1);
            $this->true_cancel_all_orders();
            sleep(2);
        }

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
        sleep(4);

        if (microtime(true) - $this->startTime < 60) {
            $this->log->info("waiting the remaining of the timeframe to finish",['timeframe'=>$this->timeFrame]);
            do {
                sleep(1);
            } while (microtime(true) - $this->startTime < 60);
        }
        return True;
    }
    public function trade_open() {
        if (file_exists($this->tradeFile)) {
            return false;
        }
        shell_exec('touch '.$this->tradeFile);
        $this->log->info('---------------------------------- New Order ----------------------------------', ['Sepparator'=>'---']);
        $price = $this->get_limit_price($this->side);
        $order = $this->true_create_order('Limit', $this->side, $this->initialAmount, $price);
        if (!$order) {
            shell_exec("rm ".$this->tradeFile);
            $this->log->error("Failed to create order", ['side'=>$this->side]);
            return;
        }
        if (!$this->verify_limit_order()) {
            $this->log->error("limit order was not filled thus canceling",["timeframe"=>$this->timeFrame]);
            $this->true_cancel_order($order['orderID']);
            return;
        }
        sleep(2);
        $this->amount = abs($this->are_open_positions()['currentQty']);
        sleep(2);
        if ($stopOrder = $this->is_stop()) {
            $this->true_edit($stopOrder, null, $this->amount, $this->stopPx);
        } else {
            $this->true_create_order('Stop', $this->get_opposite_trade_side(), $this->amount, null, $this->stopPx);
        }
        if (microtime(true) - $this->startTime < 60) {
            $this->log->info("waiting the remaining of the timeframe to finish",['timeframe'=>$this->timeFrame]);
            do {
                sleep(1);
            } while (microtime(true) - $this->startTime < 60);
        }
        return True;
    }
}

?>
