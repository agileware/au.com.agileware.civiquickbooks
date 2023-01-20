<?php
use CRM_Civiquickbooks_ExtensionUtil as E;

/**
 * Collection of upgrade steps.
 */
class CRM_Civiquickbooks_Upgrader extends CRM_Extension_Upgrader_Base {

  // By convention, functions that look like "function upgrade_NNNN()" are
  // upgrade tasks. They are executed in order (like Drupal's hook_update_N).

  /**
   * Example: Work with entities usually not available during the install step.
   *
   * This method can be used for any post-install tasks. For example, if a step
   * of your installation depends on accessing an entity that is itself
   * created during the installation (e.g., a setting or a managed entity), do
   * so here to avoid order of operation problems.
   */
  public function postInstall() {
    $this->createTags();
  }

  private function createTags() {
    if (empty(\Civi\Api4\Tag::get(FALSE)
      ->addWhere('name', '=', 'Quickbooks Sync Error')
      ->execute()->count())) {
      \Civi\Api4\Tag::create(FALSE)
        ->addValue('is_reserved', TRUE)
        ->addValue('name', 'Quickbooks Sync Error')
        ->execute();
    }
  }

  public function upgrade_20203() {
    $this->ctx->log->info('Setting default email preference to "Never" for compatibility.');
    civicrm_api3('Setting', 'create', [ 'quickbooks_email_invoice' => 'never' ]);
    return TRUE;
  }

  public function upgrade_20204() {
    $this->ctx->log->info('Adding "Quickbooks Sync Error" tag.');
    $this->createTags();
    return TRUE;
  }

}
