<?php
/**
 * Plugin Name: OmniCard
 * Plugin URI: https://ajdg.solutions
 * Description: Accept payments through Visa, MasterCard, MiniTix, Maestro, iDeal and Bancontact/Mister Cash offered by Rabo Omnikassa in WooCommerce.
 * Version: 1.3.2
 * Author: Arnan de Gans from AJdG Solutions
 * Author URI: http://meandmymac.net
 * Requires at least: 3.5, WooCommerce 2.1
 * Tested up to: 4.1
 */

register_activation_hook(__FILE__, 'omnicard_activate');
register_uninstall_hook(__FILE__, 'omnicard_uninstall');

add_action('plugins_loaded', 'omnicard_init');
add_action('admin_notices', 'omnicard_notifications_dashboard');

if(is_admin()) {
	/*--- Update API --------------------------------------------*/
	include_once(WP_CONTENT_DIR.'/plugins/omnicard/library/license-functions.php');
	include_once(WP_CONTENT_DIR.'/plugins/omnicard/library/license-api.php');

	$ajdg_solutions_domain = 'https://ajdg.solutions';
	$omnicard_api_url = $ajdg_solutions_domain.'/api/updates/3/';
	add_action('admin_init', 'omnicard_licensed_update');

	if(isset($_POST['omnicard_license_activate'])) add_action('init', 'omnicard_license_activate');
	if(isset($_POST['omnicard_license_deactivate'])) add_action('init', 'omnicard_license_deactivate');
	if(isset($_POST['omnicard_license_reset'])) add_action('init', 'omnicard_license_reset');
}

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
	load_plugin_textdomain('omnicard', false, basename(dirname(__FILE__)));

    class WC_Gateway_OmniCard extends WC_Payment_Gateway {
    
    	function __construct() {
			global $woocommerce;
	
	        $this->id = 'omnicard';
	        $this->has_fields = true;
	        $this->method_title	= 'Omnikassa';

	        $this->version = '1.3.1';
	        $this->beta	= '';
	        
	        $this->liveurl = 'https://payment-webinit.omnikassa.rabobank.nl/paymentServlet';
			$this->testurl = 'https://payment-webinit.simu.omnikassa.rabobank.nl/paymentServlet';
	        $this->merchantiddemo = '002020000000001';
			$this->secretkeydemo = '002020000000001_KEY1';
			$this->keyversiondemo = '1';
			$this->interface_version = 'HP_1.0';

			$this->uploadloc = wp_upload_dir();
			$this->pluginloc = plugins_url();
			$this->betadescription = !empty($this->beta) ? '<strong><abbr title="TEST VERSION WHICH MAY CONTAIN BUGS OR ERRORS">BETA VERSION</abbr>: ' . $this->version . $this->beta . '</strong> - ' : '';

			// Load the form fields.
			$this->init_form_fields();
	
			// Load the settings.
			$this->init_settings();

			// Define user set variables
			$this->title = !empty($this->settings['title']) ? $this->settings['title'] : 'Credit Card, iDEAL, Mister Cash, V-PAY, MiniTix';
			$this->description = !empty($this->settings['description']) ? $this->betadescription.$this->settings['description'] : 'Pay securely with your Credit Card, iDeal, Mister Cash, V-PAY, or MiniTix through Rabo Omnikassa.';

			$this->merchantid = $this->settings['merchantid'];
			$this->secretkey = $this->settings['secretkey'];
			$this->keyversion = $this->settings['keyversion'];
			
	        $this->checkout_icon = $this->settings['checkout_icon'];
			$this->method_ideal = $this->settings['method_ideal'];
			$this->method_bcmc = $this->settings['method_bcmc'];
			$this->method_visa = $this->settings['method_visa'];
			$this->method_master = $this->settings['method_master'];
			$this->method_vpay = $this->settings['method_vpay'];
			$this->method_maestro = $this->settings['method_maestro'];
			$this->method_minitix = $this->settings['method_minitix'];
			$this->method_default = $this->settings['method_default'];

			$this->remote_language = !empty($this->settings['remote_language']) ? $this->settings['remote_language'] : 'en';
			$this->remote_session = !empty($this->settings['remote_session']) ? $this->settings['remote_session'] : '7200';
			$this->invoice_prefix = !empty($this->settings['invoice_prefix']) ? $this->settings['invoice_prefix'] = preg_replace("/[^a-z0-9.]+/i", "", $this->settings['invoice_prefix']) : '';

			$this->testmode = $this->settings['testmode'];
			$this->debugger = $this->settings['debug'];

			// Logs
			if($this->debugger=='yes') $this->log = new WC_Logger();

			// Actions
			add_action('valid-omnicard-psp-request', array(&$this, 'successful_request'));
			add_action('woocommerce_receipt_' . $this->id, array(&$this, 'receipt_page'));
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
			add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'check_psp_response'));
			add_filter('woocommerce_gateway_icon', array(&$this, 'iconset'), 10, 2);

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
				if($this->settings['method_visa'] == 'yes') $icon[] = $this->icon_image('visa');
				if($this->settings['method_master'] == 'yes') $icon[] = $this->icon_image('mastercard');
				if($this->settings['method_vpay'] == 'yes') $icon[] = $this->icon_image('vpay');
				if($this->settings['method_maestro'] == 'yes') $icon[] = $this->icon_image('maestro');
				if($this->settings['method_minitix'] == 'yes') $icon[] = $this->icon_image('minitix');
				$icons = implode(" ", array_reverse($icon));
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
				$icon = '<img src="'.$this->pluginloc.'/omnicard/images/'.$brand.'.png" alt="'.$brand.'" />';
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
		
			$omnicard_activate = get_option('omnicard_activate');
	    	?>
	    	<h2>OmniCard <?php _e('for', 'omnicard'); ?> Rabo Omnikassa</h2>

			<?php if($status > 0) omnicard_status($status); ?>
			<?php if(($this->method_ideal == "yes" || $this->method_bcmc == "yes" || $this->method_minitix == "yes") && get_woocommerce_currency() != "EUR") {
				echo '<div id="message" class="error"><p>'. __('iDEAL, Mister Cash and MiniTix can only accept payments in Euros. Your store uses: ', 'omnicard') . get_woocommerce_currency() . '.</p></div>';
			}
			?>
			
	    	<p><?php _e('Accept iDEAL, Mister Cash, MiniTix, Visa Card, MasterCard, V-PAY and Maestro payments through the Rabobank Omnikassa Gateway.', 'omnicard'); ?><br />
	    	<?php _e('This plugin requires an activated Omnikassa Agreement with the Rabobank. Fees for usage apply!', 'omnicard'); ?><br />
	    	<?php _e('Visit the', 'omnicard'); ?> <a href="http://www.rabobank.nl/omnikassa/" target="_blank">Rabo Omnikassa</a> <?php _e('website for more information.', 'omnicard'); ?></p>

			<?php if(!empty($this->beta)) echo '<p>You are using OmniCard Beta: <strong>' . $this->version.$this->beta . '</strong></p>'; ?>

	    	<table class="form-table">
	    	<?php
	    		if($this->currency_is_valid()) {
	    			$this->generate_settings_html();
	    		} else {
	    		?>
           		<div class="inline error"><p><strong><?php _e('Gateway Disabled', 'omnicard'); ?></strong>: Rabo Omnikassa <?php _e('does not support your store currency.', 'omnicard'); ?></p></div>
	       		<?php
	    		}
	    	?>
			</table>

	  		<h4><?php _e('OmniCard License', 'omnicard'); ?></h4>
			<?php wp_nonce_field('omnicard-license','omnicard_license'); ?>

	    	<table class="form-table">

				<tr>
					<th valign="top"><?php _e('License Type', 'omnicard'); ?></th>
					<td>
						<?php echo ($omnicard_activate['type'] != '') ? $omnicard_activate['type'] : __('Not activated', 'omnicard'); ?></br>
					</td>
				</tr>
				<tr>
					<th valign="top"><?php _e('License Key', 'omnicard'); ?></th>
					<td>
						<input name="omnicard_license_key" type="text" class="search-input" size="50" value="<?php echo $omnicard_activate['key']; ?>" autocomplete="off" <?php echo ($omnicard_activate['status'] == 1) ? 'disabled' : ''; ?> /> <span class="description"><?php _e('You can find the license key in your purchase email.', 'omnicard'); ?></span>
					</td>
				</tr>
				<tr>
					<th valign="top"><?php _e('License Email', 'omnicard'); ?></th>
					<td>
						<input name="omnicard_license_email" type="text" class="search-input" size="50" value="<?php echo $omnicard_activate['email']; ?>" autocomplete="off" <?php echo ($omnicard_activate['status'] == 1) ? 'disabled' : ''; ?> /> <span class="description"><?php _e('The email address you used when purchasing.', 'omnicard'); ?></span>
					</td>
				</tr>
				<tr>
					<th valign="top">&nbsp;</th>
					<td>
						<?php if($omnicard_activate['status'] == 0) { ?>
						<input type="submit" id="post-role-submit" name="omnicard_license_activate" value="<?php _e('Activate', 'omnicard'); ?>" class="button-secondary" />
						<?php } else { ?>
						<input type="submit" id="post-role-submit" name="omnicard_license_deactivate" value="<?php _e('De-activate', 'omnicard'); ?>" class="button-secondary" />
						<?php } ?><br />
					</td>
				</tr>

			</table>
	    
	    	<?php
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
					'title' => __('Basic Settings', 'omnicard'),
					'type' => 'title'
				),
				'enabled' => array(
					'title' => __('Enable/Disable', 'omnicard'),
					'type' => 'checkbox',
					'label' => __('Enable the Rabo Omnikassa Gateway', 'omnicard'),
					'default' => 'no'
				),
				'title' => array(
					'title' => __('Title', 'omnicard'),
					'type' => 'text',
					'description' => __('The title which the user sees during checkout; Make sure it matches your payment methods.', 'omnicard'),
					'default' => 'Credit Card, iDEAL, MiniTix, V-PAY, Mister Cash',
					'css' => 'width:400px;'
				),
				'description' => array(
					'title' => __('Description', 'omnicard'),
					'type' => 'textarea',
					'description' => __('The description which the user sees during checkout; Make sure it matches your payment methods.', 'omnicard'),
					'default' => 'Pay securely with your Credit Card, V-PAY, iDeal, Mister Cash or MiniTix through Rabo Omnikassa.',
					'css' => 'width:400px;'
				),
				'checkout_icon' => array(
					'title' => __('Icon', 'omnicard'),
					'type' => 'checkbox',
					'label' => __('Show payment icons in the gateway selection box', 'omnicard'),
					'description' => __('Place your own logos sized approximately 32*32px in <code>wp-content/uploads/</code> named <code>omnicard-(ideal|visa|mastercard|maestro|minitix|vpay|mistercash).png</code>.', 'omnicard') . '<br />' . __('For iDEAL use: <code>wp-content/uploads/omnicard-ideal.png</code> to replace the default logo.', 'omnicard'),
					'default' => 'yes'
				),

				// MERCHANT DETAILS
				'merchant' => array(
					'title' => __('Merchant Details', 'omnicard'),
					'type' => 'title',
					'description' => __('You can find your merchant details in 2 separate emails sent to you by the Rabobank after you signed your agreement.', 'omnicard')
				),
				'merchantid' => array(
					'title' => __('Merchant ID', 'omnicard'),
					'type' => 'text',
					'description' => __('This is needed in order to take payment.', 'omnicard'),
					'default' => '',
					'css' => 'width:400px;'
				),
				'secretkey' => array(
					'title' => __('Secret Key', 'omnicard'),
					'type' => 'text',
					'description' => __('This is needed in order to take payment.', 'omnicard'),
					'default' => '',
					'css' => 'width:400px;'
				),
				'keyversion' => array(
					'title' => __('Key Version', 'omnicard'),
					'type' => 'text',
					'description' => __('This is needed in order to take payment.', 'omnicard'),
					'default' => '',
					'css' => 'width:400px;'
				),

				// PAYMENT METHODS
				'paymentmethods' => array(
					'title' => __('Accepted Payment Methods', 'omnicard'),
					'type' => 'title',
					'description' => __('Each payment method requires approval from your bank and possibly an extension on your agreement with the Rabobank. Check with the Rabobank for details before activating new payment methods!', 'omnicard')
				),
				'method_ideal' => array(
					'title' => __('iDEAL', 'omnicard'),
					'type' => 'checkbox',
					'label' => __('Receive iDEAL payments', 'omnicard'),
					'description' => __('Instant bank transfer. Available to Dutch ATM Card holders.', 'omnicard'),
					'default' => 'yes'
				),
				'method_bcmc' => array(
					'title' => __('Bancontact/Mister Cash', 'omnicard'),
					'type' => 'checkbox',
					'label' => __('Receive Bancontact Mister Cash payments', 'omnicard'),
					'description' => __('Instant bank transfer. Available to Belgian ATM Card holders.', 'omnicard'),
					'default' => 'no'
				),
				'method_visa' => array(
					'title' => __('Visa Card', 'omnicard'),
					'type' => 'checkbox',
					'label' => __('Receive Visa Card Payments', 'omnicard'),
					'description' => __('International payments. Available worldwide.', 'omnicard'),
					'default' => 'no'
				),
				'method_master' => array(
					'title' => __('MasterCard', 'omnicard'),
					'type' => 'checkbox',
					'label' => __('Receive MasterCard Payments', 'omnicard'),
					'description' => __('International payments. Available worldwide.', 'omnicard'),
					'default' => 'no'
				),
				'method_vpay' => array(
					'title' => __('V-PAY', 'omnicard'),
					'type' => 'checkbox',
					'label' => __('Receive V-PAY Payments', 'omnicard'),
					'description' => __('European Debit Card. Available in Europe.', 'omnicard'),
					'default' => 'no'
				),
				'method_maestro' => array(
					'title' => __('Maestro', 'omnicard'),
					'type' => 'checkbox',
					'label' => __('Receive Maestro Payments', 'omnicard'),
					'description' => __('Direct bank transfer. Available in Europe.', 'omnicard'),
					'default' => 'no'
				),
				'method_minitix' => array(
					'title' => __('MiniTix', 'omnicard'),
					'type' => 'checkbox',
					'label' => __('Receive MiniTix Payments', 'omnicard'),
					'description' => __('Online wallet for payments up to &euro; 150 Euros. Available to Dutch bank accounts only.', 'omnicard'),
					'default' => 'no'
				),
				'method_default' => array(
				     'title' => __('Default payment option', 'omnicard'),
				     'description' => __('Default selected option on checkout. The chosen option here must be active in your contract or no selection can be made! (Only applicable if you use multiple payment methods.)', 'omnicard'),
				     'type' => 'select',
				     'options' => array(
				          'ideal' => 'iDeal',
				          'bcmc' => 'Mister Cash',
				          'creditcard' => 'Visa or MasterCard',
				          'vpay' => 'V-PAY',
				          'maestro' => 'Maestro',
				          'minitix' => 'MiniTix'
				     )
				),

				// MISC SETTINGS
				'misc_settings' => array(
					'title' => __('Miscellaneous Settings', 'omnicard'),
					'type' => 'title'
				),
				'remote_language' => array(
					'title' => __('Language', 'omnicard'),
					'type' => 'select',
					'description' => __('Which language should the Rabobank Omnikassa Pages use for checkout.', 'omnicard'),
					'default' => 'en',
					'options' => array(
						'en' => __('English', 'omnicard'),
						'nl' => __('Dutch', 'omnicard')
					)
				),
				'remote_session' => array(
					'title' => __('Expiration', 'omnicard'),
					'type' => 'select',
					'description' => __('For added security you can add a timeout to the transaction. The transaction must be completed within this timeframe or the request will expire and has to be started over again.', 'omnicard'),
					'default' => '21600',
					'options' => array(
						'0' => __('Disable', 'omnicard'),
						'7200' => __('2 hours (Default)', 'omnicard'),
						'14400' => __('4 hours', 'omnicard'),
						'21600' => __('6 hours', 'omnicard'),
						'28800' => __('8 hours', 'omnicard'),
						'57600' => __('16 hours', 'omnicard'),
						'86400' => __('24 hours', 'omnicard')
					)
				),
				'invoice_prefix' => array(
					'title' => __('Invoice Prefix', 'omnicard'),
					'type' => 'text',
					'description' => __('Enter a prefix for your order numbers. Only alphanumeric characters allowed. If you use your Omnikassa account for multiple stores ensure this prefix is unqiue to avoid confusion.', 'omnicard') . '<br />' . __('This prefix is NOT added to WooCommerce orders but is shown on your bank statements as part of the transaction reference.', 'omnicard'),
					'default' => 'wc'
				),

				// TEST MODE AND DEBUGGING
				'testing' => array(
					'title' => __('Gateway Testing', 'omnicard'),
					'type' => 'title'
				),
				'testmode' => array(
					'title' => __('Simulator', 'omnicard'),
					'type' => 'checkbox',
					'label' => __('Enable the Omnikassa test mode', 'omnicard'),
					'default' => 'yes',
					'description' => __('You can leave your "live" account details in place.', 'omnicard'),
				),
				'debug' => array(
					'title' => __('Troubleshooting', 'omnicard'),
					'type' => 'checkbox',
					'label' => __('Enable logging', 'omnicard'),
					'default' => 'no',
					'description' => __('Log Omnikassa events, such as PSP requests and status updates, inside <code>wc-logs/omnicard-*.log</code>.', 'omnicard'),
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
				$merchantID = $this->merchantiddemo;
				$keyVersion = $this->keyversiondemo;
			} else {
				$merchantID = $this->merchantid;
				$keyVersion = $this->keyversion;
			}

			// Define Payment Methods
			$brandList = array();
			if($this->method_ideal == "yes" && ($brand == 1 || $brand == 0)) $brandList[] = 'IDEAL';
			if($this->method_bcmc == "yes" && ($brand == 5 || $brand == 0)) $brandList[] = 'BCMC';
			if(($this->method_visa == "yes" || $this->method_master == "yes") && ($brand == 2 || $brand == 0)) $brandList[] = 'VISA,MASTERCARD';
			if($this->method_vpay == "yes" && ($brand == 6 || $brand == 0)) $brandList[] = 'VPAY';
			if($this->method_maestro == "yes" && ($brand == 3 || $brand == 0)) $brandList[] = 'MAESTRO';
			if($this->method_minitix == "yes" && ($brand == 4 || $brand == 0)) $brandList[] = 'MINITIX';
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
			if($this->settings['method_visa'] == 'yes' || $this->settings['method_master'] == 'yes') {
				$active++;
				$method = 2;
			}
			if($this->settings['method_vpay'] == 'yes') {
				$active++;
				$method = 6;
			}
			if($this->settings['method_maestro'] == 'yes') {
				$active++;
				$method = 3;
			}
			if($this->settings['method_minitix'] == 'yes') {
				$active++;
				$method = 4;
			}
			
			if($active > 1) {
				$output .= '<p>';
				if($this->settings['method_ideal'] == 'yes') {
					$output .= '<input type="radio" id="ideal" name="omnicard_submethod" value="1"';
					if($this->method_default == 'ideal') $output .= ' checked="checked"';
					$output .= ' /> <label for="ideal">' . __('iDeal', 'omnicard') . '</label><br />';
				}
				if($this->settings['method_bcmc'] == 'yes') {
					$output .= '<input type="radio" id="bcmc" name="omnicard_submethod" value="5"';
					if($this->method_default == 'bcmc') $output .= ' checked="checked"';
					$output .= ' /> <label for="bcmc">' . __('Bancontact/Mister Cash', 'omnicard') . '</label><br />';
				}
				if($this->settings['method_visa'] == 'yes' || $this->settings['method_master'] == 'yes') {
					$output .= '<input type="radio" id="creditcard" name="omnicard_submethod" value="2"';
					if($this->method_default == 'creditcard') $output .= ' checked="checked"';
					$output .= ' /> <label for="creditcard">';
					if($this->settings['method_visa'] == 'yes' && $this->settings['method_master'] == 'yes') $output .= __('Visa or MasterCard', 'omnicard');
					if($this->settings['method_visa'] == 'yes' && $this->settings['method_master'] == 'no') $output .= __('Visa Card', 'omnicard');
					if($this->settings['method_visa'] == 'no' && $this->settings['method_master'] == 'yes') $output .= __('MasterCard', 'omnicard');
					$output .= '</label><br />';
				}
				if($this->settings['method_vpay'] == 'yes') {
					$output .= '<input type="radio" id="vpay" name="omnicard_submethod" value="6"';
					if($this->method_default == 'vpay') $output .= ' checked="checked"';
					$output .= ' /> <label for="vpay">' . __('V-PAY', 'omnicard') . '</label><br />';
				}
				if($this->settings['method_maestro'] == 'yes') {
					$output .= '<input type="radio" id="maestro" name="omnicard_submethod" value="3"';
					if($this->method_default == 'maestro') $output .= ' checked="checked"';
					$output .= ' /> <label for="maestro">' . __('Maestro', 'omnicard') . '</label><br />';
				}
				if($this->settings['method_minitix'] == 'yes') {
					$output .= '<input type="radio" id="minitix" name="omnicard_submethod" value="4"';
					if($this->method_default == 'minitix') $output .= ' checked="checked"';
					$output .= ' /> <label for="minitix">' . __('MiniTix', 'omnicard') . '</label><br />';
				}			
				$output .= '</p>';
			} else if($active == 1) {
				$output .= '<input type="hidden" name="omnicard_submethod" value="'. $method .'" />';
			} else {
				$output .= '<p>'.__('No payment methods activated - Check your settings or contact your administrator!', 'omnicard').'</p>';
			}
			echo $output;
			unset($active, $method, $output);
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
				$secretKey = $this->secretkeydemo;
			} else {
				$omni_address = $this->liveurl . '?';
				$secretKey = $this->secretkey;
			}
	
			$submethod = get_post_meta($order_id, 'Payment brand', 1);
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
					message: "' . esc_js(__('Thank you for your order. We are now redirecting you to Rabobank OmniKassa to make payment.', 'omnicard')) . '",
					baseZ: 99999,
					overlayCSS:	{ background: "#000", opacity: 0.6 },
					css: { padding: "20px", zindex: "9999", textAlign: "center", color: "#555", border: "3px solid #aaa", backgroundColor: "#fff", cursor: "wait", lineHeight: "32px" }
				});
				jQuery("#submit_omnicard_payment_form").click();
			');
	
			echo '<h3>'.__('Please click the button below to complete your purchase.', 'omnicard').'</h3>';
			echo '<form action="'.esc_url($omni_address).'" method="post" id="omnicard_payment_form" target="_top">';
			echo '<input type="hidden" name="InterfaceVersion" value="'.esc_attr(htmlspecialchars($this->interface_version)).'" />';
			echo '<input type="hidden" name="Data" value="'.esc_attr(htmlspecialchars($omniData)).'" />';
			echo '<input type="hidden" name="Seal" value="'.esc_attr(htmlspecialchars($omniSeal)).'" />';
			echo '<input type="submit" class="button-alt" id="submit_omnicard_payment_form" value="'.__('Continue', 'omnicard').'" />';
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
		 Return:    - None -
		 Since:		1.0
		-------------------------------------------------------------*/
	    private function currency_is_valid() {
	        if(!in_array(get_woocommerce_currency(), array('AUD', 'CAD', 'DKK', 'EUR', 'GBP', 'JPY', 'NOK', 'SEK', 'USD', 'CHF'))) return false;
	        return true;
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
				'02' => __('Creditcard authorizationlimit exceeded.', 'omnicard'),
				'05' => __('Card declined; Insufficient funds, expired or not active.', 'omnicard'),
				'14' => __('Invalid Card or Authorization failed. Check your card details.', 'omnicard'),
				'17' => __('Transaction cancelled by user.', 'omnicard'),
				'60' => __('Awaiting response from gateway. If you place another order you may be billed more than once.', 'omnicard'),
				'75' => __('You have exceeded the maximum of 3 attempts to enter your card number. Transaction aborted.', 'omnicard'),
				'89' => __('CVC/Security code invalid or empty. Please try again.', 'omnicard'),
				'90' => __('Unable to reach payment gateway.', 'omnicard'),
				'97' => __('Exceeded time limit. Transaction aborted. Please try again.', 'omnicard'),
				'99' => __('Payment gateway unavailable.', 'omnicard'),
			);

			return $status[$response];
		}

		/*-------------------------------------------------------------
		 Name:      check_psp_request_is_valid
		
		 Purpose:   Validate PSP response
		 Receive:   - None -
		 Return:    - None -
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
	        	'user-agent'	=> 'Omnicard/'.$this->version
	        );
	
			if($this->testmode == 'yes') {
				$omni_address = $this->testurl;
				$secretKey = $this->secretkeydemo;
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
						$order->update_status('on-hold', sprintf(__('Issue with reference code. Should be: %s. PSP provided: %s.', 'omnicard'), $localReference, $omniReference));
						wp_redirect(add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, esc_url($order->get_checkout_payment_url()))));
						exit;
	            	}

	            	// Check valid type and brand
	            	$accepted_types = array('CREDIT_TRANSFER', 'CARD', 'OTHER', 'Omnikassa');
	            	$accepted_brands = array('IDEAL', 'BCMC', 'VISA', 'MASTERCARD', 'VPAY', 'MAESTRO', 'MINITIX', 'Rabobank');
					if(!in_array($omniType, $accepted_types) || !in_array($omniBrand, $accepted_brands)) {
						if($this->debugger == 'yes') $this->log->add('omnicard', 'Aborting, Invalid type or brand. Response from PSP - Type :' . $omniType . ', Brand: ' . $omniBrand . '.');
				    	$order->update_status('on-hold', sprintf(__('Issue with payment type or brand. Response from PSP - Type: %s, Brand: %s.', 'omnicard'), $omniType, $omniBrand));
						wp_redirect(add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, esc_url($order->get_checkout_payment_url()))));
						exit;
					}
					
					// Validate Amount
				    if(number_format($order->get_total(), 2, '', '') != $omniAmount) {				    
				    	$omniAmountDec = number_format($omniAmount, get_option('woocommerce_price_num_decimals'), get_option('woocommerce_price_decimal_sep'), get_option('woocommerce_price_thousand_sep'));
				    	if($this->debugger == 'yes') $this->log->add('omnicard', 'Payment error: Amounts do not match (gross ' . $omniAmountDec . ').');
				    	$order->update_status('on-hold', sprintf(__('Validation error: PSP amounts do not match with original order (gross %s).', 'omnicard'), $omniAmountDec));
						wp_redirect(add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, esc_url($order->get_checkout_payment_url()))));
				    	exit;
				    }

	            	// Payment completed
	                $order->add_order_note(__('PSP payment completed', 'omnicard'));
	                $order->payment_complete();

	                if($this->debugger=='yes') $this->log->add('omnicard', 'Payment complete.');

				} elseif(strcmp($omniStatus, 'PENDING') === 0) {

	                if($this->debugger=='yes') $this->log->add('omnicard', 'pending: Response from PSP: '.$omniStatusCode.'.');
					$order->add_order_note(__('Order set to PENDING. You can try again from your order through', 'omnicard').' <a href="'.esc_url($order->get_checkout_payment_url()).'">'.__('Checkout', 'omnicard') . '</a>. ' . sprintf(__('Error: %s.', 'omnicard'), $omniStatusCode.' - '.$this->transaction_status_code($omniStatusCode)), 1);
	                $order->update_status('pending', sprintf(__('Awaiting %s payment. Order status changed to pending. Response from PSP: %s.', 'omnicard'), $omniType, $omniStatusCode));

				} elseif(strcmp($omniStatus, 'CANCELLED') === 0) {

	                if($this->debugger=='yes') $this->log->add('omnicard', 'Cancelled: Response from PSP: '.$omniStatusCode.'.');
	                $order->update_status('cancelled', __('Cancelled by user.', 'omnicard'));
					unset($woocommerce->session->order_awaiting_payment);

				} elseif(strcmp($omniStatus, 'EXPIRED') === 0) {

	                if($this->debugger=='yes') $this->log->add('omnicard', 'Expired: Response from PSP: '.$omniStatusCode.'.');
					$order->add_order_note(__('Payment session EXPIRED remotely. Please contact support.', 'omnicard'), 1);
	                $order->update_status('failed', __('Payment session EXPIRED via PSP.', 'omnicard'));

				} elseif(strcmp($omniStatus, 'NOCONTRACT') === 0) {

	                if($this->debugger=='yes') $this->log->add('omnicard', 'No Contract: Response from PSP: '.$omniStatusCode.'.');
	                $order->update_status('on-hold', sprintf(__('Payment Method "%s" is not active for this shop. Response from PSP: %s.', 'omnicard'), $omniType,  $omniStatusCode));

				} else { // FAILED & EVERYTHING ELSE

	                if($this->debugger=='yes') $this->log->add('omnicard', 'Not completed: Response from PSP: '.$omniStatusCode.'.');
					$order->add_order_note(__('Payment session failed. Please contact support.', 'omnicard'), 1);
	                $order->update_status('failed', sprintf(__('Payment session FAILED via PSP. Response from PSP: %s.', 'omnicard'), $omniStatusCode));

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
	echo '	<th colspan="2">'.__('Useful links', 'omnicard').'</th>';
	echo '	<th>'.__('Brought to you by', 'omnicard').'</th>';
	echo '</tr>';
	echo '</thead>';

	echo '<tbody>';
	echo '<tr>';
	echo '<td style="border-left:1px #ddd solid;" width="25%"><ul>';
	echo '	<li><a href="https://ajdg.solutions/products/omnicard/?utm_source=omnicard&utm_medium=omnicard_credits&utm_campaign=omnicard_link" target="_blank">'.__('OmniCard page', 'omnicard').'</a></li>';
	echo '	<li><a href="https://ajdg.solutions/products/adrotate-for-wordpress/?utm_source=omnicard&utm_medium=omnicard_credits&utm_campaign=adrotate_link" target="_blank">'.__('AdRotate for Wordpress page', 'omnicard').'</a></li>';
	echo '	<li><a href="http://meandmymac.net/" target="_blank">'.__('My blog and website', 'omnicard').'</a></li>';
	echo '</ul></td>';
	echo '<td style="border-left:1px #ddd solid;" width="25%"><ul>';
	echo '	<li><a href="https://www.rabobank.nl/bedrijven/producten/betalen_en_ontvangen/geld_ontvangen/rabo_omnikassa/#tab3" target="_blank">'.__('Omnikassa Costs', 'omnicard').'</a></li>';
	echo '	<li><a href="https://www.rabobank.nl/bedrijven/producten/betalen_en_ontvangen/geld_ontvangen/rabo_omnikassa/#tab4" target="_blank">'.__('Omnikassa Terms of Service', 'omnicard').'</a></li>';
	echo '	<li><a href="https://www.rabobank.nl/bedrijven/producten/betalen_en_ontvangen/alle_producten/rabo_omnikassa/support/" target="_blank">'.__('Omnikassa Support', 'omnicard').'</a></li>';
	echo '</ul></td>';

	echo '<td style="border-left:1px #ddd solid;"><ul>';
	echo '	<li><a href="https://ajdg.solutions/?utm_source=omnicard&utm_medium=omnicard_credits&utm_campaign=omnicard_link" title="AJdG Solutions"><img src="'.plugins_url().'/omnicard-lite/images/ajdg-logo-100x60.png" alt="ajdg-logo-100x60" width="100" height="60" align="left" style="padding: 0 10px 10px 0;" /></a>';
	echo '	<a href="https://ajdg.solutions/?utm_source=omnicard&utm_medium=omnicard_credits&utm_campaign=omnicard_link" title="AJdG Solutions">AJdG Solutions</a> - '.__('Your one stop for Webdevelopment, consultancy and anything WordPress! When you need a custom plugin, theme customizations or have your site moved/migrated entirely. Find out more about what I can do for you on my website!', 'omnicard').' '.__('Visit the', 'omnicard').' <a href="https://ajdg.solutions/?utm_source=omnicard&utm_medium=omnicard_credits&utm_campaign=omnicard_link" target="_blank">AJdG Solutions</a> '.__('website', 'omnicard').'.</li>';
	echo '</ul></td>';
	echo '</tr>';
	echo '</tbody>';

	echo '</table>';
}

/*-------------------------------------------------------------
 Name:      omnicard_return

 Purpose:   Internal redirects
 Receive:   $page, $status
 Return:    -none-
 Since:		3.8.5
-------------------------------------------------------------*/
function omnicard_return($page, $status) {

	if(strlen($page) > 0 AND ($status > 0 AND $status < 1000)) {
		$redirect = 'admin.php?page=wc-settings&tab=checkout&section=' . $page . '&status='.$status;
	} else {
		$redirect = 'admin.php?page=wc-settings&tab=checkout&section=wc_gateway_omnicard';
	}

	wp_redirect($redirect);

}

