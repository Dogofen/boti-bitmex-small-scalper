<?php

require __DIR__ . '/vendor/autoload.php';
require_once("BitMex.php");
require_once("log.php");


class Trader {

    const TICKER_PATH = '.ticker';
    const SCALP_PATH = '_scalp_info.json';
    const OPEN_POSITIONS = 'openPositions.txt';
    const OPEN_ORDERS = '_openOrders.txt';
    private $log;
    private $bitmex;

    private $strategy = 'scalp';
    private $symbol;
    private $stopLossInterval;
    private $targets;
    private $amount;
    private $env;
    private $tradeFile;
    private $timeFrame;
    private $startTime;


    public function __construct($symbol, $side, $amount) {
        $this->tradeFile = $this->strategy."_".$side.'_'.$symbol;
        if (file_exists($this->tradeFile)) {
            return;
        }
        $this->startTime = microtime(true);
        $config = include('config.php');
        $this->bitmex = new BitMex($config['key'], $config['secret'], $config['testnet']);
        $this->timeFrame = $config['timeframe'];
        $this->log = create_logger(getcwd().'/scalp.log');
        $this->priceRounds = $config['priceRounds'];
        try {
            $this->bitmex->setLeverage($config['leverage'], $this->symbol);
        } catch (Exception $e) {
            $this->log->error("Network failure to set leverage.", ["continue"=>True]);
        }
        $this->symbol = $symbol;
        $scalpInfo = json_decode(file_get_contents($this->symbol.self::SCALP_PATH));
        $scalpInfo = json_decode(json_encode($scalpInfo), true);
        $this->stopLossInterval = intval($config['stopLoss'][$this->symbol]);
        $lastTicker = $scalpInfo['last'];

        if ($side == "Buy") {
            $this->stopLoss = array($lastTicker['low'] - $this->stopLossInterval, $lastTicker['close'], $lastTicker['close']);
        }
        else {
           $this->stopLoss = array($lastTicker['high'] + $this->stopLossInterval, $lastTicker['close'], $lastTicker['close']);
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

    public function is_buy() {
        return $this->side == 'Buy' ? 1:0;
    }

    public function get_opposite_trade_side() {
        return $this->side == "Buy" ? "Sell": "Buy";
    }

    public function get_ticker() {
        do {
            $ticker = unserialize(file_get_contents(self::TICKER_PATH.$this->symbol.'.txt'));
            if ($ticker['last'] == null) {
                $this->log->error("ticker is error, retrying in 3 seconds", ['ticker'=>$ticker]);
                sleep(3);
            }
        } while ($ticker['last'] == null);
        return $ticker;
    }
    public function true_cancel_all_orders() {
        $result = False;
        $this->log->info("cancelling all open orders.", []);
        do {
            try {
                $result = $this->bitmex->cancelAllOpenOrders($this->symbol);
                sleep(3);
            } catch (Exception $e) {
                $this->log->error("Failed to sumbit", ['error'=>$e]);
                break;
            }
        } while (!is_array($result));
    }

     public function true_edit($orderId, $amount, $stopPx) {
        $result = False;
        $this->log->info("editing order.", ["orderId" => $orderId]);
        do {
            try {
                $result = $this->bitmex->editOrder($this->is_stop(), null, $amount, $stopPx);
                sleep(3);
            } catch (Exception $e) {
                $this->log->error("Failed to sumbit", ['error'=>$e]);
                break;
            }

        } while (!is_array($result));
    }


    public function true_create_order($type, $side, $amount, $price, $stopPx = null) {
        $this->log->info("Sending a Create Order command", ['side'=>$side.' '.$amount.' contracts']);
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

    public function are_open_positions() {
        $openPositions = unserialize(file_get_contents(self::OPEN_POSITIONS));
        foreach($openPositions as $pos) {
            if ($pos["symbol"] == $this->symbol) {
                return $pos;
            }
        }
        return False;
    }
    public function are_open_orders() {
        $openOrders = unserialize(file_get_contents($this->symbol.self::OPEN_ORDERS));
        $return = empty($openOrders) ? False:True;
        return $return;
    }

    public function is_stop() {
        $openOrders= unserialize(file_get_contents($this->symbol.self::OPEN_ORDERS));
        foreach($openOrders as $order) {
            if ($order["ordType"] == "Stop") {
                return $order["orderID"];
            }
        }
        return False;
    }
    public function is_limit() {
        $openOrders = unserialize(file_get_contents($this->symbol.self::OPEN_ORDERS));
        foreach($openOrders as $order) {
            if ($order["ordType"] == "Limit") {
                return True;
            }
        }
        return False;
    }
    public function sum_limit_orders() {
        $openOrders = unserialize(file_get_contents($this->symbol.self::OPEN_ORDERS));
        $sum = 0;
        foreach($openOrders as $order) {
            if ($order["ordType"] == "Limit") {
                $sum += $order["orderQty"];
            }
        }
        return $sum;
    }

    public function verify_limit_order() {
        $start = microtime(true);
        do {
            sleep(1);
            if (date('i', microtime(true)-$start) > 1) {
                return False;
            }
        } while(!$this->are_open_positions());
        return True;
    }

    public function scalp_open_and_manage() {
        if (file_exists($this->tradeFile)) {
            return false;
        }
        shell_exec('touch '.$this->tradeFile);
        $this->log->info('---------------------------------- New Order ----------------------------------', ['Sepparator'=>'---']);
        $percentage = 3;

        $scalpInfo = json_decode(file_get_contents($this->symbol.self::SCALP_PATH));
        $scalpInfo = json_decode(json_encode($scalpInfo), true);
        $lastCandle = $scalpInfo['last'];
        $emas = $scalpInfo['emas'];
        $this->targets = $emas;

        if (!$this->true_create_order('Limit', $this->side, $this->amount, $lastCandle['close'])) {
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
        $this->true_create_order('Stop', $this->get_opposite_trade_side(), $this->amount, null, $this->stopLoss[0]);
        sleep(2);
        foreach ($emas as $key=>$target) {
            $order = $this->true_create_order('Limit', $this->get_opposite_trade_side(), intval($this->amount/$percentage), round($target, $this->priceRounds[$this->symbol]));
            $this->targets[$key] = $order['orderID'];
            sleep(5);
        }
        do {
            if (!$this->is_limit()) {
                $this->log->info("No Limit orders found", ["limit"=>$this->is_limit()]);
                $this->true_cancel_all_orders();
            }
            else {
                if ($this->amount != $this->sum_limit_orders()) {
                    $this->amount = $this->sum_limit_orders();
                    $this->true_edit($this->is_stop(), $this->amount, $this->stopLoss[1]);
                }
            }
            sleep(1);

        } while ($this->is_stop());
        $this->log->info("Trade have finished removing trade File", []);
        if ($this->are_open_orders()) {
            $this->true_cancel_all_orders();
        }
        if ($this->are_open_positions()) {
            $amount = $this->are_open_positions()['currentQty'];
            $this->true_create_order("Market", null, -1*$amount, null);
        }
        sleep(2);
        if (microtime(true) - $this->startTime < $this->timeFrame*60) {
            $this->log->info("waiting the remaining of the timeframe to finish",['timeframe'=>$this->timeFrame]);
            do {
                sleep(1);
            } while (microtime(true) - $this->startTime < $this->timeFrame*60);
        }
        shell_exec("rm ".$this->tradeFile);
    }
}
$trader = New Trader($argv[1], $argv[2], $argv[3]);
$trader->scalp_open_and_manage();
?>
