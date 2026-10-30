<?php

use CRM_Civiquickbooks_ExtensionUtil as E;

/**
 * Form controller class
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC43/QuickForm+Reference
 */
class CRM_Civiquickbooks_Form_Settings extends CRM_Admin_Form_Setting {

  public function buildQuickForm() {

    $QBCredentials = CRM_Quickbooks_APIHelper::getQuickBooksCredentials();

    $this->addFieldsDefinedInSettingsMetadata();

    $this->assign('entityInClassFormat', 'setting');
    $this->addButtons([
      [
        'type' => 'submit',
        'name' => ts('Submit'),
        'isDefault' => TRUE,
      ],
    ]);

    $exdate_element = $this->_elements[$this->_elementIndex['quickbooks_access_token_expiryDate']];
    $exdate_element->freeze();

    $refresh_exdate_element = $this->_elements[$this->_elementIndex['quickbooks_refresh_token_expiryDate']];
    $refresh_exdate_element->freeze();

    $isRefreshTokenExpired = CRM_Quickbooks_APIHelper::isTokenExpired($QBCredentials, TRUE);

    if ((!empty($QBCredentials['clientID']) && !empty($QBCredentials['clientSecret']) && empty($QBCredentials['accessToken']) && empty($QBCredentials['refreshToken']) && empty($QBCredentials['realMId'])) || $isRefreshTokenExpired) {
      $url = str_replace('&amp;', '&', CRM_Utils_System::url('civicrm/quickbooks/OAuth', NULL, TRUE, NULL));
    }
    $this->assign('redirect_url', $url ?? NULL);

    $this->assign('isRefreshTokenExpired', $isRefreshTokenExpired);

    $showClientKeysMessage = TRUE;
    if (!empty($QBCredentials['clientID']) && !empty($QBCredentials['clientSecret'])) {
      $showClientKeysMessage = FALSE;
    }

    $this->assign('showClientKeysMessage', $showClientKeysMessage);

    $this->assign('pageTitle', 'QuickBooks Online Settings');
  }

  public function postProcess() {
    parent::postProcess();
    $this->saveSettings();
    $this->queueContributions();

    header('Location: ' . $_SERVER['REQUEST_URI']);
  }


  /**
   * Queue contributions since Day zero if that option was checked.
   *
   * The option is an action, not a lasting setting, so it is cleared before the
   * work starts. The work then runs in batches through a queue runner, which
   * shows progress and returns to this page when it is done.
   */
  protected function queueContributions() {
    if (empty($this->_submitValues['quickbooks_queue_contributions'])) {
      return;
    }

    Civi::settings()->set('quickbooks_queue_contributions', FALSE);

    try {
      $runner = CRM_Civiquickbooks_ContributionQueue::getRunner();
    }
    catch (Exception $e) {
      CRM_Core_Session::setStatus(
        E::ts('Could not queue contributions: %1', [1 => $e->getMessage()]),
        E::ts('Contributions not queued'),
        'error'
      );
      return;
    }

    if (!$runner) {
      CRM_Core_Session::setStatus(
        E::ts('There are no contributions on or after Day zero to queue.'),
        E::ts('Contributions queued'),
        'info'
      );
      return;
    }

    // Redirects to the progress screen, and does not return.
    $runner->runAllViaWeb();
  }

  /**
   * Get the settings we are going to allow to be set on this form.
   *
   * @return array
   */
  public function saveSettings() {
    $values = $this->_submitValues;

    $previousCredentials = CRM_Quickbooks_APIHelper::getQuickBooksCredentials();
    $clientIDChanged = $previousCredentials['clientID'] !== $values['quickbooks_consumer_key'];
    $clientSecretChanged = $previousCredentials['clientSecret'] !== $values['quickbooks_shared_secret'];

    if ($clientIDChanged || $clientSecretChanged) {
      // invalidate anything that depended on the old Client ID or Shared Secret
      civicrm_api3(
        'setting', 'create', [
          "quickbooks_access_token" => '',
          "quickbooks_refresh_token" => '',
          "quickbooks_realmId" => '',
          "quickbooks_access_token_expiryDate" => '',
          "quickbooks_refresh_token_expiryDate" => '',
        ]
      );
    }
  }

}
