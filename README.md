# Krokedil demo payments
Ett övningsbetalsätt för WooCommerce som kör mot en mock-betalleverantör. Pluginet visar hur en betalgateway hanterar olika svar, fel och säkerhet.

## Vad pluginet gör
Detta pluginet är en betallösning som kan hantera både inline-och-redirect flöden, även manuellt flöde. Detta plugin har funktion för att fungera både i blockkassan och shortcode-kassan. De tre lägen som finns är:

- **Manuellt:** inget API-anrop. Ordern sätts som *Pausad* och betalningen bekräftas manuellt.
- **Inline:** kunden stannar i kassan medan butikens server anropar betal-API:t.
- **Redirect:** kunden skickas till en betalsida och sedan tillbaka till butiken.

Varje utfall hanteras separat (godkänt, nekat, väntande, HTTP-fel, timeout och otolkbart svar) och ger en bestämd orderstatus, ordernotering och loggrad.

## Hur man laddar ned pluginet
1.  Klona repot genom att köra: ```git clone https://github.com/krokedil/Demo-payments-thyra```
2. Aktivera pluginet i WordPress under **Tillägg-> Installerade tillägg**
3. Om du vill, ändra inställningarna under **WooCommerce → Inställningar → Betalningar → Krokedil Demo Payments**

## Projektstruktur
Pluginets startpunkt ligger i huvudfilen `krokedil-demo-payments.php`, som bara startar pluginet. Logiken ligger i `classes/` och frontend-filerna i `assets/`.

- `classes/plugin.php` – bootstrappar pluginet
- `classes/gateway.php` – WooCommerce-gatewaylogik
- `classes/blocks-support.php` – stöd för WooCommerce Blocks
- `classes/assets.php` – assethantering
- `assets/js/index.js` – blockkassa JS
- `assets/css/frontend.css` – stylning för betalningsikonen

## Anslut till MockPay-API:t

1. Starta MockPay från dess mapp med `docker compose up --build`. Standardadressen är `http://localhost:8888`.
2. Öppna **WooCommerce → Inställningar → Betalningar → Krokedil Demo Payments**
3. Ange MockPays basadress under **Endpoint-URL**, utan `/payments`. Använd exempelvis `http://localhost:8888`. Om WordPress körs i en annan Docker-container kan adressen i stället behöva vara `http://host.docker.internal:8888`.
4. Ange **MockPay API-användarnamn** och **MockPay API-lösenord**. Standardvärden för lokal utveckling är `username` och `password`; de motsvarar `AUTH_USERNAME` och `AUTH_PASSWORD` i MockPay.
5. Spara inställningarna och gör en testbeställning. Kunden skickas till MockPays betalsida. **Pay** godkänner och aktiverar betalningen; **Fail payment** och **Cancel** skickar tillbaka kunden till WooCommerce där betalningen kan provas igen.

Gatewayen använder `POST /payments` för att skapa betalningen. Vid retur hämtar den betalningen igen med `GET /payments/{id}` och kontrollerar ID, belopp, valuta och status innan den aktiverar en `authorized`-betalning med `POST /activate`. Vid kundens avbokning skickas `POST /cancel`. HTTP-redirects följs inte, så Basic Auth-uppgifter inte skickas vidare till en annan adress.

MockPay validerar orderpayloaden och svarar med HTTP 422 om obligatoriska fält eller belopp är ogiltiga. Payload-mappningen omvandlar WooCommerce-belopp till heltal i minor units och skickar billing- och shipping-adress samt en sammanfattningsrad; saknad shipping-adress använder billing-adressen. MockPay kräver namn, adress, postnummer, ort, land, e-post och telefon; företag, adressrad 2 och region är valfria. Ställ in WooCommerce så att telefonfältet är obligatoriskt. Fältet **MockPay API-lösenord** ska ha samma värde som `AUTH_PASSWORD`; gatewayen skickar ingen valideringscallback, så knappen **Pay** godkänner testbetalningen direkt.

MockPays egen `PUBLIC_URL` måste vara en adress som kundens webbläsare kan öppna. Se även [MockPays README](../mock-payment/README.md) för API-konfiguration och felsökning.

## Manuell testlista

Kör listan efter ändringar i betalflödet, både i **shortcode-kassan** och i **blockkassan**. Använd en testprodukt och MockPay testuppgifter; inga riktiga betalningar genomförs.

### Förberedelser

- [ ] MockPay svarar på `/health`; WooCommerce-gatewayen är aktiverad i läget **Redirect** och använder rätt API-adress och uppgifter.
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


## Ofärdigt

- **Dubbelklick:** inget skydd på serversidan mot två samtidiga betalförsök, vilket kan ge dubbel debitering.
- **Inga webhooks:** om kunden stänger fliken efter betalning i redirect-läget får butiken aldrig veta det.