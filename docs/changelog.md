# Changes in this version

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
