<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

class BinanceParser
{

    private const GET_METHODS_URL = "https://p2p.binance.com/bapi/c2c/v2/public/c2c/adv/filter-conditions"; //url api для получения списка методов
    private const GET_ADS_URL = "https://p2p.binance.com/bapi/c2c/v2/friendly/c2c/adv/search"; //url api для получения списка объявлений
    private const GET_SELLER_INFO = "https://p2p.binance.com/bapi/c2c/v2/friendly/c2c/user/profile-and-ads-list?userNo="; //url api для получения информации о селлере
    private const GET_SELLER_REVIEWS_STATS = "https://p2p.binance.com/bapi/c2c/v1/friendly/c2c/review/user-review-statistics"; //url api для получения информации о селлере
    private const BASE_SELLER_URL = "https://p2p.binance.com/ru/advertiserDetail?advertiserNo=";

    public function __construct($proxy = null)
    {
        $this->proxy = is_null($proxy) ? null : ($proxy["ip"] . ":" . $proxy["port"]);
        $this->proxyAuth = is_null($proxy) ? null : ($proxy["login"] . ":" . $proxy["password"]);
        $this->proxyType = is_null($proxy) ? null : $proxy["type"];
        $this->adsList = [];
        $this->client = new Client();
        if ($this->proxy) {
            $proxy = $this->proxyType . "://" . $this->proxyAuth . "@" . $this->proxy;
            $this->client = new Client([
                "proxy" => $proxy
            ]);
        }

    }

    //получение списка методов
    public function getMethods($fiat = "RUB")
    {
        $methods = $this->client->post($this::GET_METHODS_URL, [
            "json" => ["fiat" => $fiat]
        ]);
        $methods = json_decode($methods->getBody()->getContents(), true);
        if ($methods["code"] == 000000) {
            $methodsList = [];
            foreach ($methods["data"]["tradeMethods"] as $method) {
                array_push($methodsList, $method);
            }
            return $methodsList;
        }
    }

    //получение списка объявлений
    public function getAds($payTypes = [], $count = 30, $asset = "USDT", $tradeType = "BUY", $fiat = "RUB")
    {

        $getAdsRequests = function () use ($count, $payTypes, $asset, $tradeType, $fiat) {
            foreach ($payTypes as $payType) {
                for ($i = 0; $i < ceil($count / 10); $i++) {
                    yield function () use ($count, $payType, $asset, $tradeType, $fiat, $i) {
                        return $this->client->postAsync($this::GET_ADS_URL, [
                            "headers" =>  ["Content-Type" => "application/json"],
                            "json" => [
                                "page" => $i + 1,
                                "rows" => 10,
                                "payTypes" => [$payType],
                                "publisherType" => null,
                                "asset" => $asset,
                                "tradeType" => $tradeType,
                                "fiat" => $fiat
                            ]
                        ])->then(function (Response $response) use ($payType) {
                            $json = json_decode($response->getBody()->getContents(), true);
                            if (!isset($this->adsList[$payType])) $this->adsList[$payType] = [];
                            if ($json["code"] == 000000) array_push($this->adsList[$payType], ...$json["data"]);
                            return $response;
                        });
                    };
                }
            }
        };

        $getAdsPool = new Pool($this->client, $getAdsRequests(), [
            'concurrency' => 90,
        ]);
        $promise = $getAdsPool->promise();
        $promise->wait();

        $this->getSellers();

        foreach ($this->adsList as $key1 => $ads) {
            $this->adsList[$key1] = [$this->getSafetyAdFromList($ads)];
            if (!isset($this->adsList[$key1][0]["price"])) $this->adsList[$key1] = [];
        }

        return $this->adsList;
    }

    //получение информации о продавцах
    public function getSellers()
    {
        $getSellersRequests = function () {
            foreach ($this->adsList as $key1 => $ads) {
                foreach ($ads as $key2 => $ad) {
                    yield function () use ($key1, $key2, $ad) {
                        return $this->client->getAsync($this::GET_SELLER_INFO . (isset($ad["advertiser"]["userNo"]) ? $ad["advertiser"]["userNo"] : ''))->then(function (Response $response) use ($key1, $key2) {
                            $json = json_decode($response->getBody()->getContents(), true);
                            if ($json["code"] == 000000) {
                                $this->adsList[$key1][$key2]["sellerInfo"] = $json["data"];
                            }
                            return $response;
                        });
                    };
                }
            }
        };

        $getSellersPool = new Pool($this->client, $getSellersRequests(), [
            'concurrency' => 90,
        ]);

        $promise = $getSellersPool->promise();
        $promise->wait();

        return $this->getSellersReviewsStats();
    }

