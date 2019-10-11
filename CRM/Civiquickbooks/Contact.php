<?php

require_once 'library/CustomException.php';
require getComposerAutoLoadPath();

class CRM_Civiquickbooks_Contact {

  private $plugin = 'quickbooks';

  public function pull($params) {
    $result = NULL;

    $start_date = (isset($params['start_date'])) ? $params['start_date'] : 'yesterday';

    // try to get customers info from quickbooks based on the date provided.
    try {
      $qbo_contacts = $this->getQBOContacts($start_date);
    } catch (CRM_Civiquickbooks_Contact_Exception $e) {
      switch ($e->getCode()) {
        case 0:
          throw new CRM_Core_Exception('Failed to pull customers from Quickbooks: ' . $e->getMessage());

          break;
        case 1:
          return ['No customers are updated in Quickbooks since ' . $start_date . '; Contact pull aborted'];

          break;
        default:
          break;
      }
    }

    // Now we are going to loop through all contacts in the result.
    foreach ($qbo_contacts as $contact) {
      $account_contact = array(
        'accounts_display_name' => $contact->DisplayName,
        // AccountSync API can not parse this date format correctly, if we use the DAO directly, it has no problem.
        // 'accounts_modified_date' => date('Y-m-d H:i:s', strtotime($contact['MetaData']['LastUpdatedTime'])),
        'plugin' => $this->plugin,
        'accounts_contact_id' => $contact->Id,
        'accounts_data' => json_encode($contact),
        'accounts_needs_update' => 0,
        'sequential' => 1,
        'error_data' => 'NULL',
      );

      try {
        $account_contact['id']= civicrm_api3('account_contact', 'getvalue', array(
          'accounts_contact_id' => $contact->Id,
          'plugin' => $this->plugin,
        ));
      } catch (CiviCRM_API3_Exception $e) {
        /* If there is no account contact found, either:

        the contact is not recorded by AccountSync,

        OR the contact is recorded but not pushed, so there is no account_contact_id, but we have
        this contact info in Quickbooks and it is updated.

        In the second case, the consequence could be:
        1. Duplicated contacts will be created in quickbooks and duplicated account_contact entity will be created.
        2. Contact push for that particular contact will fail, as quickbooks recognised that is a duplicated contact.

        For both cases, we can not deal with here and make the decision for users.

        So we assume that if no contact can not be found by Quickbooks id, then there is no existing contact record captured by AccountSync
         */
        continue;
      }

      //create/update account contact entity.
      try {
        $created = civicrm_api3('account_contact', 'create', $account_contact);

        CRM_Core_DAO::setFieldValue(
          'CRM_Accountsync_DAO_AccountContact',
          $created['values'][0]['id'],
          'accounts_modified_date',
          date("Y-m-d H:i:s", strtotime($contact->MetaData->LastUpdatedTime)),
          'id');
      } catch (CiviCRM_API3_Exception $e) {
        CRM_Core_Session::setStatus(ts('Failed to store ') . $account_contact['accounts_display_name']
          . ts(' with error ') . $e->getMessage(),
          ts('Contact Pull failed'));
      }
    }

    return $result;
  }

