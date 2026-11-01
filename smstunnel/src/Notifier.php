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

use SmsTunnel\PrestaShop\Api\ApiException;
use SmsTunnel\PrestaShop\Api\Client;
use SmsTunnel\PrestaShop\Api\TransportException;
use SmsTunnel\PrestaShop\Log\TextLog;
use SmsTunnel\PrestaShop\Message\DefaultTexts;
use SmsTunnel\PrestaShop\Message\OrderValues;
use SmsTunnel\PrestaShop\Message\Template;
use SmsTunnel\PrestaShop\Phone\PhoneNumber;

/**
 * Decides which order texts to send, to which number, and sends each one once.
 */
final class Notifier
{
    /** Seconds a send may take while a customer checks out. */
    const TIMEOUT = 8;

    /** Seconds to wait for a connection to SMSTunnel. */
    const CONNECT_TIMEOUT = 3;

    /** @var TextLog */
    private $log;

    /** @var string */
    private $userAgent;

    /**
     * SMSTunnel addresses that could not be reached during this request: later texts of the same request
     * are not tried, so a checkout never waits for several timeouts in a row.
     *
     * @var array<string, string>
     */
    private static $unreachable = [];

    public function __construct(TextLog $log, string $userAgent)
    {
        $this->log = $log;
        $this->userAgent = $userAgent;
    }

    /**
     * A new order: text the shop owner's numbers.
     *
     * @param \OrderState|null $orderState the order's first status
     */
    public function newOrder(\Order $order, $orderState = null)
    {
        $settings = new Settings((int) $order->id_shop_group, (int) $order->id_shop);
        if ($settings->apiKey() === '' || !$settings->ownerEnabled()) {
            return;
        }
        $numbers = array_slice($settings->ownerNumbers(), 0, Settings::MAX_OWNER_NUMBERS);
        if (!$numbers) {
            return;
        }

        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT', null, (int) $order->id_shop_group, (int) $order->id_shop);
        $template = trim($settings->ownerText());
        if ($template === '') {
            $template = DefaultTexts::owner($idLang);
        }
        $message = Template::render($template, OrderValues::of($order, $orderState, $idLang));
        $prefix = self::shopCallPrefix((int) $order->id_shop);

        foreach ($numbers as $raw) {
            list($number, $problem) = PhoneNumber::analyse($raw, $prefix);
            $this->deliver($settings, [
                'dedupe_key' => 'order:' . (int) $order->id . ':new:' . ($number !== '' ? $number : substr(md5($raw), 0, 16)),
                'event' => 'new_order',
                'id_order' => (int) $order->id,
                'id_order_state' => $orderState instanceof \OrderState ? (int) $orderState->id : 0,
                'id_shop' => (int) $order->id_shop,
                'recipient' => $number !== '' ? $number : $raw,
                'message' => $message,
            ], $problem, $prefix);
        }
    }

    /**
     * An order moved to another status: text the customer if the merchant ticked that status.
     *
     * @param \OrderState|null $newState
     * @param \OrderState|null $oldState
     */
    public function statusChanged(int $idOrder, $newState, $oldState = null)
    {
        if ($idOrder <= 0 || !$newState instanceof \OrderState || (int) $newState->id <= 0) {
            return;
        }
        if ($oldState instanceof \OrderState && (int) $oldState->id === (int) $newState->id) {
            return;
        }
        $order = new \Order($idOrder);
        if (!\Validate::isLoadedObject($order)) {
            return;
        }
        $settings = new Settings((int) $order->id_shop_group, (int) $order->id_shop);
        if ($settings->apiKey() === '' || !$settings->customerEnabled()) {
            return;
        }
        $idState = (int) $newState->id;
        if (!in_array($idState, $settings->customerStates(), true)) {
            return;
        }

        $idLang = (int) $order->id_lang;
        $template = $settings->customerText($idState, $idLang);
        if ($template === '') {
            $template = DefaultTexts::customer($idState, $idLang);
        }
        $message = Template::render($template, OrderValues::of($order, $newState, $idLang));
        list($number, $problem, $raw, $prefix) = self::customerNumber($order);

        $this->deliver($settings, [
            'dedupe_key' => 'order:' . (int) $order->id . ':state:' . $idState,
            'event' => 'status',
            'id_order' => (int) $order->id,
            'id_order_state' => $idState,
            'id_shop' => (int) $order->id_shop,
            'recipient' => $number !== '' ? $number : $raw,
            'message' => $message,
        ], $problem, $prefix);
    }

    /**
     * Sends a test text now and logs it.
     *
     * @return array{status: string, messageId: string, error: string, exception: \Throwable|null}
     */
    public function test(Settings $settings, string $number, string $message, string $prefix, int $idShop): array
    {
        $id = $this->log->claim([
            'dedupe_key' => null,
            'event' => 'test',
            'id_order' => 0,
            'id_order_state' => 0,
            'id_shop' => $idShop,
            'recipient' => $number,
            'message' => $message,
        ]);
        $result = $this->send($settings, $number, $message, $prefix, 10, 4);
        if ($id > 0) {
            $this->log->finish($id, $result['status'], $result['messageId'], $result['error']);
        }

        return $result;
    }

