# Changelog

All notable changes to SMSTunnel for PrestaShop. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-11-02

First version.

- A text to your own numbers (up to 5) for every new order.
- A text to the customer when the order moves to a status you tick (Shipped, Delivered and Canceled are ticked at install), with a text per status and per shop language, in the language of the order. Placeholders: `{firstname} {lastname} {order_reference} {total_paid} {shop_name} {tracking_number} {tracking_url} {status}`.
- Customer numbers from the delivery address (mobile phone first), then the invoice address, completed with the address country's calling code; invalid numbers, premium-rate numbers, short codes and satellite networks are skipped.
- Each order text is sent once, even when PrestaShop runs the same hook twice or an order gets the same status again.
- Checkout never waits long and never stops: 3 s to connect, 8 s in all, and the rest of the page's texts are not tried once SMSTunnel could not be reached. Failures go to the module's log and to PrestaShop's log.
- Module page: your phones and their state, a test text, the last texts with their status, and **Send again** for a text that did not go out.
- GDPR: the texts about a customer are part of the data export of PrestaShop's GDPR module, and are deleted when the customer is erased.
- Back office in English, French, Spanish, Italian, Romanian and German.
- PrestaShop 8.1 to 9.2, PHP 7.2.5 to 8.5.

[1.0.0]: https://github.com/narcisbodea/smstunnel-prestashop/releases/tag/v1.0.0
