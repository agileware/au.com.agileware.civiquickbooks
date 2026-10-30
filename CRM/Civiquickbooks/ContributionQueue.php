<?php

use Civi\Api4\AccountContact;
use Civi\Api4\AccountInvoice;
use Civi\Api4\Contribution;
use Civi\Api4\EntityFinancialTrxn;
use CRM_Civiquickbooks_ExtensionUtil as E;

/**
 * Queue existing contributions for sync to QuickBooks.
 *
 * Account Sync only queues a contribution when it is saved, so contributions
 * that already existed when the sync was set up are never queued. This creates
 * the sync rows for them directly, using the same rules Account Sync applies
 * when a contribution is saved.
 *
 * The work is split into batches run through a CiviCRM queue, with a progress
 * screen, so a large backlog does not hit the PHP time limit.
 */
class CRM_Civiquickbooks_ContributionQueue {

  private const PLUGIN = 'quickbooks';

  /**
   * Number of contributions handled by each queue task.
   */
  private const BATCH_SIZE = 25;

  private const SESSION_KEY = 'civiquickbooks_queue_contributions';

  /**
   * Build a queue runner that queues every eligible contribution received on
   * or after Day zero.
   *
   * A contribution is eligible if it is not a test, has a non-zero amount, has
   * a status that is enabled for push in the Account Sync settings, and is not
   * a Pending contribution without the Pay Later flag. Each batch skips
   * contributions and contacts that already have a sync row, and contributions
   * paid through a payment processor on the Account Sync skip list.
   *
   * @return \CRM_Queue_Runner|NULL
   *   NULL if there are no contributions to queue.
   *
   * @throws \CRM_Core_Exception
   */
  public static function getRunner(): ?CRM_Queue_Runner {
    $contributions = self::getEligibleContributions();

    CRM_Core_Session::singleton()->set(self::SESSION_KEY, ['contributions' => 0, 'contacts' => 0]);

    if (empty($contributions)) {
      return NULL;
    }

    $queue = CRM_Queue_Service::singleton()->create([
      'name' => 'civiquickbooks_queue_contributions',
      'type' => 'Sql',
      'reset' => TRUE,
    ]);

    $total = count($contributions);
    $done = 0;
    foreach (array_chunk($contributions, self::BATCH_SIZE, TRUE) as $batch) {
      $done += count($batch);
      $queue->createItem(new CRM_Queue_Task(
        ['CRM_Civiquickbooks_ContributionQueue', 'runBatch'],
        [$batch],
        E::ts('Queueing contributions: %1 of %2', [1 => $done, 2 => $total])
      ));
    }

    return new CRM_Queue_Runner([
      'title' => E::ts('Queueing contributions for QuickBooks'),
      'queue' => $queue,
      'errorMode' => CRM_Queue_Runner::ERROR_ABORT,
      'onEnd' => ['CRM_Civiquickbooks_ContributionQueue', 'onEnd'],
      'onEndUrl' => CRM_Utils_System::url('civicrm/admin/setting/quickbooks', 'reset=1', TRUE, NULL, FALSE),
    ]);
  }

