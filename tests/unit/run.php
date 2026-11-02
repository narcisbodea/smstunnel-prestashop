<?php

/**
 * Unit tests for the parts of the module that need no PrestaShop: phone numbers, texts, API errors, and
 * (with SMSTUNNEL_E2E_URL and SMSTUNNEL_E2E_KEY set) one real call to a local SMSTunnel test server.
 *
 * Run: php tests/unit/run.php   (no PHPUnit needed, so it runs on every PHP version the module supports)
 *
 * @author    Narcis Bodea <contact@narboweb.ro>
 * @copyright 2026 NARBOWEB SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
define('_PS_VERSION_', 'tests');

$src = dirname(__DIR__, 2) . '/smstunnel/src';
foreach (['Phone/PhoneNumber', 'Message/Template', 'Api/ApiException', 'Api/TransportException', 'Api/Client'] as $file) {
    require_once $src . '/' . $file . '.php';
}

use SmsTunnel\PrestaShop\Api\ApiException;
use SmsTunnel\PrestaShop\Api\Client;
use SmsTunnel\PrestaShop\Api\TransportException;
use SmsTunnel\PrestaShop\Message\Template;
use SmsTunnel\PrestaShop\Phone\PhoneNumber;

$passed = 0;
$failed = 0;

function check($name, $actual, $expected)
{
    global $passed, $failed;
    if ($actual === $expected) {
        ++$passed;

        return;
    }
    ++$failed;
    echo 'FAIL ', $name, "\n  expected: ", var_export($expected, true), "\n  actual:   ", var_export($actual, true), "\n";
}

// Phone numbers: [typed, calling code of the address country, expected E.164, expected problem]
$numbers = [
    ['+33 6 12 34 56 78', '33', '+33612345678', ''],
    ['06 12 34 56 78', '33', '+33612345678', ''],
    ['612345678', '33', '+33612345678', ''],
    ['33612345678', '33', '+33612345678', ''],
    ['0721 123 456', '40', '+40721123456', ''],
    ['0040 721 123 456', '33', '+40721123456', ''],
    ['(+40) 721-123-456', '33', '+40721123456', ''],
    ['40721123456', '40', '+40721123456', ''],
    ['(415) 555-0123', '1', '+14155550123', ''],
    ['14155550123', '1', '+14155550123', ''],
    ['+1 415 555 0123', '', '+14155550123', ''],
    ['612 345 678', '34', '+34612345678', ''],
    ['3123456789', '39', '+393123456789', ''],
    ['06 1234 5678', '39', '+390612345678', ''],
    ['8 916 123-45-67', '7', '+79161234567', ''],
    ['07 07 12 34 56', '225', '+2250707123456', ''],
    ['0151 23456789', '49', '+4915123456789', ''],
    ['+44 7700 900123', '44', '+447700900123', ''],
    ['0612345678', '', '', 'invalid'],
    ['', '33', '', 'missing'],
    ['   ', '33', '', 'missing'],
    [null, '33', '', 'missing'],
    ['abc', '33', '', 'invalid'],
    ['12', '33', '', 'invalid'],
    ['+33 6', '', '', 'invalid'],
    ['+1234567890123456', '', '', 'invalid'],
    ['+1 900 555 0123', '', '', 'premium'],
    ['0901 234 567', '40', '', 'premium'],
    ['08 91 23 45 67', '33', '', 'premium'],
    ['0900 1234567', '49', '', 'premium'],
    ['899 123 456', '39', '', 'premium'],
    ['806 123 456', '34', '', 'premium'],
    ['+44 909 879 0000', '', '', 'premium'],
    ['+881 6 12345678', '', '', 'premium'],
    ['+870 772 123 456', '', '', 'premium'],
    ['+3361234', '', '', 'premium'],
];
foreach ($numbers as $case) {
    check('analyse ' . var_export($case[0], true) . ' / ' . $case[1], PhoneNumber::analyse($case[0], $case[1]), [$case[2], $case[3]]);
}
check('normalize returns the number', PhoneNumber::normalize('06 12 34 56 78', 33), '+33612345678');
check('normalize returns empty for premium', PhoneNumber::normalize('+19005550123'), '');
check('isE164 good', PhoneNumber::isE164('+14155550123'), true);
check('isE164 leading zero', PhoneNumber::isE164('+0412345678'), false);

// Texts
$values = [
    'firstname' => 'Ana',
    'lastname' => 'Pop',
    'order_reference' => 'XKBKNABJK',
    'total_paid' => '25,90 €',
    'shop_name' => 'Ma Boutique',
    'tracking_number' => '',
    'tracking_url' => '',
    'status' => 'Shipped',
];
check(
    'empty placeholder leaves no trailing space',
    Template::render('Hello {firstname}, your order {order_reference} from {shop_name} is on its way. {tracking_url}', $values),
    'Hello Ana, your order XKBKNABJK from Ma Boutique is on its way.'
);
check(
    'all placeholders',
    Template::render('{firstname} {lastname} {order_reference} {total_paid} {shop_name} [{tracking_number}] [{tracking_url}] {status}', $values),
    'Ana Pop XKBKNABJK 25,90 € Ma Boutique [] [] Shipped'
);
check('line breaks kept', Template::render("Hello {firstname},\r\nyour order shipped.\n\n\n\nBye", $values), "Hello Ana,\nyour order shipped.\n\nBye");
check('control characters in values', Template::render('[{firstname}]', ['firstname' => "An\na\t\x07"]), '[An a]');
check('unknown placeholder kept', Template::render('{firstname} {nope}', $values), 'Ana {nope}');
check('non-scalar value ignored', Template::render('[{firstname}]', ['firstname' => ['x']]), '[]');
check('cut to 1600 characters', mb_strlen(Template::render(str_repeat('é', 2000), [])), 1600);
check('placeholder list', Template::placeholderList(), '{firstname} {lastname} {order_reference} {total_paid} {shop_name} {tracking_number} {tracking_url} {status}');

// API errors, read like the SDK reads them
check('error message', ApiException::messageOf(['message' => 'Invalid or expired API key', 'statusCode' => 401], 401), 'Invalid or expired API key');
check('error message list', ApiException::messageOf(['message' => ['to must be a string', 'message too long']], 400), 'to must be a string; message too long');
check('error label', ApiException::messageOf(['error' => 'Forbidden'], 403), 'Forbidden');
check('error text body', ApiException::messageOf('<html><body>Bad gateway</body></html>', 502), 'Bad gateway');
check('error without body', ApiException::messageOf(null, 503), 'SMSTunnel returned error: 503');
$e = new ApiException(403, ['message' => 'Missing permission', 'code' => 'PERMISSION_REQUIRED']);
check('error code', [$e->getStatus(), $e->getErrorCode(), $e->getCode()], [403, 'PERMISSION_REQUIRED', 403]);

// A closed port: a TransportException, quickly.
$started = microtime(true);
try {
    (new Client('sk_live_' . str_repeat('0', 16), 'http://127.0.0.1:9', 3, 2))->sendSms('+14155550123', 'x');
    check('closed port throws', 'no exception', 'TransportException');
} catch (TransportException $e) {
    check('closed port throws TransportException', true, true);
}
check('closed port fails fast', microtime(true) - $started < 3, true);

try {
    (new Client(''))->sendSms('+14155550123', 'x');
    check('empty key throws', 'no exception', 'ApiException');
} catch (ApiException $e) {
    check('empty key is a 401 before any call', $e->getStatus(), 401);
}

// End to end against a local SMSTunnel test server (never production).
$url = getenv('SMSTUNNEL_E2E_URL');
$key = getenv('SMSTUNNEL_E2E_KEY');
if ($url && $key) {
    if (strpos($url, 'smstunnel.io') !== false) {
        echo "Refusing to run end-to-end tests against production.\n";
        exit(1);
    }
    $client = new Client($key, $url, 10, 4, 'SMSTunnel-PrestaShop-tests');
    $phones = $client->getDevices();
    check('e2e: the account has phones', count($phones) > 0, true);
    $sent = $client->sendSms('+14155550123', 'SMSTunnel PrestaShop unit test ' . date('c'), null, '+1');
    check('e2e: message id', strlen($sent['messageId']) > 0, true);
    echo 'e2e: sent ', $sent['messageId'], $sent['queued'] ? ' (queued)' : '', "\n";
    try {
        (new Client('sk_live_' . str_repeat('0', 32), $url))->sendSms('+14155550123', 'x');
        check('e2e: wrong key refused', 'accepted', '401');
    } catch (ApiException $e) {
        check('e2e: wrong key refused with 401', $e->getStatus(), 401);
    }
} else {
    echo "(end-to-end part skipped: set SMSTUNNEL_E2E_URL and SMSTUNNEL_E2E_KEY to run it)\n";
}

printf("PHP %s: %d passed, %d failed\n", PHP_VERSION, $passed, $failed);
exit($failed > 0 ? 1 : 0);
