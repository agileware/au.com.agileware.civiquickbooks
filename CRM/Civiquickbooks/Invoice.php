<?php

/** Load CiviX ExtensionUtil class and bundled autoload resolver. **/

use Civi\Api4\AccountInvoice;
use CRM_Civiquickbooks_ExtensionUtil as E;

require E::path('vendor/autoload.php');

/**
 * @class CRM_Civiquickbooks_Invoice
 * Class for operating on Invoices in Quickbooks Online
 **/
class CRM_Civiquickbooks_Invoice {

  // Flag if account is US
  protected $us_company;

  private $plugin = 'quickbooks';

  protected $contribution_status;

  protected $contribution_status_by_value;

  public function __construct() {
    $this->contribution_status = civicrm_api3('Contribution', 'getoptions', [
      'field' => 'contribution_status_id',
      'context' => 'validate',
    ]);

    $this->contribution_status = $this->contribution_status['values'];

    $this->contribution_status_by_value = [];

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
   * @throws \CRM_Core_Exception
   * @throws \QuickBooksOnline\API\Exception\IdsException
   * @throws \QuickBooksOnline\API\Exception\SdkException
   */
  public function push($params = [], $limit = PHP_INT_MAX) {
    try {
      $records = $this->findPushContributions($params, $limit);
      $errors = [];

      // US companies handles the tax in Invoice differently
      $company_country = civicrm_api3('Setting', 'getvalue', [
        'name' => "quickbooks_company_country",
        'group' => 'QuickBooks Online Settings',
      ]);
      $this->us_company = ($company_country == 'US');

      // Load the dataservice outside of the main loop for performance.
      try {
        $dataService = CRM_Quickbooks_APIHelper::getAccountingDataServiceObject();
        $dataService->throwExceptionOnError(FALSE);
      }
      catch (Exception $e) {
        throw new CRM_Core_Exception('Could not get DataService Object: ' . $e->getMessage());
      }

      foreach ($records as $i => $record) {
        try {
          $accountsInvoice = $this->getAccountsInvoice($record);

          if (empty($accountsInvoice)) {
            civicrm_api3('AccountInvoice', 'create', ['id' => $record['id'], 'accounts_needs_update' => 0]);
            throw new CRM_Core_Exception(E::ts('AccountInvoice object for %1 is empty', [1 => $record['id']]), 'empty_invoice');
          }

          $proceed = TRUE;
          CRM_Accountsync_Hook::accountPushAlterMapped('invoice', $record, $proceed, $accountsInvoice);

          if (!$proceed) {
            continue;
          }

          if ($accountsInvoice->Id) {
            // Get invoice SyncToken to avoid Stale object error:
            // You and XXXX were working on this at the same time. XXX
            // finished before you did, so your work was not saved.
            $invoiceExiting = $this->getInvoiceFromQBO($record);
            $accountsInvoice->SyncToken = $invoiceExiting->SyncToken;

            $result = $dataService->Update($accountsInvoice);

            if ($last_error = $dataService->getLastError()) {
              $error_message = CRM_Quickbooks_APIHelper::parseErrorResponse($last_error);

              throw new Exception(json_encode($error_message));
            }

            $this->savePushResponse($result, $record);
          }
          else {
            $result = $dataService->Add($accountsInvoice);

            if($last_error = $dataService->getLastError()) {
              $error_message = CRM_Quickbooks_APIHelper::parseErrorResponse($last_error);

              throw new Exception(json_encode($error_message));
            }

            if ($result->Id) {
              $this->savePushResponse($result, $record);
              $result_payments = self::pushPayments($record['contribution_id'], $result);
              self::sendEmail($result->Id);
            }
          }

        } catch (Exception $e) {
          $messages = $e->getMessage();

          $errors[] = $this_error = ts('Failed to push Contribution: %1 (AccountInvoice: %3) Invoice: %4 with error: %2.', [
            1 => $record['contribution_id'],
            2 => $messages,
            3 => $record['id'],
            4 => $record['accounts_invoice_id'],
          ]);

          civicrm_api3('AccountInvoice', 'create', [
            'id' => $record['id'],
            'error_data' => json_encode([date('c'), ['message' => $messages]]),
          ]);

          \Civi::log()->warning($this_error);
        }
      }

      if ($errors) {
        // since we expect this to wind up in the job log we'll print the errors
        throw new CRM_Core_Exception(ts('Not all records were saved: ') . json_encode($errors, JSON_PRETTY_PRINT), 'incomplete', $errors);
      }
      return TRUE;
    }
    catch (CRM_Core_Exception $e) {
      throw new CRM_Core_Exception('Invoice Push aborted due to: ' . $e->getMessage());
    }
  }

  /**
   * @param array $params
   * @param int $limit
   *
   * @return bool
   * @throws \CRM_Core_Exception
   */
  public function pull($params = [], $limit = PHP_INT_MAX) {
    try {
      $records = $this->findPullContributions($params, $limit);

      $errors = [];

      // Load the dataservice outside of the main loop for performance.
      try {
        $dataService = CRM_Quickbooks_APIHelper::getAccountingDataServiceObject();
      }
      catch (Exception $e) {
        throw new CRM_Core_Exception('Could not get DataService Object: ' . $e->getMessage());
      }

      foreach ($records as $i => $record) {
        try {
          //double check if the record has been synced or not
          if (!isset($record['accounts_invoice_id']) || !isset($record['accounts_data'])) {
            continue;
          }

          $invoice = $this->getInvoiceFromQBO($record, $dataService);

          if ($invoice instanceof \QuickBooksOnline\API\Data\IPPInvoice) {
            $this->saveToCiviCRM($invoice, $record);
          }
        } catch (Exception $e) {
          $errors[] = ts('Failed to pull Contribution: %1 (AccountInvoice: %4) for QBOInvoice: %2 with error: "%3".  Invoice pull failed.', [
            1 => $record['contribution_id'],
            2 => $invoice instanceof \QuickBooksOnline\API\Data\IPPInvoice ? $invoice->Id : 'UNKNOWN',
            3 => $e->getMessage(),
            4 => $record['id'],
          ]);
        }
      }

      if ($errors) {
        // since we expect this to wind up in the job log we'll print the errors
        throw new CRM_Core_Exception(ts('Not all records were saved: ') . json_encode($errors, JSON_PRETTY_PRINT), 'incomplete', $errors);
      }
      return TRUE;
    } catch (CRM_Core_Exception $e) {
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
   * @throws \CRM_Core_Exception
   * @throws \QuickBooksOnline\API\Exception\SdkException
   * @throws \QuickBooksOnline\API\Exception\IdsException
   */
  public static function pushPayments($contribution_id, $account_invoice) {
    $payments = civicrm_api3('Payment', 'get', [
      'contribution_id' => $contribution_id,
      'status_id' => 'Completed',
      'sequential' => 1,
    ]);

    if (!$payments['count']) {
      return;
    }

    $dataService = CRM_Quickbooks_APIHelper::getAccountingDataServiceObject();
    $result = [];
    $paymentInstrument = self::getCiviPaymentInstrument();
    foreach ($payments['values'] as $payment) {
      $txnDate = $payment['trxn_date'];
      $total = sprintf('%.5f', $payment['total_amount']);
      $paymentInput = [
        'TotalAmt' => $total,
        'CustomerRef' => $account_invoice->CustomerRef,
        'CurrencyRef' => $account_invoice->CurrencyRef,
        'TxnDate' => $txnDate,
        'Line' => [
          'Amount' => $total,
          'LinkedTxn' => [
            [
              'TxnType' => 'Invoice',
              'TxnId' => $account_invoice->Id,
            ],
          ],
        ],
      ];
      // Check payment instrument present on record
      if (!empty($payment['payment_instrument_id'])) {
        $paymentMethodId = self::getPaymentMethod($paymentInstrument[$payment['payment_instrument_id']]);
        if (!empty($paymentMethodId)) {
          $paymentInput['PaymentMethodRef'] = ['value' => $paymentMethodId];
        }
      }


      // Set Transaction ID
      if (!empty($payment['trxn_id'])) {
        $paymentInput['PaymentRefNum'] = $payment['trxn_id'];
      }
      else if (!empty($payment['check_number'])) {
        $paymentInput['PaymentRefNum'] = $payment['check_number'];
      }

      $QBOPayment = \QuickBooksOnline\API\Facades\Payment::create($paymentInput);
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
   * @throws \CRM_Core_Exception
   * @throws \QuickBooksOnline\API\Exception\SdkException
   * @throws \QuickBooksOnline\API\Exception\IdsException
   */
  public static function sendEmail($invoice_id, $dataService = NULL) {
    if ($dataService == NULL) {
      $dataService = CRM_Quickbooks_APIHelper::getAccountingDataServiceObject();
    }

    $send = civicrm_api3('Setting', 'getvalue', ['name' => 'quickbooks_email_invoice']);

    switch ($send) {
      case 'unpaid':
      case 'always':
        $invoice = $dataService->FindById('invoice', $invoice_id);

        if ($invoice && (('always' == $send) || $invoice->Balance) &&
          ($customer = $dataService->FindById('customer', $invoice->CustomerRef))) {

          if (@$email = $customer->PrimaryEmailAddr->Address) {
            $dataService->sendEmail($invoice, $email);
          }
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

    $db_contribution = civicrm_api3('Contribution', 'getsingle', [
      'return' => ['contribution_status_id'],
      'id' => $contributionID,
    ]);

    $db_contribution['contri_status_in_lower'] = strtolower($this->contribution_status[$db_contribution['contribution_status_id']]);

    return $db_contribution;
  }

  /**
   * @param array $record
   * @param \QuickBooksOnline\API\DataService\DataService $dataService
   *
   * @return \Exception|\QuickBooksOnline\API\Data\IPPIntuitEntity|string|null
   * @throws \CRM_Core_Exception
   * @throws \QuickBooksOnline\API\Exception\IdsException
   * @throws \QuickBooksOnline\API\Exception\SdkException
   */
  protected function getInvoiceFromQBO($record, $dataService) {
    $dataService->throwExceptionOnError(FALSE);

    $invoice = $dataService->FindById('invoice', $record['accounts_invoice_id']);

    if ($last_error = $dataService->getLastError()) {
      $error_message = CRM_Quickbooks_APIHelper::parseErrorResponse($last_error);

      throw new Exception('"' . implode("\n", $error_message) . '"');
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
        $result = civicrm_api3('Contribution', 'completetransaction', [
          'id' => $record['contribution_id'],
          'is_email_receipt' => 0,
        ]);

        if ($result['is_error']) {
          throw new CRM_Core_Exception('Contribution status update failed: id: ' . $record['contribution_id'] . ' of Invoice ' . $invoice['Id'], 'qbo_contribution_status');
        }

        $record['accounts_needs_update'] = 0;
        $record['accounts_status_id'] = 'completed';

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
          throw new CRM_Core_Exception('Contribution status update failed: id: ' . $record['contribution_id'] . ' of Invoice ' . $invoice['Id'], 'qbo_contribution_status');
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
   * @throws \CRM_Core_Exception
   */
  protected function getAccountsInvoice($record) {
    $accountsInvoiceID = isset($record['accounts_invoice_id']) ? $record['accounts_invoice_id'] : NULL;

    $SyncToken = isset($record['accounts_data']) ? $record['accounts_data'] : NULL;

    $contributionID = $record['contribution_id'];

    $db_contribution = civicrm_api3('Contribution', 'getsingle', [
      'return' => [
        'contribution_status_id',
        'receive_date',
        'contribution_source',
      ],
      'id' => $contributionID,
    ]);

    $db_contribution['status'] = $this->contribution_status[$db_contribution['contribution_status_id']];

    $cancelledStatuses = ['failed', 'cancelled'];

    $qb_account = civicrm_api3('account_contact', 'getsingle', [
      'contact_id' => $db_contribution['contact_id'],
      'plugin' => $this->plugin,
      'connector_id' => 0,
    ]);

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
   * @throws \CRM_Core_Exception
   */
  protected function mapToAccounts($db_contribution, $accountsID, $SyncToken, $qb_id) {
    static $tmp = NULL;
    $new_invoice = [];
    $contri_status_in_lower = strtolower($db_contribution['status']);

    //those contributions we care
    $status_array = ['pending', 'completed', 'partially paid'];

    $contributionID = $db_contribution['id'];

    if (in_array($contri_status_in_lower, $status_array)) {
      $db_line_items = civicrm_api3('LineItem', 'get', [
        'contribution_id' => $contributionID,
      ]);

      if (empty($db_line_items['count'])) {
        throw new CRM_Core_Exception('No LineItems for Contribution: ' . $contributionID . '; push aborted.',
          'qbo_contribution_line_item');
      }

      $line_items = [];

      /* static array for storing financial type and its Inc account's accounting code.
       * key: financial type id.
       * value: the accounting code of the Inc financial account of this financial type.*/
      static $item_ref_codes = [];

      /* static array for storing financial type and its Inc account's accounting code.
       * key: financial type id.
       * value: the accounting code of the sales tax account of this financial type.*/
      static $tax_types = [];

      $result = NULL;

      $item_codes = [];

      $tax_codes = [];

      $tax_errormsg = [];

      //Collect all accounting codes for all line items
      foreach ($db_line_items['values'] as $id => $line_item) {
        //get Inc Account accounting code if it is not collected previously
        if (!isset($item_ref_codes[$line_item['financial_type_id']])) {
          $entityFinancialAccount = civicrm_api3('EntityFinancialAccount', 'getsingle', [
            'return' => ["financial_account_id.accounting_code", "financial_account_id.account_type_code"],
            'entity_id' => $line_item['financial_type_id'],
            'entity_table' => "civicrm_financial_type",
            'account_relationship' => "Income Account is",
          ]);

          $accountingCode = htmlspecialchars_decode($entityFinancialAccount['financial_account_id.accounting_code']);

          $item_ref_codes[$line_item['financial_type_id']] = $accountingCode;
          $item_codes[] = $accountingCode;
        }

        $db_line_items['values'][$id]['acctgCode'] = $item_ref_codes[$line_item['financial_type_id']];

        // get Sales Tax Account accounting code if it is not collected previously
        if (!isset($tax_types[$line_item['financial_type_id']])) {
          try {
            $entityFinancialAccount = civicrm_api3('EntityFinancialAccount', 'getsingle', [
              'return' => ["financial_account_id.accounting_code", "financial_account_id.account_type_code"],
              'entity_id' => $line_item['financial_type_id'],
              'entity_table' => "civicrm_financial_type",
              'account_relationship' => "Sales Tax Account is",
            ]);

            $tmp = htmlspecialchars_decode($entityFinancialAccount['financial_account_id.accounting_code']);

            // We will use account type code to get state tax code id for US companies
            $tax_types[$line_item['financial_type_id']] = [
              'sale_tax_acctgCode' => $tmp,
              'sale_tax_account_type_code' => htmlspecialchars_decode($entityFinancialAccount['financial_account_id.account_type_code'] ?? NULL),
            ];

            $tax_codes[] = $tmp;
          } catch (CRM_Core_Exception $e) {
            $tax_errormsg[] = ts(
              'Could not load "Sales Tax Account is" relationship for FinancialType %1. Error: %2',
              [
                1 => $line_item['financial_type_id'],
                2 => $e->getMessage(),
              ]
            );

            $tax_types[$line_item['financial_type_id']] = [
              'sale_tax_acctgCode' => NULL,
              'sale_tax_account_type_code' => NULL,
            ];
          }
        }


        $db_line_items['values'][$id]['sale_tax_acctgCode'] = $tax_types[$line_item['financial_type_id']]['sale_tax_acctgCode'];

        // We will use account type code to get state tax code id for US companies
        $db_line_items['values'][$id]['sale_tax_account_type_code'] = $tax_types[$line_item['financial_type_id']]['sale_tax_account_type_code'];
      }

      $i = 1;

      $item_errormsg = [];

      //looping through all line items and create an array that contains all necessary info for each line item.
      foreach ($db_line_items['values'] as $id => $line_item) {
        $line_item_description = str_replace(['&nbsp;'], ' ', $line_item['label']);

        try {
          $line_item_ref = self::getQBOItem($line_item['acctgCode']);
        } catch (Exception $e) {
          $item_errormsg[] = ts(
            'No matching QBOItem for FinancialType %2 "Income Account is": Accounting Code: %1. Error: %3',
            [
              1 => $line_item['acctgCode'],
              2 => $line_item['financial_type_id'],
              3 => $e->getMessage(),
            ]
          );

          continue;
        }

        // For US companies, this process is not needed, as the `TaxCodeRef` for each line item is either `NON` or `TAX`.
        if (!$this->us_company) {
          if (!empty($line_item['sale_tax_acctgCode'])) {
            try {
              $line_item_tax_ref = self::getQBOTaxCode($line_item['sale_tax_acctgCode']);
            } catch (\QuickbooksOnline\API\Exception\IdsException $e) {
              // Don't include any line items wih a non-matching TaxCode in Quickbooks.
              $tax_errormsg[] = ts(
                'No matching QBOTaxCode for FinancialType %2 "Sales Tax Account is": Accounting Code: %1. Error: %3',
                [
                  1 => $line_item['sale_tax_acctgCode'],
                  2 => $line_item['financial_type_id'],
                  3 => $e->getMessage(),
                ]
              );
            }
          }
        }
        else {
          // 'NON' or 'TAX' recorded in CiviCRM for US Companies
          $line_item_tax_ref = isset($line_item['sale_tax_acctgCode']) ? 'TAX' : 'NON';
        }

        $lineTotal = $line_item['line_total'];
        // Do not sync line items with zero quantity.
        if (empty((float) $line_item['qty'])) {
          continue;
        }
        $tmp = [
          'Id' => $i . '',
          'LineNum' => $i,
          'Description' => $line_item_description,
          'Amount' => sprintf('%.5f', $lineTotal),
          'DetailType' => 'SalesItemLineDetail',
          'SalesItemLineDetail' => [
            'ItemRef' => [
              'value' => $line_item_ref,
            ],
            'UnitPrice' => $lineTotal / $line_item['qty'] * 1.00,
            'Qty' => $line_item['qty'] * 1,
          ],
        ];

        if(!empty($line_item_tax_ref)) {
          $tmp['SalesItemLineDetail']['TaxCodeRef'] = [ 'value' => $line_item_tax_ref ];
        }

        $line_items[] = $tmp;
        $i += 1;
      }

      $QBO_errormsg = implode("\n", array_merge($item_errormsg, $tax_errormsg));

      $receive_date = $db_contribution['receive_date'];

      $invoice_settings = civicrm_api3('Setting', 'getvalue', [
        'sequential' => 1,
        'name' => 'contribution_invoice_settings',
        'group_name' => 'Contribute Preferences',
      ]);

      $invoice_prefix = civicrm_api3('Setting', 'getvalue', array(
        'name' => "quickbooks_invoice_prefix",
        'group' => 'QuickBooks Online Settings',
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
        $new_invoice += [
          'Id' => $accountsID,
          'SyncToken' => $SyncToken,
        ];
      }

      if (empty($line_items)) {
        throw new CRM_Core_Exception("No valid LineItems in Contribution to push:\n" . $QBO_errormsg, 'qbo_invoice_line_items');
      }

      $new_invoice += [
        'TxnDate' => $receive_date,
        'DueDate' => $due_date,
        'CustomerMemo' => [
          'value' => $db_contribution['contribution_source'],
        ],
        'Line' => $line_items,
        'CustomerRef' => [
          'value' => $qb_id,
        ],
        'GlobalTaxCalculation' => 'TaxExcluded',
      ];

      // check the setting 'Where should Invoice Numbers be generated?' and
      // populate the new invoice accordingly
      try {
        $whereToGetInvoiceNumber = civicrm_api3('Setting', 'getvalue', [
          'name' => 'quickbooks_autogenerate_invoice_number',
          'group' => 'QuickBooks Online Settings',
        ]);
      } catch (Exception $e) {
        throw new CRM_Core_Exception(
          E::ts('Error getting Invoice generation setting %1', [1 => $e->getMessage()]),
          'qbo_invoice_creation'
        );
      }

      if ($whereToGetInvoiceNumber == 'civi') {
        $new_invoice['DocNumber'] = sprintf($invoice_prefix . '%s', $db_contribution['id'] , '');
      }
      elseif ($whereToGetInvoiceNumber == 'qb') {
        $new_invoice['AutoDocNumber'] = 1;
      }

      // Check online credit card and ach payment settings
      $onlinePaymentOptions = [
        'quickbooks_allow_creditcard' => 'AllowOnlineCreditCardPayment',
        'quickbooks_allow_ach' => 'AllowOnlineACHPayment',
      ];
      foreach ($onlinePaymentOptions as $settingNameInCivi => $qbParam) {
        try {
          $allowCC = civicrm_api3('Setting', 'getvalue', [
            'name' => $settingNameInCivi,
            'group' => 'QuickBooks Online Settings',
          ]);
        } catch (Exception $e) {
          throw new CRM_Core_Exception(
            E::ts('Error getting Invoice generation setting %1', [1 => $e->getMessage()]),
            'qbo_invoice_creation'
          );
        }
        if ($allowCC == 1) {
          $new_invoice[$qbParam] = 1;
        }
      }
      try {
        $customMemo = civicrm_api3('Setting', 'getvalue', [
          'name' => 'quickbooks_customer_memo',
          'group' => 'QuickBooks Online Settings',
        ]);
      } catch (Exception $e) {
        throw new CRM_Core_Exception(
          E::ts('Error getting Invoice generation setting %1', [1 => $e->getMessage()]),
          'qbo_invoice_creation'
        );
      }
      if ($customMemo != '') {
        $new_invoice['CustomerMemo'] = ['value' => $customMemo];
      }

      // For US company, add the array generated by $this->generateTxnTaxDetail on the top of the new invoice array.
      // to specify the tax rate for the entire invoice.
      if ($this->us_company) {
        //this function is used for US companies to use the name stored in `account_type_code` of the first line item
        //to get the needed state's tax code id from Quickbooks
        try {
          $result = $this->generateTaxDetails($db_line_items);

          if (is_array($result)) {
            $new_invoice['TxnTaxDetail'] = $result;
          }
        } catch (\QuickbooksOnline\API\Exception\IdsException $e) {
          // Error handling was doing nothing before, so keep doing nothing.
        }
      }

      // Ensure HTML entities are not double encoded in Invoice create
      array_walk_recursive($new_invoice, function (&$item) {
        $item = html_entity_decode($item, (ENT_QUOTES | ENT_HTML401), 'UTF-8');
      });

      try {
        return \QuickBooksOnline\API\Facades\Invoice::create($new_invoice);
      } catch (Exception $e) {
        throw new CRM_Core_Exception(
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
   * @throws \CRM_Core_Exception
   * @throws \QuickBooksOnline\API\Exception\SdkException
   */
  public static function getQBOItem($name) {
    $items =& \Civi::$statics[__CLASS__][__FUNCTION__];

    if (!isset($items[$name])) {
      $field = (strpos($name, ':') === FALSE) ? 'Name' : 'FullyQualifiedName';
      $query = sprintf('SELECT %1$s,Id From Item WHERE %1$s = \'%2$s\'', $field, $name);

      $dataService = CRM_Quickbooks_APIHelper::getAccountingDataServiceObject();
      $result = $dataService->Query($query, 0, 1);

      if (empty($result)) {
        throw new Exception("No Product found matching $name");
      }

      $items[$name] = $result[0]->Id;
    }

    return $items[$name];
  }

  /**
   * Get TaxCode id from QBO by Name
   *
   * @param $name - Name of Tax Code.
   *
   * @return int|FALSE
   * @throws \CRM_Core_Exception
   * @throws \QuickBooksOnline\API\Exception\SdkException
   */
  public static function getQBOTaxCode($name) {
    $codes =& \Civi::$statics[__CLASS__][__FUNCTION__];

    if (empty($name)) {
      return FALSE;
    }

    if (!isset($codes[$name])) {
      $query = sprintf('SELECT Name,Id From TaxCode WHERE Name = \'%1s\'', $name);

      $dataService = CRM_Quickbooks_APIHelper::getAccountingDataServiceObject();
      $result = $dataService->Query($query, 0, 1);

      if (empty($result)) {
        throw new Exception("No Tax Code found matching $name");
      }

      $codes[$name] = $result[0]->Id;
    }

    return $codes[$name];
  }

  /**
   * Get Payment Method for syncing
   *
   * @param $name
   * @return mixed|void
   * @throws CiviCRM_API3_Exception
   * @throws \QuickBooksOnline\API\Exception\SdkException
   */
  public static function getPaymentMethod($name) {
    $name = strtolower($name);
    $paymentMethods =& \Civi::$statics[__CLASS__][__FUNCTION__];
    if (!isset($paymentMethods[$name])) {
      $query = 'SELECT * From PaymentMethod';
      $dataService = CRM_Quickbooks_APIHelper::getAccountingDataServiceObject();
      $result = $dataService->Query($query);
      if (empty($result)) {
        return;
      }
      foreach ($result as $paymentMethodObject) {
        $paymentMethods[strtolower($paymentMethodObject->Name)] = $paymentMethodObject->Id;
      }
    }

    return $paymentMethods[$name];
  }

  public static function getCiviPaymentInstrument() {
    $paymentInstrument =& \Civi::$statics[__CLASS__][__FUNCTION__];
    if (!isset($paymentInstrument)) {
      $optionValues = \Civi\Api4\OptionValue::get(FALSE)
        ->addSelect('value', 'name')
        ->addWhere('option_group_id:name', '=', 'payment_instrument')
        ->addOrderBy('value', 'ASC')
        ->setLimit(25)
        ->execute()->getArrayCopy();
      foreach ($optionValues as $optionValue) {
        $paymentInstrument[$optionValue['value']] = $optionValue['name'];
      }
    }

    return $paymentInstrument;
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
    $newInvoice = [];

    if (isset($SyncToken) && isset($accounts_invoice_id)) {
      $newInvoice += [
        'Id' => $accounts_invoice_id,
        'SyncToken' => $SyncToken,
      ];
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
   * @throws CRM_Core_Exception
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

    if (!$result || count($result) < 1) {
      return FALSE;
    }

    $tax_detail = [
      'TxnTaxCodeRef' => [
        'value' => $result[0]->Id,
      ],
    ];

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
   * @throws \CRM_Core_Exception
   */
  protected function findPushContributions($params, $limit) {
    // Quickbooks Online does not accept negative amounts.
    $accountInvoices = AccountInvoice::get()
      ->addJoin('Contribution AS contribution', 'INNER', ['contribution.total_amount', '>=', 0])
      ->addWhere('plugin', '=', $this->plugin)
      ->addWhere('connector_id', '=', 0)
      ->addWhere('accounts_status_id:name', 'NOT IN', ['completed'])
      ->addOrderBy('error_data', 'ASC')
      ->setLimit($limit);
    if (isset($params['contribution_id'])) {
      $accountInvoices->addWhere('contribution_id', '=', $params['contribution_id']);
    }
    else {
      $accountInvoices->addWhere('accounts_needs_update', '=', TRUE);
    }
    return $accountInvoices->execute()->getArrayCopy();
  }

  protected function findPullContributions($params, $limit) {
    $accountInvoices = AccountInvoice::get()
      ->addWhere('plugin', '=', $this->plugin)
      ->addWhere('connector_id', '=', 0)
      ->addWhere('accounts_status_id:name', 'NOT IN', ['completed', 'cancelled'])
      ->addWhere('accounts_invoice_id', 'IS NOT NULL')
      ->addWhere('accounts_data', 'IS NOT NULL')
      ->addWhere('error_data', 'IS NULL')
      ->setLimit($limit);

    if (isset($params['contribution_id'])) {
      $accountInvoices->addWhere('contribution_id', '=', $params['contribution_id']);
    }

    return $accountInvoices->execute()->getArrayCopy();
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
   * @throws \CRM_Core_Exception
   */
  protected function savePushResponse($result, $record, $responseErrors = NULL) {

    if (!$result) {
      $responseErrors = CRM_Quickbooks_APIHelper::getAccountingDataServiceObject()->getLastError();
    }

    if (!empty($responseErrors)) {
      $record['accounts_needs_update'] = 1;

      $record['error_data'] = json_encode([$responseErrors]);

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
