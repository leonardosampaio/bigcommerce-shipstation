<?php
require __DIR__ . '/../vendor/autoload.php';
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use bs\BigCommerceConsumer;
use bs\ShipStationConsumer;
use bs\Configuration;
use bs\Progress;

$applicationConfig = (new Configuration())->getApplication();

if (isset($applicationConfig->error))
{
    die($applicationConfig->error);
}

if (!isset($applicationConfig) ||
    !isset($applicationConfig->adminUser) ||
    !isset($applicationConfig->adminPassword))
{
    die('Invalid application configuration');
}

ini_set('memory_limit', $applicationConfig->memoryLimit);
ini_set('max_execution_time', '0');
date_default_timezone_set($applicationConfig->timezone);

//kill curl threads on connection abort
ob_implicit_flush();

$app = AppFactory::create();

//if in subpath this needs to be different from "/"
if (isset($applicationConfig->serverPath))
{
    $app->setBasePath($applicationConfig->serverPath);
}

//show errors
$app->addErrorMiddleware(true, true, true);

/**
 * Get customers ids from a group id
 */
function getCustomersInGroup($bigCommerceConfig, $groupId, $pageSize, $useCache)
{
    $groupCacheFile = __DIR__.'/../cache/customers-in-group_'.$groupId.'.json';

    $group = array();
    if ($useCache && file_exists($groupCacheFile))
    {
        (new Progress())->update('customers in group ' . $groupId, 100);
        $group = json_decode(
                file_get_contents($groupCacheFile));
    }
    else
    {
        $totalPages = 1;
        $currentPage = 1;
        do
        {
            echo "\n";
            
            $lastPage = 1;
            if ($currentPage > 1)
            {
                $lastPage = $totalPages > ($currentPage + ($bigCommerceConfig->parallelConnections-1)) ?
                    $currentPage + ($bigCommerceConfig->parallelConnections-1) : $totalPages;
            }

            $multiGroup = 
                (new BigCommerceConsumer(
                    $bigCommerceConfig->baseUrl,
                    $bigCommerceConfig->store,
                    $bigCommerceConfig->access_token))
                ->getCustomersInGroup($groupId, $currentPage, $lastPage, $pageSize);
    
            foreach($multiGroup as $page => $groupCustomers)
            {
                foreach($groupCustomers->data as $customer)
                {
                    $group[] = $customer->id;
                }
            }
            
            $totalPages = end($multiGroup)->meta->pagination->total_pages;
            (new Progress())->update('customers in group ' . $groupId, (int)($currentPage / ($totalPages ? $totalPages : 1) * 100));
            $currentPage = $totalPages != 0 ? end($multiGroup)->meta->pagination->current_page + 1 : 1;
    
        } while ($currentPage <= $totalPages);
    
        if ($group)
        {
            file_put_contents(
                $groupCacheFile,
                json_encode($group)
            );
        }
    }
    return $group;
}

/**
 * Get all groups ids by customer id
 */
function getCustomersGroups($bigCommerceConfig, $pageSize, $useCache)
{
    $groupCacheFile = __DIR__.'/../cache/customers-groups.json';

    if ($useCache && file_exists($groupCacheFile))
    {
        return (array)json_decode(
                file_get_contents($groupCacheFile));
    }

    $currentPage = 1;
    $customersGroups = array();
    do
    {
        echo "\n";

        (new Progress())->update('customers in groups', $currentPage);

        $lastPage = $currentPage+($bigCommerceConfig->parallelConnections-1);

        (new Progress())->update('orders', $currentPage);

        $multiGroups = 
            (new BigCommerceConsumer(
                $bigCommerceConfig->baseUrl,
                $bigCommerceConfig->store,
                $bigCommerceConfig->access_token))
            ->getCustomerGroups(
                $currentPage, $lastPage, $pageSize);

        $allPagesHaveGroups = !empty((array)$multiGroups) && isset($multiGroups[$lastPage]);
        foreach($multiGroups as $page => $groups)
        {
            foreach($groups as $group)
            {
                $customers = getCustomersInGroup(
                    $bigCommerceConfig, $group->id, $pageSize, $useCache);
                foreach($customers as $customerId)
                {
                    if (!isset($customersGroups[$customerId]))
                    {
                        $customersGroups[$customerId] = array();
                    }
                    $customersGroups[$customerId][] = $group->id;
                }
            }
        }
        
        $currentPage += $bigCommerceConfig->parallelConnections;

    } while ($allPagesHaveGroups);
    
    if ($customersGroups)
    {
        file_put_contents(
            $groupCacheFile,
            json_encode($customersGroups)
        );
    }

    return $customersGroups;
}

