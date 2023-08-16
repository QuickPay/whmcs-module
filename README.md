# WHMCS plugin for Quickpay

## Intro
WHMCS integration with the QuickPay payment service provider.

Based on existing integration but modified to use [payment links](https://learn.quickpay.net/tech-talk/payments/link/) instead of form.

Pull requests welcome!

## Supported WHMCS versions
* The plugin has been tested with:
   - WHMCS up to version 8.5.1
   - PHP up to version 7.3.28

## [Installation](https://quickpay.net/helpdesk/integrations/whmcs/)
* Copy the content of the zip archive to the root of your WHMCS installation on your webserver. Preserve all folders, the files should end in the path `/modules/gateways`.
* Open the administration area of your WHMCS installation, and log in as admin.
* Go to **Setup** > **Payments** > **Payment gateways**
* Choose QuickPay in the dropdown and push the button **Activate**
* Fill in at least the following fields:
   - WHMCS administrator username
   - Merchant ID
   - Payment Window Api Key
   - API key
   - Private Key
   - Agreement ID
   - The rest of the fields are optional
* Press the **Save Changes** button
* You are now ready to use QuickPay as payment provider, you must choose payment method for each group you create.
* Optional:
   - Configure a **Custom Thank You Page** to which the user will be redirected after a payment. In order to setup a **Custom Thank You Page** fill the **Custom Thank You Page URL** field with the internal path to your custom page like following example or the field empty for the default **WHMCS Thank You Page**:
      - Example: custompages/thankyoupage.php

## How to:
   1. Capture
      - When "Autocapture" is set to "1", the orders are captured automatically (set to Paid)
      - When "Autocapture" is set to "0" you can capture the order manually from Quickpay merchant dashboard and then create a transaction for the invoice, in the "Add Payment" tab (invoice details view) (use payment id from Quickpay merchant dashboard).
   1. Refund:
      - To refund an order you can use `Refund` tab from the invoice (only if the order has been captured previously).
   1. Cancel:
      - To cancel an order you can do it from Quickpay merchant dashboard.

## Changelog
#### 2.5.1:
 * Fixed some issues
   - Fixed plugin not working on latest WHMCS versions/PHP versions
   - Added (optional) to optional fields
#### 2.5.0:
 * Fixed some issues
   - added possibility to pay a failed automatic recurring payment
   - fixed set "Paid" (not payment pending) on automatic recurring payment when auto_capture in on
#### 2.4.5:
 * small changes
   - removed inexistent file inclusion (stop warning from module log)
   - added "how to" section to Readme
#### 2.4.4:
 * Fixed some issues
   - Date paid not show on invoice summary in admin panel
   - The order status not set as 'Active' if the order was already paid
#### 2.4.3:
 * Added support for card details update on subscription products
#### 2.4.2:
 * Added support for MobilePay subscriptions
 * Updated the payment flow of the subscriptions to use the "Payment Pending" state between "recurring" and "capture" operations
#### 2.4.1:
 * Fixed logic that calculated tax when tax is set to 0.
#### 2.4.0:
 * Code refactoring;
 * Rebuild function that trigger "Create subscription" service to match requested parameters;
 * Rebuild function that trigger "Create payment" service to match requested parameters;
 * Added Automatic "Recurring Payment" functionality for subscriptions that can be triggered by 2 cases:
    - After payment authorization;
    - On due date.
 * Added "Cancel Subscription" functionality that can be triggered by 2 cases:
    - Manual from Admin > Order screen;
    - Automatic at expiration.
 * Added "Refund" functionality for simple and recurring payments, can be triggered from Admin > Invoice screen.
 * Added "Custom Thank You Page" setup.
 * Fix: Apply transaction payment after the "Payment fee" is added to invoice.
