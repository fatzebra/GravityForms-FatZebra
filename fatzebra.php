<?php
/*
Plugin Name: Gravity Forms Fat Zebra Add-On
Plugin URI: http://github.com/fatzebra/GravityForms-FatZebra
Description: Accept credit card payments through Gravity Forms, simply with Fat Zebra
Version: 0.1.1
Author: Matthew Savage
Author URI: https://www.fatzebra.com.au

------------------------------------------------------------------------
Copyright 2012 Fat Zebra Pty. Ltd.
last updated: July 31, 2012
This plugin was based on the Stripe plugin, found at http://naomicbush.github.com/Gravity-Forms-Stripe/

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
*/

$gf_fatzebra_file = __FILE__;

define('GRAVITYFORMS_FATZEBRA_FILE', $gf_fatzebra_file);
define('GRAVITYFORMS_FATZEBRA_PATH', WP_PLUGIN_DIR . '/' . basename(dirname($gf_fatzebra_file)));

add_action('init', array('GFFatZebra', 'init'));

register_activation_hook(GRAVITYFORMS_FATZEBRA_FILE, array('GFFatZebra', 'add_permissions'));

class GFFatZebra
{
    private static $path = 'gravityforms-fatzebra/fatzebra.php';
    private static $url = 'http://www.gravityforms.com';
    private static $slug = 'gravityforms-fatzebra';
    private static $version = '0.1';
    private static $min_gravityforms_version = '1.6.3.3';
    private static $transaction_response = '';
    private static $log = null;

    const SANDBOX_URL = "https://gateway.sandbox.fatzebra.com.au/v1.0/purchases";
    const LIVE_URL = "https://gateway.fatzebra.com.au/v1.0/purchases";
    const SANDBOX_DASHBOARD_URL = "https://dashboard.sandbox.fatzebra.com.au";
    const LIVE_DASHBOARD_URL = "https://dashboard.fatzebra.com.au";

    //Plugin starting point. Will load appropriate files
    public static function init()
    {
        if (!self::is_gravityforms_supported())
            return;

        if (is_admin()) {
            //enables credit card field
            add_filter('gform_enable_credit_card_field', '__return_true');

            if (RGForms::get('page') == 'gf_settings') {
                RGForms::add_settings_page('Fat Zebra (Payments)', array('GFFatZebra', 'settings_page'), self::get_base_url() . '/images/fatzebra_wordpress_icon_32.png');
                add_filter('gform_currency_disabled', '__return_true');

                //loading Gravity Forms tooltips
                require_once(GFCommon::get_base_path() . '/tooltips.php');
                add_filter('gform_tooltips', array('GFFatZebra', 'tooltips'));
            }

        } else {
            //handling post submission.
            add_filter('gform_field_validation', array('GFFatZebra', 'gform_field_validation'), 10, 4);
            add_filter('gform_validation', array('GFFatZebra', 'fatzebra_validation'), 10, 4);
            add_action('gform_after_submission', array('GFFatZebra', 'fatzebra_after_submission'), 10, 2);
        }
    }

    /**
     * @static Add Fat Zebra tooltips to the base tooltips
     * @param $tooltips
     * @return array
     */
    public static function tooltips($tooltips)
    {
        $fatzebra_tooltips = array(
            'fatzebra_username' => '<h6>' . __('Username', 'gravityforms-fatzebra') . '</h6>' . __('Enter the Username for your Fat Zebra account.', 'gravityforms-fatzebra'),
            'fatzebra_token' => '<h6>' . __('Token', 'gravityforms-fatzebra') . '</h6>' . __('Enter the Token for your Fat Zebra account.', 'gravityforms-fatzebra'),
            'fatzebra_test_mode' => '<h6>' . __('Test Mode', 'gravityforms-fatzebra') . '</h6>' . __('Turn on Test Mode for your Fat Zebra account. This will use your test merchant account.', 'gravityforms-fatzebra'),
            'fatzebra_sandbox_mode' => '<h6>' . __('Sandbox Mode', 'gravityforms-fatzebra') . '</h6>' . __('Turn on Test Mode for your Fat Zebra account. This will not interact with your bank.', 'gravityforms-fatzebra')
        );
        return array_merge($tooltips, $fatzebra_tooltips);
    }

