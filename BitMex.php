<?php

/*
 * BitMex PHP REST API
 */

class BitMex {

  //const API_URL = 'https://testnet.bitmex.com';
  const API_PATH = '/api/v1/';

  private $apiKey;
  private $apiSecret;
  private $apiUrl;


  private $ch;

  public $error;
  public $printErrors = false;
  public $errorCode;
  public $errorMessage;

  /*
   * @param string $apiKey    API Key
   * @param string $apiSecret API Secret
   */

  public function __construct($apiKey = '', $apiSecret = '', $testNet = false) {

    $this->apiKey = $apiKey;
    $this->apiSecret = $apiSecret;
    $this->apiUrl = $testNet == true ? 'https://testnet.bitmex.com' : 'https://www.bitmex.com';

    $this->curlInit();

  }

  /*
   * Public
   */

  /*
   * Get Ticker
   *
   * @return ticker array
   */

  public function getTicker($symbol) {

    $data['function'] = "instrument";
    $data['params'] = array(
      "symbol" => $symbol
    );

    $return = $this->publicQuery($data);

    if(!$return || count($return) != 1 || !isset($return[0]['symbol'])) return false;

    $ticker = array(
      "symbol" => $return[0]['symbol'],
      "mark"   => $return[0]['markPrice'],
      "last"   => $return[0]['lastPrice'],
      "bid"    => $return[0]['bidPrice'],
      "ask"    => $return[0]['askPrice'],
      "high"   => $return[0]['highPrice'],
      "low"    => $return[0]['lowPrice'],
      "volume" => $return[0]['volume']
    );
    if ($return==false) {
        throw new Exception("Failed to get a ticker.");
    }
    elseif (is_array($ticker) and in_array($symbol, $ticker)) {
        foreach($ticker as $element) {
            if (is_null($element)) {
                throw new Exception("Failed to get a ticker, corrupted elemets: ".$ticker);
            }
        }
        return $ticker;
    }
    else {
        throw new Exception("Failed to get a ticker, probably network with bitmex server.");
    }
  }
  public function getXrateLimit() {
      $method = "GET";
      $function = "position";
      $data['params'] = array(
      );
      $params = http_build_query($data['params']);
      $path = self::API_PATH . $function;
      $url = $this->apiUrl . self::API_PATH . $function;
      $url .= "?" . $params;
      $path .= "?" . $params;
      $nonce = $this->generateNonce();
      $post = "";
      $sign = hash_hmac('sha256', $method.$path.$nonce.$post, $this->apiSecret);

      $headers = array();

      $headers[] = "api-signature: $sign";
      $headers[] = "api-key: {$this->apiKey}";
      $headers[] = "api-nonce: $nonce";

      $headers[] = 'Connection: Keep-Alive';
      $headers[] = 'Keep-Alive: 90';

      curl_reset($this->ch);
      curl_setopt($this->ch, CURLOPT_URL, $url);
      curl_setopt($this->ch, CURLOPT_HTTPHEADER, $headers);
      curl_setopt($this->ch, CURLOPT_SSL_VERIFYPEER , false);
      curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($this->ch, CURLOPT_HEADER, 1);
      $return = curl_exec($this->ch);
      $limitArray = preg_split("/eset/",$return);
      $xLimit = preg_split("/imit: 60/",$limitArray[0]);
      preg_match_all('!\d+!', trim($xLimit[1]), $matches);
      return intval($matches[0][0]);
  }





  /*
   * Get Candles
   *
   * Get candles history
   *
   * @param $timeFrame can be 1m 5m 1h
   * @param $count candles count
   * @param $offset timestamp conversion offset in seconds
   *
   * @return candles array (from past to present)
   */

  public function getCandles($timeFrame,$count, $symbol, $reverse = "true", $offset = 0) {

    $data['function'] = "trade/bucketed";
    $data['params'] = array(
      "symbol" => $symbol,
      "count" => $count,
      "binSize" => $timeFrame,
      "partial" => "true",
      "reverse" => $reverse
    );

    $return = $this->publicQuery($data);
    if ($return == false) {
         throw new Exception("Failed to get candles.");
    }
    $candles = array();
    $candleI = 0;
    // Converting
    foreach($return as $item) {

      $time = strtotime($item['timestamp']) + $offset; // Unix time stamp

      $candles[$candleI] = array(
          'timestamp' => date('Y-m-d H:i:s',$time), // Local time human-readable time stamp
          'time' => $time,
          'open' => $item['open'],
          'high' => $item['high'],
          'close' => $item['close'],
          'low' => $item['low']
      );
      $candleI++;
    }
    return $candles;

  }

