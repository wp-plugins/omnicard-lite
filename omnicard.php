<?php
/*
Plugin Name: OmniCard Lite
Plugin URI: https://ajdg.solutions/?pk_campaign=omnicardlite-pluginpage
Author: Arnan de Gans from AJdG Solutions
Author URI: http://meandmymac.net?pk_campaign=omnicardlite-pluginpage
Description: Accept payments through iDeal and Bancontact/Mister Cash offered by Rabo Omnikassa in WooCommerce. This Lite version can accept up to 20 payments per month through iDeal and Mister Cash.
Text Domain: omnicard-lite
Domain Path: /
Version: 1.1
*/

register_activation_hook(__FILE__, 'omnicard_activate');
register_uninstall_hook(__FILE__, 'omnicard_uninstall');

add_action('plugins_loaded', 'omnicard_init');
add_action('admin_notices', 'omnicard_notifications_dashboard');
add_action("admin_print_styles", 'omnicard_dashboard_styles');

/*-------------------------------------------------------------
 Name:      omnicard_add_gateway

 Purpose:   Make WooCommerce aware of the gateway
 Receive:   $methods
 Return:    $methods
 Since:		1.0
-------------------------------------------------------------*/
function omnicard_add_gateway($methods) {
    $methods[] = 'WC_Gateway_OmniCard'; 
    return $methods;
}

