<?php
/**
 * Gateway classes
 *
 * Generic prototype classes for local and remote payment systems
 *
 * @author Jonathan Davis
 * @version 1.0
 * @copyright Ingenesis Limited, March 17, 2009
 * @package shopp
 * @subpackage gateways
 **/

/**
 * GatewayModule interface
 *
 * Provides a template for required gateway methods
 *
 * @author Jonathan Davis
 * @since 1.1
 * @package shopp
 * @subpackage gateways
 **/
interface GatewayModule {

	/**
	 * Used for setting up event listeners
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void
	 **/
	public function actions();

	/**
	 * Used for rendering the gateway settings UI
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void
	 **/
	public function settings();

}

/**
 * GatewayFramework class
 *
 * Provides default helper methods for gateway modules.
 *
 * @author Jonathan Davis
 * @since 1.1
 * @version 1.2
 * @package shopp
 * @subpackage gateways
 **/
abstract class GatewayFramework {

	var $name = false;			// The proper name of the gateway
	var $module = false;		// The module class name of the gateway

	// Supported features
	var $cards = false;			// A list of supported payment cards
	var $refunds = false;		// Remote refund support flag

	// Config settings
	var $xml = false;			// Flag to load and enable XML parsing
	var $soap = false;			// Flag to load and SOAP client helper
	var $secure = true;			// Flag for requiring encrypted checkout process
	var $multi = false;			// Flag to enable a multi-instance gateway

	// Loaded settings
	var $session = false;		// The current shopping session ID
	var $Order = false;			// The current customer's Order
	var $baseop = false; 		// Base of operation setting
	var $precision = 2;			// Currency precision
	var $decimals = '.';		// Default decimal separator
	var $thousands = '';		// Default thousands separator
	var $settings = array();	// List of settings for the module

	/**
	 * Setup the module for runtime
	 *
	 * Auto-loads settings for the module and setups defaults
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void
	 **/
	function __construct () {
		global $Shopp;
		$Shopping = ShoppShopping();

		$this->session = $Shopping->session;
		$this->Order = &ShoppOrder();
		$this->module = get_class($this);
		$this->settings = shopp_setting($this->module);
		if (!isset($this->settings['label']) && $this->cards)
			$this->settings['label'] = __("Credit Card","Shopp");

		if ( $this->xml && ! class_exists('xmlQuery') ) require(SHOPP_MODEL_PATH."/XML.php");
		if ( $this->soap && ! class_exists('nusoap_base') ) require(SHOPP_MODEL_PATH."/SOAP.php");

		$this->baseop = shopp_setting('base_operations');
		$this->precision = $this->baseop['currency']['format']['precision'];

		$this->_loadcards();
		if ($this->myorder()) $this->actions();
	}

	/**
	 * Initialize a list of gateway module settings
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @param string $name The name of a setting
	 * @param string $name... (optional) Additional setting names to initialize
	 * @return void
	 **/
	function setup () {
		$settings = func_get_args();
		foreach ($settings as $name)
			if (!isset($this->settings[$name]))
				$this->settings[$name] = false;
	}

	/**
	 * Determine if the current order should be processed by this module
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return boolean
	 **/
	function myorder () {
		return ($this->Order->processor() == $this->module);
	}

	/**
	 * Generate a unique transaction ID using a timestamp
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return string
	 **/
	function txnid () {
		return mktime();
	}

	/**
	 * Generic connection manager for sending data
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @param string $data The encoded data to send
	 * @param string $url The URL to connect to
	 * @param string $port (optional) Connect to a specific port
	 * @return string Raw response
	 **/
	function send ($data, $url, $port=false, $options = array()) {

		$defaults = array(
			'method' => 'POST',
			'timeout' => SHOPP_GATEWAY_TIMEOUT,
			'redirection' => 7,
			'httpversion' => '1.0',
			'user-agent' => SHOPP_GATEWAY_USERAGENT.'; '.get_bloginfo( 'url' ),
			'blocking' => true,
			'headers' => array(),
			'cookies' => array(),
			'body' => null,
			'compress' => false,
			'decompress' => true,
			'sslverify' => true
		);

		$params = array_merge($defaults,$options);

		$URL = $url.$post?":$post":'';

		$connection = new WP_Http();
		$result = $connection->request($URL,$params);

		if (empty($result) || !isset($result['response'])) {
			new ShoppError($this->name.": ".Lookup::errors('gateway','noresponse'),'gateway_comm_err',SHOPP_COMM_ERR);
			return false;
		} else extract($result);

		if (200 != $reponse['code']) {
			$error = Lookup::errors('gateway','http-'.$response['code']);
			if (empty($error)) $error = Lookup::errors('gateway','http-unkonwn');
			new ShoppError($this->name.": $error",'gateway_comm_err',SHOPP_COMM_ERR);
			return false;
		}

		return $body;
	}

