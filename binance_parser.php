<?php

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
        $this->proxyType = is_null($proxy) ? null : ($proxy["type"] == "socks5" ? CURLPROXY_SOCKS5 : CURLPROXY_HTTPS);
    }

    //получение списка методов
    public function getMethods($fiat = "RUB")
    {
        $methods = $this->request($this::GET_METHODS_URL, [
            "fiat" => $fiat
        ]);
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
        $adsList = [];

        for ($i = 0; $i < ceil($count / 10); $i++) {
            $ads = $this->request($this::GET_ADS_URL, [
                "page" => $i + 1,
                "rows" => 10,
                "payTypes" => $payTypes,
                "publisherType" => null,
                "asset" => $asset,
                "tradeType" => $tradeType,
                "fiat" => $fiat
            ]);

            if ($ads["code"] == 000000) {
                array_push($adsList, ...$ads["data"]);
            }
        }

        $adsList = array_slice($adsList, 0, $count);

        foreach ($adsList as $key => $ad) {
            $seller = $this->getSeller($ad["advertiser"]["userNo"]);

            $ad["sellerInfo"] = $seller;
            $adsList[$key] = $this->formatAd($ad);
        }

        return $adsList;
    }

    //получение информации о продавце
    public function getSeller($userNo)
    {
        $seller = $this->request($this::GET_SELLER_INFO . $userNo, [], "GET");
        if ($seller["code"] == 000000) {
            $stats = $this->getSellerReviewsStats($userNo);
            $seller["data"]["userDetailVo"]["userStatsRet"]["reviewsStats"] = $stats;
            return $seller["data"];
        }
    }

    //получение статистики отзывов о продавце
    public function getSellerReviewsStats($userNo)
    {
        $stats = $this->request($this::GET_SELLER_REVIEWS_STATS, ["userNo" => $userNo]);
        if ($stats["code"] == 000000) {
            return $stats["data"];
        }
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

    //функция отправки запросов
    private function request($url, $data=[], $method = "POST", $headers = [
        "Content-Type: application/json"
    ])
    {
        $ch = curl_init($url);
        $payload = json_encode($data);
        if ($method == "POST") {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_HEADER, false);
        }
        if ($this->proxy && $this->proxyAuth && $this->proxyType) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $this->proxyAuth);
            curl_setopt($ch, CURLOPT_PROXYTYPE, $this->proxyType); // If expected to call with specific PROXY type
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }
}
