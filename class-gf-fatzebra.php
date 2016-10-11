<?php

if (class_exists("GFForms")) {
    GFForms::include_addon_framework();

GFForms::include_payment_addon_framework();

class GFFatZebra extends GFPaymentAddOn
{
    protected $_requires_credit_card = true;
    protected $_version = '1.0.1';
    protected $_min_gravityforms_version = '1.9.12';
    protected $_slug = 'gravityforms-fatzebra';
    protected $_path = 'gravityforms-fatzebra/fatzebra.php';
    protected $_full_path = __FILE__;
    protected $_url = 'https://www.fatzebra.com.au';
    protected $_title = 'Fat Zebra Payments';
    protected $_short_title = 'Fat Zebra';

    const SANDBOX_URL = "https://gateway.sandbox.fatzebra.com.au/v1.0/purchases";
    const LIVE_URL = "https://gateway.fatzebra.com.au/v1.0/purchases";
    const SANDBOX_DASHBOARD_URL = "https://dashboard.sandbox.fatzebra.com.au/v2/";
    const LIVE_DASHBOARD_URL = "https://dashboard.fatzebra.com.au/v2/";

    public function plugin_settings_fields() {
        return array(
            array(
                "title"  => "Fat Zebra",
                "fields" => array(
                    array(
                        "name"    => "username",
                        "tooltip" => "Your Fat Zebra username.",
                        "label"   => "Username",
                        "type"    => "text",
                        "class"   => "small",
                        "default_value" => "TEST"
                    ),
                    array(
                        "name"    => "token",
                        "tooltip" => "Your Fat Zebra token.",
                        "label"   => "Token",
                        "type"    => "text",
                        "class"   => "small",
                        "default_value" => "TEST"
                    ),
                    array(
                        "name"    => "mode",
                        "tooltip" => "Perform transactions in live or sandbox.",
                        "label"   => "Gateway Mode",
                        "type"    => "select",
                        "choices" => array(array("label" => "Sandbox", "value" => "sandbox"), array("label" => "Live", "value" => "live")),
                        "class"   => "small",
                        "default_value" => "sandbox"
                    ),
                )
            )
        );
    }

    
    public function authorize($feed, $submission_data, $form, $entry) {
        $details = array();

        $details["card_holder"] = rgar( $submission_data, 'card_name' );
        $details["card_number"] = rgar( $submission_data, 'card_number' );
        $expiry = rgar( $submission_data, 'card_expiration_date' );
        $details["card_expiry"] = $expiry[0] . '/' . $expiry[1];
        $details["cvv"] = rgar( $submission_data, 'card_security_code' );
        $amt = (float)rgar( $submission_data, 'payment_amount' );
        $amt = $amt * 100;
        $details["amount"] = (int)$amt;
        $details["reference"] = strtoupper(uniqid('GF-'));
        $details["customer_ip"] = $_SERVER['REMOTE_ADDR'];

        $settings = $this->get_plugin_settings();
        $result = $this->do_payment($details, $settings);

        if (is_wp_error($result)) {
            switch($result->get_error_code()) {
                case 1: // Non-200 response, so failed... (e.g. 401, 403, 500 etc).
                    return array('is_authorized' => false, 'error_message' => 'Error communicating with gateway. Please check with the site owner (invalid response code).', 'transaction_id' => '');
                    break;

                case 2: // Gateway error (data etc)
                    $errors = join(", ", $result->get_error_data());
                    $error_message = "Gateway Error: " . $errors;
                    return array('is_authorized' => false, 'error_message' => $error_message, 'transaction_id' => '');
                    break;

                case 3: // Declined - error data is array with keys: message, id
                    $data = $result->get_error_data();
                    $message = $data["message"];
                    $error_message = "Payment Declined: " . $message;
                    return array('is_authorized' => false, 'error_message' => $error_message, 'transaction_id' => '');
                    break;

                case 4: // Exception caught, something bad happened. Data is exception
                    $error_message = "Unknown gateway error.";
                    return array('is_authorized' => false, 'error_message' => $error_message, 'transaction_id' => '');
                    break;
            }
        } else {
            return array(
                'is_authorized' => true, 
                'error_message' => null, 
                'transaction_id' => $result['transaction_id'], 
                'captured_payment' => array(
                    'is_success' => true,
                    'error_message' => '',
                    'transaction_id' => $result['transaction_id'],
                    'amount' => ((float)$result['amount']) / 100
                )
            );
        }
    }


    /**
     * @static Perform the transaction against Fat Zebra
     * @param $params array - expects: card_token|(card_number, card_holder, cvv, card_expiry)
     * @return array|WP_Error
     */
    private function do_payment($params, $settings) {

        $sandbox_mode = $settings["mode"] == "sandbox";
        $order_text = json_encode($params);

        $url = $sandbox_mode ? self::SANDBOX_URL : self::LIVE_URL;

        $args = array(
            'method' => 'POST',
            'body' => $order_text,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($settings["username"] . ":" . $settings["token"]),
                'X-Test-Mode' => $test_mode,
                'User-Agent' => "GravityForms Plugin " . $this->_version
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
            //return $error;
        }
    }
    private static $_instance = null;

	public static function get_instance() {
	if ( self::$_instance == null ) {
	self::$_instance = new GFFatZebra();
	}
	
	return self::$_instance;
	}
}

new GFFatZebra();
}