  public function push($limit = PHP_INT_MAX) {
    try {
      $records = civicrm_api3('account_contact', 'get', array(
          'accounts_needs_update' => 1,
          'api.contact.get' => 1,
          'plugin' => $this->plugin,
          'contact_id' => array('IS NOT NULL' => 1),
          'connector_id' => 0,
          'options' => array(
            'limit' => $limit,
          ),
        )
      );

      $errors = array();

      foreach ($records['values'] as $account_contact) {
        //This working-around it not quite useful now. We already have `	'contact_id' => array('IS NOT NULL' => 1),` in the api call.
        //So there should not be any record in our result who has no contact id and has 25 civicrm contact record in the result of api.contact.get chain call.
        if (!isset($account_contact['contact_id'])) {
          $errors[] = ts('Failed to push a record that has no CiviCRM contact id (account_contact_id: %1). Contact Push failed.', array(1 => $account_contact['accounts_contact_id']));
          continue;
        }

        try {
          $id = isset($account_contact['accounts_contact_id']) ? $account_contact['accounts_contact_id'] : NULL;

          // NOTE if we store the json string in the response directly using Accountsync API, it will serialized it for us automatically.
          // And when we get it out using api, it will deserialize automatically for us.
          $accounts_data = isset($account_contact['accounts_contact_id']) ? $account_contact['accounts_data'] : NULL;

          $QBOContact = $this->mapToCustomer($account_contact['api.contact.get']['values'][0], $id, $accounts_data);

          $proceed = TRUE;
          CRM_Accountsync_Hook::accountPushAlterMapped('contact', $account_contact, $proceed, $QBOContact);

          if(!$proceed) {
            continue;
          }

          unset($account_contact['api.contact.get']);

          try {
            $dataService = CRM_Quickbooks_APIHelper::getAccountingDataServiceObject();

            if ($QBOContact->Id) {
              $result = $dataService->Update($QBOContact);
            }
            else {
              $result = $dataService->Add($QBOContact);
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
            $account_contact['error_data'] = json_encode([[$e->getCode() => $e->getMessage()]]);

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
        } catch (Exception $e) {
          $errors[] = ts(
            'Failed to push %1 (%2) with error %3',
            [
              1 => $account_contact['contact_id'],
              2 => $account_contact['accounts_contact_id'],
              3 => $e->getMessage()
            ]);
        }
      }

      if ($errors) {
        // since we expect this to wind up in the job log we'll print the errors
        throw new CRM_Core_Exception(ts("Not all contacts were saved: \n ") . implode("\n  ", $errors), 'incomplete', $errors);
      }
      return TRUE;
    } catch (CiviCRM_API3_Exception $e) {
      throw new CRM_Core_Exception('Contact Push aborted due to: ' . $e->getMessage());
    }
  }

  protected function mapToCustomer($contact, $accountsID, $customer_data) {
    $customer = array(
      "BillAddr" => array(
        "Line1" => $contact['street_address'],
        "City" => $contact['city'],
        "Country" => $contact['country'],
        "CountrySubDivisionCode" => $contact['state_province'],
        "PostalCode" => $contact['postal_code'],
      ),
      "Title" => $contact['individual_prefix'],
      "GivenName" => $contact['first_name'],
      "MiddleName" => $contact['middle_name'],
      "FamilyName" => $contact['last_name'],
      "Suffix" => $contact['individual_suffix'],
      "FullyQualifiedName" => $contact['display_name'],
      "CompanyName" => $contact['organization_name'],
      "DisplayName" => $contact['display_name'],
      "PrimaryPhone" => array(
        "FreeFormNumber" => $contact['phone'],

      ),
      "PrimaryEmailAddr" => array(
        "Address" => $contact['email'],
      ),
    );

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
   *  Get all the customers from Quickbooks by providing a modification date.
   */
  protected function getQBOContacts($start_date_string) {
    $date = date('c', strtotime($start_date_string));

    $query = "SELECT * FROM customer WHERE MetaData.LastUpdatedTime >= '" . $date . "'";

    try {
      $dataService = CRM_Quickbooks_APIHelper::getAccountingDataServiceObject();

      $customers = $dataService->Query($query, 0, 1000);

    }
    //process and analyse the response result from Quickbooks
    catch(\QuickbooksOnline\API\Exception\IdsException $e) {
      throw new CRM_Civiquickbooks_Contact_Exception('Error in customer pulling from QBs: ' . $e->getMessage(), 0);
    }

    if (empty($customers)) {
      // code 1 represent no customers received from Quickbooks
      throw new CRM_Civiquickbooks_Contact_Exception('No customers have been updated since the date passed as a parameter', 1);
    }

    return $customers;
  }

}

/**
 * it uses Class declared in library/CustomException.php
 * Class ContactPullGetQBCustomersException
 */
class CRM_Civiquickbooks_Contact_Exception extends CustomException {

}
