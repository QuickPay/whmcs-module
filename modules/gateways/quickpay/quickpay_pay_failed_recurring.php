<?php

use WHMCS\Billing\Invoice;
use WHMCS\Database\Capsule;
use WHMCS\Config\Setting;

require_once __DIR__ . '/../../../init.php';

/* The number of checks allowed until done */
const MAX_CHECKS = 5;
/* Waiting time between checks, in seconds */
const DELAY = 1;


$systemUrl = Setting::getValue('SystemURL');

/* GET params */
$invoice_id = (int) $whmcs->get_req_var('id');
$recurring_failed = (int) $whmcs->get_req_var("recurring_failed");

$invoiceModel = Invoice::find((int) $invoice_id);

if (
    empty($invoice_id)
    || empty($recurring_failed)
    || !$invoiceModel
    || 'Unpaid' != $invoiceModel['status']
    || 0 != $invoiceModel['amountPaid']
) {
    header("Location: {$systemUrl}/viewinvoice.php?id={$invoice_id}");
    exit();
}

/* If no user session active, redirect to login */
if (!isset($_SESSION["uid"]) && !isset($_SESSION["adminid"])) {
    require __DIR__ . '/../../../login.php';
    exit();
}

/** Get QP transaction from DB */
$quickpay_transaction = Capsule::table('quickpay_transactions')
                            ->where('invoice_id', (int) $invoice_id)
                            ->where('paid', 0)
                            ->orderBy("id", "DESC")
                            ->first();

if (empty($quickpay_transaction)) {
    header('Location: ' . $systemUrl);
    exit();
}

/** Be sure that the request has proper link. */
if (false === strpos($quickpay_transaction->payment_link, 'recurring_failed')) {
    header('Location: ' . $systemUrl);
    exit();
}

require_once __DIR__ . '/../../../includes/gatewayfunctions.php';

/** Fetch gateway configuration parameters. */
$gateway = getGatewayVariables($gatewayModuleName, $invoice_id);

/** Die if module is not active. */
if (!$gateway["type"]) {
    die("Module Not Activated");
}

/** Request params */
$params = [
        'order_id' => sprintf('%s%04d_r', $gateway['prefix'], $invoice_id),
        'amount' => number_format($quickpay_transaction->amount * 100, 0, '', ''),
        // 'continue_url' => $systemUrl ."/modules/gateways/quickpay/quickpay_processing.php?id={$invoice_id}&url=" . rawurlencode('http://localhost' . "/viewinvoice.php?id={$invoice_id}"),
        // 'cancel_url' => $systemUrl ."/modules/gateways/quickpay/quickpay_processing.php?id={$invoice_id}&url=" . rawurlencode('http://localhost' . "/viewinvoice.php?id={$invoice_id}"),
        'callback_url' => $systemUrl .'/modules/gateways/callback/quickpay.php?pay_failed_recurring=1',
        'QuickPay-Callback-Url' => $systemUrl . '/modules/gateways/callback/quickpay.php?pay_failed_recurring=1',
        'language' => $gateway['language'],
        'payment_methods' => $gateway['payment_methods'],
        'auto_capture' => $gateway['autocapture'],
        /** Used only for recurring payment request */
        'autofee' => $gateway['autofee'],
        'branding_id' => $gateway['quickpay_branding_id'],
        'google_analytics_tracking_id' => $gateway['quickpay_google_analytics_tracking_id'],
        'google_analytics_client_id' => $gateway['quickpay_google_analytics_client_id'],
        'description' => 'Invoice #' . $invoice_id,
        'due_date' => date("Y-m-d", strtotime('+24 hours')),
        "customer_email" => "test@gmail.com",
];


/**
 * API REQUEST
 *
 */
$response = helper_quickpay_request(
    $gateway['apikey'],
    sprintf('subscriptions/%s/recurring', $quickpay_transaction->transaction_id/** Subscription_id */),
    $params,
    'POST'
);

logActivity('Quickpay payment response: ' . json_encode($response));

if (!isset($response->id)) {
    throw new Exception('Failed to create recurring payment');
}

logTransaction(/**gatewayName*/'quickpay', /**debugData*/['request' => $params],  'Pay Quickpay failed recurring :: ' . 'Recurring payment request complete');


$checks = MAX_CHECKS;

/* Check paid status of invoice */
do {
    /* wait */
    sleep(DELAY);
    $checks -= 1;

    /** Get the last transaction */
    $quickpay_transaction = Capsule::table('quickpay_transactions')->where('invoice_id', (int) $invoice_id)->orderBy("id", "DESC")->first();

    /* If no invoice found, redirect to home */
    if (empty($quickpay_transaction)) {
        header('Location: '. $systemUrl);
        exit();
    }

    /* Repeat check until found or checks are exhausted */
} while ('0' == $quickpay_transaction->paid && $checks > 0);


header("Location: {$systemUrl}/viewinvoice.php?id={$invoice_id}");
exit();
