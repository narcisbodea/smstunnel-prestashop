<?php

/**
 * SMSTunnel: SMS notifications for PrestaShop.
 *
 * @author    Narcis Bodea <contact@narboweb.ro>
 * @copyright 2026 NARBOWEB SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace SmsTunnel\PrestaShop;

if (!defined('_PS_VERSION_')) {
    exit;
}

use SmsTunnel\PrestaShop\Api\Client;

/**
 * The module's settings for one shop (PrestaShop configuration table).
 */
final class Settings
{
    public const API_KEY = 'SMSTUNNEL_API_KEY';
    public const DEVICE_ID = 'SMSTUNNEL_DEVICE_ID';
    public const API_URL = 'SMSTUNNEL_API_URL';
    public const OWNER_ENABLED = 'SMSTUNNEL_OWNER_ENABLED';
    public const OWNER_NUMBERS = 'SMSTUNNEL_OWNER_NUMBERS';
    public const OWNER_TEXT = 'SMSTUNNEL_OWNER_TEXT';
    public const CUSTOMER_ENABLED = 'SMSTUNNEL_CUSTOMER_ENABLED';
    public const CUSTOMER_STATES = 'SMSTUNNEL_CUSTOMER_STATES';
    public const CUSTOMER_TEXTS = 'SMSTUNNEL_CUSTOMER_TEXTS';

    /** Every key the module stores; uninstalling deletes them. */
    public const KEYS = [
        self::API_KEY,
        self::DEVICE_ID,
        self::API_URL,
        self::OWNER_ENABLED,
        self::OWNER_NUMBERS,
        self::OWNER_TEXT,
        self::CUSTOMER_ENABLED,
        self::CUSTOMER_STATES,
        self::CUSTOMER_TEXTS,
    ];

    /** New-order texts go to at most this many numbers. */
    public const MAX_OWNER_NUMBERS = 5;

    /** @var int|null */
    private $idShopGroup;

    /** @var int|null */
    private $idShop;

    /**
     * @param int|null $idShopGroup null for the current context
     * @param int|null $idShop null for the current context
     */
    public function __construct($idShopGroup = null, $idShop = null)
    {
        $this->idShopGroup = $idShopGroup === null ? null : (int) $idShopGroup;
        $this->idShop = $idShop === null ? null : (int) $idShop;
    }

    public function apiKey(): string
    {
        return trim((string) $this->get(self::API_KEY));
    }

    public function deviceId(): string
    {
        return trim((string) $this->get(self::DEVICE_ID));
    }

    public function apiUrl(): string
    {
        $url = trim((string) $this->get(self::API_URL));

        return $url !== '' ? rtrim($url, '/') : Client::DEFAULT_URL;
    }

    public function ownerEnabled(): bool
    {
        return (bool) $this->get(self::OWNER_ENABLED);
    }

    /**
     * @return string[] the numbers, as saved (E.164)
     */
    public function ownerNumbers(): array
    {
        return self::splitNumbers((string) $this->get(self::OWNER_NUMBERS));
    }

    public function ownerText(): string
    {
        return (string) $this->get(self::OWNER_TEXT);
    }

    public function customerEnabled(): bool
    {
        return (bool) $this->get(self::CUSTOMER_ENABLED);
    }

    /**
     * @return int[] the order statuses that text the customer
     */
    public function customerStates(): array
    {
        $states = json_decode((string) $this->get(self::CUSTOMER_STATES), true);

        return is_array($states) ? array_values(array_unique(array_map('intval', $states))) : [];
    }

    /**
     * The customer texts the merchant wrote: [id_order_state => [id_lang => text]].
     *
     * @return array<int, array<int, string>>
     */
    public function customerTexts(): array
    {
        $texts = json_decode((string) $this->get(self::CUSTOMER_TEXTS), true);
        if (!is_array($texts)) {
            return [];
        }
        $clean = [];
        foreach ($texts as $idState => $byLang) {
            if (!is_array($byLang)) {
                continue;
            }
            foreach ($byLang as $idLang => $text) {
                if (is_string($text)) {
                    $clean[(int) $idState][(int) $idLang] = $text;
                }
            }
        }

        return $clean;
    }

    /**
     * The text the merchant wrote for one status and language, or '' when there is none.
     */
    public function customerText(int $idState, int $idLang): string
    {
        $texts = $this->customerTexts();

        return isset($texts[$idState][$idLang]) ? trim($texts[$idState][$idLang]) : '';
    }

    /**
     * @return string[] the non-empty parts of a list of numbers separated by commas, semicolons or lines
     */
    public static function splitNumbers(string $numbers): array
    {
        $parts = preg_split('/[,;\r\n]+/', $numbers);

        return array_values(array_filter(array_map('trim', is_array($parts) ? $parts : []), function ($part) {
            return $part !== '';
        }));
    }

    /**
     * @return mixed
     */
    private function get(string $key)
    {
        return \Configuration::get($key, null, $this->idShopGroup, $this->idShop);
    }
}
