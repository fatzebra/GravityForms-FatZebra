=== Gravity Forms Fat Zebra Add-On ===
Contributors: fatzebra
Donate link: https://www.fatzebra.com.au/
Tags: forms, fatzebra, payments, gravity forms, credit card payments
Requires at least: 3.2
Tested up to: 4.6
Stable tag: 0.2.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Adds functionality to Gravity Forms to support online payments within your forms via Fat Zebra,
an Australian Internet Payment Gateway.

== Description ==

Add payment fields to forms on your WordPress site using Gravity Forms. This payment adds support for Fat Zebra as a payment gateway for Australian Merchants to accept payments online in their forms, such as booking forms or simple purchases.

More functionality (including multiple products, donation forms etc) to be added shortly.

The source for this plugin can be found at https://github.com/fatzebra/GravityForms-FatZebra

This plugin requires a Fat Zebra account - if you do not have an account with Fat Zebra you can register at https://www.fatzebra.com.au

== Installation ==

1. Upload `fatzebra.php` to the `/wp-content/plugins/gravityforms-fatzebra` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Add your username and token under the Gravity Forms settings page (Forms -> Settings -> Fat Zebra [link at the top of the page])
4. Add at least one product and card fields to your form and test.

Testing details can be found at https://www.fatzebra.com.au/support/testing

== Screenshots ==

1. Fat Zebra Settings page
2. Example form (Booking Form)

== Changelog ==

= 0.2.1 =
* Fixed missing init call.

= 0.2.0 =
* Updated to support GFPaymentAddon integration method

= 0.1.6 =
* Reduced amount of entropy used in transaction reference generation, causing problems with upstream processor.

= 0.1.5 =
* Fixed handling of test mode flag
* Minor formatting of text

= 0.1.4 =
* Fixed bug where amount was not being passed properly

= 0.1.2 =
* Updated to support single products with quantities

= 0.1.1 =
* Initial release, supports single product payments only