    /**
     * @static Render the settings page for Gravity Forms
     */
    public static function settings_page()
    {
        if (isset($_POST["uninstall"])) {
            check_admin_referer('uninstall', 'gf_fatzebra_uninstall');
            self::uninstall();

            ?>
        <div class="updated fade"
             style="padding:20px;"><?php _e(sprintf("Gravity Forms Fat Zebra Add-On has been successfully uninstalled. It can be re-activated from the %splugins page%s.", "<a href='plugins.php'>", "</a>"), 'gravityforms-fatzebra')?></div>
        <?php
            return;
        } else if (isset($_POST["gf_fatzebra_submit"])) {
            check_admin_referer('update', 'gf_fatzebra_update');
            $settings = array(
                'username' => $_POST['fatzebra_username'],
                'token' => $_POST['fatzebra_token'],
                'test_mode' => $_POST['fatzebra_test_mode'],
                'sandbox_mode' => $_POST['fatzebra_sandbox_mode']
            );


            update_option('gf_fatzebra_settings', $settings);
            $fz_updated = true;
        } else {
            $settings = get_option('gf_fatzebra_settings');
        }

        ?>
        <form method="post" action="">
            <?php wp_nonce_field('update', 'gf_fatzebra_update') ?>

            <h3><?php _e('Fat Zebra Settings', 'gravityforms-fatzebra') ?></h3>

            <?php if ($fz_updated) { ?>
                <div class="updated fade" style="padding: 20px;">Your settings have been updated.</div>
            <?php } ?>

            <p style="text-align: left;">
                <?php _e(sprintf("Fat Zebra is an Australian Payment Gateway which allows you to accept credit card payments online. Use Gravity Forms to collect payment information and automatically integrate to your client's Fat Zebra account. If you don't have a Fat Zebra account, you can %ssign up for one here%s", "<a href='https://www.fatzebra.com.au' target='_blank'>", "</a>"), 'gravityforms-fatzebra') ?>
            </p>

            <table class="form-table">
                <tr>
                    <th scope="row" nowrap="nowrap">
                        <label for="fatzebra_username"><?php _e('Username', 'gravityforms-fatzebra'); ?> <?php gform_tooltip('fatzebra_username') ?></label>
                    </th>
                    <td width="88%">
                        <input class="size-1" id="fatzebra_username" name="fatzebra_username"
                               value="<?php echo esc_attr($settings["username"]); ?>"/>
                    </td>
                </tr>
                <tr>
                    <th scope="row" nowrap="nowrap">
                        <label for="fatzebra_token"><?php _e('Token', 'gravityforms-fatzebra'); ?> <?php gform_tooltip('fatzebra_token') ?></label>
                    </th>
                    <td width="88%">
                        <input class="size-1" id="fatzebra_token" name="fatzebra_token"
                               value="<?php echo esc_attr($settings["token"]); ?>"/>
                        <br/>
                        <small><?php _e("Your <strong>Username</strong> and <strong>Token</strong> can be found on your account settings page in your <a href='https://dashboard.fatzebra.com.au' target='_blank'>Dashboard</a>", "gravityforms-fatzebra") ?></small>
                    </td>
                </tr>
                <tr>
                    <th scope="row" nowrap="nowrap">
                        <label for="fatzebra_test_mode"><?php _e('Use Test Mode', 'gravityforms-fatzebra'); ?> <?php gform_tooltip('fatzebra_test_mode') ?></label>
                    </th>
                    <td width="88%">
                        <select name="fatzebra_test_mode" id="fatzebra_test_mode">
                            <option value="1"<?php echo $settings["test_mode"] ? " selected='selected'" : ""; ?>>Yes</option>
                            <option value="0"<?php echo $settings["test_mode"] ? "" : " selected='selected'"; ?>>No</option>
                        </select>
                        <br/>
                        <small><?php _e("What is the difference between <strong>Test Mode</strong> and <strong>Sandbox Mode</strong>? <a href='https://www.fatzebra.com.au/help/testing#modes' target='_blank'>Find out here</a>", "gravityforms-fatzebra") ?></small>
                    </td>
                </tr>


                <tr>
                    <th scope="row" nowrap="nowrap">
                        <label for="fatzebra_sandbox_mode"><?php _e('Use Sandbox Mode', 'gravityforms-fatzebra'); ?> <?php gform_tooltip('fatzebra_sandbox_mode') ?></label>
                    </th>
                    <td width="88%">
                        <select name="fatzebra_sandbox_mode" id="fatzebra_sandbox_mode">
                            <option value="1"<?php echo $settings["sandbox_mode"] ? " selected='selected'" : ""; ?>>Yes</option>
                            <option value="0"<?php echo $settings["sandbox_mode"] ? "" : " selected='selected'"; ?>>No</option>
                        </select>
                    </td>
                </tr>

                <tr>
                    <td colspan="2"><input type="submit" name="gf_fatzebra_submit" class="button-primary"
                                           value="<?php _e('Save Settings', 'gravityforms-fatzebra') ?>"/></td>
                </tr>

            </table>

        </form>

        <form action="" method="post">
            <?php wp_nonce_field('uninstall', 'gf_fatzebra_uninstall') ?>
            <?php if (GFCommon::current_user_can_any('gravityforms_fatzebra_uninstall')) { ?>
            <div class="hr-divider"></div>

            <h3><?php _e('Uninstall Fat Zebra Add-On', 'gravityforms-fatzebra') ?></h3>
            <div
                class="delete-alert">
                <?php
                $uninstall_button = '<input type="submit" name="uninstall" value="' . __('Uninstall Fat Zebra Add-On', 'gravityforms-fatzebra') . '" class="button" onclick="return confirm(\'' . __("Warning! This will disable all forms which use Fat Zebra. This cannot be undone. \'OK\' to delete, \'Cancel\' to stop", 'gravityforms-fatzebra') . '\');"/>';
                echo apply_filters('gform_fatzebra_uninstall_button', $uninstall_button);
                ?>
            </div>
            <?php } ?>
        </form>
    <?php
    }

