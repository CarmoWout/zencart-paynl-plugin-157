<?php

/**
 * Pay. return URL handler for Zencart 1.5.7d
 *
 * The customer is redirected back here after completing (or cancelling) the
 * payment on the Pay. page.  We fetch the order status via the SDK and
 * redirect the customer to the appropriate Zencart page.
 *
 * URL parameters:
 *   method    - uppercase payment method description, e.g. IDEAL
 *   orderId   - Pay. order ID (set automatically by Pay.)
 */

declare(strict_types=1);

chdir('../../../../');
require 'includes/application_top.php';

$paynlAutoload = DIR_WS_MODULES . 'payment/paynl/vendor/autoload.php';
if (!file_exists($paynlAutoload)) {
    die('Pay. SDK autoloader not found. Run: composer require paynl/php-sdk');
}
require_once $paynlAutoload;

use PayNL\Sdk\Model\Request\OrderStatusRequest;
use PayNL\Sdk\Config\Config as PayNLConfig;
use PayNL\Sdk\Exception\PayException;

$method    = isset($_REQUEST['method']) ? strtoupper(preg_replace('/[^A-Z0-9_]/', '', strtoupper($_REQUEST['method']))) : '';
$payOrderId = $_REQUEST['orderId'] ?? '';

if (!$method || !$payOrderId) {
    zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, 'payment_error=paynl&error=missing+parameters', 'SSL'));
}

$atCode   = defined('MODULE_PAYMENT_PAYNL_' . $method . '_AT_CODE')
            ? constant('MODULE_PAYMENT_PAYNL_' . $method . '_AT_CODE')
            : '';
$apiToken = defined('MODULE_PAYMENT_PAYNL_' . $method . '_API_TOKEN')
            ? constant('MODULE_PAYMENT_PAYNL_' . $method . '_API_TOKEN')
            : '';

if (!$atCode || !$apiToken) {
    zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, 'payment_error=paynl&error=credentials+missing', 'SSL'));
}

$config = new PayNLConfig();
$config->setUsername($atCode);
$config->setPassword($apiToken);

try {
    $request  = new OrderStatusRequest($payOrderId);
    $request->setConfig($config);
    $payOrder = $request->start();

    $zcOrderId = (int) $payOrder->getExtra1();

    if ($payOrder->isPaid() || $payOrder->isAuthorized()) {
        // Confirm payment and clean up
        if (isset($_SESSION['cart'])) {
            $_SESSION['cart']->reset(true);
        }
        unset($_SESSION['sendto'], $_SESSION['billto'], $_SESSION['shipping'], $_SESSION['payment'], $_SESSION['comments']);
        zen_redirect(zen_href_link(FILENAME_CHECKOUT_SUCCESS));

    } elseif ($payOrder->isCancelled()) {
        zen_redirect(zen_href_link(
            FILENAME_CHECKOUT_PAYMENT,
            'payment_error=' . urlencode(strtolower($method)) . '&error=Payment+cancelled',
            'NONSSL',
            true,
            false
        ));

    } elseif ($payOrder->isPending()) {
        // Still pending – the exchange will update the order status later
        // Redirect to checkout success so the customer sees a confirmation page
        if (isset($_SESSION['cart'])) {
            $_SESSION['cart']->reset(true);
        }
        zen_redirect(zen_href_link(FILENAME_CHECKOUT_SUCCESS));

    } else {
        zen_redirect(zen_href_link(
            FILENAME_CHECKOUT_PAYMENT,
            'payment_error=' . urlencode(strtolower($method)) . '&error=Unknown+status',
            'SSL'
        ));
    }

} catch (PayException $e) {
    zen_redirect(zen_href_link(
        FILENAME_CHECKOUT_PAYMENT,
        'payment_error=' . urlencode(strtolower($method)) . '&error=paynl&paynlErrorMessage=' . urlencode($e->getMessage()),
        'SSL'
    ));
} catch (Throwable $e) {
    zen_redirect(zen_href_link(
        FILENAME_CHECKOUT_PAYMENT,
        'payment_error=' . urlencode(strtolower($method)) . '&error=paynl&paynlErrorMessage=' . urlencode($e->getMessage()),
        'SSL'
    ));
}
