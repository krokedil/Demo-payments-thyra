<?php
/**
 * HTML för mock-betalsidan. Laddas av mock-redirect.php, som har räknat ut variablerna.
 *
 * Sidan körs utanför WordPress, så esc_html()/esc_url() finns inte. All utskrift escapas
 * därför med htmlspecialchars(), som gör samma sak för text och attribut.
 */

// Sidan ska bara visas via mock-redirect.php, inte öppnas direkt.
if (!isset($order_id, $amount, $approve_url, $decline_url)) {
    http_response_code(404);
    exit;
}
?>
<!doctype html>
<html lang="sv">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mock-betalsida</title>
    <!-- Sökvägen är relativ till mock-redirect.php, som är sidan webbläsaren öppnar. -->
    <link rel="stylesheet" href="assets/css/mock-redirect.css">
</head>

<body>
    <h1>Mock-betalning</h1>
    <p>Order <strong><?= htmlspecialchars($order_id) ?></strong><br>
        Belopp <strong><?= htmlspecialchars($amount) ?></strong></p>

    <a class="approve" href="<?= htmlspecialchars($approve_url) ?>">Godkänn</a>
    <a class="decline" href="<?= htmlspecialchars($decline_url) ?>">Neka</a>
</body>

</html>