/**
 * Get all shipments between start and end dates
 */
function getShipment($shipStationCommerceConfig, $createDateStart, $createDateEnd, $useCache)
{
    $cacheFile = __DIR__.'/../cache/shipment_'.$createDateStart.'_'.$createDateEnd.'.json';

    if ($useCache && file_exists($cacheFile))
    {
        (new Progress())->update('shipments from orders', 100);
        return json_decode(
                file_get_contents($cacheFile));
    }

    $createDateStart .= '%2000:00:00';
    $createDateEnd .= '%2023:59:59';

    $totalPages = 1;
    $currentPage = 1;
    $allShipments = array();
    do {
        echo "\n";

        $lastPage = 1;
        if ($currentPage > 1)
        {
            $lastPage = $totalPages > ($currentPage + ($shipStationCommerceConfig->parallelConnections-1)) ?
                $currentPage + ($shipStationCommerceConfig->parallelConnections-1) : $totalPages;
        }

        $multiShipments = 
            (new ShipStationConsumer(
                $shipStationCommerceConfig->baseUrl,
                $shipStationCommerceConfig->api_key,
                $shipStationCommerceConfig->api_secret))
            ->getShipments($createDateStart, $createDateEnd, $currentPage, $lastPage, $shipStationCommerceConfig->pageSize);

        foreach($multiShipments as $page => $shipments)
        {
            $allShipments = array_merge($allShipments, $shipments->shipments);
        }

        $totalPages = end($multiShipments)->pages;
        (new Progress())->update('shipments from orders', (int)($currentPage / ($totalPages ? $totalPages : 1) * 100));
        $currentPage = $totalPages != 0 ? end($multiShipments)->page + 1 : 1;

    } while($currentPage <= $totalPages);

    if ($allShipments)
    {
        file_put_contents(
            $cacheFile,
            json_encode($allShipments)
        );
    }

    return $allShipments;
}

/**
 * Get all products from orders ids
 */
