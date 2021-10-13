<?php

namespace bs;

class ShipStationConsumer {
    private $baseUrl;
    private $defaultHeader;

    /**
     * https://www.shipstation.com/docs/api/requirements/#authentication
     */
    public function __construct($baseUrl, $apiKey, $apiSecret)
    {
        $this->baseUrl = $baseUrl;
        $this->defaultHeader =
            ['Authorization: Basic ' . base64_encode("$apiKey:$apiSecret")];
    }

    /**
     * https://www.shipstation.com/docs/api/shipments/list/
    */
    public function getShipments($createDateStart, $createDateEnd, $beginPage, $endPage, $pageSize)
    {
        $urls = array();
        for ($page=$beginPage; $page <= $endPage; $page++)
        {
            $url = sprintf(
                $this->baseUrl . 
                '/shipments?createDateStart=%s&createDateEnd=%s&includeShipmentItems=true&page=%d&pageSize=%d&sortBy=CreateDate&sortDir=DESC',
                $createDateStart,
                $createDateEnd,
                (int)$page,
                (int)$pageSize
            );
            $urls[$page] = $url;
        }

        return (new CurlWrapper())->multiGet($urls, $this->defaultHeader);
    }
}