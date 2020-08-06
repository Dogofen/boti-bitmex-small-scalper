import logging
from os.path import expanduser


class Logger(object):
    NAME = 'bot'
    FORMAT = '%(asctime)s:%(levelname)s: %(message)s'

    home = expanduser('~')
    filename = '%s/git/boti-bitmex-small-scalper/bot.log' % home

    def __init__(self):
        logging.basicConfig(format=self.FORMAT, filename=self.filename, level=logging.INFO)

    def init_logger(self):
        return logging.getLogger(self.NAME)