function getProducts($bigCommerceConfig, $ordersIds, $useCache)
{
    $totalProducts = array();

    if ($useCache)
    {
        foreach($ordersIds as $k => $orderId)
        {
            $cacheFile = __DIR__.'/../cache/products-in-order_'.$orderId.'.json';

            if (file_exists($cacheFile))
            {
                unset($ordersIds[$k]);
                $totalProducts[$orderId] = 
                    json_decode(
                        file_get_contents($cacheFile));
            }
        }
    }

    //all from cache
    if (!$ordersIds)
    {
        (new Progress())->update('products in orders', 100);
        return $totalProducts;
    }

    $currentIndex = 1;
    do
    {
        echo "\n";

        $ids = array_slice($ordersIds, $currentIndex-1, $bigCommerceConfig->parallelConnections);

        (new Progress())->update('products in orders', (int)(($currentIndex-1) / sizeof($ordersIds) * 100));

        $multiProducts = 
            (new BigCommerceConsumer(
                $bigCommerceConfig->baseUrl,
                $bigCommerceConfig->store,
                $bigCommerceConfig->access_token))
            ->getProductsInOrders(
                $ids, 1, $bigCommerceConfig->pageSize);

        $allPagesHaveProducts = !empty((array)$multiProducts) && isset($multiProducts[end($ids)]);
        foreach($multiProducts as $orderId => $products)
        {
            $productsFiltered = array();
            foreach($products as $product)
            {
                $productFiltered = new stdClass();
                $productFiltered->applied_discounts = $product->applied_discounts;
                $productFiltered->base_cost_price   = $product->base_cost_price;
                $productFiltered->total_ex_tax      = $product->total_ex_tax;
                $productFiltered->total_inc_tax     = $product->total_inc_tax;
                $productFiltered->total_tax         = $product->total_tax;

                $productsFiltered[] = $productFiltered;
            }

            $cacheFile = __DIR__.'/../cache/products-in-order_'.$orderId.'.json';

            if ($productsFiltered)
            {
                file_put_contents(
                    $cacheFile,
                    json_encode($productsFiltered)
                );
            }

            if (!isset($totalProducts[$orderId]))
            {
                $totalProducts[$orderId] = $productsFiltered;
            }
            else
            {
                $totalProducts[$orderId] =
                    array_merge($totalProducts[$orderId], $productsFiltered);
            }
        }
        
        $currentIndex += $bigCommerceConfig->parallelConnections;

    } while ($allPagesHaveProducts && sizeof($ordersIds) > $currentIndex);

    return $totalProducts;
}

