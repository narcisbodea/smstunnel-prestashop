<?php

/**
 * Test helper, run inside a throwaway test shop's container (never on a real shop):
 *   php ps-helper.php state-id PS_OS_SHIPPING          id of a status
 *   php ps-helper.php set-state <id_order> <id_state>  changes the status the way the back office does
 *   php ps-helper.php fire-status <id_order> <new> <old> <times>   runs actionOrderStatusPostUpdate again
 *   php ps-helper.php fire-validate <id_order> <times>             runs actionValidateOrder again
 *   php ps-helper.php log <id_order>                   the module's log rows of an order
 *   php ps-helper.php gdpr <id_customer>               export, then erase, a customer's texts
 * Prints JSON.
 *
 * @author    Narcis Bodea <contact@narboweb.ro>
 * @copyright 2026 NARBOWEB SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require '/var/www/html/config/config.inc.php';

$action = isset($argv[1]) ? $argv[1] : '';
$arg = function ($i) use ($argv) {
    return isset($argv[$i]) ? $argv[$i] : null;
};
$out = ['action' => $action];
// Hooks fired by this helper run in SMSTunnel only: other modules may need what a CLI script lacks.
$onlySmstunnel = (int) Module::getModuleIdByName('smstunnel');

switch ($action) {
    case 'state-id':
        $out['id'] = (int) Configuration::get((string) $arg(2));
        break;

    case 'set-state':
        $order = new Order((int) $arg(2));
        // What the back office has in its context: a status that marks the order paid records a payment.
        $context = Context::getContext();
        $context->currency = new Currency((int) $order->id_currency);
        $context->employee = new Employee(1);
        $history = new OrderHistory();
        $history->id_order = (int) $order->id;
        $history->id_employee = 1;
        $history->changeIdOrderState((int) $arg(3), $order);
        $out['added'] = (bool) $history->add();
        break;

    case 'fire-status':
        $order = new Order((int) $arg(2));
        for ($i = 0; $i < (int) $arg(5); ++$i) {
            Hook::exec('actionOrderStatusPostUpdate', [
                'newOrderStatus' => new OrderState((int) $arg(3), (int) $order->id_lang),
                'oldOrderStatus' => new OrderState((int) $arg(4), (int) $order->id_lang),
                'id_order' => (int) $order->id,
            ], $onlySmstunnel, false, true, false, (int) $order->id_shop);
        }
        $out['fired'] = (int) $arg(5);
        break;

    case 'fire-validate':
        $order = new Order((int) $arg(2));
        for ($i = 0; $i < (int) $arg(3); ++$i) {
            Hook::exec('actionValidateOrder', [
                'cart' => new Cart((int) $order->id_cart),
                'order' => $order,
                'customer' => new Customer((int) $order->id_customer),
                'currency' => new Currency((int) $order->id_currency),
                'orderStatus' => new OrderState((int) $order->current_state, (int) $order->id_lang),
            ], $onlySmstunnel);
        }
        $out['fired'] = (int) $arg(3);
        break;

    case 'log':
        $out['rows'] = Db::getInstance()->executeS(
            'SELECT `id_smstunnel_log`, `event`, `id_order_state`, `recipient`, `status`, `error`, `attempts`, `message`
            FROM `' . _DB_PREFIX_ . 'smstunnel_log` WHERE `id_order` = ' . (int) $arg(2) . ' ORDER BY `id_smstunnel_log`'
        );
        break;

    case 'gdpr':
        $customer = new Customer((int) $arg(2));
        $module = Module::getInstanceByName('smstunnel');
        $out['export'] = json_decode($module->hookActionExportGDPRData((array) $customer), true);
        $out['delete'] = json_decode($module->hookActionDeleteGDPRCustomer((array) $customer), true);
        $out['after'] = json_decode($module->hookActionExportGDPRData((array) $customer), true);
        break;

    default:
        fwrite(STDERR, "unknown action\n");
        exit(1);
}

echo json_encode($out), "\n";
