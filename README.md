# Krokedil demo payments
Ett övningsbetalsätt för WooCommerce som kör mot en mock-betalleverantör. Pluginet visar hur en betalgateway hanterar olika svar, fel och säkerhet.

## Vad pluginet gör
Pluginet har två lägen och fungerar i både blockkassan och shortcode-kassan:

- **Manuellt:** inget API-anrop görs och ordern sätts på vänt.
- **MockPay:** en betalning skapas via test-API:t och kunden skickas till MockPays betalsida.

MockPay-anropen finns i en gemensam API-klient. Gatewayen verifierar betalningen mot API:t när kunden kommer tillbaka; redirect-adressen ensam räknas inte som betalningsbekräftelse.

## Hur man laddar ned pluginet
1.  Klona repot genom att köra: ```git clone https://github.com/krokedil/Demo-payments-thyra```
2. Aktivera pluginet i WordPress under **Tillägg-> Installerade tillägg**
3. Om du vill, ändra inställningarna under **WooCommerce → Inställningar → Betalningar → Krokedil Demo Payments**

## Projektstruktur
Pluginets startpunkt ligger i huvudfilen `krokedil-demo-payments.php`, som bara startar pluginet. Logiken ligger i `classes/` och frontend-filerna i `assets/`.

- `classes/plugin.php` – bootstrappar pluginet
- `classes/gateway.php` – WooCommerce-gatewaylogik
- `classes/mockpay-client.php` – autentiserade API-anrop och gemensam felsvarshantering
- `classes/mockpay-order-payload.php` – mappning av WooCommerce-order till MockPay
- `classes/blocks-support.php` – stöd för WooCommerce Blocks
- `classes/assets.php` – assethantering
- `assets/js/index.js` – blockkassa JS
- `assets/css/frontend.css` – stylning för betalningsikonen

## Anslut till MockPay-API:t

1. Starta MockPay från dess mapp med `docker compose up --build`. Standardadressen är `http://localhost:8888`.
2. Öppna **WooCommerce → Inställningar → Betalningar → Krokedil Demo Payments**
3. Välj **MockPay (redirect)** som betalläge för att använda API:t. Fyll i **Test API-URL**, **Test API-användarnamn** och **Test API-lösenord**.
4. API-URL:en är MockPays basadress utan `/payments`, och måste kunna nås från WordPress-servern. `localhost` betyder WordPress-containern om WordPress kör i Docker; använd då en adress som containern når, exempelvis `http://host.docker.internal:8888` eller nätverkets tjänsteadress. Kundens webbläsare når MockPay separat via MockPays `PUBLIC_URL`.
5. Lokala testuppgifter är normalt `username` och `password`, motsvarande MockPays `AUTH_USERNAME` och `AUTH_PASSWORD`. Lösenordet används för Basic Auth och är även nyckeln för MockPay-callbacksignaturer.
6. Spara och gör en testbeställning. Kunden skickas till MockPays betalsida. **Pay** godkänner och aktiverar betalningen; **Fail payment** och **Cancel** skickar tillbaka kunden till WooCommerce där betalningen kan provas igen. I **Manuellt** läge kontaktas inte MockPay.

API-klienten hanterar `POST /payments`, `GET /payments/{id}`, `POST /payments/{id}/activate` och `POST /payments/{id}/cancel`. Den använder Basic Auth och hanterar JSON-felsvar, inklusive `application/problem+json` och HTTP 401, 404, 409, 422 och 500. HTTP-redirects följs inte, så Basic Auth-uppgifter inte skickas vidare till en annan adress. Övrig plugin-kod anropar inte `wp_remote_*` direkt.

MockPay validerar orderpayloaden och svarar med HTTP 422 om obligatoriska fält eller belopp är ogiltiga. Payload-mappningen omvandlar WooCommerce-belopp till heltal i minor units och skickar billing- och shipping-adress samt en sammanfattningsrad; saknad shipping-adress använder billing-adressen. MockPay kräver namn, adress, postnummer, ort, land, e-post och telefon; företag, adressrad 2 och region är valfria. Ställ in WooCommerce så att telefonfältet är obligatoriskt. Gatewayen skickar ingen valideringscallback, så knappen **Pay** godkänner testbetalningen direkt.

MockPays egen `PUBLIC_URL` måste vara en adress som kundens webbläsare kan öppna. Se även [MockPays README](../mock-payment/README.md) för API-konfiguration och felsökning.

## Manuell testlista

Kör listan efter ändringar i betalflödet, både i **shortcode-kassan** och i **blockkassan**. Använd en testprodukt och MockPay testuppgifter; inga riktiga betalningar genomförs.

### Förberedelser

- [ ] MockPay svarar på `/health`; WooCommerce-gatewayen är aktiverad i läget **MockPay (redirect)** och test-API-adress och uppgifter är ifyllda.
- [ ] Telefonfältet är obligatoriskt i WooCommerce (**WooCommerce → Inställningar → Konton och sekretess / Kassaalternativ**, beroende på version).
- [ ] Varukorgen innehåller en testprodukt. Öppna shortcode-kassans sida och en sida med WooCommerce **Checkout block**.

### Kör i vardera kassan

- [ ] Lämna telefonnummer tomt och försök lägga beställningen: kassan stoppar beställningen och markerar telefonfältet.
- [ ] Fyll i giltiga faktureringsuppgifter inklusive telefonnummer och välj **Krokedil Demo Payments**.
- [ ] Lägg beställningen: webbläsaren skickas till MockPay. Kontrollera att betalsidan visar rätt belopp och valuta.
- [ ] Välj **Pay**: kunden återvänder till WooCommerce tack-sida; ordern markeras betald och betalningen är `activated` i MockPay.
- [ ] Gör en ny beställning och välj **Fail payment**: kunden återvänder till WooCommerce för att försöka igen; ordern markeras inte betald och MockPay-betalningen förblir `created`.
- [ ] Gör en ny beställning och välj **Cancel**: kunden återvänder till betalningssidan för ordern med ett meddelande; ordern är inte betald och MockPay-betalningen är `cancelled`.
- [ ] Kontrollera WooCommerce-orderanteckningar och **WooCommerce → Status → Loggar** (`krokedil-demo-payments`) vid varje utfall.

## PHPStan

Installera utvecklingsberoenden med `composer install` och kör analysen med `composer stan`. PHPStan använder WordPress- och WooCommerce-stubs. Analysen började på nivå 0 och höjdes stegvis till **nivå 9**, den högsta nivån.

| Körsätt | Resultat |
| --- | --- |
| PHPStan utan Copilot (`composer stan`) | Nivå 2 |
| PHPStan efter kodändringar med Copilot-hjälp | Nivå 9 |




## Ofärdigt

- **Dubbelklick:** inget skydd på serversidan mot två samtidiga betalförsök, vilket kan ge dubbel debitering.
- **Inga webhooks:** om kunden stänger fliken efter betalning i redirect-läget får butiken aldrig veta det.