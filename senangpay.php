<?php
require_once 'CRM/Core/Payment.php';
class my_senangpay_payment_senangpay extends CRM_Core_Payment
{
    static private $_singleton = null;
    static protected $_mode = null;
    
    function __construct($mode, &$paymentProcessor)
    {
        $this->_mode = $mode;
        $this->_paymentProcessor = $paymentProcessor;
        $this->_processorName = ts('senangPay');
    }
    
    static function &singleton($mode, &$paymentProcessor)
    {
        $processorName = $paymentProcessor['name'];
        if(self::$_singleton[$processorName] === null)
        {
            self::$_singleton[$processorName] = new my_senangpay_payment_senangpay($mode, $paymentProcessor);
        }
        
        return self::$_singleton[$processorName];
    }
    
    function checkConfig()
    {
        $config = CRM_Core_Config::singleton();
        
        $error = array();
        
        if(empty($this->_paymentProcessor['user_name']))
        {
            $error[] = ts('The "Bill To ID" is not set in the Administer CiviCRM Payment Processor.');
        }
        
        if (!empty($error))
        {
            return implode('<p>', $error);
        }
        else
        {
            return null;
        }
    }
    
    function doDirectPayment(&$params)
    {
        CRM_Core_Error::fatal(ts('This function is not implemented'));
    }
    
    function doTransferCheckout(&$params, $component)
    {
        $merchant_id = $this->_paymentProcessor['user_name'];
        $secret_key = $this->_paymentProcessor['password'];

        $order_id = time().rand(10, 99);
        $detail = 'Payment_number_'.$order_id;
        $amount = $params['amount'];
        $amount = number_format($amount, 2);
        
        # now we prepare additional data
        $additional_data_array = array(
            'invoiceID' => $params['invoiceID'],
            'qfKey' => $params['qfKey'],
            'contactID' => $params['contactID'],
            'contributionID' => $params['contributionID'],
            'contributionTypeID' => $params['contributionTypeID'],
            'eventID' => $params['eventID'],
            'participantID' => $params['participantID'],
            'membershipID' => $params['membershipID'],
            'component' => $component
        );
        
        $additional_data = '';
        foreach($additional_data_array as $key => $value)
        {
            if($additional_data != '')
                $additional_data .= '|';
            $additional_data .= $key.'='.$value;
        }
        
        $hash = md5($secret_key.$detail.$amount.$order_id.$additional_data);
        
        $query_string = '?order_id='.$order_id.'&detail='.$detail.'&amount='.$amount.'&hash='.$hash.'&data='.$additional_data;
        
        CRM_Utils_System::redirect('https://app.senangpay.my/payment/'.$merchant_id.$query_string);
        
        exit();
    }
    
    public function handlePaymentNotification()
    {
        require_once 'CRM/Utils/Array.php';
        $module = CRM_Utils_Array::value('module', $_GET);

        # Attempt to determine component type ... Will add processing of notification, for now just redirect to thank you page
        switch ($module)
        {
            case 'contribute':
                $finalURL = CRM_Utils_System::url('civicrm/event/register', "_qf_ThankYou_display=1&qfKey={$_GET['qfKey']}", false, null, false);
                break;
            case 'event':
                $finalURL = CRM_Utils_System::url('civicrm/contribute/transact', "_qf_ThankYou_display=1&qfKey={$_GET['qfKey']}", false, null, false);
                break;
            default:
                require_once 'CRM/Core/Error.php';
                CRM_Core_Error::debug_log_message("Could not get module name from request url");
                echo "Could not get module name from request url\r\n";
        }
        CRM_Utils_System::redirect($finalURL);
    }
}