/*-------------------------------------------------------------
 Name:      omnicard_init
-------------------------------------------------------------*/
function omnicard_init() {

	if(!class_exists('WC_Payment_Gateway')) return;

	add_filter('woocommerce_payment_gateways', 'omnicard_add_gateway');
	load_plugin_textdomain('omnicard-lite', false, basename(dirname(__FILE__)));

    class WC_Gateway_OmniCard extends WC_Payment_Gateway {
    
    	function __construct() {
			global $woocommerce;
	
	        $this->id = 'omnicardlite';
	        $this->has_fields = true;
	        $this->method_title = 'Omnikassa';

	        $this->liveurl = 'https://payment-webinit.omnikassa.rabobank.nl/paymentServlet';
			$this->testurl = 'https://payment-webinit.simu.omnikassa.rabobank.nl/paymentServlet';
	        $this->testmerchant = '002020000000001';
			$this->testkey = '002020000000001_KEY1';
			$this->keyversiondemo = '1';
			$this->interface_version = 'HP_1.0';

			$this->uploadloc = wp_upload_dir();
			$this->pluginloc = plugins_url();

			// Load the form fields.
			$this->init_form_fields();
	
			// Load the settings.
			$this->init_settings();

			// Define user set variables
			$this->title = !empty($this->settings['title']) ? $this->settings['title'] : 'iDEAL or Bancontact/Mister Cash';
			$this->description = !empty($this->settings['description']) ? $this->settings['description'] : 'Pay securely with iDeal or bancontact/Mister Cash through Rabo Omnikassa.';

			$this->merchantid = $this->settings['merchantid'];
			$this->secretkey = $this->settings['secretkey'];
			$this->keyversion = $this->settings['keyversion'];
			
	        $this->checkout_icon = $this->settings['checkout_icon'];
			$this->method_ideal	= $this->settings['method_ideal'];
			$this->method_bcmc = $this->settings['method_bcmc'];
			$this->method_default = $this->settings['method_default'];

			$this->remote_language = !empty($this->settings['remote_language']) ? $this->settings['remote_language'] : 'en';
			$this->remote_session = !empty($this->settings['remote_session']) ? $this->settings['remote_session'] : '7200';
			$this->invoice_prefix = !empty($this->settings['invoice_prefix']) ? $this->settings['invoice_prefix'] = preg_replace("/[^a-z0-9.]+/i", "", $this->settings['invoice_prefix']) : '';

			$this->testmode = $this->settings['testmode'];
			$this->debugger	= $this->settings['debug'];

			// Logs
			if($this->debugger=='yes') $this->log = new WC_Logger();

			// Actions
			add_action('valid-omnicard-psp-request', array(&$this, 'successful_request'));
			add_action('woocommerce_receipt_' . $this->id, array(&$this, 'receipt_page'));
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
			add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'check_psp_response'));
			add_filter('woocommerce_gateway_icon', array(&$this, 'iconset'), 10, 2);
			
			$this->transactions = get_option('omnicard_count');
			if($this->transactions['month'] < mktime(0, 0, 0, date("m"), 1, date("Y"))) update_option('omnicard_count', array('count' => 0, 'month' => mktime(0, 0, 0, date("m"), 1, date("Y")))); 
			if($this->transactions['count'] >= 20) $this->enabled = false;
			if(!$this->currency_is_valid()) $this->enabled = false;
    	}

		/*-------------------------------------------------------------
		 Name:      iconset
		
		 Purpose:   Find out and format the markup for payment icons
		 Receive:   $icon, $id
		 Return:    $icon
		 Since:		1.0
		-------------------------------------------------------------*/
		public function iconset($icons = '', $id) {
			if($id == $this->id && $this->checkout_icon == 'yes') {
				$icon = array();
				if($this->settings['method_ideal'] == 'yes') $icon[] = $this->icon_image('ideal');
				if($this->settings['method_bcmc'] == 'yes') $icon[] = $this->icon_image('mistercash');
				$icons = implode(" ", $icon);
			}

			return $icons;
		}

		/*-------------------------------------------------------------
		 Name:      icon_image
		
		 Purpose:   Figure out which PNG file to use as checkout icon
		 Receive:   $brand
		 Return:    $icon
		 Since:		1.1
		-------------------------------------------------------------*/
		private function icon_image($brand) {
		
			if(file_exists($this->uploadloc['basedir'].'/omnicard-'.$brand.'.png')) {
				$icon = '<img src="'.$this->uploadloc['baseurl'].'/omnicard-'.$brand.'.png" alt="'.$brand.'" />';
			} else {
				$icon = '<img src="'.$this->pluginloc.'/omnicard-lite/images/'.$brand.'.png" alt="'.$brand.'" />';
			}
			
			return $icon;
		}

		/*-------------------------------------------------------------
		 Name:      admin_options
		
		 Purpose:   Create and output admin settings
		 Receive:   - None -
		 Return:    - None -
		 Since:		1.0
		-------------------------------------------------------------*/
		public function admin_options() {
	
			$status = '';
			if(isset($_GET['status'])) $status = $_GET['status'];
		
	    	?>
	    	<h2>OmniCard Lite <?php _e('for', 'omnicard-lite'); ?> Rabo Omnikassa</h2>

			<?php if(get_woocommerce_currency() != "EUR") {
				echo '<div class="error"><p>'. __('iDEAL and Bancontact/Mister Cash can only accept payments in Euros. Your store uses: ', 'omnicard-lite') . get_woocommerce_currency() . '.</p></div>';
			}
			?>
			
	    	<p><?php _e("Accept iDEAL and Bancontact/Mister Cash payments through the Rabobank Omnikassa Gateway.", 'omnicard-lite'); ?><br />
	    	<?php _e("This plugin requires an activated Omnikassa Agreement with the Rabobank. Fees for usage apply!", 'omnicard-lite'); ?> 
	    	<?php _e("Visit the", 'omnicard-lite'); ?> <a href="https://www.rabobank.nl/omnikassa/" target="_blank">Rabo Omnikassa</a> <?php _e("website for more information.", 'omnicard-lite'); ?></p>
	    	<?php _e("Once you've used up your 20 transactions, the gateway will temporary disable until a new month starts.", 'omnicard-lite'); ?></p>

			<?php
   			$this->generate_settings_html();

	    	omnicard_credits();
	    }

		/*-------------------------------------------------------------
		 Name:      init_form_fields
		
		 Purpose:   Generate form field for the admin settings
		 Receive:   - None -
		 Return:    - None -
		 Since:		1.0
		-------------------------------------------------------------*/
	    public function init_form_fields() {
	    	$this->form_fields = array(
	    		// BASIC SETTINGS
				'basic' => array(
					'title' => __('Basic Settings', 'omnicard-lite'),
					'type' => 'title'
				),
				'enabled' => array(
					'title' => __('Enable/Disable', 'omnicard-lite'),
					'type' => 'checkbox',
					'label' => __('Enable the Rabo Omnikassa Gateway', 'omnicard-lite'),
					'default' => 'no'
				),
				'title' => array(
					'title' => __('Title', 'omnicard-lite'),
					'type' => 'text',
					'description' => __('The title which the user sees during checkout; Make sure it matches your payment methods.', 'omnicard-lite'),
					'default' => 'iDEAL or Bancontact/Mister Cash',
					'css' => 'width:400px;'
				),
				'description' => array(
					'title' => __('Description', 'omnicard-lite'),
					'type' => 'textarea',
					'description' => __('The description which the user sees during checkout; Make sure it matches your payment methods.', 'omnicard-lite'),
					'default' => 'Pay securely with iDeal through Rabo Omnikassa.',
					'css' => 'width:400px;'
				),
				'checkout_icon' => array(
					'title' => __('Icon', 'omnicard-lite'),
					'type' => 'checkbox',
					'label' => __('Show payment icons in the gateway selection box', 'omnicard-lite'),
					'description' => __('Place your own logo sized approximately 32*32px in <code>wp-content/uploads/</code> named <code>omnicard-ideal.png</code>.', 'omnicard-lite'),
					'default' => 'yes'
				),

				// MERCHANT DETAILS
				'merchant' => array(
					'title' => __('Merchant Details', 'omnicard-lite'),
					'type' => 'title',
					'description' => __('You can find your merchant details in 2 separate emails sent to you by the Rabobank after you signed your agreement.', 'omnicard-lite')
				),
				'merchantid' => array(
					'title' => __('Merchant ID', 'omnicard-lite'),
					'type' => 'text',
					'description' => __('This is needed in order to take payment.', 'omnicard-lite'),
					'default' => '',
					'css' => 'width:400px;'
				),
				'secretkey' => array(
					'title' => __('Secret Key', 'omnicard-lite'),
					'type' => 'text',
					'description' => __('This is needed in order to take payment.', 'omnicard-lite'),
					'default' => '',
					'css' => 'width:400px;'
				),
				'keyversion' => array(
					'title' => __('Key Version', 'omnicard-lite'),
					'type' => 'text',
					'description' => __('This is needed in order to take payment.', 'omnicard-lite'),
					'default' => '',
					'css' => 'width:400px;'
				),

				// PAYMENT METHODS
				'paymentmethods' => array(
					'title' => __('Accepted Payment Methods', 'omnicard-lite'),
					'type' => 'title',
					'description' => __('Each payment method requires approval from your bank and possibly an extension on your agreement with the Rabobank. Check with the Rabobank for details before activating new payment methods!', 'omnicard-lite')
				),
				'method_ideal' => array(
					'title' => __('iDEAL', 'omnicard-lite'),
					'type' => 'checkbox',
					'label' => __('Receive iDEAL payments', 'omnicard-lite'),
					'description' => __('Instant bank transfer. Available to Dutch ATM Card holders.', 'omnicard-lite'),
					'default' => 'yes'
				),
				'method_bcmc' => array(
					'title' => __('Bancontact/Mister Cash', 'omnicard-lite'),
					'type' => 'checkbox',
					'label' => __('Receive Bancontact/Mister Cash payments', 'omnicard-lite'),
					'description' => __('Instant bank transfer. Available to Belgian ATM Card holders.', 'omnicard-lite'),
					'default' => 'no'
				),
				'method_default' => array(
				     'title' => __('Default payment option', 'omnicard-lite'),
				     'description' => __('Default selected option on checkout. The chosen option here must be active in your contract or no selection can be made! (Only applicable if you use multiple payment methods.)', 'omnicard-lite'),
				     'type' => 'select',
				     'options' => array(
				          'ideal' => 'iDeal',
				          'bcmc' => 'Mister Cash'
				     )
				),


				// MISC SETTINGS
				'misc_settings' => array(
					'title' => __('Miscellaneous Settings', 'omnicard-lite'),
					'type' => 'title'
				),
				'remote_language' => array(
					'title' => __('Language', 'omnicard-lite'),
					'type' => 'select',
					'description' => __('Which language should the Rabobank Omnikassa Pages use for checkout.', 'omnicard-lite'),
					'default' => 'en',
					'options' => array(
						'en' => __('English', 'omnicard-lite'),
						'nl' => __('Dutch', 'omnicard-lite')
					)
				),
				'remote_session' => array(
					'title' => __('Expiration', 'omnicard-lite'),
					'type' => 'select',
					'description' => __('For added security you can add a timeout to the transaction. The transaction must be completed within this timeframe or the request will expire and has to be started over again.', 'omnicard-lite'),
					'default' => '7200',
					'options' => array(
						'0' => __('Disable', 'omnicard-lite'),
						'7200' => __('2 hours (Default)', 'omnicard-lite'),
						'14400' => __('4 hours', 'omnicard-lite'),
						'21600' => __('6 hours', 'omnicard-lite'),
						'28800' => __('8 hours', 'omnicard-lite'),
						'57600' => __('16 hours', 'omnicard-lite'),
						'86400' => __('24 hours', 'omnicard-lite')
					)

				),
				'invoice_prefix' => array(
					'title' => __('Invoice Prefix', 'omnicard-lite'),
					'type' => 'text',
					'description' => __('Enter a prefix for your order numbers. Only alphanumeric characters allowed. If you use your Omnikassa account for multiple stores ensure this prefix is unqiue to avoid confusion.', 'omnicard-lite') . '<br />' . __('This prefix is NOT added to WooCommerce orders but is shown on your bank statements as part of the transaction reference.', 'omnicard-lite'),
					'default' => 'wc'
				),

				// TESTING AND DEBUGGING
				'testing' => array(
					'title' => __('Gateway Testing', 'omnicard-lite'),
					'type' => 'title'
				),
				'testmode' => array(
					'title' => __('Simulator', 'omnicard-lite'),
					'type' => 'checkbox',
					'label' => __('Enable the Omnikassa test mode', 'omnicard-lite'),
					'default' => 'yes',
					'description' => __('You can leave your "live" account details in place.', 'omnicard-lite'),
				),
				'debug' => array(
					'title' => __('Troubleshooting', 'omnicard-lite'),
					'type' => 'checkbox',
					'label' => __('Enable logging', 'omnicard-lite'),
					'default' => 'no',
					'description' => __('Log Omnikassa events, such as PSP requests and status updates, inside <code>wc-logs/omnicard-*.log</code>.', 'omnicard-lite'),
				)
			);
	    }

		/*-------------------------------------------------------------
		 Name:      order_args
		
		 Purpose:   Build data to send to Payment Processor
		 Receive:   $order, $brand
		 Return:    $omni_args
		 Since:		1.0
		-------------------------------------------------------------*/
		protected function order_args($order_id, $brand) {
	
			$order = new WC_Order($order_id);
	
			if($this->debugger=='yes') $this->log->add('omnicard', 'Generating payment data for order #' . $order_id . '.');

			if($this->testmode == 'yes') {
				$merchantID = $this->testmerchant;
				$keyVersion = $this->keyversiondemo;
			} else {
				$merchantID = $this->merchantid;
				$keyVersion = $this->keyversion;
			}

			// Define Payment Methods
			$brandList = array();
			if($this->method_ideal == "yes" && ($brand == 1 || $brand == 0)) $brandList[] = 'IDEAL';
			$brands = implode(',', $brandList);

			// Omnikassa Args
			$omni_args = array();
			if(in_array('IDEAL', $brandList) || in_array('BCMC', $brandList) || in_array('MINITIX', $brandList)) {
				$omni_args['currencyCode'] = $this->supported_currencies('EUR');
			} else {
				$omni_args['currencyCode'] = $this->supported_currencies(get_woocommerce_currency());
			}
			$omni_args['amount'] = number_format($order->get_total(), 2, '', '');
			$omni_args['merchantId'] = substr($merchantID, 0, 15);
			$omni_args['orderId'] = substr($order_id, 0, 32);
			$omni_args['transactionReference'] = substr(uniqid($this->invoice_prefix), 0, 35);
			$omni_args['keyVersion'] = substr($keyVersion, 0, 10);
			$omni_args['customerLanguage'] = substr($this->remote_language, 0, 2);
			$omni_args['paymentMeanBrandList'] = $brands;
			$omni_args['normalReturnUrl'] = substr($this->get_return_url($order), 0, 512);
			$omni_args['automaticResponseUrl'] = substr(home_url('/') . '?wc-api=WC_Gateway_OmniCard&omnicardListener=omnicard_PSP', 0, 512);

			if($this->remote_session > 0) {
				$omni_args['expirationDate'] = substr(date_i18n("c", current_time('timestamp') + $this->remote_session), 0, 25);
			}

			// Store Transaction reference
			update_post_meta($order_id, 'TransactionReference', $omni_args['transactionReference']);
			
			if($this->debugger == 'yes') $this->log->add('omnicard', 'Generated payment data for order #' . $order_id . '. - ' . print_r($omni_args, true));

			unset($merchantID, $keyVersion, $brandList, $brands, $order, $brand);
			return $omni_args;
		}

		/*-------------------------------------------------------------
		 Name:      payment_fields
		
		 Purpose:   List available payment methods on checkout
		 Receive:   - None -
		 Return:    - None -
		 Since:		1.1
		-------------------------------------------------------------*/
	    public function payment_fields() {
			$output = '';
			if($this->description) $output .= '<p>' . $this->description . '</p>';

			$active = 0;
			if($this->settings['method_ideal'] == 'yes') {
				$active++;
				$method = 1;
			}
			if($this->settings['method_bcmc'] == 'yes') {
				$active++;
				$method = 5;
			}
			
			if($active > 1) {
				$output .= '<p>';
				if($this->settings['method_ideal'] == 'yes') {
					$output .= '<input type="radio" id="ideal" name="omnicard_submethod" value="1"';
					if($this->method_default == 'ideal') $output .= ' checked="checked"';
					$output .= ' /> <label for="ideal">' . __('iDeal', 'omnicard-lite') . '</label><br />';
				}
				if($this->settings['method_bcmc'] == 'yes') {
					$output .= '<input type="radio" id="bcmc" name="omnicard_submethod" value="5"';
					if($this->method_default == 'bcmc') $output .= ' checked="checked"';
					$output .= ' /> <label for="bcmc">' . __('Bancontact/Mister Cash', 'omnicard-lite') . '</label><br />';
				}
				$output .= '</p>';
			} else {
				$output .= '<input type="hidden" name="omnicard_submethod" value="'. $method .'" />';
			}

			echo $output;
			unset($transactions, $active, $method, $output);
		}

		/*-------------------------------------------------------------
		 Name:      process_payment
		
		 Purpose:   Initiate the payment process.
		 Receive:   $order_id
		 Return:    array
		 Since:		1.0
		-------------------------------------------------------------*/
		public function process_payment($order_id) {
		
			$order = new WC_Order($order_id);
			
			if(isset($_POST['omnicard_submethod']) && is_numeric($_POST['omnicard_submethod'])) {
 				update_post_meta($order_id, 'Payment brand', $_POST['omnicard_submethod']);
 			} else {
 				update_post_meta($order_id, 'Payment brand', 0);
			}
		
			return array(
				'result' 	=> 'success',
				'redirect'	=> $order->get_checkout_payment_url(true)
			);
		}

		/*-------------------------------------------------------------
		 Name:      receipt_page
		
		 Purpose:   Output for the order received page
		 Receive:   $order
		 Return:    String
		 Since:		1.0
		-------------------------------------------------------------*/
		public function receipt_page($order_id) {
	
			if($this->testmode == 'yes') {
				$omni_address = $this->testurl . '?';
				$secretKey = $this->testkey;
			} else {
				$omni_address = $this->liveurl . '?';
				$secretKey = $this->secretkey;
			}
	
			$submethod = get_post_meta($order_id, 'Payment Submethod', 1);
			if(!is_numeric($submethod) && ($submethod < 1 || $submethod > 6)) {
				$submethod = 0;
			}

			$omniArgs = $this->order_args($order_id, $submethod);
			$omniData = '';
			foreach($omniArgs as $k => $v) {
				$v = str_replace('|', '', $v);
				$omniData .= (empty($omniData) ? '' : '|') . ($k . '=' . $v);
			}

			$omniSeal = hash('sha256', utf8_encode($omniData . $secretKey));;

			if($this->debugger == 'yes') $this->log->add('omnicard', 'Redirecting to PSP gateway');

			wc_enqueue_js('
				jQuery.blockUI({
					message: "' . esc_js(__('Thank you for your order. We are now redirecting you to Rabobank OmniKassa to make payment.', 'omnicard-lite')) . '",
					baseZ: 99999,
					overlayCSS:	{ background: "#000", opacity: 0.6 },
					css: { padding: "20px", zindex: "9999", textAlign: "center", color: "#555", border: "3px solid #aaa", backgroundColor: "#fff", cursor: "wait", lineHeight: "32px" }
				});
				jQuery("#submit_omnicard_payment_form").click();
			');
	
			echo '<h3>'.__('Please click the button below to complete your purchase.', 'omnicard-lite').'</h3>';
			echo '<form action="'.esc_url($omni_address).'" method="post" id="omnicard_payment_form" target="_top">';
			echo '<input type="hidden" name="InterfaceVersion" value="'.esc_attr(htmlspecialchars($this->interface_version)).'" />';
			echo '<input type="hidden" name="Data" value="'.esc_attr(htmlspecialchars($omniData)).'" />';
			echo '<input type="hidden" name="Seal" value="'.esc_attr(htmlspecialchars($omniSeal)).'" />';
			echo '<input type="submit" class="button-alt" id="submit_omnicard_payment_form" value="'.__('Continue', 'omnicard-lite').'" />';
			echo '</form>';
			unset($secretKey, $omniData, $omniSeal, $omniArgs, $order_id);
		}

		/*-------------------------------------------------------------
		 Name:      supported_currencies
		
		 Purpose:   List of supported currencies. Assume EUR if currency not listed.
		 Receive:   $currency
		 Return:    Int
		 Since:		1.0
		-------------------------------------------------------------*/
		protected function supported_currencies($currency) {
			// Extracted from http://www.currency-iso.org/dl_iso_table_a1.xml
			$currencies = array('AUD' => '036', 'CAD' => '124', 'DKK' => '208', 'EUR' => '978', 'GBP' => '826', 'NOK' => '578', 'SEK' => '752', 'USD' => '840', 'CHF' => '756');

			if(isset($currencies[$currency])) return $currencies[$currency];
			return 978;
		}

		/*-------------------------------------------------------------
		 Name:      currency_is_valid
		
		 Purpose:   Make sure the shop uses Euros for currency
		 Receive:   - None -
		 Return:    Boolean
		 Since:		1.0
		-------------------------------------------------------------*/
	    private function currency_is_valid() {
	        if(get_woocommerce_currency() != 'EUR') {
	        	return false;
	        } else {
		        return true;
			}
	    }

		/*-------------------------------------------------------------
		 Name:      transaction_status
		
		 Purpose:   Status returns on transactions
		 Receive:   $code, $type
		 Return:    String
		 Since:		1.0
		-------------------------------------------------------------*/
		protected function transaction_status($code, $type) {
			if(in_array($code, array('00'))) {
				return 'SUCCESS';
			} elseif(in_array($code, array('60', '89', '90', '99', '05', '02', '14'))) {
				return 'PENDING';
			} elseif(in_array($code, array('97'))) {
				return 'EXPIRED';
			} elseif(in_array($code, array('17'))) {
				return 'CANCELLED';
			} elseif($type == "CARD" && in_array($code, array('03'))) {
				return 'NOCONTRACT';
			}
			return 'FAILED';
		}

		/*-------------------------------------------------------------
		 Name:      transaction_status_code
		
		 Purpose:   Status codes for failed transactions set to pending
		 Receive:   $response
		 Return:    String
		 Since:		1.0.2
		-------------------------------------------------------------*/
		protected function transaction_status_code($response) {
		
			$status = array(
				'02' => __('Creditcard authorizationlimit exceeded.', 'omnicard-lite'),
				'05' => __('Card declined; Insufficient funds, expired or not active.', 'omnicard-lite'),
				'14' => __('Invalid Card or Authorization failed. Check your card details.', 'omnicard-lite'),
				'17' => __('Transaction cancelled by user.', 'omnicard-lite'),
				'60' => __('Awaiting response from gateway. If you place another order you may be billed more than once.', 'omnicard-lite'),
				'75' => __('You have exceeded the maximum of 3 attempts to enter your card number. Transaction aborted.', 'omnicard-lite'),
				'89' => __('CVC/Security code invalid or empty. Please try again.', 'omnicard-lite'),
				'90' => __('Unable to reach payment gateway.', 'omnicard-lite'),
				'97' => __('Exceeded time limit. Transaction aborted. Please try again.', 'omnicard-lite'),
				'99' => __('Payment gateway unavailable.', 'omnicard-lite'),
			);

			return $status[$response];
		}

		/*-------------------------------------------------------------
		 Name:      check_psp_request_is_valid
		
		 Purpose:   Validate PSP response
		 Receive:   - None -
		 Return:    Array/Boolean
		 Since:		1.0
		-------------------------------------------------------------*/
		protected function check_psp_request_is_valid() {
			global $woocommerce;
	
			if($this->debugger == 'yes') $this->log->add('omnicard', 'Checking if PSP response is valid');
	
			$received_values = (array) stripslashes_deep($_POST);
	        $params = array(
	        	'body' 			=> 'Hello',
	        	'sslverify' 	=> true,
	        	'timeout' 		=> 15,
	        	'user-agent'	=> 'Omnicard/AJdG_Solutions'
	        );
	
			if($this->testmode == 'yes') {
				$omni_address = $this->testurl;
				$secretKey = $this->testkey;
			} else {
				$omni_address = $this->liveurl;
				$secretKey = $this->secretkey;
			}
	
	        $response = wp_remote_post($omni_address, $params);

	        if($this->debugger == 'yes') $this->log->add('omnicard', 'PSP Response Code: ' . $response['response']['code']);

	        // check to see if the request was valid
	        if(!is_wp_error($response) && $response['response']['code'] >= 200 && $response['response']['code'] < 300) {
	        	if(!empty($received_values['Data']) && !empty($received_values['Seal'])) {		        
					if(strcmp($received_values['Seal'], hash('sha256', utf8_encode($received_values['Data'] . $secretKey))) === 0) {
						$a = explode('|', $received_values['Data']);
	
						$omni_args = array();
						foreach($a as $d) {
							list($k, $v) = explode('=', $d);
							$omni_args[$k] = $v;
						}

						if($this->debugger == 'yes') $this->log->add('omnicard', 'Received data verified and valid. - ' . print_r($omni_args, true));

						unset($received_values, $params, $omni_address, $secretKey, $response, $a);
						return array(
							'transaction_reference' => $omni_args['transactionReference'], 
							'transaction_status' => $this->transaction_status($omni_args['responseCode'], $omni_args['paymentMeanType']),
							'transaction_code' => $omni_args['responseCode'],
							'transaction_id' => (empty($omni_args['authorisationId']) ? '' : $omni_args['authorisationId']),
							'transaction_type' => (empty($omni_args['paymentMeanType']) ? 'Omnikassa' : $omni_args['paymentMeanType']), 
							'transaction_brand' => (empty($omni_args['paymentMeanBrand']) ? 'Rabobank' : $omni_args['paymentMeanBrand']), 
							'order_id' => $omni_args['orderId'],
							'amount' => $omni_args['amount']
						);
					} else {
		        		$this->log->add('omnicard', 'Hash data mismatch! Check Secret Key!');
       			        return false;
					}
				}
	        }
	
	        if($this->debugger == 'yes') {
	        	$this->log->add('omnicard', 'Received invalid response from PSP');
	        	if(is_wp_error($response)) $this->log->add('omnicard', 'Error response: ' . $response->get_error_message());
	        }
	
	        return false;
	    }
	
	
		/*-------------------------------------------------------------
		 Name:      check_psp_response
		
		 Purpose:   Validate PSP response
		 Receive:   - None -
		 Return:    - None -
		 Since:		1.0
		-------------------------------------------------------------*/
		public function check_psp_response() {
	
			if (isset($_GET['omnicardListener']) && $_GET['omnicardListener'] == 'omnicard_PSP') {
				@ob_clean();

	        	$omniResponse = $this->check_psp_request_is_valid();
	        	if($omniResponse && is_array($omniResponse)) {
	        		header('HTTP/1.1 200 OK');
	            	do_action("valid-omnicard-psp-request", $omniResponse);
				} else {
	        		if($this->debugger == 'yes') $this->log->add('omnicard', 'OmniCard PSP Request Failure');
					wp_die("OmniCard PSP Request Failure");
	       		}
	       	}	
		}

		/*-------------------------------------------------------------
		 Name:      successful_request
		
		 Purpose:   Process response from PSP and update order accordingly
		 Receive:   - None -
		 Return:    - None -
		 Since:		1.0
		-------------------------------------------------------------*/
		public function successful_request($omniResponse) {
			global $woocommerce;

			if($omniResponse && is_array($omniResponse)) {

				$omniReference = $omniResponse['transaction_reference'];
				$omniStatus = $omniResponse['transaction_status'];
				$omniStatusCode = $omniResponse['transaction_code'];
				$omniID = $omniResponse['transaction_id'];
				$omniType = $omniResponse['transaction_type'];
				$omniBrand = $omniResponse['transaction_brand'];
				$omniOrderID = $omniResponse['order_id'];
				$omniAmount = $omniResponse['amount'];

				$order = new WC_Order($omniOrderID);
				$order_id = $order->id;					

				if(strcmp($omniStatus, 'SUCCESS') === 0) {

	            	if($order->status == 'completed') { // Check order not already completed
	            		 if($this->debugger == 'yes') $this->log->add('omnicard', 'Aborting, Order #' . $omniOrderID . ' is already complete.');
	            		 wp_redirect(add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, esc_url($order->get_checkout_payment_url()))));
	            		 exit;
	            	}

					$localReference = get_post_meta($order_id, 'TransactionReference', 1);
	            	if($localReference != $omniReference) { // Check order ID
	            		if($this->debugger == 'yes') $this->log->add('omnicard', 'ID Mismatch: Order #' . $localReference . ' stored.  PSP provided: ' . $omniReference);
						$order->update_status('on-hold', sprintf(__('Issue with reference code. Should be: %s. PSP provided: %s.', 'omnicard-lite'), $localReference, $omniReference));
	            		wp_redirect(add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, esc_url($order->get_checkout_payment_url()))));
						exit;
	            	}

	            	// Check valid type and brand
	            	$accepted_types = array('CREDIT_TRANSFER', 'CARD', 'OTHER', 'Omnikassa');
	            	$accepted_brands = array('IDEAL', 'VISA', 'MASTERCARD', 'MAESTRO', 'MINITIX', 'Rabobank');
					if(!in_array($omniType, $accepted_types) || !in_array($omniBrand, $accepted_brands)) {
						if($this->debugger == 'yes') $this->log->add('omnicard', 'Aborting, Invalid type or brand. Response from PSP - Type :' . $omniType . ', Brand: ' . $omniBrand . '.');
				    	$order->update_status('on-hold', sprintf(__('Issue with payment type or brand. Response from PSP - Type: %s, Brand: %s.', 'omnicard-lite'), $omniType, $omniBrand));
	            		wp_redirect(add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, esc_url($order->get_checkout_payment_url()))));
						exit;
					}
					
					// Validate Amount
				    if(number_format($order->get_total(), 2, '', '') != $omniAmount) {				    
				    	$omniAmountDec = number_format($omniAmount, get_option('woocommerce_price_num_decimals'), get_option('woocommerce_price_decimal_sep'), get_option('woocommerce_price_thousand_sep'));
				    	if($this->debugger == 'yes') $this->log->add('omnicard', 'Payment error: Amounts do not match (gross ' . $omniAmountDec . ').');
				    	$order->update_status('on-hold', sprintf(__('Validation error: PSP amounts do not match with original order (gross %s).', 'omnicard-lite'), $omniAmountDec));
	            		wp_redirect(add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, esc_url($order->get_checkout_payment_url()))));
				    	exit;
				    }

	            	// Payment completed
	                $order->add_order_note(__('PSP payment completed', 'omnicard-lite'));
	                $order->payment_complete();

					// Transaction limit
               		$transactions = get_option('omnicard_count');
               		$new = $transactions['count']+1;
               		update_option('omnicard_count', array('count' => $new, 'month' => $transactions['month']));

	                if($this->debugger=='yes') $this->log->add('omnicard', 'Transaction '.$new.' out of 20.');
	                if($this->debugger=='yes') $this->log->add('omnicard', 'Payment complete.');

				} elseif(strcmp($omniStatus, 'PENDING') === 0) {

	                if($this->debugger=='yes') $this->log->add('omnicard', 'pending: Response from PSP: '.$omniStatusCode.'.');
					$order->add_order_note(__('Order set to PENDING. You can try again from your order through', 'omnicard-lite').' <a href="'.esc_url($order->get_checkout_payment_url()).'">'.__('Checkout', 'omnicard-lite') . '</a>. ' . sprintf(__('Error: %s.', 'omnicard-lite'), $omniStatusCode.' - '.$this->transaction_status_code($omniStatusCode)), 1);
	                $order->update_status('pending', sprintf(__('Awaiting %s payment. Order status changed to pending. Response from PSP: %s.', 'omnicard-lite'), $omniType, $omniStatusCode));

				} elseif(strcmp($omniStatus, 'CANCELLED') === 0) {

	                if($this->debugger=='yes') $this->log->add('omnicard', 'Cancelled: Response from PSP: '.$omniStatusCode.'.');
	                $order->update_status('cancelled', __('Cancelled by user.', 'omnicard-lite'));
					unset($woocommerce->session->order_awaiting_payment);

				} elseif(strcmp($omniStatus, 'EXPIRED') === 0) {

	                if($this->debugger=='yes') $this->log->add('omnicard', 'Expired: Response from PSP: '.$omniStatusCode.'.');
					$order->add_order_note(__('Payment session EXPIRED remotely. Please contact support.', 'omnicard-lite'), 1);
	                $order->update_status('failed', __('Payment session EXPIRED via PSP.', 'omnicard-lite'));

				} elseif(strcmp($omniStatus, 'NOCONTRACT') === 0) {

	                if($this->debugger=='yes') $this->log->add('omnicard', 'No Contract: Response from PSP: '.$omniStatusCode.'.');
	                $order->update_status('on-hold', sprintf(__('Payment Method "%s" is not active for this shop. Response from PSP: %s.', 'omnicard-lite'), $omniType,  $omniStatusCode));

				} else { // FAILED & EVERYTHING ELSE

	                if($this->debugger=='yes') $this->log->add('omnicard', 'Not completed: Response from PSP: '.$omniStatusCode.'.');
					$order->add_order_note(__('Payment session failed. Please contact support.', 'omnicard-lite'), 1);
	                $order->update_status('failed', sprintf(__('Payment session FAILED via PSP. Response from PSP: %s.', 'omnicard-lite'), $omniStatusCode));

				}

				 // Store PSP Details
	            if(!empty($omniID)) update_post_meta($order_id, 'Transaction ID', $omniID);
	            if(!empty($omniStatusCode)) update_post_meta($order_id, 'Response Code', $omniStatusCode);
	            if(!empty($omniType)) update_post_meta($order_id, 'Payment type', $omniType);
	            if(!empty($omniBrand)) update_post_meta($order_id, 'Payment brand', $omniBrand);
			}
			
			unset($order, $omniResponse, $omniReference, $omniStatus, $omniStatusCode, $omniID, $omniType, $omniBrand, $omniOrderID, $omniAmount, $localReference);
		}
    }
}

