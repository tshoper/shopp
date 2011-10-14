<?php
/**
 * Order
 *
 * Order data container and middleware object
 *
 * @author Jonathan Davis
 * @version 1.0.1
 * @copyright Ingenesis Limited, January 12, 2010
 * @license GNU GPL version 3 (or later) {@see license.txt}
 * @package shopp
 * @subpackage transact
 **/

/**
 * Order
 *
 * @author Jonathan Davis
 * @since 1.1
 * @package transact
 **/
class Order {

	var $Customer = false;			// The current customer
	var $Shipping = false;			// The shipping address
	var $Billing = false;			// The billing address
	var $Cart = false;				// The shopping cart
	var $data = array();			// Extra/custom order data
	var $payoptions = array();		// List of payment method options
	var $paycards = array();		// List of accepted PayCards
	var $sameship = NULL;			// Flag for using the billing address for shipping

	var $processor = false;			// The payment processor module name
	var $paymethod = false;			// The selected payment method

	// Post processing properties
	var $purchase = false;			// Generated purchase ID
	var $gateway = false;			// Proper name of the gateway used to process the order
	var $txnid = false;				// The transaction ID reported by the gateway
	var $txnstatus = 'PENDING';		// Status of the payment

	// Processing control properties
	var $confirm = false;			// Flag to confirm order or not
	var $confirmed = false;			// Confirmed by the shopper for processing
	var $accounts = false;			// Account system setting
	var $validated = false;			// The pre-processing order validation flag

	/**
	 * Order constructor
	 *
	 * @author Jonathan Davis
	 *
	 * @return void
	 **/
	function __construct () {
		$this->Cart = new Cart();
		$this->Customer = new Customer();
		$this->Billing = new BillingAddress();
		$this->Shipping = new ShippingAddress();

		$this->Shipping->destination();

		$this->created = time();

		$this->listeners();
	}

	/**
	 * Re-establish event listeners and discover the current gateway processor
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void
	 **/
	function __wakeup () {
		$this->listeners();
	}

	function __destruct() {
		$this->unhook();
	}

	/**
	 * Establish event listeners
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void
	 **/
	function listeners () {
		$this->confirm = (shopp_setting('order_confirmation') == 'always');
		$this->accounts = shopp_setting('account_system');
		$this->validated = false; // Reset the order validation flag

		add_action('shopp_process_shipmethod', array($this,'shipmethod'));
		add_action('shopp_process_checkout', array($this,'checkout'));
		add_action('shopp_confirm_order', array($this,'confirmed'));
		add_action('shopp_process_order', array($this,'validate'),7);
		add_action('shopp_process_order', array($this,'process'),100);

		add_action('shopp_process_free_order',array($this,'freebie'));
		add_action('shopp_update_destination',array($this->Shipping,'destination'));
		add_action('shopp_authed_order_event',array($this,'purchase'));
		add_action('shopp_create_purchase',array($this,'purchase'));

		add_action('shopp_order_event',array($this,'event'));
		add_action('shopp_order_notifications',array($this,'notify'));

		add_action('shopp_order_txnstatus_update',array($this,'salestats'),10,2);

		// Schedule for the absolute last action to be run
		add_action('shopp_order_success',array($this,'success'),100);

		add_action('shopp_resession',array($this->Cart,'clear'));
		add_action('shopp_resession',array($this,'clear'));

		// Collect available payment methods from active gateways
		// Schedule for after the gateways are loaded  (priority 20)
		add_action('shopp_init',array($this,'payoptions'),20);

		// Select the default gateway processor
		// Schedule for after the gateways are loaded (priority 20)
		add_action('shopp_init',array($this,'processor'),20);

		// Set locking timeout for concurrency operation protection
		if (!defined('SHOPP_TXNLOCK_TIMEOUT')) define('SHOPP_TXNLOCK_TIMEOUT',10);

	}

	function unhook () {
		remove_action('shopp_authed_order_event',array($this,'purchase'));
		remove_action('shopp_create_purchase',array($this,'purchase'));
		remove_action('shopp_order_notifications',array($this,'notify'));
		remove_action('shopp_order_success',array($this,'success'));
		remove_action('shopp_process_order', array($this,'validate'),7);
		remove_action('shopp_process_order', array($this,'process'),100);

		remove_class_actions(array(
			'shopp_authed_order_event',
			'shopp_create_purchase',
			'shopp_order_notifications',
			'shopp_order_success',
			'shopp_process_order',
			),'GatewayFramework');

	}

	/**
	 * Builds a list of payment method options
	 *
	 * @author Jonathan Davis
	 * @since 1.1.5
	 *
	 * @return void
	 **/
	function payoptions () {
		global $Shopp;
		$accepted = array();

		$options = array();
		foreach ($Shopp->Gateways->active as $gateway) {
			$labels = is_array($gateway->settings['label'])?$gateway->settings['label']:array($gateway->settings['label']);
			$accepted = array_merge($accepted,$gateway->cards());
			foreach ($labels as $method) {
				$_ = new StdClass();
				$_->label = $method;
				$_->processor = $gateway->module;
				$_->cards = array_keys($gateway->cards());
				$handle = sanitize_title_with_dashes($method);
				$options[$handle] = $_;
			}
		}
		$this->paycards = $accepted;
		$this->payoptions = $options;
	}

	/**
	 * Set or get the currently selected gateway processor
	 *
	 * @author Jonathan Davis, John Dillick
	 * @since 1.1
	 *
	 * @return Object|false The currently selected gateway
	 **/
	function processor ($processor=false) {
		global $Shopp;

		// Set the gateway processor from a selected payment method
		if (isset($_POST['paymethod'])) {
			$processor = false;
			if (isset($this->payoptions[$_POST['paymethod']])) {
				$this->paymethod = $_POST['paymethod'];
				$processor = $this->payoptions[$this->paymethod]->processor;
				if (in_array($processor,$Shopp->Gateways->activated)) {
					$this->processor = $processor;
					$this->_paymethod_selected = true;
					 // Prevent unnecessary reprocessing on subsequent calls
					unset($_POST['paymethod']);
				}
			}
			if (!$processor) new ShoppError(__('The payment method you selected is no longer available. Please choose another.','Shopp'));
		}

		if (count($Shopp->Gateways->activated) == 1 // base case
			|| (!$this->processor && !$processor && count($Shopp->Gateways->activated) > 1)) {
			// Automatically select the first active gateway
			reset($Shopp->Gateways->activated);
			$module = current($Shopp->Gateways->activated);
			if ($this->processor != $module)
				$this->processor = $module;
		} elseif (!empty($processor)) { // Change the current processor
			if ($this->processor != $processor && in_array($processor,$Shopp->Gateways->activated))
				$this->processor = $processor;
		}

		if (isset($Shopp->Gateways->active[$this->processor])) {
			$Gateway = $Shopp->Gateways->active[$this->processor];
			$this->gateway = $Gateway->name;

			// Set the paymethod if not set already, or if it has changed
			$gateway_label = is_array($Gateway->settings['label'])?
				$Gateway->settings['label']:array($Gateway->settings['label']);

			if (!$this->paymethod)
				$this->paymethod = sanitize_title_with_dashes($gateway_label[0]);

		}

		return $this->processor;
	}

	/**
	 * Provides the current payment method
	 *
	 * @author Jonathan Davis
	 * @since 1.2
	 *
	 * @return object Payment method object
	 **/
	function paymethod () {
		if (!isset($this->paymethod)) return false;
		if (!isset($this->payoptions[$this->paymethod])) return false;

		return $this->payoptions[$this->paymethod];
	}

