<?php
// Mock för inline-läget: svaret styrs av parametern scenario.
// Svaret innehåller order_id och amount från förfrågan och signeras med den delade hemligheten
// i rubriken X-KDP-Signature (HMAC-SHA256 av hela svarstexten). Då kan butiken kontrollera
// att svaret är äkta och gäller rätt order och belopp.

// Nyckeln ligger i mock-config.php, som git ignorerar.
$config = is_readable(__DIR__ . '/mock-config.php') ? require __DIR__ . '/mock-config.php' : [];
$secret = is_array($config) ? (string) ($config['shared_secret'] ?? '') : '';
if ('' === $secret) {
    http_response_code(500);
    exit('Mocken saknar nyckel. Kopiera mock-config.example.php till mock-config.php.');
}

$request = json_decode((string) file_get_contents('php://input'), true);
$request = is_array($request) ? $request : [];
$order   = ['order_id' => $request['order_id'] ?? null, 'amount' => $request['amount'] ?? null];

// Skickar ett signerat JSON-svar.
function respond($body, $secret)
{
    header('Content-Type: application/json');
    header('X-KDP-Signature: ' . hash_hmac('sha256', $body, $secret));
    echo $body;
}

$scenario = $_GET['scenario'] ?? '';

switch ($scenario) {
    case 'approved':
        respond(json_encode(['status' => 'approved', 'transaction_id' => 'TX' . random_int(1000, 9999)] + $order), $secret);
        break;
    case 'declined':
        respond(json_encode(['status' => 'declined', 'reason' => 'insufficient_funds'] + $order), $secret);
        break;
    case 'pending':
        respond(json_encode(['status' => 'pending'] + $order), $secret);
        break;
    case 'error500':
        http_response_code(500);
        echo 'Internal Server Error';
        break;
    case 'error400':
        http_response_code(400);
        echo 'Bad Request';
        break;
    case 'badjson':
        respond('{ "status": "approved", "transaction_id": "TX1234" ', $secret); // Saknar avslutande måsvinge.
        break;
    case 'timeout':
        sleep(20); // Längre än gatewayens 15 sekunder.
        respond(json_encode(['status' => 'approved', 'transaction_id' => 'TX' . random_int(1000, 9999)] + $order), $secret);
        break;
    default:
        http_response_code(400);
        echo 'Invalid scenario';
        break;
}
