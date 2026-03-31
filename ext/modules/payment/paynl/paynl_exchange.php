<?php

/**
 * Pay. exchange / webhook handler for Zencart 1.5.7d
 *
 * Pay. calls this URL server-to-server when a payment status changes.
 *
 * URL parameter:
 *   method  - uppercase payment method description, e.g. IDEAL
 *
 * Pay. POST fields (exchange / TGU):
 *   order_id      - Pay. transaction ID (e.g. EX-1234-5678-1234)
 *   extra1        - Zencart order ID (stored at transaction start)
 *   action        - TGU|update (present only for server-to-server calls)
 */

// Go back to the Zencart root
chdir('../../../../');
require 'includes/application_top.php';

// Load Pay. SDK classes
require_once DIR_WS_MODULES . 'payment/paynl/Pay/Autoload.php';
require_once DIR_WS_MODULES . 'payment/paynl/Pay/Log.php';

$method = isset($_REQUEST['method'])
    ? strtoupper(preg_replace('/[^A-Z0-9_]/', '', strtoupper($_REQUEST['method'])))
    : '';

if (!$method) {
    echo 'TRUE|ERROR: missing method parameter';
    exit;
}

// Load Pay. credentials from Zencart config constants
$apiToken  = defined('MODULE_PAYMENT_PAYNL_' . $method . '_API_TOKEN')
             ? constant('MODULE_PAYMENT_PAYNL_' . $method . '_API_TOKEN')
             : '';
$serviceId = defined('MODULE_PAYMENT_PAYNL_' . $method . '_SERVICE_ID')
             ? constant('MODULE_PAYMENT_PAYNL_' . $method . '_SERVICE_ID')
             : '';

if (!$apiToken || !$serviceId) {
    echo 'TRUE|ERROR: Pay. credentials not configured for method ' . $method;
    exit;
}

// Determine if this is a server-to-server exchange call or a customer return
// Pay. server-to-server calls always contain the 'action' POST field.
$isExchange = !empty($_POST['action']);

// The Pay. transaction ID is passed as order_id in exchange calls
// or as orderId in the GET string on the return URL.
$transactionId = !empty($_POST['order_id'])
    ? $_POST['order_id']
    : (isset($_REQUEST['orderId']) ? $_REQUEST['orderId'] : '');

if (!$transactionId) {
    if ($isExchange) {
        echo 'TRUE|ERROR: missing transaction ID';
    } else {
        zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, 'payment_error=paynl&error=missing+transaction+id', 'SSL'));
    }
    exit;
}

paynl_log($method, 'exchange', 'Incoming call', [
    'isExchange'    => $isExchange,
    'transactionId' => $transactionId,
    'action'        => isset($_POST['action']) ? $_POST['action'] : '',
]);

