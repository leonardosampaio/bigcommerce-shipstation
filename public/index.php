<?php
require __DIR__ . '/../vendor/autoload.php';
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use bs\BigCommerceConsumer;
use bs\ShipStationConsumer;
use bs\Configuration;

$app = AppFactory::create();

//if in subpath
$app->setBasePath('/bs');

$app->addErrorMiddleware(true, true, true);

$app->get('/shipment', function (Request $request, Response $response, $args) {

    $orderId = isset($request->getQueryParams()['orderId']) ? $request->getQueryParams()['orderId'] : null;
    if (!$orderId || (int)$orderId == 0)
    {
        return $response->withJson(['error'=>
            'Invalid orderId']);
    }

    $shipStationCommerceConfig = (new Configuration())->getShipStation();

    if (isset($shipStationCommerceConfig->error))
    {
        return $response->withJson($shipStationCommerceConfig);
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
            ->getShipments($orderId, $currentPage, 250);

        $allShipments = array_merge($allShipments, $shipments->response->shipments);

        $currentPage = $shipments->response->page + 1;
        $totalPages = $shipments->response->pages;

        if ($currentPage != $totalPages && 
            isset($shipStationCommerceConfig->rateLimitSleepTime) &&
            $shipStationCommerceConfig->rateLimitSleepTime > 0)
        {
            //seconds
            sleep($shipStationCommerceConfig->rateLimitSleepTime);
        }

    } while($currentPage <= $totalPages);

    return $response->withJson(['shipments'=>$allShipments]);
});

$app->get('/customers-in-group', function (Request $request, Response $response, $args) {

    $groupId = isset($request->getQueryParams()['groupId']) ? $request->getQueryParams()['groupId'] : null;
    if (!$groupId || (int)$groupId == 0)
    {
        return $response->withJson(['error'=>
            'Invalid groupId']);
    }

    $bigCommerceConfig = (new Configuration())->getBigCommerce();

    $totalPages = 1;
    $currentPage = 1;
    $customers = array();
    do
    {
        $group = 
        (new BigCommerceConsumer(
            $bigCommerceConfig->baseUrl,
            $bigCommerceConfig->store,
            $bigCommerceConfig->access_token))
        ->getCustomersInGroup($groupId, $currentPage, 250);

        $customers = array_merge($customers, $group->response->data);

        $currentPage = $group->response->meta->pagination->current_page + 1;
        $totalPages = $group->response->meta->pagination->total_pages;

        if ($currentPage != $totalPages &&
            isset($bigCommerceConfig->rateLimitSleepTime) &&
            $bigCommerceConfig->rateLimitSleepTime > 0)
        {
            //seconds
            sleep($bigCommerceConfig->rateLimitSleepTime);
        }

    } while ($currentPage <= $totalPages);

    return $response->withJson(['customers'=>$customers]);
});

$app->get('/', function (Request $request, Response $response, $args) {

    $bigCommerceConfig = (new Configuration())->getBigCommerce();

    $totalPages = 1;
    $currentPage = 1;
    $users = array();
    do
    {
        $group = 
        (new BigCommerceConsumer(
            $bigCommerceConfig->baseUrl,
            $bigCommerceConfig->store,
            $bigCommerceConfig->access_token))
        ->getCustomersInGroup(12, $currentPage, 250);

        if (isset($bigCommerceConfig->rateLimitSleepTime) &&
            $bigCommerceConfig->rateLimitSleepTime > 0)
        {
            //seconds
            sleep($bigCommerceConfig->rateLimitSleepTime);
        }

        $users = array_merge($users, $group->response->data);

        $currentPage = $group->response->meta->pagination->current_page + 1;
        $totalPages = $group->response->meta->pagination->total_pages;

    } while ($currentPage <= $totalPages);

    $orders = 
        (new BigCommerceConsumer(
            $bigCommerceConfig->baseUrl,
            $bigCommerceConfig->store,
            $bigCommerceConfig->access_token))
        ->getOrders(1, 1);

    return $response->withJson([$users, $orders]);
});

$app->run();