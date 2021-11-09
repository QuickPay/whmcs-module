<?php
/**
 * WHMCS QuickPay Payment Gateway Module
 *
 * For more information, please refer to the online documentation: https://developers.whmcs.com/payment-gateways/callbacks/
 */

/** Require libraries needed for gateway module functions. */
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

/** Detect module name from filename. */
$gatewayModuleName = basename(__FILE__, '.php');

/** Fetch gateway configuration parameters. */
$gateway = getGatewayVariables($gatewayModuleName);

/** Die if module is not active. */
if (!$gateway["type"]) {
    die("Module Not Activated");
}

/* Get Returned Variables*/
$requestBody = file_get_contents("php://input");

/**
 * Validate callback authenticity.
 *
 * Most payment gateways provide a method of verifying that a callback
 * originated from them. In the case of our example here, this is achieved by
 * way of a shared secret which is used to build and compare a hash.
 */

 
$key = $gateway['private_key'];
$checksum = hash_hmac("sha256", $requestBody, $key);
if ($checksum === $_SERVER["HTTP_QUICKPAY_CHECKSUM_SHA256"]) {
    /** Decode response */
    $request = json_decode($requestBody);

    logTransaction(/**gatewayName*/'quickpay', /**debugData*/['request' => print_r($request, true)], __FUNCTION__ . '::' . 'callback');

    $operation = end($request->operations);
    $orderType = $request->type;
    $operationType = $operation->type;
    $transid = $request->id;
    $invoiceid = $request->order_id;
    
    /** Strip prefix if any*/
    if (isset($gateway['prefix'])) {
        $invoiceid = explode('_',substr($invoiceid, strlen($gateway['prefix'])))[0];
    }
    $invoiceid_arr = explode("-", $invoiceid);
    if($invoiceid_arr[1] != NULL)
    {
        $invoiceid = $invoiceid_arr[0];
    }

    /** Convert amount to decimal type */
    $amount = ($operation->amount / 100.0);

    /** In order to find any added fee, we must find the original order amount in the database */
    $tblinvoices_query = select_query("tblinvoices", "id,total", array("id" => $invoiceid));
    $tblinvoices = mysql_fetch_array($tblinvoices_query);

    /* Calculate the fee */
    $fee = $amount - $tblinvoices['total'];

    /**
     * Validate Callback Invoice ID.
     *
     * Checks invoice ID is a valid invoice number. Note it will count an
     * invoice in any status as valid.
     *
     * Performs a die upon encountering an invalid Invoice ID.
     *
     * Returns a normalised invoice ID.
     *
     * @param int $invoiceId Invoice ID
     * @param string $gatewayName Gateway Name
     */
    $invoiceid = checkCbInvoiceID($invoiceid, $gateway["name"]);
    

    /**
     * Check Callback Transaction ID.
     *
     * Performs a check for any existing transactions with the same given
     * transaction number.
     *
     * Performs a die upon encountering a duplicate.
     *
     * @param string $transactionId Unique Transaction ID
     */
    checkCbTransID($transid);

    /** If request is accepted, authorized and qp status is ok*/
    if ($request->accepted && (('authorize' == $operationType) || ('recurring' == $operationType) || ('capture' == $operationType)) && ("20000" == $operation->qp_status_code)) {
        /** Add transaction to Invoice */
        if ((("Subscription" == $orderType) && ('authorize' != $operationType)) || ("Subscription" != $orderType)) {
            /** Admin username needed for api commands */
            $adminuser = $gateway['whmcs_adminname'];

            /** Add the fee to Invoice */
            if (0 < $fee) {
                $values = [
                    'invoiceid' => $invoiceid,
                    'newitemdescription' => array("Payment fee"),
                    'newitemamount' => array($fee),
                    'newitemtaxed' => array("0")
                ];
                /** Update invoice request */
                localAPI("updateinvoice", $values, $adminuser);
            }
            if (
                //  (('authorize' == $operationType) && ("Subscription" != $orderType)) ||
                 ('recurring' == $operationType)
                ) {

                logTransaction(/**gatewayName*/'quickpay', /**debugData*/['operationType' => print_r($operationType, true)], 'operationType');
                logTransaction(/**gatewayName*/'quickpay', /**debugData*/['orderType' => print_r($orderType, true)], 'orderType');

                $updateValues = [
                    'invoiceid' => $invoiceid,
                    'status' => "Payment Pending"
                ];

                /** Update invoice request */
                localAPI("updateinvoice", $updateValues, $adminuser);
            } else {
                $updateValues = [
                    'invoiceid' => $invoiceid,
                    'status' => "Paid"
                ];

                /** Update invoice request */
                localAPI("updateinvoice", $updateValues, $adminuser);

                /** Api request parameters */
                $values = [
                    'invoiceid' => $invoiceid,
                    'transid' => $transid,
                    'amount' => $tblinvoices['total'] + $fee,
                    'fees' => $fee,
                    'gateway' => $gatewayModuleName
                ];

                /**
                 * Add Invoice Payment.
                 *
                 * Applies a payment transaction entry to the given invoice ID.
                 *
                 * @param int $invoiceId         Invoice ID
                 * @param string $transactionId  Transaction ID
                 * @param float $paymentAmount   Amount paid (defaults to full balance)
                 * @param float $paymentFee      Payment fee (optional)
                 * @param string $gatewayModule  Gateway module name
                 */
                localAPI("addinvoicepayment", $values, $adminuser);
            }
        }

        /** Get recurring values of invoice parent order */
        $recurringData = getRecurringBillingValues($invoiceid);

        /** If Subscription */
        if ($recurringData && isset($recurringData['primaryserviceid'])) {
            /** In order to find any added fee, we must find the original order amount in the database */
            $query_quickpay_transaction = select_query("quickpay_transactions", "transaction_id,paid", ["transaction_id" => (int)$transid]);
            $quickpay_transaction = mysql_fetch_array($query_quickpay_transaction);

            if ('0' == $quickpay_transaction['paid']) {
                if ($operation->type=='authorize') {
                    require_once __DIR__ . '/../../../modules/gateways/quickpay.php';

                    /** Check if the request is a card change request */
                    if($_GET['isUpdate'] == "0")
                    {
                         /** SET subscription id in tblhosting if is empty, in order to enable autobiling and cancel methods*/
                        update_query("tblhosting", ["subscriptionid" => $transid], ["id" => $recurringData['primaryserviceid'], "subscriptionid" => '']);

                        
                            /** Payment link from response */
                        $linkArray = json_decode(json_encode($request->link), true);

                            /** Recurring payment parameters */
                        $params = [
                                "amount" => number_format(($linkArray['amount']/100.0), 2, '.', ''), /** Convert amount to decimal */
                                "returnurl" => $linkArray['continue_url'],
                                "callback_url" => $linkArray['callback_url'],
                                "clientdetails" => ['email' => $linkArray['customer_email']],
                                "payment_methods" => $linkArray['payment_methods'],
                                "language" => $linkArray['language'],
                                "autocapture" => $gateway['autocapture'],
                                "autofee" => $gateway['autofee'],
                                "quickpay_branding_id" => $gateway['quickpay_branding_id'],
                                "quickpay_google_analytics_tracking_id" => $gateway['quickpay_google_analytics_tracking_id'],
                                "quickpay_google_analytics_client_id" => $gateway['quickpay_google_analytics_client_id'],
                                "apikey" => $gateway['apikey'],
                                "invoiceid" => $invoiceid,
                                "prefix" => $gateway['prefix'],
                                "description" => $request->description
                            ];

                            /** Trigger recurring payment */
                        helper_create_payment_link($transid/** Subscription ID */, $params, 'recurring');

                    }
                    else
                    {
                        if($_GET['isUpdate'] == "1")
                        {
                            /** Get the old subscription id */
                            $result = select_query("tblhosting", "id, subscriptionid", ["id" => $recurringData['primaryserviceid']]);
                            $data = mysql_fetch_array($result);  
                            $params = [
                                'subscriptionID' => $data['subscriptionid'],
                                'apikey' => $gateway['apikey']];

                            /** Cancel the subscripition */
                            quickpay_cancelSubscription($params);
                            /** Update the subscription id */
                            update_query("tblhosting", ["subscriptionid" => $transid], ["id" => $recurringData['primaryserviceid']]);

                        }
                    }

                    /** Paid 1 on subscription parent record = authorized */

                    full_query("UPDATE quickpay_transactions SET paid = '1' WHERE transaction_id = '" . (int)$transid . "'");
                } else {
                    /**  If recurring payment succeeded set transaction as paid */
                    full_query("UPDATE quickpay_transactions SET paid = '1' WHERE transaction_id = '" . (int)$transid . "'");
                }
            }
            /** If Simple Payment */
        } else {
            if ('recurring' == $operationType) {
                $updateValues = [
                    'status' => "Payment Pending"
                ];

                /** Update invoice request */
                localAPI("updateinvoice", $updateValues, $adminuser);
            }

            /** Mark payment in custom table as processed */
            full_query("UPDATE quickpay_transactions SET paid = '1' WHERE transaction_id = '" . (int)$transid . "'");
        }
        /** Save to Gateway Log: name, data array, status */
        logTransaction(/**gatewayName*/$gateway["name"], /**debugData*/$_POST, "Successful");
    } else {
        /** Save to Gateway Log: name, data array, status */
        logTransaction(/**gatewayName*/$gateway["name"], /**debugData*/$_POST, "Unsuccessful");
    }
} else {
    /** Save to Gateway Log: name, data array, status */
    logTransaction(/**gatewayName*/$gateway["name"], /**debugData*/$_POST, "Bad private key in callback, check configuration");
}
