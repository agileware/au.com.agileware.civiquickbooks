<?php

use CRM_Civiquickbooks_ExtensionUtil as E;

/**
 * Form controller class
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC43/QuickForm+Reference
 */
class CRM_Civiquickbooks_Form_Settings extends CRM_Core_Form {

  private $_settingFilter = ['group' => 'civiquickbooks'];

  // Form re-used from CiviXero
  private $_submittedValues = [];

  private $_settings = [];

  public function buildQuickForm() {
    $settings = $this->getFormSettings();
    $description = [];

    $QBCredentials = CRM_Quickbooks_APIHelper::getQuickBooksCredentials();

    foreach ($settings as $name => $setting) {
      if (isset($setting['quick_form_type'])) {
        $add = 'add' . $setting['quick_form_type'];

        if ($add == 'addElement') {
          $this->$add($setting['html_type'], $name, $setting['title'], CRM_Utils_Array::value('html_attributes', $setting, []));
        }
        elseif ($setting['html_type'] == 'Select') {
          $optionValues = [];
          if (!empty($setting['pseudoconstant']) && !empty($setting['pseudoconstant']['optionGroupName'])) {
            $optionValues = CRM_Core_OptionGroup::values($setting['pseudoconstant']['optionGroupName'], FALSE, FALSE, FALSE, NULL, 'name');
          }
          elseif (!empty($setting['pseudoconstant']) && !empty($setting['pseudoconstant']['callback'])) {
            $callBack = Civi\Core\Resolver::singleton()
              ->get($setting['pseudoconstant']['callback']);
            $optionValues = call_user_func_array($callBack, $optionValues);
          }
          $this->add('select', $setting['name'], $setting['title'], $optionValues, FALSE, $setting['html_attributes']);
        }
        else {
          $this->$add($name, $setting['title']);
        }

        $description[$name] = $setting['description'];
      }
    }

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
      $url = str_replace("&amp;", "&", CRM_Utils_System::url("civicrm/quickbooks/OAuth", NULL, TRUE, NULL));
      $this->assign('redirect_url', $url);
    }

    $this->assign('isRefreshTokenExpired', $isRefreshTokenExpired);

    $showClientKeysMessage = TRUE;
    if (!empty($QBCredentials['clientID']) && !empty($QBCredentials['clientSecret'])) {
      $showClientKeysMessage = FALSE;
    }

    $this->assign('showClientKeysMessage', $showClientKeysMessage);

    $this->assign("description_array", $description);

    $this->assign("pageTitle", 'QuickBooks Online Settings');

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());

    parent::buildQuickForm();
  }

  public function postProcess() {
    $this->_submittedValues = $this->exportValues();

    $this->saveSettings();
    parent::postProcess();
    header('Location: ' . $_SERVER['REQUEST_URI']);
  }

  /**
   * Get the fields/elements defined in this form.
   *
   * @return array (string)
   */
  public function getRenderableElementNames() {
    // The _elements list includes some items which should not be
    // auto-rendered in the loop -- such as "qfKey" and "buttons". These
    // items don't have labels. We'll identify renderable by filtering on
    // the 'label'.
    $elementNames = [];
    foreach ($this->_elements as $element) {
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }

  /**
   * Get the settings we are going to allow to be set on this form.
   *
   * @return array
   */
  public function getFormSettings() {
    if (empty($this->_settings)) {
      $settings = civicrm_api3('setting', 'getfields', ['filters' => $this->_settingFilter]);
    }
    $extraSettings = civicrm_api3('setting', 'getfields', ['filters' => ['group' => 'accountsync']]);
    $settings = $settings['values'] + $extraSettings['values'];
    return $settings;
  }

  /**
   * Get the settings we are going to allow to be set on this form.
   *
   * @return array
   */
  public function saveSettings() {
    $settings = $this->getFormSettings();
    $values = array_intersect_key($this->_submittedValues, $settings);

    // Fix for unsetting a checkbox. When setting a checkbox,
    // quickbooks_bool_value => 1 is returned. But when unsetting a checkbox,
    // quickbooks_bool_value => 0 is NOT returned. Absence of a checkbox
    // attribute should be interpreted as setting its value to 0.
    foreach ($settings as $setting_name => $setting_info) {
        if ($setting_info['type'] == "Boolean" && !array_key_exists($setting_name, $values)) {
            $values[$setting_name] = 0;
        }
    }

    civicrm_api3('setting', 'create', $values);

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

    return $settings;
  }

  /**
   * Set defaults for form.
   *
   * @see CRM_Core_Form::setDefaultValues()
   */
  public function setDefaultValues() {
    $existing = civicrm_api3('setting', 'get', ['return' => array_keys($this->getFormSettings())]);
    $defaults = [];
    $domainID = CRM_Core_Config::domainID();
    foreach ($existing['values'][$domainID] as $name => $value) {
      $defaults[$name] = $value;
    }
    return $defaults;
  }

}
