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

    header('Location: ' . $_SERVER['REQUEST_URI']);
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
