# Krokedil demo payments
Ett övningsbetalsätt för WooCommerce som kör mot en mock-betalleverantör. Pluginet visar hur en betalgateway hanterar olika svar, fel och säkerhet.

## Vad pluginet gör
Detta pluginet är en betallösning som kan hantera både inline-och-redirect flöden, även manuellt flöde. Detta plugin har funktion för att fungera både i blockkassan och shortcode-kassan. De tre lägen som finns är:

- **Manuellt:** inget API-anrop. Ordern sätts som *Pausad* och betalningen bekräftas manuellt.
- **Inline:** kunden stannar i kassan medan butikens server anropar betal-API:t.
- **Redirect:** kunden skickas till en betalsida och sedan tillbaka till butiken.

Varje utfall hanteras separat (godkänt, nekat, väntande, HTTP-fel, timeout och otolkbart svar) och ger en bestämd orderstatus, ordernotering och loggrad.

## Hur man laddar ned pluginet
1.  Klona repot genom att köra: ```git clone https://github.com/thyrakrokedil/Demo-payments.git```
2. Aktivera pluginet i WordPress under **Tillägg-> Installerade tillägg**
3. Om du vill, ändra inställningarna under **WooCommerce → Inställningar → Betalningar → Krokedil Demo Payments**

## Hur man sätter upp mock-endpointen
Mock-endpointen finns i filerna mock-payment.php och mock-redirect.php

**Inline: `mock-payment.php`.** Svaret styrs av parametern `scenario`.
Tillgängliga scenarier: `approved`, `declined`, `pending`, `error500`, `error400`, `badjson` (200 med trasig JSON) och `timeout` (svarar efter 20 s, längre än gatewayens timeout på 15 s).

**Redirect: `mock-redirect.php`.** En betalsida med knapparna Godkänn och Neka.
1. Skapa en nyckel: t.ex med en random password generator
2. Lägg in den i `mock-redirect.php`: `const SHARED_SECRET = '<nyckeln>';`
3. Klistra in samma nyckel i inställningen **Delad hemlighet**.
4. Sätt endpoint-URL:en till filens adress, utan `scenario`.

## Manuell testlista

Kör listan efter ändringar i betalflödet, både i shortcode-kassan och i blockkassan. Använd en
testprodukt och mock-endpointsen ovan. Inga riktiga betalningar genomförs.

### Förberedelser

- [ ] Gatewayen är aktiverad under **WooCommerce → Inställningar → Betalningar → Krokedil Demo Payments** och **Loggning** är ikryssad.
- [ ] Mock-endpointsen svarar på en adress som WordPress-servern når, till exempel sajtens egen adress följt av `/wp-content/plugins/Krokedil demo payments/mock-payment.php`.
- [ ] **Delad hemlighet** i inställningarna är samma som `SHARED_SECRET` i `mock-redirect.php`.
- [ ] Varukorgen innehåller en testprodukt. Öppna shortcode-kassans sida och en sida med WooCommerce Checkout-blocket.

### Kör i vardera kassan

**Manuellt läge** (Läge: Manuellt)

- [ ] Lägg beställningen: kunden kommer till tack-sidan, ordern är *Pausad* och tack-sidan visar betalinstruktionen.
- [ ] Ordermejlet *Beställning pausad* visar samma instruktion, både som HTML-mejl och som textmejl.

**Inline-läge** (Läge: Inline, Endpoint-URL: `…/mock-payment.php?scenario=<scenario>`)

- [ ] `approved`: ordern blir *Behandlas* och leverantörens referens (`TX…`) sparas som transaktions-ID.
- [ ] `pending`: ordern blir *Pausad* och kunden kommer till tack-sidan.
- [ ] `declined`: kunden stannar i kassan med ett felmeddelande och ordern blir *Misslyckad*.
- [ ] `error500` och `error400`: kunden stannar i kassan med ett generellt felmeddelande och ordern blir *Misslyckad*.
- [ ] `badjson`: ordern blir *Pausad* med en notering om okänt utfall, och kunden kommer till tack-sidan.
- [ ] `timeout`: efter cirka 15 sekunder blir ordern *Pausad* med en notering om okänt utfall. Kunden kan inte betala igen direkt, för att undvika dubbel debitering.
- [ ] Tom eller ogiltig Endpoint-URL: kunden stannar i kassan och ordern blir *Misslyckad*.

**Redirect-läge** (Läge: Redirect, Endpoint-URL: `…/mock-redirect.php`)

- [ ] Lägg beställningen: webbläsaren skickas till mock-betalsidan, som visar rätt ordernummer och belopp.
- [ ] Välj **Godkänn**: kunden kommer till tack-sidan och ordern blir *Behandlas* med transaktions-ID.
- [ ] Gör en ny beställning och välj **Neka**: kunden kommer tillbaka till kassan med ett felmeddelande och ordern blir *Misslyckad*.
- [ ] Ändra `amount` i adressfältet på betalsidan och välj **Godkänn**: betalsidan avvisar förfrågan eftersom signaturen inte stämmer.
- [ ] Ändra `status` i retur-adressen till butiken: ordern ändras inte och noteringen visar att en retur med ogiltig signatur avvisades.
- [ ] Ladda om retur-adressen efter en godkänd betalning: kunden kommer till tack-sidan och ordern behandlas inte en gång till.
- [ ] Töm **Delad hemlighet** och lägg en beställning: kunden stannar i kassan och ordern blir *Misslyckad*.

### Efter varje utfall

- [ ] Kontrollera orderanteckningarna och **WooCommerce → Status → Loggar** (källan `krokedil-demo-payments`).
- [ ] Ikonen i kassan är högst 30 px hög. CSS-filen `assets/css/frontend.css` laddas bara på kassasidan.
- [ ] `wp-content/debug.log` innehåller inga nya fel eller varningar från pluginet.

## phpcs och PHPStan

Installera utvecklingsberoendena med `composer install`. Kör sedan, i pluginmappen:

```sh
./vendor/bin/phpcs     # WordPress-standarden, regler i phpcs.xml.dist
./vendor/bin/phpcbf    # rättar formateringsfel automatiskt
composer stan          # PHPStan, inställningar i phpstan.neon.dist
```

phpcs undantar `mock-payment.php` och `mock-redirect.php`, eftersom de körs utanför WordPress.
Inga regler i WordPress-standarden är avstängda. Där en regel inte passar finns en
`// phpcs:ignore` med en kort motivering i koden.
PHPStan använder WordPress- och WooCommerce-stubs och körs på nivå 9, den högsta nivån.

| Körsätt | Resultat |
|---|---|
| PHPStan på originalkoden | Klarar inte nivå 0 (89 fel). Klasserna låg inuti `kdp_init()`, så PHPStan hittade dem inte. |
| PHPStan efter kodändringar med Claude-hjälp | Nivå 9, 0 fel |

## Ofärdigt

- **Dubbelklick:** inget skydd på serversidan mot två samtidiga betalförsök, vilket kan ge dubbel debitering.
- **Inga webhooks:** om kunden stänger fliken efter betalning i redirect-läget får butiken aldrig veta det.
