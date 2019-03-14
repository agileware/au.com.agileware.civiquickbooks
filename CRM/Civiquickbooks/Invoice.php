<?php

require getComposerAutoLoadPath();

class CRM_Civiquickbooks_Invoice {

  protected $_quickbooks_is_us_company_flag;

  private $_plugin = 'quickbooks';

  protected $contribution_status_settings;

  protected $contribution_status_settings_lower_reverse;

  public function __construct() {
    $this->contribution_status_settings = civicrm_api3('Contribution', 'getoptions', array('field' => 'contribution_status_id'));

    $this->contribution_status_settings = $this->contribution_status_settings['values'];

    $this->contribution_status_settings_lower_reverse = array();

    foreach ($this->contribution_status_settings as $key => $value) {
      $this->contribution_status_settings[$key] = strtolower($value);

      $this->contribution_status_settings_lower_reverse[strtolower($value)] = $key;
    }
  }

  /**
   * Push invoices to QuickBooks from the civicrm_account_contact with
   * 'needs_update' = 1.
   *
   * We call the civicrm_accountPullPreSave hook so other modules can alter if
   * required
   *
   * @param array $params
   *  - start_date
   *
   * @param int $limit
   *   Number of invoices to process
   *
   * @return bool
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function push($params = array(), $limit = PHP_INT_MAX) {
    try {
      $records = $this->getContributionsRequiringPushUpdate($params, $limit);
      $errors = array();

      // US companies handles the tax in Invoice differently
      $quickbooks_current_company_country = civicrm_api3('Setting', 'getvalue', array(
        'name' => "quickbooks_company_country",
        'group' => 'QuickBooks Online Settings',
      ));
      $this->_quickbooks_is_us_company_flag = ($quickbooks_current_company_country == 'US') ? TRUE : FALSE;

      foreach ($records['values'] as $i => $record) {
        try {
          $accountsInvoice = $this->getAccountsInvoice($record);
          $dataService = CRM_Quickbooks_APIHelper::getAccountingDataServiceObject();

          if ($accountsInvoice->Id) {
            $result = $dataService->Update($accountsInvoice);
          }
          else {
            $result = $dataService->Add($accountsInvoice);
          }

          $responseErrors = $this->savePushResponse($result, $record, $dataService);
          if (!empty($responseErrors)) {
            $errors[] = $responseErrors;
          }
        } catch (CiviCRM_API3_Exception $e) {
          $this_error = $errors[] = ts('Failed to store %1 with error %2.', array(
            1 => $record['contribution_id'],
            2 => $e->getMessage(),
          ));
          CRM_Core_Error::debug_var($this_error, CRM_Core_Error::formatBacktrace($e->getTrace()));
        }
      }

      if ($errors) {
        // since we expect this to wind up in the job log we'll print the errors
        throw new CRM_Core_Exception(ts('Not all records were saved ') . json_encode($errors, JSON_PRETTY_PRINT), 'incomplete', $errors);
      }
      return TRUE;
    } catch (CiviCRM_API3_Exception $e) {
      throw new CRM_Core_Exception('Invoice Push aborted due to: ' . $e->getMessage());
    }
  }

  public function pull($params = array(), $limit = PHP_INT_MAX) {
    try {
      $records = $this->getContributionsRequiringPullUpdate($params, $limit);

      $errors = array();

      foreach ($records['values'] as $i => $record) {
        try {
          //double check if the record has been synched or not
          if (!isset($record['accounts_invoice_id']) || !isset($record['accounts_data'])) {
            continue;
          }

          $invoice = $this->getInvoiceFromQBs($record);
          if ($invoice instanceof \QuickBooksOnline\API\Data\IPPInvoice) {
            $result = $this->saveToCiviCRM($invoice, $record);
          }
          else {
            $errors[] = $invoice;
          }
        } catch (CiviCRM_API3_Exception $e) {
          $errors[] = ts('Failed to store contribution %1 for invoice %2 with error: "%3".  Invoice pull failed.', array(
            1 => $record['contribution_id'],
            2 => $invoice['Id'],
            3 => $e->getMessage(),
          ));
        }
      }

      if ($errors) {
        // since we expect this to wind up in the job log we'll print the errors
        throw new CRM_Core_Exception(ts('Not all records were saved: ') . json_encode($errors, JSON_PRETTY_PRINT), 'incomplete', $errors);
      }
      return TRUE;
    } catch (CiviCRM_API3_Exception $e) {
      throw new CRM_Core_Exception('Invoice Pull aborted due to: ' . $e->getMessage());
    }
  }

  protected function getContributionInfo($contributionID) {
    if (!isset($contributionID)) {
      return FALSE;
    }

    $db_contribution = civicrm_api3('Contribution', 'getsingle', array(
      'return' => array('contribution_status_id'),
      'id' => $contributionID,
    ));

    $db_contribution['contri_status_in_lower'] = strtolower($this->contribution_status_settings[$db_contribution['contribution_status_id']]);

    return $db_contribution;
  }

  protected function getInvoiceFromQBs($record) {
    $dataService = CRM_Quickbooks_APIHelper::getAccountingDataServiceObject();
    $invoice = $dataService->FindById('invoice', $record['accounts_invoice_id']);
    $error = $dataService->getLastError();

    if ($error || !$invoice) {
      return $error->getResponseBody();
    }

    return $invoice;
  }

  protected function saveToCiviCRM($invoice, $record) {
    if ((int) $record['accounts_data'] == (int) $invoice->SyncToken) {
      return FALSE;
    }

    $invoice_status = $this->parseInvoiceStatus($invoice);

    $contribution = $this->getContributionInfo($record['contribution_id']);

    if ($invoice_status == 'paid') {
      if ($contribution['contri_status_in_lower'] != 'completed') {
        $result = civicrm_api3('Contribution', 'completetransaction', array(
          'id' => $record['contribution_id'],
          'is_email_receipt' => 0,
        ));

        if ($result['is_error']) {
          throw new CiviCRM_API3_Exception('Contribution status update failed: id: ' . $record['contribution_id'] . ' of Invoice ' . $invoice['Id'], 'qbo_contribution_status');
        }

        $record['accounts_needs_update'] = 0;
        $record['accounts_status_id'] = 3;

        CRM_Core_DAO::setFieldValue(
          'CRM_Accountsync_DAO_AccountInvoice',
          $record['id'],
          'accounts_modified_date',
          date('Y-m-d H:i:s', strtotime($invoice->MetaData->LastUpdatedTime)),
          'id');
      }
    }
    elseif ($invoice_status == 'voided') {
      if ($contribution['contri_status_in_lower'] != 'cancelled') {
        $result = CRM_Core_DAO::setFieldValue('CRM_Contribute_DAO_Contribution', $record['contribution_id'], 'contribution_status_id', $this->contribution_status_settings_lower_reverse['cancelled'], 'id');

        if ($result == FALSE) {
          throw new CiviCRM_API3_Exception('Contribution status update failed: id: ' . $record['contribution_id'] . ' of Invoice ' . $invoice['Id'], 'qbo_contribution_status');
        }

        $record['accounts_needs_update'] = 0;

        CRM_Core_DAO::setFieldValue(
          'CRM_Accountsync_DAO_AccountInvoice',
          $record['id'],
          'accounts_modified_date',
          date('Y-m-d H:i:s', strtotime($invoice->MetaData->LastUpdatedTime)),
          'id');
      }
    }

    // This will update the last sync date & anything hook-modified
    unset($record['last_sync_date']);

    unset($record['accounts_modified_date']);

    // Must update synctoken as any modification in QBs end will change the origional token
    $record['accounts_data'] = $invoice->SyncToken;

    $result = civicrm_api3('AccountInvoice', 'create', $record);

    return TRUE;
  }

  protected function parseInvoiceStatus($invoice) {
    $due_date = strtotime($invoice->DueDate);
    $txn_date = strtotime($invoice->TxnDate);

    $balance = (float) $invoice->Balance;
    $total_amt = (float) $invoice->TotalAmt;
    $private_note = $invoice->PrivateNote;

    if ($total_amt == 0 && $balance == 0 && strpos($private_note, 'Voided') !== FALSE) {
      return 'voided';
    }
    elseif ($balance == 0) {
      return 'paid';
    }
    elseif ($due_date <= $txn_date || $due_date <= strtotime('now')) {
      return 'overdue';
    }
    elseif ($balance === $total_amt) {
      return 'open';
    }
    else {
      return 'partial';
    }
  }

  /**
   * Get invoice formatted for QuickBooks.
   *
   * @param array $record
   *
   * @return array
   * @throws \CiviCRM_API3_Exception
   */
  protected function getAccountsInvoice($record) {
    $accountsInvoiceID = isset($record['accounts_invoice_id']) ? $record['accounts_invoice_id'] : NULL;

    $SyncToken = isset($record['accounts_data']) ? $record['accounts_data'] : NULL;

    $contributionID = $record['contribution_id'];

    $db_contribution = civicrm_api3('Contribution', 'getsingle', array(
      'return' => array(
        'contribution_status_id',
        'receive_date',
        'contribution_source',
      ),
      'id' => $contributionID,
    ));

    $db_contribution['status'] = $this->contribution_status_settings[$db_contribution['contribution_status_id']];

    $cancelledStatuses = array('failed', 'cancelled');

    $qb_account = civicrm_api3('account_contact', 'getsingle', array(
      'contact_id' => $db_contribution['contact_id'],
      'plugin' => $this->_plugin,
      'connector_id' => 0,
    ));

    $qb_id = $qb_account['accounts_contact_id'];

    if (in_array(strtolower($db_contribution['status']), $cancelledStatuses)) {
      //according to the revised task description, we are not going to synch cancelled or failed contributions that are just created and not synched before.
      if (isset($accountsInvoiceID) && isset($SyncToken)) {
        $accountsInvoice = $this->mapCancelled($accountsInvoiceID, $SyncToken);
        return $accountsInvoice;
      }
      else {
        return NULL;
      }
    }
    else {
      $accountsInvoice = $this->mapToAccounts($db_contribution, $accountsInvoiceID, $SyncToken, $qb_id);
      return $accountsInvoice;
    }
  }