	/**
	 * Determine if payment card data has been submitted
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return boolean
	 **/
	function paycard () {
		$ccdata = array('card','cardexpires-mm','cardexpires-yy','cvv');
		foreach ($ccdata as $field)
			if (isset($_POST['billing'][$field])) return true;
		return false;
	}

	/**
	 * Checkout form processing
	 *
	 * Handles taking user input from the checkout form and
	 * processing the information into useable order data
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void
	 **/
	function checkout () {
		global $Shopp;
		$Shopping = ShoppShopping();

		if (!isset($_POST['checkout'])) return;
		if ($_POST['checkout'] != 'process') return;

		$_POST = stripslashes_deep($_POST);

		$cc = $this->paycard();

		if ($cc) {
			$_POST['billing']['cardexpires'] = sprintf('%02d%02d',$_POST['billing']['cardexpires-mm'],$_POST['billing']['cardexpires-yy']);

			// If the card number is provided over a secure connection
			// Change the cart to operate in secure mode
			if (!empty($_POST['billing']['card']) && is_shopp_secure())
				$Shopping->secured(true);

			// Sanitize the card number to ensure it only contains numbers
			if (!empty($_POST['billing']['card']))
				$_POST['billing']['card'] = preg_replace('/[^\d]/','',$_POST['billing']['card']);

		}

		// Remove invlalid characters from the phone number
		$_POST['phone'] = preg_replace('/[^\d\(\)\-+\. (ext|x)]/','',$_POST['phone']);

		if (isset($_POST['data'])) $this->data = $_POST['data'];
		if (isset($_POST['info'])) $this->Customer->info = $_POST['info'];

		if (empty($this->Customer))
			$this->Customer = new Customer();

		$this->Customer->updates($_POST);

		// Keep confirm-password field value when showing checkout validation errors
		if (isset($_POST['confirm-password']))
			$this->Customer->_confirm_password = $_POST['confirm-password'];

		if (empty($this->Billing))
			$this->Billing = new BillingAddress();
		// Default the cardtype to the payment method label selected
		$this->Billing->cardtype = $this->payoptions[$this->paymethod]->label;
		$this->Billing->updates($_POST['billing']);

		// Special case for updating/tracking billing locale
		if (!empty($_POST['billing']['locale']))
			$this->Billing->locale = $_POST['billing']['locale'];

		if ($cc) {
			if (!empty($_POST['billing']['cardexpires-mm']) && !empty($_POST['billing']['cardexpires-yy'])) {
				$exmm = preg_replace('/[^\d]/','',$_POST['billing']['cardexpires-mm']);
				$exyy = preg_replace('/[^\d]/','',$_POST['billing']['cardexpires-yy']);
				$this->Billing->cardexpires = mktime(0,0,0,$exmm,1,($exyy)+2000);
			} else $this->Billing->cardexpires = 0;

			$this->Billing->cvv = preg_replace('/[^\d]/','',$_POST['billing']['cvv']);
			if (!empty($_POST['billing']['xcsc'])) {
				$this->Billing->xcsc = array();
				foreach ($_POST['billing']['xcsc'] as $field => $value) {
					$this->Billing->xcsc[] = $field;
					$this->Billing->{$field} = $value;
				}

			}
		}

		if (!empty($this->Cart->shipped)) {
			if (empty($this->Shipping))
				$this->Shipping = new ShippingAddress();

			if (isset($_POST['shipping'])) $this->Shipping->updates($_POST['shipping']);
			if (!empty($_POST['shipmethod'])) $this->Shipping->method = $_POST['shipmethod'];
			else $this->Shipping->method = key($this->Cart->shipping);

			// Override posted shipping updates with billing address
			if ( isset($_POST['sameshipaddress']) ) {
				if ( $this->sameship = ( 'on' == $_POST['sameshipaddress'] ) ) {
					$this->Shipping->updates($_POST['billing']);
				}
			}
		} else $this->Shipping = new ShippingAddress(); // Use blank shipping for non-Shipped orders

		$freebie = $this->Cart->orderisfree();
		$estimated = $this->Cart->Totals->total;

		$this->Cart->changed(true);
		$this->Cart->totals();

		// Stop here if this is a shipping method update
		if (isset($_POST['update-shipping'])) return;

		if ($this->validform() !== true) return;
		else $this->Customer->updates($_POST); // Catch changes from validation

		do_action('shopp_checkout_processed');

		if (apply_filters('shopp_process_free_order',$this->Cart->orderisfree())) return;

		// Catch originally free orders that get extra (shipping) costs added to them
		if ($freebie && $this->Cart->Totals->total > 0) {

			if ( ! (count($this->payoptions) == 1 // One paymethod
					&& ( isset($this->payoptions[$this->paymethod]->cards) // Remote checkout
						&& empty( $this->payoptions[$this->paymethod]->cards ) ) )
				) {
				new ShoppError(__('Payment information for this order is missing.','Shopp'),'checkout_no_paymethod',SHOPP_ERR);
				shopp_redirect( shoppurl(false,'checkout',$this->security()) );
			}
		}

		// If the cart's total changes at all, confirm the order
		if ($estimated != $this->Cart->Totals->total || $this->confirm)
			shopp_redirect( shoppurl(false,'confirm',$this->security()) );
		else do_action('shopp_process_order');

	}

	/**
	 * Processes changes to the shipping method
	 *
	 * Handles changes to the shipping method outside of other
	 * checkout processes
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void Description...
	 **/
	function shipmethod () {
		if (empty($this->Cart->shipped)) return;
		if (empty($this->Shipping))
				$this->Shipping = new ShippingAddress();

		if ($_POST['shipmethod'] == $this->Shipping->method) return;

		$this->Shipping->method = $_POST['shipmethod'];
		$this->Cart->retotal = true;
		$this->Cart->totals();
	}

	/**
	 * Confirms the order and starts order processing
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void
	 **/
	function confirmed () {

		if ($_POST['checkout'] == 'confirmed') {
			$this->confirmed = true;
			do_action('shopp_process_order');
		}

	}

	/**
	 * Handles processing free orders, overriding any configured gateways
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void
	 **/
	function freebie ($free) {
		if (!$free) return $free;

		$this->gateway = $this->Billing->cardtype = __('Free Order','Shopp');
		$this->transaction(crc32($this->Customer->email.mktime()),'CHARGED');
		return true;
	}

