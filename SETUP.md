# Setup & Verification Guide

This plugin was built and reviewed in an environment without a live WordPress/WooCommerce site or
real Gobear credentials, so it has **not** been exercised end-to-end against the real API. This
guide is both the install instructions and the test script to run on your own site.

## Requirements

- WordPress 5.8+, WooCommerce 6.0+, PHP 7.4+.
- **Your site must be publicly reachable over HTTPS** at the moment staff dispatch a job — Gobear's
  create-order API takes an image *URL*, not a file upload, so the device fetches the artwork from
  your site. A `localhost`/private site cannot be used for that step.
- GD (near-universal on WP hosts) at minimum; the Imagick PHP extension if available is used
  automatically for faster, higher-fidelity image compositing.
- A Gobear App Key/App Secret (contact your Gobear sales/account manager) and, ideally, at least
  one device in test/idle status you're allowed to send a real job to.

## Install

1. Zip the `gobear-phone-case-personalization/` folder (or point Plugins → Add New → Upload at it).
2. Activate. If WooCommerce isn't active you'll see an admin notice instead of a fatal error.
3. Confirm the two custom tables were created: `wp_gobear_designs` and `wp_gobear_fulfillments`
   (check via phpMyAdmin/Adminer, or `wp db query "SHOW TABLES LIKE 'wp_gobear_%'"` with WP-CLI).

## Configure

1. **Gobear Cases → Settings**: enter App Key + App Secret, click **Test Connection**.
   - If it reports `MERCHANT`, you're done — it auto-fills the Merchant ID.
   - If it reports `AGENT`, several endpoints require an explicit Merchant ID: fill it in yourself
     (ask Gobear which merchant id your storefront should scope to) and save again.
2. Copy the **Webhook URL** shown on that screen and send it, with your App Key, to Gobear's
   ops/support contact — per their docs, webhook registration is manual on their side.
3. Edit a product you want to sell as a personalized case → **Gobear Personalization** tab →
   enable it, pick the device family (Printer = GBK77/GBK114, Desk Printer = K20), save the
   product, then click **Sync Gobear Catalog** to pull the phone/case catalog for that family.

## Test the customer flow

1. View the product on the storefront. Click **Design Your Case**.
2. Pick a phone model, choose a case style if more than one is offered, upload a photo, drag/zoom
   it, click **Use this design**. You should see a small summary (thumbnail + phone/case name)
   appear under the button.
3. Add to cart and complete checkout with a test order. Confirm:
   - The order line item shows "Phone" / "Case" meta.
   - An order note says N personalized item(s) are awaiting device assignment.
   - A new row exists in `wp_gobear_fulfillments` with `status = pending`.

## Test the fulfillment flow

1. Go to **Gobear Cases → Fulfillment Queue**. The order should be listed as "Awaiting device
   assignment".
2. Click **Fulfill**. The device list should load (calls Gobear's device/list, and — for the
   printer family — device/storage to flag whether that case is actually in stock on each device).
3. Pick a device (ideally a real test device you're allowed to send a job to) and click **Send to
   Device**. This is the one action in the whole plugin that calls Gobear's create-order endpoint
   and therefore **starts real production/vending** — don't do this against a live device unless
   you mean it.
4. Confirm the fulfillment row's status becomes "Sent to device", the order gets an order note with
   the Gobear order number, and (if the order was Processing/On-hold) the order status becomes
   "Personalizing".
5. Once Gobear's webhook is registered, finishing the job on the device should flip the fulfillment
   row to Completed/Failed automatically. Until then (or as a backstop), use **Check status now**
   on the Fulfill screen, or wait for the 15-minute cron safety net.

## Verifying the webhook signature logic without a live webhook

You can sanity-check `Gobear_Webhook::compute_signature()` against doc 5.0's own PHP example with
a fixed fixture, e.g. in a WP-CLI shell (`wp shell`):

```php
$params = array( 'app_key' => 'test', 'order_id' => 123, 'event_type' => 'ORDER_SUCCESS' );
echo Gobear_Webhook::compute_signature( $params, 'mysecret' );
// Compare against: md5('app_key=test&event_type=ORDER_SUCCESS&order_id=123&' . 'mysecret')
```
Both should print the same MD5 hash — the fields are sorted ascending by key name before
concatenation, exactly as doc 5.0 specifies.

## What wasn't (and couldn't be) tested here

- No live Gobear account was available, so no real API call in this plugin has been exercised.
- No running WordPress/WooCommerce install was available, so no admin screen or storefront flow
  was clicked through in a browser.
- Every PHP file was manually reviewed for correctness; `php -l` syntax linting was attempted but
  the only local PHP binary in this environment was broken (missing shared library) and could not
  be run — please run `php -l` (or just activate the plugin, which will fatal loudly on a syntax
  error) as your first check on a real server.
