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
 * Calls the SMSTunnel public API: one text to one number per call.
 *
 * Timeouts are short on purpose: a text is sent while the customer checks out, and checkout must
 * never wait long for SMSTunnel.
 */
final class Client
{
    const DEFAULT_URL = 'https://smstunnel.io';

    /** @var string */
    private $apiKey;

    /** @var string */
    private $baseUrl;

    /** @var int */
    private $timeout;

    /** @var int */
    private $connectTimeout;

    /** @var string */
    private $userAgent;

    public function __construct(
        string $apiKey,
        string $baseUrl = self::DEFAULT_URL,
        int $timeout = 8,
        int $connectTimeout = 4,
        string $userAgent = 'SMSTunnel-PrestaShop'
    ) {
        // A key never holds line breaks; dropping them keeps the header intact whatever was saved.
        $this->apiKey = trim(str_replace(["\r", "\n"], '', $apiKey));
        $this->baseUrl = rtrim($baseUrl !== '' ? $baseUrl : self::DEFAULT_URL, '/');
        $this->timeout = max(1, $timeout);
        $this->connectTimeout = max(1, min($connectTimeout, $this->timeout));
        $this->userAgent = $userAgent;
    }

    /**
     * Sends one text.
     *
     * @param string $to the number, in E.164 format (+14155550123)
     * @param string $message up to 1600 characters
     * @param string|null $deviceId one of the account's phones, or null to let SMSTunnel pick one
     * @param string|null $defaultCountryCode e.g. "+33": completes a number written without + or 00
     *
     * @return array{messageId: string, queued: bool} queued is true when the phone was offline:
     *                                                it sends the text when it is back online
     *
     * @throws ApiException when SMSTunnel refuses the text
     * @throws TransportException when SMSTunnel cannot be reached
     */
    public function sendSms(string $to, string $message, $deviceId = null, $defaultCountryCode = null): array
    {
        $body = ['to' => $to, 'message' => $message];
        if (is_string($deviceId) && $deviceId !== '') {
            $body['deviceId'] = $deviceId;
        }
        if (is_string($defaultCountryCode) && $defaultCountryCode !== '') {
            $body['defaultCountryCode'] = $defaultCountryCode;
        }

        $answer = $this->request('POST', '/api/v1/sms/send', $body);
        $data = isset($answer['data']) && is_array($answer['data']) ? $answer['data'] : [];
        if (isset($answer['success']) && $answer['success'] === false) {
            throw new ApiException(200, ['message' => isset($answer['error']) ? $answer['error'] : null]);
        }
        $messageId = '';
        if (isset($answer['messageId']) && is_scalar($answer['messageId'])) {
            $messageId = (string) $answer['messageId'];
        } elseif (isset($data['messageId']) && is_scalar($data['messageId'])) {
            $messageId = (string) $data['messageId'];
        }

        return [
            'messageId' => $messageId,
            'queued' => !empty($answer['queued']) || !empty($data['queued']),
        ];
    }

    /**
     * The phones linked to the key's account.
     *
     * @return array<int, array{id: string, name: string, isOnline: bool, lastSeenAt: string}>
     *
     * @throws ApiException
     * @throws TransportException
     */
    public function getDevices(): array
    {
        $answer = $this->request('GET', '/api/v1/devices');
        $devices = isset($answer['data']['devices']) && is_array($answer['data']['devices']) ? $answer['data']['devices'] : [];
        $phones = [];
        foreach ($devices as $device) {
            if (!is_array($device) || !isset($device['id']) || !is_scalar($device['id'])) {
                continue;
            }
            $phones[] = [
                'id' => (string) $device['id'],
                'name' => isset($device['name']) && is_scalar($device['name']) ? (string) $device['name'] : '',
                'isOnline' => !empty($device['isOnline']),
                'lastSeenAt' => isset($device['lastSeenAt']) && is_scalar($device['lastSeenAt']) ? (string) $device['lastSeenAt'] : '',
            ];
        }

        return $phones;
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed> the decoded answer
     *
     * @throws ApiException
     * @throws TransportException
     */
    private function request(string $method, string $path, $body = null): array
    {
        if ($this->apiKey === '') {
            throw new ApiException(401, ['message' => 'No API key is saved.']);
        }
        if (!function_exists('curl_init')) {
            throw new TransportException('The PHP cURL extension is missing.');
        }

        $headers = [
            'X-API-Key: ' . $this->apiKey,
            'Accept: application/json',
            'User-Agent: ' . $this->userAgent,
        ];
        $payload = null;
        if ($body !== null) {
            $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
            if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
                $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
            }
            $payload = json_encode($body, $flags);
            if ($payload === false) {
                throw new ApiException(400, ['message' => 'The text could not be encoded.']);
            }
            $headers[] = 'Content-Type: application/json';
        }

        $curl = curl_init($this->baseUrl . $path);
        if ($curl === false) {
            throw new TransportException('cURL could not start.');
        }
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = $payload;
        }
        if (class_exists('Composer\CaBundle\CaBundle')) {
            // Hosts with an outdated CA list still verify smstunnel.io's certificate.
            $options[CURLOPT_CAINFO] = \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
        }
        curl_setopt_array($curl, $options);

        $raw = curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80000) {
            // A no-op since PHP 8.0, and deprecated in PHP 8.5.
            curl_close($curl);
        }

        if ($raw === false || $errno !== 0) {
            throw new TransportException(sprintf('Could not reach %s (%s).', $this->baseUrl, $error !== '' ? $error : 'cURL error ' . $errno));
        }

        $text = is_string($raw) ? $raw : '';
        $decoded = $text !== '' ? json_decode($text, true) : null;
        if ($status < 200 || $status >= 300) {
            throw new ApiException($status, is_array($decoded) ? $decoded : $text);
        }

        return is_array($decoded) ? $decoded : [];
    }
}
