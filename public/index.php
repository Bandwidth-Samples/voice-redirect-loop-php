<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$BANDWIDTH_ACCOUNT_ID = getenv("BANDWIDTH_ACCOUNT_ID");
$BANDWIDTH_USERNANME = getenv("BANDWIDTH_USERNAME");
$BANDWIDTH_PASSWORD = getenv("BANDWIDTH_PASSWORD");
$BANDWIDTH_VOICE_APPLICATION_ID = getenv("BANDWIDTH_VOICE_APPLICATION_ID");
$BASE_URL = getenv("BASE_URL");

$config = new BandwidthLib\Configuration(
    array(
        "voiceBasicAuthUserName" => $BANDWIDTH_USERNANME,
        "voiceBasicAuthPassword" => $BANDWIDTH_PASSWORD
    )
);

// Instantiate Bandwidth Client
$client = new BandwidthLib\BandwidthClient($config);

// Instantiate App
$app = AppFactory::create();

// Add error middleware
$app->addErrorMiddleware(true, true, true);

$voice_client = $client->getVoice()->getClient();
$active_calls = []

$app->post('/callbacks/inbound', function (Request $request, Response $response) {
  $data = $request->getParsedBody();
  })

$app->post('/callbacks/goodbye', function (Request $request, Response $response) {
  })

$app->get('/calls/{callId}', function (Request $request, Response $response) {
  })

$app->get('/activeCalls', function (Request $request, Response $response) {
  })