    /**
     * @static
     * Add permissions for the plugin
     */
    public static function add_permissions()
    {
        global $wp_roles;
        $wp_roles->add_cap('administrator', 'gravityforms_fatzebra');
        $wp_roles->add_cap('administrator', 'gravityforms_fatzebra_uninstall');
    }

    /**
     * @static Capabilities for members
     * @param $caps
     * @return array
     */
    public static function members_get_capabilities($caps)
    {
        return array_merge($caps, array('gravityforms_fatzebra', 'gravityforms_fatzebra_uninstall'));
    }

    /**
     * @static Fetch the credit card field from the form
     * @param $form
     * @return bool
     */
    public static function get_creditcard_field($form)
    {
        $fields = GFCommon::get_fields_by_type($form, array('creditcard'));
        return empty($fields) ? false : $fields[0];
    }


    /**
     * @static Determine if the current page is the 'last' page
     * @param $form
     * @return bool
     */
    private static function is_last_page($form)
    {
        $current_page = GFFormDisplay::get_source_page($form["id"]);
        $target_page = GFFormDisplay::get_target_page($form, $current_page, rgpost('gform_field_values'));
        return $target_page == 0;
    }


    /**
     * @static Perform validation on the card fields
     * @param $validation_result
     * @param $value
     * @param $form
     * @param $field
     * @return array
     */
    public static function gform_field_validation($validation_result, $value, $form, $field)
    {
        if ($field['type'] == 'creditcard') {
            $card_number_valid = rgpost('card_number_valid');
            $exp_date_valid = rgpost('exp_date_valid');
            $cvc_valid = rgpost('cvc_valid');
            $cardholder_name_valid = rgpost('cardholder_name_valid');
            $create_token_error = rgpost('create_token_error');
            if (('false' == $card_number_valid) || ('false' == $exp_date_valid) || ('false' == $cvc_valid) || ('false' == $cardholder_name_valid)) {
                $validation_result['is_valid'] = false;
                $message = ('false' == $card_number_valid) ? __('Invalid credit card number.', 'gravityforms-fatzebra') : '';
                $message .= ('false' == $exp_date_valid) ? __(' Invalid expiration date.', 'gravityforms-fatzebra') : '';
                $message .= ('false' == $cvc_valid) ? __(' Invalid security code.', 'gravityforms-fatzebra') : '';
                $message .= ('false' == $cardholder_name_valid) ? __(' Invalid cardholder name.', 'gravityforms-fatzebra') : '';
                $validation_result['message'] = sprintf(__('%s', 'gravityforms-fatzebra'), $message);
            } else if (!empty($create_token_error)) {
                $validation_result['is_valid'] = false;
                $validation_result['message'] = sprintf(__('%s', 'gravityforms-fatzebra'), $create_token_error);
            } else {
                $validation_result['is_valid'] = true;
                unset($validation_result['message']);
            }
        }

        return $validation_result;
    }

    /**
     * @static Checks for a card field, and if present (and visible) process the payment
     * @param $validation_result
     * @return array
     */
    public static function fatzebra_validation($validation_result)
    {
        $fields = GFCommon::get_fields_by_type( $form, array( 'creditcard' ) );
        if (empty($fields)) {
            return $validation_result;
        }
        else {
            if(RGFormsModel::is_field_hidden( $validation_result[ "form" ], $fields[0], array())) {
                return $validation_result;
            } else {
                return self::make_product_payment($validation_result);
            }
        }
    }


