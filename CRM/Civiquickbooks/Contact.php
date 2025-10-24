<?php

/** Load CiviX ExtensionUtil class and bundled autoload resolver. **/

use Civi\Api4\{EntityTag,AccountContact};
use CRM_Civiquickbooks_ExtensionUtil as E;

require E::path('vendor/autoload.php');

/**
 * @class CRM_Civiquickbooks_Contact
 * Class for operating on Customers in Quickbooks Online. These are known as
 * Contacts to AccountSync
 **/
class CRM_Civiquickbooks_Contact {

  private $plugin = 'quickbooks';

  public function pull($params) {
    $result = NULL;

    $start_date = (isset($params['start_date'])) ? $params['start_date'] : 'yesterday';

    $skip_list = [];

    // Attempt to match up AccountContacts pending sync to existing QBO
    // Customers.
    try {
      $ac_list = AccountContact::get(FALSE)
        ->addSelect('contact_id', 'contact_id.last_name', 'contact_id.first_name', 'contact_id.organization_name', 'contact_id.household_name', 'contact_id.contact_type')
        ->addWhere('accounts_contact_id', 'IS NULL')
        ->addWhere('plugin', '=', $this->plugin)
        ->addWhere('connector_id', '=', 0)
        ->addWhere('do_not_sync', '=', FALSE)
        ->execute();

      foreach($ac_list as $ac) {
        switch($ac['contact_id.contact_type']) {
          case 'Individual':
            $contact = $this->getQBOContactByName($ac['contact_id.last_name'], $ac['contact_id.first_name']);
            break;
          case 'Organization':
            $contact = $this->getQBOContactByName($ac['contact_id.organization_name']);
            break;
          case 'Household':
            $contact = $this->getQBOContactByName($ac['contact_id.household_name']);
            break;
        }

        if (empty($contact)) {
          continue;
        }

        $skip_list[] = $contact->Id;

        $existingAccountContact = AccountContact::get(FALSE)
          ->addSelect('id', 'contact_id.display_name', 'contact_id')
          ->addWhere('plugin', '=', $this->plugin)
          ->addWhere('connector_id', '=', 0)
          ->addWhere('accounts_contact_id', '=', $contact->Id)
          ->execute()
          ->first();

        if(empty($existingAccountContact)) {
          civicrm_api3('AccountContact', 'create', [
            'id' => $ac['id'],
            'plugin' => $this->plugin,
            'accounts_contact_id' => $contact->Id,
            'accounts_data' => json_encode($contact),
            'error_data' => 'NULL',
          ]);
        }
        else {
          civicrm_api3('AccountContact', 'create', [
            'id' => $ac['id'],
            'plugin' => $this->plugin,
            'accounts_needs_update' => 0,
            'do_not_sync' => 1,
            'error_data' => [
              'error' => E::ts(
                'Matches QBO Contact %1, which is already synced to %2 (%3). Deduplication is required.',
                [1 => $contact->Id, 2 => $existingAccountContact['contact_id.display_name'], 3 => $existingAccountContact['contact_id']]
              ),
            ]
          ]);
        }
      }
    }
    catch (Exception $e) {
      Civi::log()->error($e->getMessage());
    }

    // try to get customers info from quickbooks based on the date provided.
    try {
      $qbo_contacts = $this->getQBOContacts($start_date);
    } catch (CRM_Civiquickbooks_Contact_Exception $e) {
      switch ($e->getCode()) {
        case 0:
          throw new CRM_Core_Exception('Failed to pull customers from Quickbooks: ' . $e->getMessage());

          break;
        case 1:
          return ['No customers are updated in Quickbooks since ' . $start_date];

          break;
        default:
          break;
      }
    }

    // Now we are going to loop through all contacts in the result.
    foreach ($qbo_contacts as $contact) {
      if(in_array($contact->Id, $skip_list))
        continue;

      $account_contact = [
        'accounts_display_name' => $contact->DisplayName,
        // AccountSync API can not parse this date format correctly, if we use the DAO directly, it has no problem.
        // 'accounts_modified_date' => date('Y-m-d H:i:s', strtotime($contact['MetaData']['LastUpdatedTime'])),
        'plugin' => $this->plugin,
        'accounts_contact_id' => $contact->Id,
        'accounts_data' => json_encode($contact),
        'accounts_needs_update' => 0,
        'sequential' => 1,
        'error_data' => 'NULL',
      ];

      $matchedAccountContact = AccountContact::get(FALSE)
        ->addWhere('plugin', '=', $this->plugin)
        ->addWhere('connector_id', '=', 0)
        ->addWhere('accounts_contact_id', '=', $contact->Id)
        ->execute()
        ->first();

      if (empty($matchedAccountContact)) {
        // No existing AccountContact found; the following API call will create one.
        // Future CIVIQBO-60 entry point for preemptive deduplication.
        continue;
      }
      if ($matchedAccountContact['do_not_sync']) {
        // This contact is marked as Do Not Sync
        continue;
      }

      $account_contact['id'] = $matchedAccountContact['id'];

      //create/update account contact entity.
      try {
        $created = civicrm_api3('account_contact', 'create', $account_contact);

        CRM_Core_DAO::setFieldValue(
          'CRM_Accountsync_DAO_AccountContact',
          $created['values'][0]['id'],
          'accounts_modified_date',
          date('Y-m-d H:i:s', strtotime($contact->MetaData->LastUpdatedTime)),
          'id');
      } catch (CRM_Core_Exception $e) {
        CRM_Core_Session::setStatus(ts('Failed to store ') . $account_contact['accounts_display_name']
          . ts(' with error ') . $e->getMessage(),
          ts('Contact Pull failed'));
      }
    }

    return $result;
  }

