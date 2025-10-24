<?php
use CRM_Civiquickbooks_ExtensionUtil as E;

return [
  [
    'name' => 'Navigation_Accountsync_Quickbooks_Settings',
    'entity' => 'Navigation',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'domain_id' => 'current_domain',
        'label' => E::ts('Quickbooks Settings'),
        'name' => 'Quickbooks Settings',
        'url' => 'civicrm/admin/setting/quickbooks',
        'icon' => NULL,
        'permission' => [
          'administer CiviCRM',
        ],
        'permission_operator' => 'AND',
        'parent_id.name' => 'Accounts_System',
        'is_active' => TRUE,
        'has_separator' => 0,
        'weight' => 10,
      ],
    ],
  ],
];