  /**
   * Map CiviCRM array to Accounts package field names.
   *
   * @param array $db_contribution - require
   *  contribution fields
   *   - line items
   *   - receive date
   *   - source
   *   - contact_id
   * @param int $accountsID
   *
   * @return array|bool
   *   Contact Object/ array as expected by accounts package
   */
  protected function mapToAccounts($db_contribution, $accountsID, $SyncToken, $qb_id) {
    static $tmp = NULL;
    $new_invoice = array();
    $contri_status_in_lower = strtolower($db_contribution['status']);

    //those contributions we care
    $status_array = array('pending', 'completed', 'partially paid');

    if (in_array($contri_status_in_lower, $status_array)) {
      $contributionID = $db_contribution['id'];

      $db_line_items = civicrm_api3('LineItem', 'get', array(
        'contribution_id' => $contributionID,
      ));

      if (empty($db_line_items['count'])) {
        throw new CiviCRM_API3_Exception('No line item in contribution(ID:' . $contributionID . '). Invoice push for this one aborted!', 'qbo_contribution_line_item');
        return FALSE;
      }

      $lineItems = array();

      /* static array for storing financial type and its Inc account's accounting code.
       * key: financial type id.
       * value: the accounting code of the Inc financial account of this financial type.*/
      static $acctgcode_for_itemref = array();

      /* static array for mapping item name from Quickbooks and its item refer id.
       * key: account code (item name in Quickbooks).
       * value:  Quickbooks' Item id */
      static $ItemRefs = array();

      /* static array for storing financial type and its Inc account's accounting code.
       * key: financial type id.
       * value: the accounting code of the sales tax account of this financial type.*/
      static $_acctgcode_for_taxref = array();

      /* static array for mapping item name from Quickbooks and its item refer id.
       * key: Tax account code (Tax account name in Quickbooks).
       * value:  Quickbooks' Tax account id */
      static $_TaxRefs = array();

      $_tmp_result = NULL;

      $tmp_acctgcode = array();
      $tmp_ItemRefs = array();

      $_tmp_civi_tax_account_code = array();
      $_tmp_TaxRefs = array();

      //Collect all accounting codes for all line items
      foreach ($db_line_items['values'] as $id => $lineItem) {
        //get Inc Account accounting code if it is not collected previously
        if (!isset($acctgcode_for_itemref[$lineItem['financial_type_id']])) {
          $tmp = htmlspecialchars_decode(CRM_Financial_BAO_FinancialAccount::getAccountingCode($lineItem['financial_type_id']));

          $acctgcode_for_itemref[$lineItem['financial_type_id']] = $tmp;
          $tmp_acctgcode[] = $tmp;
        }

        $db_line_items['values'][$id]['acctgCode'] = $acctgcode_for_itemref[$lineItem['financial_type_id']];

        //get Sales Tax Account accounting code if it is not collected previously
        if (!isset($_acctgcode_for_taxref[$lineItem['financial_type_id']])) {
          try {
            $_tmp_result = civicrm_api3('EntityFinancialAccount', 'getsingle', array(
              'sequential' => 1,
              'return' => array("financial_account_id"),
              'entity_id' => $lineItem['financial_type_id'],
              'entity_table' => "civicrm_financial_type",
              'account_relationship' => "Sales Tax Account is",
            ));

            $_tmp_result = civicrm_api3('FinancialAccount', 'getsingle', array(
              'sequential' => 1,
              'id' => $_tmp_result['financial_account_id'],
            ));

            $tmp = htmlspecialchars_decode($_tmp_result['accounting_code']);

            // We will use account type code to get state tax code id for US companies
            $_acctgcode_for_taxref[$lineItem['financial_type_id']] = array(
              'sale_tax_acctgCode' => $tmp,
              'sale_tax_account_type_code' => htmlspecialchars_decode($_tmp_result['account_type_code']),
            );

            $_tmp_civi_tax_account_code[] = $tmp;
          } catch (CiviCRM_API3_Exception $e) {

          }
        }

        $db_line_items['values'][$id]['sale_tax_acctgCode'] = $_acctgcode_for_taxref[$lineItem['financial_type_id']]['sale_tax_acctgCode'];

        // We will use account type code to get state tax code id for US companies
        $db_line_items['values'][$id]['sale_tax_account_type_code'] = $_acctgcode_for_taxref[$lineItem['financial_type_id']]['sale_tax_account_type_code'];
      }

      //If we have collected any Sales Tax accounting code, request their information from Quickbooks.
      //For US companies, this process is not needed, as the `TaxCodeRef` for each line item is either `NON` or `TAX`.
      if (!empty($_tmp_civi_tax_account_code) && !$this->_quickbooks_is_us_company_flag) {
        $_tmp_TaxRefs = $this->getTaxRefs($_tmp_civi_tax_account_code);

        if ($_tmp_TaxRefs) {

          $tmp = array();

          //putting the name, id we got from Quickbooks into a temp array, with name as the key
          foreach ($_tmp_TaxRefs as $value) {
            $tmp[$value->Name] = $value->Id;
          }

          //add our temp array on static array
          $_TaxRefs = $_TaxRefs + $tmp;
        }
      }

      //If we have collected any item Income accounting code, request their information from Quickbooks.
      if (!empty($tmp_acctgcode)) {
        $tmp_ItemRefs = $this->getItemRefs($tmp_acctgcode);

        if ($tmp_ItemRefs) {
          $tmp = array();

          //putting the name, id we got from Quickbooks into a temp array, with name as the key
          foreach ($tmp_ItemRefs as $value) {
            $tmp[$value->Name] = $value->Id;
          }

          //add our temp array on static array
          $ItemRefs = $ItemRefs + $tmp;
        }
      }

      $tmp = array();
      $i = 1;

      $_error_msg_to_QBs = NULL;

      $_error_msg_for_item_acctgcode = NULL;

      $_error_msg_for_tax_acctgcode = NULL;

      //looping through all line items and create an array that contains all necessary info for each line item.
      foreach ($db_line_items['values'] as $id => $lineItem) {
        $line_item_description = str_replace(array('&nbsp;'), ' ', $lineItem['label']);

        if (!isset($ItemRefs[$lineItem['acctgCode']])) {
          // If we have any line items that does not have a matched accounting code in Quickbooks.
          // We are not going to include this line item in quickbooks, and include this error in Customer memo as a public not in this Invoice.
          // Customer memo can be edited by Quickbooks Users and they can use this error message to find the corresponding contribution in CiviCRM and find the
          // line item that is wrong.

          if (empty($_error_msg_for_item_acctgcode)) {
            //@TODO fix engrish
            $_error_msg_for_item_acctgcode = "Line item could not be added; the Income Accounts for the Financial Type of this item have no accounting code configured.  Please correct this invoice manually:\n" .
              'ID: ' . $lineItem['financial_type_id'] . ' Inc_acctgcode: ' . $lineItem['acctgCode'];
          }
          else {
            $_error_msg_for_item_acctgcode .= ', ID: ' . $lineItem['financial_type_id'] . ' Inc_acctgcode: ' . $lineItem['acctgCode'] . ' ';
          }

          continue;
        }
        else {
          $line_item_ref = $ItemRefs[$lineItem['acctgCode']];
        }

        // For US companies, this process is not needed, as the `TaxCodeRef` for each line item is either `NON` or `TAX`.
        if (!$this->_quickbooks_is_us_company_flag) {
          if (!empty($_TaxRefs) && !isset($_TaxRefs[$lineItem['sale_tax_acctgCode']])) {
            // if we have any line items that does not have a matched Salse Tax accounting code in Quickbooks
            // We are not going to include this line item in quickbooks, and include this error in Customer memo as a public not in this Invoice.
            // Customer memo can be edited by Quickbooks Users and they can use this error message to find the corresponding contribution in CiviCRM and find the
            // line item that is wrong.

            if (empty($_error_msg_for_tax_acctgcode)) {
              //@TODO fix engrish
              $_error_msg_for_tax_acctgcode = 'The sales tax financial accounts of following financial types have no/worng acctgcode filled out in CiviCRM. Can not find matched tax code names in Quickbooks. Corresponding line items are not synced to quickbooks, please correct this invoice manually:
	            ID: ' . $lineItem['financial_type_id'] . ' Sale_tax_acctgcode: ' . $lineItem['sale_tax_acctgCode'];
            }
            else {
              $_error_msg_for_tax_acctgcode = $_error_msg_for_tax_acctgcode . ', ID: ' . $lineItem['financial_type_id'] . ' Sale_tax_acctgcode: ' . $lineItem['sale_tax_acctgCode'] . ' ';
            }

            continue;
          }
          else {
            $line_item_tax_ref = $_TaxRefs[$lineItem['sale_tax_acctgCode']];
          }
        }
        else {
          // 'NON' or 'TAX' recorded in CiviCRM for US Companies
          $usTaxCode = "NON";
          if (isset($lineItem['sale_tax_acctgCode'])) {
            $usTaxCode = "TAX";
          }
          $line_item_tax_ref = $usTaxCode;
        }

        $lineTotal = $lineItem['line_total'];

        $tmp = array(
          'Id' => $i . '',
          'LineNum' => $i,
          'Description' => $line_item_description,
          'Amount' => sprintf('%.5f', $lineTotal),
          'DetailType' => 'SalesItemLineDetail',
          'SalesItemLineDetail' => array(
            'ItemRef' => array(
              'value' => $line_item_ref,
            ),
            'UnitPrice' => $lineTotal / $lineItem['qty'] * 1.00,
            'Qty' => $lineItem['qty'] * 1,
            'TaxCodeRef' => array(
              'value' => $line_item_tax_ref,
            ),
          ),
        );

        $lineItems[] = $tmp;
        $i += 1;
      }

      $_error_msg_to_QBs = $_error_msg_for_item_acctgcode . $_error_msg_for_tax_acctgcode;

      $receive_date = $db_contribution['receive_date'];

      $invoice_settings = civicrm_api3('Setting', 'getvalue', array(
        'sequential' => 1,
        'name' => 'contribution_invoice_settings',
        'group_name' => 'Contribute Preferences',
      ));

      if (!empty($invoice_settings['due_date']) && !empty($invoice_settings['due_date_period'])) {
        $time_adjust_str = '+' . $invoice_settings['due_date'] . ' ' . $invoice_settings['due_date_period'];
      }
      else {
        $time_adjust_str = '+ 15 days';
      }

      $due_date = date('Y-m-d', strtotime($time_adjust_str, CRM_Utils_Date::unixTime($receive_date)));

      // if we use `sparse = true` here. it means that the we are going to partially update the invoice, this approach sometimes causes update issue.
      // so do not use it.
      if (isset($SyncToken) && isset($accountsID)) {
        $new_invoice += array(
          'Id' => $accountsID,
          'SyncToken' => $SyncToken,
        );
      }

      if (empty($lineItems)) {
        throw new CiviCRM_API3_Exception('No valid line items in the Invoice to push. ' . $_error_msg_to_QBs, 'qbo_invoice_line_items');
      }

      $new_invoice += array(
        'TxnDate' => $receive_date,
        'DueDate' => $due_date,
        'DocNumber' => 'Civi-' . $db_contribution['id'],
        'CustomerMemo' => array(
          'value' => empty($_error_msg_to_QBs) ? $db_contribution['contribution_source'] : $_error_msg_to_QBs,
        ),
        'Line' => $lineItems,
        'CustomerRef' => array(
          'value' => $qb_id,
        ),
        'GlobalTaxCalculation' => 'TaxExcluded',
      );

      // For US company, add the array generated by $this->generateTxnTaxDetail on the top of the new invoice array.
      // to specify the tax rate for the entire invoice.
      if ($this->_quickbooks_is_us_company_flag) {
        //this function is used for US companies to use the name stored in `account_type_code` of the first line item
        //to get the needed state's tax code id from Quickbooks
        $result = $this->generateTxnTaxDetail($db_line_items);

        if (is_array($result)) {
          $new_invoice['TxnTaxDetail'] = $result;
        }
      }
    }

    array_walk_recursive($new_invoice, function (&$item) {
      $item = html_entity_decode($item, (ENT_QUOTES | ENT_HTML401), 'UTF-8');
    });

    $new_invoice = \QuickBooksOnline\API\Facades\Invoice::create($new_invoice);

    return $new_invoice;
  }