	/**
	 * Generates a Purchase record from the order
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void
	 **/
	function purchase ($Auth) {
		global $Shopp;
		$Shopping = ShoppShopping();

		// No auth message, bail
		if (empty($Auth))
			return (!$error = new ShoppError('Order failure: An empty Authorization message was received by the order processor.','shopp_order_failure',SHOPP_DEBUG_ERR));

		// No transaction ID... dude, really?
		if (empty($Auth->txnid))
			return (!$error = new ShoppError('Order failure: An Authorization message was received by the order processor with an empty transaction ID.','shopp_order_failure',SHOPP_DEBUG_ERR));

		$Purchase = new Purchase($Auth->txnid,'txnid');
		if (!empty($Purchase->id)) {
			$Auth->order = $Purchase->id;
			$Auth->save();
			// // @todo handle new $status (old $status was set in Order::transaction() method)
			// if($status != $Purchase->txnstatus) {
			// 	do_action_ref_array('shopp_order_txnstatus_update',array(&$status,&$Purchase));
			// 	$Purchase->txnstatus = $status;
			// 	$Purchase->save();
			// }
		}

		// Copy details from Auth message
		$this->txnid = $Auth->txnid;
		$this->txnstatus = $Auth->name;
		$this->gateway = $Auth->gateway;
		$this->fees = $this->fees;

		// Lock for concurrency protection
		$this->lock();

		$Purchase = new Purchase($this->txnid,'txnid');
		if (!empty($Purchase->id)) {
			$this->unlock();
			$Shopp->resession();

			$this->purchase = $Purchase->id;
			if ($this->purchase !== false)
				shopp_redirect(shoppurl(false,'thanks'));

		}

		// WordPress account integration used, customer has no wp user
		if ('wordpress' == $this->accounts && empty($this->Customer->wpuser)) {
			if ( $wpuser = get_current_user_id() ) $this->Customer->wpuser = $wpuser; // use logged in WordPress account
			else $this->Customer->create_wpuser(); // not logged in, create new account
		}

		// New customer, save hashed password
		if (!$this->Customer->exists()) {
			$this->Customer->id = false;
			if (SHOPP_DEBUG) new ShoppError('Creating new Shopp customer record','new_customer',SHOPP_DEBUG_ERR);
			if (empty($this->Customer->password)) $this->Customer->password = wp_generate_password(12,true);
			if ('shopp' == $this->accounts) $this->Customer->notification();
			$this->Customer->password = wp_hash_password($this->Customer->password);
		} else unset($this->Customer->password); // Existing customer, do not overwrite password field!

		$this->Customer->save();

		$this->Billing->customer = $this->Customer->id;
		$this->Billing->card = substr($this->Billing->card,-4);
		$paycard = Lookup::paycard($this->Billing->cardtype);
		$this->Billing->cardtype = !$paycard?$this->Billing->cardtype:$paycard->name;
		$this->Billing->cvv = false;
		$this->Billing->save();

		// Card data is truncated, switch the cart to normal mode
		$Shopping->secured(false);

		if (!empty($this->Shipping->address)) {
			$this->Shipping->customer = $this->Customer->id;
			$this->Shipping->save();
		}

		$base = shopp_setting('base_operations');

		$promos = array();
		foreach ($this->Cart->discounts as &$promo) {
			$promos[$promo->id] = $promo->name;
			$promo->uses++;
		}

		$Purchase = new Purchase();
		$Purchase->copydata($this);
		$Purchase->copydata($this->Customer);
		$Purchase->copydata($this->Billing);
		$Purchase->copydata($this->Shipping,'ship');
		$Purchase->copydata($this->Cart->Totals);
		$Purchase->customer = $this->Customer->id;
		$Purchase->billing = $this->Billing->id;
		$Purchase->shipping = $this->Shipping->id;
		$Purchase->taxing = str_true(shopp_setting('tax_inclusive'))?'inclusive':'exclusive';
		$Purchase->promos = $promos;
		$Purchase->freight = $this->Cart->Totals->shipping;
		$Purchase->ip = $Shopping->ip;
		$Purchase->save();
		$this->unlock();
		Promotion::used(array_keys($promos));

		if (empty($Purchase->id)) {
			new ShoppError(__('The order could not be created because of a technical problem on the server. Please try again, or contact the website adminstrator.','Shopp'),'shopp_purchase_save_failure');
			return;
		}

		foreach($this->Cart->contents as $Item) {
			$Purchased = new Purchased();
			$Purchased->copydata($Item);
			$Purchased->price = $Item->option->id;
			$Purchased->purchase = $Purchase->id;
			if (!empty($Purchased->download)) $Purchased->keygen();
			$Purchased->save();
			if ($Item->inventory) $Item->unstock();
		}

		$this->purchase = $Purchase->id;
		$Shopp->Purchase = &$Purchase;

		$Auth->order = $this->purchase;
		$Auth->created = null;
		$Auth->save();

		// Support legacy Auth+Capture transactions for 1.1 Gateways
		if ($Auth->capture || 'CHARGED' == $Purchase->txnstatus) {
			shopp_add_order_event($Purchase->id,'captured',array(
				'txnid' => $Purchase->txnid,				// Can be either the original transaction ID or an ID for this transaction
				'amount' => $Auth->amount,					// Capture of entire order amount
				'fees' => $Purchase->fees,					// Transaction fees taken by the gateway net revenue = amount-fees
				'gateway' => $Auth->gateway					// Gateway handler name (module name from @subpackage)
			));
		}

		if (SHOPP_DEBUG) new ShoppError('Purchase '.$Purchase->id.' was successfully saved to the database.',false,SHOPP_DEBUG_ERR);

		do_action('shopp_order_notifications');

		do_action_ref_array('shopp_order_success',array(&$Shopp->Purchase));
	}

	/**
	 * Recalculates sales stats for products
	 *
	 * Updates the sales stats for products affected by purchase transaction status changes.
	 *
	 * @author Jonathan Davis
	 * @since 1.2
	 *
	 * @param string $status New transaction status being set
	 * @param Purchase $Purchase The affected Purchase object
	 * @return void
	 **/
	function salestats ($status, &$Purchase) {
		if (empty($Purchase->id)) return;

		$products = DatabaseObject::tablename(Product::$table);
		$purchased = DatabaseObject::tablename(Purchased::$table);

		// Transaction status changed
		if ('CHARGED' == $status) // Now CHARGED, add quantity ordered to product 'sold' stat
			$query = "UPDATE $products AS p LEFT JOIN $purchased AS s ON p.id=s.product SET p.sold=p.sold+s.quantity WHERE s.purchase=$Purchase->id";
		elseif ($Purchase->txnstatus == 'CHARGED') // Changed from CHARGED, remove quantity ordered from product 'sold' stat
			$query = "UPDATE $products AS p LEFT JOIN $purchased AS s ON p.id=s.product SET p.sold=p.sold-s.quantity WHERE s.purchase=$Purchase->id";

		$db->query($query);

	}

	/**
	 * Create a lock for transaction processing
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return boolean
	 **/
	function lock () {
		if (empty($this->txnid)) return false;

		$locked = 0;
		for ($attempts = 0; $attempts < 3 && $locked == 0; $attempts++)
			$locked = DB::query("SELECT GET_LOCK('$this->txnid',".SHOPP_TXNLOCK_TIMEOUT.") AS locked",'auto','col','locked');

		if ($locked == 1) return true;

		new ShoppError(sprintf(__('Transaction %s failed. Could not acheive a transaction lock.','Shopp'),$this->txnid),'order_txn_lock',SHOPP_TRXN_ERR);
		shopp_redirect( shoppurl(false,'checkout',$this->security()) );
	}

	/**
	 * Unlocks a transaction lock
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return boolean
	 **/
	function unlock () {
		if (empty($this->txnid)) return false;
		$unlocked = DB::query("SELECT RELEASE_LOCK('$this->txnid') as unlocked",'auto','col','unlocked');
		return ($unlocked == 1)?true:false;
	}

	/**
	 * Send out new order notifications
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void
	 **/
	function notify () {

		// Send email notifications
		// notification(addressee name, email, subject, email template, receipt template)
		ShoppPurchase()->notification(
			ShoppPurchase()->firstname." ".ShoppPurchase()->lastname,
			ShoppPurchase()->email,
			__('Order Receipt','Shopp')
		);
		if (1 != shopp_setting('receipt_copy')) return;

		ShoppPurchase()->notification(
			'',
			shopp_setting('merchant_email'),
			__('New Order','Shopp'),
			'order-merchant.php'		// Use a custom merchant order notification template (if available)
		);
	}

