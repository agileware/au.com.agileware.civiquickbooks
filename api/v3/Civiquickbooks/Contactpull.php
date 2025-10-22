<?php

/**
 * Civiquickbooks.Contactpull API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_civiquickbooks_Contactpull_spec(&$spec) {
  $spec['start_date'] = [
    'api.default' => 'yesterday',
    'type' => CRM_Utils_Type::T_DATE,
    'name' => 'start_date',
    'title' => 'Sync Start Date',
    'description' => 'date to start pulling from',
  ];
  $spec['connector_id'] = [
    'api.default' => 0,
    'type' => CRM_Utils_Type::T_INT,
    'name' => 'connector_id',
    'title' => 'Connector ID',
    'description' => 'Connector ID if using nz.co.fuzion.connectors, else 0',
  ];
}

/**
 * Civiquickbooks.Contactpull API
 *
 * @param array $params
 *
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 */
function civicrm_api3_civiquickbooks_Contactpull($params) {
  if (!CRM_Quickbooks_APIHelper::isAuthorized()) {
    throw new CRM_Core_Exception('Not authorized! Reauthorize QuickBooks application to continue syncing contacts and contributions updates to QuickBooks');
  }

  $quickbooks = new CRM_Civiquickbooks_Contact();
  $result = $quickbooks->pull($params);

  return civicrm_api3_create_success($result, $params, 'Civiquickbooks', 'Contactpull');
}
