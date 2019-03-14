<?php

class CRM_Quickbooks_APIHelper {

  private static $quickBooksDataService = NULL; //Data service object for login

  private static $quickBooksAccountingDataService = NULL; // Data service object for accounting and company info retrieval

  /**
   * Generate random State token to verify the Access token on redirection.
   *
   * @param $length
   * @param string $keyspace
   *
   * @return string
   * @throws Exception
   */
  public static function generateStateToken($length, $keyspace = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ') {
    $pieces = [];
    $max = mb_strlen($keyspace, '8bit') - 1;
    for ($i = 0; $i < $length; ++$i) {
      $pieces[] = $keyspace[random_int(0, $max)];
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
      'state' => json_encode($stateToken),
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
  public static function getAccountingDataServiceObject($forRefreshToken = FALSE) {

    if (!$forRefreshToken) {
      self::refreshAccessTokenIfRequired();
    }

    if (self::$quickBooksAccountingDataService && !$forRefreshToken) {
      return self::$quickBooksAccountingDataService;
    }

    $QBCredentials = self::getQuickBooksCredentials();
    $dataServiceParams = array(
      'auth_mode' => 'oauth2',
      'ClientID' => $QBCredentials['clientID'],
      'ClientSecret' => $QBCredentials['clientSecret'],
      'accessTokenKey' => $QBCredentials['accessToken'],
      'refreshTokenKey' => $QBCredentials['refreshToken'],
      'QBORealmID' => $QBCredentials['realMId'],
      'baseUrl' => "Development",
    );

    if ($forRefreshToken) {
      unset($dataServiceParams['accessTokenKey']);
    }

    $dataService = \QuickBooksOnline\API\DataService\DataService::Configure($dataServiceParams);
    if (!$forRefreshToken) {
      self::$quickBooksAccountingDataService = $dataService;
      return self::$quickBooksAccountingDataService;
    }

    return $dataService;
  }

  /**
   * Get redirection URL for OAuth request.
   *
   * @return mixed
   */
  private static function getRedirectUrl() {
    return str_replace("&amp;", "&", CRM_Utils_System::url("civicrm/quickbooks/OAuth", NULL, TRUE, NULL));
  }

  /**
   * Refresh QuickBooks access token if required.
   *
   * @throws CiviCRM_API3_Exception
   * @throws \QuickBooksOnline\API\Exception\SdkException
   */
  private function refreshAccessTokenIfRequired() {
    $QBCredentials = self::getQuickBooksCredentials();
    $now = new DateTime();
    $now->modify("-5 minutes");

    $isAccessTokenExpired = self::isTokenExpired($QBCredentials);
    $isRefreshTokenExpired = self::isTokenExpired($QBCredentials, TRUE);

    if ($isAccessTokenExpired || $isRefreshTokenExpired) {
      $dataService = self::getAccountingDataServiceObject(TRUE);

      try {
        $OAuth2LoginHelper = $dataService->getOAuth2LoginHelper();

        if ($isRefreshTokenExpired) {
          $refreshedAccessTokenObj = $OAuth2LoginHelper->refreshAccessTokenWithRefreshToken($QBCredentials['refreshToken']);
        }
        else {
          $refreshedAccessTokenObj = $OAuth2LoginHelper->refreshToken();
        }

        $tokenExpiresIn = new DateTime();
        $tokenExpiresIn->modify("+" . $refreshedAccessTokenObj->getAccessTokenValidationPeriodInSeconds() . "seconds");

        $refreshTokenExpiresIn = new DateTime();
        $refreshTokenExpiresIn->modify("+" . $refreshedAccessTokenObj->getRefreshTokenValidationPeriodInSeconds() . "seconds");

        $accessToken = $refreshedAccessTokenObj->getAccessToken();
        $refreshToken = $refreshedAccessTokenObj->getRefreshToken();

        civicrm_api3('Setting', 'create', array(
          'quickbooks_access_token' => $accessToken,
          'quickbooks_refresh_token' => $refreshToken,
          'quickbooks_access_token_expiryDate' => $tokenExpiresIn->format("Y-m-d H:i:s"),
          'quickbooks_refresh_token_expiryDate' => $refreshTokenExpiresIn->format("Y-m-d H:i:s"),
        ));

      } catch (\QuickBooksOnline\API\Exception\IdsException $e) {

      }
    }
  }

  /**
   * Get all required credentials to connect with QuickBooks
   *
   * @return array
   * @throws CiviCRM_API3_Exception
   */
  public static function getQuickBooksCredentials() {
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

  /**
   * Check if refresh/access token expired or not.
   *
   * @param $QBCredentials
   *
   * @return bool
   * @throws Exception
   */
  public static function isTokenExpired($QBCredentials, $isRefreshToken = FALSE) {
    $tokenKey = "accessTokenExpiryDate";
    if ($isRefreshToken) {
      $tokenKey = "refreshTokenExpiryDate";
    }
    $isTokenExpired = FALSE;
    if (isset($QBCredentials[$tokenKey]) && !empty($QBCredentials[$tokenKey]) && $QBCredentials[$tokenKey] != 1) {
      $currentDateTime = new DateTime();
      $currentDateTime->modify("-5 minutes");
      $tokenExpiryDate = DateTime::createFromFormat("Y-m-d H:i:s", $QBCredentials[$tokenKey]);

      if ($currentDateTime > $tokenExpiryDate) {
        $isTokenExpired = TRUE;
      }
    }

    return $isTokenExpired;
  }

}