	/**
	 * Sets transaction information to create the purchase record
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 * @deprecated Will be removed in Shopp 1.3
	 *
	 * @param string $id Transaction ID
	 * @param string $status (optional) Transaction status (PENDING, CHARGED, VOID, etc)
	 * @param float $fees (optional) Transaction fees assesed by the processor
	 *
	 * @return true
	 **/
	function transaction ($id,$status='PENDING',$fees=0) {
		$this->txnid = $id;
		$this->txnstatus = $status;
		$this->fees = $fees;

		$Order = ShoppOrder();
		if (empty($id)) return new ShoppError(sprintf('Order failure. %s did not provide a transaction ID.',$Order->processor()),'shopp_order_transaction',SHOPP_DEBUG_ERR);

		$OrderTotals = $Order->Cart->Totals;
		$paymethod = $Order->payoptions[$Order->paymethod]->label;
		$paytype = $Order->Billing->cardtype;
		$payid = $Order->Billing->card;

		$Purchase = new Purchase($id,'txnid');

		$type = false;
		switch ($status) {
			case 'CHARGED': if (!$type) $type = 'captured';
			case 'VOID':	if (!$type) $type = 'voided';
			case 'REFUND':	if (!$type) $type = 'refunded';

				if (empty($Purchase->id)) {
					shopp_add_order_event(false,'authed',array(
						'txnid' => $id,
						'amount' => (float)$OrderTotals->total,
						'fees' => (float)$fees,
						'gateway' => $Order->processor(),
						'paymethod' => $paymethod,
						'paytype' => $paytype,
						'payid' => $payid
					));
					$Purchase = new Purchase($id,'txnid');
				}

				shopp_add_order_event($Purchase->id,$type,array(
					'txnid' => $id,								// Can be either the original transaction ID or an ID for this transaction
					'amount' => $Purchase->total,				// Capture of entire order amount (limitation of 1.1 payment gateways)
					'gateway' => $Order->processor()			// Gateway handler name (module name from @subpackage)
				));
				break;

			case 'PENDING':
			default:
				shopp_add_order_event(false,'authed',array(
					'txnid' => $id,
					'amount' => $OrderTotals->total,
					'fees' => $fees,
					'gateway' => $Order->processor(),
					'paymethod' => $paymethod,
					'paytype' => $paytype,
					'payid' => $payid
				));
		}

	}

	/**
	 * Order processing decides the type of request to make
	 *
	 * Decides with operation to request:
	 * Authorization - Get authorization to charge the order amount with the payment method provided
	 * Sale - Get authorization and immediate capture (charge) of the payment
	 *
	 * @author Jonathan Davis
	 * @since 1.2
	 *
	 * @return void Description...
	 **/
	function process () {

		$process = 'sale';	// No shipped products
		if ($this->Cart->shipped) // There are shipped products
			$process = 'auth';

		shopp_add_order_event(false,$process,array(
			'gateway' => $this->processor(),
			'amount' => $this->Cart->Totals->total
		));
	}

	/**
	 * Resets the session and redirects to the thank you page
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void
	 **/
	function success () {
		global $Shopp;

		$Shopp->resession();

		if ($this->purchase !== false)
			shopp_redirect(shoppurl(false,'thanks'));
	}