	/**
	 * Helper to encode a data structure into a URL-compatible format
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @param array $data Key/value pairs of data to encode
	 * @return string
	 **/
	function encode ($data) {
		$query = "";
		$data = stripslashes_deep($data);
		foreach($data as $key => $value) {
			if (is_array($value)) {
				foreach($value as $item) {
					if (strlen($query) > 0) $query .= "&";
					$query .= "$key=".urlencode($item);
				}
			} else {
				if (strlen($query) > 0) $query .= "&";
				$query .= "$key=".urlencode($value);
			}
		}
		return $query;
	}

	/**
	 * Formats a data structure into POST-able form elements
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @param array $data Key/value pairs of data to format into form elements
	 * @return string
	 **/
	function format ($data) {
		$query = "";
		foreach($data as $key => $value) {
			if (is_array($value)) {
				foreach($value as $item)
					$query .= '<input type="hidden" name="'.$key.'[]" value="'.esc_attr($item).'" />';
			} else {
				$query .= '<input type="hidden" name="'.$key.'" value="'.esc_attr($value).'" />';
			}
		}
		return $query;
	}

	/**
	 * Provides the accepted PayCards for the gateway
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return array
	 **/
	function cards () {
		$accepted = array();
		if (!empty($this->settings['cards'])) $accepted = $this->settings['cards'];
		if (empty($accepted) && is_array($this->cards)) $accepted = array_keys($this->cards);
		$pcs = Lookup::paycards();
		$cards = array();
		foreach ($accepted as $card) {
			$card = strtolower($card);
			if (isset($pcs[$card])) $cards[$card] = $pcs[$card];
		}
		return $cards;
	}

	/**
	 * Formats monetary amounts for handing off to the gateway
	 *
	 * Supports specifying an order total by name (subtotal, tax, shipping, total)
	 *
	 * @author Jonathan Davis
	 * @since 1.2
	 *
	 * @param string|float|int $amount The amount (or name of the amount total) to format
	 * @return string Formatted amount
	 **/
	function amount ($amount,$format=array()) {

		if (is_string($amount)) {
			$Totals = ShoppOrder()->Cart->Totals;
			if (!isset($Totals->$name)) return false;
			$amount = $Totals->$name;
		} elseif ( ! ( is_int($amount) && is_float($amount) ) ) return false;

		$defaults = array(
			'precision' => $this->precision,
			'decimals' => $this->decimals,
			'thousands' => $this->thousands,
		);
		$format = array_merge($defaults,$format);
		extract($format);

		return number_format($amount,$precision,$decimals,$thousands);
	}

	/**
	 * Loads the enabled payment cards
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void
	 **/
	private function _loadcards () {
		if (empty($this->settings['cards'])) $this->settings['cards'] = $this->cards;
		if ($this->cards) {
			$cards = array();
			$pcs = Lookup::paycards();
			foreach ($this->cards as $card) {
				$card = strtolower($card);
				if (isset($pcs[$card])) $cards[] = $pcs[$card];
			}
			$this->cards = $cards;
		}
	}

	/**
	 * Generate the settings UI for the module
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @param string $module The module class name
	 * @param string $name The formal name of the module
	 * @return void
	 **/
	function initui ($module,$name) {
		if (!isset($this->settings['label'])) $this->settings['label'] = $name;
		$this->ui = new GatewaySettingsUI($this,$name);
		$this->settings();
	}

	function uitemplate () {
		$this->ui->template();
	}

	function ui () {
		$editor = $this->ui->generate();
		foreach ($this->settings as $name => $value)
			$data['{$'.$name.'}'] = $value;

		return str_replace(array_keys($data),$data,$editor);
	}

} // END class GatewayFramework


/**
 * GatewayModules class
 *
 * Gateway module file manager to load gateways that are active.
 *
 * @author Jonathan Davis
 * @since 1.1
 * @package shopp
 * @subpackage gateways
 **/
class GatewayModules extends ModuleLoader {

	var $selected = false;		// The chosen gateway to process the order
	var $installed = array();
	var $secure = false;		// SSL-required flag

