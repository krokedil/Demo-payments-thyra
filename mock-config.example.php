<?php
/**
 * Exempel på konfiguration för mock-betalsidan.
 *
 * Kopiera filen till mock-config.php och fyll i en egen nyckel, till exempel från
 * `openssl rand -hex 32`. mock-config.php ignoreras av git, så nyckeln hamnar aldrig i repot.
 * Samma nyckel ska stå i inställningen Delad hemlighet i WooCommerce.
 */
return [
    'shared_secret' => '',
];
