<?php

require_once 'library/CustomException.php';
require getComposerAutuLoadPath();

class CRM_Civiquickbooks_Contact {

  private $_plugin = 'quickbooks';

  public function pull($params) {
    $_result_to_return = NULL;

    $_start_date_string = (isset($params['start_date'])) ? $params['start_date'] : 'yesterday';

    // try to get customers info from quickbooks based on the date provided.
    try {
      $_contacts_from_QBs = $this->_get_QBs_customers($_start_date_string);
    }
    catch (ContactPullGetQBCustomersException $e) {
      switch ($e->getCode()) {
        case 0:
          CRM_Core_Session::setStatus('Failed to pull customers from Quickbooks, as: ' . $e->getMessage());
          return FALSE;

        break;

        case 1:
          CRM_Core_Session::setStatus('No customers are updated in Quickbooks since ' . $_start_date_string . '. Contacts pulling aborted');
          return TRUE;

        break;

      }
    }

    // Now we are going to loop through all contacts in the result.
    foreach ($_contacts_from_QBs as $contact) {
      $_params_for_account_sync_api = array(
        'accounts_display_name' => $contact->DisplayName,
        //AccountSync API can not parse this date format correctly, if we use the DAO directly, it has no problem.
        //'accounts_modified_date' => date('Y-m-d H:i:s', strtotime($contact['MetaData']['LastUpdatedTime'])),
        'plugin' => $this->_plugin,
        'accounts_contact_id' => $contact->Id,
        'accounts_data' => json_encode($contact),
        'accounts_needs_update' => 0,
        'sequential' => 1,
        'error_data' => 'NULL',
      );

      try {
        $_tmp_QBs_contact = civicrm_api3('account_contact', 'getsingle', array(
                              'accounts_contact_id' => $contact->Id,
                              'plugin' => $this->_plugin,
                            ));

        $_params_for_account_sync_api['id'] = $_tmp_QBs_contact['id'];
      }
      catch (CiviCRM_API3_Exception $e) {
        /*if there is no account contact found, it means that:

        Either: The contact is not recorded by AccountSync

        OR: The contact is recorded but not pushed, so there is no account_contact_id, but somehow (maybe manually created)we have
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
        $result = civicrm_api3('account_contact', 'create', $_params_for_account_sync_api);

        CRM_Core_DAO::setFieldValue(
          'CRM_Accountsync_DAO_AccountContact',
          $result['values'][0]['id'],
          'accounts_modified_date',
          date("Y-m-d H:i:s", strtotime($contact->MetaData->LastUpdatedTime)),
          'id');
      }
      catch (CiviCRM_API3_Exception $e) {
        CRM_Core_Session::setStatus(ts('Failed to store ') . $_params_for_account_sync_api['accounts_display_name']
          . ts(' with error ') . $e->getMessage(),
          ts('Contact Pull failed'));
      }
    }

    return $_result_to_return;
  }

  public function push($limit = PHP_INT_MAX) {
    try {
      $records = civicrm_api3('account_contact', 'get', array(
                   'accounts_needs_update' => 1,
                   'api.contact.get' => 1,
                   'plugin' => $this->_plugin,
                   'contact_id' => array('IS NOT NULL' => 1),
                   'connector_id' => 0,
                   'options' => array(
                     'limit' => $limit,
                   ),
                 )
      );

      $errors = array();

      foreach ($records['values'] as $record) {
        //This working-around it not quite useful now. We already have `	'contact_id' => array('IS NOT NULL' => 1),` in the api call.
        //So there should not be any record in our result who has no contact id and has 25 civicrm contact record in the result of api.contact.get chain call.
        if (!isset($record['contact_id'])) {
          $errors[] = ts('Failed to push ') . 'a record that has no CiviCRM contact id.  (account_contact_id: ' . $record['accounts_contact_id'] . ' ) '
            . ts('Contact Push failed');
          continue;
        }

        try {
          $accountsContactID = isset($record['accounts_contact_id']) ? $record['accounts_contact_id'] : NULL;

          // NOTE if we store the json string in the response directly using Accountsync API, it will serialized it for us automatically.
          // And when we get it out using api, it will deserialize automatically for us.
          $customer_data = isset($record['accounts_contact_id']) ? $record['accounts_data'] : NULL;

          $accountsContact = $this->mapToCustomer($record['api.contact.get']['values'][0], $accountsContactID, $customer_data);

          unset($record['api.contact.get']);

          $dataService = CRM_Quickbooks_APIHelper::getAccountingDataServiceObject();

          if ($accountsContact->Id) {
            $result = $dataService->Update($accountsContact);
          }
          else {
            $result = $dataService->Add($accountsContact);
          }

          $responseErrors = array();
          if (!$result) {
            $responseErrors = $dataService->getLastError();
          }

          if (!empty($responseErrors)) {
            $record['error_data'] = json_encode([$responseErrors->getResponseBody()]);

            if (gettype($record['accounts_data']) == 'array') {
              $record['accounts_data']  = json_encode($record['accounts_data']);
            }
          }
          elseif ($result) {

            $record['error_data'] = 'NULL';

            $record['accounts_contact_id'] = $result->Id;

            CRM_Core_DAO::setFieldValue(
              'CRM_Accountsync_DAO_AccountContact',
              $record['id'],
              'accounts_modified_date',
              date("Y-m-d H:i:s", strtotime($result->MetaData->LastUpdatedTime)),
              'id');

            $record['accounts_data'] = json_encode($result);

            $record['accounts_display_name'] = $result->DisplayName;

            $record['accounts_needs_update'] = 0;
          }

          // This will update the last sync date.
          unset($record['last_sync_date']);

          $result = civicrm_api3('account_contact', 'create', $record);
        }
        catch (CiviCRM_API3_Exception $e) {
          $errors[] = ts('Failed to push ') . $record['contact_id'] . ' (' . $record['accounts_contact_id'] . ' )'
            . ts(' with error ') . $e->getMessage() . print_r($responseErrors, TRUE)
                                                    . ts('Contact Push failed');
        }
      }

      if ($errors) {
        // since we expect this to wind up in the job log we'll print the errors
        throw new CRM_Core_Exception(ts('Not all contacts were saved') . print_r($errors, TRUE), 'incomplete', $errors);
      }
      return TRUE;
    }
    catch (CiviCRM_API3_Exception $e) {
      throw new CRM_Core_Exception('Contact Push aborted due to: ' . $e->getMessage());
    }
  }

  protected function mapToCustomer($contact, $accountsID, $customer_data) {
    $customer = array();

    if (isset($accountsID)) {
      if (isset($customer_data)) {
        //NOTE here the customer_data is deserialized as an array.
        $customer['SyncToken'] = $customer_data['SyncToken'];
      }

      $customer['Id'] = $accountsID;
    }

    $customer = $customer + array(
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

    $customer = \QuickBooksOnline\API\Facades\Customer::create($customer);

    return $customer;
  }

  /**
   *  Get all the customers from Quickbooks by providing a modification date.
   */
  protected function _get_QBs_customers($start_date_string) {

    $_result_to_return = NULL;

    $_date_UTC_string_for_QBs = date('c', strtotime($start_date_string));

    $query = "select * from customer where MetaData.LastUpdatedTime >= '" . $_date_UTC_string_for_QBs . "'";

    $dataService = CRM_Quickbooks_APIHelper::getAccountingDataServiceObject();
    $customers = $dataService->Query($query, 0, 1000);

    $error = $dataService->getLastError();
    $_result_to_return = $customers;

    //process and analyse the response result from Quickbooks
    if ($error) {
      // code 0 represent error received from Quickbooks. json result from Quickbooks is inserted into the message.
      throw new ContactPullGetQBCustomersException('Got Error in customer pulling from QBs, Json: ' . $result, 0);
    }
    else {
      if (empty($_result_to_return)) {
        // code 1 represent no customers received from Quickbooks
        throw new ContactPullGetQBCustomersException('No customers have been updated since the date past as param', 1);
      }
    }

    return $_result_to_return;
  }

}

/**
 * it uses Class declared in library/CustomException.php
 * Class ContactPullGetQBCustomersException
 */
class ContactPullGetQBCustomersException extends CustomException {

}
