# WHMCS plugin for Quickpay

## Intro
WHMCS integration with the QuickPay payment service provider.

Based on existing integration but modified to use [payment links](https://learn.quickpay.net/tech-talk/payments/link/) instead of form.

Pull requests welcome!

## Supported WHMCS versions
* The plugin has been tested with WHMCS up to version 8.0.4

## [Installation](https://learn.quickpay.net/helpdesk/en/articles/integrations/whmcs/)
* Copy the content of the zip archive to the root of your WHMCS installation on your webserver.Preserve all folders, the files should end in the path /modules/gateways.
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
   - Continue URL
   - Cancel URL
   - Callback URL
      - Should be on the form **http://www.yourserver.com/whmcs*/modules/gateways/callback/quickpay.php*
         - replace *www.yourserver.com/whmcs* with the real path to your server.
   - The rest of the fields are optional
* Press the **Save Changes** button
* You are now ready to use QuickPay as payment provider, you must choose payment method for each group you create.
* Optional:
   - Configure a **Custom Thank You Page** to which the user will be redirected after a payment. In order to setup a **Custom Thank You Page** fill the **Custom Thank You Page URL** field with the internal path to your custom page like following example or the field empty for the default **WHMCS Thank You Page**:
      - Example: custompages/thankyoupage.php

## Changelog
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
 * Fixed VAT calculation
