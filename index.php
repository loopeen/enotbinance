<?php

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

foreach ($methods as $method) { //перебираем каждый метод из списка и получаем по нему объявления
    $methodAdsParseStartTime = microtime(true); //засекаем время начала парсинга объявлений по этому методу
    $ads = $parser->getAds([$method["identifier"]], 30); //получаем 30 штук объявлений по этому методу
    $safetyAd = $parser->getSafetyAdFromList($ads); //получаем одно надежное объявление из 30
    if (isset($safetyAd["price"])) $parsedAds[$method["identifier"]] = $safetyAd; //проверка на существование объявления

    $methodAdsParseEndTime = microtime(true); //засекаем время конца парсинга объявлений по этому методу
    print_r("\n\nПарсинг объявлений по методу " . $method["identifier"] . " длился " . round($methodAdsParseEndTime - $methodAdsParseStartTime, 2) . " сек."); //выводим время парсинга объявлений по этому методу
}

$endTime = microtime(true); //засекаем время конца парсинга

print_r($parsedAds); //выводим объявлений
print_r("\n\nПарсинг объявлений длился " . round($endTime - $startTime, 2) . " сек."); //выводим время парсинга