    /**
     * Sends one text now.
     *
     * @return array{status: string, messageId: string, error: string, exception: \Throwable|null}
     */
    public function send(Settings $settings, string $number, string $message, string $prefix, int $timeout = self::TIMEOUT, int $connectTimeout = self::CONNECT_TIMEOUT): array
    {
        $url = $settings->apiUrl();
        if (isset(self::$unreachable[$url])) {
            return self::result(TextLog::FAILED, '', 'Not tried: SMSTunnel could not be reached a moment ago. ' . self::$unreachable[$url]);
        }
        try {
            $client = new Client($settings->apiKey(), $url, $timeout, $connectTimeout, $this->userAgent);
            $deviceId = $settings->deviceId();
            $sent = $client->sendSms($number, $message, $deviceId !== '' ? $deviceId : null, $prefix !== '' ? '+' . $prefix : null);

            return self::result($sent['queued'] ? TextLog::QUEUED : TextLog::SENT, $sent['messageId'], '');
        } catch (TransportException $e) {
            self::$unreachable[$url] = $e->getMessage();

            return self::result(TextLog::FAILED, '', $e->getMessage(), $e);
        } catch (ApiException $e) {
            $code = $e->getErrorCode();

            return self::result(TextLog::FAILED, '', 'HTTP ' . $e->getStatus() . ($code ? ' ' . $code : '') . ': ' . $e->getMessage(), $e);
        }
    }

    /**
     * The customer's mobile number: the delivery address first (mobile phone, then phone), then the
     * invoice address, each completed with its country's calling code.
     *
     * @return array{0: string, 1: string, 2: string, 3: string} the E.164 number (or ''), the problem,
     *                                                           the number as typed, the calling code
     */
    public static function customerNumber(\Order $order): array
    {
        $firstProblem = null;
        $addresses = array_unique([(int) $order->id_address_delivery, (int) $order->id_address_invoice]);
        foreach ($addresses as $idAddress) {
            if ($idAddress <= 0) {
                continue;
            }
            $address = new \Address($idAddress);
            if (!\Validate::isLoadedObject($address)) {
                continue;
            }
            $prefix = self::callPrefix((int) $address->id_country);
            foreach ([$address->phone_mobile, $address->phone] as $raw) {
                $raw = trim((string) $raw);
                if ($raw === '') {
                    continue;
                }
                list($number, $problem) = PhoneNumber::analyse($raw, $prefix);
                if ($problem === PhoneNumber::OK) {
                    return [$number, PhoneNumber::OK, $raw, $prefix];
                }
                if ($firstProblem === null) {
                    $firstProblem = ['', $problem, $raw, $prefix];
                }
            }
        }

        return $firstProblem !== null ? $firstProblem : ['', PhoneNumber::MISSING, '', ''];
    }

    /**
     * The calling code of a country, e.g. "33", or '' when unknown.
     */
    public static function callPrefix(int $idCountry): string
    {
        if ($idCountry <= 0) {
            return '';
        }
        $country = new \Country($idCountry);

        return \Validate::isLoadedObject($country) && (int) $country->call_prefix > 0 ? (string) (int) $country->call_prefix : '';
    }

    /**
     * The calling code of the shop's country.
     */
    public static function shopCallPrefix(int $idShop = 0): string
    {
        return self::callPrefix((int) \Configuration::get('PS_COUNTRY_DEFAULT', null, null, $idShop > 0 ? $idShop : null));
    }

    /**
     * Reserves the text, then sends it unless it went out already.
     *
     * @param array{dedupe_key: string|null, event: string, id_order: int, id_order_state: int, id_shop: int, recipient: string, message: string} $text
     */
    private function deliver(Settings $settings, array $text, string $problem, string $prefix)
    {
        $id = $this->log->claim($text);
        if ($id === 0) {
            // The same text went out already, or is going out right now.
            return;
        }
        if ($problem !== PhoneNumber::OK) {
            $this->log->finish($id, TextLog::SKIPPED, '', 'number:' . $problem);

            return;
        }
        $result = $this->send($settings, $text['recipient'], $text['message'], $prefix);
        $this->log->finish($id, $result['status'], $result['messageId'], $result['error']);
        if ($result['status'] === TextLog::FAILED) {
            self::logError(sprintf('Text about order %d not sent: %s', $text['id_order'], $result['error']), (int) $text['id_order']);
        }
    }

    /**
     * Writes to PrestaShop's log (Advanced Parameters > Logs).
     */
    public static function logError(string $message, int $idOrder = 0)
    {
        try {
            // The log refuses < > { } in a message.
            $clean = trim((string) preg_replace('/[<>{}]+/', ' ', 'SMSTunnel: ' . $message));
            \PrestaShopLogger::addLog(substr($clean, 0, 1000), 3, null, $idOrder > 0 ? 'Order' : null, $idOrder > 0 ? $idOrder : null, true);
        } catch (\Throwable $e) {
            // Logging must never break the page that triggered it.
            return;
        }
    }

    /**
     * @return array{status: string, messageId: string, error: string, exception: \Throwable|null}
     */
    private static function result(string $status, string $messageId, string $error, $exception = null): array
    {
        return ['status' => $status, 'messageId' => $messageId, 'error' => $error, 'exception' => $exception];
    }
}
