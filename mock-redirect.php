<?php
/**
 * Mock-betalsida som spelar betalleverantör i redirect-läget.
 * Knapparna skickar tillbaka kunden till return_url med:
 *   &status=approved&transaction_id=TX1234   eller   &status=declined
 */
// Samma nyckel som i gatewayens inställningar. I verkligheten är det leverantören
// som skapar nyckeln, och butiken klistrar in den i sina inställningar.
//Vet att secrets ej ska ligga i klartext i koden, men detta är bara en demo. I verkligheten ska den ligga i databas eller config-fil.
const SHARED_SECRET = 'hmo<iRvl\_N25!u362IrC3;d';

$return_url = $_GET['return_url'] ?? '';
$order_id = $_GET['order_id'] ?? '?';
$amount = $_GET['amount'] ?? '?';

// Skicka bara tillbaka till riktiga webbadresser (inte t.ex. javascript:).
$scheme = parse_url($return_url, PHP_URL_SCHEME); //parsar url
// Om scheme inte http/https, skicka 400.
if (!in_array($scheme, ['http', 'https'], true)) {
    http_response_code(400);
    exit('Ogiltig eller saknad return_url.');
}

// Verifiera att förfrågan kommer från butiken och inte har ändrats på vägen.
$signature = $_GET['signature'] ?? '';
$expected = hash_hmac('sha256', implode('|', [$order_id, $amount, $return_url]), SHARED_SECRET);

if (!hash_equals($expected, $signature)) {
    http_response_code(400);
    echo 'Ogiltig förfrågan: signaturen stämmer inte. Ingen betalning har gjorts.';
    exit;
}

// Bygger länken tillbaka till butiken med resultatet.
function result_link($return_url, $params)
{
    $sep = (strpos($return_url, '?') === false) ? '?' : '&';
    // Escapas i mallen, när länken skrivs ut.
    return $return_url . $sep . http_build_query($params);
}

// Signerar resultatet: order, status, referens och belopp.
// Ändras något av dem på vägen tillbaka blir signaturen ogiltig.
function sign_result($order_id, $status, $transaction_id, $amount)
{
    $data = implode('|', [$order_id, $status, $transaction_id, $amount]);
    return hash_hmac('sha256', $data, SHARED_SECRET);
}

function signed_link($return_url, $order_id, $status, $transaction_id, $amount)
{
    return result_link($return_url, [
        'status' => $status,
        'transaction_id' => $transaction_id,
        'amount' => $amount,
        'signature' => sign_result($order_id, $status, $transaction_id, $amount),
    ]);
}

$approve_url = signed_link($return_url, $order_id, 'approved', 'TX' . random_int(1000, 9999), $amount);
$decline_url = signed_link($return_url, $order_id, 'declined', '', $amount);

// Sidans HTML ligger i assets/html/ och CSS:en i assets/css/.
require __DIR__ . '/assets/html/mock-redirect.php';
