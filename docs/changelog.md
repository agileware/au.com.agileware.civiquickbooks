# Changes in this version

* A Contact whose push failed was described as retried up to 3 times and then marked `do not sync`.
  In fact it was never retried. The docs now describe the real behaviour (it waits for `Retry sync`),
  and the unused failure counter has been removed.
* Contact and Invoice push failures are now always shown on the Account Sync `Contact Errors` and
  `Invoice Errors` lists. Previously a failure after an earlier `Retry sync` was hidden.
* The Contact Summary `QuickBooks Sync Status` now shows when a Contact failed to sync or is set to
  `do not sync`, instead of reporting it as queued. Its link clears the error and updates the
  existing record, so it now really retries the Contact.
* The phone sent to QuickBooks no longer depends on CiviCRM Phone Types. Previously the Contact Push
  failed on sites where the Fax, Pager or Voicemail types had been removed.
* An Invoice for a Contact without a QuickBooks ID now fails with a clear message naming the Contact,
  instead of a confusing QuickBooks SDK error.
* An empty Contribution Source no longer stops an Invoice from being pushed.
* Payment reference numbers are cut to QuickBooks' 21 character limit, which previously made every
  Stripe payment fail.
* A Payment that fails after its Invoice has been created is retried on the next Invoice Push.
* New **Queue all contributions since Day zero?** option to queue existing Contributions, with batching
  and progress, and respecting the Account Sync payment processor skip list.
