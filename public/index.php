<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$BW_ACCOUNT_ID = getenv("BW_ACCOUNT_ID");
$BW_USERNAME = getenv("BW_USERNAME");
$BW_PASSWORD = getenv("BW_PASSWORD");
$BW_VOICE_APPLICATION_ID = getenv("BW_VOICE_APPLICATION_ID");
$BASE_CALLBACK_URL = getenv("BASE_CALLBACK_URL");

$config = new BandwidthLib\Configuration(
    array(
        "voiceBasicAuthUserName" => $BW_USERNAME,
        "voiceBasicAuthPassword" => $BW_PASSWORD
    )
);


function modifyArray($file, $call_id, $method){
  $jsonString = file_get_contents($file, true);
  $data = json_decode($jsonString, true);
  $idArr = $data["callIds"];
  if($method == 'add' or $method == 'remove' or $method == 'get'){
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
    file_put_contents($file, json_encode($newJsonString));
    return json_encode($newJsonString);
} else {
  // return true or false if the callId passed into the function exists
  $bool = false;
  foreach ($data['callIds'] as $key => $value){
    foreach ($value as $dKey => $dValue){
      if($call_id == $dValue)
        $bool = true;
      }
    }
    return $bool;
  }
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


$app->delete('/calls/{id}', function (Request $request, Response $response, $args) use ($voice_client, $BW_ACCOUNT_ID, $BASE_CALLBACK_URL, $call_id_file){
if(modifyArray($call_id_file, $args['id'], 'check')){
    try {
      $body = new BandwidthLib\Voice\Models\ApiModifyCallRequest();
      $body->state = "active";
      $body->redirectUrl = $BASE_CALLBACK_URL."/callbacks/goodbye";
      $voice_client->modifyCall($BW_ACCOUNT_ID, $args['id'], $body);
      $arr = modifyArray($call_id_file, $args['id'], 'remove');
      $response = $response->withStatus(200)->withHeader('Content-Type', 'application/xml');
      $response->getBody()->write($arr);
      return $response;
    } catch (BandwidthLib\APIException $e) {
      print_r($e);
    }
  } else {
    $response = $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    $response->getBody()->write('{"error": "Call Id not found"}');
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
