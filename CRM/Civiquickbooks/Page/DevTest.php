<?php

require_once 'CRM/Core/Page.php';

require_once('library/AboutQBs.php');

class CRM_Civiquickbooks_Page_DevTest extends CRM_Core_Page {
  public function run() {
    // Example: Set the page-title dynamically; alternatively, declare a static title in xml/Menu/*.xml
    CRM_Utils_System::setTitle(ts('DevTest'));

    // Example: Assign a variable for use in a template
    $this->assign('currentTime', date('Y-m-d H:i:s'));

    /**********************OAUTH test**************************/

    // $syncToken = array("syncToken" => '0');
    //
    //  $output = serialize($syncToken);

    // $output = isset($syncToken['syncToken']);

    // $output = unserialize($output);

    // $t = false;
    // $output = isset($t);
    /////////////////////////////////////////////////////////////
    // $syncToken = array('Customer' => array('SyncToken' => '0'));
    //
    // $output = json_encode($syncToken);
    //
    //  $output =json_encode($output);
    //
    // $output = serialize($output);

    // $output = gettype($output));
    //
    // $output = json_decode(json_decode($output),true);
    // Output: true -(json_encode)> true -(json_decode)> 1

    //"{\"Customer\":{\"SyncToken\":\"0\"}}"

    /**********************Contact and Invoice test**************************/

    // $test1 = new CRM_Civiquickbooks_Invoice();
    //
    // $test2 = new CRM_Civiquickbooks_Contact();
    //
    // $output_contact_push = $test2->push();
    // $this->assign('test_var1', print_r($output_contact_push, TRUE));

    // $output_contact_pull = $test2->pull(array('start_date' => 'yesterday'));
    // $this->assign('test_var2', print_r($output_contact_pull, TRUE));
    //
    //
    // $output_invoice_push = $test1->push();
    // $this->assign('test_var3', print_r($output_invoice_push,TRUE));
    //
    //
    // $output_invoice_pull = $test1->pull();
    // $this->assign('test_var4', print_r($output_invoice_pull,TRUE));

    /*********************************Accounts data verification************************************/

    // $records = civicrm_api3('account_contact', 'get', array(
    // 		'api.contact.get' => 1,
    //     'plugin' => 'quickbooks',
    //     'connector_id' => 0,
    // 		'contact_id' => array('IS NOT NULL' => 1),
    // 		'options' => array(
    // 			'limit' => 9999999,
    // 		),
    //    )
    // );
    //
    // $this->assign('test_var1', print_r($records,TRUE));

    // $test = get_QB_setting_value('quickbooks_company_country');
    //
    //
    // $this->assign('test_var1', print_r($test,TRUE));

    //$records = civicrm_api3('account_contact', 'getsuggestions', array(
    //	'plugin' => 'quickbooks',
    //	'sequential' => 1,
    //		'options' => array(
    //			'limit' => 10,
    //		),
    //	)
    //);
    //
    //
    //
    //$this->assign('test_var2', print_r($records,TRUE));

    parent::run();
  }
}
