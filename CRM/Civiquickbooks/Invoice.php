<?php

require getComposerAutoLoadPath();

class CRM_Civiquickbooks_Invoice {

  // Flag if account is US
  protected $us_company;

  private $plugin = 'quickbooks';

  protected $contribution_status;

  protected $contribution_status_by_value;

  public function __construct() {
    $this->contribution_status = civicrm_api3('Contribution', 'getoptions', array('field' => 'contribution_status_id'));

    $this->contribution_status = $this->contribution_status['values'];

    $this->contribution_status_by_value = array();

    foreach ($this->contribution_status as $key => $value) {
      $this->contribution_status[$key] = strtolower($value);

      $this->contribution_status_by_value[strtolower($value)] = $key;
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
   *
   * @throws \CRM_Core_Exception
   * @throws \QuickBooksOnline\API\Exception\IdsException
   * @throws \QuickBooksOnline\API\Exception\SdkException
   */
  public function push($params = array(), $limit = PHP_INT_MAX) {
    try {
      $records = $this->findPushContributions($params, $limit);
      $errors = array();

      // US companies handles the tax in Invoice differently
      $company_country = civicrm_api3('Setting', 'getvalue', array(
        'name' => "quickbooks_company_country",
        'group' => 'QuickBooks Online Settings',
      ));
      $this->us_company = ($company_country == 'US');

      foreach ($records['values'] as $i => $record) {
        try {
          $accountsInvoice = $this->getAccountsInvoice($record);

          if(empty($accountsInvoice)) {
              civicrm_api3('AccountInvoice', 'create', ['id' => $record['id'], 'accounts_needs_update' => 0]);
              throw new CiviCRM_API3_Exception(E::ts('AccountInvoice object for %1 is empty', [1 => $record['id']]), 'empty_invoice');
          }

          $proceed = TRUE;
          CRM_Accountsync_Hook::accountPushAlterMapped('invoice', $record, $proceed, $accountsInvoice);

          if(!$proceed) {
            continue;
          }

          $dataService = CRM_Quickbooks_APIHelper::getAccountingDataServiceObject();

          if ($accountsInvoice->Id) {
            $result = $dataService->Update($accountsInvoice);
          }
          else {
            $result = $dataService->Add($accountsInvoice);

            if ($result->Id) {
              $result_payments = self::pushPayments($record['contribution_id'], $result);
              self::sendEmail($result->Id);
            }
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

          civicrm_api3('AccountInvoice', 'create', [ 'id' => $record['id'], 'error_data' => json_encode (
              [ date('c'), [ 'message' => 'CiviCRM: ' . $e->getMessage ] ]
          )]);

          CRM_Core_Error::debug_log_message($this_error);
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
      $records = $this->findPullContributions($params, $limit);

      $errors = array();

      foreach ($records['values'] as $i => $record) {
        try {
          //double check if the record has been synched or not
          if (!isset($record['accounts_invoice_id']) || !isset($record['accounts_data'])) {
            continue;
          }

          $invoice = $this->getInvoiceFromQBO($record);
          if ($invoice instanceof \QuickBooksOnline\API\Data\IPPInvoice) {
           $this->saveToCiviCRM($invoice, $record);
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

  /**
   * Find Payment entities for given contribution ID and record against
   * AccountInvoice
   *
   * @param $contribution_id
   * @param $account_invoice
   *
   * @throws \CiviCRM_API3_Exception
   * @throws \QuickBooksOnline\API\Exception\SdkException
   * @throws \QuickBooksOnline\API\Exception\IdsException
   */
  public static function pushPayments($contribution_id, $account_invoice) {
    $payments = civicrm_api3('Payment', 'get', ['contribution_id' => $contribution_id, 'status_id' => 'Completed', 'sequential' => 1]);

    if (!$payments['count']) {
      return;
    }

    $dataService = CRM_Quickbooks_APIHelper::getAccountingDataServiceObject();
    $result = [];

    foreach($payments['values'] as $payment) {
      $txnDate = $payment['trxn_date'];
      $total = sprintf('%.5f', $payment['total_amount']);
      $QBOPayment = \QuickBooksOnline\API\Facades\Payment::create(
        [
          'TotalAmt' => $total,
          'CustomerRef' => $account_invoice->CustomerRef,
          'CurrencyRef' => $account_invoice->CurrencyRef,
          'TxnDate' => $txnDate,
          'Line' => [
            'Amount' => $total,
            'LinkedTxn' => [[
              'TxnType' => 'Invoice',
              'TxnId' => $account_invoice->Id,
            ]],
          ],
        ]
      );
      $result[] = $dataService->Add($QBOPayment);
    }

    return $result;
  }

  /**
   * Calls QuickBooks Online to send an invoice email for a given invoice ID.
   *
   * @param $invoice_id
   * @param $dataService
   *
   * @throws \CiviCRM_API3_Exception
   * @throws \QuickBooksOnline\API\Exception\SdkException
   * @throws \QuickBooksOnline\API\Exception\IdsException
   */
  public static function sendEmail($invoice_id, $dataService = NULL) {
    if($dataService == NULL) {
      $dataService = CRM_Quickbooks_APIHelper::getAccountingDataServiceObject();
    }

    $send = civicrm_api3('Setting', 'getvalue', [ 'name' => 'quickbooks_email_invoice' ]);

    switch($send) {
      case 'unpaid':
      case 'always':
        $invoice = $dataService->FindById('invoice', $record['accounts_invoice_id']);

        if ($invoice && (('always' == $send) || $invoice->Balance)) {
          $dataService->sendEmail($invoice);
        }

        break;
      default:
        break;
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

    $db_contribution['contri_status_in_lower'] = strtolower($this->contribution_status[$db_contribution['contribution_status_id']]);

    return $db_contribution;
  }

  protected function getInvoiceFromQBO($record) {
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
        $result = CRM_Core_DAO::setFieldValue('CRM_Contribute_DAO_Contribution', $record['contribution_id'], 'contribution_status_id', $this->contribution_status_by_value['cancelled'], 'id');

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

    // Must update synctoken as any modification in QBs end will change the original token
    $record['accounts_data'] = $invoice->SyncToken;

    civicrm_api3('AccountInvoice', 'create', $record);

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

    $db_contribution['status'] = $this->contribution_status[$db_contribution['contribution_status_id']];

    $cancelledStatuses = array('failed', 'cancelled');

    $qb_account = civicrm_api3('account_contact', 'getsingle', array(
      'contact_id' => $db_contribution['contact_id'],
      'plugin' => $this->plugin,
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
   *
   * @throws \CiviCRM_API3_Exception
   */
  protected function mapToAccounts($db_contribution, $accountsID, $SyncToken, $qb_id) {
    static $tmp = NULL;
    $new_invoice = array();
    $contri_status_in_lower = strtolower($db_contribution['status']);

    //those contributions we care
    $status_array = array('pending', 'completed', 'partially paid');

    $contributionID = $db_contribution['id'];

    if (in_array($contri_status_in_lower, $status_array)) {
      $db_line_items = civicrm_api3('LineItem', 'get', array(
        'contribution_id' => $contributionID,
      ));

      if (empty($db_line_items['count'])) {
        throw new CiviCRM_API3_Exception('No line item in contribution id ' . $contributionID . '; push aborted.', 'qbo_contribution_line_item');
      }

      $line_items = array();

      /* static array for storing financial type and its Inc account's accounting code.
       * key: financial type id.
       * value: the accounting code of the Inc financial account of this financial type.*/
      static $item_ref_codes = array();

      /* static array for storing financial type and its Inc account's accounting code.
       * key: financial type id.
       * value: the accounting code of the sales tax account of this financial type.*/
      static $tax_types = array();

      $result = NULL;

      $item_codes = array();

      $tax_codes = array();

      //Collect all accounting codes for all line items
      foreach ($db_line_items['values'] as $id => $line_item) {
        //get Inc Account accounting code if it is not collected previously
        if (!isset($item_ref_codes[$line_item['financial_type_id']])) {
          $tmp = htmlspecialchars_decode(CRM_Financial_BAO_FinancialAccount::getAccountingCode($line_item['financial_type_id']));

          $item_ref_codes[$line_item['financial_type_id']] = $tmp;
          $item_codes[] = $tmp;
        }

        $db_line_items['values'][$id]['acctgCode'] = $item_ref_codes[$line_item['financial_type_id']];

        //get Sales Tax Account accounting code if it is not collected previously
        if (!isset($tax_types[$line_item['financial_type_id']])) {
          try {
            $result = civicrm_api3('EntityFinancialAccount', 'getsingle', array(
              'sequential' => 1,
              'return' => array("financial_account_id"),
              'entity_id' => $line_item['financial_type_id'],
              'entity_table' => "civicrm_financial_type",
              'account_relationship' => "Sales Tax Account is",
            ));

            $result = civicrm_api3('FinancialAccount', 'getsingle', array(
              'sequential' => 1,
              'id' => $result['financial_account_id'],
            ));

            $tmp = htmlspecialchars_decode($result['accounting_code']);

            // We will use account type code to get state tax code id for US companies
            $tax_types[$line_item['financial_type_id']] = array(
              'sale_tax_acctgCode' => $tmp,
              'sale_tax_account_type_code' => htmlspecialchars_decode($result['account_type_code']),
            );

            $tax_codes[] = $tmp;
          } catch (CiviCRM_API3_Exception $e) {

          }
        }

        $db_line_items['values'][$id]['sale_tax_acctgCode'] = $tax_types[$line_item['financial_type_id']]['sale_tax_acctgCode'];

        // We will use account type code to get state tax code id for US companies
        $db_line_items['values'][$id]['sale_tax_account_type_code'] = $tax_types[$line_item['financial_type_id']]['sale_tax_account_type_code'];
      }

      $i = 1;

      $QBO_errormsg = NULL;

      $item_errormsg = NULL;

      $tax_errormsg = NULL;

      //looping through all line items and create an array that contains all necessary info for each line item.
      foreach ($db_line_items['values'] as $id => $line_item) {
        $line_item_description = str_replace(array('&nbsp;'), ' ', $line_item['label']);

        $item_code = self::getItem($line_item['acctgCode']);

        if (!$item_code) {
          // If we have any line items that does not have a matched accounting code in Quickbooks.
          // We are not going to include this line item in quickbooks, and include this error in Customer memo as a public not in this Invoice.
          // Customer memo can be edited by Quickbooks Users and they can use this error message to find the corresponding contribution in CiviCRM and find the
          // line item that is wrong.

          if (empty($item_errormsg)) {
            $item_errormsg = ts('No matching Item found in Quickbooks Online for accounting code "%1" (financial type %2)', array(
              1 => $line_item['acctgCode'],
              2 => $line_item['financial_type_id'],
            ));
          }
          else {
            $item_errormsg .= ', ID: ' . $line_item['financial_type_id'] . ' Inc_acctgcode: ' . $line_item['acctgCode'] . ' ';
          }

          continue;
        }
        else {
          $line_item_ref = $item_code;
        }

        // For US companies, this process is not needed, as the `TaxCodeRef` for each line item is either `NON` or `TAX`.
        if (!$this->us_company) {
          if(!empty($line_item['sale_tax_acctgCode'])){
            $tax_ref = self::getTaxCode($line_item['sale_tax_acctgCode']);

            if (!$tax_ref) {
              // Don't include any line items wih a non-matching TaxCode in Quickbooks.
              if (empty($tax_errormsg)) {
                $tax_errormsg = ts('No matching Tax type found in Quickbooks online for %1', array(1 => $line_item['sale_tax_acctgCode']));
              }
              else {
                $tax_errormsg .= ', ID: ' . $line_item['financial_type_id'] . ' Tax type: ' . $line_item['sale_tax_acctgCode'] . ' ';
              }
            }
            else {
              $line_item_tax_ref = $tax_ref;
            }
          }
        }
        else {
          // 'NON' or 'TAX' recorded in CiviCRM for US Companies
          $line_item_tax_ref = isset($line_item['sale_tax_acctgCode']) ? 'TAX' : 'NON';
        }

        $lineTotal = $line_item['line_total'];

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
            'UnitPrice' => $lineTotal / $line_item['qty'] * 1.00,
            'Qty' => $line_item['qty'] * 1,
            'TaxCodeRef' => array(
              'value' => $line_item_tax_ref,
            ),
          ),
        );

        $line_items[] = $tmp;
        $i += 1;
      }

      $QBO_errormsg = $item_errormsg . $tax_errormsg;

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

      if (empty($line_items)) {
        throw new CiviCRM_API3_Exception('No valid line items in the Invoice to push. ' . $QBO_errormsg, 'qbo_invoice_line_items');
      }

      $new_invoice += array(
        'TxnDate' => $receive_date,
        'DueDate' => $due_date,
        'DocNumber' => 'Civi-' . $db_contribution['id'],
        'CustomerMemo' => array(
          'value' => $db_contribution['contribution_source'],
        ),
        'Line' => $line_items,
        'CustomerRef' => array(
          'value' => $qb_id,
        ),
        'GlobalTaxCalculation' => 'TaxExcluded',
      );

      // For US company, add the array generated by $this->generateTxnTaxDetail on the top of the new invoice array.
      // to specify the tax rate for the entire invoice.
      if ($this->us_company) {
        //this function is used for US companies to use the name stored in `account_type_code` of the first line item
        //to get the needed state's tax code id from Quickbooks
        $result = $this->generateTaxDetails($db_line_items);

        if (is_array($result)) {
          $new_invoice['TxnTaxDetail'] = $result;
        }
      }

      // Ensure HTML entities are not double encoded in Invoice create
      array_walk_recursive($new_invoice, function (&$item) {
          $item = html_entity_decode($item, (ENT_QUOTES | ENT_HTML401), 'UTF-8');
      });

      try {
        return \QuickBooksOnline\API\Facades\Invoice::create($new_invoice);
      }
      catch(Exception $e) {
        throw new CiviCRM_API3_Exception(
          E::ts('Error creating Invoice for %1: %2', [1 => $contributionID, 2 => $e->getMessage()]),
          'qbo_invoice_creation'
        );
      }
    }
  }

  /**
   * Get item id from QBO by Name or FullyQualifiedName
   *
   * @param $name - Name or FullyQualifiedName of Item.
   *                Assumes FullyQualifiedName if containing a colon (:)
   *
   * @return int|FALSE
   * @throws \CiviCRM_API3_Exception
   * @throws \QuickBooksOnline\API\Exception\SdkException
   */
  public static function getItem($name) {
    $items =& \Civi::$statics[__CLASS__][__FUNCTION__];

    if(!isset($items[$name])) {
      $field = (strpos($name, ':') === FALSE) ? 'Name' : 'FullyQualifiedName';
      $query = sprintf('SELECT %1$s,Id From Item WHERE %1$s = \'%2$s\'', $field, $name);

      $dataService= CRM_Quickbooks_APIHelper::getAccountingDataServiceObject();
      $result = $dataService->Query($query,0,1);

      if(empty($result)) {
        $items[$name] = FALSE;

        if($error = $dataService->getLastError()) {
          CRM_Core_Error::debug_log_message(ts('Error getting Item "%1" from QBO: %2', [1 => $name, 2 => $error->getResponseBody()]));
          CRM_Core_Error::debug_var('query', $query);
        } else {
          CRM_Core_Error::debug_log_message(ts('No result getting Item "%1" from QBO', [1 => $name]));
          CRM_Core_Error::debug_var('query', $query);
        }
      }
      else {
        $items[$name] = $result[0]->Id;
      }
    }

    return $items[$name];
  }

  /**
   * Get TaxCode id from QBO by Namde
   *
   * @param $name - Name of Tax Code.
   *
   * @return int|FALSE
   * @throws \CiviCRM_API3_Exception
   * @throws \QuickBooksOnline\API\Exception\SdkException
   */
  public static function getTaxCode($name) {
    $codes =& \Civi::$statics[__CLASS__][__FUNCTION__];

    if(empty($name)) {
      return FALSE;
    }

    if(!isset($codes[$name])) {
      $query = sprintf('SELECT Name,Id From TaxCode WHERE Name = \'%1s\'', $name);

      $dataService= CRM_Quickbooks_APIHelper::getAccountingDataServiceObject();
      $result = $dataService->Query($query,0,1);

      if(empty($result)) {
        $codes[$name] = FALSE;

        if($error = $dataService->getLastError()) {
          CRM_Core_Error::debug_log_message(ts('Error getting TaxCode "%1" from QBO: %2', [1 => $name, 2 => $error->getResponseBody()]));
          CRM_Core_Error::debug_var('query', $query);
        }
        else {
          CRM_Core_Error::debug_log_message(ts('No result getting TaxCode "%1" from QBO', [1 => $name]));
          CRM_Core_Error::debug_var('query', $query);
        }
      }
      else {
        $codes[$name] = $result[0]->Id;
      }
    }

    return $codes[$name];
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
   * @param $line_items
   *
   * @return array|bool
   * @throws CiviCRM_API3_Exception
   * @throws \QuickBooksOnline\API\Exception\SdkException
   */
  protected function generateTaxDetails($line_items) {
    //We only take the first line item's sales tax account's `account type code`.
    //As we assume that all lint items have assigned with correct Tax financial account with correct
    //state tax name filled in to `account type code`.

    foreach ($line_items['values'] as $id => $line_item) {
      if ($line_item['sale_tax_acctgCode'] == 'TAX') {
        $tax_code = $line_item['sale_tax_account_type_code'];
        break;
      }
      else {
        continue;
      }
    }

    if (!isset($tax_code)) {
      return FALSE;
    }

    $query = "SELECT Id FROM TaxCode WHERE name='" . $tax_code . "'";

    $dataService = CRM_Quickbooks_APIHelper::getAccountingDataServiceObject();
    $result = $dataService->Query($query, 0, 10);
    $errors = $dataService->getLastError();

    if ($errors || !$result || count($result) < 1) {
      return FALSE;
    }

    $tax_detail = array(
      'TxnTaxCodeRef' => array(
        'value' => $result[0]->Id,
      ),
    );

    return $tax_detail;
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
  protected function findPushContributions($params, $limit) {
    $criteria = array(
      'accounts_needs_update' => 1,
      'plugin' => $this->plugin,
      'connector_id' => 0,
      'accounts_status_id' => array('NOT IN', 3),
      'options' => array(
        'sort' => 'error_data ASC',
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

  protected function findPullContributions($params, $limit) {
    $criteria = array(
      'plugin' => $this->plugin,
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
