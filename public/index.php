<?php

use Bandwidth\Api\CallsApi;
use Bandwidth\Configuration;
use Bandwidth\Model\Bxml\Hangup;
use Bandwidth\Model\Bxml\Redirect;
use Bandwidth\Model\Bxml\Response as BxmlResponse;
use Bandwidth\Model\Bxml\Ring;
use Bandwidth\Model\Bxml\SpeakSentence;
use Bandwidth\Model\UpdateCall;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$BW_ACCOUNT_ID = getenv('BW_ACCOUNT_ID');
$BW_CLIENT_ID = getenv('BW_CLIENT_ID');
$BW_CLIENT_SECRET = getenv('BW_CLIENT_SECRET');
$BASE_CALLBACK_URL = getenv('BASE_CALLBACK_URL');

$config = Configuration::getDefaultConfiguration()
    ->setClientId($BW_CLIENT_ID)
    ->setClientSecret($BW_CLIENT_SECRET);
$callsApi = new CallsApi(new Client(), $config);

$app = AppFactory::create();
$app->addErrorMiddleware(true, true, true);

function parseBody(Request $request): ?array
{
    $data = json_decode($request->getBody()->getContents(), true);
    return is_array($data) ? $data : null;
}

function xmlResponse(Response $response, BxmlResponse $bxml): Response
{
    $response->getBody()->write($bxml->toBxml());
    return $response->withHeader('Content-Type', 'application/xml');
}

// PHP doesn't keep memory between requests, so active calls live in a temp file that start.php resets
function activeCallsFile(): string
{
    return sys_get_temp_dir() . "/voice-redirect-loop-{$_SERVER['SERVER_PORT']}.json";
}

function loadActiveCalls(): array
{
    return json_decode(@file_get_contents(activeCallsFile()) ?: '[]', true);
}

function saveActiveCalls(array $calls): void
{
    file_put_contents(activeCallsFile(), json_encode(array_values($calls)), LOCK_EX);
}

$app->post('/callbacks/inbound/voice', function (Request $request, Response $response) {
    $data = parseBody($request);
    $eventType = $data['eventType'] ?? null;
    if ($eventType !== 'initiate' && $eventType !== 'redirect') {
        return $response->withStatus(400);
    }

    $calls = loadActiveCalls();
    if ($eventType === 'initiate' && !in_array($data['callId'], $calls, true)) {
        $calls[] = $data['callId'];
        saveActiveCalls($calls);
    }

    return xmlResponse($response, new BxmlResponse([
        new SpeakSentence('Redirecting call, please wait.'),
        new Ring(duration: 30),
        new Redirect(redirectUrl: '/callbacks/inbound/voice'),
    ]));
});

$app->post('/callbacks/inbound/voice/callEnded', function (Request $request, Response $response) {
    $data = parseBody($request);
    if (($data['eventType'] ?? null) !== 'redirect') {
        return $response->withStatus(400);
    }

    return xmlResponse($response, new BxmlResponse([
        new SpeakSentence('The call has been ended. Goodbye.'),
        new Hangup(),
    ]));
});

$app->post('/callbacks/inbound/voice/disconnect', function (Request $request, Response $response) {
    $data = parseBody($request);
    if (($data['eventType'] ?? null) !== 'disconnect') {
        return $response->withStatus(400);
    }

    saveActiveCalls(array_diff(loadActiveCalls(), [$data['callId'] ?? null]));
    return $response->withStatus(204);
});

$app->get('/calls', function (Request $request, Response $response) {
    $response->getBody()->write(json_encode(loadActiveCalls()));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->delete('/calls/{callId}', function (Request $request, Response $response, array $args) use ($callsApi, $BW_ACCOUNT_ID, $BASE_CALLBACK_URL) {
    $callId = $args['callId'];
    $calls = loadActiveCalls();
    if (!in_array($callId, $calls, true)) {
        return $response->withStatus(404);
    }

    $updateCall = new UpdateCall([
        'redirect_url' => "{$BASE_CALLBACK_URL}/callbacks/inbound/voice/callEnded",
    ]);

    try {
        $callsApi->updateCall($BW_ACCOUNT_ID, $callId, $updateCall);
    } catch (Throwable $e) {
        error_log('Failed to update call: ' . $e->getMessage());
        return $response->withStatus(500);
    }

    saveActiveCalls(array_diff($calls, [$callId]));
    return $response->withStatus(204);
});

$app->run();
