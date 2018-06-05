<?php

use CRM_Civiquickbooks_ExtensionUtil as E;

/**
 * Form controller class
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC43/QuickForm+Reference
 */
class CRM_Civiquickbooks_Form_Settings extends CRM_Core_Form {
  private $_settingFilter = array('group' => 'civiquickbooks');
  // Form re-used from CiviXero
  private $_submittedValues = array();
  private $_settings = array();

  function buildQuickForm() {
    $settings = $this->getFormSettings();
    $description = array();

    $ckey = civicrm_api3('Setting', 'getvalue', array('group_name' => 'QuickBooks Online Settings', 'name' => 'quickbooks_consumer_key'));
    $ssecret = civicrm_api3('Setting', 'getvalue', array('group_name' => 'QuickBooks Online Settings', 'name' => 'quickbooks_shared_secret'));

    foreach ($settings as $name => $setting) {
      if (isset($setting['quick_form_type'])) {
        $add = 'add' . $setting['quick_form_type'];
        CRM_Core_Error::debug_var('setting[' . $name . ']', $setting);
        if ($add == 'addElement') {
          $this->$add($setting['html_type'], $name, $setting['title'], CRM_Utils_Array::value('html_attributes', $setting, array ()));
        }
        elseif ($setting['html_type'] == 'Select') {
          $optionValues = array();
          if (!empty($setting['pseudoconstant']) && !empty($setting['pseudoconstant']['optionGroupName'])) {
            $optionValues = CRM_Core_OptionGroup::values($setting['pseudoconstant']['optionGroupName'], FALSE, FALSE, FALSE, NULL, 'name');
          }
          $this->add('select', $setting['name'], $setting['title'], $optionValues, FALSE, $setting['html_attributes']);
        }
        else {
          $this->$add($name, $setting['title']);
        }

        $description[$name] = $setting['description'];
      }
    }

    $this->addButtons(array(
        array (
          'type' => 'submit',
          'name' => ts('Submit'),
          'isDefault' => TRUE,
        ),
      ));

    $descriptions_to_add_link = array(
      'quickbooks_consumer_key' => 'https://developer.intuit.com/docs/0100_quickbooks_online/0100_essentials/0085_develop_quickbooks_apps/0005_use_your_app_with_production_keys',
      'quickbooks_shared_secret' => 'https://developer.intuit.com/docs/0100_quickbooks_online/0100_essentials/0085_develop_quickbooks_apps/0001_your_first_request/0100_get_auth_tokens'
    );

    foreach ($descriptions_to_add_link as $key => $value) {
      $index = $this->_elementIndex[$key];
      $element = $this->_elements[$index];
    }

    $exdate_element = $this->_elements[$this->_elementIndex['quickbooks_access_token_expiryDate']];

    $exdate_element->freeze();

    if(!empty($ckey) && !empty($ssecret)) {
      $url = str_replace("&amp;", "&", CRM_Utils_System::url("civicrm/quickbooks/OAuth",null,true,null));
      $this->assign('redirect_url', $url);
    }

    $this->assign("description_array", $description);

    $this->assign("pageTitle", 'QuickBooks Online Settings');

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());

    parent::buildQuickForm();
  }

  function postProcess() {
    $this->_submittedValues = $this->exportValues();

    $settings = $this->saveSettings();
    parent::postProcess();
    header('Location: '.$_SERVER['REQUEST_URI']);
  }

  /**
   * Get the fields/elements defined in this form.
   *
   * @return array (string)
   */
  function getRenderableElementNames() {
    // The _elements list includes some items which should not be
    // auto-rendered in the loop -- such as "qfKey" and "buttons". These
    // items don't have labels. We'll identify renderable by filtering on
    // the 'label'.
    $elementNames = array();
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
  function getFormSettings() {
    if (empty($this->_settings)) {
      $settings = civicrm_api3('setting', 'getfields', array('filters' => $this->_settingFilter));
    }
    $extraSettings = civicrm_api3('setting', 'getfields', array('filters' => array('group' => 'accountsync')));
    $settings = $settings['values'] + $extraSettings['values'];
    return $settings;
  }

  /**
   * Get the settings we are going to allow to be set on this form.
   *
   * @return array
   */
  function saveSettings() {
    $settings = $this->getFormSettings();
    $values = array_intersect_key($this->_submittedValues, $settings);
    $default = $this->setDefaultValues();

    civicrm_api3('setting', 'create', $values);

    if($values['consumer_key'] !== $default['consumer_key'] || $values['shared_secret'] !== $default['shared_secret']) {
      civicrm_api3(
        'setting', 'create', array(
          "quickbooks_access_token" => '',
          "quickbooks_access_token_secret" => '',
          "quickbooks_access_token_expiryDate" =>
          'No access token and secret available',
        )
      );
    }

    return $settings;
  }

  /**
   * Set defaults for form.
   *
   * @see CRM_Core_Form::setDefaultValues()
   */
  function setDefaultValues() {
    $existing = civicrm_api3('setting', 'get', array('return' => array_keys($this->getFormSettings())));
    $defaults = array();
    $domainID = CRM_Core_Config::domainID();
    foreach ($existing['values'][$domainID] as $name => $value) {
      $defaults[$name] = $value;
    }
    return $defaults;
  }
}
