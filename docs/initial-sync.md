# Initial sync: step by step

Follow these steps, in order, the first time you connect CiviCRM to a QuickBooks Online company. They
use the settings described in [Configuration](configuration.md) and [Mapping financial accounts](financial-account-mapping.md), and take you from a fresh install to Contacts and Invoices in
QuickBooks.

**Before you start**

* Take a backup of your CiviCRM database.
* Decide whether you are connecting to QuickBooks `Production` or a `Development` (sandbox) company. If
  this is your first time, trying it on a Development company first is a good idea.
* Make sure CiviCRM's Scheduled Jobs (cron) run on your site, or be ready to run the jobs by hand in
  step 9.
* The four Scheduled Jobs are active as soon as the extension is installed, and run each time your
  cron runs (every 15 minutes on some sites). Disable them under `Administer` > `System Settings` >
  `Scheduled Jobs` until step 11, so they cannot run against half-finished settings or start pushing
  part way through your mapping. Jobs that run before the connection is authorised log `not
  authorized` errors.
* Everything that is pushed becomes real accounting data. A Contribution is pushed as an Invoice, and
  as a Payment if it has been paid. Records that have been sent are not sent again, and they are not removed from
  QuickBooks if you change your mind, so test with a few records first (step 6).

**Step 1. Install and enable**

Install and enable **Account Sync** and **QuickBooks Online Integration** as described in
[Installation](installation.md#installation).

**Step 2. Connect to QuickBooks**

Create the Intuit app, complete Intuit's [App assessment
questionnaire](app-assessment-questionnaire.md) (needed for Production keys), enter the Client ID and Client Secret, add the Redirect URI to
the Intuit app, and click **Authorize**, as described in
[Configuration](configuration.md#create-an-intuit-app-and-get-your-keys). Confirm that the `Access
Token Expiry Date` shows a date.

**Step 3. Map Products/Services and tax codes**

For every Financial Type you use:

1. Set the **Income Account** Financial Account's `Acctg Code` to the exact name of the QuickBooks
   Product/Service.
2. Set the **Sales Tax Account** Financial Account's `Acctg Code` to the QuickBooks tax code name (AU:
   for example `GST` or `GST free`). US companies, see [For US Companies](financial-account-mapping.md#for-us-companies).

A line item with no matching Product/Service is left off the Invoice, and if every line item is left off
the Invoice is not pushed at all. This is the most common reason an Invoice fails, so check it before you
sync.

**Step 4. Review the settings**

Open `Contributions` > `Accounts Integration` > `Quickbooks Settings` and check:

1. **Send Email for new Invoices** - set this to `Never` for the initial sync. The default is `Only if
   the invoice is unpaid`, and QuickBooks would then email every customer a new Invoice for each unpaid
   or Pending Contribution in your backlog.
2. **Where should Invoice Numbers be generated?** and **Invoice Prefix** - for example `CiviCRM` and
   `INV_%d`. Keep invoice numbers within 21 characters.
3. **Entities to trigger contact create** and **invoice create** - `Contribution`.
4. **Push Contribution Status** - the statuses you want in QuickBooks. Decide whether you want Pending
   Contributions (unpaid invoices) as well as Completed ones.
5. **Payment processors to trigger skip of invoice create** - any payment processors whose
   Contributions you do not want in QuickBooks, for example if the processor's payouts are already
   reconciled another way.
6. **Allow Online Credit Card Payment** / **Allow ACH Payment** and **Customer Memo Override** - as you
   prefer.

**Step 5. Choose the Day zero date**

Set **Day zero for contributions** to the earliest Contribution date you want in QuickBooks, for example
the start of your current financial year. Contributions before this date are never synced.

**Step 6. Do a small test first (recommended)**

Set Day zero to a recent date so that only a few Contributions will be queued, and carry out steps 7 to
10. When you are happy with the Contacts and Invoices in QuickBooks, move Day zero back to the real
date and repeat steps 7 and 8. Records that have already been synced are not sent again.

**Step 7. Queue the existing Contributions**

Tick **Queue all contributions since Day zero?** and save. Watch the progress screen, and note the
message that tells you how many Contributions and Contacts were queued. Check the remaining list at
`Contributions` > `Accounts Integration` > `Non-Queued Contributions`; contributions that [the queueing rules](configuration.md#account-sync-options-on-the-same-page) skip
may still be listed there.

**Step 8. Match existing QuickBooks customers**

If your QuickBooks company already has Customers who are also CiviCRM Contacts, run the **Civiquickbooks
Contact Pull Job** first. For each queued Contact that has no QuickBooks ID yet, it looks for a
QuickBooks Customer with the same name (first and last name for an Individual, the full name for an
Organization or Household) and links them, so that the Contact is updated rather than a second Customer
being created. If the Customer found is already linked to a different Contact, the second Contact is
marked `do not sync` and listed as an error for you to deduplicate.

Names must match exactly. QuickBooks needs each Customer to have a unique name, so a Contact that has
the same display name as an existing Customer, but was not matched, fails to push with a QuickBooks
duplicate name error. Rename one of them, or merge the duplicate Contacts, and queue the Contact again.

**Step 9. Push Contacts, then Invoices**

Go to `Administer` > `System Settings` > `Scheduled Jobs` and run these jobs in this order, using
`Execute Now` on each:

1. **Civiquickbooks Contact Push Job** - creates the QuickBooks Customers. Run it until the job log
   shows no failures.
2. **Civiquickbooks Invoice Push Job** - creates the Invoices, and records a Payment against each paid
   Invoice. This can only push Invoices for Contacts that already have a QuickBooks ID.

Each record takes about a second, so a large backlog takes a while. If QuickBooks reports that its rate
limit has been reached, the job stops and the extension pauses its API calls for up to an hour (see
[QuickBooks Online API rate limits](usage.md#quickbooks-online-api-rate-limits)); run the job again after that
and it continues with the records that remain.

**Step 10. Check the results**

* Open the job log (`Scheduled Jobs` > `View Job Log`). Each job should finish with `Success`.
* Check `Contributions` > `Accounts Integration` > `Invoice Errors` and `Contact Errors` for anything that
  failed, and see [Troubleshooting](troubleshooting.md#troubleshooting) for what the common errors mean. Once fixed, use
  **Retry sync** on the error list.
* Open a few synced Contacts. The `QuickBooks Sync Status` block on the Contact Summary page shows
  whether the Contact is synced, and any error.
* A job can finish with `Success` even though nothing reached QuickBooks, for example when no
  Product/Service matches your Financial Accounts (step 3). If you see no results, check the mapping
  and the error lists first.
* In QuickBooks, look under **Sales** > **Invoices** and the Customers list. Pushed Invoices do not
  appear under bank transactions. Check some of the Customers and Invoices against CiviCRM. For a paid Contribution,
  the Invoice should show as paid, with a linked Payment whose reference is the transaction ID (cut
  to 21 characters if it is longer).

**Step 11. Finish**

1. If you used a recent Day zero for the test, set it to the real date and repeat steps 7 to 10.
2. Set **Send Email for new Invoices** to what you want for day to day use.
3. Re-enable the four Scheduled Jobs you disabled at the start, and make sure they are enabled and running on your site's cron. From now on, new
   Contributions are queued automatically when they are saved, and the jobs send them.