	/**
	 * Validate the checkout form data before processing the order
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return boolean Status of valid checkout data
	 **/
	function validform () {

		if (apply_filters('shopp_firstname_required',empty($_POST['firstname'])))
			return new ShoppError(__('You must provide your first name.','Shopp'),'cart_validation');

		if (apply_filters('shopp_lastname_required',empty($_POST['lastname'])))
			return new ShoppError(__('You must provide your last name.','Shopp'),'cart_validation');

		$rfc822email =	'([^\\x00-\\x20\\x22\\x28\\x29\\x2c\\x2e\\x3a-\\x3c\\x3e\\x40\\x5b-\\x5d\\x7f-\\xff]+|\\x22([^\\x0d'.
						'\\x22\\x5c\\x80-\\xff]|\\x5c[\\x00-\\x7f])*\\x22)(\\x2e([^\\x00-\\x20\\x22\\x28\\x29\\x2c\\x2e'.
						'\\x3a-\\x3c\\x3e\\x40\\x5b-\\x5d\\x7f-\\xff]+|\\x22([^\\x0d\\x22\\x5c\\x80-\\xff]|\\x5c[\\x00-\\x7f])*'.
						'\\x22))*\\x40([^\\x00-\\x20\\x22\\x28\\x29\\x2c\\x2e\\x3a-\\x3c\\x3e\\x40\\x5b-\\x5d\\x7f-\\xff]+'.
						'|\\x5b([^\\x0d\\x5b-\\x5d\\x80-\\xff]|\\x5c[\\x00-\\x7f])*\\x5d)(\\x2e([^\\x00-\\x20\\x22\\x28'.
						'\\x29\\x2c\\x2e\\x3a-\\x3c\\x3e\\x40\\x5b-\\x5d\\x7f-\\xff]+|\\x5b([^\\x0d\\x5b-\\x5d\\x80-\\xff]'.
						'|\\x5c[\\x00-\\x7f])*\\x5d))*';
		if(apply_filters('shopp_email_valid',!preg_match("!^$rfc822email$!", $_POST['email'])))
			return new ShoppError(__('You must provide a valid e-mail address.','Shopp'),'cart_validation');

		if (apply_filters(' shopp_clickwrap_required',isset($_POST['data']['clickwrap']) && 'agreed' !== $_POST['data']['clickwrap']) )
			return new ShoppError(__('You must agree to the terms of sale.','Shopp'),'checkout_validation');

		if ($this->accounts == "wordpress" && !$this->Customer->logged_in()) {
			require(ABSPATH."/wp-includes/registration.php");

			// Validate possible wp account names for availability
			if(isset($_POST['loginname'])){
				if(apply_filters('shopp_login_exists',username_exists($_POST['loginname'])))
					return new ShoppError(__('The login name you provided is not available.  Try logging in if you have previously created an account.'), 'cart_validation');
			} else { // need to find a usuable login
				list($handle,$domain) = explode("@",$_POST['email']);
				if(!username_exists($handle)) $_POST['loginname'] = $handle;

				$handle = $_POST['firstname'].substr($_POST['lastname'],0,1);
				if(!isset($_POST['loginname']) && !username_exists($handle)) $_POST['loginname'] = $handle;

				$handle = substr($_POST['firstname'],0,1).$_POST['lastname'];
				if(!isset($_POST['loginname']) && !username_exists($handle)) $_POST['loginname'] = $handle;

				$handle .= rand(1000,9999);
				if(!isset($_POST['loginname']) && !username_exists($handle)) $_POST['loginname'] = $handle;

				if(apply_filters('shopp_login_required',!isset($_POST['loginname'])))
					return new ShoppError(__('A login is not available for creation with the information you provided.  Please try a different email address or name, or try logging in if you previously created an account.'),'cart_validation');
			}
			if(SHOPP_DEBUG) new ShoppError('Login set to '. $_POST['loginname'] . ' for WordPress account creation.',false,SHOPP_DEBUG_ERR);
			$ExistingCustomer = new Customer($_POST['email'],'email');
			if (apply_filters('shopp_email_exists',(email_exists($_POST['email']) || !empty($ExistingCustomer->id))))
				return new ShoppError(__('The email address you entered is already in use. Try logging in if you previously created an account, or enter another email address to create your new account.','Shopp'),'cart_validation');
		} elseif ($this->accounts == "shopp"  && !$this->Customer->logged_in()) {
			$ExistingCustomer = new Customer($_POST['email'],'email');
			if (apply_filters('shopp_email_exists',!empty($ExistingCustomer->id)))
				return new ShoppError(__('The email address you entered is already in use. Try logging in if you previously created an account, or enter another email address to create a new account.','Shopp'),'cart_validation');
		}

		// Validate WP account
		if (apply_filters('shopp_login_required',(isset($_POST['loginname']) && empty($_POST['loginname']))))
			return new ShoppError(__('You must enter a login name for your account.','Shopp'),'cart_validation');

		if (isset($_POST['loginname'])) {
			require(ABSPATH."/wp-includes/registration.php");
			if (apply_filters('shopp_login_valid',(!validate_username($_POST['loginname']))))
				return new ShoppError(__('This login name is invalid because it uses illegal characters. Please enter a valid login name.','Shopp'),'cart_validation');

			if (apply_filters('shopp_login_exists',username_exists($_POST['loginname'])))
				return new ShoppError(__('The login name is already in use. Try logging in if you previously created that account, or enter another login name for your new account.','Shopp'),'cart_validation');
		}

		if (isset($_POST['password'])) {
			if (apply_filters('shopp_passwords_required',(empty($_POST['password']) || empty($_POST['confirm-password']))))
				return new ShoppError(__('You must provide a password for your account and confirm it to ensure correct spelling.','Shopp'),'cart_validation');
			if (apply_filters('shopp_password_mismatch',($_POST['password'] != $_POST['confirm-password']))) {
				$_POST['password'] = ""; $_POST['confirm-password'] = "";
				return new ShoppError(__('The passwords you entered do not match. Please re-enter your passwords.','Shopp'),'cart_validation');
			}
		}

		if (apply_filters('shopp_billing_address_required',(empty($_POST['billing']['address']) || strlen($_POST['billing']['address']) < 4)))
			return new ShoppError(__('You must enter a valid street address for your billing information.','Shopp'),'cart_validation');

		if (apply_filters('shopp_billing_postcode_required',empty($_POST['billing']['postcode'])))
			return new ShoppError(__('You must enter a valid postal code for your billing information.','Shopp'),'cart_validation');

		if (apply_filters('shopp_billing_country_required',empty($_POST['billing']['country'])))
			return new ShoppError(__('You must select a country for your billing information.','Shopp'),'cart_validation');

		// Skip validating payment details for purchases not requiring a
		// payment (credit) card including free orders, remote checkout systems, etc
		if (!$this->paycard()) return apply_filters('shopp_validate_checkout',true);

		if (apply_filters('shopp_billing_card_required',empty($_POST['billing']['card'])))
			return new ShoppError(__('You did not provide a credit card number.','Shopp'),'cart_validation');

		if (apply_filters('shopp_billing_cardtype_required',empty($_POST['billing']['cardtype'])))
			return new ShoppError(__('You did not select a credit card type.','Shopp'),'cart_validation');

		$card = Lookup::paycard(strtolower($_POST['billing']['cardtype']));
		if (!$card) return apply_filters('shopp_validate_checkout',true);
		if (apply_filters('shopp_billing_valid_card',!$card->validate($_POST['billing']['card'])))
			return new ShoppError(__('The credit card number you provided is invalid.','Shopp'),'cart_validation');

		if (apply_filters('shopp_billing_cardexpires_month_required',empty($_POST['billing']['cardexpires-mm'])))
			return new ShoppError(__('You did not enter the month the credit card expires.','Shopp'),'cart_validation');

		if (apply_filters('shopp_billing_cardexpires_year_required',empty($_POST['billing']['cardexpires-yy'])))
			return new ShoppError(__('You did not enter the year the credit card expires.','Shopp'),'cart_validation');

		if (apply_filters('shopp_billing_card_expired',(!empty($_POST['billing']['cardexpires-mm']) && !empty($_POST['billing']['cardexpires-yy'])))
		 	&& $_POST['billing']['cardexpires-mm'] < date('n') && $_POST['billing']['cardexpires-yy'] <= date('y'))
			return new ShoppError(__('The credit card expiration date you provided has already expired.','Shopp'),'cart_validation');

		if (apply_filters('shopp_billing_cardholder_required',strlen($_POST['billing']['cardholder']) < 2))
			return new ShoppError(__('You did not enter the name on the credit card you provided.','Shopp'),'cart_validation');

		if (apply_filters('shopp_billing_cvv_required',strlen($_POST['billing']['cvv']) < 3))
			return new ShoppError(__('You did not enter a valid security ID for the card you provided. The security ID is a 3 or 4 digit number found on the back of the credit card.','Shopp'),'cart_validation');

		return apply_filters('shopp_validate_checkout',true);
	}


	function validate () {
		if (apply_filters('shopp_valid_order',$this->isvalid())) return true;
		$Shopping = ShoppShopping();
		$Shopping->save();
		shopp_redirect( shoppurl(false,'checkout',$this->security()) );
	}

	/**
	 * Validate order data before transaction processing
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return boolean Validity of the order
	 **/
	function isvalid ($report=true) {
		$Customer = $this->Customer;
		$Shipping = $this->Shipping;
		$Cart = $this->Cart;
		$errors = 0;
		$valid = true;

		if (SHOPP_DEBUG) new ShoppError('Validating order data for processing',false,SHOPP_DEBUG_ERR);

		if (empty($Cart->contents)) {
			$valid = apply_filters('shopp_ordering_empty_cart',false);
			new ShoppError(__("There are no items in the cart."),'invalid_order'.$errors++,($report?SHOPP_TRXN_ERR:SHOPP_DEBUG_ERR));
		}

		$stock = true;
		foreach ($Cart->contents as $item) {
			if (!$item->instock()){
				$valid = apply_filters('shopp_ordering_items_outofstock',false);
				new ShoppError(sprintf(__("%s does not have sufficient stock to process order."),
				$item->name . ($item->option->label?" ({$item->option->label})":"")),
				'invalid_order'.$errors++,($report?SHOPP_TRXN_ERR:SHOPP_DEBUG_ERR));
				$stock = false;
			}
		}

		$valid_customer = true;
		if (!$Customer) $valid_customer = apply_filters('shopp_ordering_empty_customer',false); // No Customer

		// Always require name and email
		if (empty($Customer->firstname)) $valid_customer = apply_filters('shopp_ordering_empty_firstname',false);
		if (empty($Customer->lastname)) $valid_customer = apply_filters('shopp_ordering_empty_lastname',false);
		if (empty($Customer->email)) $valid_customer = apply_filters('shopp_ordering_empty_email',false);

		if (!$valid_customer) {
			$valid = false;
			new ShoppError(__('There is not enough customer information to process the order.','Shopp'),'invalid_order'.$errors++,($report?SHOPP_TRXN_ERR:SHOPP_DEBUG_ERR));
		}

		// Check for shipped items but no Shipping information
		$valid_shipping = true;
		if (!empty($this->Cart->shipped) && !$this->Cart->noshipping) {
			if (empty($Shipping->address))
				$valid_shipping = apply_filters('shopp_ordering_empty_shipping_address',false);
			if (empty($Shipping->country))
				$valid_shipping = apply_filters('shopp_ordering_empty_shipping_country',false);
			if (empty($Shipping->postcode))
				$valid_shipping = apply_filters('shopp_ordering_empty_shipping_postcode',false);

			if ($Cart->freeshipping === false && $Cart->Totals->shipping === false) {
				$valid = apply_filters('shopp_ordering_no_shipping_costs',false);
				$message = __('The order cannot be processed. No shipping costs could be determined. Either the shipping rate provider was unavailable, or may have rejected the shipping address you provided. Please return to %scheckout%s and try again.', 'Shopp');
				if (!$valid) new ShoppError( sprintf( $message,'<a href="'.shoppurl(false,'checkout',$this->security()).'">','</a>' ), 'invalid_order'.$errors++, ($report?SHOPP_TRXN_ERR:SHOPP_DEBUG_ERR)
				);
			}

		}
		if (!$valid_shipping) {
			$valid = false;
			new ShoppError(__('The shipping address information is incomplete. The order cannot be processed.','Shopp'),'invalid_order'.$errors++,($report?SHOPP_TRXN_ERR:SHOPP_DEBUG_ERR));
		}

		return $valid;
	}

