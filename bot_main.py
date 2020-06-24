import json
import pandas as pd
from time import sleep
import os

symbols = ["XBTUSD"];
amount = 9
historicalFile ="_historical.json";
outPutFile = "_scalp_info.json"

while (1):
    for symbol in symbols:
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
        if data[-1]['close'] < float(band_low.iloc[-1]):
            os.system("php Trader.php {} Buy {} &".format(symbol, amount))
            sleep(5)
        if data[-1]['close'] > float(band_high.iloc[-1]):
            os.system("php Trader.php {} Sell {} &".format(symbol, amount))
            sleep(5)

        sleep(1)