/*-------------------------------------------------------------
 Name:      omnicard_credits

 Purpose:   OmniCard Credits
 Receive:   - None -
 Return:    - None -
 Since:		1.0
-------------------------------------------------------------*/
function omnicard_credits() {

	echo '<table class="widefat" style="margin-top: .5em">';

	echo '<thead>';
	echo '<tr valign="top">';
	echo '	<th colspan="2">'.__('Useful links', 'omnicard-lite').'</th>';
	echo '	<th width="35%">'.__('Get more bang for your buck', 'omnicard-lite').'</th>';
	echo '	<th width="20%"><center>'.__('Brought to you by', 'omnicard-lite').'</center></th>';
	echo '</tr>';
	echo '</thead>';

	echo '<tbody>';
	echo '<tr>';

	echo '<td width="25%">';
	echo '<a href="https://ajdg.solutions/products/omnikassa-for-woocommerce/?pk_campaign=omnicardlite-credits" target="_blank">'.__('Omnikassa for WooCommerce', 'omnicard-lite').'</a><br />';
	echo '<a href="https://ajdg.solutions/products/adrotate-for-wordpress/?pk_campaign=omnicardlite-credits" target="_blank">'.__('AdRotate for Wordpress', 'omnicard-lite').'</a><br />';
	echo '<a href="http://www.floatingcoconut.net/?pk_campaign=omnicardlite-credits" target="_blank">'.__('Arnan de Gans', 'omnicard-lite').'</a>';
	echo '</td>';

	echo '<td style="border-left:1px #ddd solid;">';
	echo '<a href="https://www.rabobank.nl/bedrijven/betalen/geld-ontvangen/rabo-omnikassa/" target="_blank">'.__('Omnikassa Website', 'omnicard-lite').'</a><br />';
	echo '<a href="https://cas.merchant-extranet.sips-atos.com/cas/login?service=https%3A%2F%2Fdashboard.omnikassa.rabobank.nl%2Fportal%2Fhome" target="_blank">'.__('Omnikassa Dashboard', 'omnicard-lite').'</a><br />';
	echo '<a href="https://www.rabobank.nl/bedrijven/betalen/geld-ontvangen/rabo-omnikassa/support/" target="_blank">'.__('Omnikassa Support', 'omnicard-lite').'</a>';
	echo '</td>';

	echo '<td style="border-left:1px #ddd solid;">';
	echo __('Upgrade to the full version of OmniCard Lite so you can accept credit cards and get <strong>10%</strong> off. Use coupon code <strong>getomnikassa</strong> on checkout!', 'omnicard-lite');
	echo '</td>';

	echo '<td style="border-left:1px #ddd solid;"><center><a href="https://ajdg.solutions/?pk_campaign=omnicardlite-credits" title="Arnan de Gans"><img src="'.plugins_url('/images/arnan-jungle.jpg', __FILE__).'" alt="Arnan de Gans" width="60" height="60" align="left" class="adrotate-photo" /></a><a href="http://www.floatingcoconut.net?pk_campaign=omnicardlite-credits" target="_blank">Arnan de Gans</a><br />from<br /><a href="https://ajdg.solutions?pk_campaign=omnicardlite-credits" target="_blank">AJdG Solutions</a></center></td></td>';
	echo '</tr>';
	echo '</tbody>';

	echo '</table>';
}

