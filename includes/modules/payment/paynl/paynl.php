<?php

/**
 * Pay. payment module for Zencart 1.5.7d
 * Updated for the new Pay. PHP SDK (paynl/php-sdk ^1.2)
 *
 * Authentication: use AT-code as username + API Token as password.
 * The old SL-code (Service ID) is still stored for the payment method icon.
 *
 * Requires: composer require paynl/php-sdk
 * Autoload: DIR_WS_MODULES/payment/paynl/vendor/autoload.php
 */

$paynlVendorAutoload = dirname(__FILE__) . '/vendor/autoload.php';
if (file_exists($paynlVendorAutoload)) {
    require_once $paynlVendorAutoload;
}

use PayNL\Sdk\Model\Request\OrderCreateRequest;
use PayNL\Sdk\Config\Config as PayNLConfig;
use PayNL\Sdk\Model\Customer;
use PayNL\Sdk\Model\Company;
use PayNL\Sdk\Model\Address;
use PayNL\Sdk\Model\Order as PayNLOrder;
use PayNL\Sdk\Model\Products;
use PayNL\Sdk\Model\Product;
use PayNL\Sdk\Model\Stats;
use PayNL\Sdk\Model\Amount;
use PayNL\Sdk\Exception\PayException;

class paynl
{
    var $code, $title, $description, $enabled;
    public $apiVersion = '3.0';

    /**
     * @param string $signature
     * @param string $apiVersion
     * @param string $code
     * @param int    $payment_method_id
     * @param string $payment_method_description  uppercase e.g. 'IDEAL'
     * @param string $title
     * @param string $public_title
     * @param string $description
     * @param int    $sort_order
     * @param bool   $enabled
     * @param int    $order_status
     * @param string $configuration_key
     */
    function __construct(
        $signature,
        $apiVersion,
        $code,
        $payment_method_id,
        $payment_method_description,
        $title,
        $public_title,
        $description,
        $sort_order,
        $enabled,
        $order_status,
        $configuration_key
    ) {
        global $order;

        $this->signature                  = $signature;
        $this->api_version                = $apiVersion;
        $this->code                       = $code;
        $this->title                      = $title;
        $this->public_title               = $public_title;
        $this->description                = $description;
        $this->sort_order                 = $sort_order;
        $this->enabled                    = $enabled;
        $this->order_status               = $order_status;
        $this->configuration_key          = $configuration_key;
        $this->payment_method_id          = $payment_method_id;
        $this->payment_method_description = $payment_method_description;

        if ($this->enabled === true) {
            // Require AT-code (username) and API Token (password)
            $atCode   = defined('MODULE_PAYMENT_PAYNL_' . $this->payment_method_description . '_AT_CODE')
                        ? constant('MODULE_PAYMENT_PAYNL_' . $this->payment_method_description . '_AT_CODE')
                        : '';
            $apiToken = defined('MODULE_PAYMENT_PAYNL_' . $this->payment_method_description . '_API_TOKEN')
                        ? constant('MODULE_PAYMENT_PAYNL_' . $this->payment_method_description . '_API_TOKEN')
                        : '';

            if (!zen_not_null($atCode) || !zen_not_null($apiToken)) {
                $this->description = '<div class="secWarning">' . MODULE_PAYMENT_PAYNL_ERROR_ADMIN_CONFIGURATION . '</div>' . $this->description;
                $this->enabled = false;
            }
        }

        if ($this->enabled === true) {
            if (isset($order) && is_object($order)) {
                $this->update_status();
            }
        }
    }

    function check()
    {
        global $db;
        if (!isset($this->_check)) {
            $check_query = $db->Execute(
                "SELECT configuration_value FROM " . TABLE_CONFIGURATION . "
                 WHERE configuration_key = '" . $this->configuration_key . "'"
            );
            $this->_check = $check_query->RecordCount();
        }
        return $this->_check;
    }

    function update_status()
    {
        global $order, $db;

        if (($this->enabled == true) && ((int)constant('MODULE_PAYMENT_PAYNL_' . $this->payment_method_description . '_ZONE') > 0)) {
            $check_flag = false;
            $check_query = $db->Execute(
                "SELECT zone_id FROM " . TABLE_ZONES_TO_GEO_ZONES . "
                 WHERE geo_zone_id = " . constant('MODULE_PAYMENT_PAYNL_' . $this->payment_method_description . '_ZONE') . "
                 AND zone_country_id = " . (int)$order->billing['country']['id'] . "
                 ORDER BY zone_id"
            );
            foreach ($check_query as $item) {
                if ($item['zone_id'] < 1) {
                    $check_flag = true;
                    break;
                } elseif ($item['zone_id'] == $order->billing['zone_id']) {
                    $check_flag = true;
                    break;
                }
            }

            if ($check_flag == false) {
                $this->enabled = false;
            }
        }
    }

