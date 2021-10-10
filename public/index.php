<?php
require __DIR__ . '/../vendor/autoload.php';
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use bs\BigCommerceConsumer;
use bs\ShipStationConsumer;
use bs\Configuration;
use bs\Progress;

date_default_timezone_set('America/Denver');

$app = AppFactory::create();

//if in subpath
$app->setBasePath('/bs');

$app->addErrorMiddleware(true, true, true);

function getGroup($bigCommerceConfig, $groupId, $pageSize, $request, $response)
{
    $groupCacheFile = __DIR__.'/../cache/customers-in-group_'.$groupId.'.json';

    $useCacheGroup = isset($request->getQueryParams()['groupUsingCache']) ?
        $request->getQueryParams()['groupUsingCache'] == 'true' : null;

    $group = array();
    if ($useCacheGroup && file_exists($groupCacheFile))
    {
        $group = $response->withJson(
            json_decode(
                file_get_contents($groupCacheFile)));
    }
    else
    {
        $totalPages = 1;
        $currentPage = 1;
        do
        {
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
            (new Progress())->update('customers-in-group', (int)($currentPage / $totalPages * 100));
            $currentPage = $totalPages != 0 ? end($multiGroup)->meta->pagination->current_page + 1 : 1;
    
            if ($currentPage != $totalPages &&
                isset($bigCommerceConfig->rateLimitSleepTime) &&
                $bigCommerceConfig->rateLimitSleepTime > 0)
            {
                //seconds
                sleep($bigCommerceConfig->rateLimitSleepTime);
            }
    
        } while ($currentPage <= $totalPages);
    
        (new Progress())->update('customers-in-group', 100);

        file_put_contents(
            $groupCacheFile,
            json_encode($group)
        );
    }
    return $group;
}

function getShipment($shipStationCommerceConfig, $orderNumber, $request)
{
    $cacheFile = __DIR__.'/../cache/shipment_'.$orderNumber.'.json';

    $useCache = isset($request->getQueryParams()['shipmentsUsingCache']) ?
        $request->getQueryParams()['shipmentsUsingCache'] == 'true' : null;

    if ($useCache && file_exists($cacheFile))
    {
        return json_decode(
                file_get_contents($cacheFile));
    }

    $totalPages = 1;
    $currentPage = 1;
    $allShipments = array();
    do {
        $shipments = 
            (new ShipStationConsumer(
                $shipStationCommerceConfig->baseUrl,
                $shipStationCommerceConfig->api_key,
                $shipStationCommerceConfig->api_secret))
            ->getShipments($orderNumber, $currentPage, 250);

        $allShipments = array_merge($allShipments, $shipments->response->shipments);

        $totalPages = $shipments->response->pages;
        $currentPage = $totalPages != 0 ? $shipments->response->page + 1 : 1;
        
        if ($totalPages != 0 &&
            $currentPage != $totalPages && 
            isset($shipStationCommerceConfig->rateLimitSleepTime) &&
            $shipStationCommerceConfig->rateLimitSleepTime > 0)
        {
            //seconds
            sleep($shipStationCommerceConfig->rateLimitSleepTime);
        }

    } while($currentPage <= $totalPages);

    file_put_contents(
        $cacheFile,
        json_encode($allShipments)
    );

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
        return $totalProducts;
    }

    $currentIndex = 1;
    do
    {
        $ids = array_slice($ordersIds, $currentIndex-1, 5);

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
            file_put_contents(
                $cacheFile,
                json_encode($products)
            );
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

    $groupId = $bigCommerceConfig->groupId;
    $pageSize = $bigCommerceConfig->pageSize;

    $group = getGroup($bigCommerceConfig, $groupId, $pageSize, $request, $response);

    $currentPage = 1;
    $totalOrders = array();
    do
    {
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
                foreach($group as $customer)
                {
                    //only orders from customers in group 12
                    if ($customer->id == $order->customer_id)
                    {
                        $totalOrders[$order->id] = $order;
                        break;
                    }
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

    $count = 1;
    foreach($totalOrders as $k => &$order)
    {
        if (isset($productsFromOrders[$order->id]))
        {
            $order->products = $productsFromOrders[$order->id];
        }

        //slow part
        $shipStationInfo = getShipment($shipStationCommerceConfig, $order->id, $request);
        if (isset($shipStationInfo) && !empty($shipStationInfo))
        {
            $order->shipStation = $shipStationInfo;
        }

        (new Progress())->update('shipment', (int)($count / sizeof($totalOrders) * 100));
        $count++;

        if (isset($shipStationCommerceConfig->rateLimitSleepTime) &&
            $shipStationCommerceConfig->rateLimitSleepTime > 0)
        {
            //seconds
            sleep($shipStationCommerceConfig->rateLimitSleepTime);
        }
    }

    (new Progress())->update('shipment', 100);

    file_put_contents(
        $ordersCacheFile,
        json_encode($totalOrders)
    );

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