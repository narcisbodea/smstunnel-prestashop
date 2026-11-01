<?php
/**
 * SMSTunnel: SMS notifications for PrestaShop.
 *
 * @author    Narcis Bodea <contact@narboweb.ro>
 * @copyright 2026 NARBOWEB SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace SmsTunnel\PrestaShop\Phone;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Turns the number a customer typed into the international format (E.164) and refuses the numbers a
 * shop should never text: malformed ones, premium-rate numbers, short codes and satellite networks.
 *
 * The checks mirror smstunnel_sanitize_recipient() of the SMSTunnel WordPress plugin. Numbers written
 * without "+" or "00" are completed with the country's calling code, the way the SMSTunnel server does.
 */
final class PhoneNumber
{
    /** The number is fine. */
    const OK = '';

    /** No number was given. */
    const MISSING = 'missing';

    /** Not a phone number SMSTunnel can text. */
    const INVALID = 'invalid';

    /** A premium-rate number, a short code or a satellite network. */
    const PREMIUM = 'premium';

    /**
     * Calling codes of the countries where the leading 0 belongs to the number itself (Italy, San Marino,
     * Vatican, Côte d'Ivoire, Benin, Gabon, Republic of the Congo), so it stays after the calling code.
     */
    const KEEP_LEADING_ZERO = ['39', '378', '379', '225', '229', '241', '242'];

    /** Kept in sync with SMSTunnel::is_premium_number() of the WordPress plugin. */
    const PREMIUM_PATTERNS = [
        '/^\+40(90|80)[0-9]+$/',
        '/^\+44(90|91|98)[0-9]+$/',
        '/^\+1(900)[0-9]+$/',
        '/^\+49(900|137|138)[0-9]+$/',
        '/^\+33(89)[0-9]+$/',
        '/^\+39(89|166|899)[0-9]+$/',
        '/^\+34(80[3-7]|90[3-7])[0-9]+$/',
    ];

    /** Satellite networks the SMSTunnel server refuses (Iridium, Thuraya, Inmarsat, Globalstar...). */
    const BLOCKED_PREFIXES = ['+8816', '+8817', '+88216', '+88217', '+870', '+871', '+872', '+873', '+874', '+881', '+882'];

    /**
     * The number in E.164 format, or an empty string when it is missing, invalid or not allowed.
     *
     * @param mixed $raw the number as typed
     * @param string|int $callPrefix the calling code of the address country, e.g. "33" (empty when unknown)
     */
    public static function normalize($raw, $callPrefix = ''): string
    {
        $result = self::analyse($raw, $callPrefix);

        return $result[0];
    }

    /**
     * @param mixed $raw the number as typed
     * @param string|int $callPrefix the calling code of the address country, e.g. "33" (empty when unknown)
     *
     * @return array{0: string, 1: string} the E.164 number (or '') and the problem (one of the constants)
     */
    public static function analyse($raw, $callPrefix = ''): array
    {
        if (!is_string($raw) && !is_int($raw) && !is_float($raw)) {
            return ['', self::MISSING];
        }
        $raw = trim((string) $raw);
        $digits = (string) preg_replace('/\D+/', '', $raw);
        if ($digits === '') {
            return ['', $raw === '' ? self::MISSING : self::INVALID];
        }
        $prefix = (string) preg_replace('/\D+/', '', (string) $callPrefix);

        if (preg_match('/^[^0-9]*\+/', $raw)) {
            // Already international: "+33 6 12 34 56 78", "(+40) 721 123 456".
            $number = '+' . $digits;
        } elseif (strpos($digits, '00') === 0) {
            // International with 00: "0040 721 123 456".
            $number = '+' . substr($digits, 2);
        } elseif ($prefix === '') {
            // No country to complete it with: read it as international, like the WordPress plugin.
            $number = '+' . $digits;
        } elseif ($prefix === '7' && strlen($digits) === 11 && $digits[0] === '8') {
            // Russia and Kazakhstan write the trunk prefix as 8: "8 916 123-45-67".
            $number = '+7' . substr($digits, 1);
        } elseif ($digits[0] === '0') {
            // National number with its trunk 0: "06 12 34 56 78" in France is +33 6 12 34 56 78.
            $number = '+' . $prefix . (in_array($prefix, self::KEEP_LEADING_ZERO, true) ? $digits : substr($digits, 1));
        } elseif (strlen($digits) >= 11 && strpos($digits, $prefix) === 0) {
            // The calling code typed without "+": "33612345678". No national number is that long.
            $number = '+' . $digits;
        } else {
            // National number without the trunk 0: "612345678".
            $number = '+' . $prefix . $digits;
        }

        if (!self::isE164($number)) {
            return ['', self::INVALID];
        }
        if (self::isPremium($number)) {
            return ['', self::PREMIUM];
        }

        return [$number, self::OK];
    }

    /**
     * @param mixed $number
     */
    public static function isE164($number): bool
    {
        return is_string($number) && preg_match('/^\+[1-9]\d{6,14}$/', $number) === 1;
    }

    /**
     * True for premium-rate numbers, short codes and satellite networks (E.164 input).
     */
    public static function isPremium(string $number): bool
    {
        if (!preg_match('/^\+[1-9]\d+$/', $number)) {
            return false;
        }
        if (strlen($number) < 10) {
            return true;
        }
        foreach (self::PREMIUM_PATTERNS as $pattern) {
            if (preg_match($pattern, $number)) {
                return true;
            }
        }
        foreach (self::BLOCKED_PREFIXES as $blocked) {
            if (strpos($number, $blocked) === 0) {
                return true;
            }
        }

        return false;
    }
}