  /**
   * @param array $params
   *
   * @return bool
   * @throws \CRM_Core_Exception
   */
  public function push($params) {
    $abort_loop = FALSE;

    try {
      $accountContacts = AccountContact::get(FALSE)
        ->addWhere('plugin', '=', $this->plugin)
        ->addWhere('connector_id', '=', $params['connector_id'])
        ->addWhere('do_not_sync', '=', FALSE)
        ->addWhere('error_data', 'IS NULL')
        // Sort contact records without error data first. This should ensure valid
        // records to be processed before API limits are hit trying to process
        // records that have previously failed.
        ->addOrderBy('error_data', 'ASC')
        ->addOrderBy('contact_id.modified_date', 'ASC');

      // If we specified a CiviCRM contact ID just push that contact.
      if (!empty($params['contact_id'])) {
        $accountContacts
          ->addWhere('contact_id', '=', $params['contact_id']);
      }
      else {
        $accountContacts
          ->addWhere('contact_id', 'IS NOT NULL')
          ->addWhere('accounts_needs_update', '=', TRUE);
      }

      if(!empty($params['limit'])) {
        $accountContacts
          ->setLimit($params['limit']);
      }

      $records = $accountContacts->execute()->getArrayCopy();
      $errors = [];

      // Load the dataservice outside of the main loop for performance.
      try {
        $dataService = CRM_Quickbooks_APIHelper::getAccountingDataServiceObject();
        $dataService->throwExceptionOnError(FALSE);
      }
      catch (Exception $e) {
        throw new CRM_Core_Exception('Could not get DataService Object: ' . $e->getMessage());
      }

      foreach ($records as $account_contact) {
        if($abort_loop)
          break;

        $error_data = json_decode($account_contact['error_data'], TRUE);

        $failure_count = $error_data['failures'] ?? 0;

        try {
          $id = isset($account_contact['accounts_contact_id']) ? $account_contact['accounts_contact_id'] : NULL;

          $accounts_data = isset($account_contact['accounts_data']) ? json_decode($account_contact['accounts_data'], TRUE) : [];

          $QBOContact = $this->mapToCustomer(
              civicrm_api3('contact', 'getsingle', [ 'id' => $account_contact['contact_id'] ]),
              $id,
              $accounts_data
          );

          $proceed = TRUE;
          CRM_Accountsync_Hook::accountPushAlterMapped('contact', $account_contact, $proceed, $QBOContact);

          if(!$proceed) {
            continue;
          }

          unset($account_contact['api.contact.get']);

          try {
            if ($QBOContact->Id) {
              $result = $dataService->Update($QBOContact);
            }
            else {
              $result = $dataService->Add($QBOContact);
            }

            if ($last_error = $dataService->getLastError()) {
              $error_message = CRM_Quickbooks_APIHelper::parseErrorResponse($last_error);
              $error_code = $last_error->getHttpStatusCode();

              switch($error_code) {
                case 401:
                case 403:
                  // Authentication error.
                  // Causes: OAuth is not valid, API throttling has occured.
                  // Stop processing this run.
                  $abort_loop = TRUE;
                  throw new CRM_Core_Exception('Authentication failure doing QBO contact push, aborting', 9000 + $error_code);
                  break;

                default:
                  $account_contact['error_data'] = json_encode(['failures' => ++$failure_count, 'error' => $error_message]);
                  throw new Exception('"' . implode("\n", $error_message) . '"');
                  break;
              }
            }

            if($result) {
              $account_contact['error_data'] = 'NULL';

              $account_contact['accounts_contact_id'] = $result->Id;

              CRM_Core_DAO::setFieldValue(
                'CRM_Accountsync_DAO_AccountContact',
                $account_contact['id'],
                'accounts_modified_date',
                date("Y-m-d H:i:s", strtotime($result->MetaData->LastUpdatedTime)),
                'id');

              $account_contact['accounts_data'] = json_encode($result);

              $account_contact['accounts_display_name'] = $result->DisplayName;

              $account_contact['accounts_needs_update'] = 0;
            }
          }
          catch (\QuickbooksOnline\API\Exception\IdsException $e) {
            $account_contact['error_data'] = json_encode(['failures' => ++$failure_count, 'error' => [$e->getCode(), $e->getMessage()]]);

            throw $e;
          }
          finally {
            if (gettype($account_contact['accounts_data']) == 'array') {
              $account_contact['accounts_data'] = json_encode($account_contact['accounts_data']);
            }

            // This will update the last sync date.
            unset($account_contact['last_sync_date']);

            if($failure_count > 3) {
              $account_contact['do_not_sync'] = 1;
            }

            civicrm_api3('account_contact', 'create', $account_contact);
          }

          // Success! Remove sync error tag
          CRM_Civiquickbooks_Helper::removeSyncErrorTag($account_contact['contact_id']);
        } catch (Exception $e) {
          $errors[] = ts(
            'Failed to push Contact: %1 (AccountsContact: %2) with error: %3',
            [
              1 => $account_contact['contact_id'],
              2 => $account_contact['accounts_contact_id'],
              3 => $e->getMessage()
            ]);
          // Add sync error tag
          CRM_Civiquickbooks_Helper::addSyncErrorTag($account_contact['contact_id']);
        }
      }

      if ($errors) {
        // since we expect this to wind up in the job log we'll print the errors
        throw new CRM_Core_Exception(E::ts("Not all contacts were saved:\n  ") . implode("\n  ", $errors), 'incomplete', $errors);
      }
      return TRUE;
    } catch (Exception $e) {
      throw new CRM_Core_Exception('Contact Push aborted due to: ' . $e->getMessage());
    }
  }

