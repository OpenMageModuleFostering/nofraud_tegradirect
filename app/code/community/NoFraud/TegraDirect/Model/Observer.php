<?php

class NoFraud_TegraDirect_Model_Observer {

    private static $last_transaction;
    
    public function salesOrderPaymentPlaceStart(Varien_Event_Observer $observer){
        $is_active = $this->getConfigData('active');

        if($is_active){
            $payment = $observer->getEvent()->getPayment();
            $order = $payment->getOrder();
            
            $this->last_transaction = Mage::getSingleton('core/session')->getNfLastTransaction();
            
            if(!empty($this->last_transaction)){
                //Mage::log(var_export($this->last_transaction,true),null,'tegra-direct.log');
                $request = $this->_buildGatewayResponseRequest($this->last_transaction['id'],$payment);
                //Mage::log(var_export($request,true),null,'tegra-direct.log');
                $response = $this->_sendRequest($request,$this->getURL('gateway_response'));
                //Mage::log(var_export($response,true),null,'tegra-direct.log');
                $this->last_transaction = "";
                Mage::getSingleton('core/session')->unsNfLastTransaction();
            } else {
                Mage::log("\$this->last_transaction is empty. Skipping.",null,'tegra-direct.log');
            }

            $request = $this->_buildRequest($order,$payment);
            //Mage::log(var_export($request,true),null,'tegra-direct.log');
            $result = $this->_sendRequest($request,$this->getURL());
            //Mage::log(var_export($result,true),null,'tegra-direct.log');
            $comment = "NoFraud was unable to render a result on this transaction due to an error.";
            if(!isset($result['id'])){
                Mage::log(var_export($result,true),null,'tegra-direct.log');
            } else {
                $comment = "NoFraud rendered a result of \"{$result['decision']}\" for this transaction giving it the ID of <a href=\"https://portal.nofraud.com/transaction/{$result['id']}\" target=\"_blank\">{$result['id']}</a>";
                if($result['decision'] == "review"){
                    $comment .= "\nFor Review results, we're on it already looking into it on your behalf.";
                }
                $this->last_transaction = $result;
                //Mage::log(var_export($this->last_transaction,true),null,'tegra-direct.log');
                Mage::getSingleton('core/session')->setNfLastTransaction($result);
            }
            $order->addStatusHistoryComment("{$comment}");
            $order->save();

            if(isset($result['decision']) && $result['decision'] == "fail"){
                $this->last_transaction = "";
                Mage::getSingleton('core/session')->unsNfLastTransaction();
                $message = ($result['message'] || "Declined")? $result['message']:"Declined";
                Mage::log(var_export($result,true),null,'tegra-direct.log');
                Mage::throwException(Mage::helper('paygate')->__($message));
            }
        }
    }

    public function CheckoutSubmitAllAfter(Varien_Event_Observer $observer){
        $is_active = $this->getConfigData('active');

        if($is_active){
            $order = $observer->getEvent()->getOrder();
            $payment = $order->getPayment();

            if(!empty($this->last_transaction)){
                $request = $this->_buildGatewayResponseRequest($this->last_transaction['id'],$payment);
                //Mage::log(var_export($request,true),null,'tegra-direct.log');
                $response = $this->_sendRequest($request,$this->getURL("gateway_response"));

                if($this->last_transaction['decision'] == "review"){
                    if(!$payment->getIsFraudDetected()){
                        $payment->setIsTransactionPending(true);
                        $payment->setIsFraudDetected(true);
                        $order->setState($order->getState(),Mage_Sales_Model_Order::STATUS_FRAUD);
                        $payment->save();
                        $order->save();
                    }
                }

                $this->last_transaction = "";
                Mage::getSingleton('core/session')->unsNfLastTransaction();
                Mage::log(var_export($this->last_transaction,true),null,'tegra-direct.log');
            }
        }
    }

    private function _buildGatewayResponseRequest($id,$payment){
        $order = $payment->getOrder();
        
        $gateway_status = "fail";
        if($payment->getIsFraudDetected()){
            $gateway_status = "review";
        }
        if($order->getBaseTotalDue() == 0){
            $gateway_status = "pass";
        }

        $params = [];
        $params['nf-token'] = $this->getConfigData('nftoken');
        $params['nf-id'] = $id;
        $params['gateway-response'] = [];
        $params['gateway-response']['result'] = $gateway_status;

        if(!empty($payment->getLastTransId())){
            $params['gateway-response']['transaction-id'] = $payment->getLastTransId();
        }
        
        return $params;
    }