/*-------------------------------------------------------------
 Name:      omnicard_activate

 Purpose:   Set up firstrun status
 Since:		1.0
-------------------------------------------------------------*/
function omnicard_activate() {
	if(!current_user_can('activate_plugins')) {
		deactivate_plugins(plugin_basename('omnicard.php'));
		wp_die('You do not have appropriate access to activate this plugin! Contact your administrator!<br /><a href="'. get_option('siteurl').'/wp-admin/plugins.php">Back to plugins</a>.'); 
		return; 
	} else {
		// Set default settings and values
		$transactions = get_option('omnicard_count');
		if(!$transactions) {
			$c = 0;
		} else {
			$c = $transactions['count'];
		}
		update_option('omnicard_count', array('count' => $c, 'month' => mktime(0, 0, 0, date("m"), 1, date("Y"))));
	}
}

/*-------------------------------------------------------------
 Name:      omnicard_uninstall

 Purpose:   Clean up on uninstall
 Since:		1.0
-------------------------------------------------------------*/
function omnicard_uninstall() {
	// 42
}

/*-------------------------------------------------------------
 Name:      omnicard_dashboard_styles

 Purpose:   Load stylesheet
 Receive:   -None-
 Return:	-None-
 Since:		1.3.2
-------------------------------------------------------------*/
function omnicard_dashboard_styles() {
	wp_enqueue_style('omnicard-admin-stylesheet', plugins_url('library/dashboard.css', __FILE__));
}

