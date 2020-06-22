<?php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

function create_logger($logPath) {
    $startTime = microtime(true);
    $log = new Logger('BOT');
    $log->pushHandler(new StreamHandler($logPath, Logger::DEBUG));
    $log->pushProcessor(function ($entry) use($startTime) {
        $endTime = microtime(true);
        $s = $endTime - $startTime;
        $h = floor($s / 3600);
        $s -= $h * 3600;
        $m = floor($s / 60);
        $s -= $m * 60;
        $entry['extra']['Time Elapsed'] = $h.':'.sprintf('%02d', $m).':'.sprintf('%02d', $s);
        return $entry;
    });
    return $log;
}
?>