    private function _buildRequest($order, $payment){
        $billing = $order->getBillingAddress();
        $shipping = $order->getShippingAddress();

        $billing_region = Mage::getModel('directory/region')->load($billing->getRegion_id());
        $shipping_region = Mage::getModel('directory/region')->load($shipping->getRegion_id());

        $params = [];
        $params['nf-token'] = $this->getConfigData('nftoken');
        $params['amount'] = Mage::getModel('directory/currency')->formatTxt($order->getGrandTotal(), array('display' => Zend_Currency::NO_SYMBOL));
        $params['customerIP'] = $order->getRemoteIp();
        
        if($order->getShippingAmount() > 0){
            $params['shippingAmount'] = Mage::getModel('directory/currency')->formatTxt($order->getShippingAmount(), array('display' => Zend_Currency::NO_SYMBOL));
        }

        if(!empty($payment->getCcAvsStatus())){
            $params['avsResultCode'] = $payment->getCcAvsStatus();
        }
        if(!empty($payment->getCcCidStatus())){
            $params['cvvResultCode'] = $payment->getCcCidStatus();
        }

        $params['billTo'] = [];
        $params['billTo']['firstName'] = $billing->getFirstname();
        $params['billTo']['lastName'] = $billing->getLastname();
        $params['billTo']['address'] = $billing->getStreet(1)." ".$billing->getStreet(2);
        $params['billTo']['city'] = $billing->getCity();
        $params['billTo']['state'] =$billing_region->getCode();
        $params['billTo']['zip'] = $billing->getPostcode();
        $params['billTo']['country'] = $billing->getCountry();
        $params['billTo']['phoneNumber'] = $billing->getTelephone();

        if(!empty($shipping)){
            $params['shipTo'] = [];
            $params['shipTo']['firstName'] = $shipping->getFirstname();
            $params['shipTo']['lastName'] = $shipping->getLastname();
            $params['shipTo']['address'] = $shipping->getStreet(1)." ".$shipping->getStreet(2);
            $params['shipTo']['city'] = $shipping->getCity();
            $params['shipTo']['state'] = $shipping_region->getCode();
            $params['shipTo']['zip'] = $shipping->getPostcode();
            $params['shipTo']['country'] = $shipping->getCountry();
        }

        $params['customer'] = [];
        $params['customer']['email'] = $order->getCustomerEmail();


        $expirDate;
        if(strlen($payment->getCcExpMonth()) == 1){
            $expirDate = "0".$payment->getCcExpMonth();
        } else {
            $expirDate = $payment->getCcExpMonth();
        }

        if(strlen($payment->getCcExpYear()) == 4){
            $expirDate .= substr($payment->getCcExpYear(), 2);
        } else if(strlen($payment->getCcExpYear()) == 2){
            $expirDate .= $payment->getCcExpYear();
        }


        if(!empty($payment->getCcNumber())){
            $params['payment'] = [];
            $params['payment']['creditCard']['cardNumber'] = $payment->getCcNumber();
            $params['payment']['creditCard']['expirationDate'] = $expirDate;
            
            if(!empty($payment->getCcCid())){
                $params['payment']['creditCard']['cardCode'] = $payment->getCcCid();
            }
        }

        //This is for the next Deployment of Direct API.
        //$params['order'] = [];
        //$params['order']['invoiceNumber'] = $order->getIncrementId();

        $params['userFields'] = [];
        $params['userFields']['orderId'] = $order->getIncrementId();

        return $params;
    }

    private function _sendRequest($request,$nfurl){
        $body = json_encode($request);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_POST, true); 
        curl_setopt($ch, CURLOPT_URL, $nfurl); 
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Content-Length: ' . strlen($body)));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        $result = curl_exec($ch);
        //$info = curl_getinfo($ch);
           
        $res_obj = json_decode($result,true);
        
        return $res_obj;
    }

    private function getConfigData($datapoint){
        $data = Mage::getStoreConfig('payment_services/TegraDirect/'.$datapoint,Mage::app()->getStore());

        if(strcmp($datapoint,"nftoken") == 0){
            $data = Mage::helper('core')->decrypt($data);
        }

        //Mage::log("{$datapoint} == {$data}",null,'tegra-direct.log');
        return $data;
    }

    private function getURL($addition = ""){
        $url = 'https://api.nofraud.com/'.$addition;
        //$url = 'https://60c64d03.ngrok.io/'.$addition;

        if($this->getConfigData('sandbox')){
            $url = 'https://apitest.nofraud.com/'.$addition;
        }
        
        //Mage::log(var_export($url,true),null,'tegra-direct.log');
        return $url;
    }
}
?>
