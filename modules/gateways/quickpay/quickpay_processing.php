<?php
require __DIR__ . '/../../../init.php';

/* The number of checks allowed until done */
const MAX_CHECKS = 5;
/* Waiting time between checks, in seconds */
const DELAY = 1;

/* GET params */

$invoice_id = (int) $whmcs->get_req_var("id");
$redirect_url = rawurldecode($whmcs->get_req_var("url"));
/* If invoice id or redirect url are empty, redirect to root */
if (empty($invoice_id) || empty($redirect_url)) {
    header('Location: '.\WHMCS\Utility\Environment\WebHelper::getBaseUrl());
    exit();
}

/* If no user session active, redirect to login */
if (!isset($_SESSION["uid"]) && !isset($_SESSION["adminid"])) {
    require __DIR__ . '/../../../login.php';
    exit();
}

$checks = MAX_CHECKS;
/* Check paid status of invoice */
do {
    /* wait */
    sleep(DELAY);
    $checks -= 1;
    $query_quickpay_transaction = select_query("quickpay_transactions", "id, transaction_id, paid", ["invoice_id" => (int)$invoice_id], "id DESC");
    $quickpay_transaction = mysql_fetch_array($query_quickpay_transaction);
    /* If no invoice found, redirect to home */
    if (empty($quickpay_transaction)) {
        header('Location: '.\WHMCS\Utility\Environment\WebHelper::getBaseUrl());
        exit();
    }
    /* Repeat check until found or checks are exhausted */
} while ('0' == $quickpay_transaction['paid'] && $checks > 0);

/* Redirect to return url */
header("Location: ".$redirect_url);
exit();
