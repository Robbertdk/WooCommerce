<?php

require_once 'library/include.php';
require_once(dirname(__FILE__) . '/library/api/paymentmethods/afterpay/afterpay.php');

function getClientIpBuckaroo() {
    $ipaddress = '';
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ipaddress = $_SERVER['HTTP_CLIENT_IP'];
    } else if(!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ipaddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else if(!empty($_SERVER['HTTP_X_FORWARDED'])) {
        $ipaddress = $_SERVER['HTTP_X_FORWARDED'];
    } else if(!empty($_SERVER['HTTP_FORWARDED_FOR'])) {
        $ipaddress = $_SERVER['HTTP_FORWARDED_FOR'];
    } else if(!empty($_SERVER['HTTP_FORWARDED'])) {
        $ipaddress = $_SERVER['HTTP_FORWARDED'];
    } else if(!empty($_SERVER['REMOTE_ADDR'])) {
        $ipaddress = $_SERVER['REMOTE_ADDR'];
    } else {
        $ipaddress = 'UNKNOWN';
    }
    $ex = explode(",", $ipaddress);
    return trim($ex[0]);
}

/**
* @package Buckaroo
*/
class WC_Gateway_Buckaroo_Afterpay extends WC_Gateway_Buckaroo {
    var $type;
    var $b2b;
    var $showpayproc;
    var $vattype;
    function __construct() {
        $woocommerce = getWooCommerceObject();

        $this->id = 'buckaroo_afterpay';
        $this->title = 'AfterPay';
        $this->icon 		= apply_filters('woocommerce_buckaroo_afterpay_icon', plugins_url('library/buckaroo_images/24x24/afterpay.jpg', __FILE__));
        $this->has_fields 	= false;
        $this->method_title = 'Buckaroo AfterPay Old';
        $this->description = "Betaal met AfterPay Old";
        $GLOBALS['plugin_id'] = $this->plugin_id . $this->id . '_settings';
        $this->currency = get_woocommerce_currency();
        $this->transactiondescription = BuckarooConfig::get('BUCKAROO_TRANSDESC');

        $this->secretkey = BuckarooConfig::get('BUCKAROO_SECRET_KEY');
        $this->mode = BuckarooConfig::getMode();
        $this->thumbprint = BuckarooConfig::get('BUCKAROO_CERTIFICATE_THUMBPRINT');
        $this->culture = BuckarooConfig::get('CULTURE');
        $this->usenotification = BuckarooConfig::get('BUCKAROO_USE_NOTIFICATION');
        $this->notificationdelay = BuckarooConfig::get('BUCKAROO_NOTIFICATION_DELAY');

        parent::__construct();

        $this->supports           = array(
            'products',
            'refunds'
        );
        $this->type = $this->settings['service'];
        $this->b2b = $this->settings['enable_bb'];
        $this->vattype = $this->settings['vattype'];
        $this->notify_url = home_url('/');

        if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '<' ) ) {

        } else {
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            add_action( 'woocommerce_api_wc_gateway_buckaroo_sepadirectdebit', array( $this, 'response_handler' ) );
            if ($this->showpayproc) add_action( 'woocommerce_thankyou_buckaroo_afterpay' , array( $this, 'thankyou_description' ) );
            $this->notify_url   = add_query_arg('wc-api', 'WC_Gateway_Buckaroo_Afterpay', $this->notify_url);
        }
        //add_action( 'woocommerce_api_callback', 'response_handler' );           
    }

    /**
     * Can the order be refunded
     * @access public
     * @param object $order WC_Order
     * @return object & string
     */
    public function can_refund_order( $order ) {
        return $order && $order->get_transaction_id();
    }

    /**
     * Can the order be refunded
     * @param integer $order_id
     * @param integer $amount defaults to null
     * @param string $reason
     * @return callable|string function or error
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order = wc_get_order( $order_id );
        if ( ! $this->can_refund_order( $order ) ) {
            return new WP_Error('error_refund_trid', __("Refund failed: Order not in ready state, Buckaroo transaction ID do not exists."));
        }
        if ($order->get_total() != $amount) {
            return new WP_Error('error_refund_full_amount', __("Refund failed: Only full amount can be refunded for AfterPay. Partial amount can be refunded using Buckaroo Payment Plaza."));
        }
        update_post_meta($order_id, '_pushallowed', 'busy');
        $GLOBALS['plugin_id'] = $this->plugin_id . $this->id . '_settings';
        $order = wc_get_order( $order_id );
        if (checkForSequentialNumbersPlugin()) {
            $order_id = $order->get_order_number(); //Use sequential id
        }
        $afterpay = new BuckarooAfterPay($this->type);
        $afterpay->amountDedit = 0;
        $afterpay->amountCredit = $amount;
        $afterpay->currency = $this->currency;
        $afterpay->description = $reason;
        if ($this->mode=='test') {
            $afterpay->invoiceId = 'WP_'.(string)$order_id;
        }
        $afterpay->orderId = $order_id;
        $afterpay->OriginalTransactionKey = $order->get_transaction_id();
        $afterpay->returnUrl = $this->notify_url;
        $payment_type = str_replace('buckaroo_', '', strtolower($this->id));
        $afterpay->channel = BuckarooConfig::getChannel($payment_type, __FUNCTION__);
        try {
            $response = $afterpay->Refund();
        } catch (exception $e) {
            update_post_meta($order_id, '_pushallowed', 'ok');
        }
        return fn_buckaroo_process_refund($response, $order, $amount, $this->currency);
    }

    /**
     * Validate payment fields on the frontend.
     * 
     * @access public
     * @return void
     */
    public function validate_fields() {
        
        if (empty($_POST["buckaroo-afterpay-accept"])) {
            wc_add_notice( __("Please accept licence agreements", 'wc-buckaroo-bpe-gateway'), 'error' );
        }
        if (!empty($_POST["buckaroo-afterpay-b2b"]) && $_POST["buckaroo-afterpay-b2b"] == 'ON') {
            if (empty($_POST["buckaroo-afterpay-CompanyCOCRegistration"])) {
                wc_add_notice( __("Company registration number is required (KvK)", 'wc-buckaroo-bpe-gateway'), 'error' );
            }
            if (empty($_POST["buckaroo-afterpay-CompanyName"])) {
                wc_add_notice( __("Company name is required", 'wc-buckaroo-bpe-gateway'), 'error' );
            }
        } else {
            $birthdate = $_POST['buckaroo-afterpay-birthdate'];
            if (!$this->validateDate($birthdate,'d-m-Y')){
                wc_add_notice( __("Please enter correct birthdate date", 'wc-buckaroo-bpe-gateway'), 'error' );
            }
        }
        if ($this->type == 'afterpayacceptgiro') {
            if (empty($_POST["buckaroo-afterpay-CustomerAccountNumber"])) {
                wc_add_notice( __("IBAN is required", 'wc-buckaroo-bpe-gateway'), 'error' );
            }
        }
        resetOrder();
        return;
    }

    /**
     * Process payment
     * 
     * @param integer $order_id
     * @return callable|void fn_buckaroo_process_response() or void
     */
    function process_payment($order_id) {
        $woocommerce = getWooCommerceObject();

        $GLOBALS['plugin_id'] = $this->plugin_id . $this->id . '_settings';
        $order = new WC_Order( $order_id );
        $afterpay = new BuckarooAfterPay($this->type);
        if (checkForSequentialNumbersPlugin()) {
            $order_id = $order->get_order_number(); //Use sequential id
        }
        if (method_exists($order, 'get_order_total')) {
            $afterpay->amountDedit = $order->get_order_total();
        } else {
            $afterpay->amountDedit = $order->get_total();
        }
        $payment_type = str_replace('buckaroo_', '', strtolower($this->id));
        $afterpay->channel = BuckarooConfig::getChannel($payment_type, __FUNCTION__);
        $afterpay->currency = $this->currency;
        $afterpay->description = $this->transactiondescription;
        $afterpay->invoiceId = getUniqInvoiceId((string)$order_id, $this->mode);
        $afterpay->orderId = (string)$order_id;
        
        $afterpay->BillingGender = $_POST['buckaroo-afterpay-gender'];

        $get_billing_first_name = getWCOrderDetails($order_id, "billing_first_name");
        $get_billing_last_name = getWCOrderDetails($order_id, "billing_last_name");
        $get_billing_email = getWCOrderDetails($order_id, "billing_email");

        $afterpay->BillingInitials = $this->getInitials($get_billing_first_name);
        $afterpay->BillingLastName = $get_billing_last_name;
        $birthdate = $_POST['buckaroo-afterpay-birthdate'];
        if (!empty($_POST["buckaroo-afterpay-b2b"]) && $_POST["buckaroo-afterpay-b2b"] == 'ON') {
            $birthdate = '01-01-1990';
        }
        if ($this->validateDate($birthdate,'d-m-Y')){
            $birthdate = date('Y-m-d', strtotime($birthdate));
        } else {
            wc_add_notice( __("Please enter correct birthdate date", 'wc-buckaroo-bpe-gateway'), 'error' );
            return;
        }
        if (empty($_POST["buckaroo-afterpay-accept"])) {
            wc_add_notice( __("Please accept licence agreements", 'wc-buckaroo-bpe-gateway'), 'error' );
            return;
        }
        $shippingCosts = $order->get_total_shipping();
        $shippingCostsTax = $order->get_shipping_tax();
        if (floatval($shippingCosts) > 0) {
            $afterpay->ShippingCosts = number_format($shippingCosts, 2)+number_format($shippingCostsTax, 2);
        }
        if (!empty($_POST["buckaroo-afterpay-b2b"]) && $_POST["buckaroo-afterpay-b2b"] == 'ON') {

            if (empty($_POST["buckaroo-afterpay-CompanyCOCRegistration"])) {
                wc_add_notice( __("Company registration number is required (KvK)", 'wc-buckaroo-bpe-gateway'), 'error' );
                return;
            }
            if (empty($_POST["buckaroo-afterpay-CompanyName"])) {
                wc_add_notice( __("Company name is required", 'wc-buckaroo-bpe-gateway'), 'error' );
                return;
            }
            $afterpay->B2B = 'TRUE';
            $afterpay->CompanyCOCRegistration = $_POST["buckaroo-afterpay-CompanyCOCRegistration"];
            $afterpay->CompanyName = $_POST["buckaroo-afterpay-CompanyName"];
            // $afterpay->CostCentre = $_POST["buckaroo-afterpay-CostCentre"];
            // $afterpay->VatNumber = $_POST["buckaroo-afterpay-VatNumber"];
        }
        $afterpay->BillingBirthDate = date('Y-m-d', strtotime($birthdate));

        $get_billing_address_1 = getWCOrderDetails($order_id, 'billing_address_1');
        $get_billing_address_2 = getWCOrderDetails($order_id, 'billing_address_2');
        $address_components = fn_buckaroo_get_address_components($get_billing_address_1." ".$get_billing_address_2);
        $afterpay->BillingStreet = $address_components['street'];
        $afterpay->BillingHouseNumber = $address_components['house_number'];
        $afterpay->BillingHouseNumberSuffix = $address_components['number_addition'];
        $afterpay->BillingPostalCode = getWCOrderDetails($order_id, 'billing_postcode');
        $afterpay->BillingCity = getWCOrderDetails($order_id, 'billing_city');
        $afterpay->BillingCountry = getWCOrderDetails($order_id, 'billing_country');
        $get_billing_email = getWCOrderDetails($order_id, 'billing_email');
        $afterpay->BillingEmail = !empty($get_billing_email) ? $get_billing_email : '';
        $afterpay->BillingLanguage = 'nl';
        $get_billing_phone = getWCOrderDetails($order_id, 'billing_phone');
        $number = $this->cleanup_phone($get_billing_phone);
        $afterpay->BillingPhoneNumber = $number['phone'];


        $afterpay->AddressesDiffer = 'FALSE';
        if (isset($_POST["buckaroo-afterpay-shipping-differ"])) {
        // if (!empty($_POST["buckaroo-afterpay-shipping-differ"])) {
            $afterpay->AddressesDiffer = 'TRUE';

            $get_shipping_first_name = getWCOrderDetails($order_id, 'shipping_first_name');
            $afterpay->ShippingInitials = $this->getInitials($get_shipping_first_name);
            $get_shipping_last_name = getWCOrderDetails($order_id, 'shipping_last_name');
            $afterpay->ShippingLastName = $get_shipping_last_name;
            $get_shipping_address_1 = getWCOrderDetails($order_id, 'shipping_address_1');
            $get_shipping_address_2 = getWCOrderDetails($order_id, 'shipping_address_2');
            $address_components = fn_buckaroo_get_address_components($get_shipping_address_1." ".$get_shipping_address_2);
            $afterpay->ShippingStreet = $address_components['street'];
            $afterpay->ShippingHouseNumber = $address_components['house_number'];
            $afterpay->ShippingHouseNumberSuffix = $address_components['number_addition'];

            $afterpay->ShippingPostalCode = getWCOrderDetails($order_id, 'shipping_postcode');
            $afterpay->ShippingCity = getWCOrderDetails($order_id, 'shipping_city');
            $afterpay->ShippingCountryCode = getWCOrderDetails($order_id, 'shipping_country');


            $get_shipping_email = getWCOrderDetails($order_id, 'billing_email');
            $afterpay->ShippingEmail = !empty($get_shipping_email) ? $get_shipping_email : '';
            $afterpay->ShippingLanguage = 'nl';
            $get_shipping_phone = getWCOrderDetails($order_id, 'billing_phone');
            $number = $this->cleanup_phone($get_shipping_phone);
            $afterpay->ShippingPhoneNumber = $number['phone'];
        }
        if ($this->type == 'afterpayacceptgiro') {

            if (empty($_POST["buckaroo-afterpay-CustomerAccountNumber"])) {
                wc_add_notice( __("IBAN is required", 'wc-buckaroo-bpe-gateway'), 'error' );
                return;
            }
            $afterpay->CustomerAccountNumber = $_POST["buckaroo-afterpay-CustomerAccountNumber"];
        }

        $afterpay->CustomerIPAddress = getClientIpBuckaroo();
        $afterpay->Accept = 'TRUE';
        $products = Array();
        $items = $order->get_items();
        $itemsTotalAmount = 0;
        foreach ( $items as $item ) {
            $product = new WC_Product($item['product_id']);
            $tax_class = $product->get_attribute("vat_category");
            if (empty($tax_class)){
                $tax_class = $this->vattype;
                //wc_add_notice( __("Vat category (vat_category) do not exist for product ", 'wc-buckaroo-bpe-gateway').$item['name'], 'error' );
               // return;
            }
            $tmp["ArticleDescription"] = $item['name'];
            $tmp["ArticleId"] = $item['product_id'];
            $tmp["ArticleQuantity"] = 1;
            $tmp["ArticleUnitprice"] = number_format(number_format($item["line_total"]+$item["line_tax"], 4)/$item["qty"], 2);
            $itemsTotalAmount += $tmp["ArticleUnitprice"] * $item["qty"];
            $tmp["ArticleVatcategory"] = $tax_class;
            for($i = 0 ; $item["qty"] > $i ; $i++) {
                $products[] = $tmp;
            }
        }       
        $fees = $order->get_fees();
        foreach ( $fees as $key => $item ) {
            $tmp["ArticleDescription"] = $item['name'];
            $tmp["ArticleId"] = $key;
            $tmp["ArticleQuantity"] = 1;
            $tmp["ArticleUnitprice"] = number_format(($item["line_total"]+$item["line_tax"]), 2);
            $itemsTotalAmount += $tmp["ArticleUnitprice"];
            $tmp["ArticleVatcategory"] = '4';
            $products[] = $tmp;
        }
        if(!empty($afterpay->ShippingCosts)) {
            $itemsTotalAmount += $afterpay->ShippingCosts;
        }
        for($i = 0; count($products) > $i; $i++) {
            if($afterpay->amountDedit != $itemsTotalAmount) {
                if(number_format($afterpay->amountDedit - $itemsTotalAmount,2) >= 0.01) {
                    $products[$i]['ArticleUnitprice'] += 0.01; 
                    $itemsTotalAmount += 0.01;
                } elseif(number_format($itemsTotalAmount - $afterpay->amountDedit,2) >= 0.01) {
                    $products[$i]['ArticleUnitprice'] -= 0.01; 
                    $itemsTotalAmount -= 0.01;

                }
            }
        }
        
        $afterpay->returnUrl = $this->notify_url;

        if ($this->usenotification == 'TRUE') {
            $afterpay->usenotification = 1;
            $customVars['Customergender'] = $_POST['buckaroo-sepadirectdebit-gender'];

            $get_billing_first_name = getWCOrderDetails($order_id, 'billing_first_name');
            $get_billing_last_name = getWCOrderDetails($order_id, 'billing_last_name');
            $get_billing_email = getWCOrderDetails($order_id, 'billing_email');
            $customVars['CustomerFirstName'] = !empty($get_billing_first_name) ? $get_billing_first_name : '';
            $customVars['CustomerLastName'] = !empty($get_billing_last_name) ? $get_billing_last_name : '';
            $customVars['Customeremail'] = !empty($get_billing_email) ? $get_billing_email : '';
            $customVars['Notificationtype'] = 'PaymentComplete';
            $customVars['Notificationdelay'] = date('Y-m-d', strtotime(date('Y-m-d', strtotime('now + ' . (int) $this->invoicedelay . ' day')).' + '. (int)$this->notificationdelay.' day'));
        }

        $response = $afterpay->PayAfterpay($products);
        return fn_buckaroo_process_response($this, $response, $this->mode);
    }
        
    /**
     * Payment form on checkout page
     */
    function payment_fields() {
        $accountname = get_user_meta( $GLOBALS["current_user"]->ID, 'billing_first_name', true )." ".get_user_meta( $GLOBALS["current_user"]->ID, 'billing_last_name', true );
        $post_data = Array();
        if (!empty($_POST["post_data"])) {
            parse_str($_POST["post_data"], $post_data);
        }
        ?>
        <?php if ($this->mode=='test') : ?><p><?php _e('TEST MODE', 'wc-buckaroo-bpe-gateway'); ?></p><?php endif; ?>
        <?php if ($this->description) : ?><p><?php echo wpautop(wptexturize($this->description)); ?></p><?php endif; ?>

        <fieldset>
            <?php if ($this->b2b == 'enable' && $this->type== 'afterpaydigiaccept') { ?>
            <p class="form-row form-row-wide validate-required">
                <?php echo _e('Checkout for company', 'wc-buckaroo-bpe-gateway')?> <input id="buckaroo-afterpay-b2b" name="buckaroo-afterpay-b2b" onclick="CheckoutFields(this.checked)" type="checkbox" value="ON" />
            </p>

            <script>
                function CheckoutFields(showFiields) {
                     if (showFiields) {
                        document.getElementById('showB2BBuckaroo').style.display = 'block';
                        document.getElementById('buckaroo-afterpay-CompanyName').value = document.getElementById('billing_company').value;
                        document.getElementById('buckaroo-afterpay-birthdate').disabled = true;
                        document.getElementById('buckaroo-afterpay-birthdate').value = '';
                        document.getElementById('buckaroo-afterpay-birthdate').parentElement.style.display = 'none';
                        document.getElementById('buckaroo-afterpay-birthdate').parentElement.classList.remove('woocommerce-invalid');
                        document.getElementById('buckaroo-afterpay-birthdate').parentElement.classList.remove('validate-required');
                        document.getElementById('buckaroo-afterpay-genderm').disabled = true;
                        document.getElementById('buckaroo-afterpay-genderf').disabled = true;
                        document.getElementById('buckaroo-afterpay-genderm').parentElement.style.display = 'none';
                        document.getElementById('buckaroo-afterpay-genderm').parentElement.getElementsByTagName('span').item(0).style.display = 'none';
                     } else {
                        document.getElementById('showB2BBuckaroo').style.display = 'none';
                        document.getElementById('buckaroo-afterpay-birthdate').disabled = false;
                        document.getElementById('buckaroo-afterpay-birthdate').parentElement.style.display = 'block';
                        document.getElementById('buckaroo-afterpay-birthdate').parentElement.classList.add('validate-required');
                        document.getElementById('buckaroo-afterpay-genderm').disabled = false;
                        document.getElementById('buckaroo-afterpay-genderf').disabled = false;
                        document.getElementById('buckaroo-afterpay-genderf').parentElement.style.display = 'inline-block';
                        document.getElementById('buckaroo-afterpay-genderf').parentElement.getElementsByTagName('span').item(0).style.display = 'inline-block';
                    }
                }
            </script>
            
            <span id="showB2BBuckaroo" style="display:none">
            <p class="form-row form-row-wide validate-required">
                <?php echo _e('Fill required fields if bill in on the company:', 'wc-buckaroo-bpe-gateway')?>
            </p>
            <p class="form-row form-row-wide validate-required">
                <label for="buckaroo-afterpay-CompanyCOCRegistration"><?php echo _e('COC (KvK) number:', 'wc-buckaroo-bpe-gateway')?><span class="required">*</span></label>
                <input id="buckaroo-afterpay-CompanyCOCRegistration" name="buckaroo-afterpay-CompanyCOCRegistration" class="input-text" type="text" maxlength="250" autocomplete="off" value="" />
            </p>
            <p class="form-row form-row-wide validate-required">
                <label for="buckaroo-afterpay-CompanyName"><?php echo _e('Name of the organization:', 'wc-buckaroo-bpe-gateway')?><span class="required">*</span></label>
                <input id="buckaroo-afterpay-CompanyName" name="buckaroo-afterpay-CompanyName" class="input-text" type="text" maxlength="250" autocomplete="off" value="" />
            </p>
            </span>
            <?php } ?>

            <p class="form-row">
                <label for="buckaroo-afterpay-gender"><?php echo _e('Gender:', 'wc-buckaroo-bpe-gateway')?><span class="required">*</span></label>
                <input id="buckaroo-afterpay-genderm" name="buckaroo-afterpay-gender" class="" type="radio" value="1" checked style="float:none; display: inline !important;" /> <?php echo _e('Male', 'wc-buckaroo-bpe-gateway')?> &nbsp;
                <input id="buckaroo-afterpay-genderf" name="buckaroo-afterpay-gender" class="" type="radio" value="2" style="float:none; display: inline !important;" /> <?php echo _e('Female', 'wc-buckaroo-bpe-gateway')?>
            </p>
            <p class="form-row form-row-wide validate-required">
                <label for="buckaroo-afterpay-birthdate"><?php echo _e('Birthdate (format DD-MM-YYYY):', 'wc-buckaroo-bpe-gateway')?><span class="required">*</span></label>
                <input id="buckaroo-afterpay-birthdate" name="buckaroo-afterpay-birthdate" class="input-text" type="text" maxlength="250" autocomplete="off" value="" placeholder="DD-MM-YYYY" />
            </p>
        <?php if (!empty($post_data["ship_to_different_address"])) { ?>
                <input id="buckaroo-afterpay-shipping-differ" name="buckaroo-afterpay-shipping-differ" class="" type="hidden" value="1"/>
        <?php } ?>
            <?php if ($this->type == 'afterpayacceptgiro') { ?>
                <p class="form-row form-row-wide validate-required">
                    <label for="buckaroo-afterpay-CustomerAccountNumber"><?php echo _e('IBAN:', 'wc-buckaroo-bpe-gateway')?><span class="required">*</span></label>
                    <input id="buckaroo-afterpay-CustomerAccountNumber" name="buckaroo-afterpay-CustomerAccountNumber" class="input-text" type="text" value="" />
                </p>
            <?php } ?>

            <p class="form-row form-row-wide validate-required">
                <a href="https://www.afterpay.nl/nl/algemeen/betalen-met-afterpay/betalingsvoorwaarden/" target="_blank"><?php echo _e('Accept licence agreement:', 'wc-buckaroo-bpe-gateway')?></a><span class="required">*</span> <input id="buckaroo-afterpay-accept" name="buckaroo-afterpay-accept" type="checkbox" value="ON" />
            </p>
            <p class="required" style="float:right;">* Verplicht</p>
        </fieldset>
    <?php
    }
    
    /**
     * Check response data
     *
     * @access public
     */
    public function response_handler() {
        $woocommerce = getWooCommerceObject();
        fn_buckaroo_process_response($this);
        exit;
    }

    /**
     * Add fields to the form_fields() array, specific to this page.
     * 
     * @access public
     */
    public function init_form_fields() {
        parent::init_form_fields();
        
        add_filter('woocommerce_settings_api_form_fields_' . $this->id, array($this, 'enqueue_script_certificate'));
        
        add_filter('woocommerce_settings_api_form_fields_' . $this->id, array($this, 'enqueue_script_hide_local'));
      
        //Start Dynamic Rendering of Hidden Fields
        $options = get_option("woocommerce_".$this->id."_settings", null );
        $ccontent_arr = array();
        $keybase = 'certificatecontents';
        $keycount = 1;
        if (!empty($options["$keybase$keycount"])) {
            while(!empty($options["$keybase$keycount"])){
                $ccontent_arr[] = "$keybase$keycount";
                $keycount++;
            }
        }
        $while_key = 1;
        $selectcertificate_options = array('none' => 'None selected');
        while($while_key != $keycount) {
            $this->form_fields["certificatecontents$while_key"] = array(
                'title' => '',
                'type' => 'hidden', 
                'description' => '',
                'default' => ''
            );
            $this->form_fields["certificateuploadtime$while_key"] = array(
                'title' => '',
                'type' => 'hidden', 
                'description' => '',
                'default' => '');
            $this->form_fields["certificatename$while_key"] = array(
                'title' => '',
                'type' => 'hidden', 
                'description' => '',
                'default' => '');
            $selectcertificate_options["$while_key"] = $options["certificatename$while_key"];

            $while_key++;
        }
        $final_ccontent = $keycount;
        $this->form_fields["certificatecontents$final_ccontent"] = array(
            'title' => '',
            'type' => 'hidden', 
            'description' => '',
            'default' => '');
        $this->form_fields["certificateuploadtime$final_ccontent"] = array(
            'title' => '',
            'type' => 'hidden', 
            'description' => '',
            'default' => '');
        $this->form_fields["certificatename$final_ccontent"] = array(
            'title' => '',
            'type' => 'hidden', 
            'description' => '',
            'default' => '');
        
        $this->form_fields['selectcertificate'] = array(
            'title' => __('Select Certificate', 'wc-buckaroo-bpe-gateway'),
            'type' => 'select', 
            'description' => __('Select your certificate by name.', 'wc-buckaroo-bpe-gateway'),
            'options' => $selectcertificate_options,
            'default' => 'none'
        );
        $this->form_fields['choosecertificate'] = array(
            'title' => __( '', 'wc-buckaroo-bpe-gateway' ),
            'type' => 'file',
            'description' => __(''),
            'default' => '');
        $this->form_fields['service'] = array(
            'title' => __( 'Select afterpay service', 'wc-buckaroo-bpe-gateway' ),
            'type' => 'select',
            'description' => __( 'Please select the service', 'wc-buckaroo-bpe-gateway' ),
            'options' => array('afterpayacceptgiro'=>'Offer customer to pay afterwards by SEPA Direct Debit.', 'afterpaydigiaccept'=>'Offer customer to pay afterwards by digital invoice.'),
            'default' => 'afterpaydigiaccept');

        $this->form_fields['enable_bb'] = array(
            'title' => __( 'Enable B2B option for AfterPay', 'wc-buckaroo-bpe-gateway' ),
            'type' => 'select',
            'description' => __( 'Enables or disables possibility to pay using company credentials', 'wc-buckaroo-bpe-gateway' ),
            'options' => array('enable'=>'Enable', 'disable'=>'Disable'),
            'default' => 'disable');

        $this->form_fields['vattype'] = array(
            'title' => __( 'Default product Vat type', 'wc-buckaroo-bpe-gateway' ),
            'type' => 'select',
            'description' => __( 'Please select default vat type for your products', 'wc-buckaroo-bpe-gateway' ),
            'options' => array(
                '1'=>'1 = High rate',
                '2'=>'2 = Low rate',
                '3'=>'3 = Zero rate',
                '4'=>'4 = Null rate',
                '5'=>'5 = middle rate'),
            'default' => '1');

        $this->form_fields['usenotification'] = array(
            'title' => __( 'Use Notification Service', 'wc-buckaroo-bpe-gateway' ),
            'type' => 'select',
            'description' => __( 'The notification service can be used to have the payment engine sent additional notifications.', 'wc-buckaroo-bpe-gateway' ),
            'options' => array('TRUE'=>'Yes', 'FALSE'=>'No'),
            'default' => 'FALSE');

        $this->form_fields['notificationdelay'] = array(
            'title' => __('Notification delay', 'wc-buckaroo-bpe-gateway'),
            'type' => 'text',
            'description' => __('The time at which the notification should be sent. If this is not specified, the notification is sent immediately.', 'wc-buckaroo-bpe-gateway'),
            'default' => '0');
    }
}