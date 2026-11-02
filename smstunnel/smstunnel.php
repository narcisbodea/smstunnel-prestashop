<?php

/**
 * SMSTunnel: SMS notifications for PrestaShop.
 *
 * Texts customers about their orders, and the shop owner about new orders, from the merchant's own
 * Android phone and own number, through SMSTunnel (https://smstunnel.io).
 *
 * @author    Narcis Bodea <contact@narboweb.ro>
 * @copyright 2026 NARBOWEB SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use SmsTunnel\PrestaShop\Api\ApiException;
use SmsTunnel\PrestaShop\Api\Client;
use SmsTunnel\PrestaShop\Api\TransportException;
use SmsTunnel\PrestaShop\Log\TextLog;
use SmsTunnel\PrestaShop\Message\DefaultTexts;
use SmsTunnel\PrestaShop\Message\Template;
use SmsTunnel\PrestaShop\Notifier;
use SmsTunnel\PrestaShop\Phone\PhoneNumber;
use SmsTunnel\PrestaShop\Settings;

class Smstunnel extends Module
{
    public const GOOGLE_PLAY_URL = 'https://play.google.com/store/apps/details?id=io.smstunnel.app';
    public const DASHBOARD_URL = 'https://dashboard.smstunnel.io/apps';

    /** Texts longer than this are refused when saving (SMSTunnel accepts 1600 characters). */
    public const MAX_TEXT = 1600;

    /** @var Notifier|null */
    private $notifier;

    public function __construct()
    {
        $this->name = 'smstunnel';
        $this->tab = 'emailing';
        $this->version = '1.0.0';
        $this->author = 'NARBOWEB SRL';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '8.1.0', 'max' => '9.99.99'];

        parent::__construct();

        $this->displayName = $this->trans('SMSTunnel: SMS notifications', [], 'Modules.Smstunnel.Admin');
        $this->description = $this->trans('Text your customers about their orders, and yourself about new orders, from your own phone and your own number.', [], 'Modules.Smstunnel.Admin');
        $this->confirmUninstall = $this->trans('Remove SMSTunnel, its settings and its log of texts?', [], 'Modules.Smstunnel.Admin');
    }

    /**
     * @return bool
     */
    public function isUsingNewTranslationSystem()
    {
        return true;
    }

    /**
     * @return bool
     */
    public function install()
    {
        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }
        $states = array_values(array_filter([
            (int) Configuration::get('PS_OS_SHIPPING'),
            (int) Configuration::get('PS_OS_DELIVERED'),
            (int) Configuration::get('PS_OS_CANCELED'),
        ]));

        return parent::install()
            && TextLog::install()
            && Configuration::updateValue(Settings::API_URL, Client::DEFAULT_URL)
            && Configuration::updateValue(Settings::OWNER_ENABLED, 1)
            && Configuration::updateValue(Settings::CUSTOMER_ENABLED, 1)
            && Configuration::updateValue(Settings::CUSTOMER_STATES, json_encode($states), true)
            && $this->registerHook([
                'actionValidateOrder',
                'actionOrderStatusPostUpdate',
                'actionDeleteGDPRCustomer',
                'actionExportGDPRData',
            ]);
    }

    /**
     * @return bool
     */
    public function uninstall()
    {
        if (!parent::uninstall()) {
            return false;
        }
        foreach (Settings::KEYS as $key) {
            Configuration::deleteByName($key);
        }

        return TextLog::uninstall();
    }

    /**
     * A new order: text the shop owner. Never stops the checkout.
     *
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionValidateOrder($params)
    {
        $order = isset($params['order']) ? $params['order'] : null;
        if (!$order instanceof Order) {
            return;
        }
        try {
            $state = isset($params['orderStatus']) && $params['orderStatus'] instanceof OrderState ? $params['orderStatus'] : null;
            $this->getNotifier()->newOrder($order, $state);
        } catch (Throwable $e) {
            $this->logFailure('new-order text: ' . $e->getMessage(), (int) $order->id);
        }
    }

    /**
     * An order changed status: text the customer if the merchant chose that status. Never stops the page.
     *
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionOrderStatusPostUpdate($params)
    {
        $idOrder = isset($params['id_order']) ? (int) $params['id_order'] : 0;
        try {
            $this->getNotifier()->statusChanged(
                $idOrder,
                isset($params['newOrderStatus']) ? $params['newOrderStatus'] : null,
                isset($params['oldOrderStatus']) ? $params['oldOrderStatus'] : null
            );
        } catch (Throwable $e) {
            $this->logFailure('status text: ' . $e->getMessage(), $idOrder);
        }
    }

    /**
     * GDPR (PrestaShop's psgdpr module): export the texts sent to a customer about their orders.
     *
     * @param array<string, mixed> $customer
     *
     * @return string JSON
     */
    public function hookActionExportGDPRData($customer)
    {
        $rows = [];
        foreach ($this->gdprCustomerIds($customer) as $idCustomer) {
            $rows = array_merge($rows, $this->getTextLog()->forCustomer($idCustomer));
        }

        return (string) json_encode($rows ?: $this->trans('No texts were sent about this customer.', [], 'Modules.Smstunnel.Admin'));
    }

    /**
     * GDPR (PrestaShop's psgdpr module): delete the texts about a customer's orders from the log.
     *
     * @param array<string, mixed> $customer
     *
     * @return string JSON
     */
    public function hookActionDeleteGDPRCustomer($customer)
    {
        $ok = true;
        foreach ($this->gdprCustomerIds($customer) as $idCustomer) {
            $ok = $this->getTextLog()->deleteForCustomer($idCustomer) && $ok;
        }

        return (string) json_encode($ok ? true : $this->trans('The texts about this customer could not be deleted.', [], 'Modules.Smstunnel.Admin'));
    }

    /**
     * The module's configuration page.
     *
     * @return string
     */
    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submitSmstunnelSettings')) {
            $output .= $this->saveSettings();
        } elseif (Tools::isSubmit('submitSmstunnelTest')) {
            $output .= $this->sendTestText();
        } elseif (Tools::isSubmit('submitSmstunnelResend')) {
            $output .= $this->resendText((int) Tools::getValue('id_smstunnel_log'));
        }

        $this->context->controller->addCSS($this->_path . 'views/css/admin.css');
        $this->context->controller->addJS($this->_path . 'views/js/admin.js');

        return $output . $this->renderStatus() . $this->renderSettingsForm() . $this->renderLog();
    }

    /**
     * Validates and saves the settings form.
     */
    private function saveSettings(): string
    {
        $errors = [];
        $idLangDefault = (int) Configuration::get('PS_LANG_DEFAULT');

        $apiKey = trim((string) Tools::getValue('SMSTUNNEL_API_KEY'));
        if ($apiKey !== '' && !preg_match('/^sk_(live|test)_[A-Za-z0-9]{16,128}$/', $apiKey)) {
            $errors[] = $this->trans('This does not look like an SMSTunnel API key. Copy it again from Apps in your SMSTunnel dashboard.', [], 'Modules.Smstunnel.Admin');
        }
        $deviceId = trim((string) Tools::getValue('SMSTUNNEL_DEVICE_ID'));
        if ($deviceId !== '' && !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $deviceId)) {
            $errors[] = $this->trans('This does not look like a phone ID. Copy it again from Phones in your SMSTunnel dashboard, or leave the field empty.', [], 'Modules.Smstunnel.Admin');
        }
        $apiUrl = rtrim(trim((string) Tools::getValue('SMSTUNNEL_API_URL')), '/');
        if ($apiUrl === '') {
            $apiUrl = Client::DEFAULT_URL;
        }
        if (!filter_var($apiUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $apiUrl)) {
            $errors[] = $this->trans('The server address must start with https:// (or http:// for a test server).', [], 'Modules.Smstunnel.Admin');
        }

        $ownerEnabled = (bool) Tools::getValue('SMSTUNNEL_OWNER_ENABLED');
        $ownerNumbers = [];
        $prefix = Notifier::shopCallPrefix((int) $this->context->shop->id);
        foreach (Settings::splitNumbers((string) Tools::getValue('SMSTUNNEL_OWNER_NUMBERS')) as $raw) {
            list($number, $problem) = PhoneNumber::analyse($raw, $prefix);
            if ($problem !== PhoneNumber::OK) {
                $errors[] = $this->trans('%s is not a mobile number SMSTunnel can text. Write it in international format, such as +33612345678.', [$raw], 'Modules.Smstunnel.Admin');
            } elseif (!in_array($number, $ownerNumbers, true)) {
                $ownerNumbers[] = $number;
            }
        }
        if (count($ownerNumbers) > Settings::MAX_OWNER_NUMBERS) {
            $errors[] = $this->trans('New-order texts can go to at most %d numbers.', [Settings::MAX_OWNER_NUMBERS], 'Modules.Smstunnel.Admin');
        }
        if ($ownerEnabled && !$ownerNumbers) {
            $errors[] = $this->trans('Add your number for new-order texts, or turn them off.', [], 'Modules.Smstunnel.Admin');
        }

        // A text left empty, or left as suggested, is not stored: the suggested text (in the language of
        // each order) is used, and it follows the module's updates.
        $allTexts = [];
        $ownerText = self::cleanText((string) Tools::getValue('SMSTUNNEL_OWNER_TEXT'));
        $allTexts[] = $ownerText;
        if ($ownerText === DefaultTexts::owner($idLangDefault)) {
            $ownerText = '';
        }

        $customerEnabled = (bool) Tools::getValue('SMSTUNNEL_CUSTOMER_ENABLED');
        $states = [];
        $texts = [];
        foreach ($this->orderStates() as $state) {
            $idState = (int) $state['id_order_state'];
            if (Tools::getValue('SMSTUNNEL_STATES_' . $idState)) {
                $states[] = $idState;
            }
            foreach (Language::getLanguages(false) as $language) {
                $idLang = (int) $language['id_lang'];
                $text = self::cleanText((string) Tools::getValue('SMSTUNNEL_TEXT_' . $idState . '_' . $idLang));
                $allTexts[] = $text;
                if ($text !== '' && $text !== DefaultTexts::customer($idState, $idLang)) {
                    $texts[$idState][$idLang] = $text;
                }
            }
        }
        foreach ($allTexts as $text) {
            if (Tools::strlen($text) > self::MAX_TEXT) {
                $errors[] = $this->trans('A text can be at most %d characters long.', [self::MAX_TEXT], 'Modules.Smstunnel.Admin');
                break;
            }
        }

        if ($errors) {
            return $this->displayError(array_map([$this, 'escape'], array_unique($errors)));
        }

        if ($apiKey !== '') {
            Configuration::updateValue(Settings::API_KEY, $apiKey);
        }
        Configuration::updateValue(Settings::DEVICE_ID, $deviceId);
        Configuration::updateValue(Settings::API_URL, $apiUrl);
        Configuration::updateValue(Settings::OWNER_ENABLED, (int) $ownerEnabled);
        Configuration::updateValue(Settings::OWNER_NUMBERS, implode(',', $ownerNumbers));
        Configuration::updateValue(Settings::OWNER_TEXT, $ownerText, true);
        Configuration::updateValue(Settings::CUSTOMER_ENABLED, (int) $customerEnabled);
        Configuration::updateValue(Settings::CUSTOMER_STATES, (string) json_encode($states), true);
        Configuration::updateValue(Settings::CUSTOMER_TEXTS, (string) json_encode($texts, JSON_UNESCAPED_UNICODE), true);

        return $this->displayConfirmation($this->escape($this->trans('Settings saved.', [], 'Modules.Smstunnel.Admin')));
    }

    /**
     * Sends a test text to the number typed on the page.
     */
    private function sendTestText(): string
    {
        $settings = new Settings();
        if ($settings->apiKey() === '') {
            return $this->displayError($this->escape($this->trans('Save your API key first.', [], 'Modules.Smstunnel.Admin')));
        }
        $raw = trim((string) Tools::getValue('SMSTUNNEL_TEST_NUMBER'));
        $prefix = Notifier::shopCallPrefix((int) $this->context->shop->id);
        list($number, $problem) = PhoneNumber::analyse($raw, $prefix);
        if ($problem !== PhoneNumber::OK) {
            return $this->displayError($this->escape($this->trans('%s is not a mobile number SMSTunnel can text. Write it in international format, such as +33612345678.', [$raw], 'Modules.Smstunnel.Admin')));
        }

        $message = Template::render(DefaultTexts::test((int) $this->context->language->id), [
            'shop_name' => (string) Configuration::get('PS_SHOP_NAME'),
        ]);
        $result = $this->getNotifier()->test($settings, $number, $message, $prefix, (int) $this->context->shop->id);

        if ($result['status'] === TextLog::QUEUED) {
            return $this->displayWarning($this->escape($this->trans('Your phone is offline. The test text to %s waits on your account and goes out when the phone is back online.', [$number], 'Modules.Smstunnel.Admin')));
        }
        if ($result['status'] === TextLog::SENT) {
            return $this->displayConfirmation($this->escape($this->trans('Test text sent to %s. It leaves from your phone in a moment.', [$number], 'Modules.Smstunnel.Admin')));
        }

        return $this->displayError($this->escape($this->describeError($result['exception'], $result['error'])));
    }

    /**
     * Sends a text that did not go out once more, from the log.
     */
    private function resendText(int $id): string
    {
        $row = $this->getTextLog()->find($id);
        if ($row === null || $row['status'] !== TextLog::FAILED) {
            return $this->displayError($this->escape($this->trans('Only a text that did not go out can be sent again.', [], 'Modules.Smstunnel.Admin')));
        }
        $settings = new Settings(null, (int) $row['id_shop'] > 0 ? (int) $row['id_shop'] : null);
        if ($settings->apiKey() === '') {
            return $this->displayError($this->escape($this->trans('Save your API key first.', [], 'Modules.Smstunnel.Admin')));
        }
        $result = $this->getNotifier()->resend($settings, $row);
        $number = (string) $row['recipient'];
        if ($result === null) {
            return $this->displayError($this->escape($this->trans('Only a text that did not go out can be sent again.', [], 'Modules.Smstunnel.Admin')));
        }
        if ($result['status'] === TextLog::QUEUED) {
            return $this->displayWarning($this->escape($this->trans('Your phone is offline. The text to %s waits on your account and goes out when the phone is back online.', [$number], 'Modules.Smstunnel.Admin')));
        }
        if ($result['status'] === TextLog::SENT) {
            return $this->displayConfirmation($this->escape($this->trans('Text sent again to %s.', [$number], 'Modules.Smstunnel.Admin')));
        }

        return $this->displayError($this->escape($this->describeError($result['exception'], $result['error'])));
    }

    /**
     * The panel above the settings: how to start, the phones of the account and the test text.
     */
    private function renderStatus(): string
    {
        $settings = new Settings();
        $hasKey = $settings->apiKey() !== '';
        $phones = [];
        $phonesError = '';
        if ($hasKey) {
            try {
                $client = new Client($settings->apiKey(), $settings->apiUrl(), 6, 3, $this->userAgent());
                $phones = $client->getDevices();
            } catch (Throwable $e) {
                $phonesError = $this->describeError($e, $e->getMessage());
            }
        }
        $deviceId = $settings->deviceId();
        $deviceUnknown = $deviceId !== '' && $phonesError === '' && !in_array($deviceId, array_column($phones, 'id'), true);
        $failure = $this->getTextLog()->lastFailure((int) $this->context->shop->id);
        $ownerNumbers = $settings->ownerNumbers();

        $this->context->smarty->assign([
            'smstunnel' => [
                'has_key' => $hasKey,
                'phones' => $phones,
                'phones_error' => $phonesError,
                'device_id' => $deviceId,
                'device_unknown' => $deviceUnknown,
                'test_number' => (string) Tools::getValue('SMSTUNNEL_TEST_NUMBER', $ownerNumbers ? $ownerNumbers[0] : ''),
                'action' => $this->configureUrl(),
                'google_play_url' => self::GOOGLE_PLAY_URL,
                'dashboard_url' => self::DASHBOARD_URL,
                'test_server' => $settings->apiUrl() !== Client::DEFAULT_URL ? $settings->apiUrl() : '',
                'last_failure' => $failure ? [
                    'date' => (string) $failure['date_upd'],
                    'error' => (string) $failure['error'],
                    'reference' => (string) $failure['id_order'],
                ] : null,
            ],
        ]);

        return $this->context->smarty->fetch($this->local_path . 'views/templates/admin/status.tpl');
    }

    /**
     * The settings form (PrestaShop's HelperForm).
     */
    private function renderSettingsForm(): string
    {
        $settings = new Settings();
        $languages = Language::getLanguages(false);
        $idLangDefault = (int) Configuration::get('PS_LANG_DEFAULT');
        $submitted = Tools::isSubmit('submitSmstunnelSettings');
        $placeholders = $this->trans('You can use: %s', [Template::placeholderList()], 'Modules.Smstunnel.Admin');
        $yesNo = [
            ['id' => 'active_on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Admin.Global')],
            ['id' => 'active_off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')],
        ];

        $apiKey = $settings->apiKey();
        $keyHelp = $apiKey !== ''
            ? $this->trans('A key is saved (%s). Leave this field empty to keep it.', [substr($apiKey, 0, 8) . '...' . substr($apiKey, -4)], 'Modules.Smstunnel.Admin')
            : $this->trans('In your SMSTunnel dashboard, open Apps, then Add an app, and copy its key.', [], 'Modules.Smstunnel.Admin');

        $account = [
            'form' => [
                'legend' => ['title' => $this->escape($this->trans('Your SMSTunnel account', [], 'Modules.Smstunnel.Admin')), 'icon' => 'icon-key'],
                'input' => [
                    [
                        'type' => 'password',
                        'label' => $this->escape($this->trans('API key', [], 'Modules.Smstunnel.Admin')),
                        'name' => 'SMSTUNNEL_API_KEY',
                        'autocomplete' => false,
                        'class' => 'fixed-width-xxl',
                        'desc' => $this->escape($keyHelp),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->escape($this->trans('Phone ID', [], 'Modules.Smstunnel.Admin')),
                        'name' => 'SMSTUNNEL_DEVICE_ID',
                        'class' => 'fixed-width-xxl',
                        'desc' => $this->escape($this->trans('Optional. Leave it empty to send from any of your phones. To always use one phone, copy its ID from Phones in your SMSTunnel dashboard.', [], 'Modules.Smstunnel.Admin')),
                    ],
                ],
                'submit' => ['title' => $this->escape($this->trans('Save', [], 'Admin.Actions')), 'name' => 'submitSmstunnelSettings'],
            ],
        ];

        $owner = [
            'form' => [
                'legend' => ['title' => $this->escape($this->trans('New orders: a text to you', [], 'Modules.Smstunnel.Admin')), 'icon' => 'icon-shopping-cart'],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->escape($this->trans('Text me about new orders', [], 'Modules.Smstunnel.Admin')),
                        'name' => 'SMSTUNNEL_OWNER_ENABLED',
                        'is_bool' => true,
                        'values' => $yesNo,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->escape($this->trans('Your numbers', [], 'Modules.Smstunnel.Admin')),
                        'name' => 'SMSTUNNEL_OWNER_NUMBERS',
                        'class' => 'fixed-width-xxl',
                        'desc' => $this->escape($this->trans('One or more numbers (at most 5), separated by commas, in international format such as +33612345678.', [], 'Modules.Smstunnel.Admin')),
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->escape($this->trans('Text', [], 'Modules.Smstunnel.Admin')),
                        'name' => 'SMSTUNNEL_OWNER_TEXT',
                        'rows' => 3,
                        'desc' => $this->escape($placeholders),
                    ],
                ],
                'submit' => ['title' => $this->escape($this->trans('Save', [], 'Admin.Actions')), 'name' => 'submitSmstunnelSettings'],
            ],
        ];

        $stateOptions = [];
        $stateInputs = [];
        $chosen = $settings->customerStates();
        $values = [];
        foreach ($this->orderStates() as $state) {
            $idState = (int) $state['id_order_state'];
            $name = $this->escape((string) $state['name']);
            $stateOptions[] = ['id_order_state' => $idState, 'name' => $name];
            $values['SMSTUNNEL_STATES_' . $idState] = $submitted
                ? (bool) Tools::getValue('SMSTUNNEL_STATES_' . $idState)
                : in_array($idState, $chosen, true);
            $stateInputs[] = [
                'type' => 'textarea',
                'label' => $this->escape($this->trans('Text for "%s"', [(string) $state['name']], 'Modules.Smstunnel.Admin')),
                'name' => 'SMSTUNNEL_TEXT_' . $idState,
                'lang' => true,
                'rows' => 3,
                'form_group_class' => 'smstunnel-state-text smstunnel-state-' . $idState,
            ];
            foreach ($languages as $language) {
                $idLang = (int) $language['id_lang'];
                $saved = $settings->customerText($idState, $idLang);
                $default = $saved !== '' ? $saved : DefaultTexts::customer($idState, $idLang);
                $values['SMSTUNNEL_TEXT_' . $idState][$idLang] = $submitted
                    ? (string) Tools::getValue('SMSTUNNEL_TEXT_' . $idState . '_' . $idLang, $default)
                    : $default;
            }
        }

        $customer = [
            'form' => [
                'legend' => ['title' => $this->escape($this->trans('Order updates: a text to the customer', [], 'Modules.Smstunnel.Admin')), 'icon' => 'icon-user'],
                'description' => $this->escape($this->trans('The text goes to the mobile number of the order address, in the language of the order. Customers can reply: the answer reaches your phone.', [], 'Modules.Smstunnel.Admin')),
                'input' => array_merge([
                    [
                        'type' => 'switch',
                        'label' => $this->escape($this->trans('Text customers about their orders', [], 'Modules.Smstunnel.Admin')),
                        'name' => 'SMSTUNNEL_CUSTOMER_ENABLED',
                        'is_bool' => true,
                        'values' => $yesNo,
                    ],
                    [
                        'type' => 'checkbox',
                        'label' => $this->escape($this->trans('When the order status becomes', [], 'Modules.Smstunnel.Admin')),
                        'name' => 'SMSTUNNEL_STATES',
                        'class' => 'smstunnel-state-box',
                        'values' => ['query' => $stateOptions, 'id' => 'id_order_state', 'name' => 'name'],
                        'desc' => $this->escape($placeholders),
                    ],
                ], $stateInputs),
                'submit' => ['title' => $this->escape($this->trans('Save', [], 'Admin.Actions')), 'name' => 'submitSmstunnelSettings'],
            ],
        ];

        $advanced = [
            'form' => [
                'legend' => ['title' => $this->escape($this->trans('Advanced', [], 'Modules.Smstunnel.Admin')), 'icon' => 'icon-cogs'],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->escape($this->trans('SMSTunnel server', [], 'Modules.Smstunnel.Admin')),
                        'name' => 'SMSTUNNEL_API_URL',
                        'class' => 'fixed-width-xxl',
                        'desc' => $this->escape($this->trans('For testing only. Your shop uses https://smstunnel.io: change it only to try the module with a test server.', [], 'Modules.Smstunnel.Admin')),
                    ],
                ],
                'submit' => ['title' => $this->escape($this->trans('Save', [], 'Admin.Actions')), 'name' => 'submitSmstunnelSettings'],
            ],
        ];

        $ownerText = $settings->ownerText();
        $values += [
            'SMSTUNNEL_API_KEY' => '',
            'SMSTUNNEL_DEVICE_ID' => $submitted ? (string) Tools::getValue('SMSTUNNEL_DEVICE_ID') : $settings->deviceId(),
            'SMSTUNNEL_API_URL' => $submitted ? (string) Tools::getValue('SMSTUNNEL_API_URL') : $settings->apiUrl(),
            'SMSTUNNEL_OWNER_ENABLED' => $submitted ? (int) Tools::getValue('SMSTUNNEL_OWNER_ENABLED') : (int) $settings->ownerEnabled(),
            'SMSTUNNEL_OWNER_NUMBERS' => $submitted ? (string) Tools::getValue('SMSTUNNEL_OWNER_NUMBERS') : implode(', ', $settings->ownerNumbers()),
            'SMSTUNNEL_OWNER_TEXT' => $submitted
                ? (string) Tools::getValue('SMSTUNNEL_OWNER_TEXT')
                : ($ownerText !== '' ? $ownerText : DefaultTexts::owner($idLangDefault)),
            'SMSTUNNEL_CUSTOMER_ENABLED' => $submitted ? (int) Tools::getValue('SMSTUNNEL_CUSTOMER_ENABLED') : (int) $settings->customerEnabled(),
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->table = $this->table;
        $helper->identifier = $this->identifier;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = $this->moduleAdminLink();
        $helper->submit_action = 'submitSmstunnelSettings';
        $helper->default_form_language = $idLangDefault;
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->tpl_vars = [
            'fields_value' => $values,
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => (int) $this->context->language->id,
        ];

        return $helper->generateForm([$account, $owner, $customer, $advanced]);
    }

    /**
     * The last texts, newest first.
     */
    private function renderLog(): string
    {
        $stateNames = [];
        foreach ($this->orderStates() as $state) {
            $stateNames[(int) $state['id_order_state']] = (string) $state['name'];
        }
        $labels = [
            TextLog::SENT => $this->trans('Sent to the phone', [], 'Modules.Smstunnel.Admin'),
            TextLog::QUEUED => $this->trans('Waiting for the phone', [], 'Modules.Smstunnel.Admin'),
            TextLog::FAILED => $this->trans('Not sent', [], 'Modules.Smstunnel.Admin'),
            TextLog::SKIPPED => $this->trans('Skipped', [], 'Modules.Smstunnel.Admin'),
            TextLog::SENDING => $this->trans('Sending', [], 'Modules.Smstunnel.Admin'),
        ];
        $reasons = [
            'number:' . PhoneNumber::MISSING => $this->trans('No mobile number on the order address.', [], 'Modules.Smstunnel.Admin'),
            'number:' . PhoneNumber::INVALID => $this->trans('The number on the order address is not a mobile number SMSTunnel can text.', [], 'Modules.Smstunnel.Admin'),
            'number:' . PhoneNumber::PREMIUM => $this->trans('Premium-rate numbers and short codes are never texted.', [], 'Modules.Smstunnel.Admin'),
        ];

        $rows = [];
        foreach ($this->getTextLog()->recent(20, (int) $this->context->shop->id) as $row) {
            $event = (string) $row['event'];
            if ($event === 'new_order') {
                $what = $this->trans('New order, text to you', [], 'Modules.Smstunnel.Admin');
            } elseif ($event === 'test') {
                $what = $this->trans('Test text', [], 'Modules.Smstunnel.Admin');
            } else {
                $stateName = isset($stateNames[(int) $row['id_order_state']]) ? $stateNames[(int) $row['id_order_state']] : '#' . (int) $row['id_order_state'];
                $what = $this->trans('Status: %s', [$stateName], 'Modules.Smstunnel.Admin');
            }
            $error = (string) $row['error'];
            $rows[] = [
                'id' => (int) $row['id_smstunnel_log'],
                'date' => (string) $row['date_upd'],
                'what' => $what,
                'order' => (int) $row['id_order'] > 0 ? (string) ($row['reference'] ?: '#' . (int) $row['id_order']) : '',
                'order_url' => (int) $row['id_order'] > 0 ? $this->orderUrl((int) $row['id_order']) : '',
                'recipient' => (string) $row['recipient'],
                'message' => (string) $row['message'],
                'status' => (string) $row['status'],
                'status_label' => isset($labels[$row['status']]) ? $labels[$row['status']] : (string) $row['status'],
                'detail' => isset($reasons[$error]) ? $reasons[$error] : $error,
                'attempts' => (int) $row['attempts'],
            ];
        }

        $this->context->smarty->assign([
            'smstunnel_log' => $rows,
            'smstunnel_action' => $this->configureUrl(),
        ]);

        return $this->context->smarty->fetch($this->local_path . 'views/templates/admin/log.tpl');
    }

    /**
     * A sentence for the merchant about why a call to SMSTunnel failed.
     *
     * @param Throwable|null $error
     */
    private function describeError($error, string $fallback): string
    {
        if ($error instanceof ApiException) {
            $status = $error->getStatus();
            if ($status === 401) {
                return $this->trans('SMSTunnel refused the API key. Check it in your SMSTunnel dashboard, under Apps.', [], 'Modules.Smstunnel.Admin');
            }
            if ($status === 403 && $error->getErrorCode() === 'PERMISSION_REQUIRED') {
                return $this->trans('This API key may not send texts. Create a new one under Apps, then Add an app.', [], 'Modules.Smstunnel.Admin');
            }
            if ($status === 429) {
                return $this->trans('Too many texts at once, or the daily limit of your plan is reached. SMSTunnel says: %s', [$error->getMessage()], 'Modules.Smstunnel.Admin');
            }
            if ($status >= 500) {
                return $this->trans('SMSTunnel had a problem (HTTP %d). Try again in a few minutes.', [$status], 'Modules.Smstunnel.Admin');
            }

            return $this->trans('SMSTunnel refused the text: %s', [$error->getMessage()], 'Modules.Smstunnel.Admin');
        }
        if ($error instanceof TransportException) {
            return $this->trans('SMSTunnel could not be reached: %s', [$error->getMessage()], 'Modules.Smstunnel.Admin');
        }

        return $fallback;
    }

    /**
     * The order statuses of the shop, in the employee's language.
     *
     * @return array<int, array<string, mixed>>
     */
    private function orderStates(): array
    {
        return OrderState::getOrderStates((int) $this->context->language->id);
    }

    /**
     * @param array<string, mixed>|mixed $customer
     *
     * @return int[]
     */
    private function gdprCustomerIds($customer): array
    {
        $customer = (array) $customer;
        if (!empty($customer['id'])) {
            return [(int) $customer['id']];
        }
        $ids = [];
        if (!empty($customer['email']) && Validate::isEmail($customer['email'])) {
            foreach (Customer::getCustomersByEmail((string) $customer['email']) as $row) {
                $ids[] = (int) $row['id_customer'];
            }
        }

        return $ids;
    }

    /**
     * The module's configuration page, without its token (as native modules build it: an absolute link
     * that works whether the page is shown by the legacy controller or by PrestaShop 9's route).
     */
    private function moduleAdminLink(): string
    {
        return $this->context->link->getAdminLink('AdminModules', false) . '&' . http_build_query([
            'configure' => $this->name,
            'tab_module' => $this->tab,
            'module_name' => $this->name,
        ]);
    }

    /**
     * The module's configuration page, with its token: where the page's own forms post.
     */
    private function configureUrl(): string
    {
        return $this->moduleAdminLink() . '&token=' . Tools::getAdminTokenLite('AdminModules');
    }

    private function orderUrl(int $idOrder): string
    {
        try {
            return (string) $this->context->link->getAdminLink('AdminOrders', true, ['route' => 'admin_orders_view', 'orderId' => $idOrder]);
        } catch (Throwable $e) {
            return '';
        }
    }

    private function getNotifier(): Notifier
    {
        if ($this->notifier === null) {
            $this->notifier = new Notifier($this->getTextLog(), $this->userAgent());
        }

        return $this->notifier;
    }

    private function getTextLog(): TextLog
    {
        return new TextLog();
    }

    private function userAgent(): string
    {
        return 'SMSTunnel-PrestaShop/' . $this->version . ' (PrestaShop ' . _PS_VERSION_ . '; PHP ' . PHP_VERSION . ')';
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * A text as typed in a textarea, with plain line breaks.
     */
    private static function cleanText(string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }

    /**
     * Writes a failure to PrestaShop's log with core classes only, so it works even if the module's own
     * classes could not be loaded.
     */
    private function logFailure(string $message, int $idOrder): void
    {
        try {
            $clean = trim((string) preg_replace('/[<>{}]+/', ' ', 'SMSTunnel: ' . $message));
            PrestaShopLogger::addLog(substr($clean, 0, 1000), 3, null, $idOrder > 0 ? 'Order' : null, $idOrder > 0 ? $idOrder : null, true);
        } catch (Throwable $e) {
            return;
        }
    }
}