  /*
   * Get Order
   *
   * Get order by order ID
   *
   * @return array or false
   */

  public function getOrder($orderID,$count = 100, $symbol) {

    $data['method'] = "GET";
    $data['function'] = "order";
    $data['params'] = array(
      "symbol" => $symbol,
      "count" => $count,
      "reverse" => "true"
    );

    $orders = $this->authQuery($data);

    foreach($orders as $order) {
      if($order['orderID'] == $orderID) {
        return $order;
      }
    }

    return false;

  }

  /*
   * Get Orders
   *
   * Get last 100 orders
   *
   * @return orders array (from the past to the present)
   */

  public function getOrders($count = 100, $symbol) {

    $data['method'] = "GET";
    $data['function'] = "order";
    $data['params'] = array(
      "symbol" => $symbol,
      "count" => $count,
      "reverse" => "true"
    );

    return array_reverse($this->authQuery($data));
  }

  /*
   * Get Open Orders
   *
   * Get open orders from the last 100 orders
   *
   * @return open orders array
   */

  public function getOpenOrders($symbol) {

    $data['method'] = "GET";
    $data['function'] = "order";
    $data['params'] = array(
      "symbol" => $symbol,
      "reverse" => "true"
    );

    $orders = $this->authQuery($data);
    if (!$orders) {
        throw new Exception("Failed to create an order");
    }

    $openOrders = array();
    foreach($orders as $order) {
      if($order['ordStatus'] == 'New' || $order['ordStatus'] == 'PartiallyFilled') $openOrders[] = $order;
    }

    return $openOrders;

  }

  /*
   * Get Open Positions
   *
   * Get all your open positions
   *
   * @return open positions array
   */

  public function getOpenPositions() {

    $data['method'] = "GET";
    $data['function'] = "position";
    $data['params'] = array(
    );
    $positions = $this->authQuery($data);
    if (!$positions) {
        throw new Exception("Failed to retrieve Open positions");
    }

    $openPositions = array();
    foreach($positions as $position) {
      if(isset($position['isOpen']) && $position['isOpen'] == true) {
        $openPositions[] = $position;
      }
    }

    return $openPositions;
  }

  public function getPosition($symbol, $count) {
    $data['method'] = "GET";
    $data['function'] = "position?filter=";
    $data['params'] = array(
    );
    $filter = rawurlencode(json_encode(array("symbol"=>$symbol))).'&count='.$count;
    $data['function'] = $data['function'].$filter;
    $positions = $this->authQuery($data);
    return $positions;
  }

  /*
   * Close Position
   *
   * Close open position
   *
   * @return array
   */

  public function closePosition($symbol, $price) {

    $data['method'] = "POST";
    $data['function'] = "order/closePosition";
    $data['params'] = array(
      "symbol" => $symbol,
      "price" => $price
    );

    return $this->authQuery($data);
  }

  /*
   * Edit Order Price
   *
   * Edit you open order price
   *
   * @param $orderID    Order ID
   * @param $price      new price
   *
   * @return new order array
   */

  public function editOrder($orderID,$price, $quantity=null, $stopPx=null) {

    $data['method'] = "PUT";
    $data['function'] = "order";
    $data['params'] = array(
      "orderID" => $orderID,
      "orderQty" => $quantity,
      "stopPx"   => $stopPx,
      "price" => $price
    );

    return $this->authQuery($data);
  }

  public function bulkEditOrders($orderIDs,$prices, $quantities=null, $stopPxs=null) {

    $data['method'] = "PUT";
    $data['function'] = "order/bulk";
    $counter = 0;
    $orders = array();
    foreach($orderIDs as $id) {
        array_push($orders, array(
            "orderID" => $id,
            "orderQty" => $quantities[$counter],
            "stopPx"   => $stopPxs[$counter],
            "price" => $prices[$counter])
        );
        $counter += 1;
    }

    $data['params'] = $orders;
    return $this->authQuery($data);
  }


