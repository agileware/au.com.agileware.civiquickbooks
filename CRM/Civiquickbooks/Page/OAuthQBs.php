<?php

define('CiviQB_EXTENSION_DIR', realpath(__DIR__ . '/../../..'));
define('CiviQB_EXTENSION_LIB_DIR', realpath(CiviQB_EXTENSION_DIR.'/library'));

require_once 'CRM/Core/Page.php';
require_once CiviQB_EXTENSION_LIB_DIR . '/OAuthSimple.php';

class CRM_Civiquickbooks_Page_OAuthQBs extends CRM_Core_Page {
  private $consumer_key;
  private $shared_secret;

  private $oauthObject;
  private $output;
  private $signatures;
  private $result;

  const REQUEST_URL = 'https://oauth.intuit.com/oauth/v1/get_request_token';
  const ACCESS_URL = 'https://oauth.intuit.com/oauth/v1/get_access_token';
  const AUTH_URL = 'https://appcenter.intuit.com/Connect/Begin';

  static function callback_url () {
    return str_replace("&amp;", "&", CRM_Utils_System::url("civicrm/quickbooks/OAuth",NULL,TRUE,NULL));
  }

  public function run() {
    $this->oauthObject = new OAuthSimple();

    //get current value in the database
    $this->consumer_key = civicrm_api3('Setting', 'getvalue', array('name' => "quickbooks_consumer_key"));
    $this->shared_secret  = civicrm_api3('Setting', 'getvalue', array('name' => "quickbooks_shared_secret"));

    //the initial value of consumer_key and shared_secret is empty string, need to check if they have been set
    if($this->consumer_key === 0 || $this->consumer_key == '' || !isset($this->consumer_key)) {
      throw new Exception("Initial Consumer Key is NOT set!", 1);
    }
    if($this->shared_secret === 0 || $this->shared_secret == '' || !isset($this->shared_secret)) {
      throw new Exception("Initial Shared Secret is NOT set!", 1);
    }

    $this->signatures = array(
      'consumer_key' => $this->consumer_key,
      'shared_secret' => $this->shared_secret);

    $this->output = array('message' => 'Authorizing...');

    try {
      if (!isset($_GET['oauth_verifier'])) {
        $this->result = $this->oauthObject->sign(array(
                          'path' => self::REQUEST_URL,
                          'parameters' => array('oauth_callback' => self::callback_url()),
                          'signatures' => $this->signatures));

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_URL, $this->result['signed_url']);
        $r = curl_exec($ch);
        curl_close($ch);

        parse_str($r, $returned_items);
        $request_token = $returned_items['oauth_token'];
        $request_token_secret = $returned_items['oauth_token_secret'];

        $_SESSION['request_secret'] = $request_token_secret;

        $this->result = $this->oauthObject->sign(array(
                          'path' => self::AUTH_URL,
                          'parameters' => array('oauth_token' => $request_token),
                          'signatures' => $this->signatures));

        // See you in a sec in step 3.
        header("Location:".$this->result['signed_url']);
        exit;
      }
      else {
        $this->signatures['oauth_secret'] = $_SESSION['request_secret'];
        $this->signatures['oauth_token'] = $_GET['oauth_token'];

        //store the realmId as in the next request we are just getting token, not other stuff
        $realmId = $_GET['realmId'];

        // Build the request-URL...
        $this->result = $this->oauthObject->sign(array(
                          'path' => self::ACCESS_URL,
                          'parameters' => array(
                            'oauth_verifier' => $_GET['oauth_verifier'],
                            'oauth_token' => $_GET['oauth_token']),
                          'signatures' => $this->signatures));

        // The final access token is different with the oauth_token in the query string in the callback url
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_URL, $this->result['signed_url']);
        $r = curl_exec($ch);

        // Voila, we've got a long-term access token.
        parse_str($r, $returned_items);
        $access_token = $returned_items['oauth_token'];
        $access_token_secret = $returned_items['oauth_token_secret'];

        // Expiry date = validate time (180 days) + current time.
        $expiration = ''.date('Y-m-d H:i:s',time() + (180 * 24 * 60 * 60));

        try {
          $result = civicrm_api3('Setting', 'create',
                    array(
                      'quickbooks_access_token' => $access_token,
                      'quickbooks_access_token_secret' => $access_token_secret,
                      'quickbooks_realmId' => $realmId,
                      'quickbooks_access_token_expiryDate' => $expiration,
                    ));
        }
        catch (CiviCRM_API3_Exception $e) {
          // Handle error here.
          $errorMessage = $e->getMessage();
          $errorCode = $e->getErrorCode();
          $errorData = $e->getExtraParams();
          return array(
            'error' => $errorMessage,
            'error_code' => $errorCode,
            'error_data' => $errorData,
          );
        }

        // Now We have got all information we need to connect to this Quickbooks company.
        // Let's get the company country.
        $_company_country = $this->_get_company_country();

        //if getting error response, set country settings as AU company.
        $_company_country = ( isset($_company_country['CompanyInfo']) ) ? $_company_country['CompanyInfo']['Country'] : 'AU';

        try {
          $result = civicrm_api3('Setting', 'create', array('quickbooks_company_country' => $_company_country));
        }
        catch (CiviCRM_API3_Exception $e) {
          // Handle error here.
          $errorMessage = $e->getMessage();
          $errorCode = $e->getErrorCode();
          $errorData = $e->getExtraParams();
          return array(
            'error' => $errorMessage,
            'error_code' => $errorCode,
            'error_data' => $errorData,
          );
        }

        $this->output = array(
          'message' => "Access token info retrieved and stored successfully!",
          'redirect_url' => '<a href="'.str_replace("&amp;", "&", CRM_Utils_System::url("civicrm/quickbooks/settings",null,true,null)).'">Click here to go back to CiviQuickbooks settings page to see the new expiry date of your new access token and key</a>',
        );

        unset($_SESSION['request_secret']);
      }
    }catch(OAuthSimpleException $e) {
      $this->output = array('message' => '<pre>' . $e .'</pre>');
    }

    $this->assign('output', $this->output);

    parent::run();
  }

  //Function for getting the linked company's country form QuickBooks
  protected function _get_company_country(){
    require_once(realpath( __DIR__ . '/../OAuthBase.php') );

    $_oauth_base = new CRM_Civiquickbooks_OAuthBase();

    $_oauth_base->request_uri = $_oauth_base->base_url . '/v3/company/' . $_oauth_base->realmId . '/companyinfo/' . $_oauth_base->realmId;

    $_oauth_base->reset_vars_to_pass();

    $_oauth_base->vars_to_pass['action'] = 'GET';
    $_oauth_base->vars_to_pass['path'] = $_oauth_base->request_uri;

    $_oauth_base->oauthObject->reset();

    $result = $_oauth_base->oauthObject->sign($_oauth_base->vars_to_pass);

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    curl_setopt($ch, CURLINFO_HEADER_OUT, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER,
      array(
        'Accept: application/json',
        'Content-Type: application/json',
      ));

    curl_setopt($ch, CURLOPT_URL, $result['signed_url']);

    $result = curl_exec($ch);

    curl_close($ch);

    return json_decode($result,TRUE);
  }
}
