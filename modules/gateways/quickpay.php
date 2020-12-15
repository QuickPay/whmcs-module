<?php

/**
 * WHMCS QuickPay Payment Gateway Module
 *
 * The WHMCS QuickPay Payment Gateway Module allow you to integrate payment
 * solutions with your WHMCS platform.
 *
 * Within the module itself, all functions must be prefixed with the module
 * filename, followed by an underscore, and then the function name. For this
 * example file, the filename is "gatewaymodule" and therefore all functions
 * begin "gatewaymodule_".
 *
 * If your module or third party API does not support a given function, you
 * should not define that function within your module. Only the _config
 * function is required.
 *
 * For more information, please refer to the online documentation: https://developers.whmcs.com/payment-gateways/
 */

/** Require libraries needed for gateway module functions. */
use Illuminate\Database\Capsule\Manager as Capsule;

require_once 'quickpay/quickpay_countries.php';

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Define module related meta data.
 *
 * Values returned here are used to determine module related capabilities and
 * settings.
 *
 * @see https://developers.whmcs.com/payment-gateways/meta-data-params/
 *
 * @return array
 */
function quickpay_MetaData()
{
    return [
        'DisplayName' => 'QuickPay',
        'APIVersion' => '1.1'
    ];
}

/**
 * Define gateway configuration options.
 *
 * The fields you define here determine the configuration options that are
 * presented to administrator users when activating and configuring your
 * payment gateway module for use.
 *
 * Supported field types include:
 * * text
 * * password
 * * yesno
 * * dropdown
 * * radio
 * * textarea
 *
 * @return array
 */
function quickpay_config()
{
    helper_verify_table();

    return [
        /** the friendly display name for a payment gateway should be */
        /** defined here for backwards compatibility */
        "FriendlyName" => [
            "Type" => "System",
            "Value" => "Quickpay"
        ],
        "quickpay_versionnumber" => [
            "FriendlyName" => "Installed module version",
            "Type" => null,
            "Description" => "2.4.0",
            "Size" => "20",
            "disabled" => true
        ],
        "whmcs_adminname" => [
            "FriendlyName" => "WHMCS administrator username",
            "Type" => "text",
            "Value" => "admin",
            "Size" => "20"
        ],
        "merchant" => [
            "FriendlyName" => "Merchant ID",
            "Type" => "text",
            "Size" => "30"
        ],
        "md5secret" => [
            "FriendlyName" => "Payment Window Api Key",
            "Type" => "text",
            "Size" => "60"
        ],
        "apikey" => [
            "FriendlyName" => "API Key",
            "Type" => "text",
            "Size" => "60"
        ],
        "private_key" => [
            "FriendlyName" => "Private Key",
            "Type" => "text",
            "Size" => "60"
        ],
        "agreementid" => [
            "FriendlyName" => "Agreement ID",
            "Type" => "text",
            "Size" => "30"
        ],
        "language" => [
            "FriendlyName" => "Language",
            "Type" => "dropdown",
            "Options" => "da,de,en,es,fi,fr,fo,kl,it,no,nl,pl,sv,ru"
        ],
        "autofee" => [
            "FriendlyName" => "Autofee",
            "Type" => "dropdown",
            "Options" => "0,1"
        ],
        "autocapture" => [
            "FriendlyName" => "Autocapture",
            "Type" => "dropdown",
            "Options" => "0,1"
        ],
        "payment_methods" => [
            "FriendlyName" => "Payment Method",
            "Type" => "text",
            "Size" => "30",
            "Value" => "creditcard"
        ],
        "prefix" => [
            "FriendlyName" => "Order Prefix",
            "Type" => "text",
            "Size" => "30"
        ],
        "quickpay_branding_id" => [
            "FriendlyName" => "Branding ID",
            "Type" => "text",
            "Size" => "30"
        ],
        "quickpay_google_analytics_tracking_id" => [
            "FriendlyName" => "Google Analytics Tracking ID", "
            Type" => "text",
            "Size" => "30"
        ],
        "quickpay_google_analytics_client_id" => [
            "FriendlyName" => "Google Analytics Client ID",
            "Type" => "text",
            "Size" => "30",
        ],
        "link_text" => [
            "FriendlyName" => "Pay now text",
            "Type" => "text",
            "Value" =>
            "Pay Now",
            "Size" => "60"
        ],
    ];
}

/**
 * Payment link.
 *
 * Defines the HTML output displayed on an invoice. Typically consists of an
 * HTML form that will take the user to the payment gateway endpoint.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @see https://developers.whmcs.com/payment-gateways/third-party-gateway/
 *
 * @return string
 */