/*-------------------------------------------------------------
 Name:      omnicard_status

 Purpose:   Internal redirects
 Receive:   $status
 Return:    -none-
 Since:		3.8.5
-------------------------------------------------------------*/
function omnicard_status($status) {

	switch($status) {
		// Licensing
		case '600' :
			echo '<div id="message" class="error"><p>'. __('Invalid request', 'omnicard') .'</p></div>';
		break;

		case '601' :
			echo '<div id="message" class="error"><p>'. __('No license key or email provided', 'omnicard') .'</p></div>';
		break;

		case '602' :
			echo '<div id="message" class="error"><p>'. __('No valid response from license server. Contact support.', 'omnicard') .'</p></div>';
		break;

		case '603' :
			echo '<div id="message" class="error"><p>'. __('The email provided is invalid. If you think this is not true please contact support.', 'omnicard') .'</p></div>';
		break;

		case '604' :
			echo '<div id="message" class="error"><p>'. __('Invalid license key. If you think this is not true please contact support.', 'omnicard') .'</p></div>';
		break;

		case '605' :
			echo '<div id="message" class="error"><p>'. __('The purchase matching this product is not complete. Contact support.', 'omnicard') .'</p></div>';
		break;

		case '606' :
			echo '<div id="message" class="error"><p>'. __('No remaining activations for this license. If you think this is not true please contact support.', 'omnicard') .'</p></div>';
		break;

		case '607' :
			echo '<div id="message" class="error"><p>'. __('Could not (de)activate key. Contact support.', 'omnicard') .'</p></div>';
		break;

		case '608' :
			echo '<div id="message" class="updated"><p>'. __('Thank you. Your license is now active', 'omnicard') .'</p></div>';
		break;

		case '609' :
			echo '<div id="message" class="updated"><p>'. __('Thank you. Your license is now de-activated', 'omnicard') .'</p></div>';
		break;

		case '610' :
			echo '<div id="message" class="updated"><p>'. __('Thank you. Your licenses have been reset', 'omnicard') .'</p></div>';
		break;

	}
}

