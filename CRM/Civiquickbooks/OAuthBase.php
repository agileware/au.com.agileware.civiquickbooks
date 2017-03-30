<?php

require_once('library/AboutQBs.php');
require_once('library/OAuthSimple.php');

class CRM_Civiquickbooks_OAuthBase {
  public $consumer_key;
  public $shared_secret;
  public $realmId;
  public $access_token;
  public $access_token_secret;
  public $base_url;
  public $request_uri;
  public $oauthObject;
  public $signatures;
  public $vars_to_pass;

  protected $contribution_status_settings;
  protected $contribution_status_settings_lower_reverse;

  protected $_plugin = 'quickbooks';

  public function __construct() {
    $this->consumer_key = get_QB_setting_value('quickbooks_consumer_key');
    $this->shared_secret = get_QB_setting_value('quickbooks_shared_secret');
    $this->access_token = get_QB_setting_value('quickbooks_access_token');
    $this->access_token_secret = get_QB_setting_value('quickbooks_access_token_secret');
    $this->realmId = get_QB_setting_value('quickbooks_realmId');

    //@TODO Need to replace this with production site url before push to public
    //current is development url:  https://sandbox-quickbooks.api.intuit.com
    //For production site url:         https://quickbooks.api.intuit.com
    $this->base_url = 'https://sandbox-quickbooks.api.intuit.com';

    $this->oauthObject = new OAuthSimple();

    $this->signatures = array(
      'oauth_token' => $this->access_token,
      'oauth_secret' => $this->access_token_secret,
      'consumer_key' => $this->consumer_key,
      'shared_secret' => $this->shared_secret,
    );

    $this->reset_vars_to_pass();

    $this->contribution_status_settings = civicrm_api3('Contribution', 'getoptions', array('field' => 'contribution_status_id'));

    $this->contribution_status_settings = $this->contribution_status_settings['values'];

    $this->contribution_status_settings_lower_reverse = array();

    foreach ($this->contribution_status_settings as $key => $value) {
      $this->contribution_status_settings[$key] = strtolower($value);

      $this->contribution_status_settings_lower_reverse[strtolower($value)] = $key;
    }
  }

  public function reset_vars_to_pass() {
    $this->vars_to_pass = array(
      'action' => FALSE,
      'path' => FALSE,
      'parameters' => array(
        'oauth_token' => FALSE,
        'oauth_nonce' => FALSE,
        'oauth_consumer_key' => FALSE,
        'oauth_signature_method' => FALSE,
        'oauth_timestamp' => FALSE,
        'oauth_version' => FALSE,
      ),
      'signatures' => $this->signatures,
    );
  }
}
