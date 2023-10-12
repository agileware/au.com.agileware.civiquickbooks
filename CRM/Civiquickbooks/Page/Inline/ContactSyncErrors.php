<?php

class CRM_Civiquickbooks_Page_Inline_ContactSyncErrors extends CRM_Core_Page {

  public function run() {
    $contactId = CRM_Utils_Request::retrieveValue('cid', 'Positive');
    if (!$contactId) {
      return;
    }
    self::addContactSyncErrorsBlock($this, $contactId);
    parent::run();
  }

  /**
   * @param CRM_Core_Page $page
   * @param int $contactID
   */
  public static function addContactSyncErrorsBlock(&$page, $contactID) {

    $hasContactErrors = FALSE;

    try{
      $account_contact = civicrm_api3('account_contact', 'getsingle', [
        'contact_id' => $contactID,
        'return'     => 'accounts_contact_id, accounts_needs_update, connector_id, error_data, id, contact_id',
        'plugin'     => _civiquickbooks_account_plugin_name(),
      ]);

      $page->assign('accountContactId', $account_contact['id']);

      if (!empty($account_contact['error_data'])) {
        $hasContactErrors = TRUE;
      }

    }
    catch(Exception $e) {

    }

    $page->assign('hasContactErrors', $hasContactErrors);
    $page->assign('contactID', $contactID);

  }

}
