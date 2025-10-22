<?php

/**
 * Civiquickbooks.InvoicePush API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_civiquickbooks_InvoicePush_spec(&$spec) {
  $spec['contribution_id'] = [
    'type' => CRM_Utils_Type::T_INT,
    'name' => 'contribution_id',
    'title' => 'Contribution ID',
    'description' => 'contribution id (optional, overrides needs_update flag)',
  ];
}

/**
 * Civiquickbooks.InvoicePush API
 *
 * @param array $params
 *
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws CRM_Core_Exception
 */
function civicrm_api3_civiquickbooks_InvoicePush($params) {
  if (!CRM_Quickbooks_APIHelper::isAuthorized()) {
    throw new CRM_Core_Exception('Not authorized! Reauthorize QuickBooks application to continue syncing contacts and contributions updates to QuickBooks');
  }

  $options = _civicrm_api3_get_options_from_params($params);

  $quickbooks = new CRM_Civiquickbooks_Invoice();
  $result = $quickbooks->push($params, $options['limit']);

  return civicrm_api3_create_success($result, $params, 'Civiquickbooks', 'Invoicepush');
}