  /**
   * Get item id from Quickbooks, by given their names.
   *
   * @param array $tmp_acctgcode
   *
   * @return array|bool
   * @throws CiviCRM_API3_Exception
   * @throws \QuickBooksOnline\API\Exception\SdkException
   */
  protected function getItemRefs($tmp_acctgcode = array()) {
    if (empty($tmp_acctgcode)) {
      return FALSE;
    }

    $query = 'SELECT Name,Id FROM Item WHERE name in (';

    $i = 1;
    $max = (int) count($tmp_acctgcode);

    //assembling the name options in query.
    foreach ($tmp_acctgcode as $value) {
      $query = $query . "'" . $value . "'";

      if ($i !== $max) {
        $query = $query . ',';
      }
      else {
        $query = $query . ')';
      }
      $i = $i + 1;
    }

    $dataService = CRM_Quickbooks_APIHelper::getAccountingDataServiceObject();
    $result = $dataService->Query($query, 0, 10);
    $errors = $dataService->getLastError();

    if ($errors || !$result) {
      return FALSE;
    }

    return $result;
  }

  /**
   * Get Tax account id from Quickbooks, by given their names.
   *
   * @param array $_tmp_civi_tax_account_code
   *
   * @return array|bool
   * @throws CiviCRM_API3_Exception
   * @throws \QuickBooksOnline\API\Exception\SdkException
   */
  protected function getTaxRefs($_tmp_civi_tax_account_code = array()) {
    if (empty($_tmp_civi_tax_account_code)) {
      return FALSE;
    }

    $query = 'SELECT Name,Id FROM TaxCode WHERE name in (';

    $i = 1;
    $max = (int) count($_tmp_civi_tax_account_code);

    //assembling the name options in query.
    foreach ($_tmp_civi_tax_account_code as $value) {
      $query = $query . "'" . $value . "'";

      if ($i !== $max) {
        $query = $query . ',';
      }
      else {
        $query = $query . ')';
      }
      $i = $i + 1;
    }

    $dataService = CRM_Quickbooks_APIHelper::getAccountingDataServiceObject();
    $result = $dataService->Query($query, 0, 10);
    $errors = $dataService->getLastError();

    if ($errors || !$result) {
      return FALSE;
    }

    return $result;
  }