	/**
	 * Evaluates if checkout process needs to be secured
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return boolean Whether the checkout form should be secured
	 **/
	function security () {
		global $Shopp;
		return $Shopp->Gateways->secure || is_shopp_secure();
	}

	/**
	 * Clear order-specific information to prepare for a new order
	 *
	 * @author Jonathan Davis
	 * @since 1.1.5
	 *
	 * @return void
	 **/
	function clear () {
		$this->data = array();			// Custom order data
		$this->txnid = false;			// The transaction ID reported by the gateway
		$this->gateway = false;			// Proper name of the gateway used to process the order
		$this->txnstatus = "PENDING";	// Status of the payment
		$this->confirmed = false;		// Confirmed by the shopper for processing
	}

	function event ($Event) {
		$updates = array('captured','refunded','voided');
		if (!in_array($Event->name,$updates)) return;

		$Purchase = new Purchase($Event->order);
		$Purchase->txnstatus = $Event->name;
		// if (isset())
		// $Purchase->status =
		$Purchase->save();
	}

} // END class Order

/**
 * Helper to access the Shopping Order
 *
 * @author Jonathan Davis
 * @since 1.0
 *
 * @return Order
 **/
function &ShoppOrder () {
	global $Shopp;
	return $Shopp->Order;
}


/**
 * Provides a unified interface for generating and accessing system order events
 *
 * @author Jonathan Davis
 * @since 1.2
 * @package shopp
 * @subpackage orderevent
 **/
class OrderEvent extends SingletonFramework {

	private static $instance;
	private $handlers = array();

	static function instance () {
		if (!self::$instance instanceof self)
			self::$instance = new self;
		return self::$instance;
	}

	static function register ($type,$class) {
		$Dispatch = self::instance();
		$Dispatch->handlers[$type] = $class;
	}

	static function add ($order,$type,$message=array()) {
		$Dispatch = self::instance();

		if (!isset($Dispatch->handlers[$type]))
			return trigger_error('OrderEvent type "'.$type.'" does not exist.',E_USER_ERROR);

		$Event = $Dispatch->handlers[$type];
		$message['order'] = $order;
		$OrderEvent = new $Event($message);
		if (!isset($OrderEvent->_exception)) return $OrderEvent;
		return false;
	}

	static function events ($order) {
		$Dispatch = self::instance();
		$Object = new OrderEventMessage();
		$meta = $Object->_table;
		$query = "SELECT *
					FROM $meta
					WHERE context='$Object->context'
						AND type='$Object->type'
						AND parent='$order'
					ORDER BY created";
		return DB::query($query,'array',array($Object,'loader'),'name');
	}

	static function handler ($name) {
		$Dispatch = self::instance();
		if (isset($Dispatch->handlers[$name]))
			return $Dispatch->handlers[$name];
	}

}

/**
 * Defines the base message protocol for the Shopp Order Event subsystem.
 *
 * @author Jonathan Davis
 * @since 1.2
 * @package shopp
 * @subpackage orderevent
 **/
class OrderEventMessage extends MetaObject {

	// Mapped properties should be added (not exclude standard properties)
	var $_addmap = true;
	var $_map = array('order' => 'parent','amount' => 'numeral');
	var $_xcols = array();
	var $context = 'purchase';
	var $type = 'event';

	var $order = false;
	var $amount = 0.0;

	function __construct ($data=false) {
		$this->init(self::$table);
		if (!$data) return;

		$message = $this->msgprops();

		if (is_int($data)) $this->load($data);

 		$this->context = 'purchase';
		$this->type = 'event';

		if (!is_array($data)) return;

		/* Creating a new event */
		$data = $this->filter($data);

		// Ensure the data is provided
		$missing = array_diff($this->_xcols,array_keys($data));

		if (!empty($missing)) {
			$params = array();
			foreach ($missing as $key) $params[] = "'$key' [{$message[$key]}]";
			trigger_error(sprintf('Required %s parameters missing (%s)',get_class($this),join(', ',$params)),E_USER_ERROR);
			return $this->_exception = true;
		}

		// Automatically populate the object and save it
		$this->copydata($data);
		$this->save();

		if (empty($this->id)) {
			new ShoppError(sprintf('An error occured while saving a new %s',get_class($this)),false,SHOPP_DEBUG_ERR);
			return $this->_exception = true;
		}

		$action = sanitize_title_with_dashes($this->name);

		do_action_ref_array('shopp_order_event',array($this));
		do_action_ref_array('shopp_'.$action.'_order_event',array($this));

		if (isset($this->gateway)) {
			$gateway = sanitize_title_with_dashes($this->gateway);
			do_action_ref_array('shopp_'.$gateway.'_'.$action,array($this));
		}

		new ShoppError(sprintf('%s dispatched.',get_class($this)),false,SHOPP_DEBUG_ERR);

	}

	function msgprops () {
		$message = $this->message;
		unset($this->message);
		if (isset($message) && !empty($message)) {
			foreach ($message as $property => &$default) {
				$this->$property = false;
				$this->_xcols[] = $property;
				$default = $this->datatype($default);
			}
		}
		return $message;
	}

	function datatype ($var) {
        if (is_array($var)) return 'array';
        if (is_bool($var)) return 'boolean';
        if (is_float($var)) return 'float';
        if (is_int($var)) return 'integer';
        if (is_null($var)) return 'NULL';
        if (is_numeric($var)) return 'numeric';
        if (is_object($var)) return 'object';
        if (is_resource($var)) return 'resource';
        if (is_string($var)) return 'string';
        return 'unknown type';
	}

	/**
	 * Callback for loading concrete OrderEventMesssage objects from a record set
	 *
	 * @author Jonathan Davis
	 * @since 1.2
	 *
	 * @param array $records A reference to the loaded record set
	 * @param object $record Result record data object
	 * @return void
	 **/
	function loader (&$records,&$record,$type=false,$index='id',$collate=false) {
		if ($type !== false && isset($record->$type) && class_exists(OrderEvent::handler($record->$type))) {
			$OrderEventClass = OrderEvent::handler($record->$type);
		} elseif (isset($this)) {
			if ($index == 'id') $index = $this->_key;
			$OrderEventClass = get_class($this);
		}
		$index = isset($record->$index)?$record->$index:'!NO_INDEX!';
		$Object = new $OrderEventClass();
		$Object->msgprops();
		$Object->populate($record);
		if (method_exists($Object,'expopulate'))
			$Object->expopulate();

		if ($collate) {
			if (!isset($records[$index])) $records[$index] = array();
			$records[$index][] = $Object;
		} else $records[$index] = $Object;
	}

