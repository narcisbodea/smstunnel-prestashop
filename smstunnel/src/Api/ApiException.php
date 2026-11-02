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
 * SMSTunnel answered with an error status.
 *
 * - 401: the API key is wrong, was deleted, or its account is suspended.
 * - 403: the key lacks a permission (code PERMISSION_REQUIRED), or the plan does not include the feature.
 * - 404: no such phone (deviceId) for this key.
 * - 429: too many requests, or the daily limit is reached.
 * - 5xx: SMSTunnel had a problem.
 */
final class ApiException extends \RuntimeException
{
    /** @var int */
    private $status;

    /** @var string|null */
    private $errorCode;

    /**
     * @param int $status the HTTP status
     * @param mixed $body the decoded answer (array), its text, or null
     */
    public function __construct(int $status, $body = null)
    {
        $this->status = $status;
        if (is_array($body) && isset($body['code']) && is_string($body['code'])) {
            $this->errorCode = $body['code'];
        }
        parent::__construct(self::messageOf($body, $status), $status);
    }

    /** The HTTP status SMSTunnel answered with. */
    public function getStatus(): int
    {
        return $this->status;
    }

    /** The `code` field of the answer, such as PERMISSION_REQUIRED, when SMSTunnel gave one. */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * The words of an error answer, whatever its shape (same rules as the SMSTunnel SDK).
     *
     * @param mixed $body
     */
    public static function messageOf($body, int $status): string
    {
        if (is_string($body) && trim($body) !== '') {
            return substr(trim(strip_tags($body)), 0, 300);
        }
        $fields = is_array($body) ? $body : [];
        $message = isset($fields['message']) ? $fields['message'] : null;
        if (is_array($message)) {
            $message = implode('; ', array_filter($message, 'is_scalar'));
        }
        if (is_scalar($message) && (string) $message !== '') {
            return substr((string) $message, 0, 300);
        }
        if (isset($fields['error']) && is_scalar($fields['error']) && (string) $fields['error'] !== '') {
            return substr((string) $fields['error'], 0, 300);
        }

        return 'SMSTunnel returned error: ' . $status;
    }
}
