<?php

/**
 * Civiquickbooks.ContactPush API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_civiquickbooks_ContactPush_spec(&$spec) {
  $spec['start_date'] = [
    'api.default' => 'yesterday',
    'type' => CRM_Utils_Type::T_DATE,
    'name' => 'start_date',
    'title' => 'Sync Start Date',
    'description' => 'date to start pushing from',
  ];
  $spec['connector_id'] = [
    'api.default' => 0,
    'type' => CRM_Utils_Type::T_INT,
    'name' => 'connector_id',
    'title' => 'Connector ID',
    'description' => 'Connector ID if using nz.co.fuzion.connectors, else 0',
  ];
  $spec['contact_id'] = [
    'name' => 'contact_id',
    'title' => 'contact ID',
    'description' => 'ID of the CiviCRM contact',
    'api.required' => 0,
    'type' => CRM_Utils_Type::T_INT,
  ];
}

/**
 * Civiquickbooks.ContactPush API
 *
 * @param array $params
 *
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws CRM_Core_Exception
 */
function civicrm_api3_civiquickbooks_ContactPush($params) {
  if (!CRM_Quickbooks_APIHelper::isAuthorized()) {
    throw new CRM_Core_Exception('Not authorized! Reauthorize QuickBooks application to continue syncing contacts and contributions updates to QuickBooks');
  }

  $options = _civicrm_api3_get_options_from_params($params);
  $params['limit'] = $options['limit'];

  $quickbooks = new CRM_Civiquickbooks_Contact();
  $output = $quickbooks->push($params);

  return civicrm_api3_create_success($output, $params, 'Civiquickbooks', 'Contactpush');
}
