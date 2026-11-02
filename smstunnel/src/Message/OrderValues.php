<?php

/**
 * SMSTunnel: SMS notifications for PrestaShop.
 *
 * @author    Narcis Bodea <contact@narboweb.ro>
 * @copyright 2026 NARBOWEB SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace SmsTunnel\PrestaShop\Message;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The placeholder values of an order: {firstname} {lastname} {order_reference} {total_paid} {shop_name}
 * {tracking_number} {tracking_url} {status}.
 */
final class OrderValues
{
    /**
     * @param \OrderState|null $state the status to name in {status}
     *
     * @return array<string, string>
     */
    public static function of(\Order $order, $state, int $idLang): array
    {
        $customer = new \Customer((int) $order->id_customer);
        $firstname = (string) $customer->firstname;
        $lastname = (string) $customer->lastname;
        if ($firstname === '' && $lastname === '') {
            $address = new \Address((int) $order->id_address_delivery);
            $firstname = (string) $address->firstname;
            $lastname = (string) $address->lastname;
        }
        list($trackingNumber, $trackingUrl) = self::tracking($order);

        return [
            'firstname' => $firstname,
            'lastname' => $lastname,
            'order_reference' => (string) $order->reference,
            'total_paid' => self::price((float) $order->total_paid, (int) $order->id_currency, $idLang),
            'shop_name' => (string) \Configuration::get('PS_SHOP_NAME', null, (int) $order->id_shop_group, (int) $order->id_shop),
            'tracking_number' => $trackingNumber,
            'tracking_url' => $trackingUrl,
            'status' => self::stateName($state, $idLang),
        ];
    }

    /**
     * The amount with its currency, as the shop shows prices in the language of the text ("€25.90" in
     * English, "25,90 €" in French), whatever the language of the page that sends it.
     */
    public static function price(float $amount, int $idCurrency, int $idLang = 0): string
    {
        $currency = new \Currency($idCurrency);
        $iso = \Validate::isLoadedObject($currency) ? (string) $currency->iso_code : '';
        $formatted = '';
        if ($iso !== '') {
            try {
                $formatted = (string) self::locale($idLang)->formatPrice($amount, $iso);
            } catch (\Throwable $e) {
                $formatted = '';
            }
        }
        if ($formatted === '') {
            $formatted = trim(number_format($amount, 2, '.', '') . ' ' . $iso);
        }

        // Prices use no-break spaces, which are not in the basic SMS alphabet: a plain space keeps the SMS long.
        return str_replace(["\xC2\xA0", "\xE2\x80\xAF", "\xE2\x80\x89"], ' ', $formatted);
    }

    /**
     * PrestaShop's locale of a language, or the page's own when it is the same or cannot be had.
     *
     * @return mixed PrestaShop's Locale (8.x) or LocaleInterface (9.x): both have formatPrice()
     */
    private static function locale(int $idLang)
    {
        $context = \Context::getContext();
        $code = $idLang > 0 ? (string) \Language::getLocaleById($idLang) : '';
        $current = isset($context->language->locale) ? (string) $context->language->locale : '';
        if ($code !== '' && $code !== $current) {
            try {
                $container = (new \PrestaShop\PrestaShop\Adapter\ContainerFinder($context))->getContainer();

                return $container->get('prestashop.core.localization.locale.repository')->getLocale($code);
            } catch (\Throwable $e) {
                // No locale service here (some scripts): the page's locale formats the price.
                return \Tools::getContextLocale($context);
            }
        }

        return \Tools::getContextLocale($context);
    }

    /**
     * The status name in the language.
     *
     * @param \OrderState|null $state
     */
    public static function stateName($state, int $idLang): string
    {
        if (!$state instanceof \OrderState || (int) $state->id <= 0) {
            return '';
        }
        $named = new \OrderState((int) $state->id, $idLang > 0 ? $idLang : null);
        $name = $named->name;
        if (is_array($name)) {
            $name = isset($name[$idLang]) ? $name[$idLang] : (string) reset($name);
        }

        return (string) $name;
    }

    /**
     * The order's tracking number and its tracking link, or empty strings.
     *
     * @return array{0: string, 1: string}
     */
    public static function tracking(\Order $order): array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT `tracking_number`, `id_carrier` FROM `' . _DB_PREFIX_ . 'order_carrier`
            WHERE `id_order` = ' . (int) $order->id . ' AND `tracking_number` <> \'\'
            ORDER BY `id_order_carrier` DESC',
            false
        );
        if (!is_array($row) || trim((string) $row['tracking_number']) === '') {
            return ['', ''];
        }
        $number = trim((string) $row['tracking_number']);
        $url = trim((string) \Db::getInstance()->getValue(
            'SELECT `url` FROM `' . _DB_PREFIX_ . 'carrier` WHERE `id_carrier` = ' . (int) $row['id_carrier'],
            false
        ));

        return [$number, $url !== '' && strpos($url, '@') !== false ? str_replace('@', rawurlencode($number), $url) : ''];
    }
}
