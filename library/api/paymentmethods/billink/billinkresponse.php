<?php
/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */

require_once(dirname(__FILE__) . '/../response.php');

class BuckarooBIllinkResponse extends BuckarooResponse {
    public $consumerIssuer;
    public $consumerName;
    public $consumerAccountNumber;
    public $consumerCity;

    /**
     * @access protected
     */
    protected function _parseSoapResponseChild() {

    }

    /**
     * @access protected
     */
    protected function _parsePostResponseChild() {

    }
}