  /**
   * Find the contributions to consider queueing.
   *
   * @return array
   *   Contact ID, keyed by contribution ID.
   *
   * @throws \CRM_Core_Exception
   */
  private static function getEligibleContributions(): array {
    $dayZero = Civi::settings()->get('account_sync_contribution_day_zero');
    if (empty($dayZero)) {
      throw new CRM_Core_Exception(E::ts('Set "Day zero for contributions" before queueing contributions.'));
    }

    $pushStatuses = Civi::settings()->get('account_sync_push_contribution_status') ?: [];
    if (empty($pushStatuses)) {
      return [];
    }
    $pendingStatusId = array_search('Pending', CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name'));

    $eligible = [];
    $contributions = Contribution::get(FALSE)
      ->addSelect('id', 'contact_id', 'contribution_status_id', 'is_pay_later')
      ->addWhere('is_test', '=', FALSE)
      ->addWhere('total_amount', '!=', 0)
      ->addWhere('receive_date', '>=', $dayZero)
      ->addWhere('contribution_status_id', 'IN', $pushStatuses)
      ->addOrderBy('id')
      ->setLimit(0)
      ->execute();
    foreach ($contributions as $contribution) {
      // Same rule as Account Sync: skip incomplete transactions, which are
      // Pending contributions without the Pay Later flag.
      if ($contribution['contribution_status_id'] == $pendingStatusId && empty($contribution['is_pay_later'])) {
        continue;
      }
      $eligible[$contribution['id']] = $contribution['contact_id'];
    }

    return $eligible;
  }

  /**
   * Queue task: create the sync rows for one batch of contributions.
   *
   * @param \CRM_Queue_TaskContext $ctx
   * @param array $batch
   *   Contact ID, keyed by contribution ID.
   *
   * @return bool
   *
   * @throws \CRM_Core_Exception
   */
  public static function runBatch(CRM_Queue_TaskContext $ctx, array $batch): bool {
    $contributionIDs = array_keys($batch);
    $session = CRM_Core_Session::singleton();
    $created = $session->get(self::SESSION_KEY) ?: ['contributions' => 0, 'contacts' => 0];

    foreach (_accountsync_get_connectors() as $connector_id) {
      $queueInvoices = in_array('Contribution', _accountsync_get_invoice_create_entities($connector_id), TRUE);
      $createContacts = in_array('Contribution', _accountsync_get_contact_create_entities($connector_id), TRUE);

      $contributionsWithRow = array_flip(AccountInvoice::get(FALSE)
        ->addSelect('contribution_id')
        ->addWhere('plugin', '=', self::PLUGIN)
        ->addWhere('connector_id', '=', $connector_id)
        ->addWhere('contribution_id', 'IN', $contributionIDs)
        ->setLimit(0)
        ->execute()
        ->column('contribution_id'));
      $contactsWithRow = array_flip(AccountContact::get(FALSE)
        ->addSelect('contact_id')
        ->addWhere('plugin', '=', self::PLUGIN)
        ->addWhere('connector_id', '=', $connector_id)
        ->addWhere('contact_id', 'IN', array_values(array_unique($batch)))
        ->setLimit(0)
        ->execute()
        ->column('contact_id'));
      $skippedContributions = array_flip(self::getSkippedContributionIDs($contributionIDs, $connector_id));

      foreach ($batch as $contributionID => $contactID) {
        // As in Account Sync, the contact is queued before the contribution is
        // checked against the payment processor skip list.
        if (!isset($contactsWithRow[$contactID])) {
          _accountsync_create_account_contact((int) $contactID, $createContacts, $connector_id);
          $contactsWithRow[$contactID] = TRUE;
          $created['contacts']++;
        }

        if ($queueInvoices && !isset($contributionsWithRow[$contributionID]) && !isset($skippedContributions[$contributionID])) {
          _accountsync_create_account_invoice((int) $contributionID, $connector_id);
          $created['contributions']++;
        }
      }
    }

    $session->set(self::SESSION_KEY, $created);

    return TRUE;
  }

  /**
   * Find which of the given contributions were paid through a payment
   * processor on the Account Sync skip list.
   *
   * @param int[] $contributionIDs
   * @param int $connector_id
   *
   * @return int[]
   *
   * @throws \CRM_Core_Exception
   */
  private static function getSkippedContributionIDs(array $contributionIDs, int $connector_id): array {
    $skipProcessors = _accountsync_get_skip_invoice_create_entities($connector_id);
    if (empty($skipProcessors)) {
      return [];
    }

    return EntityFinancialTrxn::get(FALSE)
      ->addSelect('entity_id')
      ->addWhere('entity_table', '=', 'civicrm_contribution')
      ->addWhere('entity_id', 'IN', $contributionIDs)
      ->addWhere('financial_trxn_id.payment_processor_id', 'IN', $skipProcessors)
      ->setLimit(0)
      ->execute()
      ->column('entity_id');
  }

  /**
   * Queue runner callback: report what was queued.
   *
   * @param \CRM_Queue_TaskContext $ctx
   */
  public static function onEnd(CRM_Queue_TaskContext $ctx): void {
    $session = CRM_Core_Session::singleton();
    $created = $session->get(self::SESSION_KEY) ?: ['contributions' => 0, 'contacts' => 0];
    $session->set(self::SESSION_KEY, NULL);

    CRM_Core_Session::setStatus(
      E::ts('Queued %1 contributions and %2 contacts for sync to QuickBooks. They will be pushed the next time the Contact Push and Invoice Push jobs run.', [
        1 => $created['contributions'],
        2 => $created['contacts'],
      ]),
      E::ts('Contributions queued'),
      'success'
    );
  }

}
