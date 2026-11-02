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
 * The texts the module suggests until the merchant writes their own, in the language of each order.
 *
 * They avoid characters outside the basic SMS alphabet where the language allows it: one such character
 * shortens every SMS part from 160 to 70 characters.
 */
final class DefaultTexts
{
    /** @var array<string, object> translators by locale, for this request */
    private static $translators = [];

    /**
     * The text sent to the shop owner for a new order.
     */
    public static function owner(int $idLang): string
    {
        list($translator, $locale) = self::translator($idLang);

        return self::plain($translator->trans('{shop_name}: new order {order_reference} from {firstname} {lastname}, {total_paid}.', [], 'Modules.Smstunnel.Admin', $locale));
    }

    /**
     * The text sent to the customer when the order moves to a status.
     */
    public static function customer(int $idState, int $idLang): string
    {
        list($translator, $locale) = self::translator($idLang);
        switch (self::kind($idState)) {
            case 'shipped':
                $text = $translator->trans('Hello {firstname}, your order {order_reference} from {shop_name} is on its way. {tracking_url}', [], 'Modules.Smstunnel.Admin', $locale);
                break;
            case 'delivered':
                $text = $translator->trans('Hello {firstname}, your order {order_reference} from {shop_name} has been delivered. Thank you!', [], 'Modules.Smstunnel.Admin', $locale);
                break;
            case 'canceled':
                $text = $translator->trans('Hello {firstname}, your order {order_reference} from {shop_name} has been canceled. Reply to this text if you have any questions.', [], 'Modules.Smstunnel.Admin', $locale);
                break;
            case 'paid':
                $text = $translator->trans('Hello {firstname}, we have received your payment of {total_paid} for order {order_reference}. Thank you for shopping at {shop_name}!', [], 'Modules.Smstunnel.Admin', $locale);
                break;
            case 'refunded':
                $text = $translator->trans('Hello {firstname}, your order {order_reference} from {shop_name} has been refunded.', [], 'Modules.Smstunnel.Admin', $locale);
                break;
            case 'preparing':
                $text = $translator->trans('Hello {firstname}, we are preparing your order {order_reference} from {shop_name}.', [], 'Modules.Smstunnel.Admin', $locale);
                break;
            default:
                $text = $translator->trans('Hello {firstname}, your order {order_reference} from {shop_name} is now: {status}.', [], 'Modules.Smstunnel.Admin', $locale);
        }

        return self::plain($text);
    }

    /**
     * The test text.
     */
    public static function test(int $idLang): string
    {
        list($translator, $locale) = self::translator($idLang);

        return self::plain($translator->trans('This is a test text from {shop_name}. Your order texts will come from this phone.', [], 'Modules.Smstunnel.Admin', $locale));
    }

    /**
     * Which suggested text fits a status: shipped, delivered, canceled, paid, refunded, preparing or other.
     */
    public static function kind(int $idState): string
    {
        $kinds = [
            'PS_OS_SHIPPING' => 'shipped',
            'PS_OS_DELIVERED' => 'delivered',
            'PS_OS_CANCELED' => 'canceled',
            'PS_OS_PAYMENT' => 'paid',
            'PS_OS_WS_PAYMENT' => 'paid',
            'PS_OS_REFUND' => 'refunded',
            'PS_OS_PREPARATION' => 'preparing',
        ];
        foreach ($kinds as $key => $kind) {
            if ($idState > 0 && (int) \Configuration::get($key) === $idState) {
                return $kind;
            }
        }

        return 'other';
    }

    /**
     * A translator for the language, and its locale (null when unknown).
     *
     * @return array{0: object, 1: string|null}
     */
    private static function translator(int $idLang): array
    {
        try {
            $context = \Context::getContext();
            $locale = $idLang > 0 ? \Language::getLocaleById($idLang) : null;
            $current = $context->getTranslator();
            if (empty($locale) || (isset($context->language->locale) && $context->language->locale === $locale)) {
                return [$current, empty($locale) ? null : $locale];
            }
            if (!isset(self::$translators[$locale])) {
                self::$translators[$locale] = $context->getTranslatorFromLocale($locale);
            }

            return [self::$translators[$locale], $locale];
        } catch (\Throwable $e) {
            // No translator here (a script without a language, for instance): the English wording is used.
            return [new EnglishWording(), null];
        }
    }

    /**
     * SMS are plain text: undo any HTML escaping a translator may apply.
     */
    private static function plain(string $text): string
    {
        return trim(html_entity_decode($text, ENT_QUOTES, 'UTF-8'));
    }
}