	function filter ($msg) {
		return $msg;
	}


} // END class OrderEvent

/**
 * Intermediary class to set the message as a posting CREDIT transaction
 *
 * @author Jonathan Davis
 * @since 1.2
 * @package shopp
 * @subpackage orderevent
 **/
class CreditOrderEventMessage extends OrderEventMessage {
	var $transactional = true;	// Mark the order event as a balance adjusting event
	var $credit = true;
	var $debit = false;
}

/**
 * Intermediary class to set the message as a posting DEBIT transaction
 *
 * @author Jonathan Davis
 * @since 1.2
 * @package shopp
 * @subpackage orderevent
 **/
class DebitOrderEventMessage extends OrderEventMessage {
	var $transactional = true;	// Mark the order event as a balance adjusting event
	var $debit = true;
	var $credit = false;
}

/**
 * Shopper initiated authorization command message
 *
 * Triggers the gateway(s) responsible for the order to initiate a payment
 * authorization request
 *
 * @author Jonathan Davis
 * @since 1.2
 * @package shopp
 * @subpackage orderevent
 **/
class AuthOrderEvent extends OrderEventMessage {
	var $name = 'auth';
	var $message = array(
		'gateway' => '',		// Gateway (class name) to process authorization through
		'amount' => 0.0			// Amount to capture (charge)
	);
}
OrderEvent::register('auth','AuthOrderEvent');

/**
 * Payment authorization message
 *
 * This message is the key message that begins the order creation process
 * (generating a Purchase record). When the payment is Authed, the billing agreement
 * is in place allowing Shopp to "Invoice" against (debit) the purchase total.
 *
 * In accounting terms the debit is against the merchant's account receivables and
 * sales accounts indicating an amount owed to the merchant by a customer.
 *
 * When generating an AuthedOrderEvent message using shopp_add_order_event() in a
 * payment gateway, it is necessary to pass a (boolean) false value as the first
 * ($order) parameter since the purchase record is created against the AuthedOrderEvent
 * message.
 *
 * Example: shopp_add_order_event(false,'auth',array(...));
 *
 * @author Jonathan Davis
 * @since 1.2
 * @package shopp
 * @subpackage orderevent
 **/
class AuthedOrderEvent extends DebitOrderEventMessage {
	var $name = 'authed';
	var $capture = false;
	var $message = array(
		'txnid' => '',			// Transaction ID
		'amount' => 0.0,		// Gross amount authorized
		'gateway' => '',		// Gateway handler name (module name from @subpackage)
		'paymethod' => '',		// Payment method (payment method label from payment settings)
		'paytype' => '',		// Type of payment (check, MasterCard, etc)
		'payid' => ''			// Payment ID (last 4 of card or check number)
	);

	function filter ($msg) {
		if (isset($msg['capture']) && true === $msg['capture'])
			$this->capture = true;

		if (empty($msg['payid'])) return $msg;
		$paycards = Lookup::paycards();
		foreach ($paycards as $card) { // If it looks like a payment card number, truncate it
			if (!empty($msg['payid']) && $card->match($msg['payid']) && $msg['paytype'] == $card->name);
				$msg['payid'] = substr($msg['payid'],-4);
		}

		return $msg;
	}
}
OrderEvent::register('authed','AuthedOrderEvent');

/**
 * Shopper initiated authorization and capture command message
 *
 * Triggers the gateway(s) responsible for the order to initiate a payment
 * authorization request with capture
 *
 * @author Jonathan Davis
 * @since 1.2
 * @package shopp
 * @subpackage orderevent
 **/
class SaleOrderEvent extends OrderEventMessage {
	var $name = 'sale';
	var $message = array(
		'gateway' => '',		// Gateway (class name) to process authorization through
		'amount' => 0.0			// Amount to capture (charge)
	);
}
OrderEvent::register('sale','SaleOrderEvent');

/**
 * Recurring billing payment message
 *
 * The rebill message is used to adjust the running balance for an order to accommodate
 * a new recurring payment event. It debits the order so the RecapturedOrderEvent
 * credit can apply against it and keep the account balanced.
 *
 * @author Jonathan Davis
 * @since 1.2
 * @package shopp
 * @subpackage orderevent
 **/
class RebillOrderEvent extends DebitOrderEventMessage {
	var $name = 'rebill';
	var $message = array(
		'txnid' => '',			// Transaction ID
		'gateway' => '',		// Gateway class name (module name from @subpackage)
		'amount' => 0.0,		// Gross amount authorized
		'fees' => 0.0,			// Transaction fees taken by the gateway net revenue = amount-fees
		'paymethod' => '',		// Payment method (check, MasterCard, etc)
		'payid' => ''			// Payment ID (last 4 of card or check number)
	);
}
OrderEvent::register('rebill','RebillOrderEvent');

/**
 * Merchant initiated capture command message
 *
 * Triggers the gateway(s) responsible for the order to initiate a capture
 * request to capture the previously authorized amount.
 *
 * @author Jonathan Davis
 * @since 1.2
 * @package shopp
 * @subpackage orderevent
 **/
class CaptureOrderEvent extends OrderEventMessage {
	var $name = 'capture';
	var $message = array(
		'txnid' => '',			// Transaction ID of the prior AuthedOrderEvent
		'gateway' => '',		// Gateway (class name) to process capture through
		'amount' => 0.0,		// Amount to capture (charge)
		'user' => 0				// User for user-initiated captures
	);
}
OrderEvent::register('capture','CaptureOrderEvent');

/**
 * Captured funds message
 *
 * This message notifies the Shopp order system that funds were successfully
 * captured by the responsible gateway. It is typically fired by the gateway
 * after receiving the payment gateway server response from a
 * CaptureOrderEvent initiated capture request.
 *
 * A CapturedOrderEvent will credit the merchant's accounts receivable cancelling the
 * debit of an AuthedOrderEvent message.
 *
 * @author Jonathan Davis
 * @since 1.2
 * @package shopp
 * @subpackage orderevent
 **/
class CapturedOrderEvent extends CreditOrderEventMessage {
	var $name = 'captured';
	var $message = array(
		'txnid' => '',			// Transaction ID of the CAPTURE event
		'amount' => 0.0,		// Amount captured
		'fees' => 0.0,			// Transaction fees taken by the gateway net revenue = amount-fees
		'gateway' => ''			// Gateway handler name (module name from @subpackage)
	);
}
OrderEvent::register('captured','CapturedOrderEvent');

/**
 * Recurring payment captured message
 *
 * A recaptured message notifies the Shopp order system that funds were successfully
 * captured by the responsible gateway in connection with a recurring billing agreement.
 *
 * A RecaptureOrderEvent is triggered by a payment gateway when it receives a
 * remote notification message from the upstream payment gateway server that a recurring
 * payment has been successfully processed.
 *
 * A RebillOrderEvent must be triggered against the Purchase record first before
 * adding the RecapturedOrderEvent so that running balance remains accurate.
 *
 * Similar to the CapturedOrderEvent, the RecapturedOrderEvent is a payment received that
 * credits the merchant's accounts receivable.
 *
 * @author Jonathan Davis
 * @since 1.2
 * @package shopp
 * @subpackage orderevent
 **/
