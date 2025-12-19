<?php
/*
Plugin Name: PayJunction Gateway Module for WooCommerce
Description: Credit Card Processing Module for WooCommerce using the PayJunction REST API (adds optional JS tokenization)
Version: 2.2.2-tokenized
Plugin URI: https://pricing.payjunction.com/woothemes
Author: Payjunction
Author URI: https://pricing.payjunction.com/woothemes
*/

add_action('plugins_loaded', 'payjunction_rest_init', 0);

function payjunction_rest_init() {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }
    
    class WC_PJ_Rest extends WC_Payment_Gateway {
        protected $msg = array();
        
        public function __construct() {
            $this->id = 'payjunctionrest';
            $this->method_title = __('PayJunction REST', 'woothemes');
            $this->has_fields = true;
            $this->supports = array('refunds');
            
            $this->init_form_fields();
            $this->init_settings();
            $this->title = isset($this->settings['title']) ? $this->settings['title'] : '';
            $this->description = isset($this->settings['description']) ? $this->settings['description'] : '';
            $this->show_description = isset($this->settings['showdescription']) && $this->settings['showdescription'] == 'yes' ? true : false;
            $this->testmode = isset($this->settings['testmode']) && $this->settings['testmode'] == 'yes' ? true : false;
            $this->cvvmode = isset($this->settings['cvvmode']) && $this->settings['cvvmode'] == 'no' ? true : false;
            $this->localavs = isset($this->settings['uselocalavs']) && $this->settings['uselocalavs'] == 'yes' ? true : false;
            $this->avsmode = isset($this->settings['avsmode']) ? $this->settings['avsmode'] : 'ADDRESS_AND_ZIP';
            $this->dynavsmode = isset($this->settings['dynamicavsmode']) && $this->settings['dynamicavsmode'] == 'yes' ? true : false;
            $this->fraudmsgenabled = isset($this->settings['fraudmsgenabled']) && $this->settings['fraudmsgenabled'] == 'yes' ? true : false;
            $this->fraudmsgtext = isset($this->settings['fraudmsgtext']) ? $this->settings['fraudmsgtext'] : '';
            $this->requestsignature = isset($this->settings['requestsignature']) && $this->settings['requestsignature'] == 'yes' ? true : false;
            $this->signotificationemail = isset($this->settings['signotificationemail']) ? $this->settings['signotificationemail'] : '';
            $this->simpleamounts = isset($this->settings['simpleamount']) && $this->settings['simpleamount'] == 'yes' ? true : false;
            $this->dbg = isset($this->settings['debugging']) && $this->settings['debugging'] == 'yes' ? true : false;
            $this->msg['message'] = '';
            $this->msg['class'] = '';
            
            // Publishable key for JS tokenization (optional)
            $this->publishable_key = isset($this->settings['publishable_key']) ? $this->settings['publishable_key'] : '';

            // Logic for selecting API login and password and server URL based on whether we're in test mode or not
            if (!$this->testmode) {
                $this->login = isset($this->settings['login']) ? $this->settings['login'] : '';
                $this->password = isset($this->settings['password']) ? $this->settings['password'] : '';
                $this->url = 'https://api.payjunction.com/transactions';
                $this->appkey = isset($this->settings['appkey']) && !empty($this->settings['appkey']) ? $this->settings['appkey'] : '639ff34b-d729-48cc-9f99-6e099543bb66';
                $this->view_transaction_url = 'https://www.payjunction.com/trinity/virtualterminal/transaction/view.action?txn.txnTransactionId=%s';
            } else {
                // Change the login and password settings below to use a custom login for payjunctionlabs.com
                $this->login = isset($this->settings['login']) && !empty($this->settings['login']) ? $this->settings['login'] : 'pj-ql-01';
                $this->password = isset($this->settings['password']) && !empty($this->settings['password']) ? $this->settings['password'] : 'pj-ql-01p';
                $this->url = 'https://api.payjunctionlabs.com/transactions';
                $this->appkey = isset($this->settings['appkey']) && !empty($this->settings['appkey']) ? $this->settings['appkey'] : '81998712-17e5-4345-a7cb-374ad1757392';
                $this->view_transaction_url = 'https://www.payjunctionlabs.com/trinity/virtualterminal/transaction/view.action?txn.txnTransactionId=%s';
            }
            
            // See if we're doing Authorization Only
            if (isset($this->settings['auth_only']) && $this->settings['auth_only'] == 'yes') {
                $this->salemethod = 'HOLD';
            } else {
                $this->salemethod = 'CAPTURE';
            }
            
            // Test if Force SSL is enabled
            $this->force_ssl = get_option('woocommerce_force_ssl_checkout');
            
            if (defined('WOOCOMMERCE_VERSION') && version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
                add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );
            } else {
                add_action( 'woocommerce_update_options_payment_gateways', array( &$this, 'process_admin_options' ) );
            }
            
            add_action('woocommerce_receipt_payjunctionrest', array(&$this, 'receipt_page'));
            add_action('woocommerce_thankyou_payjunctionrest', array(&$this, 'thankyou_page'));

            // Enqueue tokenization scripts
            add_action('wp_enqueue_scripts', array(&$this, 'payment_scripts'));
        }
        
        function init_form_fields() {
            $this->form_fields = array(
                'module_title' => array(
                    'title' => 'PayJunction Trinity Gateway (REST)',
                    'type' => 'title'),
                'enabled' => array(
                    'title' => 'Enable',
                    'type' => 'checkbox',
                    'label' => 'Enable the PayJunction Trinity Gateway (REST)',
                    'default' => 'yes'),
                'testmode' => array(
                    'title' => 'Enable Test Mode',
                    'description' => 'Enable this mode to prevent live processing of credit cards for testing purposes',
                    'type' => 'checkbox',
                    'default' => 'no'),
                'debugging' => array(
                	'title' => 'Enable Debugging Mode',
                	'description' => 'Enabling this option causes extra information to be logged to the order notes in WooCommerce',
                	'type' => 'checkbox',
                	'default' => 'no'),
                'login' => array(
                    'title' => 'PayJunction API Login Name',
                    'description' => 'Please see our guide for help with obtaining this information',
                    'type' => 'text',
                    'default' => ''),
                'password' => array(
                    'title' => 'PayJunction API Password',
                    'description' => 'Please see our guide for help with obtaining this information',
                    'type' => 'text',
                    'default' => ''),
                'appkey' => array(
                    'title' => 'Application Key (X-PJ-Application-Key)',
                    'type' => 'text',
                    'description' => 'Application Key used for API requests.',
                    'default' => ''),
                'publishable_key' => array(
                    'title' => 'Publishable Key (JS Tokenization)',
                    'type' => 'text',
                    'description' => 'PayJunction Publishable Key used by the JavaScript tokenization SDK (optional). If blank, tokenization is disabled.',
                    'default' => ''),
                'auth_only' => array(
                    'title' => 'Authorize Only',
                    'description' => 'Authorize but do not capture transaction, i.e. process it as a Hold in PayJunction.',
    				'type' => 'checkbox',
    				'default' => 'no'),
				'cvvmode' => array(
				    'title' => 'Disable CVV Check',
				    'description' => 'Check this option to remove the card security code requirement from the checkout page.',
				    'type' => 'checkbox',
				    'default' => 'no'),
			    'uselocalavs' => array(
			        'title' => 'Use Local Address Verification Security Settings',
			        'description' => 'Enables the option to use the local AVS settings below instead of the options set directly in your PayJunction account.',
			        'type' => 'checkbox',
			        'default' => 'yes'),
			    'avsmode' => array(
			        'title' => 'Address Verification Security',
					'type' => 'select',
					'options' => array(
					    'ADDRESS_AND_ZIP' => 'Address AND Zip',
					    'ADDRESS_OR_ZIP' => 'Address OR Zip',
					    'BYPASS' => 'Bypass AVS',
					    'ZIP' => 'Zip Only',
					    'ADDRESS' => 'Street Address Only',
					    'OFF' => 'Disable AVS (Not Recommended)'),
				    'default' => 'ADDRESS_AND_ZIP'),
			    'dynamicavsmode' => array(
			        'title' => 'Dynamic AVS Mode',
    				'type' => 'checkbox',
    				'default' => 'no'),
				'fraudmsgenabled' => array(
					'title' => 'Enable Fraud Special Response',
					'description' => 'When enabled, send a special message instead of simply saying "Transaction Declined" to prevent multiple card authorizations.',
					'type' => 'checkbox',
					'default' => 'yes'),
				'fraudmsgtext' => array(
					'title' => 'Fraud Special Response Text',
					'description' => 'Customize the message given for fraud declines.',
					'type' => 'textarea',
					'default' => 'Payment error, before attempting to process again please contact us directly for assistance.'),
				'requestsignature' => array(
				    'title' => 'Email Signature Request',
				    'type' => 'checkbox',
				    'default' => 'yes'),
			    'signotificationemail' => array(
			    	'title' => 'Send Signed Receipt Notification to',
			    	'type' => 'text',
			    	'default' => 'no-reply@payjunction.com'),
                'title' => array(
                    'title' => 'Payment Option Title',
                    'type' => 'text',
                    'default' => 'Credit/Debit Payment'),
                'showdescription' => array(
                    'title' => 'Show Payment Option Description',
                    'type' => 'checkbox',
                    'default' => 'yes'),
                'description' => array(
                    'title' => 'Payment Option Description',
                    'type' => 'textarea',
                    'default' => 'Pay with your credit or debit card directly through the shopping cart'),
                'simpleamounts' => array(
                	'title' => 'Simple Amounts',
                	'description' => 'If enabled, only send the order total rather than detailed tax/shipping breakdown.',
                	'type' => 'checkbox',
                	'default' => 'no')
            );
        }
        
        public function admin_options() {
	    	echo '<h3>'.__( 'PayJunction', 'woothemes' ).'</h3>';
	    	echo '<p>'.__( 'PayJunction works by adding credit card fields on the checkout and then sending the details to PayJunction for verification. You can optionally enable JavaScript tokenization by supplying a Publishable Key.', 'woothemes' ).'</p>';
	    	echo '<table class="form-table">';
    		$this->generate_settings_html();
			echo '</table>';
			?>
			<script type="text/javascript">
			/*<! [CDATA[*/
			    jQuery(function($) {
			        jQuery('#woocommerce_payjunctionrest_password').attr('type', 'password');
    			    <?php
    			    $no_ssl = '<div class="error"><p>PayJunction is enabled and the <a href="/wp-admin/admin.php?page=wc-settings&tab=checkout&section=">force SSL option</a> is disabled; your checkout is not secure! Please enable SSL and ensure your server has a valid SSL certificate.';
    			    if ($this->force_ssl == 'no' && $this->enabled == true) {
        			    ?>
        			    jQuery('body').append('<?php echo $no_ssl ?>');
        			    <?php
    			    } 
    			    if (!function_exists('curl_version')) {
    			    	$no_curl = '<div class="error"><p>The cURL extension for PHP is not installed and transactions will not run!</p></div>';
    			    	?>
    			    	jQuery('body').append('<?php echo $no_curl ?>');
    			    	<?php
    			    } ?>
    			    
    			    // Add test button for API credentials
    			    var $apiTest = $('<button>Test Credentials</button>');
    			    $apiTest.click(function(event) {
    			       event.preventDefault();
    			       
    			       var login = $('#woocommerce_payjunctionrest_login').val();
    			       var pass = $('#woocommerce_payjunctionrest_password').val();
    			       
    			       var credentials = "login=" + encodeURIComponent(login) + "&pass=" + encodeURIComponent(pass);
    			       
    			       $.post('<?php echo plugins_url('credential-check.php', __FILE__) ?>', credentials, function(data) {
    			           var response;
    			           try {
    			               response = JSON.parse(data);
    			           } catch (err) {
    			               response = {'status': 'error', 'type': 'Invalid response', 'message': 'Could not parse the response from credential-check.php'};
    			           } finally {
        			           if (response['status'] === 'success') {
        			               alert("Success! Your API login and password are valid.");
        			           } else if (response['status'] === 'failure') {
        			               alert("Failure: the API login and password are not valid.");
        			           } else if (response['status'] === 'error') {
        			               alert("There was an error:\n" + response['type'] + ":\n" + response['message']);
        			           } else {
        			               alert("Could not check credentials due to an unknown error");
        			           }
    			           }
    			       });
    			    });
    			    $('#woocommerce_payjunctionrest_password').after($apiTest);
	            });
	            
	            /*]]>*/
            </script>
            <?php
        }
        
        /**
         * Enqueue PayJunction SDK and tokenization script when configured.
         */
        public function payment_scripts() {
            if ( ! is_checkout() && ! is_checkout_pay_page() ) {
                return;
            }

            if ( ! isset( $this->publishable_key ) || empty( $this->publishable_key ) ) {
                return; // tokenization not configured
            }

            // SDK url based on test/prod
            $sdk_url = $this->testmode
                ? 'https://www.payjunctionlabs.com/trinity/js/sdk.js'
                : 'https://www.payjunction.com/trinity/js/sdk.js';

            wp_enqueue_script(
                'payjunction-sdk',
                $sdk_url,
                array(),
                null,
                true
            );

            // Our tokenization helper
            wp_enqueue_script(
                'wc-payjunction-tokenization',
                plugins_url( 'assets/js/wc-payjunction-tokenization.js', __FILE__ ),
                array( 'jquery', 'payjunction-sdk' ),
                '1.0.0',
                true
            );

            wp_localize_script(
                'wc-payjunction-tokenization',
                'wcPayJunctionVars',
                array(
                    'publishableKey' => $this->publishable_key,
                    'gatewayId'      => $this->id,
                    'cvvmode'        => $this->cvvmode ? 'yes' : 'no'
                )
            );
        }
        
        function payment_fields() {
            if ($this->description && $this->show_description) {
                echo wpautop(wptexturize($this->description));
            }
            if ($this->testmode) echo '<span style="color:red;">The PayJunction module is currently in testing mode, the credit card will not actually be charged.</span>';
            ?>
            <fieldset>
                <p>
                    <label for="ccnum">
                        <?php echo __('Credit Card Number', 'woothemes') ?>
                        <span class='required'>*</span>
                    </label>
                    <input type='text' class='input-text' id='ccnum' name='ccnum' />
                </p>
                <p>
                    <label for='cc-expire-month'>
                        <?php echo __('Expiration Date', 'woothemes'); ?>
                        <span class='required'>*</span>
                    </label>
                    <select name='expmonth' id='expmonth'>
                        <option value=''><?php echo __('Month', 'woothemes'); ?></option>
                        <?php
                            $months = array();
                            for ($x = 1; $x <= 12; $x++) {
                                $timestamp = mktime(0, 0, 0, $x, 1);
                                $months[date('m', $timestamp)] = date('F', $timestamp);
                            }
                            foreach ($months as $num => $name) {
                                printf('<option value="%s">%s</option>', $num, $name);
                            }
                        ?>
                    </select>
                    <select name='expyear' id='expyear'>
                        <option value=''><?php echo __('Year', 'woothemes'); ?></option>
                        <?php
                            for ($x = date('Y'); $x <= date('Y') + 15; $x++) {
                                printf('<option value="%u">%u</option>', $x, $x);
                            }
                        ?>
                    </select>
                </p>
                <?php
                    if ($this->cvvmode) {
                        ?>
                        <p>
                            <label for='cvv'>
                                <?php echo __('Card Security Code (CVV)', 'woothemes'); ?>
                                <span class='required'>*</span>
                            </label>
                            <input type='text' class='input-text' id='cvv' name='cvv' maxlength='4' style='width:75px' />
                        </p>
                <?php   } ?>
                <!-- Hidden token field for JS tokenization; optional -->
                <input type="hidden" id="pj_token_id" name="pj_token_id" value="" />
            </fieldset>
            <?php
        }
        
        public function validate_fields() {
            // If token present, skip client-side validation (server will trust token flow)
            if (!empty($_POST['pj_token_id'])) {
                return true;
            }

            // Otherwise, validate posted PAN/CVV/expiry as before
            $cardNumber = isset($_POST['ccnum']) ? $_POST['ccnum'] : '';
            $cardCSC = isset($_POST['cvv']) ? $_POST['cvv'] : null;
            $cardExpirationMonth = isset($_POST['expmonth']) ? $_POST['expmonth'] : '';
            $cardExpirationYear = isset($_POST['expyear']) ? $_POST['expyear'] : '';
    
            if ($this->cvvmode) {
                //check security code
                if(!ctype_digit($cardCSC)) {
                    wc_add_notice(__('Card security code is invalid (only digits are allowed)', 'woothemes'));
                    return false;
                }
            }
    
            //check expiration data
            $currentYear = date('Y');
            
            if(!ctype_digit($cardExpirationMonth) || !ctype_digit($cardExpirationYear) ||
                 $cardExpirationMonth > 12 ||
                 $cardExpirationMonth < 1 ||
                 $cardExpirationYear < $currentYear ||
                 $cardExpirationYear > $currentYear + 20
            ) {
                wc_add_notice(__('Card expiration date is invalid', 'woothemes'));
                return false;
            }
    
            //check card number
            $cardNumber = str_replace(array(' ', '-'), '', $cardNumber);
    
            if(empty($cardNumber) || !ctype_digit($cardNumber)) {
                wc_add_notice(__('Card number is invalid', 'woothemes'), 'error');
                return false;
            }
            return true;
        }
        
        function is_fraud_decline($resp) {
            $fraud_declines = array("AA", "AI", "AN", "AU", "AW", "AX", "AY", "AZ", "CN", "CV");
            return in_array($resp, $fraud_declines);
        }
        
        function set_payjunction_hold($txnid) {
            $post = 'status=HOLD';
            $this->process_rest_request("PUT", $post, $txnid);
        }
        
        function process_rest_request($type, $post=null, $txnid=null, $order=null) {
            
            $url = !is_null($txnid) ? $this->url."/".$txnid : $this->url;
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json', 'X-PJ-Application-Key: ' . $this->appkey));
            curl_setopt($ch, CURLOPT_USERPWD, $this->login . ':' . $this->password);
            switch($type) {
                case "POST":
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
                    break;
                case "GET":
                    curl_setopt($ch, CURLOPT_HTTPGET, true);
                    break;
                case "PUT":
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
                    break;
            }
            
            $content = curl_exec($ch);
            $curl_errno = curl_errno($ch);
            $curl_error = curl_error($ch);
            curl_close($ch);
            
            if ($curl_errno) {
                if ($order) $order->add_order_note("Curl Error" . $curl_errno . " - " . $curl_error);
                $response = array("errors"=>array('message' => "cURL Error - $curl_errno: $curl_error", 'parameter' => 'cURL', 'type' => $curl_errno));
                return $response;
            }
            return json_decode($content, true);
        }
        
        function send_pj_email($order, $txnid) {
            if (isset($this->signotificationemail) && !empty($this->signotificationemail)) {
                $post = http_build_query(array('to' => $order->billing_email, 'replyTo' => $this->signotificationemail, 'requestSignature' => 'true'));
                $this->process_rest_request('POST', $post, $txnid.'/receipts/latest/email');
            } else {
                $order->add_order_note("Could not email signature request because there was no email address to send the notification to.");
            }
        }
        
        function receipt_page($order) {
            echo '<p>'.__('Thank you for your order.', 'woothemes').'</p>';
        }
        
        public function thankyou_page($order_id) {
            
        }
        
        function set_fraud_hold($order, $transactionId, $note) {
            global $woocommerce;
            $order->update_status('on-hold', $note);
            if ($this->salemethod != 'HOLD') { 
                $this->set_payjunction_hold($transactionId);
                $order->add_order_note(__("<strong>Don't forget to Capture or Void the transaction in PayJunction!<strong>", 'woothemes'));
            }
            $order->reduce_order_stock();
            $woocommerce->cart->empty_cart();
        }

        /**
         * process_payment - supports both tokenized (pj_token_id) and legacy card POST
         */
        function process_payment($order_id) {
            // If legacy card flow, validate posted fields (validate_fields does that)
            if (!$this->validate_fields()) return;

            global $woocommerce;
            $order = new WC_Order($order_id);

            if ($this->dbg) $order->add_order_note("Debugging enabled");

            // If tokenized request, use token path
            $token_id = isset($_POST['pj_token_id']) ? sanitize_text_field(wp_unslash($_POST['pj_token_id'])) : '';

            if (!empty($token_id)) {
                // Tokenized flow: send tokenId + amountBase + metadata
                $payjunction_request = array(
                    'tokenId' => $token_id,
                    'amountBase' => number_format((float)$order->get_total(), 2, ".", ""),
                    'status' => $this->salemethod,
                    'invoiceNumber' => $order->get_id(),
                    'billingFirstName' => $order->get_billing_first_name(),
                    'billingLastName' => $order->get_billing_last_name(),
                    'billingAddress' => $order->get_billing_address_1(),
                    'billingCity' => $order->get_billing_city(),
                    'billingState' => $order->get_billing_state(),
                    'billingZip' => $order->get_billing_postcode(),
                    'billingCountry' => $order->get_billing_country(),
                    'billingEmail' => $order->get_billing_email(),
                    'note' => "WooCommerce Order\n\nCustomer ID: ".$order->get_user_id()
                );

                $post = http_build_query($payjunction_request);

                if ($this->logging_enabled()) {
                    $this->log('PayJunction token request: ' . print_r($payjunction_request, true));
                }

                $content = $this->process_rest_request('POST', $post, null, $order);

            } else {
                // Legacy flow – continue to send PAN/CVV/etc as before
                $payjunction_request = array(
                    'amountBase' => number_format((float)$order->get_subtotal(), 2, ".", ""),
                    'amountShipping' => '',
                    'amountTax' => '',
                    'cardNumber' => isset($_POST['ccnum']) ? $_POST['ccnum'] : '',
                    'cardExpMonth' => isset($_POST['expmonth']) ? $_POST['expmonth'] : '',
                    'cardExpYear' => isset($_POST['expyear']) ? $_POST['expyear'] : '',
                    'status' => $this->salemethod,
                    'billingFirstName' => $order->get_billing_first_name(),
                    'billingLastName' => $order->get_billing_last_name(),
                    'billingAddress' => $order->get_billing_address_1(),
                    'billingCity' => $order->get_billing_city(),
                    'billingState' => $order->get_billing_state(),
                    'billingZip' => $order->get_billing_postcode(),
                    'billingCountry' => $order->get_billing_country(),
                    'billingEmail' => $order->get_billing_email(),
                    'shippingFirstName' => $order->get_shipping_first_name(),
                    'shippingLastName' => $order->get_shipping_last_name(),
                    'shippingAddress' => $order->get_shipping_address_1(),
                    'shippingCity' => $order->get_shipping_city(),
                    'shippingState' => $order->get_shipping_state(),
                    'shippingZip' => $order->get_shipping_postcode(),
                    'shippingCountry' => $order->get_shipping_country(),
                    'note' => "WooCommerce Order\n\nCustomer ID: ".$order->get_user_id(),
                    'invoiceNumber' => $order->get_id()
                );

                // Check if we're using local or remote AVS settings
                if ($this->localavs) {
                    $payjunction_request['avs'] = $this->avsmode;
                }

                if (!$this->simpleamounts) {
                    $total_amount = 0;
                    $total_amount += (float)$order->get_subtotal();
                    if ($order->get_total_shipping()) { // Add shipping amount
                        $payjunction_request['amountShipping'] = number_format((float)$order->get_total_shipping(), 2, ".", "");
                        $total_amount += (float)$order->get_total_shipping();
                    }

                    if ($order->get_cart_tax()) { // Add tax amount
                        $payjunction_request['amountTax'] = number_format((float)$order->get_cart_tax(), 2, ".", "");
                        $total_amount += (float)$order->get_cart_tax();
                    }

                    if ($order->get_shipping_tax()) { // Add shipping tax
                        $tax = (float)$order->get_cart_tax();
                        $s_tax = (float)$order->get_shipping_tax();
                        $total_tax = $tax + $s_tax;
                        $payjunction_request['amountTax'] = number_format((float)$total_tax, 2, ".", "");
                        $total_amount += (float)$order->get_shipping_tax();
                    }

                    if ($this->dbg) { 
                        $order->add_order_note("WC order total: " . $order->get_total() . ", WC subtotal: " . $order->get_subtotal()
                                                                    . ", WC shipping: " . $order->get_total_shipping() . ", WC shipping tax: "
                                                                    . $order->get_shipping_tax() . ", WC tax: " . $order->get_cart_tax());
                    }

                    if (number_format((float)$order->get_total(), 2, ".", "") != number_format((float)$total_amount, 2, ".", "")) {
                        $payjunction_request['amountTax'] = '';
                        $payjunction_request['amountShipping'] = '';
                        $payjunction_request['amountBase'] = number_format((float)$order->get_total(), 2, ".", "");
                        $payjunction_request['note'] .= "\nWooCommerce module was unable to determine the tax and shipping, processed as a total amount instead.";
                        $payjunction_request['note'] .= sprintf("\nOrder Total: %s\nComputed Total: %s", number_format((float)$order->get_total(), 2, ".", ""), number_format((float)$total_amount, 2, ".", ""));
                    }
                } else {
                    if ($this->dbg) { $order->add_order_note("WC order total: " . $order->get_total()); }
                    $payjunction_request['amountBase'] = number_format((float)$order->get_total(), 2, ".", "");
                }

                if ($this->dbg) {
                    $order->add_order_note("amountBase: " . $payjunction_request['amountBase']  . " amountShipping: " . $payjunction_request['amountShipping'] 
                                            . " amountTax: " . $payjunction_request['amountTax']);
                }

                if ($this->dynavsmode) {
                    $payjunction_request['avs'] = 'BYPASS';
                }

                if ($this->cvvmode) {
                    $payjunction_request['cvv'] = 'ON';
                    $payjunction_request['cardCvv'] = isset($_POST['cvv']) ? $_POST['cvv'] : '';
                } else {
                    $payjunction_request['cvv'] = 'OFF';
                }

                // Build the query string...
                $post = http_build_query($payjunction_request);

                $content = $this->process_rest_request('POST', $post, null, $order);
            }

            if ($this->dbg) $order->add_order_note(is_array($content) ? http_build_query($content) : print_r($content, true));

            if (isset($content['transactionId'])) { // Valid response
                $transactionId = $content['transactionId'];
                $order->add_order_note(__('PJ TransactionId: ' . $transactionId, 'woothemes'));
                $resp_code = isset($content['response']['code']) ? $content['response']['code'] : '';

                if (strcmp($resp_code, '00') == 0 || strcmp($resp_code, '85') == 0) {
                    // Successful Payment
                    $success_note = __('Credit Card/Debit Card payment completed', 'woothemes');
                    if ($this->salemethod == "HOLD") $order->add_order_note(__("<strong>Don't forget to Capture or Void the transaction in PayJunction!</strong>", 'woothemes'));
                    if ($this->dynavsmode) {
                        $address = isset($content['response']['processor']['avs']['match']['ADDRESS']) ? $content['response']['processor']['avs']['match']['ADDRESS'] : false;
                        $zip = isset($content['response']['processor']['avs']['match']['ZIP']) ? $content['response']['processor']['avs']['match']['ZIP'] : false;
                        $note = __(sprintf('Placed on Hold Status due to Address Match: %s and Zip Match: %s (Dynamic AVS)', $address == true ? 'true' : 'false', $zip == true ? 'true' : 'false'), 'woothemes');
                        $order->payment_complete($transactionId);
                        if ($this->avsmode == 'ADDRESS_AND_ZIP') {
                            if ($address && $zip) {
                                $order->add_order_note($success_note);
                                if (!empty($transactionId)) update_post_meta($order->get_id(), '_transaction_id', $transactionId);
                            } else {
                                $this->set_fraud_hold($order, $transactionId, $note);
                            }
                        } elseif ($this->avsmode == 'ADDRESS_OR_ZIP') {
                            if ($address || $zip) {
                                $order->add_order_note($success_note);
                                if (!empty($transactionId)) update_post_meta($order->get_id(), '_transaction_id', $transactionId);
                            } else {
                                $this->set_fraud_hold($order, $transactionId, $note);
                            }
                        } elseif ($this->avsmode == 'ADDRESS') {
                            if ($address) {
                                $order->add_order_note($success_note);
                                if (!empty($transactionId)) update_post_meta($order->get_id(), '_transaction_id', $transactionId);
                            } else {
                                $this->set_fraud_hold($order, $transactionId, __(sprintf('Placed on Hold Status due to Address Match: %s (Dynamic AVS)', $address == true ? 'true' : 'false'), 'woothemes'));
                            }
                        } elseif ($this->avsmode == 'ZIP') {
                            if ($zip) {
                                $order->add_order_note($success_note);
                                if (!empty($transactionId)) update_post_meta($order->get_id(), '_transaction_id', $transactionId);
                            } else {
                                $this->set_fraud_hold($order, $transactionId, __(sprintf('Placed on Hold Status due to Zip Match: %s (Dynamic AVS)', $zip == true ? 'true' : 'false'), 'woothemes'));
                            }
                        } else {
                            $order->add_order_note($success_note);
                            if (!empty($transactionId)) update_post_meta($order->get_id(), '_transaction_id', $transactionId);
                        }
                        if ($this->requestsignature) $this->send_pj_email($order, $transactionId);
                        return array('result' => 'success', 'redirect' => $this->get_return_url($order));
                    } else {
                        $order->add_order_note($success_note);
                        if (!empty($transactionId)) update_post_meta($order->get_id(), '_transaction_id', $transactionId);
                        $order->payment_complete($transactionId);

                        if ($this->requestsignature) $this->send_pj_email($order, $transactionId);
                        return array('result' => 'success', 'redirect' => $this->get_return_url($order));
                    }

                } else {
                    // Non-successful Payment
                    $cancelNote = __(sprintf('PayJunction payment failed (Code: %s, Message: %s).', $resp_code, isset($content['response']['message']) ? $content['response']['message'] : ''), 'woothemes');
                    add_post_meta($order->get_id(), '_transaction_id', $transactionId, true);
                    $order->add_order_note( $cancelNote );

                    if ($this->is_fraud_decline($resp_code) && $this->fraudmsgenabled) {
                        wc_add_notice($this->fraudmsgtext, 'error');
                        return;
                    } else {
                        wc_add_notice(__('Transaction Declined.', 'woothemes'), 'error');
                        return;
                    }
                }
            } else {
                $error = 'There was at least one unrecoverable error:';
                if (isset($content['errors'])) {
                    foreach ($content['errors'] as $err) {
                        $error .= sprintf('<br>%s',  $err['message']);
                    }
                    $order->add_order_note($error);
                    wc_add_notice($error, 'error');
                } else {
                    $order->add_order_note('No transactionId returned from PayJunction: ' . print_r($content, true));
                    wc_add_notice(__('Payment error: unable to process payment at this time.','woothemes'), 'error');
                }
            }
        }
        
        public function process_refund($order_id, $amount = null, $reason = '') {
            $order = wc_get_order($order_id);
            if (!$order) return false;
            $transactionId = $order->get_transaction_id();
            
            if (empty($transactionId)) {
                $order->add_order_note("Cannot refund from WooCommerce because the TransactionID is missing. Please refund manually by signing into your PayJunction account.");
                return false;
            }
            
            $refund_request = array('action' => 'REFUND', 'transactionId' => $transactionId);
            if (!is_null($amount) && $amount != 0) {
                $refund_request['amountBase'] = number_format((float)$amount, 2, ".", "");
                $refund_request['amountTax'] = "0.00";
            }
            
            if (!empty($reason)) {
                $refund_request['note'] = $reason;
            }
            
            $post = http_build_query($refund_request);
            $content = $this->process_rest_request('POST', $post);
            if ($this->dbg) $order->add_order_note(is_array($content) ? http_build_query($content) : print_r($content, true));
            if (isset($content['transactionId'])) { // Valid transaction
                if (!empty($content['response']['approved'])) {
                    return true;
                } else {
                    $order->add_order_note('Refund: ' . (isset($content['response']['message']) ? $content['response']['message'] : 'Refund not approved'));
                    return false;
                }
            } else {
                $error = ' Refund - There was at least one unrecoverable error:';
                if (isset($content['errors'])) {
                    foreach ($content['errors'] as $err) {
                        $error .= sprintf('<br>%s',  $err['message']);
                    }
                }
                $order->add_order_note($error);
                return false;
            }
        }

        private function logging_enabled() {
            return (isset($this->settings['debugging']) && $this->settings['debugging'] == 'yes');
        }

        private function log($message) {
            if ($this->logging_enabled()) {
                if ( function_exists( 'wc_get_logger' ) ) {
                    $logger = wc_get_logger();
                    $logger->info( '[payjunction] ' . $message, array( 'source' => 'payjunction' ) );
                }
            }
        }
    }
    
    function woocommerce_add_payjunction_rest_gateway($methods) {
        $methods[] = 'WC_PJ_Rest';
        return $methods;
    }
    
    add_filter('woocommerce_payment_gateways', 'woocommerce_add_payjunction_rest_gateway');
}