  /**
   * @param array $contact
   *
   * @return mixed|string
   * @throws \CRM_Core_Exception
   */
  public static function getBillingEmail($contact) {
    if (is_array($contact)) {
      $contact = $contact['id'];
    }

    if($contact) {
      $criteria_set = [
        [ 'location_type_id' => 'Billing' ], // Start with location type
        [ 'is_billing'       => 1         ], // Then check is_billing flag
        [ 'is_primary'       => 1         ], // Then check is_primary flag
        [                                 ], // Finally, fall back
      ];

      foreach ( $criteria_set as $criteria ) {
        $emails = civicrm_api3(
          'Email',
          'get',
          $criteria + [
            'contact_id' => $contact,
            'options' => [ 'sort' => 'is_billing DESC, is_primary DESC, on_hold ASC, id DESC' ],
            'sequential' => 1
          ]
        );

        if ( $emails['count'] ) {
          return $emails['values'][0]['email'];
        }
      }
    }
    // The contact HAS no email, apparently.
    return '';
  }

  /**
   * @param array $contact
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  public static function getBillingAddr($contact) {
    if (is_array($contact)) {
      $contact = $contact['id'];
    }

    if($contact) {
      $criteria_set = [
        [ 'location_type_id' => 'Billing' ], // Start with location type
        [ 'is_billing'       => 1         ], // Then check is_billing flag
        [ 'is_primary'       => 1         ], // Then check is_primary flag
        [                                 ], // Finally, fall back
      ];

      foreach ( $criteria_set as $criteria ) {
        $addrs = civicrm_api3(
          'Address',
          'get',
          $criteria + [
            'contact_id' => $contact,
            'options'    => [ 'sort' => 'is_billing DESC, is_primary DESC, id DESC' ],
            'sequential' => 1,
            'return'     => [
              'street_address',
              'supplemental_address_1',
              'supplemental_address_2',
              'supplemental_address_3',
              'city',
              'state_province_id.country_id.name',
              'state_province_id.abbreviation',
              'postal_code',
            ]
          ]
        );

        if ( $addrs['count'] ) {
          $addr = $addrs['values'][0];

          return @array_filter([
              'Line1'                  => $addr['street_address'],
              'Line2'                  => $addr['supplemental_address_1'],
              'Line3'                  => $addr['supplemental_address_2'],
              'Line4'                  => $addr['supplemental_address_3'],
              'City'                   => $addr['city'],
              'Country'                => $addr['state_province_id.country_id.name'],
              'CountrySubDivisionCode' => $addr['state_province_id.abbreviation'],
              'PostalCode'             => $addr['postal_code'],
            ]);
        }
      }
    }

    return [];
  }

  /**
   * @param array $contact
   *
   * @return mixed|string
   * @throws \CRM_Core_Exception
   */
  public static function getBillingPhone($contact) {
    if (is_array($contact)) {
      $contact = $contact['id'];
    }

    if($contact) {
      $criteria_set = [
        [ 'location_type_id' => 'Billing' ], // Start with location type
        [ 'is_billing'       => 1         ], // Then check is_billing flag
        [ 'is_primary'       => 1         ], // Then check is_primary flag
        [                                 ], // Finally, fall back
      ];

      foreach ( $criteria_set as $criteria ) {
        $phones = civicrm_api3(
          'Phone',
          'get',
          $criteria + [
            'contact_id' => $contact,
            'options' => [ 'sort' => 'is_billing DESC, is_primary DESC, phone_type_id ASC, id DESC' ],
            // We can only make limited assertions about what phone types are
            // available, so exclude the default types we're sure are incorrect.
            'phone_type_id' => [ 'NOT IN' => ['Fax','Pager','Voicemail'] ],
            'sequential' => 1
          ]
        );

        if ( $phones['count'] ) {
          return $phones['values'][0]['phone'];
        }
      }
    }
    // The contact HAS no phone, apparently.
    return '';
  }

