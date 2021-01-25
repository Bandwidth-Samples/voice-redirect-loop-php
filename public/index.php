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

function modifyArray($file, $call_id, $method){
  $jsonString = file_get_contents($file, true);
  $data = json_decode($jsonString, true);
  $idArr = $data["callIds"];
  if($method == 'add'){
    $callIdString = array("callId"=>$call_id);
    array_push($data["callIds"], $callIdString);
  } elseif($method == 'remove') {
    foreach ($data['callIds'] as $key => $value){
      foreach ($value as $dKey => $dValue){
        if($call_id == $dValue)
          array_splice($data['callIds'], $key, 1);
      }
    }
  }
  $newJsonString = $data;
  // var_dump($newJsonString);
  file_put_contents($file, json_encode($newJsonString));
  return json_encode($newJsonString);
  // return $data;
}

$call_id_file = __DIR__ . '/../callId.json';

// Instantiate Bandwidth Client
$client = new BandwidthLib\BandwidthClient($config);
$voice_client = $client->getVoice()->getClient();

// Instantiate App
$app = AppFactory::create();

// Add error middleware
$app->addErrorMiddleware(true, true, true);

$app->post('/callbacks/inbound', function (Request $request, Response $response) use ($call_id_file) {
  $data = $request->getParsedBody();

  if ($data['eventType'] == "initiate"){
    $arr = modifyArray($call_id_file, $data["callId"], 'add');;
  }

  $bxmlResponse = new BandwidthLib\Voice\Bxml\Response();
  if ($data['eventType'] == "initiate" or $data['eventType'] == "redirect"){
    $ring = new BandwidthLib\Voice\Bxml\Ring();
    $ring->duration(10);

    $redirect = new BandwidthLib\Voice\Bxml\Redirect();
    $redirect->redirectUrl("/callbacks/inbound");

    $bxmlResponse->addVerb($ring);
    $bxmlResponse->addVerb($redirect);
  }

  $response = $response->withStatus(200)->withHeader('Content-Type', 'application/xml');
  $response->getBody()->write($bxmlResponse->toBxml());
  return $response;
});


$app->post('/callbacks/goodbye', function (Request $request, Response $response) {
    $data = $request->getParsedBody();
    $bxmlResponse = new BandwidthLib\Voice\Bxml\Response();

    if ($data['eventType'] == "redirect"){
      $speakSentence = new BandwidthLib\Voice\Bxml\SpeakSentence("The call has been updated. Goodbye");
      $bxmlResponse->addVerb($speakSentence);
    }

    $response = $response->withStatus(200)->withHeader('Content-Type', 'application/xml');
    $response->getBody()->write($bxmlResponse->toBxml());
    return $response;
});


$app->delete('/calls/{id}', function (Request $request, Response $response, $args) use ($voice_client, $BANDWIDTH_ACCOUNT_ID, $BASE_URL, $call_id_file){
    try {
      $body = new BandwidthLib\Voice\Models\ApiModifyCallRequest();
      $body->state = "active";
      $body->redirectUrl = $BASE_URL."/callbacks/goodbye";
      $voice_client->modifyCall($BANDWIDTH_ACCOUNT_ID, $args['id'], $body);

      $arr = modifyArray($call_id_file, $args['id'], 'remove');
      $response = $response->withStatus(200)->withHeader('Content-Type', 'application/xml');
      $response->getBody()->write($arr);
      return $response;
    } catch (BandwidthLib\APIException $e) {
      $response = $response->withStatus(404);
      $response->getBody()->write($e);
      return $response;
    }
});


$app->get('/activeCalls', function (Request $request, Response $response) use ($app, $call_id_file) {
  $arr = modifyArray($call_id_file, '', 'get');
  $response = $response->withStatus(200)->withHeader('Content-Type', 'application/json');
  $response->getBody()->write($arr);
  return $response;
});


$app->run();
