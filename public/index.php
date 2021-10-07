<?php
require __DIR__ . '/../vendor/autoload.php';
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use bs\BigCommerceConsumer;
use bs\ShipStationConsumer;

$app = AppFactory::create();

//if in subpath
$app->setBasePath('/bs');

$app->addErrorMiddleware(true, true, true);

$app->get('/', function (Request $request, Response $response, $args) {

    $bigCommerceConfigFile = __DIR__.'/../configuration/bigcommerce.json';
    $shipStationCommerceConfigFile = __DIR__.'/../configuration/shipstation.json';

    foreach([$bigCommerceConfigFile, $shipStationCommerceConfigFile] as $file)
    {
        if (!file_exists($file) || !is_readable($file))
        {
            die("Configuration $file not found or not readable");
        }
    }

    $bigCommerceConfig = json_decode(file_get_contents($bigCommerceConfigFile));
    $shipStationCommerceConfig = json_decode(file_get_contents($shipStationCommerceConfigFile));

    $group = 
        (new BigCommerceConsumer(
            $bigCommerceConfig->baseUrl,
            $bigCommerceConfig->store,
            $bigCommerceConfig->access_token))
        ->getCustomerGroup(12);

    $orders = 
        (new BigCommerceConsumer(
            $bigCommerceConfig->baseUrl,
            $bigCommerceConfig->store,
            $bigCommerceConfig->access_token))
        ->getOrders(1, 1);

    $shipments = 
        (new ShipStationConsumer($shipStationCommerceConfig->baseUrl, $shipStationCommerceConfig->api_key,$shipStationCommerceConfig->api_secret))
        ->getShipments(1, 1);
    return $response->withJson([$group, $orders, $shipments]);
});

$app->run();