/*-------------------------------------------------------------
 Name:      omnicard_notifications_dashboard

 Purpose:   Promote Full version, count transactions
 Since:		1.3.2
-------------------------------------------------------------*/
function omnicard_notifications_dashboard() {
	if(isset($_GET['section'])) { $page = $_GET['section']; } else { $page = ''; }

	if($page == 'wc_gateway_omnicard') {
		$transactions = get_option('omnicard_count');

		echo '<div class="updated" style="padding: 0; margin: 0; border-left: none;">';
		echo '	<div class="omnicard_banner">';
		echo '		<div class="button_div">';
		echo '			<a class="button" target="_blank" href="https://ajdg.solutions/cart/?add-to-cart=211?pk_campaign=omnicardlite-banner">'.__('Upgrade now!', 'omnicard-lite').'</a>';
		echo '		</div>';

		if($transactions['count'] >= 20) {
			echo '		<div class="text">'.__("You've used all 20 transactions for this month in <strong>OmniCard Lite</strong>. To get <strong>UNLIMITED</strong> transactions per month, upgrade to the full version.", 'omnicard-lite').'<br />';
			echo '			<span>'.__("Also accept credit cards. Get 10% off with coupon <strong>getomnikassa</strong>. Upgrade today!", 'omnicard-lite').' '.__('Thank you for your purchase!', 'omnicard' ).'</span>';
			echo '		</div>';
		} else {
			echo '		<div class="text">'.__("You have used", 'omnicard-lite').' <strong>'.$transactions['count'].'</strong> '.__("out of 20 transactions this month. Remove this limitation with the <strong>FULL</strong> version!", 'omnicard-lite').'<br />';
			echo '			<span>'.__("Also accept credit cards. Get 10% off with coupon <strong>getomnikassa</strong>. Upgrade today!", 'omnicard-lite').' '.__('Thank you for your purchase!', 'omnicard' ).'</span>';
			echo '		</div>';
		}

		echo '	</div>';
		echo '</div>';
	}
}
?>