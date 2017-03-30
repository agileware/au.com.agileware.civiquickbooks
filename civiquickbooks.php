<?php

require_once 'civiquickbooks.civix.php';

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function civiquickbooks_civicrm_config(&$config) {
  _civiquickbooks_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @param array $files
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function civiquickbooks_civicrm_xmlMenu(&$files) {
  _civiquickbooks_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function civiquickbooks_civicrm_install() {
  _civiquickbooks_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function civiquickbooks_civicrm_uninstall() {
  _civiquickbooks_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function civiquickbooks_civicrm_enable() {
  _civiquickbooks_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function civiquickbooks_civicrm_disable() {
  _civiquickbooks_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed
 *   Based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function civiquickbooks_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _civiquickbooks_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function civiquickbooks_civicrm_managed(&$entities) {
  _civiquickbooks_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types.
 *
 * @param array $caseTypes
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function civiquickbooks_civicrm_caseTypes(&$caseTypes) {
  _civiquickbooks_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function civiquickbooks_civicrm_angularModules(&$angularModules) {
  _civiquickbooks_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function civiquickbooks_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _civiquickbooks_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Functions below this ship commented out. Uncomment as required.
 *
 *
 * /**
 * Implements hook_civicrm_preProcess().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_preProcess
 *
 * function civiquickbooks_civicrm_preProcess($formName, &$form) {
 *
 * } // */

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * Adds entries to the navigation menu.
 *
 * @param array $menu
 */
function civiquickbooks_civicrm_navigationMenu(&$menu) {
  $maxID = CRM_Core_DAO::singleValueQuery("SELECT max(id) FROM civicrm_navigation");
  $navId = $maxID + 1;

  // Get the id of System Settings Menu
  $administerMenuId = CRM_Core_DAO::getFieldValue('CRM_Core_BAO_Navigation', 'Administer', 'id', 'name');
  $parentID = !empty($administerMenuId) ? $administerMenuId : NULL;

  $navigationMenu = array(
    'attributes' => array(
      'label' => 'QuickBooks',
      'name' => 'QuickBooks',
      'url' => null,
      'permission' => 'administer CiviCRM',
      'operator' => NULL,
      'separator' => NULL,
      'parentID' => $parentID,
      'active' => 1,
      'navID' => $navId,
    ),
    'child' => array(
      $navId + 1 => array(
        'attributes' => array(
          'label' => 'Quickbooks Settings',
          'name' => 'Quickbooks Settings',
          'url' => 'civicrm/quickbooks/settings',
          'permission' => 'administer CiviCRM',
          'operator' => NULL,
          'separator' => null,
          'active' => 1,
          'parentID' => $navId,
          'navID' => $navId + 1,
        ),
      ),

      $navId+2 => array (
        'attributes' => array(
          'label' => 'Synchronize contacts',
          'name' => 'Contact Sync',
          'url' => 'civicrm/a/#/accounts/contact/sync/quickbooks',
          'permission' => 'administer CiviCRM',
          'operator' => null,
          'separator' => null,
          'active' => 1,
          'parentID'   => $navId,
          'navID' => $navId + 2,
        ))

    ),
  );

  if ($parentID) {
    $menu[$parentID]['child'][$navId] = $navigationMenu;
  }
  else {
    $menu[$navId] = $navigationMenu;
  }
}

/**
 * Map quickbooks accounts data to generic data.
 *
 * @param array $accountsData
 * @param string $entity
 * @param string $plugin
 */
function civiquickbooks_civicrm_mapAccountsData(&$accountsData, $entity, $plugin) {
  if ($plugin != 'quickbooks' || $entity != 'contact') {
    return;
  }

  $accountsData['civicrm_formatted'] = array();

  $mappedFields = array(
    'DisplayName' => 'display_name',
    'GivenName' => 'first_name',
    'MiddleName' => 'middle_name',
    'FamilyName' => 'last_name',
    'PrimaryEmailAddr' => 'email',
  );

  /* Map primary email address */
  foreach ($mappedFields as $quickbooksField => $civicrmField) {
    if (isset($accountsData[$quickbooksField])) {
      if ($quickbooksField == 'PrimaryEmailAddr') {
        $exploded_by_comma = explode(',', $accountsData[$quickbooksField]['Address']);

        $accountsData['civicrm_formatted'][$civicrmField] = trim($exploded_by_comma[0]);
      }
      else {
        $accountsData['civicrm_formatted'][$civicrmField] = $accountsData[$quickbooksField];
      }
    }
  }

  /* Map Billing Address */
  if (isset($accountsData['BillAddr']) && is_array($accountsData['BillAddr'])) {
    $addressMappedFields = array(
      'Line1' => 'street_address',
      'City' => 'city',
      'PostalCode' => 'postal_code',
    );

    foreach ($addressMappedFields as $quickbooksField => $civicrmField) {
      if (isset($accountsData['BillAddr'][$quickbooksField])) {
        $accountsData['civicrm_formatted'][$civicrmField] = $accountsData['BillAddr'][$quickbooksField];
      }
    }
  }
  /* Map Shipping Address */
  elseif (isset($accountsData['ShipAddr']) && is_array($accountsData['ShipAddr'])) {
    $addressMappedFields = array(
      'Line1' => 'street_address',
      'City' => 'city',
      'PostalCode' => 'postal_code',
    );

    foreach ($addressMappedFields as $quickbooksField => $civicrmField) {
      if (isset($accountsData['ShipAddr'][$quickbooksField])) {
        $accountsData['civicrm_formatted'][$civicrmField] = $accountsData['ShipAddr'][$quickbooksField];
      }
    }
  }

  /* Map Primary Phone */
  if (isset($accountsData['PrimaryPhone']) && is_array($accountsData['PrimaryPhone'])) {
    if (isset($accountsData['PrimaryPhone']['FreeFormNumber'])) {
      $accountsData['civicrm_formatted']['phone'] = $accountsData['PrimaryPhone']['FreeFormNumber'];
    }
  }
}

/**
 * Implements hook_civicrm_accountsync_plugins().
 */
function civiquickbooks_civicrm_accountsync_plugins(&$plugins) {
  $plugins[] = 'quickbooks';
}