$app->get('/orders', function (Request $request, Response $response, $args) {

    session_start();

    (new Progress())->clean();

    if (!isset($_SESSION['user']))
    {
        return $response->withJson(['error'=>'Unauthorized']);
    }

    foreach(['minDateCreated', 'maxDateCreated'] as $requiredField)
    {
        $fieldValue = isset($request->getQueryParams()[$requiredField]) ?
            $request->getQueryParams()[$requiredField] : null;

        if (!$fieldValue)
        {
            return $response->withJson(['error'=>
            "Invalid $requiredField"]);
        }
    }

    //YYYY-MM-DD
    $minDateCreated = date_format(date_create($request->getQueryParams()['minDateCreated'] . ' 00:00:00'), 'c');
    $maxDateCreated = date_format(date_create($request->getQueryParams()['maxDateCreated'] . ' 23:59:59'), 'c');
    if ($minDateCreated > $maxDateCreated)
    {
        return $response->withJson(['error'=>
            "Begin date should be less than end date"]);
    }

    $ordersCacheFile = __DIR__.
        str_replace(':','_','/../cache/orders_'.$minDateCreated.'_'.$maxDateCreated.'.json');

    $ordersUsingCache = isset($request->getQueryParams()['ordersUsingCache']) ?
        $request->getQueryParams()['ordersUsingCache'] == 'true' : null;

    if ($ordersUsingCache && file_exists($ordersCacheFile))
    {
        (new Progress())->clean();
        return $response->withJson(
            json_decode(
                file_get_contents($ordersCacheFile)));
    }

    $bigCommerceConfig = (new Configuration())->getBigCommerce();
    if (isset($bigCommerceConfig->error))
    {
        return $response->withJson($bigCommerceConfig);
    }

    $shipStationCommerceConfig = (new Configuration())->getShipStation();
    if (isset($shipStationCommerceConfig->error))
    {
        return $response->withJson($shipStationCommerceConfig);
    }

    $pageSize = $bigCommerceConfig->pageSize;

    $groupUsingCache = isset($request->getQueryParams()['groupUsingCache']) ?
        $request->getQueryParams()['groupUsingCache'] == 'true' : null;

    $customersGroups = getCustomersGroups($bigCommerceConfig, $pageSize, $groupUsingCache);

    $currentPage = 1;
    $totalOrders = array();
    do
    {
        echo "\n";

        $lastPage = $currentPage+($bigCommerceConfig->parallelConnections-1);

        (new Progress())->update('orders', $currentPage);

        $multiOrders = 
            (new BigCommerceConsumer(
                $bigCommerceConfig->baseUrl,
                $bigCommerceConfig->store,
                $bigCommerceConfig->access_token))
            ->getOrders(
                $currentPage, $lastPage, $minDateCreated, $maxDateCreated, $pageSize);

        $allPagesHaveOrders = !empty((array)$multiOrders) && isset($multiOrders[$lastPage]);
        foreach($multiOrders as $page => $orders)
        {
            foreach($orders as $order)
            {
                $filteredOrder = new stdClass();
                $filteredOrder->id = $order->id;
                $filteredOrder->base_shipping_cost = $order->base_shipping_cost;
                $filteredOrder->subtotal_ex_tax = $order->subtotal_ex_tax;
                $filteredOrder->total_ex_tax = $order->total_ex_tax;
                $filteredOrder->total_tax = $order->total_tax;
                $filteredOrder->total_inc_tax = $order->total_inc_tax;
                $filteredOrder->store_credit_amount = $order->store_credit_amount;
                $filteredOrder->discount_amount = $order->discount_amount;
                $filteredOrder->coupon_discount = $order->coupon_discount;

                $totalOrders[$order->id] = $filteredOrder;

                if (isset($customersGroups[$order->customer_id]))
                {
                    $totalOrders[$order->id]->group = implode(',',$customersGroups[$order->customer_id]);
                }
            }
        }
        
        $currentPage += $bigCommerceConfig->parallelConnections;

    } while ($allPagesHaveOrders);

    $productsUsingCache = isset($request->getQueryParams()['productsUsingCache']) ?
        $request->getQueryParams()['productsUsingCache'] == 'true' : null;

    $productsFromOrders = getProducts($bigCommerceConfig, array_keys($totalOrders), $productsUsingCache);

    $shipmentsUsingCache = isset($request->getQueryParams()['shipmentsUsingCache']) ?
        $request->getQueryParams()['shipmentsUsingCache'] == 'true' : null;

    $shipmentsEndDate = date('Y-m-d', strtotime($request->getQueryParams()['maxDateCreated'] . " +15 day"));

    $shipmentsBetweenDates = getShipment(
        $shipStationCommerceConfig,
        $request->getQueryParams()['minDateCreated'],
        $shipmentsEndDate,
        $shipmentsUsingCache);
    $shipments = array();    
    foreach ($shipmentsBetweenDates as $shipment)
    {
        if (isset($shipments[$shipment->orderNumber]))
        {
            $shipments[$shipment->orderNumber] =  array();
        }

        $filteredShipment = new stdClass();
        $filteredShipment->shipmentCost = $shipment->shipmentCost;

        $shipments[$shipment->orderNumber][] = $filteredShipment;
    }    

    foreach($totalOrders as $k => &$order)
    {
        echo "\n";

        if (isset($productsFromOrders[$order->id]))
        {
            $order->products = $productsFromOrders[$order->id];
        }

        if (isset($shipments[$order->id]))
        {
            $order->shipStation = array_values($shipments[$order->id]);
        }
    }

    if ($totalOrders)
    {
        file_put_contents(
            $ordersCacheFile,
            json_encode($totalOrders)
        );
    }

    (new Progress())->clean();

    return $response->withJson($totalOrders);
});

$app->get('/', function (Request $request, Response $response, $args) use ($applicationConfig){

    session_start();
    
    if(isset($_SESSION['user']) || (
	    isset($_SERVER['PHP_AUTH_USER']) && $_SERVER['PHP_AUTH_USER'] == $applicationConfig->adminUser &&
      	isset($_SERVER['PHP_AUTH_PW']) && $_SERVER['PHP_AUTH_PW'] == $applicationConfig->adminPassword
	))
	{
        $_SESSION['user'] = true;
        return $response->write(file_get_contents(__DIR__.'/search.html'));
    }
    else {
        header('WWW-Authenticate: Basic realm="Protected Area"');
        print("Unauthorized");
        die();
    }
});

$app->run();