<?php

class CRM_Civiquickbooks_Page_Inline_ContactSyncStatus extends CRM_Core_Page {

  public function run() {
    $contactId = CRM_Utils_Request::retrieveValue('cid', 'Positive');
    if (!$contactId) {
      return;
    }
    self::addContactSyncStatusBlock($this, $contactId);
    parent::run();
  }

  /**
   * @param CRM_Core_Page $page
   * @param int $contactID
   */
  public static function addContactSyncStatusBlock(&$page, $contactID) {

    // One of: not_queued, queued, synced, error, do_not_sync.
    $syncStatus = 'not_queued';
    $accountContactID = NULL;

    try {
      $account_contact = civicrm_api3('account_contact', 'getsingle', [
        'contact_id' => $contactID,
        'return'     => 'accounts_contact_id, accounts_needs_update, connector_id, error_data, do_not_sync, id, contact_id',
        'plugin'     => _civiquickbooks_account_plugin_name(),
      ]);
      $accountContactID = $account_contact['id'];

      // A failed push leaves the contact queued, so check for an error first.
      if (!empty($account_contact['do_not_sync'])) {
        $syncStatus = 'do_not_sync';
      }
      elseif (!empty($account_contact['error_data'])) {
        $syncStatus = 'error';
      }
      elseif (!empty($account_contact['accounts_needs_update'])) {
        $syncStatus = 'queued';
      }
      elseif (!empty($account_contact['accounts_contact_id'])) {
        $syncStatus = 'synced';
      }
    }
    catch (Exception $e) {
      // No AccountContact record yet.
    }

    $page->assign('syncStatus', $syncStatus);
    $page->assign('accountContactID', $accountContactID);
    // The links save through the AccountContact API, which needs this permission.
    $page->assign('canManageSync', CRM_Core_Permission::check('administer CiviCRM'));
    $page->assign('contactID', $contactID);

  }

}
