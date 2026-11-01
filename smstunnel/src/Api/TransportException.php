<?php
/**
 * SMSTunnel: SMS notifications for PrestaShop.
 *
 * @author    Narcis Bodea <contact@narboweb.ro>
 * @copyright 2026 NARBOWEB SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace SmsTunnel\PrestaShop\Api;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * SMSTunnel could not be reached: DNS, TLS, refused connection or timeout.
 */
final class TransportException extends \RuntimeException
{
}
