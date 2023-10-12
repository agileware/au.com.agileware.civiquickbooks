<?php
// This file declares a managed database record of type "Job".
// The record will be automatically inserted, updated, or deleted from the
// database as appropriate. For more details, see "hook_civicrm_managed" at:
// http://wiki.civicrm.org/confluence/display/CRMDOC42/Hook+Reference

return [
  0 => [
    'name' => 'Civiquickbooks Contact Push Job',
    'entity' => 'Job',
    'update' => 'never',
    'params' => [
      'version' => 3,
      'name' => 'Civiquickbooks Contact Push Job',
      'description' => 'Push updated contacts to Quickbooks',
      'api_entity' => 'Civiquickbooks',
      'api_action' => 'Contactpush',
      'run_frequency' => 'Always',
      'parameters' => '',
    ],
  ],
  1 => [
    'name' => 'Civiquickbooks Contact Pull Job',
    'entity' => 'Job',
    'update' => 'never',
    'params' => [
      'version' => 3,
      'name' => 'Civiquickbooks Contact Pull Job',
      'description' => 'Pull updated contacts from Civiquickbooks',
      'api_entity' => 'Civiquickbooks',
      'api_action' => 'Contactpull',
      'run_frequency' => 'Always',
      'parameters' => 'start_date=yesterday',
    ],
  ],
  2 => [
    'name' => 'Civiquickbooks Invoice Push Job',
    'entity' => 'Job',
    'update' => 'never',
    'params' => [
      'version' => 3,
      'name' => 'Civiquickbooks Invoice Push Job',
      'description' => 'Push updated invoices to Quickbooks',
      'api_entity' => 'Civiquickbooks',
      'api_action' => 'Invoicepush',
      'run_frequency' => 'Always',
      'parameters' => '',
    ],
  ],
  3 => [
    'name' => 'Civiquickbooks Invoice Pull Job',
    'entity' => 'Job',
    'update' => 'never',
    'params' => [
      'version' => 3,
      'name' => 'Civiquickbooks Invoice Pull Job',
      'description' => 'Pull updated invoices from Quickbooks',
      'api_entity' => 'Civiquickbooks',
      'api_action' => 'Invoicepull',
      'run_frequency' => 'Always',
      'parameters' => '',
    ],
  ],
];
