<?php

class CRM_Civiquickbooks_RateLimitException extends CRM_Core_Exception {
    // Custom exception class to represent QBO API rate limit errors, allowing us to catch this specific case and handle it gracefully without treating it as a general error.
}