try {
    $paynlInfo = new Pay_Api_Info();
    $paynlInfo->setApiToken($apiToken);
    $paynlInfo->setServiceId($serviceId);
    $paynlInfo->setTransactionId($transactionId);

    $result = $paynlInfo->doRequest();

    $stateCode = (int) $result['paymentDetails']['state'];
    $stateText = Pay_Helper::getStateText($stateCode);
    $zcOrderId = isset($result['statsDetails']['extra1']) ? (int) $result['statsDetails']['extra1'] : 0;

    paynl_log($method, 'exchange', 'Transaction status fetched', [
        'transactionId' => $transactionId,
        'stateCode'     => $stateCode,
        'stateText'     => $stateText,
        'zcOrderId'     => $zcOrderId,
    ]);

    if ($stateText === 'PENDING') {

        updatePaynlTransaction($transactionId, 'PENDING');

        if ($isExchange) {
            echo 'TRUE|Pending';
        } else {
            zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL'));
        }

    } elseif ($stateText === 'PAID') {

        if ($isExchange && isAlreadyPaid($transactionId)) {
            echo 'TRUE|Already PAID';
        } else {
            updatePaynlTransaction($transactionId, 'PAID');
            updateOrderStatus($method, $zcOrderId);

            if ($isExchange) {
                cleanSession();
                echo 'TRUE|PAID';
            } else {
                cleanSession();
                zen_redirect(zen_href_link(FILENAME_CHECKOUT_SUCCESS));
            }
        }

    } elseif ($stateText === 'CANCEL') {

        updatePaynlTransaction($transactionId, 'CANCEL');
        cancelOrder($method, $zcOrderId);

        if ($isExchange) {
            echo 'TRUE|CANCEL';
        } else {
            zen_redirect(zen_href_link(
                FILENAME_CHECKOUT_PAYMENT,
                'payment_error=' . urlencode(strtolower($method)) . '&error=Payment+cancelled',
                'NONSSL',
                true,
                false
            ));
        }

    } elseif ($stateText === 'CHECKAMOUNT') {

        updatePaynlTransaction($transactionId, 'CHECKAMOUNT');

        if ($isExchange) {
            echo 'TRUE|CHECKAMOUNT';
        } else {
            zen_redirect(zen_href_link(FILENAME_CHECKOUT_SUCCESS));
        }

    } else {
        if ($isExchange) {
            echo 'TRUE|Unhandled status: ' . $stateText . ' (' . $stateCode . ')';
        } else {
            zen_redirect(zen_href_link(
                FILENAME_CHECKOUT_PAYMENT,
                'payment_error=' . urlencode(strtolower($method)) . '&error=Unknown+status',
                'SSL'
            ));
        }
    }

} catch (Pay_Exception $e) {
    paynl_log($method, 'exchange', 'Pay_Exception: ' . $e->getMessage(), [
        'transactionId' => $transactionId,
        'isExchange'    => $isExchange,
        'trace'         => $e->getTraceAsString(),
    ], 'ERROR');
    if ($isExchange) {
        echo 'FALSE|ERROR: ' . $e->getMessage();
    } else {
        zen_redirect(zen_href_link(
            FILENAME_CHECKOUT_PAYMENT,
            'payment_error=' . urlencode(strtolower($method)) . '&error=paynl&paynlErrorMessage=' . urlencode($e->getMessage()),
            'SSL'
        ));
    }
} catch (Exception $e) {
    paynl_log($method, 'exchange', 'Exception: ' . $e->getMessage(), [
        'transactionId' => $transactionId,
        'isExchange'    => $isExchange,
        'trace'         => $e->getTraceAsString(),
    ], 'ERROR');
    if ($isExchange) {
        echo 'FALSE|ERROR: ' . $e->getMessage();
    } else {
        zen_redirect(zen_href_link(
            FILENAME_CHECKOUT_PAYMENT,
            'payment_error=' . urlencode(strtolower($method)) . '&error=paynl&paynlErrorMessage=' . urlencode($e->getMessage()),
            'SSL'
        ));
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Helper functions
// ─────────────────────────────────────────────────────────────────────────────

function isAlreadyPaid($transactionId)
{
    global $db;

    $row = $db->Execute(
        "SELECT order_id FROM paynl_transaction
         WHERE transaction_id = '" . zen_db_input($transactionId) . "'"
    );

    if (!isset($row->fields['order_id'])) {
        return false;
    }

    $result = $db->Execute(
        "SELECT COUNT(*) AS cnt FROM paynl_transaction
         WHERE order_id = " . (int)$row->fields['order_id'] . "
         AND status = 'PAID'"
    );

    return (int)$result->fields['cnt'] > 0;
}

function updatePaynlTransaction($transactionId, $status)
{
    global $db;
    $db->Execute(
        "UPDATE paynl_transaction
         SET status = '" . zen_db_input($status) . "', last_update = NOW()
         WHERE transaction_id = '" . zen_db_input($transactionId) . "'"
    );
}

function updateOrderStatus($method, $orderId)
{
    global $db;

    if ($orderId < 1) {
        return;
    }

    $order_status_id = (
        defined('MODULE_PAYMENT_PAYNL_' . $method . '_TRANSACTION_ORDER_STATUS_ID') &&
        (int)constant('MODULE_PAYMENT_PAYNL_' . $method . '_TRANSACTION_ORDER_STATUS_ID') > 0
    )
        ? (int)constant('MODULE_PAYMENT_PAYNL_' . $method . '_TRANSACTION_ORDER_STATUS_ID')
        : (int)DEFAULT_ORDERS_STATUS_ID;

    $db->Execute(
        "UPDATE " . TABLE_ORDERS . "
         SET orders_status = " . $order_status_id . ", last_modified = NOW()
         WHERE orders_id = " . $orderId
    );

    $db->Execute(
        "INSERT INTO " . TABLE_ORDERS_STATUS_HISTORY . "
            (orders_id, orders_status_id, date_added, customer_notified, comments)
         VALUES
            (" . $orderId . ", " . $order_status_id . ", NOW(), 0, 'Pay. Transaction [VERIFIED]')"
    );
}

function cancelOrder($method, $orderId)
{
    global $db;

    if ($orderId < 1) {
        return;
    }

    // Use the configured cancel status, or fall back to Zencart default orders status
    $cancel_status_id = (
        defined('MODULE_PAYMENT_PAYNL_' . $method . '_CANCEL_ORDER_STATUS_ID') &&
        (int)constant('MODULE_PAYMENT_PAYNL_' . $method . '_CANCEL_ORDER_STATUS_ID') > 0
    )
        ? (int)constant('MODULE_PAYMENT_PAYNL_' . $method . '_CANCEL_ORDER_STATUS_ID')
        : (int)DEFAULT_ORDERS_STATUS_ID;

    $db->Execute(
        "UPDATE " . TABLE_ORDERS . "
         SET orders_status = " . $cancel_status_id . ", last_modified = NOW()
         WHERE orders_id = " . $orderId
    );

    $db->Execute(
        "INSERT INTO " . TABLE_ORDERS_STATUS_HISTORY . "
            (orders_id, orders_status_id, date_added, customer_notified, comments)
         VALUES
            (" . $orderId . ", " . $cancel_status_id . ", NOW(), 0, 'Pay. Transaction [CANCELLED]')"
    );
}

function cleanSession()
{
    if (isset($_SESSION['cart'])) {
        $_SESSION['cart']->reset(true);
    }
    unset(
        $_SESSION['sendto'],
        $_SESSION['billto'],
        $_SESSION['shipping'],
        $_SESSION['payment'],
        $_SESSION['comments']
    );
}
