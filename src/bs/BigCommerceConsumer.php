<?php

namespace bs;
use bs\CurlWrapper;

class BigCommerceConsumer {
    private $baseUrl;
    private $storeHash;
    private $defaultHeader;

    public function __construct($baseUrl, $storeHash, $authToken)
    {
        $this->baseUrl = $baseUrl;
        $this->storeHash = $storeHash;
        $this->defaultHeader = [
            "x-auth-token: $authToken",
            'Accept: application/json'
        ];
    }

    /**
     * https://developer.bigcommerce.com/api-reference/store-management/customers-v3/customers/customersget
     */
    public function getCustomersInGroup($id, $page, $pageSize)
    {
        $url = sprintf(
            $this->baseUrl . 
            "/%s/v3/customers?page=%d&limit=%d&customer_group_id:in=%d",
            $this->storeHash,
            (int)$page,
            (int)$pageSize,
            (int)$id
        );

        return (new CurlWrapper())->get($url, $this->defaultHeader);
    }

    /**
     * https://developer.bigcommerce.com/api-reference/store-management/orders/orders/getallorders
     */
    public function getOrders($page, $pageSize)
    {
        $url = sprintf(
            $this->baseUrl . 
            "/%s/v2/orders?page=%d&limit=%d&sort=date_created:desc",
            $this->storeHash,
            (int)$page,
            (int)$pageSize
        );

        return (new CurlWrapper())->get($url, $this->defaultHeader);
    }
}