  /**
   * @param array $contact
   * @param string $accountsID
   * @param array|NULL $customer_data
   *
   * @return mixed|null
   * @throws \Exception
   */
  protected function mapToCustomer($contact, $accountsID, $customer_data) {
    $customer = [
      "BillAddr"           => self::getBillingAddr($contact),
      "Title"              => $contact['individual_prefix'],
      "GivenName"          => $contact['first_name'],
      "MiddleName"         => $contact['middle_name'],
      "FamilyName"         => $contact['last_name'],
      "Suffix"             => $contact['individual_suffix'],
      "FullyQualifiedName" => $contact['display_name'],
      "CompanyName"        => $contact['organization_name'],
      "DisplayName"        => $contact['display_name'],
      "PrimaryPhone"       => [
        "FreeFormNumber" => self::getBillingPhone($contact),
      ],
      "PrimaryEmailAddr"   => [
        "Address"        => self::getBillingEmail($contact),
      ],
    ];

    // This sets the company name field for Individuals to be their current
    // employer (if the contact has a current employer). Presumbably contacts of
    // the type "Individual" will never have an "organization_name" as that
    // field is for contacts of the type "Organization"
    if ($contact['contact_type'] == 'Individual' && !empty($contact['current_employer']) && empty($contact['organization_name'])) {
      $customer["CompanyName"] = $contact['current_employer'];
    }

    if (isset($accountsID)) {
      if (isset($customer_data)) {
        //NOTE here the customer_data is deserialized as an array.
        $customer['SyncToken'] = $customer_data['SyncToken'];
      }

      $customer['Id'] = $accountsID;
    }

    return \QuickBooksOnline\API\Facades\Customer::create($customer);

  }

