
<?php
/**
 * HOOK
 * 
 * Renders the change card button and status message 
 *
 * @return - code to be displayed
 */
add_hook('ClientAreaProductDetailsOutput', 1, function ($service) {
    if ($service['service']['product']['paytype'] == 'recurring' && $service['service']['paymentmethod'] == 'quickpay' && $service['service']['domainstatus'] == 'Active') {
        if (isset($_GET["isCardUpdate"])) {
            if (isset($_GET["updatedId"])) {
                /** Get card update status */
                $query_quickpay_transaction = select_query("quickpay_transactions", "id, transaction_id, paid", ["transaction_id" => $_GET["updatedId"]], "id DESC");
                $quickpay_transaction = mysql_fetch_array($query_quickpay_transaction);

                $card_update_status_message = "Your card has been declined, please try again!";
                $status = FALSE;
                if ($quickpay_transaction['paid'] == '1') {
                    $card_update_status_message = "Your card has been succesfully changed for this subscription";
                    $status = TRUE;
                }
                return dispay_change_payment($card_update_status_message, $status, $service['service']['paymentmethod']);
            }
        }

        return dispay_change_payment(null, FALSE, $service['service']['paymentmethod']);
    }
});

/**
 * HOOK
 * 
 * WHMCS hook that handles the post request 
 */
add_hook('ClientAreaProductDetails', 1, function ($vars) {
    require_once __DIR__ . '/../gatewayfunctions.php';
    if (isset($_POST["changeCardFlag"])) {
        handle_change_card_request($vars['service']['subscriptionid'], $vars['service']['id']);
    }
});


/**
 * Create HTML Code
 * 
 * Creates the HTML Code that has to be displayed acoring to the params
 * @param string - Message to be displayed in case of change payment request
 * @param bool - Status of the payment method change request 
 * @param string - Payment Method 
 *
 * @return - code to be displayed
 */
function dispay_change_payment($message, $success, $paymentmethod)
{
    $output = '<div class="card"><div class="card-body"><div class="row">';

    if (!empty($message)) {
         $output .= '<div class="col-12">';
        if ($success) {
            $output .= '
                <div class="alert alert-success alert-dismissible">
                    <strong>Success!</strong> ' . $message . '
                </div>';
        } else {
            $output .= '
                <div class="alert alert-danger alert-dismissible">
                    <strong>Error!</strong> ' . $message . '
                </div>';
        }
        $output .= '</div>';
    }

    $output .= '
        <div class="col-12"><h4 class="text-capitalize">' . $paymentmethod . '</h4></div>
            <div class="col-12">
                <p class="mb-2">Update card details for this subscription:</p>
                <form method="post" id="changeSubscriptionForm">
                    <input type="hidden" id="changeCardFlag" name="changeCardFlag" value="TRUE">
                </form>
                <div class="row"><div class="col-12 col-md-6">
                    <button class="btn btn-block btn-dark" type="submit" form="changeSubscriptionForm" value="Submit">Change card details</button>
                </div>
            </div>
        </div>';

    $output .= '</div></div></div>';

    return $output;
}

/**
 * Change card button click handler
 * 
 * Gets the payment link of the new subscription and redirects the user to it
 * @param string - Subscription id of the displayed product
 * @param string - Service id of the displayed product
 *
 */
function handle_change_card_request($subscriptionId, $serviceId)
{
    require_once __DIR__ . '/../../init.php';
    require_once __DIR__ . '/../gatewayfunctions.php';
    $gatewayModuleName = 'quickpay';
    $gateway = getGatewayVariables($gatewayModuleName);
    $params = [
        "autocapture" => $gateway['autocapture'],
        "apikey" => $gateway['apikey'],
        "subscriptionid" => $subscriptionId,
        "continue_url" => get_server_url() . "/clientarea.php?action=productdetails&id=" . $serviceId . "&isCardUpdate=1"
    ];
    require_once __DIR__ . '/../../modules/gateways/quickpay.php';
    $url = helper_update_subscription($params)->url;
    header("Location:" . $url);
}


/**
 * Get server URL
 * 
 * @return string The url of the server that is hosting the app
 */
function get_server_url()
{
    if (isset($_SERVER['HTTPS'])) {
        $protocol = ($_SERVER['HTTPS'] && $_SERVER['HTTPS'] != "off") ? "https" : "http";
    } else {
        $protocol = 'http';
    }
    return $protocol . "://" . $_SERVER['SERVER_NAME'];
}

?>
