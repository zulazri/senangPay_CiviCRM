<?php
 
require_once 'CRM/Core/Payment/BaseIPN.php';
 
class senangpayIPN extends CRM_Core_Payment_BaseIPN
{
    static private $_singleton = null;
    static protected $_mode = null;
 
    static function retrieve( $name, $type, $object, $abort = true ) {
      $value = CRM_Utils_Array::value($name, $object);
      if ($abort && $value === null) {
        CRM_Core_Error::debug_log_message("Could not find an entry for $name");
        echo "Failure: Missing Parameter<p>";
        exit();
      }
 
      if ($value) {
        if (!CRM_Utils_Type::validate($value, $type)) {
          CRM_Core_Error::debug_log_message("Could not find a valid entry for $name");
          echo "Failure: Invalid Parameter<p>";
          exit();
        }
      }
 
      return $value;
    }
 
 
    /**
     * Constructor
     *
     * @param string $mode the mode of operation: live or test
     *
     * @return void
     */
    function __construct($mode, &$paymentProcessor) {
      parent::__construct();
 
      $this->_mode = $mode;
      $this->_paymentProcessor = $paymentProcessor;
    }
 
    /**
     * The function gets called when a new order takes place.
     *
     * @param xml   $dataRoot    response send by google in xml format
     * @param array $privateData contains the name value pair of <merchant-private-data>
     *
     * @return void
     *
     */
    function newOrderNotify( $success, $privateData, $component, $amount, $transactionReference ) {
        $ids = $input = $params = array( );
 
        $input['component'] = strtolower($component);
 
        $ids['contact']          = self::retrieve( 'contactID'     , 'Integer', $privateData, true );
        $ids['contribution']     = self::retrieve( 'contributionID', 'Integer', $privateData, true );
 
        if ( $input['component'] == "event" ) {
            $ids['event']       = self::retrieve( 'eventID'      , 'Integer', $privateData, true );
            $ids['participant'] = self::retrieve( 'participantID', 'Integer', $privateData, true );
            $ids['membership']  = null;
        } else {
            $ids['membership'] = self::retrieve( 'membershipID'  , 'Integer', $privateData, false );
        }
        $ids['contributionRecur'] = $ids['contributionPage'] = null;
 
        if ( ! $this->validateData( $input, $ids, $objects ) ) {
            return false;
        }
 
        // make sure the invoice is valid and matches what we have in the contribution record
        $input['invoice']    =  $privateData['invoiceID'];
        $input['newInvoice'] =  $transactionReference;
        $contribution        =& $objects['contribution'];
        $input['trxn_id']  =    $transactionReference;
 
        if ( $contribution->invoice_id != $input['invoice'] ) {
            CRM_Core_Error::debug_log_message( "Invoice values dont match between database and IPN request" );
            echo "Failure: Invoice values dont match between database and IPN request<p>";
            return;
        }
 
        // lets replace invoice-id with Payment Processor -number because thats what is common and unique
        // in subsequent calls or notifications sent by google.
        $contribution->invoice_id = $input['newInvoice'];
 
        $input['amount'] = $amount;
 
        if ( $contribution->total_amount != $input['amount'] ) {
            CRM_Core_Error::debug_log_message( "Amount values dont match between database and IPN request" );
            echo "Failure: Amount values dont match between database and IPN request.".$contribution->total_amount."/".$input['amount']."<p>";
            return;
        }
 
        require_once 'CRM/Core/Transaction.php';
        $transaction = new CRM_Core_Transaction( );
 
        // check if contribution is already completed, if so we ignore this ipn
 
        if ( $contribution->contribution_status_id == 1 ) {
            CRM_Core_Error::debug_log_message( "returning since contribution has already been handled" );
            echo "Success: Contribution has already been handled<p>";
            return true;
        } else {
            /* Since trxn_id hasn't got any use here,
             * lets make use of it by passing the eventID/membershipTypeID to next level.
             * And change trxn_id to the payment processor reference before finishing db update */
            if ( $ids['event'] ) {
                $contribution->trxn_id =
                    $ids['event']       . CRM_Core_DAO::VALUE_SEPARATOR .
                    $ids['participant'] ;
            } else {
                $contribution->trxn_id = $ids['membership'];
            }
        }
        $this->completeTransaction ( $input, $ids, $objects, $transaction);
        return true;
    }
 
 
    /**
     * singleton function used to manage this object
     *
     * @param string $mode the mode of operation: live or test
     *
     * @return object
     * @static
     */
    static function &singleton( $mode, $component, &$paymentProcessor ) {
        if ( self::$_singleton === null ) {
            self::$_singleton = new senangpayIPN($mode, $paymentProcessor);
        }
        return self::$_singleton;
    }
 