function quickpay_link($params)
{
    /** Get payment URL */
    $payment = helper_get_payment($params);

    /** Payment button HTML body */
    $code = sprintf('<a href="%s">%s</a>', $payment, $params['link_text']);
    $cart = $_GET['a'];

    /** Inject redirect parameters in page header */
    if ('complete' == $cart) {
        $invoiceId = $params['invoiceid'];
        header('Location: viewinvoice.php?id=' . $invoiceId . '&qpredirect=true');
    }

    /** Determine if we should autoredirect */
    if ($_GET['qpredirect']) {
        $code .= '<script type="text/javascript">window.location.replace("' . $payment . '");</script>';
    }

    return $code;
}

/**
 * Refund transaction.
 *
 * Called when a refund is requested for a previously successful transaction.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @see https://developers.whmcs.com/payment-gateways/refunds/
 *
 * @return array Transaction response status
 * @throws Exception
 */
function quickpay_refund($params)
{
    logTransaction(/**gatewayName*/'quickpay', /**debugData*/['params' => $params], __FUNCTION__ . '::' . 'Refund request received');

    /** Get invoice data */
    $invoice = localAPI(/**command*/'GetInvoice', /**postData*/['invoiceid' => $params['invoiceid']]);

    /** Gateway request parameters */
    $request = [
        'id' => $params['transid'],
        'amount' => str_replace('.', '', $params['amount']),
        'vat_rate' => number_format((((float) $invoice['taxrate'] > 0) ? ((float) $invoice['taxrate']) : ((float) $invoice['taxrate2'])), 2, '.', '')
    ];

    /** Gateway retund request */
    $response = helper_quickpay_request($params['apikey'], sprintf('payments/%s/refund', $params['transid']), $request, 'POST');

    /** Fail due to a gateway connection issue */
    if (!isset($response)) {
        throw new Exception('Failed to refund payment');
    }

    /** Fail due to a gateway issue */
    if (!isset($response->id)) {
        return [
            /** 'success' if successful, any other value for failure */
            'status' => 'failed',
            /** Data to be recorded in the gateway log */
            'rawdata' => $response->message,
            'transid' => $params['transid']
        ];
    }

    /** Success */
    return [
        /** 'success' if successful, any other value for failure */
        'status' => 'success',
        /** Data to be recorded in the gateway log */
        'rawdata' => 'Transaction successfully refunded',
        'transid' => $params['transid'],
         /* Optional fee amount for the fee value refunded */
        'fees' => ((-1) * number_format(($response->fee/100.0), 2, '.', '')) /** Convert amount to decimal */
    ];
}

/**
 * Cancel subscription.
 *
 * If the payment gateway creates subscriptions and stores the subscription
 * ID in tblhosting.subscriptionid, this function is called upon cancellation
 * or request by an admin user.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @see https://developers.whmcs.com/payment-gateways/subscription-management/
 *
 * @return array Transaction response status
 * @throws Exception
 */
function quickpay_cancelSubscription($params)
{
    logTransaction(/**gatewayName*/'quickpay', /**debugData*/['params' => $params], __FUNCTION__ . '::' . 'Cancel subscription request received');

    /** Gateway request parameters */
    $request = ['id' => $params['subscriptionID']];

    /** Gateway cancel request */
    $response = helper_quickpay_request($params['apikey'], sprintf('subscriptions/%s/cancel', $params['subscriptionID']), $request, 'POST');

    /** Fail due to a connection issue */
    if (!isset($response)) {
        throw new Exception('Failed to cancel subsciption payment');
    }

    /** Fail due to a gateway issue */
    if (!isset($response->id)) {
        return [
            /** 'success' if successful, any other value for failure */
            'status' => 'failed',
            /** Data to be recorded in the gateway log - can be a string or array */
            'rawdata' => $response->message
        ];
    }

    /** Success */
    return [
        /** 'success' if successful, any other value for failure */
        'status' => 'success',
        /** Data to be recorded in the gateway log - can be a string or array */
        'rawdata' => 'Subscription successfully canceled'
    ];
}

/******************** Custom Quickpay functions START ***********************/
/**
 * Get or create payment
 *
 * @param $params
 *
 * @return mixed
 */
