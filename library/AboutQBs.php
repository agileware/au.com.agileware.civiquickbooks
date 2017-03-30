<?php

/**
 * Get a Civiquickbooks setting.
 *
 * @param $param - one of 'quickbooks_access_token_expiryDate', 'quickbooks_consumer_key',
 *        'quickbooks_shared_secret', 'quickbooks_realmId', 'quickbooks_access_token', 'quickbooks_access_token_secret' or 'quickbooks_company_country'
 */
function get_QB_setting_value($param) {
  switch($param) {
    case 'quickbooks_access_token_expiryDate':
    case 'quickbooks_consumer_key':
    case 'quickbooks_shared_secret':
    case 'quickbooks_realmId':
    case 'quickbooks_access_token':
    case 'quickbooks_access_token_secret':
    case 'quickbooks_company_country':
      $return = civicrm_api3('Setting', 'getvalue', array('name' => $param));

      if(empty($return) && ($param == 'quickbooks_consumer_key' || $param == 'quickbooks_shared_secret')) {
        return FALSE;
      }
      else {
        return $return;
      }
    default:
      return FALSE;
  }
}

/**
 * Set a Civiquickbooks setting.
 *
 * @param $param - one of 'quickbooks_access_token_expiryDate', 'quickbooks_consumer_key',
 *        'quickbooks_shared_secret', 'quickbooks_realmId', 'quickbooks_access_token', 'quickbooks_access_token_secret' or 'quickbooks_company_country'
 *
 * @param $value - the new value of the setting.
 */
function set_QB_setting_value($param,$value) {
  switch($param) {
    case 'quickbooks_access_token_expiryDate':
    case 'quickbooks_consumer_key':
    case 'quickbooks_shared_secret':
    case 'quickbooks_realmId':
    case 'quickbooks_access_token':
    case 'quickbooks_access_token_secret':
    case 'quickbooks_company_country':
      $result = civicrm_api3('Setting', 'create', array($param => $value));
      return !empty($result);
    default:
      return FALSE;
  }
}