    /**
     * The function returns the component(Event/Contribute..)and whether it is Test or not
     *
     * @param array   $privateData    contains the name-value pairs of transaction related data
     *
     * @return array context of this call (test, component, payment processor id)
     * @static
     */
    static function getContext($privateData)    {
      require_once 'CRM/Contribute/DAO/Contribution.php';
 
      $component = null;
      $isTest = null;
 
      $contributionID = $privateData['contributionID'];
      $contribution = new CRM_Contribute_DAO_Contribution();
      $contribution->id = $contributionID;
 
      if (!$contribution->find(true)) {
        CRM_Core_Error::debug_log_message("Could not find contribution record: $contributionID");
        echo "Failure: Could not find contribution record for $contributionID<p>";
        exit();
      }
 
      if (stristr($contribution->source, 'Online Contribution')) {
        $component = 'contribute';
      }
      elseif (stristr($contribution->source, 'Online Event Registration')) {
        $component = 'event';
      }
      $isTest = $contribution->is_test;
 
      $duplicateTransaction = 0;
      if ($contribution->contribution_status_id == 1) {
        //contribution already handled. (some processors do two notifications so this could be valid)
        $duplicateTransaction = 1;
      }
 
      if ($component == 'contribute') {
        if (!$contribution->contribution_page_id) {
          CRM_Core_Error::debug_log_message("Could not find contribution page for contribution record: $contributionID");
          echo "Failure: Could not find contribution page for contribution record: $contributionID<p>";
          exit();
        }
 
        // get the payment processor id from contribution page
        $paymentProcessorID = CRM_Core_DAO::getFieldValue('CRM_Contribute_DAO_ContributionPage', 
                              $contribution->contribution_page_id, 'payment_processor_id');
      }
      else {
        $eventID = $privateData['eventID'];
 
        if (!$eventID) {
          CRM_Core_Error::debug_log_message("Could not find event ID");
          echo "Failure: Could not find eventID<p>";
          exit();
        }
 
        // we are in event mode
        // make sure event exists and is valid
        require_once 'CRM/Event/DAO/Event.php';
        $event = new CRM_Event_DAO_Event();
        $event->id = $eventID;
        if (!$event->find(true)) {
          CRM_Core_Error::debug_log_message("Could not find event: $eventID");
          echo "Failure: Could not find event: $eventID<p>";
          exit();
        }
 
        // get the payment processor id from contribution page
        $paymentProcessorID = $event->payment_processor_id;
      }
 
      if (!$paymentProcessorID) {
        CRM_Core_Error::debug_log_message("Could not find payment processor for contribution record: $contributionID");
        echo "Failure: Could not find payment processor for contribution record: $contributionID<p>";
        exit();
      }
 
      return array($isTest, $component, $paymentProcessorID, $duplicateTransaction);
    }
 
 
    /**
     * This method is handles the response that will be invoked (from UCMercedPaymentCollectionNotify.php) every time
     * a notification or request is sent by the UCM Payment Collection Server.
     *
     */
    static function main() {
        echo 'reached, data: '.$_REQUEST['data'];
        die();
    }
 
}