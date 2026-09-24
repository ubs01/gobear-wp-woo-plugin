=== Gobear Personalized Phone Cases for WooCommerce ===
Contributors: yourstore
Tags: woocommerce, personalization, phone case, gobear, vending
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let customers design a personalized phone case in WooCommerce, then have a store manager pick a
Gobear device to print/vend it — production only ever starts when staff say so.

== Description ==

This plugin connects a WooCommerce store to the Gobear Open API (GBK77 / GBK114 "printer" devices
and K20 "desk printer" devices — phone case personalization and vending hardware).

**Customer side**

* A "Design Your Case" tool appears on any product you mark as personalizable.
* The shopper picks their phone model, uploads a photo, and positions/zooms it against the case's
  real print template.
* They check out through WooCommerce exactly as normal — no separate payment step, no redirect to
  Gobear.

**Back office**

* Nothing is sent to Gobear automatically. Paid orders wait in a **Fulfillment Queue**
  (Gobear Cases → Fulfillment Queue, or a box right on the order screen) until a staff member
  reviews them.
* Staff pick a specific physical device — the queue shows which devices are online and (for the
  printer family) whether that exact case is actually loaded in stock on the device — and click
  **Send to Device**. That single action is what pushes the job onto the device's production
  queue.
* A signed webhook from Gobear (plus a periodic safety-net check) reports back when production
  finishes, updating the order automatically.

== Installation ==

1. Upload the plugin folder to `wp-content/plugins/`, or install the zip via
   Plugins → Add New → Upload Plugin.
2. Activate it. WooCommerce must already be installed and active.
3. Go to **Gobear Cases → Settings** and enter the App Key / App Secret provided by your Gobear
   account manager.
4. Click **Test Connection** to confirm the credentials work.
5. On each product you want to sell as a personalized case, open the **Gobear Personalization**
   tab in Product Data, enable it, choose the device family, and click **Sync Gobear Catalog**.
6. Give Gobear's operations team the Webhook URL shown on the Settings screen so they can register
   it against your account.

See `SETUP.md` in this folder for a full walkthrough, including the image-hosting requirement
(your site must be publicly reachable over HTTPS — see below) and server requirements.

== Frequently Asked Questions ==

= Does this print/vend the case immediately when the customer pays? =

No — that's deliberate. The job is only ever sent to a device when a staff member picks one from
the Fulfillment Queue and clicks Send to Device.

= Does the plugin upload the artwork file to Gobear, or just a link? =

Gobear's API takes a URL, not a file upload — the device fetches the image from that URL. This
plugin composites the finished artwork server-side and stores it in your WordPress Media Library,
then passes its public URL when dispatching to a device. Your site must be live and reachable over
HTTPS for that to work (not `localhost`).

= What happens if I don't have Imagick installed? =

The plugin falls back to GD (present on virtually every WordPress host) at a capped working
resolution, so it still works — just somewhat slower and at slightly lower artwork resolution.

== Changelog ==

= 1.0.0 =
* Initial release.
