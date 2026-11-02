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
 * Fills a text's placeholders and tidies the result: an empty placeholder leaves no double spaces
 * behind, and the text is cut at the 1600 characters SMSTunnel accepts.
 */
final class Template
{
    /** The longest text SMSTunnel accepts (10 joined SMS). */
    public const MAX_LENGTH = 1600;

    /** The placeholders a text can use. */
    public const PLACEHOLDERS = [
        'firstname',
        'lastname',
        'order_reference',
        'total_paid',
        'shop_name',
        'tracking_number',
        'tracking_url',
        'status',
    ];

    /**
     * @param string $template the text with placeholders such as {firstname}
     * @param array<string, mixed> $values the value of each placeholder, by name without braces
     */
    public static function render(string $template, array $values): string
    {
        $map = [];
        foreach (self::PLACEHOLDERS as $name) {
            $value = isset($values[$name]) && is_scalar($values[$name]) ? (string) $values[$name] : '';
            // A value is one line of plain text.
            $map['{' . $name . '}'] = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value));
        }
        $text = strtr(str_replace(["\r\n", "\r"], "\n", $template), $map);

        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $lines[] = trim((string) preg_replace('/[ \t]+/', ' ', $line));
        }
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));

        return self::cut($text, self::MAX_LENGTH);
    }

    /**
     * The text, cut to at most $length characters.
     */
    public static function cut(string $text, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_strlen($text, 'UTF-8') > $length ? rtrim(mb_substr($text, 0, $length, 'UTF-8')) : $text;
        }

        return strlen($text) > $length ? rtrim(substr($text, 0, $length)) : $text;
    }

    /**
     * The placeholders as they are typed in a text: "{firstname} {lastname} ...".
     */
    public static function placeholderList(): string
    {
        return '{' . implode('} {', self::PLACEHOLDERS) . '}';
    }
}
