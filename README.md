## WHMCS plugin for Quickpay

WHMCS integration with the QuickPay payment service provider.

Based on existing integration but modified to use [payment links](https://learn.quickpay.net/tech-talk/payments/link/) instead of form.

Pull requests welcome!

## Supported WHMCS versions

*The plugin has been tested with WHMCS up to version 8.0.4

## Installation
  * Upload the content to your modules/gateways/ folder;
  * Activate the gateway in the WHMCS admin area setup > payment > payment gateway;
  * Configure gateway.


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
