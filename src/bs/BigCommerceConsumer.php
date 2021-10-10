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
     * https://developer.bigcommerce.com/api-reference/store-management/orders/order-products/getallorderproducts
     */
    public function getProductsInOrder($orderId, $page, $pageSize)
    {
        $url = sprintf(
            $this->baseUrl . 
            "/%s/v2/orders/%d/products?page=%d&limit=%d",
            $this->storeHash,
            (int)$orderId,
            (int)$page,
            (int)$pageSize
        );

        return (new CurlWrapper())->get($url, $this->defaultHeader);
    }

    public function getProductsInOrders($ordersIds, $page, $pageSize)
    {
        $urls = array();
        foreach ($ordersIds as $orderId)
        {
            $url = sprintf(
                $this->baseUrl . 
                "/%s/v2/orders/%d/products?page=%d&limit=%d",
                $this->storeHash,
                (int)$orderId,
                (int)$page,
                (int)$pageSize
            );
            $urls[$orderId] = $url;
        }

        return (new CurlWrapper())->multiGet($urls, $this->defaultHeader);
    }

    /**
     * https://developer.bigcommerce.com/api-reference/store-management/customers-v3/customers/customersget
     */
    public function getCustomersInGroup($id, $beginPage, $endPage, $pageSize)
    {
        $urls = array();
        for ($page=$beginPage; $page <= $endPage; $page++)
        {
            $url = sprintf(
                $this->baseUrl . 
                "/%s/v3/customers?page=%d&limit=%d&customer_group_id:in=%d",
                $this->storeHash,
                (int)$page,
                (int)$pageSize,
                (int)$id
            );
            $urls[$page] = $url;
        }

        return (new CurlWrapper())->multiGet($urls, $this->defaultHeader);
    }

    /**
     * https://developer.bigcommerce.com/api-reference/store-management/orders/orders/getallorders
     */
    public function getOrders($beginPage, $endPage, $minDateCreated, $maxDateCreated, $pageSize)
    {
        $urls = array();
        for ($page=$beginPage; $page <= $endPage; $page++)
        {
            $url = sprintf(
                $this->baseUrl . 
                "/%s/v2/orders?min_date_created=%s&max_date_created=%s&page=%d&limit=%d&sort=date_created:desc",
                $this->storeHash,
                $minDateCreated,
                $maxDateCreated,
                (int)$beginPage,
                (int)$pageSize
            );
            $urls[$page] = $url;
        }

        return (new CurlWrapper())->multiGet($urls, $this->defaultHeader);

    }
}