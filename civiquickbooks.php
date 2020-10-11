<?php
/** @noinspection HtmlUnknownTarget */

require_once 'civiquickbooks.civix.php';

use CRM_Civiquickbooks_ExtensionUtil as E;

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
 * Implementation of hook_civicrm_postInstall
 */
function civiquickbooks_civicrm_postInstall() {
  _civiquickbooks_civix_civicrm_postInstall();
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
 * Implements hook_civicrm_navigationMenu().
 *
 * Adds entries to the navigation menu.
 */
function civiquickbooks_civicrm_navigationMenu(&$menu) {
  $item[] = [
    'label' => E::ts('QuickBooks'),
    'name' => 'QuickBooks',
    'url' => NULL,
    'permission' => 'administer CiviCRM',
    'operator' => NULL,
    'separator' => NULL,
  ];
  _civiquickbooks_civix_insert_navigation_menu($menu, 'Administer', $item[0]);

  $item[] = [
    'label' => E::ts('Quickbooks Settings'),
    'name' => 'Quickbooks Settings',
    'url' => 'civicrm/quickbooks/settings',
    'permission' => 'administer CiviCRM',
    'operator' => NULL,
    'separator' => NULL,
  ];
  _civiquickbooks_civix_insert_navigation_menu($menu, 'Administer/QuickBooks', $item[1]);

  $item[] = [
    'label' => E::ts('Synchronize contacts'),
    'name' => 'Contact Sync',
    'url' => 'civicrm/a/#/accounts/contact/sync/quickbooks',
    'permission' => 'administer CiviCRM',
    'operator' => NULL,
    'separator' => NULL,
  ];
  _civiquickbooks_civix_insert_navigation_menu($menu, 'Administer/QuickBooks', $item[2]);
  _civiquickbooks_civix_navigationMenu($menu);
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

/**
 * Adding a refresh token check to the status Page/System.
 *
 * Implements hook_civicrm_check().
 */
function civiquickbooks_civicrm_check(&$messages) {
  $QBCredentials = CRM_Quickbooks_APIHelper::getQuickBooksCredentials();
  $isRefreshTokenExpired = CRM_Quickbooks_APIHelper::isTokenExpired($QBCredentials, TRUE);
  if ($isRefreshTokenExpired) {
    $messages[] = (new CRM_Utils_Check_Message(
      'quickbooks_refresh_token_expired',
      ts('QuickBooks refresh is token is expired, <a href="%1">Reauthorize QuickBooks application</a> to continue syncing contacts and contributions updates to QuickBooks.', array(
        1 => CRM_Utils_System::url('civicrm/quickbooks/OAuth', ''),
      )),
      ts('QuickBooks Token Expired'),
      \Psr\Log\LogLevel::CRITICAL,
      'fa-clock-o'
    ));
  }
}

/**
 * Get plugin name.
 *
 * @return string
 */
function _civiquickbooks_account_plugin_name() {
  return 'quickbooks';
}

/**
 * Gettings contributions of sinlge contact
 *
 * @param $contactid
 */
function _civiquickbooks_getContactContributions($contactid) {
  $contributions = civicrm_api3("Contribution", "get", array(
    "contact_id" => $contactid,
    "return"     => array("contribution_id"),
    "sequential" => TRUE,
  ));
  $contributions = array_column($contributions["values"], "id");
  return $contributions;
}

/**
 * Gettings errored invoices of given contributions
 *
 * @param $contributions
 */
function _civiquickbooks_getErroredInvoicesOfContributions($contributions) {
  $invoices = civicrm_api3("AccountInvoice", "get", array(
    "plugin"          => _civiquickbooks_account_plugin_name(),
    "sequential"      => TRUE,
    "contribution_id" => array("IN" => $contributions),
    "error_data"      => array("<>" => ""),
  ));
  return $invoices;
}

/**
 * Implements hook_civicrm_contactSummaryBlocks().
 *
 * @link https://github.com/civicrm/org.civicrm.contactlayout
 */
function civiquickbooks_civicrm_contactSummaryBlocks(&$blocks) {
  $blocks += [
    'civiquickbooksblock' => [
      'title' => ts('Civi QuickBooks'),
      'blocks' => [],
    ]
  ];
  $blocks['civiquickbooksblock']['blocks']['contactsyncstatus'] = [
    'title' => ts('Contact Sync Status'),
    'tpl_file' => 'CRM/Civiquickbooks/Page/Inline/ContactSyncStatus.tpl',
    'edit' => FALSE,
  ];
  $blocks['civiquickbooksblock']['blocks']['contactsyncerrors'] = [
    'title' => ts('Contact Sync Errors'),
    'tpl_file' => 'CRM/Civiquickbooks/Page/Inline/ContactSyncErrors.tpl',
    'edit' => FALSE,
  ];
  $blocks['civiquickbooksblock']['blocks']['invoicesyncerrors'] = [
    'title' => ts('Invoice Sync Errors'),
    'tpl_file' => 'CRM/Civiquickbooks/Page/Inline/InvoiceSyncErrors.tpl',
    'edit' => FALSE,
  ];

}

/**
 * Implements hook pageRun().
 *
 * Add QuickBooks links to contact summary
 *
 * @param $page
 */
function civiquickbooks_civicrm_pageRun(&$page) {
  $pageName = get_class($page);
  if ($pageName != 'CRM_Contact_Page_View_Summary' || !CRM_Core_Permission::check('view all contacts')) {
    return;
  }

  if (($contactID = $page->getVar('_contactId')) != FALSE) {

    CRM_Core_Resources::singleton()->addScriptFile('au.com.agileware.civiquickbooks', 'js/civiquickbooks_errors.js');

    CRM_Civiquickbooks_Page_Inline_ContactSyncStatus::addContactSyncStatusBlock($page, $contactID);
    CRM_Civiquickbooks_Page_Inline_ContactSyncErrors::addContactSyncErrorsBlock($page, $contactID);
    CRM_Civiquickbooks_Page_Inline_InvoiceSyncErrors::addInvoiceSyncErrorsBlock($page, $contactID);

    CRM_Core_Region::instance('contact-basic-info-right')->add(array(
      'template' => "CRM/Civiquickbooks/ContactSyncBlock.tpl",
    ));
  }
}
