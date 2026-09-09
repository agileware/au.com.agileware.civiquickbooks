<?php

class CRM_Civiquickbooks_RateLimitException extends CRM_Core_Exception {
    // Custom exception class to represent QBO API rate limit errors, allowing us to catch this specific case and handle it gracefully without treating it as a general error.

    public function __construct($message, $error_code = NULL, $extra_error_data = NULL) {
      parent::__construct($message, $error_code, $extra_error_data);

      // Record that QBO's API rate limit was hit, so the persistent circuit
      // breaker (CRM_Quickbooks_APIHelper::checkApiRateExceeded) can block
      // further QuickBooks sync API calls until the retry window has
      // passed, rather than every subsequent scheduled job run immediately
      // re-triggering the same rate limit.
      CRM_Quickbooks_APIHelper::setApiRateLimitExceeded();
    }
}