<?php

class CRM_Quickbooks_APIHelper {

  private static $quickBooksDataService = NULL; //Data service object for login
  private static $quickBooksAccountingDataService = NULL; // Data service object for accounting and company info retrieval

  /**
   * Generate random State token to verify the Access token on redirection.
   *
   * @param $length
   * @param string $keyspace
   * @return string
   * @throws Exception
   */
  public static function generateStateToken($length, $keyspace = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ') {
    $pieces = [];
    $max = mb_strlen($keyspace, '8bit') - 1;
    for ($i = 0; $i < $length; ++$i) {
      $pieces []= $keyspace[random_int(0, $max)];
    }
    return implode('', $pieces);
  }

  /**
   * Generate dataservice object to verify and login into QuickBooks
   *
   * @return \QuickBooksOnline\API\DataService\DataService|null
   * @throws CiviCRM_API3_Exception
   * @throws \QuickBooksOnline\API\Exception\SdkException
   */
  public static function getLoginDataServiceObject() {

    if (self::$quickBooksDataService) {
      return self::$quickBooksDataService;
    }

    $redirectUrl = self::getRedirectUrl();
    $stateTokenValue = self::generateStateToken(40);

    $clientID = civicrm_api3('Setting', 'getvalue', array('name' => "quickbooks_consumer_key"));
    $clientSecret = civicrm_api3('Setting', 'getvalue', array('name' => "quickbooks_shared_secret"));

    $stateToken = array(
      'state_token' => $stateTokenValue,
    );
    Civi::settings()->set('quickbooks_state_token', $stateTokenValue);

    self::$quickBooksDataService = \QuickBooksOnline\API\DataService\DataService::Configure(array(
      'auth_mode' => 'oauth2',
      'ClientID' => $clientID,
      'ClientSecret' => $clientSecret,
      'RedirectURI' => $redirectUrl,
      'scope' => "com.intuit.quickbooks.accounting",
      'response_type' => 'code',
      'state'         => json_encode($stateToken),
    ));

    return self::$quickBooksDataService;
  }

  /**
   * Generates data service object for accounting into QuickBooks.
   *
   * @return \QuickBooksOnline\API\DataService\DataService|null
   * @throws CiviCRM_API3_Exception
   * @throws \QuickBooksOnline\API\Exception\SdkException
   */
  public static function getAccountingDataServiceObject() {

    if (self::$quickBooksAccountingDataService) {
      return self::$quickBooksAccountingDataService;
    }

    $redirectUrl = self::getRedirectUrl();

    $QBCredentials = self::getQuickBooksCredentials();

    self::$quickBooksAccountingDataService = \QuickBooksOnline\API\DataService\DataService::Configure(array(
      'auth_mode' => 'oauth2',
      'ClientID' => $QBCredentials['clientID'],
      'ClientSecret' => $QBCredentials['clientSecret'],
      'RedirectURI' => $redirectUrl,
      'accessTokenKey' => $QBCredentials['accessToken'],
      'refreshTokenKey' => $QBCredentials['refreshToken'],
      'QBORealmID' => $QBCredentials['realMId'],
      'baseUrl' => "Development"
    ));

    return self::$quickBooksAccountingDataService;
  }

  /**
   * Get redirection URL for OAuth request.
   * @return mixed
   */
  private static function getRedirectUrl() {
    return str_replace("&amp;", "&", CRM_Utils_System::url("civicrm/quickbooks/OAuth",NULL,TRUE,NULL));
  }

  /**
   * Get all required credentials to connect with QuickBooks
   *
   * @return array
   * @throws CiviCRM_API3_Exception
   */
  public function getQuickBooksCredentials() {
    $quickBooksSettings = civicrm_api3('Setting', 'get', array('group' => "QuickBooks Online Settings"));
    $quickBooksSettings = $quickBooksSettings['values'][$quickBooksSettings['id']];
    $clientID = $quickBooksSettings["quickbooks_consumer_key"];
    $clientSecret = $quickBooksSettings["quickbooks_shared_secret"];
    $accessToken = $quickBooksSettings["quickbooks_access_token"];
    $refreshToken = $quickBooksSettings["quickbooks_refresh_token"];
    $realMId = $quickBooksSettings["quickbooks_realmId"];
    $tokenExpiryDate = $quickBooksSettings["quickbooks_access_token_expiryDate"];
    $refreshTokenExpiryDate = $quickBooksSettings["quickbooks_refresh_token_expiryDate"];

    return array(
      'clientID' => $clientID,
      'clientSecret' => $clientSecret,
      'accessToken' => $accessToken,
      'refreshToken' => $refreshToken,
      'realMId' => $realMId,
      'accessTokenExpiryDate' => $tokenExpiryDate,
      'refreshTokenExpiryDate' => $refreshTokenExpiryDate,
    );
  }

}