    function javascript_validation()
    {
        return false;
    }

    function selection()
    {
        return [
            'id'     => $this->code,
            'module' => '<img src="https://static.pay.nl/payment_profiles/25x25/' . $this->payment_method_id . '.png" alt="' . htmlspecialchars($this->public_title) . '"> ' . $this->public_title,
        ];
    }

    function pre_confirmation_check()
    {
        return false;
    }

    function confirmation()
    {
        return false;
    }

    function process_button()
    {
        return false;
    }

    function before_process()
    {
        return false;
    }

    /**
     * after_process() is called after the order is inserted into the database.
     * Here we start the Pay. transaction and redirect the customer.
     */
    function after_process()
    {
        global $customer_id, $order, $insert_id;

        $desc = $this->payment_method_description;

        $atCode      = constant('MODULE_PAYMENT_PAYNL_' . $desc . '_AT_CODE');
        $apiToken    = constant('MODULE_PAYMENT_PAYNL_' . $desc . '_API_TOKEN');
        $serviceId   = defined('MODULE_PAYMENT_PAYNL_' . $desc . '_SERVICE_ID')
                       ? constant('MODULE_PAYMENT_PAYNL_' . $desc . '_SERVICE_ID')
                       : '';

        $config = new PayNLConfig();
        $config->setUsername($atCode);
        $config->setPassword($apiToken);

        // Build order total in cents
        $orderTotal = (float) $this->format_raw($order->info['total']);

        $request = new OrderCreateRequest();
        $request->setConfig($config);

        if ($serviceId) {
            $request->setServiceId($serviceId);
        }

        $request->setAmount(new Amount((int) round($orderTotal * 100), DEFAULT_CURRENCY));
        $request->setDescription('Order ' . $insert_id);
        $request->setPaymentMethodId((int) $this->payment_method_id);

        // Return URL (customer lands here after payment)
        $returnUrl    = $this->generateReturnURL('ext/modules/payment/paynl/return.php?method=' . $desc);
        // Exchange URL (server-to-server webhook)
        $exchangeUrl  = $this->generateReturnURL('ext/modules/payment/paynl/paynl_exchange.php?method=' . $desc);

        $request->setReturnurl($returnUrl);
        $request->setExchangeUrl($exchangeUrl);

        // Stats / extra data
        $stats = new Stats();
        $stats->setExtra1((string) $insert_id);
        $stats->setExtra2((string) $customer_id);
        $stats->setObject('zencart 1.5.7d');
        $stats->setTool('zencart-paynl-plugin');
        $request->setStats($stats);

        // Customer
        $b_address = $this->splitAddress(trim($order->billing['street_address']));
        $d_address = $this->splitAddress(trim($order->delivery['street_address']));

        $customer = new Customer();
        $customer->setFirstName(substr($order->delivery['firstname'], 0, 50));
        $customer->setLastName(substr($order->delivery['lastname'], 0, 50));
        $customer->setEmail($order->customer['email_address']);
        $customer->setPhone($order->customer['telephone']);
        $customer->setLanguage(strtoupper(isset($_SESSION['languages_code']) ? $_SESSION['languages_code'] : 'NL'));
        $customer->setIpAddress(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
        $request->setCustomer($customer);

        // Order / addresses
        $payOrder = new PayNLOrder();

        $deliveryAddress = new Address();
        $deliveryAddress->setCode('DEL');
        $deliveryAddress->setStreetName($d_address[0]);
        $deliveryAddress->setStreetNumber(substr($d_address[1], 0, 10));
        $deliveryAddress->setZipCode($order->delivery['postcode']);
        $deliveryAddress->setCity($order->delivery['city']);
        $deliveryAddress->setCountryCode($order->delivery['country']['iso_code_2']);
        $payOrder->setDeliveryAddress($deliveryAddress);

        $invoiceAddress = new Address();
        $invoiceAddress->setCode('INV');
        $invoiceAddress->setStreetName($b_address[0]);
        $invoiceAddress->setStreetNumber(substr($b_address[1], 0, 10));
        $invoiceAddress->setZipCode($order->billing['postcode']);
        $invoiceAddress->setCity($order->billing['city']);
        $invoiceAddress->setCountryCode($order->billing['country']['iso_code_2']);
        $payOrder->setInvoiceAddress($invoiceAddress);

        // Products
        $products = new Products();
        foreach ($order->products as $product) {
            list($productId) = explode(':', $product['id']);
            $p = new Product();
            $p->setId((string) $productId);
            $p->setDescription(substr($product['name'], 0, 100));
            $p->setType(Product::TYPE_ARTICLE);
            $p->setAmount((float) $product['final_price']);
            $p->setCurrency(DEFAULT_CURRENCY);
            $p->setQuantity((int) $product['qty']);
            $p->setVatPercentage(0);
            $products->addProduct($p);
        }

        // Shipping
        if ($order->info['shipping_cost'] > 0) {
            $ship = new Product();
            $ship->setId('shipcost');
            $ship->setDescription(substr($order->info['shipping_method'], 0, 100));
            $ship->setType(Product::TYPE_SHIPPING);
            $ship->setAmount((float) $order->info['shipping_cost']);
            $ship->setCurrency(DEFAULT_CURRENCY);
            $ship->setQuantity(1);
            $ship->setVatPercentage(0);
            $products->addProduct($ship);
        }

        // Tax lines
        $countTaxes = 1;
        foreach ($order->info['tax_groups'] as $tax_name => $tax_cost) {
            if ($tax_cost > 0) {
                $tax = new Product();
                $tax->setId('tax' . $countTaxes);
                $tax->setDescription(substr($tax_name, 0, 100));
                $tax->setType(Product::TYPE_HANDLING);
                $tax->setAmount((float) $tax_cost);
                $tax->setCurrency(DEFAULT_CURRENCY);
                $tax->setQuantity(1);
                $tax->setVatPercentage(0);
                $products->addProduct($tax);
            }
            $countTaxes++;
        }

        $payOrder->setProducts($products);
        $request->setOrder($payOrder);

        try {
            $payResult = $request->start();
            $orderId   = $payResult->getOrderId();
            $payUrl    = $payResult->getPaymentUrl();

            $this->insertPaynlTransaction(
                $orderId,
                $this->payment_method_id,
                (int) round($orderTotal * 100),
                $insert_id
            );

            zen_redirect($payUrl);

        } catch (PayException $e) {
            zen_redirect(zen_href_link(
                FILENAME_CHECKOUT_PAYMENT,
                'payment_error=' . $this->code . '&error=paynl&paynlErrorMessage=' . urlencode($e->getMessage()),
                'SSL'
            ));
        } catch (Exception $e) {
            zen_redirect(zen_href_link(
                FILENAME_CHECKOUT_PAYMENT,
                'payment_error=' . $this->code . '&error=paynl&paynlErrorMessage=' . urlencode($e->getMessage()),
                'SSL'
            ));
        }
    }

    function get_error()
    {
        $desc = $this->payment_method_description;
        $error_message = constant('MODULE_PAYMENT_PAYNL_' . $desc . '_ERROR_GENERAL');

        switch ($_GET['error'] ?? '') {
            case 'verification':
                $error_message = constant('MODULE_PAYMENT_PAYNL_' . $desc . '_ERROR_VERIFICATION');
                break;
            case 'declined':
                $error_message = constant('MODULE_PAYMENT_PAYNL_' . $desc . '_ERROR_DECLINED');
                break;
            case 'paynl':
                $error_message = htmlspecialchars(urldecode($_REQUEST['paynlErrorMessage'] ?? ''));
                break;
            default:
                $error_message = constant('MODULE_PAYMENT_PAYNL_' . $desc . '_ERROR_GENERAL');
                break;
        }

        return [
            'title' => constant('MODULE_PAYMENT_PAYNL_' . $desc . '_ERROR_TITLE'),
            'error' => $error_message,
        ];
    }

    function install($parameter = null)
    {
        global $db;

        $sql = "CREATE TABLE IF NOT EXISTS paynl_transaction (
            id             int(11)      NOT NULL AUTO_INCREMENT,
            transaction_id varchar(50)  NOT NULL,
            option_id      int(11)      NOT NULL,
            amount         int(11)      NOT NULL,
            order_id       int(11)      NOT NULL,
            status         varchar(20)  NOT NULL DEFAULT 'PENDING',
            created        timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_update    timestamp    NULL,
            start_data     timestamp    NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=" . DB_CHARSET;

        $db->Execute($sql);

        $params = $this->getParams();

        if (isset($parameter)) {
            if (isset($params[$parameter])) {
                $params = [$parameter => $params[$parameter]];
            } else {
                $params = [];
            }
        }

        foreach ($params as $key => $data) {
            $sql_data_array = [
                'configuration_title'       => $data['title'],
                'configuration_key'         => $key,
                'configuration_value'       => $data['value'] ?? '',
                'configuration_description' => $data['desc'],
                'configuration_group_id'    => '6',
                'sort_order'                => '0',
                'date_added'                => 'now()',
            ];

            if (isset($data['set_func'])) {
                $sql_data_array['set_function'] = $data['set_func'];
            }
            if (isset($data['use_func'])) {
                $sql_data_array['use_function'] = $data['use_func'];
            }

            zen_db_perform(TABLE_CONFIGURATION, $sql_data_array);
        }
    }

    function remove()
    {
        global $db;
        $db->Execute(
            "DELETE FROM " . TABLE_CONFIGURATION . "
             WHERE configuration_key IN ('" . implode("', '", $this->keys()) . "')"
        );
    }

    function keys()
    {
        $keys = array_keys($this->getParams());

        if ($this->check()) {
            foreach ($keys as $key) {
                if (!defined($key)) {
                    $this->install($key);
                }
            }
        }

        return $keys;
    }

    function getParams()
    {
        global $db;
        $desc = $this->payment_method_description;

        if (!defined('MODULE_PAYMENT_PAYNL_' . $desc . '_TRANSACTION_ORDER_STATUS_ID')) {
            $check_query = $db->Execute(
                "SELECT orders_status_id FROM " . TABLE_ORDERS_STATUS . "
                 WHERE orders_status_name = 'Pay. [PAID]'
                 LIMIT 1"
            );

            if ($check_query->RecordCount() < 1) {
                $status_query = $db->Execute(
                    "SELECT MAX(orders_status_id) AS status_id FROM " . TABLE_ORDERS_STATUS
                );
                $status    = $status_query->fields;
                $status_id = $status['status_id'] + 1;

                $languages = zen_get_languages();
                foreach ($languages as $lang) {
                    $db->Execute(
                        "INSERT INTO " . TABLE_ORDERS_STATUS . " (orders_status_id, language_id, orders_status_name)
                         VALUES (" . (int)$status_id . ", " . (int)$lang['id'] . ", 'Pay. [PAID]')"
                    );
                }
            } else {
                $status_id = $check_query->fields['orders_status_id'];
            }
        } else {
            $status_id = constant('MODULE_PAYMENT_PAYNL_' . $desc . '_TRANSACTION_ORDER_STATUS_ID');
        }

        return [
            'MODULE_PAYMENT_PAYNL_' . $desc . '_STATUS' => [
                'title'    => 'Enable Pay. ' . ucfirst(strtolower($desc)) . ' payment method',
                'desc'     => 'Accept payments via Pay. ' . ucfirst(strtolower($desc)) . '?',
                'value'    => 'True',
                'set_func' => "zen_cfg_select_option(array('True', 'False'), ",
            ],
            'MODULE_PAYMENT_PAYNL_' . $desc . '_AT_CODE' => [
                'title' => 'AT-code (username)',
                'desc'  => 'Your Pay. AT-code, e.g. AT-####-####. Found in the Pay. dashboard under My Account.',
            ],
            'MODULE_PAYMENT_PAYNL_' . $desc . '_API_TOKEN' => [
                'title' => 'API Token (password)',
                'desc'  => 'Your Pay. API token. Found in the Pay. dashboard under My Account.',
            ],
            'MODULE_PAYMENT_PAYNL_' . $desc . '_SERVICE_ID' => [
                'title' => 'Service ID / SL-code (optional)',
                'desc'  => 'Your Pay. SL-code, e.g. SL-####-####. Used to identify the sales location. Leave empty to use the AT-code default.',
            ],
            'MODULE_PAYMENT_PAYNL_' . $desc . '_ORDER_STATUS_ID' => [
                'title'    => 'Pending order status',
                'desc'     => 'Status for orders that are pending payment.',
                'value'    => '0',
                'use_func' => 'zen_get_order_status_name',
                'set_func' => 'zen_cfg_pull_down_order_statuses(',
            ],
            'MODULE_PAYMENT_PAYNL_' . $desc . '_TRANSACTION_ORDER_STATUS_ID' => [
                'title'    => 'Paid order status',
                'desc'     => 'Status for orders that are successfully paid.',
                'value'    => $status_id,
                'use_func' => 'zen_get_order_status_name',
                'set_func' => 'zen_cfg_pull_down_order_statuses(',
            ],
            'MODULE_PAYMENT_PAYNL_' . $desc . '_ZONE' => [
                'title'    => 'Payment Zone',
                'desc'     => 'If a zone is selected, only enable this payment method for that zone.',
                'value'    => '0',
                'set_func' => 'zen_cfg_pull_down_zone_classes(',
                'use_func' => 'zen_get_zone_class_title',
            ],
            'MODULE_PAYMENT_PAYNL_' . $desc . '_DEBUG_EMAIL' => [
                'title' => 'Debug e-mail address',
                'desc'  => 'All parameters of a failed transaction are sent here.',
            ],
            'MODULE_PAYMENT_PAYNL_' . $desc . '_SORT_ORDER' => [
                'title' => 'Sort order of display',
                'desc'  => 'Lowest is displayed first.',
                'value' => '0',
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Helper functions
    // -------------------------------------------------------------------------

    /**
     * Generate an absolute URL to a Zencart page.
     */
    protected function generateReturnURL($page, $parameters = '')
    {
        global $request_type;
        $strLink = HTTP_SERVER . DIR_WS_CATALOG;
        if (ENABLE_SSL == 'true' && $request_type == 'SSL') {
            $strLink = HTTPS_SERVER . DIR_WS_HTTPS_CATALOG;
        }
        if (zen_not_null($parameters)) {
            return $strLink . $page . '?' . $parameters;
        }
        return $strLink . $page;
    }

    /**
     * Format an order total as a plain decimal string (no currency symbols).
     */
    function format_raw($number, $currency_code = '', $currency_value = '')
    {
        global $currencies;
        if (!isset($currency_code) || empty($currency_code)) {
            $currency_code = DEFAULT_CURRENCY;
        }
        if (empty($currency_value) || !is_numeric($currency_value)) {
            $currency_value = $currencies->currencies[$currency_code]['value'];
        }
        $decimal_places = $currencies->currencies[$currency_code]['decimal_places'];
        return number_format(zen_round($number * $currency_value, $decimal_places), $decimal_places, '.', '');
    }

    /**
     * Split a street address into street name and number.
     */
    function splitAddress($strAddress)
    {
        $strAddress = trim($strAddress);
        $a = preg_split('/([0-9]+)/', $strAddress, 2, PREG_SPLIT_DELIM_CAPTURE);
        $strStreetName   = trim(array_shift($a));
        $strStreetNumber = trim(implode('', $a));

        if (empty($strStreetName)) {
            // American address notation: number first
            $a = preg_split('/([a-zA-Z]{2,})/', $strAddress, 2, PREG_SPLIT_DELIM_CAPTURE);
            $strStreetNumber = trim(implode('', $a));
            $strStreetName   = trim(array_shift($a));
        }

        return [$strStreetName, $strStreetNumber];
    }

    /**
     * Send a debug e-mail when something goes wrong.
     */
    function sendDebugEmail($response = [])
    {
        $desc = $this->payment_method_description;
        if (zen_not_null(constant('MODULE_PAYMENT_PAYNL_' . $desc . '_DEBUG_EMAIL'))) {
            $email_body = '';
            if (!empty($response)) {
                $email_body .= 'RESPONSE:' . "\n\n" . print_r($response, true) . "\n\n";
            }
            if (!empty($_POST)) {
                $email_body .= '$_POST:' . "\n\n" . print_r($_POST, true) . "\n\n";
            }
            if (!empty($_GET)) {
                $email_body .= '$_GET:' . "\n\n" . print_r($_GET, true) . "\n\n";
            }
            if (!empty($email_body)) {
                zen_mail(
                    '',
                    constant('MODULE_PAYMENT_PAYNL_' . $desc . '_DEBUG_EMAIL'),
                    'Pay. ' . $desc . ' Debug E-Mail',
                    trim($email_body),
                    STORE_OWNER,
                    STORE_OWNER_EMAIL_ADDRESS
                );
            }
        }
    }

    /**
     * Store a new transaction record.
     */
    function insertPaynlTransaction($transactionId, $option_id, $amount, $orderId)
    {
        global $db;
        $db->Execute(
            "INSERT INTO paynl_transaction (transaction_id, option_id, amount, order_id, start_data)
             VALUES ('" . zen_db_input($transactionId) . "', " . (int)$option_id . ", " . (int)$amount . ", " . (int)$orderId . ", '" . date('Y-m-d H:i:s') . "')"
        );
    }
}