  /**
   * Map fields for a cancelled contribution to be updated to QuickBooks.
   *
   * @param $contributionID int
   * @param $accounts_invoice_id int
   *
   * @return array
   */
  protected function mapCancelled($accounts_invoice_id, $SyncToken) {
    $newInvoice = array();

    if (isset($SyncToken) && isset($accounts_invoice_id)) {
      $newInvoice += array(
        'Id' => $accounts_invoice_id,
        'SyncToken' => $SyncToken,
      );
    }

    $newInvoice = \QuickBooksOnline\API\Facades\Invoice::create($newInvoice);

    return $newInvoice;
  }

  /**
   * This function was used to calculate the tax details for the whole invoice,
   * based on price of each line item. this function could be used for US
   * companies to use the name stored in `account_type_code` of the first line
   * item to get the needed state's tax code id from Quickbooks. It should
   * returns an array with content like:
   * "TxnTaxDetail": {
   * "TxnTaxCodeRef": {
   * "value": "2"  <- the id here is in the response of calling Quickbooks REST
   * API, it is the state's tax code id for this invoice.
   * }
   *
   * @param $db_line_items
   *
   * @return array|bool
   * @throws CiviCRM_API3_Exception
   * @throws \QuickBooksOnline\API\Exception\SdkException
   */
  protected function generateTxnTaxDetail($db_line_items) {
    //We only take the first line item's sales tax account's `account type code`.
    //As we assume that all lint items have assigned with correct Tax financial account with correct
    //state tax name filled in to `account type code`.

    foreach ($db_line_items['values'] as $id => $line_item) {
      if ($line_item['sale_tax_acctgCode'] == 'TAX') {
        $_first_line_item_account_type_code = $line_item['sale_tax_account_type_code'];
        break;
      }
      else {
        continue;
      }
    }

    if (!isset($_first_line_item_account_type_code)) {
      return FALSE;
    }

    $query = "SELECT Id FROM TaxCode WHERE name='" . $_first_line_item_account_type_code . "'";

    $dataService = CRM_Quickbooks_APIHelper::getAccountingDataServiceObject();
    $result = $dataService->Query($query, 0, 10);
    $errors = $dataService->getLastError();

    if ($errors || !$result) {
      return FALSE;
    }

    if (count($result) > 0) {
      $_tax_code_id = $result[0]->Id;
    }
    else {
      return FALSE;
    }

    $_to_return = array(
      'TxnTaxCodeRef' => array(
        'value' => $_tax_code_id,
      ),
    );

    return $_to_return;
  }