  /*
   * Create Order
   *
   * Create new market order
   *
   * @param $type can be "Limit"
   * @param $side can be "Buy" or "Sell"
   * @param $price in BTC or USD
   * @param $quantity should be in USD (number of contracts)
   * @param $maker forces platform to complete your order as a 'maker' only
   *
   * @return new order array
   */

  public function createOrder($symbol, $type, $side, $price, $quantity, $stopPx = null, $maker = false) {

    $data['method'] = "POST";
    $data['function'] = "order";
    $data['params'] = array(
      "symbol"   => $symbol,
      "side"     => $side,
      "price"    => $price,
      "orderQty" => $quantity,
      "ordType"  => $type,
      "stopPx"   => $stopPx
    );

    if($maker) {
      $data['params']['execInst'] = "ParticipateDoNotInitiate";
    }

    $ans = $this->authQuery($data);

    if (!$ans) {
        throw new Exception("Failed to create an order");
    }
    elseif (is_array($ans) and in_array($symbol, $ans)) {
        return $ans;
    }
    else {
        throw new Exception("Failed to create an order probaly network with bitmex server");
    }
  }

  /*
   * Cancel All Open Orders
   *
   * Cancels all of your open orders
   *
   * @param $text is a note to all closed orders
   *
   * @return all closed orders arrays
   */

  public function cancelAllOpenOrders($symbol, $text="") {

    $data['method'] = "DELETE";
    $data['function'] = "order/all";
    $data['params'] = array(
      "symbol" => $symbol,
      "text" => $text
    );
    return $this->authQuery($data);
  }
  public function cancelOpenOrder($orderId, $clOrdID=null, $text="") {

    $data['method'] = "DELETE";
    $data['function'] = "order";
    $data['params'] = array(
      "orderID" => $orderId,
      "clOrdID" => $clOrdID,
      "text" => $text
    );
    return $this->authQuery($data);
  }

  /*
   * Get Wallet
   *
   * Get your account wallet
   *
   * @return array
   */

  public function getWallet() {

    $data['method'] = "GET";
    $data['function'] = "user/walletSummary";
    $data['params'] = array(
      "currency" => "XBt"
    );

    return $this->authQuery($data);
  }

  /*
   * Get Margin
   *
   * Get your account margin
   *
   * @return array
   */

  public function getMargin() {

    $data['method'] = "GET";
    $data['function'] = "user/margin";
    $data['params'] = array(
      "currency" => "XBt"
    );

    return $this->authQuery($data);
  }

  /*
   * Get Order Book
   *
   * Get L2 Order Book
   *
   * @return array
   */

  public function getOrderBook($depth = 25, $symbol) {

    $data['method'] = "GET";
    $data['function'] = "orderBook/L2";
    $data['params'] = array(
      "symbol" => $symbol,
      "depth" => $depth
    );

    return $this->authQuery($data);
  }

  /*
   * Set Leverage
   *
   * Set position leverage
   * $leverage = 0 for cross margin
   *
   * @return array
   */

  public function setLeverage($leverage, $symbol) {

    $data['method'] = "POST";
    $data['function'] = "position/leverage";
    $data['params'] = array(
      "symbol" => $symbol,
      "leverage" => $leverage
    );

    return $this->authQuery($data);
  }

  /*
   * Private
   *
   */

  /*
   * Auth Query
   *
   * Query for authenticated queries only
   *
   * @param $data consists method (GET,POST,DELETE,PUT),function,params
   *
   * @return return array
   */

