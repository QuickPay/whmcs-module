<?php
/**
 * WHMCS QuickPay Payment Gateway Module
 *
 * For more information, please refer to the online documentation: https://developers.whmcs.com/payment-gateways/callbacks/
 */

/** Require libraries needed for gateway module functions. */
use WHMCS\Database\Capsule;
use WHMCS\Billing\Invoice;
use WHMCS\Order\Order;

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

    /**
     * Get last operation from request
     * Save variables with operation type and status code.
     */
    $operation = end($request->operations);
    $operationType = $operation->type;
    $qpStatusCode = $operation->qp_status_code;

    $orderType = $request->type;
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

    /** Get invoice data */
    $invoice = localAPI(/**command*/'GetInvoice', /**postData*/['invoiceid' => $invoiceid]);

    /* Calculate the fee */
    $fee = $amount - $invoice['total'];

    /**
     * Validate Callback Invoice ID.
     *
     * Checks invoice ID is a valid invoice number.
     * NOTE: it will count an invoice in any status as valid.
     *
     * Performs a die upon encountering an invalid Invoice ID.
     *
     * Returns a normalized invoice ID.
     *
     * @param int $invoiceId Invoice ID
     * @param string $gatewayName Gateway Name
     */
    $invoiceid = checkCbInvoiceID($invoiceid, $gateway["name"]);


    /**
     * Check Callback Transaction ID.
     *
     * Performs a check for any existing transactions
     * with the same given transaction number.
     *
     * Performs a die upon encountering a duplicate.
     *
     * @param string $transactionId Unique Transaction ID
     */
    checkCbTransID($transid);

    /** If request is accepted, authorized and qp status is ok*/
    if ($request->accepted && in_array($operationType, ['authorize', 'capture', 'recurring']) && ("20000" == $qpStatusCode)) {
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
                localAPI("UpdateInvoice", $values, $adminuser);
            }

            if (
                //  (('authorize' == $operationType) && ("Subscription" != $orderType)) ||
                'authorize' == $operationType
            ) {
                logTransaction(/**gatewayName*/'quickpay', /**debugData*/['operationType' => print_r($operationType, true)], 'operationType');
                logTransaction(/**gatewayName*/'quickpay', /**debugData*/['orderType' => print_r($orderType, true)], 'orderType');

                $updateValues = [
                    'invoiceid' => $invoiceid,
                    'status' => "Payment Pending"
                ];

                /** Update invoice request */
                localAPI("UpdateInvoice", $updateValues, $adminuser);
            }
            elseif ('recurring' == $operationType && 'capture' == $operationType) {

                logTransaction(/**gatewayName*/'quickpay', /**debugData*/['operationType' => print_r($operationType, true)], 'operationType');
                logTransaction(/**gatewayName*/'quickpay', /**debugData*/['orderType' => print_r($orderType, true)], 'orderType');

                // $updateValues = [
                //     'invoiceid' => $invoiceid,
                //     'status' => "Paid"
                // ];

                // /** Update invoice request */
                // localAPI("UpdateInvoice", $updateValues, $adminuser);
                addInvoicePayment($invoiceid, $transid, $amount, $fee, $gatewayModuleName);


            }
            else {
                addInvoicePayment($invoiceid, $transid, $amount, $fee, $gatewayModuleName);
            }
        }

        /** Get recurring values of invoice parent order */
        $recurringData = getRecurringBillingValues($invoiceid);

        /** If Subscription */
        if ($recurringData && isset($recurringData['primaryserviceid'])) {
            /** In order to find any added fee, we must find the original order amount in the database */
            $query_quickpay_transaction = select_query("quickpay_transactions", "transaction_id,paid", ["transaction_id" => (int)$transid]);
            $quickpay_transaction = mysql_fetch_array($query_quickpay_transaction);

            /**
             * Get QP transaction by invoice number if the request is from a previous failed recurring
             * Update with proper data
             */
            if ('1' == $_GET['pay_recurring_failed'] && 'capture' == $operationType) {
            // if ('1' == $_GET['pay_failed_recurring']) {
                $qpTransaction = Capsule::table('quickpay_transactions')
                                            ->where('invoice_id', (int) $invoiceid)
                                            ->where('paid', 0)
                                            ->orderBy("id", "DESC")->first();

                if ($qpTransaction) {
                        Capsule::table('quickpay_transactions')
                            ->where('invoice_id', (int) $invoiceid)
                            ->where('paid', 0)
                            ->update([
                                'transaction_id' => (int)$transid,
                                'payment_link' => '',
                                'amount' => $amount,
                                'paid' => 1,
                            ]);
                }
                return;
            }


            /** If not Paid. */
            if ('0' == $quickpay_transaction['paid']) {
                if ('authorize' == $operationType) {
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

                        /**
                         * Get order ID & accept order (set status=Active)
                         */
                        $orderData = helper_get_order_id(/*Hosting id*/ $recurringData['primaryserviceid']);

                        // Check mobilepay_mark_as_paid_before_capture
                        if ($gateway['mobilepay_mark_as_paid_before_capture'] == 'on') {
                            if($invoice['status'] == 'Unpaid') {
                                addInvoicePayment($invoiceid, $transid, $amount, $fee, $gatewayModuleName);
                            }
                        }
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

                    /**
                     * !!! Paid 1 on subscription parent record = authorized !!!
                     */
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
                localAPI("UpdateInvoice", $updateValues, $adminuser);
            } else {
                $orderModel = new Order;
                $invoiceModel = Invoice::find((int) $invoiceid);
                /**
                 * Accept order if invoice paid (set status=Active)
                 */
                if ('Paid' == $invoiceModel->status) {
                    $orderModel->where('invoiceid', (int) $invoiceid)->update(['status' => 'Active']);
                }
            }

            /** Mark payment in custom table as processed */
            $qpTransaction = Capsule::table('quickpay_transactions')->where('transaction_id', (int) $transid);
            $qpTransaction->update(['paid' => 1]);
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