  /**
   * Get contributions marked as needing to be pushed to the accounts package.
   *
   * We sort by error data to get the ones that have not yet been attempted
   * first. Otherwise we can wind up endlessly retrying the same failing
   * records.
   *
   * @param array $params
   * @param int $limit
   *
   * @return array
   * @throws \CiviCRM_API3_Exception
   */
  protected function getContributionsRequiringPushUpdate($params, $limit) {
    $criteria = array(
      'accounts_needs_update' => 1,
      'plugin' => $this->_plugin,
      'connector_id' => 0,
      'accounts_status_id' => array('NOT IN', 3),
      'options' => array(
        'sort' => 'error_data',
        'limit' => $limit,
      ),
    );
    if (isset($params['contribution_id'])) {
      $criteria['contribution_id'] = $params['contribution_id'];
      unset($criteria['accounts_needs_update']);
    }

    $records = civicrm_api3('AccountInvoice', 'get', $criteria);

    if (!isset($params['contribution_id'])) {
      $criteria['accounts_status_id'] = array('IS NULL' => 1);

      $nullrec = civicrm_api3('AccountInvoice', 'get', $criteria);
      $records['values'] = array_merge($records['values'], $nullrec['values']);
    }

    return $records;
  }

  protected function getContributionsRequiringPullUpdate($params, $limit) {
    $criteria = array(
      'plugin' => $this->_plugin,
      'connector_id' => 0,
      'accounts_status_id' => array('NOT IN', array(1, 3)),
      'accounts_invoice_id' => array('IS NOT NULL' => 1),
      'accounts_data' => array('IS NOT NULL' => 1),
      'error_data' => array('IS NULL' => 1),
      'options' => array(
        'sort' => 'error_data',
        'limit' => $limit,
      ),
    );
    if (isset($params['contribution_id'])) {
      $criteria['contribution_id'] = $params['contribution_id'];
      unset($criteria['accounts_needs_update']);
    }

    $records = civicrm_api3('AccountInvoice', 'get', $criteria);

    if (!isset($params['contribution_id'])) {
      $criteria['accounts_status_id'] = array('IS NULL' => 1);

      $nullrec = civicrm_api3('AccountInvoice', 'get', $criteria);
      $records['values'] = array_merge($records['values'], $nullrec['values']);
    }

    return $records;
  }