function helper_get_payment($params)
{
    /** Get PDO and determine if payment exists and is usable */
    $pdo = Capsule::connection()->getPdo();

    /** Get transaction data from quickpay custom table */
    $statement = $pdo->prepare("SELECT * FROM quickpay_transactions WHERE invoice_id = :invoice_id ORDER BY id DESC");
    $statement->execute([
        ':invoice_id' => $params['invoiceid'],
    ]);

    /** Determine if invoice is part of subscription or not */
    $payment_type = helper_getInvoiceType($params['invoiceid']);

    $result = $statement->fetch();
    if (0 < $result) {
        /** New payment needs creating */
        if ($result['paid'] && ('subscription' !== $payment_type)) {
            /** unique order id required for new payment */
            $params['suffix'] = '_' . $statement->rowCount();

        /** fall through to create payment below */
        /** Invoice amount changed, payment link needs updating */
        } elseif ($result['amount'] != $params['amount']) {
            return helper_create_payment_link($result['transaction_id'], $params, $payment_type);

        /** Existing payment link still OK */
        } else {
            return $result['payment_link'];
        }
    }

    /** If payment | subscription doesn't exist, create it */
    if ('subscription' === $payment_type) {
        $paymentlink = helper_create_subscription($params);
    } else {
        $paymentlink = helper_create_payment($params);
    }

    return $paymentlink;
}

/**
 * Create QuickPay payment and trigger payment link
 *
 * @param $params
 *
 * @return mixed
 * @throws Exception
 */
function helper_create_payment($params)
{
    /** Build request parameters array */
    $request = helper_quickpay_request_params($params);

    /** Create gateway payment */
    $payment = helper_quickpay_request($params['apikey'], '/payments', $request, 'POST');

    if (!isset($payment->id)) {
        throw new Exception('Failed to create payment');
    }

    /** Do payment - payment link URL expected*/
    $paymentLink = helper_create_payment_link($payment->id, $params);
    logTransaction(/**gatewayName*/'quickpay', /**debugData*/['params' => $params], __FUNCTION__ . '::' . 'Create payment request complete');

    return $paymentLink;
}

/**
 * Create QuickPay subscription and trigger payment link | trigger recurring payment
 *
 * @param $params
 *
 * @return mixed
 * @throws Exception
 */
function helper_create_subscription($params)
{
    $activeSubscriptionId = null;
    $paymentLink = null;

    /** Get invoice parent order recurring values */
    $recurringData = getRecurringBillingValues($params['invoiceid']);

    /** Check if invoice parent order is subscription type*/
    if ($recurringData && isset($recurringData['primaryserviceid'])) {
        /** Get active subscription id */
        $result = select_query("tblhosting", "id, subscriptionid", ["orderid" => $recurringData['primaryserviceid']]);
        $data = mysql_fetch_array($result);
        $activeSubscriptionId = $data['subscriptionid'];
    }

    /** Check if active subscription */
    if (isset($activeSubscriptionId)) {
        /** Do subscription recurring payment - null payment link expected*/
        $paymentLink = helper_create_payment_link($activeSubscriptionId, $params, 'recurring');
    } else {
        $request = helper_quickpay_request_params($params);
        /** Create gateway subscription */
        $payment = helper_quickpay_request($params['apikey'], '/subscriptions', $request, 'POST');

        /**
         * Log Transaction.
         *
         * Add an entry to the Gateway Log for debugging purposes.
         */
        logTransaction(/**gatewayName*/'quickpay', /**debugData*/['params' => $params], __FUNCTION__ . '::' . 'Create subscription request complete');

        /** Fail */
        if (!isset($payment->id)) {
            throw new Exception('Failed to create subscription');
        }

        /** Do subscription first payment - payment link URL expected*/
        $paymentLink = helper_create_payment_link($payment->id, $params, 'subscription');
    }

    return $paymentLink;
}

/**
 * Create payment link
 *
 * @param $paymentId - gateway payment id | gateway subscription id
 * @param $params
 * @param string $type - payment | subscription | recurring
 *
 * @return string - Payment URL | Null
 * @throws Exception
 */
