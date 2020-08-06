import json
import pandas as pd
import datetime
from time import sleep
import os
import sys
from botlogger import Logger
botLogger = Logger()
logger = botLogger.init_logger()
logger.info('Boti Trading system initiated')

os.system("php historicals.php &")
sleep(2)
with open('conf.json') as json_file:
    config = json.load(json_file)

amount = config['amount']
closeInterval = config['closeInterval']
timeFrame = config['timeframe']
historicalFile ="_historical.json";
outPutFile = "_scalp_info.json"

symbol = sys.argv[1]
side   = sys.argv[2]
times  = sys.argv[3]
stopPx = sys.argv[4]
strategy = sys.argv[5]
tradeFile = 'scalp_{}'.format(symbol)
counter = 0
print("looking for {} Trades of {} {}".format(times, side, symbol))
logger.info("looking for {} Trades of {} {}".format(times, side, symbol))
data = False
with open("{}{}".format(symbol, historicalFile)) as json_file:
    data = json.load(json_file)
    data.reverse()
    data.pop()
while (counter < int(times)):
    with open("{}{}".format(symbol, historicalFile)) as json_file:
        tmp_data = json.load(json_file)
        tmp_data.reverse()
        tmp_data.pop()
    if tmp_data == data:
        continue
    data = tmp_data
    data_dict = {'timestamp': [d['timestamp'] for d in data], 'close': [d['close'] for d in data]}
    df = pd.DataFrame(data_dict, columns=['timestamp','close'])
    ema20 = df.rolling(20).mean()
    ema8 = df.rolling(8).mean()
    ema12 = df.rolling(12).mean()
    ema26 = df.rolling(26).mean()
    std = df.rolling(20).std()
    band_high = ema20 + 2*std
    band_low = ema20 - 2*std
    emas = {'ema8':float(ema8.iloc[-1]), 'ema12':float(ema12.iloc[-1]), 'ema26':float(ema26.iloc[-1])}
    scalp_info = {'emas': emas, 'bands': [float(band_low.iloc[-1]), float(band_high.iloc[-1])], 'last': data[-1]}
    with open('{}{}'.format(symbol,outPutFile), 'w') as json_file:
        json.dump(scalp_info, json_file)
    if  float(band_low.iloc[-1]) - float(data[-1]['close']) >= closeInterval[symbol]["Buy"]:
        if side == "Buy" or side == "Both":
            if not os.path.exists(tradeFile) and datetime.datetime.now().minute%timeFrame == 0:
                os.system("php CreateTrade.php {} Buy {} {} {} &".format(symbol, amount, stopPx, strategy))
                logger.info("Buy Trade initiated diff is: {} and interval is: {}".format(float(band_low.iloc[-1]) - float(data[-1]['close']), closeInterval[symbol]["Buy"]))
                logger.info("current indicators bands: {} and candle: {}".format(scalp_info['bands'], data[-1]))
                counter = counter + 1
                print("number of executions is {}".format(counter))
    if float(data[-1]['close']) - float(band_high.iloc[-1]) >= closeInterval[symbol]["Sell"]:
        if side == "Sell" or side == "Both":
            if not os.path.exists(tradeFile) and datetime.datetime.now().minute%timeFrame == 0:
                os.system("php CreateTrade.php {} Sell {} {} {} &".format(symbol, amount, stopPx, strategy))
                logger.info("Sell Trade initiated diff is: {} and interval is: {}".format(float(data[-1]['close']) - float(band_high.iloc[-1]), closeInterval[symbol]["Buy"]))
                logger.info("current indicators bands: {} and candle: {}".format(scalp_info['bands'], data[-1]))
                counter = counter + 1
                logger.info("number of executions is {}".format(counter))
                print("number of executions is {}".format(counter))
    logger.info("current indicators bands: {} and candle: {}".format(scalp_info['bands'], data[-1]))

while (os.path.exists(tradeFile)):
    print("continuting until last trade is over")
    with open("{}{}".format(symbol, historicalFile)) as json_file:
        data = json.load(json_file)
    data.reverse()
    data_dict = {'timestamp': [d['timestamp'] for d in data], 'close': [d['close'] for d in data]}
    df = pd.DataFrame(data_dict, columns=['timestamp','close'])
    ema20 = df.rolling(20).mean()
    ema8 = df.rolling(8).mean()
    ema12 = df.rolling(12).mean()
    ema26 = df.rolling(26).mean()
    std = df.rolling(20).std()
    band_high = ema20 + 2*std
    band_low = ema20 - 2*std
    emas = {'ema8':float(ema8.iloc[-1]), 'ema12':float(ema12.iloc[-1]), 'ema26':float(ema26.iloc[-1])}
    scalp_info = {'emas': emas, 'bands': [float(band_low.iloc[-1]), float(band_high.iloc[-1])], 'last': data[-1]}
    with open('{}{}'.format(symbol,outPutFile), 'w') as json_file:
        json.dump(scalp_info, json_file)
    sleep(1)

with open('historicalsPid.json') as json_file:
    pid = json.load(json_file)
os.system("kill -9 {}".format(pid))
print("we are finished")




