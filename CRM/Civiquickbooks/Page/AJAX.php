<?php

class CRM_Civiquickbooks_Page_AJAX extends CRM_Core_Page {

  /**
   * Function to get contact sync errors by id
   */
  public static function contactSyncErrors() {
    $syncerrors = [];
    if (CRM_Utils_Array::value('quickbookserrorid', $_REQUEST)) {
      $quickbookserrorid = CRM_Utils_Type::escape($_REQUEST['quickbookserrorid'], 'Integer');
      $accountcontact = civicrm_api3('AccountContact', 'get', [
        'id'          => $quickbookserrorid ,
        'sequential' => TRUE,
      ]);
      if ($accountcontact['count']) {
        $accountcontact = $accountcontact['values'][0];
        $syncerrors = $accountcontact['error_data'];
        $syncerrors = json_decode($syncerrors, TRUE);
      }
    }
    CRM_Utils_JSON::output($syncerrors);
  }

  /**
   * Function to get invoice sync errors by id
   *
   */
  public static function invoiceSyncErrors() {
    $syncerrors = [];
    if (CRM_Utils_Array::value('quickbookserrorid', $_REQUEST)) {
      $contactid = CRM_Utils_Type::escape($_REQUEST['quickbookserrorid'], 'Integer');
      $contributions = _civiquickbooks_getContactContributions($contactid);
      $invoices = _civiquickbooks_getErroredInvoicesOfContributions($contributions);
      foreach ($invoices['values'] as $invoice) {
        $syncerrors = array_merge($syncerrors, json_decode($invoice['error_data'], TRUE));
      }
    }
    CRM_Utils_JSON::output($syncerrors);
  }

}