function helper_create_payment_link($paymentId, $params, $type = 'payment')
{
    $paymentlink = null;

    /** Quickpay API key */
    $apiKey = $params['apikey'];

    /** Gateway request parameters array */
    $request = [
        "amount" => str_replace('.', '', $params['amount']),
        "continue_url" => $params['returnurl'],
        "cancel_url" => $params['returnurl'],
        "callback_url" => $params['systemurl'],
        "customer_email" => $params['clientdetails']['email'],
        "payment_methods" => $params['payment_methods'],
        "language" => $params['language'],
        "auto_capture" => $params['autocapture'],
        /** Used only for recurring payment request */
        "autofee" => $params['autofee'],
        /** Used only for simple payment request */
        "auto_fee" => $params['autofee'],
        "branding_id" => $params['quickpay_branding_id'],
        "google_analytics_tracking_id" => $params['quickpay_google_analytics_tracking_id'],
        "google_analytics_client_id" => $params['quickpay_google_analytics_client_id']
    ];

    /** Check if transaction type is recurring */
    if ('recurring' === $type) {
        /** Construt orderid string */
        $request["order_id"] = sprintf('%s%04d_r', $orderPrefix, $params['invoiceid']);

        /** Request endpoint */
        $endpoint = sprintf('subscriptions/%s/recurring', $paymentId/** Subscription_id */);
        $response = helper_quickpay_request($apiKey, $endpoint, $request, 'POST');

        /** Current transaction id */
        $paymentId = $response->id;

        if (!isset($response->id)) {
            throw new Exception('Failed to create recurring payment');
        }

        logTransaction(/**gatewayName*/'quickpay', /**debugData*/['params' => $params], __FUNCTION__ . '::' . 'Recurring payment request complete');
    } else {
        /** Construt request endpoint URL based on payment type */
        $endpoint = sprintf('payments/%s/link', $paymentId);

        if ('subscription' === $type) {
            $endpoint = sprintf('subscriptions/%s/link', $paymentId);
        }

        /** Payment link request */
        $paymentlink = helper_quickpay_request($apiKey, $endpoint, $request, 'PUT');

        /** Fail */
        if (!isset($paymentlink->url)) {
            throw new Exception('Failed to create payment link');
        }

        logTransaction(/**gatewayName*/'quickpay', /**debugData*/['params' => $params], __FUNCTION__ . '::' . 'Create payment link request complete');
    }

    /** Save transaction data to custom table */
    $pdo = Capsule::connection()->getPdo();
    $pdo->beginTransaction();

    try {
        /** Replace old payment link if one already exists */
        $pdo->prepare(
            'DELETE FROM quickpay_transactions WHERE transaction_id = :transaction_id AND paid != 1'
        )->execute([
            ':transaction_id' => $paymentId,
        ]);

        /** Insert operation */
        $statement = $pdo->prepare(
            'INSERT INTO quickpay_transactions (invoice_id, transaction_id, payment_link, amount, paid) VALUES (:invoice_id, :transaction_id, :payment_link, :amount, 0)'
        )->execute([
            ':invoice_id' => $params['invoiceid'],
            ':transaction_id' => $paymentId,
            ':payment_link' => (isset($paymentlink->url)) ? ($paymentlink->url) : (''),
            ':amount' => $params['amount']
        ]);

        $pdo->commit();
    } catch (\Exception $e) {
        /** DB operations fail */
        $pdo->rollBack();
        throw new Exception('Failed to create payment link, please try again later');
    }

    /** Return payment link if payment or subscription and null if recurring payment */
    return (isset($paymentlink->url)) ? ($paymentlink->url) : (null);
}

/**
 * Create quickpay payment | subscription request parameters
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @return array Request parameters
 */
function helper_quickpay_request_params($params)
{
    /** Order Parameters */
    $request_arr = [
        'currency' => $params['currency'],
        'order_id' => sprintf('%s%04d%s', $params['prefix'], $params['invoiceid'], (isset($params['suffix']) ? $params['suffix'] : '')),
        'description' => $params['description'],
        'branding_id' => $params['quickpay_branding_id']
    ];

    /** Invoice Parameters */
    $request_arr['invoice_address'] = [
        'name' => $params['clientdetails']['firstname'] . ' ' . $params['clientdetails']['lastname'],
        'company_name' => $params['clientdetails']['companyname'],
        'street' => ((!empty($params['clientdetails']['address2'])) ? ($params['clientdetails']['address1'] . ', ' . $params['clientdetails']['address2']) : ($params['clientdetails']['address1'])),
        'city' => $params['clientdetails']['city'],
        'zip_code' => $params['clientdetails']['postcode'],
        'region' => $params['clientdetails']['state'],
        'country_code' => QuickPay_Countries::getAlpha3FromAlpha2($params['clientdetails']['countrycode']),
        'phone_number' => $params['clientdetails']['phonenumber'],
        'email' => $params['clientdetails']['email']
    ];

    /** Extract the invoice items details. */
    $invoice = localAPI(/**command*/'GetInvoice', /**postData*/['invoiceid' => $params['invoiceid']]);

    /** Cart Items Parameters */
    $request_arr['basket'] = [];
    foreach ($invoice['items']['item'] as $item) {
        $request_arr['basket'][] = [
            'qty' => 1,
            'item_no' => (string)$item['id'],
            'item_name' => $item['description'],
            'item_price' => (int) $item['amount'],
            'vat_rate' => number_format((((float) $invoice['taxrate']) > 0) ? ((float) $invoice['taxrate']) : ((float) $invoice['taxrate2']), 2, '.', '')
        ];
    }

    return $request_arr;
}