    //получение статистики отзывов о продавцах
    public function getSellersReviewsStats()
    {
        $getSellerReviewsStatsRequests = function () {
            foreach ($this->adsList as $key1 => $ads) {
                foreach ($ads as $key2 => $ad) {
                    yield function () use ($key1, $key2, $ad) {
                        $userNo = isset($ad["sellerInfo"]["userDetailVo"]["userNo"]) ? $ad["sellerInfo"]["userDetailVo"]["userNo"] : '';
                        return $this->client->postAsync($this::GET_SELLER_REVIEWS_STATS, ["json" => ["userNo" => $userNo]])->then(function (Response $response) use ($key1, $key2) {
                            $json = json_decode($response->getBody()->getContents(), true);
                            if ($json["code"] == 000000) {
                                $this->adsList[$key1][$key2]["sellerInfo"]["userDetailVo"]["userStatsRet"]["reviewsStats"] = $json["data"];
                                $this->adsList[$key1][$key2] = $this->formatAd($this->adsList[$key1][$key2]);
                            }
                            return $response;
                        });
                    };
                }
            }
        };

        $getSellerReviewsStatsPool = new Pool($this->client, $getSellerReviewsStatsRequests(), [
            'concurrency' => 90,
        ]);


        $promise = $getSellerReviewsStatsPool->promise();
        $promise->wait();
        return true;
    }

    //форматирование объявления, фильтрация лишней информации
    public function formatAd($ad)
    {
        $sellerStats = $ad["sellerInfo"]["userDetailVo"]["userStatsRet"];
        return [
            "price" => $ad["adv"]["price"], //курс обмена
            "method" => $ad["adv"]["tradeMethods"][0]["tradeMethodName"], //метод обмена
            "seller" => [
                "url" => $this::BASE_SELLER_URL . $ad["advertiser"]["userNo"], //ссылка на продавца
                "avgReleaseTime" => round($sellerStats["avgReleaseTimeOfLatest30day"] / 60, 2), //среднее время перевода
                "regDays" => $sellerStats["registerDays"], //кол-во зарегистрированных дней
                "riskPer" => round(100 - $ad["sellerInfo"]["userDetailVo"]["monthFinishRate"] * 100, 2), //процент риска
                "reviews" => [
                    "positive" => $sellerStats["reviewsStats"]["positiveCount"], //кол-во позитивных отзывов
                    "negative" => $sellerStats["reviewsStats"]["negativeCount"] //кол-во негативных отзывов
                ]
            ]
        ];
    }

    //получить одно надежное объявление из списка
    public function getSafetyAdFromList($ads)
    {
        $lastAd = [
            "seller" => [
                "avgReleaseTime" => null,
                "regDays" => null,
                "reviews" => [
                    "positive" => null,
                    "negative" => null,
                    "rate" => null
                ]
            ]
        ];

        foreach ($ads as $ad) {
            $ad["seller"]["reviews"]["rate"] = $this->wilsonScore($ad["seller"]["reviews"]["positive"], $ad["seller"]["reviews"]["negative"]);
            if (!is_null($lastAd["seller"]["avgReleaseTime"])) {
                if ($ad["price"] > $lastAd["price"] && $ad["seller"]["reviews"]["rate"] < $lastAd["seller"]["reviews"]["rate"] && $ad["seller"]["regDays"] < $lastAd["seller"]["regDays"]) continue;
            }
            $lastAd = $ad;
        }
        return $lastAd;
    }

    //формула расчета рейтинга на основе позитивных и негативных отзывов
    private function wilsonScore($up, $down)
    {
        if (!$up) return -$down;
        $n = $up + $down;
        $z = 1.64485;
        $phat = $up / $n;
        return ($phat + $z * $z / (2 * $n) - $z * sqrt(($phat * (1 - $phat) + $z * $z / (4 * $n)) / $n)) / (1 + $z * $z / $n);
    }
}
