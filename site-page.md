# Site page: SMSTunnel for PrestaShop

Content for a future page on smstunnel.io (suggested path `/integrations/prestashop`). The site belongs to another
project; this file only holds the words, the structure and the assets to use.

- **Page title (≤ 60 chars):** SMSTunnel for PrestaShop: order texts from your own phone
- **Meta description (≤ 155 chars):** Text your PrestaShop customers about their orders from your own Android phone
  and number: shipped with tracking, delivered, canceled. Free module.
- **Screenshots:** for the page, the clean ones in `docs/screenshots/` of this repository
  (`phones-and-test-text.png`, `configuration.png`, `recent-texts.png`, `back-office-in-french.png`); the test
  runs' own captures are in `D:\ANDROID\integrari\prestashop-screens\` (`ps8-*` PrestaShop 8.1, `ps9-*` 9.2, and
  `received-texts.png`, what the test server received)
- **Download:** `https://github.com/narcisbodea/smstunnel-prestashop/releases/latest/download/smstunnel.zip`
  (once the GitHub release exists; later also the Addons listing)
- **Non-affiliation line (keep it on the page):** SMSTunnel for PrestaShop is not affiliated with, endorsed by, or
  sponsored by PrestaShop SA.

---

## Hero

**Order texts from your own phone, for PrestaShop**

Your PrestaShop shop texts customers when their order ships, arrives or is canceled, and texts you for every new
order: from your own Android phone, with your own number. Customers see a number they know and can simply reply.

[Download the module](https://github.com/narcisbodea/smstunnel-prestashop/releases/latest/download/smstunnel.zip) ·
[Get it on Google Play](https://play.google.com/store/apps/details?id=io.smstunnel.app) (badge: `/google-play-badge.png`)

## How it works

1. **Your phone.** Install the SMSTunnel app on your Android phone and sign in.
2. **Your shop.** Upload `smstunnel.zip` in **Modules > Module Manager**, paste the key from **Apps → Add an app**,
   send yourself a test text.
3. **Your customers.** When an order comes in or changes status, your shop hands the text to your SMSTunnel account,
   and your phone sends it from its own SIM.

## What your shop sends

| When | To | Example |
| --- | --- | --- |
| New order | you (up to 5 numbers) | My Shop: new order XKBKNABJK from Ana Pop, €25.90. |
| Shipped | the customer | Hello Ana, your order XKBKNABJK from My Shop is on its way. https://track.example/?n=6A1234 |
| Delivered | the customer | Hello Ana, your order XKBKNABJK from My Shop has been delivered. Thank you! |
| Canceled | the customer | Hello Ana, your order XKBKNABJK from My Shop has been canceled. Reply to this text if you have any questions. |
| Any status you choose | the customer | Hello Ana, your order XKBKNABJK from My Shop is now: Payment accepted. |

Tick the statuses that text the customer, reword each text, and write it in each language of your shop. The customer
gets it in the language they ordered in. Suggested texts come in English, French, Spanish, Italian, Romanian and
German.

## Built for real shops

- **Checkout never waits long, and never stops.** Short timeouts, and if SMSTunnel cannot be reached the order goes
  through; the failure is logged, and the text can be sent again with one click.
- **Numbers that work.** Local numbers are completed with the calling code of the address country (`06 12 34 56 78`
  in France becomes `+33612345678`). Invalid numbers, premium-rate numbers and short codes are skipped, with the
  reason.
- **Never twice.** Each text goes out once, even if an order gets the same status again.
- **Every text in one list.** The module page shows the last texts, whether they went out, and why one did not, with
  Send again.
- **GDPR ready.** Only transactional texts about orders; the texts appear in the customer's data export of
  PrestaShop's GDPR module and are deleted with the customer.
- **PrestaShop 8.1 to 9.2.** Tested on PrestaShop 8.1 (PHP 7.4) and 9.2 (PHP 8.5), Classic and Hummingbird themes.

## Install

1. Download [smstunnel.zip](https://github.com/narcisbodea/smstunnel-prestashop/releases/latest/download/smstunnel.zip).
2. In your back office, open **Modules > Module Manager > Upload a module** and drop the zip.
3. Click **Configure**, paste the API key from **Apps → Add an app** in your
   [dashboard](https://dashboard.smstunnel.io/apps), add your number, and click **Send a test text**.

## Build more with an AI assistant

Need a text the module does not send, such as a back-in-stock alert or a reminder for an unpaid bank wire? The README
has a ready-to-copy prompt for Claude, ChatGPT, Cursor or Copilot that explains the SMSTunnel API (one recipient per
call, `X-API-Key`, codes of 4 to 10 digits for sign-in texts, `defaultCountryCode`, the errors to handle) and asks for
a small PrestaShop module that reads the key from the configuration and never stops a checkout. Show the prompt on the
page in a copy box, from the README section "Build it with an AI assistant".

## FAQ

**Do I need a special SIM or number?** No. Texts leave from the phone and SIM you already have, so customers see your
usual number.

**Can customers reply?** Yes. Replies reach your phone like any text.

**What if my phone is offline?** Texts wait on your SMSTunnel account and go out as soon as the phone is back online.
The module page shows which phone is online.

**Does it slow down checkout?** A text takes a fraction of a second. If SMSTunnel cannot be reached, the module waits
at most a few seconds once, logs the failure, and the order goes through.

**Which numbers get texts?** The mobile number of the order's delivery address (then its phone, then the invoice
address). Numbers that cannot be texted are skipped, and the module says why.

**Which languages?** The module page is in English, French, Spanish, Italian, Romanian and German, and every text can
be written in each of your shop's languages.

**What does SMSTunnel receive?** The recipient's number and the text, nothing else from your shop: no email, no
address, no order contents.

---

Wording for this page follows the shared rules: "your own phone", "your own number", one text per recipient, and never
imply that a server controls the phone.