    /**
     * @static Perform the transaction against Fat Zebra
     * @param $params array - expects: card_token|(card_number, card_holder, cvv, card_expiry)
     * @return array|WP_Error
     */
    private static function do_payment($params, $settings) {

        $sandbox_mode = $settings["sandbox_mode"];
        $test_mode = $settings["test_mode"];

        $order_text = json_encode($params);

        $url = $sandbox_mode ? self::SANDBOX_URL : self::LIVE_URL;

        $args = array(
            'method' => 'POST',
            'body' => $order_text,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($settings["username"] . ":" . $settings["token"]),
                'X-Test-Mode' => $test_mode,
                'User-Agent' => "GravityForms Plugin " . self::$version
            ),
            'timeout' => 30
        );
        try {
            $response = (array)wp_remote_request($url, $args);

            if ((int)$response["response"]["code"] != 200 && (int)$response["response"]["code"] != 201) {
                $error = new WP_Error();
                $error->add(1, "Credit Card Payment failed: " . $response["response"]["message"]);
                $error->add_data($response);
                return $error;
            }

            $response_data = json_decode($response['body']);

            if (!$response_data->successful) {
                $error = new WP_Error();
                $error->add(2, "Gateway Error", $response_data->errors);

                return $error;
            }

            if (!$response_data->response->successful) {
                $error = new WP_Error();
                $error->add(3, "Payment Declined", array("message" => $response_data->response->message, "id" => $response_data->response->id));
                return $error;
            }

            if ($response_data->response->successful) {
                return array("transaction_id" => $response_data->response->id,
                        "card_token" => $response_data->response->card_token,
                        "amount" => $response_data->response->amount);
            }

        } catch (Exception $e) {
            $error = new WP_Error();
            $error->add(4, "Unknown Error", $e);
            return $error;
        }
    }

    /**
     * @static Extract the card details form the field
     * @param $field the GF creditcard field
     * @return array
     */
    private static function extract_card_details($field) {
        $values = array();
        foreach($field["inputs"] as &$input) {
            switch($input["label"]) {
                case "Card Number":
                    $values["card_number"] = rgpost("input_" . str_replace(".", "_", $input["id"]));
                    break;

                case "Expiration Date":
                    $raw = rgpost("input_" . str_replace(".", "_", $input["id"]));
                    $values["card_expiry"] = $raw[0] . "/" . $raw[1];
                    break;

                case "Security Code":
                    $values["cvv"] = rgpost("input_" . str_replace(".", "_", $input["id"]));
                    break;

                case "Cardholder's Name":
                    $values["card_holder"] = rgpost("input_" . str_replace(".", "_", $input["id"]));
                    break;
            }
        }

        return $values;
    }

    /**
     * @static Extract the product details from the field
     * @param $field the GF product field
     * @return array
     */
    private static function extract_product_details($field) {
        $values = array("amount" => 0);
        foreach($field["inputs"] as &$input) {
            switch($input["label"]) {
                case "Price": // At the moment we only support a single 'line'
                    $raw = rgpost("input_" . str_replace(".", "_", $input["id"]));
                    $values["amount"] += (int)(floatval(str_replace("$", "", $raw)) * 100);
                    break;
            }
        }

        return $values;
    }

    /**
     * @static Perform the product payment and handle the response. If the payment fails it is added as an failed validation.
     * @param $validation_result
     * @return array
     */
    private static function make_product_payment($validation_result)
    {
        // Extract form fields
        $form = $validation_result["form"];
        $details = array();
        foreach($form["fields"] as &$field) {

            switch($field["type"]) {
                case "creditcard":
                    $details = array_merge($details, self::extract_card_details($field));
                    break;

                case "product":
                    $details = array_merge($details, self::extract_product_details($field));
                    break;
            }
        }

        $details["reference"] = strtoupper(uniqid('GF-', true));
        $details["customer_ip"] = $_SERVER['REMOTE_ADDR'];

        $settings = get_option('gf_fatzebra_settings');

        $result = self::do_payment($details, $settings);

        if (is_wp_error($result)) {
            switch($result->get_error_code()) {
                case 1: // Non-200 response, so failed... (e.g. 401, 403, 500 etc).
                    $error_message = "Error communicating with gateway. Please check with the site owner (invalid response code).";
                    break;

                case 2: // Gateway error (data etc)
                    $errors = join(", ", $result->get_error_data());
                    $error_message = "Gateway Error: " . $errors;
                    break;

                case 3: // Declined - error data is array with keys: message, id
                    $data = $result->get_error_data();
                    $message = $data["message"];
                    $error_message = "Payment Declined: " . $message;
                    break;

                case 4: // Exception caught, something bad happened. Data is exception
                    $error_message = "Unknown gateway error.";
                    break;
            }

            // Payment for single transaction was not successful
            return self::set_validation_result($validation_result, $_POST, $error_message);
        } else {
            self::$transaction_response = array(
                'transaction_id' => $result['transaction_id'],
                'reference' => $details["reference"],
                'amount' => floatval((int)$details['amount'] / 100));

            $validation_result["is_valid"] = true;
        }

        return $validation_result;
    }

    /**
     * @static Update the entry in the database with the payment details
     * @param $entry
     * @param $form
     */
    public static function fatzebra_after_submission($entry, $form)
    {
        $entry_id = rgar($entry, 'id');

        if (!empty(self::$transaction_response)) {
            //Current Currency
            $currency = GFCommon::get_currency();
            $transaction_id = self::$transaction_response["transaction_id"];
            $amount = self::$transaction_response["amount"];
            $payment_date = gmdate('Y-m-d H:i:s');
            $entry["currency"] = $currency;
            $entry["payment_status"] = 'Approved';
            $entry["payment_amount"] = $amount;
            $entry["payment_date"] = $payment_date;
            $entry["transaction_id"] = $transaction_id;
            $entry["reference"] = self::$transaction_response["reference"];
            $entry["transaction_type"] = "1";
            $entry["is_fulfilled"] = true;

            RGFormsModel::update_lead($entry);
        }

    }

    /**
     * Get the dashboard URL
     */
    private static function get_dashboard_url() {
        $settings = get_option('gf_fatzebra_settings');
        return $settings["sandbox_mode"] ? self::SANDBOX_DASHBOARD_URL : self::LIVE_DASHBOARD_URL;
    }


    /**
     * @static Sets the validation result if unsuccessful
     * @param $validation_result
     * @param $post
     * @param $error_message
     * @return array
     */
    private static function set_validation_result($validation_result, $post, $error_message)
    {

        $credit_card_page = 0;
        foreach ($validation_result["form"]["fields"] as &$field) {
            if ($field["type"] == 'creditcard') {
                $field["failed_validation"] = true;
                $field["validation_message"] = $error_message;
                $credit_card_page = $field["pageNumber"];
                break;
            }

        }
        $validation_result["is_valid"] = false;

        GFFormDisplay::set_current_page($validation_result["form"]["id"], $credit_card_page);

        return $validation_result;
    }

    /**
     * @static Uninstall the plugin
     *
     */
    public static function uninstall()
    {
        if (!GFFatZebra::has_access('gravityforms_fatzebra_uninstall'))
            die(__('You don\'t have adequate permission to uninstall the Fat Zebra Add-On.', 'gravityforms-fatzebra'));

        //Deactivating plugin
        $plugin = 'gravityforms-fatzebra/fatzebra.php';
        deactivate_plugins($plugin);
        update_option('recently_activated', array($plugin => time()) + (array)get_option('recently_activated'));
    }

    /**
     * @static Check for gravity forms installation
     * @return bool
     */
    private static function is_gravityforms_installed()
    {
        return class_exists('RGForms');
    }

    /**
     * @static Check for GravityForms
     * @return bool|mixed
     */
    private static function is_gravityforms_supported()
    {
        if (class_exists('GFCommon')) {
            $is_correct_version = version_compare(GFCommon::$version, self::$min_gravityforms_version, '>=');
            return $is_correct_version;
        } else {
            return false;
        }
    }

    /**
     * @static Check that the current user has access (for member capabilities)
     * @param $required_permission
     * @return bool|string
     */
    protected static function has_access($required_permission)
    {
        $has_members_plugin = function_exists('members_get_capabilities');
        $has_access = $has_members_plugin ? current_user_can($required_permission) : current_user_can('level_7');
        if ($has_access)
            return $has_members_plugin ? $required_permission : 'level_7';
        else
            return false;
    }


    /**
     * @static Determines if the current page is a 'Fat Zebra' page
     * @return bool
     */
    private static function is_fatzebra_page()
    {
        $current_page = trim(strtolower(RGForms::get('page')));
        return in_array($current_page, array('gf_fatzebra'));
    }

    /**
     * @static Determines the URL of the plugins root folder
     * @return mixed
     */
    private static function get_base_url()
    {
        return plugins_url(null, GRAVITYFORMS_FATZEBRA_FILE);
    }

    /**
     * @static Gets the base path of the plugin's folder
     * @return string
     */
    private static function get_base_path()
    {
        $folder = basename(dirname(__FILE__));
        return WP_PLUGIN_DIR . '/' . $folder;
    }
}

?>