/*-------------------------------------------------------------
 Name:      omnicard_nonce_error

 Purpose:   Display a formatted error if Nonce fails
 Since:		1.0
-------------------------------------------------------------*/
function omnicard_nonce_error() {
	echo '	<h2 style="text-align: center;">'.__('Oh no! Something went wrong!', 'omnicard').'</h2>';
	echo '	<p style="text-align: center;">'.__('WordPress was unable to verify the authenticity of the url you have clicked. Verify if the url used is valid or log in via your browser.', 'omnicard').'</p>';
	echo '	<p style="text-align: center;">'.__('If you have received the url you want to visit via email, you are being tricked!', 'omnicard').'</p>';
	echo '	<p style="text-align: center;">'.__('Contact support if the issue persists:', 'omnicard').' <a href="https://ajdg.solutions/support/?utm_source=omnicard&utm_medium=omnicard_nonce_error&utm_campaign=support" title="AJdG Solutions Support" target="_blank">AJdG Solutions Support</a>.</p>';
}

/*-------------------------------------------------------------
 Name:      omnicard_activate

 Purpose:   Set up licensing and firstrun status
 Since:		1.0
-------------------------------------------------------------*/
function omnicard_activate() {
	if(!current_user_can('activate_plugins')) {
		deactivate_plugins(plugin_basename('omnicard/omnicard.php'));
		wp_die('You do not have appropriate access to activate this plugin! Contact your administrator!<br /><a href="'. get_option('siteurl').'/wp-admin/plugins.php">Back to plugins</a>.'); 
		return; 
	} else {
		// Set default settings and values
		add_option('omnicard_activate', array('status' => 0, 'instance' => '', 'activated' => '', 'deactivated' => '', 'type' => '', 'key' => '', 'email' => '', 'version' => '', 'firstrun' => 1));
	}
}

/*-------------------------------------------------------------
 Name:      omnicard_uninstall

 Purpose:   Clean up on uninstall
 Since:		1.0
-------------------------------------------------------------*/
function omnicard_uninstall() {
	delete_option('omnicard_activate');
}

/*-------------------------------------------------------------
 Name:      omnicard_notifications_dashboard

 Purpose:   Tell user to register copy
 Since:		1.0
-------------------------------------------------------------*/
function omnicard_notifications_dashboard() {
	$license = get_option('omnicard_activate');

	if($license['firstrun'] == 1) {
		echo '<div class="updated"><p>' . __('Register your copy of OmniCard.', 'omnicard').' <a href="admin.php?page=wc-settings&tab=checkout&section=wc_gateway_omnicard" class="button">'.__('Register OmniCard', 'omnicard').'</a></p></div>';
		update_option('omnicard_activate', array('status' => 0, 'instance' => '', 'activated' => '', 'deactivated' => '', 'type' => '', 'key' => '', 'email' => '', 'version' => '', 'firstrun' => 0));
	}
}

?>