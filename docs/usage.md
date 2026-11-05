# Usage

## Scheduled Jobs

This extension registers four Scheduled Jobs, active by default once installed:

* **Civiquickbooks Contact Push Job** - pushes CiviCRM contacts flagged as needing an update to
  QuickBooks Online as Customers.
* **Civiquickbooks Contact Pull Job** - first matches queued Contacts that have no QuickBooks ID to
  existing QuickBooks Customers of the same name, then pulls Customers updated in QuickBooks Online
  since the previous day into CiviCRM, matching or creating Account Sync records for later review.
* **Civiquickbooks Invoice Push Job** - pushes Contributions flagged as needing an update to
  QuickBooks Online as Invoices, including associated Payments.
* **Civiquickbooks Invoice Pull Job** - pulls the current status of previously-pushed Invoices from
  QuickBooks Online, updating the matching Contribution's status (e.g. marking it Completed when the
  Invoice is paid in full, or Cancelled when the Invoice is voided).

These jobs only run if CiviCRM's own Scheduled Jobs / cron is configured on
your site. You can review each job's configuration and run history under
`Administer` > `System Settings` > `Scheduled Jobs`.

## Sync status on the Contact page

A Contact who has been synchronised, or is queued to be synchronised, with QuickBooks Online shows
a "QuickBooks Sync Status" summary on their Contact Summary page, along with a link to manually
queue the Contact to be re-synchronised. If the Contact or any of their Contributions have a sync
error, a "Contact Sync Errors" and/or "Invoice Sync Errors" indicator is also shown; clicking it
shows the underlying error message(s) reported by QuickBooks Online.

Contacts whose push to QuickBooks Online fails are tagged with a reserved "Quickbooks Sync Error"
tag, which is automatically removed once the Contact syncs successfully. A Contact that fails to
push more than 3 times in a row is automatically marked as excluded from future syncing (`do not
sync`) until an administrator investigates and re-queues it.

## System-wide status check

A CiviCRM System Status message is shown (`Administer` > `System Settings` > `System Status`,
also visible on the CiviCRM home/dashboard) when the stored QuickBooks Online refresh token has
expired, prompting an administrator to re-authorise the connection via the `QuickBooks Online
Settings` page.

## QuickBooks Online API rate limits

If QuickBooks Online reports that its API rate limit has been exceeded, the extension records the
time this occurred and blocks further CiviQuickBooks Push/Pull API calls for up to an hour, to
avoid repeatedly hitting the limit. Invoice pushes are also throttled to roughly one request per
second to reduce the likelihood of hitting QuickBooks Online's rate limits in the first place.
