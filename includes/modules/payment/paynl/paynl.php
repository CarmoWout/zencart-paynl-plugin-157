<?php

/**
 * Pay. payment module for Zencart 1.5.7d
 *
 * Authentication: API Token + Service ID (SL-code).
 * Uses the bundled Pay/ SDK classes (PHP 7.4 compatible).
 */

require_once dirname(__FILE__) . '/Pay/Autoload.php';
require_once dirname(__FILE__) . '/Pay/Log.php';

// Global test mode switch for ALL Pay. payment methods.
// Set to 'True' to enable Pay. sandbox mode (sends integration.test=true).
// Also set the Sales Location to Test mode in my.pay.nl -> Settings -> Sales location.
if (!defined('PAYNL_GLOBAL_TEST_MODE')) {
    define('PAYNL_GLOBAL_TEST_MODE', 'False');
}

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
            // Require API Token and Service ID
            $apiToken  = defined('MODULE_PAYMENT_PAYNL_' . $this->payment_method_description . '_API_TOKEN')
                         ? constant('MODULE_PAYMENT_PAYNL_' . $this->payment_method_description . '_API_TOKEN')
                         : '';
            $serviceId = defined('MODULE_PAYMENT_PAYNL_' . $this->payment_method_description . '_SERVICE_ID')
                         ? constant('MODULE_PAYMENT_PAYNL_' . $this->payment_method_description . '_SERVICE_ID')
                         : '';

            if (!zen_not_null($apiToken) || !zen_not_null($serviceId)) {
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

        $apiToken  = constant('MODULE_PAYMENT_PAYNL_' . $desc . '_API_TOKEN');
        $serviceId = constant('MODULE_PAYMENT_PAYNL_' . $desc . '_SERVICE_ID');

        $testMode = defined('PAYNL_GLOBAL_TEST_MODE') && PAYNL_GLOBAL_TEST_MODE === 'True';

        // Build order total in cents
        $orderTotal = (float) $this->format_raw($order->info['total']);

        // Return URL (customer lands here after payment)
        $returnUrl   = $this->generateReturnURL('ext/modules/payment/paynl/return.php?method=' . $desc);
        // Exchange URL (server-to-server webhook)
        $exchangeUrl = $this->generateReturnURL('ext/modules/payment/paynl/paynl_exchange.php?method=' . $desc);

        // Split addresses
        $b_address = $this->splitAddress(trim($order->billing['street_address']));
        $d_address = $this->splitAddress(trim($order->delivery['street_address']));

        paynl_log($desc, 'after_process', 'Starting Pay. transaction', [
            'order_id'   => $insert_id,
            'amount_cts' => (int) round($orderTotal * 100),
            'method_id'  => $this->payment_method_id,
            'returnUrl'  => $returnUrl,
            'exchangeUrl'=> $exchangeUrl,
        ]);

        try {
            $paynlService = new Pay_Api_Start();
            $paynlService->setApiToken($apiToken);
            $paynlService->setServiceId($serviceId);
            if ($testMode) {
                $paynlService->setTestMode(true);
            }

            $paynlService->setAmount((int) round($orderTotal * 100));
            $paynlService->setCurrency(DEFAULT_CURRENCY);
            $paynlService->setPaymentOptionId((int) $this->payment_method_id);
            $paynlService->setDescription('Order ' . $insert_id);
            $paynlService->setOrderNumber((string) $insert_id);

            $paynlService->setFinishUrl($returnUrl);
            $paynlService->setExchangeUrl($exchangeUrl);

            // Stats / tracing data
            $paynlService->setExtra1((string) $insert_id);
            $paynlService->setExtra2((string) $customer_id);
            $paynlService->setObject('zencart 1.5.7d');

            // Enduser data
            $lang = isset($_SESSION['languages_code']) ? strtoupper($_SESSION['languages_code']) : 'NL';
            $paynlService->setEnduser([
                'initials'     => substr($order->delivery['firstname'], 0, 1),
                'lastName'     => substr($order->delivery['lastname'], 0, 50),
                'language'     => $lang,
                'emailAddress' => $order->customer['email_address'],
                'phoneNumber'  => $order->customer['telephone'],
                'address'      => [
                    'streetName'   => substr($d_address[0], 0, 50),
                    'streetNumber' => substr($d_address[1], 0, 10),
                    'zipCode'      => $order->delivery['postcode'],
                    'city'         => $order->delivery['city'],
                    'countryCode'  => $order->delivery['country']['iso_code_2'],
                ],
                'invoiceAddress' => [
                    'initials'     => substr($order->billing['firstname'], 0, 1),
                    'lastname'     => substr($order->billing['lastname'], 0, 50),
                    'streetName'   => substr($b_address[0], 0, 50),
                    'streetNumber' => substr($b_address[1], 0, 10),
                    'zipCode'      => $order->billing['postcode'],
                    'city'         => $order->billing['city'],
                    'countryCode'  => $order->billing['country']['iso_code_2'],
                ],
            ]);

            // Products
            foreach ($order->products as $product) {
                list($productId) = explode(':', $product['id']);
                $price = (int) round((float) $product['final_price'] * 100);
                $paynlService->addProduct(
                    (string) $productId,
                    substr($product['name'], 0, 45),
                    $price,
                    (int) $product['qty'],
                    'H'
                );
            }

            // Shipping
            if ($order->info['shipping_cost'] > 0) {
                $paynlService->addProduct(
                    'shipcost',
                    substr($order->info['shipping_method'], 0, 45),
                    (int) round((float) $order->info['shipping_cost'] * 100),
                    1,
                    'H'
                );
            }

            $result    = $paynlService->doRequest();
            $orderId   = $result['transaction']['transactionId'];
            $payUrl    = $result['transaction']['paymentURL'];

            paynl_log($desc, 'after_process', 'Transaction started OK', [
                'transactionId' => $orderId,
                'paymentURL'    => $payUrl,
                'order_id'      => $insert_id,
            ]);

            $this->insertPaynlTransaction(
                $orderId,
                $this->payment_method_id,
                (int) round($orderTotal * 100),
                $insert_id
            );

            zen_redirect($payUrl);

        } catch (Pay_Exception $e) {
            paynl_log($desc, 'after_process', 'Pay_Exception: ' . $e->getMessage(), [
                'order_id' => $insert_id,
                'trace'    => $e->getTraceAsString(),
            ], 'ERROR');
            $this->sendDebugEmail(['exception' => $e->getMessage(), 'order_id' => $insert_id]);
            zen_redirect(zen_href_link(
                FILENAME_CHECKOUT_PAYMENT,
                'payment_error=' . $this->code . '&error=paynl&paynlErrorMessage=' . urlencode($e->getMessage()),
                'SSL'
            ));
        } catch (Exception $e) {
            paynl_log($desc, 'after_process', 'Exception: ' . $e->getMessage(), [
                'order_id' => $insert_id,
                'trace'    => $e->getTraceAsString(),
            ], 'ERROR');
            $this->sendDebugEmail(['exception' => $e->getMessage(), 'order_id' => $insert_id]);
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
            'MODULE_PAYMENT_PAYNL_' . $desc . '_API_TOKEN' => [
                'title' => 'API Token',
                'desc'  => 'Your Pay. API token. Found in the Pay. dashboard under My Account.',
            ],
            'MODULE_PAYMENT_PAYNL_' . $desc . '_SERVICE_ID' => [
                'title' => 'Service ID (SL-code)',
                'desc'  => 'Your Pay. SL-code, e.g. SL-####-####. Found in the Pay. dashboard under Programs.',
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
            'MODULE_PAYMENT_PAYNL_' . $desc . '_CANCEL_ORDER_STATUS_ID' => [
                'title'    => 'Cancelled order status',
                'desc'     => 'Status for orders where the payment was cancelled or declined. Leave at 0 to use the default Zencart cancelled status.',
                'value'    => '0',
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
            'MODULE_PAYMENT_PAYNL_' . $desc . '_DEBUG_LOG' => [
                'title'    => 'Enable debug logging',
                'desc'     => 'Write debug info to DIR_FS_LOGS/paynl_YYYY-MM-DD.log. Errors are always logged.',
                'value'    => 'False',
                'set_func' => "zen_cfg_select_option(array('True', 'False'), ",
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