class RecapturedOrderEvent extends CreditOrderEventMessage {
	var $name = 'recaptured';
	var $message = array(
		'txnorigin' => '',		// Original transaction ID (txnid of original Purchase record)
		'txnid' => '',			// Transaction ID of the recurring payment event
		'amount' => 0.0,		// Amount captured
		'gateway' => '',		// Gateway handler name (module name from @subpackage)
		'balance' => 0.0,		// Balance of the billing agreement
		'nextdate' => 0,		// Timestamp of the next scheduled payment
		'status' => ''			// Status of the billing agreement
	);
}
OrderEvent::register('recaptured','RecapturedOrderEvent');

/**
 * Merchant initiated refund command message
 *
 * Triggers the responsible payment gateway to initiate a refund request to the
 * payment gateway server.
 *
 * @author Jonathan Davis
 * @since 1.2
 * @package shopp
 * @subpackage orderevent
 **/
class RefundOrderEvent extends OrderEventMessage {
	var $name = 'refund';
	var $message = array(
		'txnid' => '',
		'gateway' => '',		// Gateway (class name) to process refund through
		'amount' => 0.0,
		'user' => 0,
		'reason' => 0
	);

	function filter ($msg) {
		$reasons = shopp_setting('cancel_reasons');
		$msg['reason'] = $reasons[ $msg['reason'] ];
		return $msg;
	}

}
OrderEvent::register('refund','RefundOrderEvent');

/**
 * Refunded amount message
 *
 * This event message indicates a successful refund that re-debits the merchant's
 * account receivables.
 *
 * This message will cause Shopp's order system to automatically add a VoidedOrderEvent
 * to apply to the order in order to keep an accurate account balance.
 *
 * @author Jonathan Davis
 * @since 1.2
 * @package shopp
 * @subpackage orderevent
 **/
class RefundedOrderEvent extends DebitOrderEventMessage {
	var $name = 'refunded';
	var $message = array(
		'txnid' => '',			// Transaction ID for the REFUND event
		'amount' => 0.0,		// Amount refunded
		'gateway' => ''			// Gateway handler name (module name from @subpackage)
	);
}
OrderEvent::register('refunded','RefundedOrderEvent');

/**
 * Merchant initiated void command message
 *
 * Used to cancel an order prior to successful capture. This triggers the responsible gateway to
 * initiate a void request.
 *
 * @author Jonathan Davis
 * @since 1.2
 * @package shopp
 * @subpackage orderevent
 **/
class VoidOrderEvent extends OrderEventMessage {
	var $name = 'void';
	var $message = array(
		'txnid' => 0,			// Transaction ID for the authorization
		'gateway' => '',		// Gateway (class name) to process capture through
		'user' => 0,			// The WP user ID processing the void
		'reason' => 0			// The reason code
	);

	function filter ($msg) {
		$reasons = shopp_setting('cancel_reasons');
		$msg['reason'] = $reasons[ $msg['reason'] ];
		return $msg;
	}

}
OrderEvent::register('void','VoidOrderEvent');

/**
 * Used to cancel the balance of an order from either an Authed or Refunded event
 *
 * @author Jonathan Davis
 * @since 1.2
 * @package shopp
 * @subpackage orderevent
 **/
class VoidedOrderEvent extends CreditOrderEventMessage {
	var $name = 'voided';
	var $message = array(
		'txnorigin' => '',		// Original transaction ID (txnid of original Purchase record)
		'txnid' => '',			// Transaction ID for the VOID event
		'gateway' => ''			// Gateway handler name (module name from @subpackage)
	);
}
OrderEvent::register('voided','VoidedOrderEvent');


/**
 * Failure messages
 *
 * Failure messages log transaction attempt failures which may be caused by
 * communication errors or another problem with the request (not enough funds,
 * security declines, etc)
 **/

class AuthFailOrderEvent extends OrderEventMessage {
	var $name = 'auth-fail';
	var $message = array(
		'amount' => 0.0,		// Amount to be authorized
		'error' => '',			// Error code (if provided)
		'message' => '',		// Error message reported by the gateway
		'gateway' => '',		// Gateway handler name (module name from @subpackage)
		'paymethod' => '',		// Payment method (check, MasterCard, etc)
		'payid' => ''			// Payment ID (last 4 of card or check number)
	);
}
OrderEvent::register('auth-fail','AuthFailOrderEvent');

class CaptureFailOrderEvent extends OrderEventMessage {
	var $name = 'capture-fail';
	var $message = array(
		'amount' => 0.0,		// Amount to be captured
		'error' => '',			// Error code (if provided)
		'message' => '',		// Error message reported by the gateway
		'gateway' => ''			// Gateway handler name (module name from @subpackage)
	);
}
OrderEvent::register('capture-fail','CaptureFailOrderEvent');

class RecaptureFailOrderEvent extends OrderEventMessage {
	var $name = 'recapture-fail';
	var $message = array(
		'amount' => 0.0,		// Amount of the recurring payment
		'error' => '',			// Error code (if provided)
		'message' => '',		// Error message reported by the gateway
		'gateway' => '',		// Gateway handler name (module name from @subpackage)
		'retrydate' => 0		// Timestamp of the next attempt to recapture
	);
}
OrderEvent::register('recapture-fail','RecaptureFailOrderEvent');

class RefundFailOrderEvent extends OrderEventMessage {
	var $name = 'refund-fail';
	var $message = array(
		'amount' => 0.0,		// Amount to be refunded
		'error' => '',			// Error code (if provided)
		'message' => '',		// Error message reported by the gateway
		'gateway' => ''			// Gateway handler name (module name from @subpackage)
	);
}
OrderEvent::register('refund-fail','RefundFailOrderEvent');

class VoidFailOrderEvent extends OrderEventMessage {
	var $name = 'void-fail';
	var $message = array(
		'error' => '',			// Error code (if provided)
		'message' => '',		// Error message reported by the gateway
		'gateway' => ''			// Gateway handler name (module name from @subpackage)
	);
}
OrderEvent::register('void-fail','VoidFailOrderEvent');

/**
 * Logs manual processing decryption events
 *
 * @author Jonathan Davis
 * @since 1.2
 * @package shopp
 * @subpackage orderevent
 **/
class DecryptOrderEvent extends OrderEventMessage {
	var $name = 'decrypt';
	var $message = array(
		'user' => 0				// WordPress user id
	);
}
OrderEvent::register('decrypt','DecryptOrderEvent');

/**
 * Logs shipment events
 *
 * @author Jonathan Davis
 * @since 1.2
 * @package shopp
 **/
class ShippedOrderEvent extends OrderEventMessage {
	var $name = 'shipped';
	var $message = array(
		'tracking' => '',		// Tracking number (you know, for tracking)
		'carrier' => '',		// Carrier ID (name, eg. UPS, USPS, FedEx)
	);
}
OrderEvent::register('shipped','ShippedOrderEvent');

/**
 * Logs download access
 *
 * @author Jonathan Davis
 * @since 1.2
 * @package shopp
 **/
class DownloadOrderEvent extends OrderEventMessage {
	var $name = 'download';
	var $message = array(
		'purchased' => 0,		// Purchased line item ID (or add-on meta record ID)
		'download' => 0,		// Download ID (meta record)
		'ip' => '',				// IP address of the download
		'customer' => 0			// Authenticated customer
	);
}
OrderEvent::register('download','DownloadOrderEvent');

?>