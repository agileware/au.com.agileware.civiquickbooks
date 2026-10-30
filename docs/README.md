# QuickBooks Online Integration (au.com.agileware.civiquickbooks)

This is a [CiviCRM](https://civicrm.org) extension which integrates CiviCRM with [QuickBooks
Online](https://www.intuit.com.au/). CiviCRM Contacts and Contributions are pushed to your
QuickBooks Online account, QuickBooks Invoices are pulled into CiviCRM, updating the Contribution
status. Synchronise all things!

Integration of the two systems saves you time by not having to do repetitive data entry in your
accounting system and CiviCRM. This extension does the work for you!

QuickBooks Online provides different Tax APIs for US and non-US countries. This extension has been
developed and tested with:

1. QuickBooks Online, Australia (AU)
2. QuickBooks Online, United States (USA)
3. QuickBooks Online, United Kingdom (UK)
4. QuickBooks Online, Canada (CA)

CiviQuickBooks version 2 implements the OAuth 2.0 protocol for QuickBooks Online - if you are
upgrading from a previous version you will need to generate new keys. See the
[Configuration](#configuration) section below.

The extension is licensed under the terms in [LICENSE.txt](https://github.com/agileware/au.com.agileware.civiquickbooks/blob/master/LICENSE.txt).

## Contents

* [How it works](#how-it-works)
* [Requirements](#requirements)
* [Installation](#installation)
* [Configuration](#configuration)
* [Initial sync: step by step](#initial-sync-step-by-step)
* [Usage](#usage)
* [Troubleshooting](#troubleshooting)
* [Special notes](#special-notes)

## How it works

The sync is made of two parts, and it helps to know which does what:

* **[Account Sync](https://github.com/eileenmcnaughton/nz.co.fuzion.accountsync)** decides *what* is
  to be synchronised. When a Contribution is created or changed, Account Sync records it, and its
  Contact, as "queued" for QuickBooks (internally, a row in the `civicrm_account_invoice` and
  `civicrm_account_contact` tables flagged as needing an update).
* **This extension** does the synchronising. Four Scheduled Jobs send the queued Contacts and
  Contributions to QuickBooks Online, and read changes back.

Two things follow from this:

1. Nothing is sent to QuickBooks until it has been **queued**. Account Sync only queues a Contribution
   when it is saved, so Contributions that already existed before you set up the sync are not queued
   automatically. The [initial sync](#initial-sync-step-by-step) below includes a one-click option to
   queue them.
2. A Contact is pushed to QuickBooks Online as a Customer, and a Contribution as an Invoice (with a
   Payment recorded against it if the Contribution has been paid). The Customer must exist in
   QuickBooks before its Invoice can be pushed, so Contacts are always pushed first.

## Requirements

* CiviCRM 6.11+
* The [Account Sync](https://github.com/eileenmcnaughton/nz.co.fuzion.accountsync) extension,
  declared as a dependency in `info.xml` and required for CiviQuickBooks to function

## Installation

1. Ensure the CiviContribute component is enabled
2. Download the [latest version of this
   extension](https://github.com/agileware/au.com.agileware.civiquickbooks/releases)
3. Unzip in the CiviCRM extension directory, as defined in `System Settings /
   Directories`.
4. Download the CiviCRM extension, **[Account
   Sync](https://github.com/eileenmcnaughton/nz.co.fuzion.accountsync/archive/master.tar.gz)**.
5. In CiviCRM, go to `System Settings / Extensions` and enable both extensions,
   **Account Sync** and **QuickBooks Online Integration**.
6. An `Accounts Integration` menu will now be available under the `Contributions` menu. It contains
   `Quickbooks Settings`, the sync error lists and the other Account Sync pages.
7. Click `Quickbooks Settings` to display the `QuickBooks Online Settings` page. Its address is
   `civicrm/admin/setting/quickbooks`, for example:
    * WordPress: `/wp-admin/admin.php?page=CiviCRM&q=civicrm/admin/setting/quickbooks&reset=1`
    * Drupal: `/civicrm/admin/setting/quickbooks?reset=1`
8. Update the CiviQuickBooks Online configuration as detailed below.

## Configuration

### To use this extension, you need to generate a QuickBooks Online consumer key and secret.

1. You will need an Intuit Developer Account, sign up at
   (https://developer.intuit.com/). This account must be added as a user of `Admin` user type to the
   QuickBooks Online company being integrated.
2. Once your developer account is created and linked, Go to the `My Apps`
   section and select `Create new app`.
3. Use the `Select APIs` button in the `Just start coding` card, then select the
   Accounting API.
4. From the App dashboard, select the `OAuth 2.0 keys`
5. In the `Production Keys` section, copy the `Client ID` and `Client Secret`
   keys.
6. Paste these values into the CiviQuickBooks Online Settings page and submit.
7. Copy the Redirect URI that is present into the `Production Keys` section of
   your App in the Intuit Developer site.

CiviQuickBooks Online Settings page is shown below.

![](https://github.com/agileware/au.com.agileware.civiquickbooks/raw/master/docs/img/civiquickbooks-settings.png)

**Note**: changing the Client ID or Client Secret on this page clears any stored access/refresh
tokens, so you will need to re-authorise (see below) after doing so.

#### Authorise CiviQuickBooks access to your QuickBooks Online account.

1. Click the `Authorize` link as shown below the `Access Token Expiry Date`
   field.
2. The QuickBooks Online Authentication page will now be displayed.
3. Follow the instructions and to complete the authentication process.
4. After authentication, you will be redirected back to CiviCRM.
5. Open the `QuickBooks Online Settings` page.
6. To confirm QuickBooks Online authentication a date will be shown in the
   `Access Token Expiry Date` field. If no date is shown then authentication has
   failed. Repeat the process.

Once authorised, the extension records which QuickBooks Online company country (AU, US, UK, CA,
etc.) your connected company uses; this is detected automatically from QuickBooks and is not a
setting you configure yourself.

### Map your QuickBooks product/service to CiviCRM Financial account codes

Each QuickBooks Product/Service has a unique name. This is used in the CiviCRM
Financial Account codes to correctly code each Invoice in QuickBooks Online.

1. Open the QuickBooks Company, go to product/service settings page
   ([https://sandbox.qbo.intuit.com/app/items](https://sandbox.qbo.intuit.com/app/items)).
2. Identify each QuickBooks Product/Service that you want to sync with CiviCRM.
3. Open the CiviCRM Financial Account setting page
   (civicrm/admin/financial/financialAccount) and update the `Acctg Code` of
   corresponding Financial account to be the same as each QuickBooks
   Product/Service name:
   ![Product Name -> Acctg Code](https://github.com/agileware/au.com.agileware.civiquickbooks/raw/master/docs/img/product_name-to-acctg_code.png)
4. When setting up Contributions in CiviCRM, ensure that the Financial Type for
   the Contribution is set to use the correct Financial Account as the Income
   Account.
5. During sync, the Contribution line item will be set to the corresponding
   QuickBooks Product/Service.
6. When a CiviCRM `Acctg Code` does not match any QuickBooks Product/Service
   name, which means that no product/service in QuickBooks has the
   same name, that particular line item will **NOT** be pushed through the
   invoice.

The match is exact: it is case sensitive and punctuation such as brackets counts, so the Product/Service
`Member Dues (4400)` needs the Acctg Code `Member Dues (4400)`. Either the Product/Service Name or its
full `Parent:Child` name (for sub-items) can be used. The list of Products/Services is cached for up to 7
days, so a Product/Service you have just added or renamed in QuickBooks may not be noticed straight
away. Clearing the CiviCRM caches may refresh it sooner.

### Map your QuickBooks tax account name to corresponding CiviCRM financial type`s Sales Tax Account`s `acctg code`. 

When the extension pushes an invoice to QuickBooks Online, it requires every
item to have a specified Tax account.

#### For AU Companies:

1. Go to `GST` > `Rates&Settings`. There are many tax accounts listed there,
   with names in column `Tax name`, copy the tax name you want and paste it into
   the `Acctg code` field of corresponding Tax financial account.
2. Open the CiviCRM Financial Account setting page
   (civicrm/admin/financial/financialAccount) and update the `Acctg Code` of
   corresponding Financial account to be the same as each QuickBooks tax account
   name.
3. If a financial type does not contain any GST, a financial account also needs
   to be created with the corresponding Tax account name in QuickBooks filled
   out. For example, create a new financial account called `NO GST` with GST
   rate as `0`, `acctg code`as `GST free` (a tax account name which has GST rate
   as `0` also). And assign that financial account as corresponding line item`s
   `Sales Tax account`.
4. When setting up Contributions in CiviCRM, ensure that the Financial Type for
   the Contribution/line item is set to use the correct Financial Account as the
   `Sales Tax Account`.
5. During sync, the Contribution line item will be assigned with corresponding
   Tax account.
6. When a CiviCRM `Acctg Code` does not match any QuickBooks Tax account name,
   which means that the there is no tax account in QuickBooks has the same name,
   that particular line item will **NOT** be pushed through the invoice.

#### For US Companies:

If none of your Contributions are taxable, you can skip this section entirely -
whenever a financial type has no `sales tax financial account` configured, the
extension automatically sends a `NON` (non-taxable) tax code for that line item,
which already satisfies QuickBooks Online's requirement that every item have a
tax code.

1. For US companies, each line item or product/service in an invoice can only be
   marked with `NON` (for non-taxable) or `TAX` (taxable), and the entire
   invoice will have a single tax rate selected as a state tax or a combination
   tax rate.
2. Users need to make sure that:
 - In CiviCRM:
    - The financial type of each line item in the contribution is associated
      with a `sales tax financial account`
      - All those associated financial accounts need to have `TAX` or `NON` as
        the `acctg code` field.
      - All those associated financial accounts need to have the name of the
        desired QuickBooks `TaxCode` (not to be confused with a `Tax Rate`) as
        the `account type code` field in CiviCRM. e.g. `California`. For US
        companies using QuickBooks Online's Automated Sales Tax, this is
        usually the name of the state, and is generated automatically by
        QuickBooks rather than being listed anywhere for you to copy from -
        the QuickBooks API can be queried to confirm the exact name.
      - Make sure that all the financial types of line items have the same value
        of `account type code` in the `sales tax financial account`. The
        extension will pick the first line item that is taxable and with a
        `account type code`. And use that name to get the ID of `tax account`
        from QuickBooks Online.
  - In QuickBooks Online:
      - Make sure there are matched products/services that have the name that is
 same with the values of the `acctg code` of all `Income financial account` of
 all `financial types` used in the contributions.
      - Make sure all used `tax accounts` have been created and have names
        recored in the `account type code` of the `sales tax financial account`
        of matched `financial type`.

### Are you using a QuickBooks Online Production or Development account?

This extension currently assumes that it will be connected to a QuickBooks Online, `Production` account.
If instead, you wish to connect this to a QuickBooks Online `Development` account then open the `QuickBooks Online Settings` page and change the `Environment` option from `Production` to `Development`.
When you have finished testing in the Development account, then change the `Environment` option back to `Production`.

For more details on the QuickBooks Online environments, see:
* https://developer.intuit.com/app/developer/qbo/docs/develop/sandboxes
* https://developer.intuit.com/app/developer/qbo/docs/develop/sdks-and-samples-collections/php/configuration

### Other settings

The `QuickBooks Online Settings` page also provides the following options:

* **Send Email for new Invoices** - whether QuickBooks Online should email the customer when a new
  Invoice is created: `Never`, `Only if the invoice is unpaid` (the default), or `Always`. See the
  warning in the [initial sync](#initial-sync-step-by-step) before pushing existing Contributions.
* **Where should Invoice Numbers be generated?** - `CiviCRM` (the default; the Invoice's `DocNumber`
  is set from the Contribution ID using the Invoice Prefix below) or `QuickBooks` (QuickBooks Online
  assigns the number automatically).
* **Invoice Prefix** - the `sprintf()` format used to generate the Invoice `DocNumber` when Invoice
  Numbers are generated by CiviCRM, e.g. the default `Civi-%07d` produces `Civi-0001234` for
  Contribution ID 1234, and `INV_%d` produces `INV_1234`. QuickBooks limits an Invoice number to 21
  characters, so keep the prefix short.
* **Allow Online Credit Card Payment** / **Allow ACH Payment** - whether the pushed Invoice permits
  the customer to pay online via credit card / ACH from within QuickBooks Online.
* **Customer Memo Override** - optional text to use as the Invoice's Customer Memo instead of the
  Contribution's Source. If this is empty, the Contribution's Source is used as the memo, and if the
  Contribution has no Source the Invoice is pushed without a memo.
* **Activate QBO logging?** - enables additional logging of QuickBooks Online API activity, for
  troubleshooting. The log includes each response's `intuit_tid`, which Intuit support can use to
  trace a request.

#### Account Sync options on the same page

The settings page also shows the Account Sync options that control what is queued:

* **Entities to trigger contact Create in the Remote System** and **Entities to trigger invoice
  create** - leave both as `Contribution` so that a Contact and its Invoice are queued when a
  Contribution is saved.
* **Entities to trigger contact update** - the Contact, Email, Address and Phone changes that
  re-queue a Contact so its QuickBooks Customer is updated.
* **Payment processors to trigger skip of invoice create** - Contributions paid through these
  payment processors are never queued.
* **Day zero for contributions** - Contributions received before this date are never synced.
* **Queue all contributions since Day zero?** - see [below](#queue-existing-contributions).
* **Send receipts for Contributions** - sets the CiviCRM `Send receipt?` option for all Contributions
  that are synced.
* **Push Contribution Status** - only Contributions with these statuses (by default Completed and
  Pending) are pushed.

A Contribution is also never queued if it is a test, has a zero amount, or is a Pending Contribution
without the Pay Later flag (an incomplete online transaction).

#### Queue existing contributions

Account Sync only queues a Contribution when it is saved, so Contributions that existed before you set
up the sync would never be sent to QuickBooks. To queue them, tick **Queue all contributions since Day
zero?** and save the settings page.

* Every Contribution received on or after the Day zero date is queued, along with its Contact, if it
  meets the rules above and is not on the payment processor skip list.
* Contributions and Contacts that are already queued or synced are left alone, so it is safe to run
  more than once.
* The work is done in batches of 25 Contributions, with a progress screen, so a large number of
  Contributions is not a problem. When it finishes you are returned to the settings page with a message
  saying how many Contributions and Contacts were queued.
* The tick box is an action rather than a setting: it is cleared as soon as the work starts.
* Queueing only prepares the records. They are sent to QuickBooks when the Contact Push and Invoice
  Push jobs run (see the [initial sync](#initial-sync-step-by-step)).
* The Day zero date is not checked for being sensible. The default, `1990-01-01`, would queue every
  Contribution you have ever recorded, so check it first.

You can see the Contributions that are not yet queued at `Contributions` > `Accounts Integration` >
`Non-Queued Contributions`.

## Initial sync: step by step

Follow these steps, in order, the first time you connect CiviCRM to a QuickBooks Online company. They
use the settings described above and take you from a fresh install to Contacts and Invoices in
QuickBooks.

**Before you start**

* Take a backup of your CiviCRM database.
* Decide whether you are connecting to QuickBooks `Production` or a `Development` (sandbox) company. If
  this is your first time, trying it on a Development company first is a good idea.
* Make sure CiviCRM's Scheduled Jobs (cron) run on your site, or be ready to run the jobs by hand in
  step 9.
* Everything that is pushed becomes real accounting data. A Contribution is pushed as an Invoice, and
  as a Payment if it has been paid. Records that have been sent are not sent again, and they are not removed from
  QuickBooks if you change your mind, so test with a few records first (step 6).

**Step 1. Install and enable**

Install and enable **Account Sync** and **QuickBooks Online Integration** as described in
[Installation](#installation).

**Step 2. Connect to QuickBooks**

Create the Intuit app, enter the Client ID and Client Secret, add the Redirect URI to the Intuit app,
and click **Authorize**, as described in [Configuration](#configuration). Confirm that the `Access
Token Expiry Date` shows a date.

**Step 3. Map Products/Services and tax codes**

For every Financial Type you use:

1. Set the **Income Account** Financial Account's `Acctg Code` to the exact name of the QuickBooks
   Product/Service.
2. Set the **Sales Tax Account** Financial Account's `Acctg Code` to the QuickBooks tax code name (AU:
   for example `GST` or `GST free`). US companies, see [For US Companies](#for-us-companies).

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
`Contributions` > `Accounts Integration` > `Non-Queued Contributions`; contributions the rules above skip
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
[QuickBooks Online API rate limits](#quickbooks-online-api-rate-limits)); run the job again after that
and it continues with the records that remain.

**Step 10. Check the results**

* Open the job log (`Scheduled Jobs` > `View Job Log`). Each job should finish with `Success`.
* Check `Contributions` > `Accounts Integration` > `Invoice Errors` and `Contact Errors` for anything that
  failed, and see [Troubleshooting](#troubleshooting) for what the common errors mean. Once fixed, use
  **Retry sync** on the error list.
* Open a few synced Contacts. The `QuickBooks Sync Status` block on the Contact Summary page shows
  whether the Contact is synced, and any error.
* In QuickBooks, check some of the Customers and Invoices against CiviCRM. For a paid Contribution,
  the Invoice should show as paid, with a linked Payment whose reference is the transaction ID (cut
  to 21 characters if it is longer).

**Step 11. Finish**

1. If you used a recent Day zero for the test, set it to the real date and repeat steps 7 to 10.
2. Set **Send Email for new Invoices** to what you want for day to day use.
3. Make sure all four Scheduled Jobs are enabled and running on your site's cron. From now on, new
   Contributions are queued automatically when they are saved, and the jobs send them.

## Usage

### Scheduled Jobs

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

### What is sent to QuickBooks

**Customer (from a Contact)**

* Name, company name and title are taken from the Contact.
* The email, phone and address sent are chosen in this order: one on a `Billing` location, then one
  flagged as the billing one, then the primary one, then any. The phone is not chosen by its Phone
  Type, so it does not matter which Phone Types your site has defined, renamed or removed.

**Invoice (from a Contribution)**

* Each line item becomes an Invoice line, using the Product/Service and tax code that match its
  Financial Type.
* The Invoice number is generated from the Invoice Prefix and Contribution ID, or left to QuickBooks,
  depending on the setting.
* The Customer Memo is the Customer Memo Override if there is one, otherwise the Contribution's Source.
  If neither has a value the Invoice has no memo.
* An Invoice is only pushed for a Contact that already has a QuickBooks ID. If it does not, the push
  fails with a message naming the Contact, and the Invoice is sent after the next successful Contact
  Push. Cancelling an Invoice that is already in QuickBooks does not need this.

**Payment (from a completed Payment on a Contribution)**

* A Payment is recorded in QuickBooks against the Invoice, using the payment method that matches the
  CiviCRM payment instrument if there is one.
* The reference number is the transaction ID, or the cheque number if there is no transaction ID.
  QuickBooks allows at most 21 characters, so a longer value, such as a 27 character Stripe charge ID,
  is cut to its first 21 characters.
* If the Payment cannot be pushed after the Invoice has been created, the Contribution is kept queued
  and the error is recorded. On the next Invoice Push the Invoice is updated as usual and only the
  Payments that were not sent are pushed, so no Payment is sent twice. The Invoice email, if one is
  due, is sent once the Payments succeed.

### Sync status on the Contact page

A Contact who has been synchronised, or is queued to be synchronised, with QuickBooks Online shows
a "QuickBooks Sync Status" summary on their Contact Summary page, along with a link to manually
queue the Contact to be re-synchronised. If the Contact or any of their Contributions have a sync
error, a "Contact Sync Errors" and/or "Invoice Sync Errors" indicator is also shown; clicking it
shows the underlying error message(s) reported by QuickBooks Online.

Contacts whose push to QuickBooks Online fails are tagged with a reserved "Quickbooks Sync Error"
tag, which is automatically removed once the Contact syncs successfully. A Contact that fails to
push more than 3 times in a row is automatically marked as excluded from future syncing (`do not
sync`) until an administrator investigates and re-queues it.

### System-wide status check

A CiviCRM System Status message is shown (`Administer` > `System Settings` > `System Status`,
also visible on the CiviCRM home/dashboard) when the stored QuickBooks Online refresh token has
expired, prompting an administrator to re-authorise the connection via the `QuickBooks Online
Settings` page.

### QuickBooks Online API rate limits

If QuickBooks Online reports that its API rate limit has been exceeded, the extension records the
time this occurred and blocks further CiviQuickBooks Push/Pull API calls for up to an hour, to
avoid repeatedly hitting the limit. Invoice pushes are also throttled to roughly one request per
second to reduce the likelihood of hitting QuickBooks Online's rate limits in the first place.

## Troubleshooting

Errors are shown in the Scheduled Job log, on the lists at `Contributions` > `Accounts Integration` >
`Invoice Errors` / `Contact Errors`, and on the Contact Summary page.

| Message | Meaning and what to do |
|---|---|
| `Contact N has no QuickBooks customer ID` | The Contact has not been pushed to QuickBooks yet, or its push failed. Run the Contact Push job, check that Contact for errors, then run the Invoice Push job again. |
| `No matching QBOItem ... Accounting Code: X` | No QuickBooks Product/Service is named exactly `X`. Fix the `Acctg Code` of the Financial Type's Income Account, or the QuickBooks name. See [Map your QuickBooks product/service](#map-your-quickbooks-productservice-to-civicrm-financial-account-codes). |
| `No valid LineItems in Contribution to push` | Every line item was left off because of a Product/Service mismatch. See the entry above. |
| `Business Validation Error: You cannot enter more than 21 characters in the doc_num field` | A reference or Invoice number sent to QuickBooks is too long. Payment references are cut to 21 characters automatically; if this is about the Invoice number, shorten the Invoice Prefix. |
| `Invoice created in QuickBooks but pushing its payments failed` | The Invoice exists in QuickBooks but a Payment did not go through. The Invoice stays queued, and the next Invoice Push retries only the Payments not yet sent. The rest of the message says why. |
| `Authentication failure` or a System Status message about the refresh token | The QuickBooks authorisation has expired or been revoked. Click `Authorize` again on the settings page. |
| `QBO API rate limit exceeded` | QuickBooks is limiting requests. Wait, then run the job again; it continues where it stopped. |
| A Contact is marked `do not sync` | It failed to push more than 3 times. Fix the cause, then use `Retry sync` on the Contact Errors list or the link on the Contact Summary page. |
| `Matches QBO Contact ..., which is already synced to ... Deduplication is required.` | Two CiviCRM Contacts match the same QuickBooks Customer. Merge or rename the Contacts. |

Turning on **Activate QBO logging?** records the requests and responses to the CiviCRM log directory,
including the `intuit_tid` for each response, which helps when asking Intuit for support. Turn it off
again when you are done, as the logs contain customer data.

## Special notes

1. A line item whose Financial Type has no matching QuickBooks Product/Service
   name (via `Acctg Code`, see above) is excluded from the invoice, so an
   invoice can be pushed with fewer line items than the Contribution actually
   has. If every line item is excluded this way, the invoice will not be
   pushed at all, and the Financial Type(s)/Accounting Code(s) that failed to
   match are recorded as a sync error against that Contribution, viewable on
   the Contact's summary page.
2. A line item whose Financial Type has no matching QuickBooks Sales Tax
   Account name (non-US companies only, see above) is still pushed, but
   without a Tax Code applied - there is currently no notification for this
   case, so review your Financial Account `Acctg Code` mappings carefully to
   avoid un-taxed invoice lines in QuickBooks.
3. Contributions and Contacts are queued by Account Sync, not by this extension. If something is not
   being sent, check first that it has been queued (see [How it works](#how-it-works)).

## Changes in this version

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

# Funding for this CiviCRM extension

Development and support for this Extension is funded solely by Agileware and CiviCRM community contributions. Agileware does not receive any funding at all for this Extension from the CiviCRM Partner program or CiviCRM LLC. Agileware welcome any funding to help continue paying Agileware staff to continue contributing to the CiviCRM community and Extensions.

[:heart: Donate to Agileware](https://www.paypal.me/agileware) to tell us how much you appreciate Agileware's CiviCRM open source work and community contributions.

# About the Authors

This CiviCRM extension was developed by the team at
[Agileware](https://agileware.com.au).

[Agileware](https://agileware.com.au) provide a range of CiviCRM services
including:

  * CiviCRM migration
  * CiviCRM integration
  * CiviCRM extension development
  * CiviCRM support
  * CiviCRM hosting
  * CiviCRM remote training services

Support your Australian [CiviCRM](https://civicrm.org) developers, [contact
Agileware](https://agileware.com.au/contact) today!


![Agileware](https://github.com/agileware/au.com.agileware.civiquickbooks/raw/master/docs/logo/agileware-logo.png)
