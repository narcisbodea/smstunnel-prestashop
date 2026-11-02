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
 * Stands in for PrestaShop's translator where there is none: returns the English wording.
 */
final class EnglishWording
{
    /**
     * @param string $id
     * @param array<int|string, mixed> $parameters
     * @param string|null $domain
     * @param string|null $locale
     */
    public function trans($id, array $parameters = [], $domain = null, $locale = null): string
    {
        return (string) $id;
    }
}