	/**
	 * Initializes the gateway module loader
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void Description...
	 **/
	function __construct () {

		$this->path = SHOPP_GATEWAYS;

		// Get hooks in place before getting things started
		add_action('shopp_module_loaded',array(&$this,'properties'));

		$this->installed();
		$this->activated();

		add_action('shopp_init',array(&$this,'load'));
	}

	/**
	 * Determines the activated gateway modules
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return array List of module names for the activated modules
	 **/
	function activated () {
		global $Shopp;
		$this->activated = array();
		$gateways = explode(",",shopp_setting('active_gateways'));
		foreach ($this->modules as $gateway)
			if (in_array($gateway->subpackage,$gateways))
				$this->activated[] = $gateway->subpackage;

		return $this->activated;
	}

	/**
	 * Sets Gateway system settings flags based on activated modules
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @param string $module Activated module class name
	 * @return void
	 **/
	function properties ($module) {
		if (!isset($this->active[$module])) return;
		$this->active[$module]->name = $this->modules[$module]->name;
		if ($this->active[$module]->secure) $this->secure = true;
	}

	/**
	 * Get a specified gateway
	 *
	 * @author Jonathan Davis
	 * @since 1.2
	 *
	 * @return void Description...
	 **/
	function &get ($gateway) {
		if (empty($this->active)) $this->settings();
		if (!isset($this->active[$gateway])) return false;
		return $this->active[$gateway];
	}

	/**
	 * Loads all the installed gateway modules for the payments settings
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void
	 **/
	function settings () {
		$this->load(true);
	}

	/**
	 * Initializes the settings UI for each loaded module
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void Description...
	 **/
	function ui () {
		foreach ($this->active as $package => &$module)
			$module->initui($package,$this->modules[$package]->name);
	}

	function templates () {
		foreach ($this->active as $package => &$module)
			$module->uitemplate($package,$this->modules[$package]->name);
	}

} // END class GatewayModules

class GatewaySettingsUI extends ModuleSettingsUI {

	function generate () {

		$_ = array();
		$_[] = '<tr><td colspan="5">';
		$_[] = '<table class="form-table shopp-settings"><tr>';
		$_[] = '<th scope="row" colspan="4">'.$this->name.'<input type="hidden" name="gateway" value="'.$this->module.'" /></th>';
		$_[] = '</tr><tr>';
		$_[] = '<td><input type="text" name="settings['.$this->module.'][label]" value="'.$this->label.'" id="'.$this->id.'-label" size="16" class="selectall" /><br />';
		$_[] = '<label for="'.$this->id.'-label">'.__('Option Name','Shopp').'</label></td>';

		foreach ($this->markup as $markup) {
			$_[] = '<td>';
			if (empty($markup)) $_[] = '&nbsp;';
			else $_[] = join("\n",$markup);
			$_[] = '</td>';
		}

		$_[] = '</tr><tr>';
		$_[] = '<td colspan="4">';
		$_[] = '<p class="textright">';
		$_[] = '<a href="${cancel_href}" class="button-secondary cancel alignleft">'.__('Cancel','Shopp').'</a>';
		$_[] = '<input type="submit" name="save" value="'.__('Save Changes','Shopp').'" class="button-primary" /></p>';
		$_[] = '</td>';
		$_[] = '</tr></table>';
		$_[] = '</td></tr>';

		return join("\n",$_);

	}

}


/**
 * PayCard class
 *
 * Implements structured payment card (credit card) behaviors including
 * card number validation and extra security field requirements.
 *
 * @author Jonathan Davis
 * @since 1.1
 * @package shopp
 * @subpackage gateways
 **/
class PayCard {

	var $name;
	var $symbol;
	var $pattern = false;
	var $csc = false;
	var $inputs = array();

	function __construct ($name,$symbol,$pattern,$csc=false,$inputs=array()) {
		$this->name = $name;
		$this->symbol = $symbol;
		$this->pattern = $pattern;
		$this->csc = $csc;
		$this->inputs = $inputs;
	}

	function validate ($pan) {
		$n = preg_replace('/\D/','',$pan);
		return ($this->match($n) && $this->checksum($n));
	}

	function match ($number) {
		if ($this->pattern && !preg_match($this->pattern,$number)) return false;
		return true;
	}

	function checksum ($number) {
		$code = strrev($number);
		for ($i = 0; $i < strlen($code); $i++) {
			$d = intval($code[$i]);
			if ($i & 1) $d *= 2;
			$cs += $d % 10;
			if ($d > 9) $cs += 1;
		}
		return ($cs % 10 == 0);
	}

}


?>