<?php

/**
 * Pay. return URL handler for Zencart 1.5.7d
 *
 * The customer is redirected back here after completing (or cancelling) the
 * payment on the Pay. page.  We fetch the order status via Pay_Api_Info and
 * redirect the customer to the appropriate Zencart page.
 *
 * URL parameters:
 *   method    - uppercase payment method description, e.g. IDEAL
 *   orderId   - Pay. order ID (set automatically by Pay.)
 */

chdir('../../../../');
require 'includes/application_top.php';

require_once DIR_WS_MODULES . 'payment/paynl/Pay/Autoload.php';
require_once DIR_WS_MODULES . 'payment/paynl/Pay/Log.php';

$method     = isset($_REQUEST['method'])
    ? strtoupper(preg_replace('/[^A-Z0-9_]/', '', strtoupper($_REQUEST['method'])))
    : '';
$payOrderId = isset($_REQUEST['orderId']) ? $_REQUEST['orderId'] : '';

if (!$method || !$payOrderId) {
    zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, 'payment_error=paynl&error=missing+parameters', 'SSL'));
}

$apiToken  = defined('MODULE_PAYMENT_PAYNL_' . $method . '_API_TOKEN')
             ? constant('MODULE_PAYMENT_PAYNL_' . $method . '_API_TOKEN')
             : '';
$serviceId = defined('MODULE_PAYMENT_PAYNL_' . $method . '_SERVICE_ID')
             ? constant('MODULE_PAYMENT_PAYNL_' . $method . '_SERVICE_ID')
             : '';

if (!$apiToken || !$serviceId) {
    zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, 'payment_error=paynl&error=credentials+missing', 'SSL'));
}

paynl_log($method, 'return', 'Customer return', ['orderId' => $payOrderId]);

try {
    $paynlInfo = new Pay_Api_Info();
    $paynlInfo->setApiToken($apiToken);
    $paynlInfo->setServiceId($serviceId);
    $paynlInfo->setTransactionId($payOrderId);

    $result    = $paynlInfo->doRequest();
    $stateCode = (int) $result['paymentDetails']['state'];
    $stateText = Pay_Helper::getStateText($stateCode);

    paynl_log($method, 'return', 'Status fetched', [
        'orderId'   => $payOrderId,
        'stateCode' => $stateCode,
        'stateText' => $stateText,
    ]);

    if ($stateText === 'PAID') {
        // Update order status as fallback — the exchange (webhook) may not have
        // arrived yet (e.g. sandbox, firewall, or timing). Safe to call twice
        // because updateOrderStatus() only inserts a history row if needed.
        $zcOrderId = isset($result['statsDetails']['extra1'])
                     ? (int)$result['statsDetails']['extra1']
                     : 0;

        if ($zcOrderId > 0) {
            $order_status_id = (
                defined('MODULE_PAYMENT_PAYNL_' . $method . '_TRANSACTION_ORDER_STATUS_ID') &&
                (int)constant('MODULE_PAYMENT_PAYNL_' . $method . '_TRANSACTION_ORDER_STATUS_ID') > 0
            )
                ? (int)constant('MODULE_PAYMENT_PAYNL_' . $method . '_TRANSACTION_ORDER_STATUS_ID')
                : (int)DEFAULT_ORDERS_STATUS_ID;

            // Only update if not already set to the paid status
            $current = $db->Execute(
                "SELECT orders_status FROM " . TABLE_ORDERS . " WHERE orders_id = " . $zcOrderId
            );
            if (!$current->EOF && (int)$current->fields['orders_status'] !== $order_status_id) {
                $db->Execute(
                    "UPDATE " . TABLE_ORDERS . "
                     SET orders_status = " . $order_status_id . ", last_modified = NOW()
                     WHERE orders_id = " . $zcOrderId
                );
                $db->Execute(
                    "INSERT INTO " . TABLE_ORDERS_STATUS_HISTORY . "
                        (orders_id, orders_status_id, date_added, customer_notified, comments)
                     VALUES
                        (" . $zcOrderId . ", " . $order_status_id . ", NOW(), 0, 'Pay. [PAID] via return URL')"
                );
                paynl_log($method, 'return', 'Order status updated via return URL (exchange fallback)', [
                    'zcOrderId'       => $zcOrderId,
                    'order_status_id' => $order_status_id,
                ]);
            }
        }

        // Clean up cart and session
        if (isset($_SESSION['cart'])) {
            $_SESSION['cart']->reset(true);
        }
        unset($_SESSION['sendto'], $_SESSION['billto'], $_SESSION['shipping'], $_SESSION['payment'], $_SESSION['comments']);
        zen_redirect(zen_href_link(FILENAME_CHECKOUT_SUCCESS));

    } elseif ($stateText === 'CANCEL') {
        zen_redirect(zen_href_link(
            FILENAME_CHECKOUT_PAYMENT,
            'payment_error=' . urlencode(strtolower($method)) . '&error=Payment+cancelled',
            'NONSSL',
            true,
            false
        ));

    } elseif ($stateText === 'PENDING' || $stateText === 'CHECKAMOUNT') {
        // Still pending – the exchange will update the order status later
        // Redirect to checkout success so the customer sees a confirmation page
        if (isset($_SESSION['cart'])) {
            $_SESSION['cart']->reset(true);
        }
        unset($_SESSION['sendto'], $_SESSION['billto'], $_SESSION['shipping'], $_SESSION['payment'], $_SESSION['comments']);
        zen_redirect(zen_href_link(FILENAME_CHECKOUT_SUCCESS));

    } else {
        zen_redirect(zen_href_link(
            FILENAME_CHECKOUT_PAYMENT,
            'payment_error=' . urlencode(strtolower($method)) . '&error=Unknown+status',
            'SSL'
        ));
    }

} catch (Pay_Exception $e) {
    paynl_log($method, 'return', 'Pay_Exception: ' . $e->getMessage(), [
        'orderId' => $payOrderId,
        'trace'   => $e->getTraceAsString(),
    ], 'ERROR');
    zen_redirect(zen_href_link(
        FILENAME_CHECKOUT_PAYMENT,
        'payment_error=' . urlencode(strtolower($method)) . '&error=paynl&paynlErrorMessage=' . urlencode($e->getMessage()),
        'SSL'
    ));
} catch (Exception $e) {
    paynl_log($method, 'return', 'Exception: ' . $e->getMessage(), [
        'orderId' => $payOrderId,
        'trace'   => $e->getTraceAsString(),
    ], 'ERROR');
    zen_redirect(zen_href_link(
        FILENAME_CHECKOUT_PAYMENT,
        'payment_error=' . urlencode(strtolower($method)) . '&error=paynl&paynlErrorMessage=' . urlencode($e->getMessage()),
        'SSL'
    ));
}
