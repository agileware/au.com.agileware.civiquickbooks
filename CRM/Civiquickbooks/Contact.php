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

      if($e instanceof CRM_Civiquickbooks_RateLimitException) {
        // API rate limit exceeded. Stop processing this run.
        throw new CRM_Core_Exception('QBO API rate limit exceeded while attempting to match existing customers, aborting contact pull.');
      } 
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
          // An unrecognised exception code: fail loudly rather than falling
          // through to the loop below with $qbo_contacts left undefined.
          throw new CRM_Core_Exception('Failed to pull customers from Quickbooks: ' . $e->getMessage());
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

      $save = TRUE;
      CRM_Accountsync_Hook::accountPullPreSave('contact', $contact, $save, $account_contact);

      if (!$save) {
        continue;
      }

      $matchedAccountContact = AccountContact::get(FALSE)
        ->addWhere('plugin', '=', $this->plugin)
        ->addWhere('connector_id', '=', 0)
        ->addWhere('accounts_contact_id', '=', $contact->Id)
        ->execute()
        ->first();

      if (!empty($matchedAccountContact)) {
        if ($matchedAccountContact['do_not_sync']) {
          // This contact is marked as Do Not Sync
          continue;
        }

        $account_contact['id'] = $matchedAccountContact['id'];
      }

      // If no existing AccountContact matched, this creates a new unlinked
      // record (no contact_id) so the QBO customer is visible for an
      // administrator to match or dedupe later, rather than being dropped.
      // Future CIVIQBO-60 entry point for preemptive deduplication.
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
        // A contact whose last push failed is left out until its error is
        // cleared, by "Retry sync" on Account Sync's Contact Errors list or by
        // the "Retry sync" link on the Contact Summary page.
        ->addWhere('error_data', 'IS NULL')
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
                case 429:
                  // API rate limit exceeded.
                  // Causes: Too many API calls in a given time period.
                  // Stop processing this run.
                  $abort_loop = TRUE;
                  throw new CRM_Civiquickbooks_RateLimitException('QBO API rate limit exceeded during contact push, aborting', 'qbo_rate_limit_exceeded');
                  break;

                default:
                  // QuickBooks refuses a new Customer whose name is already
                  // used by another record. Link to the existing Customer if
                  // that is what holds the name, otherwise explain what does.
                  if (empty($QBOContact->Id) && $this->isDuplicateNameError($last_error)) {
                    try {
                      $resolution = $this->resolveDuplicateName(
                        $dataService,
                        $account_contact,
                        $QBOContact,
                        // On unless an administrator turned it off. A site that
                        // never saved this setting gets its default, which is on.
                        (bool) Civi::settings()->get('quickbooks_link_existing_customer')
                      );
                    }
                    catch (CRM_Civiquickbooks_RateLimitException $e) {
                      $abort_loop = TRUE;
                      throw $e;
                    }

                    if (!empty($resolution['result'])) {
                      $result = $resolution['result'];
                      break;
                    }

                    $error_message = $resolution['error'];
                  }

                  $account_contact['error_data'] = json_encode(['error' => $error_message]);
                  // Show the error on Account Sync's Contact Errors list, even if
                  // an earlier error on this contact was retried or resolved.
                  $account_contact['is_error_resolved'] = 0;
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
            $account_contact['error_data'] = json_encode(['error' => [$e->getCode(), $e->getMessage()]]);
            $account_contact['is_error_resolved'] = 0;

            throw $e;
          }
          finally {
            if (gettype($account_contact['accounts_data']) == 'array') {
              $account_contact['accounts_data'] = json_encode($account_contact['accounts_data']);
            }

            // This will update the last sync date.
            unset($account_contact['last_sync_date']);

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
   * Is this QuickBooks fault a "Duplicate Name Exists" (error 6240) error?
   *
   * QuickBooks keeps a single list of names shared by Customers, Vendors,
   * Employees and Other Names, active or inactive, so creating a Customer fails
   * when anything in that list already uses its display name.
   *
   * @param \QuickBooksOnline\API\Core\HttpClients\FaultHandler $error
   *
   * @return bool
   */
  protected function isDuplicateNameError($error) {
    return (bool) preg_match('/<Error[^>]*\scode="6240"/', (string) $error->getResponseBody());
  }

  /**
   * Find the Customers, Vendors and Employees that use a display name,
   * including inactive ones. Other Names cannot be queried through the API.
   *
   * @param \QuickBooksOnline\API\DataService\DataService $dataService
   * @param string $displayName
   *
   * @return array[]
   *   Each element has 'type' (Customer, Vendor or Employee) and 'record'.
   *
   * @throws \CRM_Civiquickbooks_RateLimitException
   * @throws \CRM_Core_Exception
   */
  protected function findNameHolders($dataService, $displayName) {
    $holders = [];

    foreach (['Customer', 'Vendor', 'Employee'] as $type) {
      $rows = $dataService->Query(sprintf(
        "SELECT * FROM %s WHERE DisplayName = '%s' AND Active IN (true, false) MAXRESULTS 5",
        $type,
        addslashes($displayName)
      ));

      CRM_Quickbooks_APIHelper::checkForError($dataService, 'looking up a duplicate name');

      foreach ((array) $rows as $row) {
        $holders[] = ['type' => $type, 'record' => $row];
      }
    }

    return $holders;
  }

  /**
   * Deal with a new Customer being refused because its name is already used.
   *
   * If an existing QuickBooks Customer (active or inactive) holds the name, it
   * is reactivated if needed, linked to the Contact and updated from CiviCRM.
   * If a Vendor or Employee holds it, nothing is changed in QuickBooks and the
   * error says what to do instead.
   *
   * @param \QuickBooksOnline\API\DataService\DataService $dataService
   * @param array $account_contact
   * @param \QuickBooksOnline\API\Data\IPPCustomer $QBOContact
   *   The Customer that was refused.
   * @param bool $link
   *   Whether an existing Customer may be linked (the "Link existing
   *   QuickBooks customers" setting). If not, a Customer holding the name is
   *   reported like any other record, and nothing is changed.
   *
   * @return array
   *   ['result' => the QuickBooks Customer as saved] when linked, otherwise
   *   ['error' => string[]] explaining what to do.
   *
   * @throws \CRM_Civiquickbooks_RateLimitException
   */
  protected function resolveDuplicateName($dataService, $account_contact, $QBOContact, $link = TRUE) {
    $name = $QBOContact->DisplayName;

    try {
      $holders = $this->findNameHolders($dataService, $name);
    }
    catch (CRM_Civiquickbooks_RateLimitException $e) {
      throw $e;
    }
    catch (CRM_Core_Exception $e) {
      return ['error' => [E::ts(
        'QuickBooks says the name "%1" is already in use, and looking up what uses it failed: %2',
        [1 => $name, 2 => $e->getMessage()]
      )]];
    }

    $customer = NULL;
    $other = NULL;
    foreach ($holders as $holder) {
      if ($holder['type'] == 'Customer') {
        $customer = $holder['record'];
        break;
      }
      $other = $other ?: $holder;
    }

    if (!$customer) {
      return ['error' => [$this->describeNonCustomerNameHolder($name, $other)]];
    }

    if (!$link) {
      $inactive = (strtolower((string) $customer->Active) === 'false');

      return ['error' => [E::ts(
        'QuickBooks already has %1 Customer named "%2" (QuickBooks ID %3). It was not linked to this Contact because "Link existing QuickBooks customers with the same name?" is turned off on the QuickBooks Online Settings page. Turn that setting on, or rename the Customer in QuickBooks, then use Retry sync on this Contact.',
        [1 => $inactive ? E::ts('an inactive') : E::ts('a'), 2 => $name, 3 => $customer->Id]
      )]];
    }

    // The Customer must not already belong to another CiviCRM Contact.
    $linked = AccountContact::get(FALSE)
      ->addSelect('id', 'contact_id', 'contact_id.display_name')
      ->addWhere('plugin', '=', $this->plugin)
      ->addWhere('connector_id', '=', 0)
      ->addWhere('accounts_contact_id', '=', $customer->Id)
      ->addWhere('id', '!=', $account_contact['id'])
      ->execute()
      ->first();

    if ($linked) {
      if (!empty($linked['contact_id'])) {
        return ['error' => [E::ts(
          'The QuickBooks Customer "%1" (ID %2) is already linked to CiviCRM Contact %3 (%4), so it was not linked to this Contact. Merge the duplicate Contacts, or change the display name of one of them, then use Retry sync.',
          [1 => $name, 2 => $customer->Id, 3 => $linked['contact_id'], 4 => $linked['contact_id.display_name']]
        )]];
      }

      return ['error' => [E::ts(
        'The QuickBooks Customer "%1" (ID %2) already exists in Account Sync as an unmatched QuickBooks customer (Account Contact record %3), so it was not linked to this Contact. Delete that unmatched record, then use Retry sync.',
        [1 => $name, 2 => $customer->Id, 3 => $linked['id']]
      )]];
    }

    $reactivate = (strtolower((string) $customer->Active) === 'false');

    $QBOContact->Id = $customer->Id;
    $QBOContact->SyncToken = $customer->SyncToken;
    if ($reactivate) {
      $QBOContact->Active = TRUE;
    }

    $result = $dataService->Update($QBOContact);

    if ($last_error = $dataService->getLastError()) {
      $error_message = CRM_Quickbooks_APIHelper::parseErrorResponse($last_error);

      if ($last_error->getHttpStatusCode() == 429) {
        throw new CRM_Civiquickbooks_RateLimitException('QBO API rate limit exceeded during contact push, aborting', 'qbo_rate_limit_exceeded', $error_message);
      }

      return ['error' => array_merge([E::ts(
        'The name "%1" belongs to an existing QuickBooks Customer (ID %2), but linking this Contact to it failed:',
        [1 => $name, 2 => $customer->Id]
      )], $error_message)];
    }

    Civi::log()->info(E::ts(
      'QuickBooks Contact Push: linked Contact %1 to the existing QuickBooks Customer %2 ("%3")%4.',
      [1 => $account_contact['contact_id'], 2 => $customer->Id, 3 => $name, 4 => $reactivate ? E::ts(' and reactivated it') : '']
    ));

    return ['result' => $result];
  }

  /**
   * Explain how to fix a name clash with something that is not a Customer.
   *
   * @param string $name
   * @param array|null $holder
   *   The Vendor or Employee holding the name, from findNameHolders(), or
   *   NULL if nothing was found.
   *
   * @return string
   */
  protected function describeNonCustomerNameHolder($name, $holder) {
    if (!$holder) {
      return E::ts(
        'QuickBooks says the name "%1" is already in use, but no Customer, Vendor or Employee with that name was found. It may belong to an Other Name record, or differ only in spacing or punctuation. Ask your QuickBooks administrator to find it and rename it, then use Retry sync.',
        [1 => $name]
      );
    }

    $record = $holder['record'];
    $inactive = (strtolower((string) $record->Active) === 'false');

    return E::ts(
      'QuickBooks already has %1 %2 named "%3" (QuickBooks ID %4), and QuickBooks does not allow a Customer to share a name with a %2. In QuickBooks, rename that %2 (for example to "%3 (%2)"), then use Retry sync on this Contact. Nothing was changed in QuickBooks.',
      [
        1 => $inactive ? E::ts('an inactive') : E::ts('a'),
        2 => $holder['type'],
        3 => $name,
        4 => $record->Id,
      ]
    );
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

      // Phone Types can be customised, disabled or deleted on each site, so do
      // not filter on them here (a missing type name makes the API throw).
      foreach ( $criteria_set as $criteria ) {
        $phones = civicrm_api3(
          'Phone',
          'get',
          $criteria + [
            'contact_id' => $contact,
            'options' => [ 'sort' => 'is_billing DESC, is_primary DESC, phone_type_id ASC, id DESC' ],
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
      CRM_Quickbooks_APIHelper::checkForError($dataService, 'pulling customers');
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
      CRM_Quickbooks_APIHelper::checkForError($dataService, 'pulling single customer');

      return is_array($customers) ? current($customers) : NULL;
    }
    //process and analyse the response result from Quickbooks
    catch(Exception $e) {
      if($e instanceof \CRM_Civiquickbooks_RateLimitException) {
        // API rate limit exceeded. Stop processing this run.
        throw $e;
      }

      throw new CRM_Civiquickbooks_Contact_Exception('Error pulling single Customer from QBO: ' . $e->getMessage(), 0);
    }
  }

}

class CRM_Civiquickbooks_Contact_Exception extends CRM_Core_Exception {

}