  /**
   * Get all the customers from Quickbooks by providing a modification date.
   *
   * @param string $start_date_string
   *
   * @return array
   * @throws \CRM_Civiquickbooks_Contact_Exception
   */
  protected function getQBOContacts($start_date_string) {
    $date = date('c', strtotime($start_date_string));

    $query = "SELECT * FROM customer WHERE MetaData.LastUpdatedTime >= '" . $date . "'";

    try {
      $dataService = CRM_Quickbooks_APIHelper::getAccountingDataServiceObject();

      $dataService->throwExceptionOnError(FALSE);

      $customers = $dataService->Query($query, 0, 1000);
      if ($last_error = $dataService->getLastError()) {
        $error_message = CRM_Quickbooks_APIHelper::parseErrorResponse($last_error);

        throw new Exception('"' . implode("\n", $error_message) . '"');
      }

    }
    //process and analyse the response result from Quickbooks
    catch(Exception $e) {
      throw new CRM_Civiquickbooks_Contact_Exception('Error pulling Customers from QBO: ' . $e->getMessage(), 0);
    }

    if (empty($customers)) {
      // code 1 represent no customers received from Quickbooks
      throw new CRM_Civiquickbooks_Contact_Exception('No customers have been updated since the date passed as a parameter', 1);
    }

    return $customers;
  }

  /**
   * Get a single customer from Quickbooks Online by name.
   *
   * Quickbooks Online doesn't appear to differentiate between individuals and
   * companies except by what fields are present on the Customer record - this
   * function reflects the same by accepting either a FullyQualifiedName or
   * FamilyName + GivenName pair
   *
   * @param string $name      Family Name for Individuals or Company /
   *                   Fully Qualified Name for Organisations
   * @param string $givenName Given Name for Individuals if present; Contact is assumed
   *                   to be an Organisation otherwise.
   */
  protected function getQBOContactByName($name, $givenName = NULL) {
    $query = (
    empty($givenName)
      ? sprintf("SELECT * FROM Customer WHERE FullyQualifiedName = '%s'", addslashes($name))
      : sprintf("SELECT * FROM Customer WHERE FamilyName = '%s' AND GivenName = '%s'", addslashes($name), addslashes($givenName))
    );

    try {
      $dataService = CRM_Quickbooks_APIHelper::getAccountingDataServiceObject();

      $dataService->throwExceptionOnError(FALSE);

      $customers = $dataService->Query($query, 0, 1);
      if ($last_error = $dataService->getLastError()) {
        $error_message = CRM_Quickbooks_APIHelper::parseErrorResponse($last_error);

        throw new Exception('"' . implode("\n", $error_message) . '"');
      }

      return is_array($customers) ? current($customers) : NULL;
    }
    //process and analyse the response result from Quickbooks
    catch(Exception $e) {
      throw new CRM_Civiquickbooks_Contact_Exception('Error pulling single Customer from QBO: ' . $e->getMessage(), 0);
    }
  }

}

class CRM_Civiquickbooks_Contact_Exception extends CRM_Core_Exception {

}
