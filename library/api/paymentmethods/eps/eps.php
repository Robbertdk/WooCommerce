<?php

require_once(dirname(__FILE__) . '/../paymentmethod.php');

class BuckarooEPS extends BuckarooPaymentMethod {
    public function __construct() {
        $this->type = "eps";
        $this->version = 1;
    }

    /**
     * @access public
     * @param array $customVars
     * @return callable parent::Pay()
     */
    public function Pay($customVars = array())
    {
        return parent::Pay();
    }

    /**
     * @access public
     * @return callable parent::Refund();
     * @throws Exception
     */
    public function Refund() {
        return parent::Refund();
    }

    /**
     * @access public
     * @return callable parent::checkRefundData($data);
     * @param $data array
     * @throws Exception
     */
    public function checkRefundData($data) {
        return parent::checkRefundData($data);
    }
}
