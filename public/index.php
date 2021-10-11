<?php
require __DIR__ . '/../vendor/autoload.php';
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use bs\BigCommerceConsumer;
use bs\ShipStationConsumer;
use bs\Configuration;
use bs\Progress;

ini_set('memory_limit', '1024M');
date_default_timezone_set('America/Denver');
ob_implicit_flush();

$app = AppFactory::create();

//if in subpath
$app->setBasePath('/bs');

$app->addErrorMiddleware(true, true, true);

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
                $lastPage = $totalPages > ($currentPage + 4) ? $currentPage + 4 : $totalPages;
            }

            $multiGroup = 
            (new BigCommerceConsumer(
                $bigCommerceConfig->baseUrl,
                $bigCommerceConfig->store,
                $bigCommerceConfig->access_token))
            ->getCustomersInGroup($groupId, $currentPage, $lastPage, $pageSize);
    
            foreach($multiGroup as $page => $groupCustomers)
            {
                $group = array_merge($group, $groupCustomers->data);
            }
            
            $totalPages = end($multiGroup)->meta->pagination->total_pages;
            (new Progress())->update('customers in group ' . $groupId, (int)($currentPage / ($totalPages ? $totalPages : 1) * 100));
            $currentPage = $totalPages != 0 ? end($multiGroup)->meta->pagination->current_page + 1 : 1;
    
            if ($currentPage != $totalPages &&
                isset($bigCommerceConfig->rateLimitSleepTime) &&
                $bigCommerceConfig->rateLimitSleepTime > 0)
            {
                //seconds
                sleep($bigCommerceConfig->rateLimitSleepTime);
            }
    
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

function getCustomersGroups($bigCommerceConfig, $pageSize, $useCache)
{
    $groupCacheFile = __DIR__.'/../cache/customers-groups.json';

    if ($useCache && file_exists($groupCacheFile))
    {
        return json_decode(
                file_get_contents($groupCacheFile));
    }

    $currentPage = 1;
    $customersGroups = array();
    do
    {
        echo "\n";

        (new Progress())->update('customers in groups', $currentPage);

        $lastPage = $currentPage+4;

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
                //TODO only $group->id == 12?
                $customers = getCustomersInGroup(
                    $bigCommerceConfig, $group->id, $pageSize, $useCache);
                foreach($customers as $customer)
                {
                    if (!isset($customersGroups[$customer->id]))
                    {
                        $customersGroups[$customer->id] = array();
                    }
                    $customersGroups[$customer->id][] = $group->id;
                }
            }
        }
        
        $currentPage += 5;

        if ($allPagesHaveGroups &&
            isset($bigCommerceConfig->rateLimitSleepTime) &&
            $bigCommerceConfig->rateLimitSleepTime > 0)
        {
            //seconds
            sleep($bigCommerceConfig->rateLimitSleepTime);
        }

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

function getShipment($shipStationCommerceConfig, $createDateStart, $createDateEnd, $request)
{
    $cacheFile = __DIR__.'/../cache/shipment_'.$createDateStart.'_'.$createDateEnd.'.json';

    $useCache = isset($request->getQueryParams()['shipmentsUsingCache']) ?
        $request->getQueryParams()['shipmentsUsingCache'] == 'true' : null;

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

        $shipments = 
            (new ShipStationConsumer(
                $shipStationCommerceConfig->baseUrl,
                $shipStationCommerceConfig->api_key,
                $shipStationCommerceConfig->api_secret))
            ->getShipments($createDateStart, $createDateEnd, $currentPage, $shipStationCommerceConfig->pageSize);

        $allShipments = array_merge($allShipments, $shipments->response->shipments);

        $totalPages = $shipments->response->pages;
        (new Progress())->update('shipments from orders', (int)($currentPage / ($totalPages ? $totalPages : 1) * 100));
        
        if ($totalPages != 0 &&
            $currentPage != $totalPages && 
            isset($shipStationCommerceConfig->rateLimitSleepTime) &&
            $shipStationCommerceConfig->rateLimitSleepTime > 0)
        {
            //seconds
            sleep($shipStationCommerceConfig->rateLimitSleepTime);
        }

        $currentPage = $totalPages != 0 ? $shipments->response->page + 1 : 1;

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

function getProducts($bigCommerceConfig, $ordersIds, $request)
{
    $totalProducts = array();

    $useCache = isset($request->getQueryParams()['productsUsingCache']) ?
        $request->getQueryParams()['productsUsingCache'] == 'true' : null;
    
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

        $ids = array_slice($ordersIds, $currentIndex-1, 5);

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
            $cacheFile = __DIR__.'/../cache/products-in-order_'.$orderId.'.json';
            if ($products)
            {
                file_put_contents(
                    $cacheFile,
                    json_encode($products)
                );
            }
            $totalProducts[$orderId] = $products;
        }
        
        $currentIndex += 5;

        if ($allPagesHaveProducts &&
            isset($bigCommerceConfig->rateLimitSleepTime) &&
            $bigCommerceConfig->rateLimitSleepTime > 0)
        {
            //seconds
            sleep($bigCommerceConfig->rateLimitSleepTime);
        }

    } while ($allPagesHaveProducts && sizeof($ordersIds) > $currentIndex);

    return $totalProducts;
}

$app->get('/orders', function (Request $request, Response $response, $args) {

    (new Progress())->clean();

    session_start();
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

        $lastPage = $currentPage+4;

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
                $totalOrders[$order->id] = $order;

                if (isset($customersGroups[$order->customer_id]))
                {
                    $totalOrders[$order->id]->group = implode(',',$customersGroups[$order->customer_id]);
                }
            }
        }
        
        $currentPage += 5;

        if ($allPagesHaveOrders &&
            isset($bigCommerceConfig->rateLimitSleepTime) &&
            $bigCommerceConfig->rateLimitSleepTime > 0)
        {
            //seconds
            sleep($bigCommerceConfig->rateLimitSleepTime);
        }

    } while ($allPagesHaveOrders);

    $productsFromOrders = getProducts($bigCommerceConfig, array_keys($totalOrders), $request);

    $shipmentsBetweenDates = getShipment(
        $shipStationCommerceConfig,
        $request->getQueryParams()['minDateCreated'],
        $request->getQueryParams()['maxDateCreated'],
        $request);
    $shipments = array();    
    foreach ($shipmentsBetweenDates as $shipment)
    {
        if (isset($shipments[$shipment->orderNumber]))
        {
            $shipments[$shipment->orderNumber] =  array();
        }

        $shipments[$shipment->orderNumber][] = $shipment;
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

$app->get('/', function (Request $request, Response $response, $args) {

    session_start();
    
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