/**
 * Perform a request to the QuickPay API
 *
 * @param $endpoint
 * @param array $params
 * @param string $method
 *
 * @return mixed
 * @throws Exception
 */
function helper_quickpay_request($apikey = '', $endpoint = '', $params = [], $method = 'GET')
{
    /** Endpoint URL */
    $url = 'https://api.quickpay.net/' . $endpoint;

    /** Request header */
    $headers = [
        'Accept-Version: v10',
        'Accept: application/json',
        'Authorization: Basic ' . base64_encode(':' . $apikey),
    ];

    /** Request parameters */
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_URL => $url,
        CURLOPT_POSTFIELDS => preg_replace('/%5B[0-9]+%5D/simU', '%5B%5D', http_build_query($params, '', '&')),
    ];

    /** Do request */
    $ch = curl_init();
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);

    /** Check for errors */
    if (0 !== curl_errno($ch)) {
        /** Fail */
        throw new Exception(curl_error($ch), curl_errno($ch));
    }

    /** Close request */
    curl_close($ch);

    return json_decode($response);
}
/******************** Custom Quickpay functions END *************************/

/**************** Custom Quickpay DB table functions START ******************/
/**
 * Install quickpay custom table
 *
 * @param PDO $pdo
 */
function helper_install_table(PDO $pdo)
{
    $pdo->beginTransaction();

    try {
        $query = "CREATE TABLE IF NOT EXISTS `quickpay_transactions` (
            `id`             int(10) NOT NULL AUTO_INCREMENT,
            `invoice_id`     int (10) UNSIGNED NOT NULL,
            `transaction_id` int (32) UNSIGNED NOT NULL,
            `payment_link`   varchar(255) NOT NULL,
            `amount`         decimal(10,2) NOT NULL,
            `paid`           tinyint(1) UNSIGNED NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `id` (`id`)
        ) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;";

        $statement = $pdo->prepare($query);
        $statement->execute();

        $pdo->commit();
    } catch (\Exception $e) {
        /** Fail */
        $pdo->rollBack();
        logActivity('Error during quickpay table creation: ' . $e->getMessage());
    }
}

/**
 * Update quickpay custom table
 *
 * @param PDO $pdo
 */
function helper_update_table(PDO $pdo)
{
    $pdo->beginTransaction();

    try {
        $query = "ALTER TABLE `quickpay_transactions`
            ADD `amount` decimal(10,2) NOT NULL,
            ADD `paid` tinyint(1) unsigned NOT NULL AFTER `amount`";

        $statement = $pdo->prepare($query);
        $statement->execute();

        $pdo->commit();
    } catch (\Exception $e) {
        /** Fail */
        $pdo->rollBack();
        logActivity('Error during quickpay table update: ' . $e->getMessage());
    }
}

/**
 * Check for quickpay custom table and create if not exists
 */
function helper_verify_table()
{
    /** Get PDO and check if table exists */
    $pdo = Capsule::connection()->getPdo();

    $result = $pdo->query("SHOW TABLES LIKE 'quickpay_transactions'");
    $row = $result->fetch(PDO::FETCH_ASSOC);

    /** If not create it */
    if (false === $row) {
        helper_install_table($pdo);
    } else {
        /** check table has columns added in 2020_07 version */
        $result = $pdo->query("SHOW COLUMNS FROM `quickpay_transactions` LIKE 'amount'");
        $row = $result->fetch(PDO::FETCH_ASSOC);

        if (false === $row) {
            /** If not, add them */
            helper_update_table($pdo);
        }
    }
}
/****************** Custom Quickpay DB table functions END ******************/

/************************** Utils functions START **************************/
/** Determine if invoice is part of subscription or not
 * @param string $invoiceid
 *
 * @return string - invoice type
 */
function helper_getInvoiceType($invoiceid)
{
    $recurringData = getRecurringBillingValues($invoiceid);
    if ($recurringData && isset($recurringData['primaryserviceid'])) {
        return 'subscription';
    } else {
        return 'payment';
    }
}
/************************** Utils functions END **************************/
