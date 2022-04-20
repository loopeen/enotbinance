<?php

require './vendor/autoload.php';
require_once "binance_parser.php";


/* использование прокси */

// $parser = new BinanceParser([
//     "ip" => '127.0.0.1', //ip прокси
//     'port' => 5000, //порт прокси
//     'login' => 'admin', //login от прокси
//     'password' => 'admin', //пароль от прокси
//     'type' => 'socks5' // socks5 или https
// ]);


$parser = new BinanceParser(); //инициализация класса


$parsedAds = []; //здесь будут собраны объявления
$startTime = microtime(true); //засекаем время начала парсинга

$methods = $parser->getMethods(); //получаем список методов

$ads = $parser->getAds(array_map(function ($val){
    return $val["identifier"];
}, $methods), 30); //получаем 30 штук объявлений по каждому методу

$endTime = microtime(true); //засекаем время конца парсинга

print_r($parser->adsList); //выводим объявления
print_r("\n\nПарсинг объявлений длился " . round($endTime - $startTime, 2) . " сек."); //выводим время парсинга