  /**
   * Save outcome from the push attempt to the civicrm_accounts_invoice table.
   *
   * @param array $result
   * @param array $record
   *
   * @return array
   *   Array of any errors
   *
   * @throws \CiviCRM_API3_Exception
   */
  protected function savePushResponse($result, $record, $dataService) {
    $responseErrors = array();
    if (!$result) {
      $responseErrors = $dataService->getLastError();
      $record['accounts_needs_update'] = 1;
    }

    if (!empty($responseErrors)) {
      $record['error_data'] = json_encode([$responseErrors->getResponseBody()]);

      if (gettype($record['accounts_data']) == 'array') {
        $record['accounts_data'] = json_encode($record['accounts_data']);
      }
    }
    else {
      $parsed_result = $result;

      $record['error_data'] = 'null';

      if (empty($record['accounts_invoice_id'])) {
        $record['accounts_invoice_id'] = $parsed_result->Id;
      }

      CRM_Core_DAO::setFieldValue(
        'CRM_Accountsync_DAO_AccountInvoice',
        $record['id'],
        'accounts_modified_date',
        date('Y-m-d H:i:s', strtotime($parsed_result->MetaData->LastUpdatedTime)),
        'id');

      $record['accounts_data'] = $parsed_result->SyncToken;

      $record['accounts_needs_update'] = 0;

    }

    //this will update the last sync date & anything hook-modified
    unset($record['last_sync_date']);

    unset($record['accounts_modified_date']);

    civicrm_api3('AccountInvoice', 'create', $record);
    return $responseErrors;
  }

}