  private function authQuery($data) {

    $method = $data['method'];
    $function = $data['function'];
    if ($function == "order/bulk") {
        $params = rawurlencode(''.json_encode($data['params']));
    }
    else {
        $params = http_build_query($data['params']);
    }
    $path = self::API_PATH . $function;
    $url = $this->apiUrl . self::API_PATH . $function;
    if($method == "GET" && count($data['params']) >= 1) {
      $url .= "?" . $params;
      $path .= "?" . $params;
    }
    $nonce = $this->generateNonce();
    if($method == "GET") {
      $post = "";
    }
    elseif($function == "order/bulk") {
        $post = "orders=".$params;
    }
    else {
      $post = $params;
    }
    $sign = hash_hmac('sha256', $method.$path.$nonce.$post, $this->apiSecret);

    $headers = array();

    $headers[] = "api-signature: $sign";
    $headers[] = "api-key: {$this->apiKey}";
    $headers[] = "api-nonce: $nonce";

    $headers[] = 'Connection: Keep-Alive';
    $headers[] = 'Keep-Alive: 90';

    curl_reset($this->ch);
    curl_setopt($this->ch, CURLOPT_URL, $url);
    if($data['method'] == "POST") {
      curl_setopt($this->ch, CURLOPT_POST, true);
      curl_setopt($this->ch, CURLOPT_POSTFIELDS, $post);
    }
    if($data['method'] == "DELETE") {
      curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, "DELETE");
      curl_setopt($this->ch, CURLOPT_POSTFIELDS, $post);
      $headers[] = 'X-HTTP-Method-Override: DELETE';
    }
    if($data['method'] == "PUT") {
      curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, "PUT");
      curl_setopt($this->ch, CURLOPT_POSTFIELDS, $post);
      $headers[] = 'X-HTTP-Method-Override: PUT';
    }
    curl_setopt($this->ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($this->ch, CURLOPT_SSL_VERIFYPEER , false);
    curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
    $return = curl_exec($this->ch);

    if(!$return) {
      $this->curlError();
      $this->error = true;
      throw new Exception("Curl failed to execute, general failure");
    }

    $return_decoded = json_decode($return,true);

    if(isset($return_decoded['error'])) {
        $this->platformError($return_decoded);
        $this->error = true;
        throw new Exception($return);
    }

    $this->error = false;
    $this->errorCode = false;
    $this->errorMessage = false;

    return $return_decoded;

  }

  /*
   * Public Query
   *
   * Query for public queries only
   *
   * @param $data consists function,params
   *
   * @return return array
   */

  private function publicQuery($data) {

    $function = $data['function'];
    $params = http_build_query($data['params']);
    $url = $this->apiUrl . self::API_PATH . $function . "?" . $params;;

    $headers = array();

    $headers[] = 'Connection: Keep-Alive';
    $headers[] = 'Keep-Alive: 90';

    curl_reset($this->ch);
    curl_setopt($this->ch, CURLOPT_URL, $url);
    curl_setopt($this->ch, CURLOPT_SSL_VERIFYPEER , false);
    curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($this->ch, CURLOPT_HTTPHEADER, $headers);
    $return = curl_exec($this->ch);

    if(!$return) {
      $this->curlError();
      $this->error = true;
      return false;
    }

    $return = json_decode($return,true);

    if(isset($return['error'])) {
      $this->platformError($return);
      $this->error = true;
      return false;
    }

    $this->error = false;
    $this->errorCode = false;
    $this->errorMessage = false;

    return $return;

  }

  /*
   * Generate Nonce
   *
   * @return string
   */

  private function generateNonce() {

    $nonce = (string) number_format(round(microtime(true) * 100000), 0, '.', '');

    return $nonce;

  }

  /*
   * Curl Init
   *
   * Init curl header to support keep-alive connection
   */

  private function curlInit() {

    $this->ch = curl_init();

  }

  /*
   * Curl Error
   *
   * @return false
   */

  private function curlError() {

    if ($errno = curl_errno($this->ch)) {
      $this->errorCode = $errno;
      $errorMessage = curl_strerror($errno);
      $this->errorMessage = $errorMessage;
      if($this->printErrors) echo "cURL error ({$errno}) : {$errorMessage}\n";
      return true;
    }

    return false;
  }

  /*
   * Platform Error
   *
   * @return false
   */

  private function platformError($return) {

    $this->errorCode = $return['error']['name'];
    $this->errorMessage = $return['error']['message'];
    if($this->printErrors) echo "BitMex error ({$return['error']['name']}) : {$return['error']['message']}\n";

    return true;
  }

}
