<?php

namespace bs;

class ShipStationConsumer {
    private $baseUrl;
    private $defaultHeader;

    public function __construct($baseUrl, $apiKey, $apiSecret)
    {
        $this->baseUrl = $baseUrl;
        $this->defaultHeader =
            ['Authorization: Basic ' . base64_encode("$apiKey:$apiSecret")];
    }

    /**
     * https://www.shipstation.com/docs/api/shipments/list/
    */
    public function getShipments($createDateStart, $createDateEnd, $page, $pageSize)
    {
        $url = sprintf(
            $this->baseUrl . 
            '/shipments?createDateStart=%s&createDateEnd=%s&includeShipmentItems=true&page=%d&pageSize=%d&sortBy=CreateDate&sortDir=DESC',
            $createDateStart,
            $createDateEnd,
            (int)$page,
            (int)$pageSize
        );

        return (new CurlWrapper())->get($url, $this->defaultHeader);
    }
}