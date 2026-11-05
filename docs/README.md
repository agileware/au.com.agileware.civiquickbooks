# QuickBooks Online Integration for CiviCRM

**Stop re-keying your CiviCRM money into your accounts. Let it flow.**

CiviQuickBooks connects [CiviCRM](https://civicrm.org) to [QuickBooks Online](https://www.intuit.com.au/)
so that the donations, membership dues and event fees you record in CiviCRM appear in QuickBooks as
properly coded Invoices and Payments, and the status of those Invoices flows back into CiviCRM.
Your fundraisers keep working in CiviCRM. Your finance team keeps working in QuickBooks. Nobody
copies numbers between the two.

## What it makes possible

* **Your books stay in step with your database.** A Contribution saved in CiviCRM becomes a
  QuickBooks Invoice, and a Contact becomes a QuickBooks Customer, without anyone touching a
  spreadsheet.
* **Your members and donors see the truth.** When an Invoice is paid, voided or cancelled in
  QuickBooks, the matching Contribution in CiviCRM updates to Completed or Cancelled, so staff do
  not have to ask finance what has been paid.
* **Your finance team gets clean, coded data.** Each line item lands on the QuickBooks
  Product/Service and tax code you chose, so income is reported the way your accountant expects.
* **Your backlog is not stranded.** Bringing years of existing Contributions across is a single
  tick box and a progress bar, not a data-migration project.

## Highlights

* **Two-way sync.** Contacts and Contributions are pushed to QuickBooks. Customers and Invoice
  statuses are pulled back.
* **Contacts to Customers, Contributions to Invoices.** Paid Contributions get a Payment recorded
  against the Invoice too, with the transaction ID as the reference.
* **Matches existing Customers.** If a QuickBooks Customer already has the same name as a CiviCRM
  Contact, they are linked rather than duplicated.
* **Account-code driven.** The `Acctg Code` on your CiviCRM Financial Accounts decides which
  QuickBooks Product/Service and tax code each line item uses.
* **Works in four QuickBooks regions.** Developed and tested with QuickBooks Online Australia,
  United States, United Kingdom and Canada. The company's country is detected automatically.
* **Production or sandbox.** Rehearse against a QuickBooks Development company, then flip one
  setting to go live.
* **Runs unattended.** Four Scheduled Jobs do the work on your site's cron, and the extension backs
  off by itself if QuickBooks rate limits are reached.
* **Visible and recoverable.** Each Contact shows its sync status and any error from QuickBooks.
  Failures are listed in one place and can be retried with a click.
* **Modern authentication.** OAuth 2.0, with a CiviCRM System Status warning when the connection
  needs to be re-authorised.

## Make it yours

Most behaviour is a setting on the QuickBooks Online Settings page, not a code change.

* **What gets synced.** Choose the Contribution statuses to push (for example Completed and Pending),
  skip whole payment processors, and set a "Day zero" so older records are never sent.
* **How Invoices look.** Generate invoice numbers in CiviCRM with your own prefix (`INV_%d`,
  `Civi-%07d`) or let QuickBooks number them. Set a Customer Memo, and allow online card or ACH
  payment from the QuickBooks Invoice.
* **Who gets emailed.** Have QuickBooks email customers never, only for unpaid Invoices, or always.
* **How taxes are applied.** Map your own GST or sales tax codes onto CiviCRM Financial Accounts.
* **When it runs.** Run each Scheduled Job as often as suits you, or run it by hand.

## Real-world uses

* **A membership organisation** invoices annual dues through CiviCRM. Each renewal appears in
  QuickBooks as an Invoice coded to the right income account. When the treasurer receives payment
  there, the membership's Contribution is marked Completed in CiviCRM.
* **A charity taking online donations** has every donation, from every payment processor except the
  ones it chooses to skip, arrive in QuickBooks with its Payment recorded and the processor's
  transaction ID as the reference, ready for reconciliation.
* **An association running paid events** codes registrations to different QuickBooks Products/Services
  using different Financial Types, with the right tax treatment on each line.
* **A team moving onto CiviCRM** with years of history queues all Contributions since a chosen date
  in one step, and rehearses first against a QuickBooks sandbox company.
* **A bookkeeper** gets a clear list of anything that failed to sync, and why, instead of
  discovering gaps at month end.

## How it works, in brief

The [Account Sync](https://github.com/eileenmcnaughton/nz.co.fuzion.accountsync) extension notices
when a Contribution is saved and queues it, together with its Contact. CiviQuickBooks then sends
queued Contacts first, then Invoices (and Payments), and reads changes back from QuickBooks. Nothing
is sent until it has been queued, which is why the [initial sync guide](initial-sync.md) includes a
step to queue existing Contributions. See [How it works](how-it-works.md) for the details.

## Quick start

1. Install **Account Sync** and **QuickBooks Online Integration**. See [Installation](installation.md).
2. Create an Intuit app, enter its keys and click **Authorize**. See [Configuration](configuration.md).
3. Map your Financial Accounts to QuickBooks Products/Services and tax codes. See
   [Mapping financial accounts](financial-account-mapping.md).
4. Follow the [initial sync guide](initial-sync.md) to bring your existing Contributions across
   safely, trying a few records first.

## Requirements

* CiviCRM 6.11 or later
* The [Account Sync](https://github.com/eileenmcnaughton/nz.co.fuzion.accountsync) extension
* A QuickBooks Online company (AU, US, UK or CA) and an Intuit Developer account
* CiviCRM Scheduled Jobs (cron) running on your site

CiviQuickBooks version 2 uses OAuth 2.0. If you are upgrading from version 1 you need to generate
new keys, as described in [Configuration](configuration.md).

## Documentation

| Page | What it covers |
|---|---|
| [How it works](how-it-works.md) | The queueing model, and exactly what is sent to QuickBooks for each Contact, Contribution and Payment |
| [Installation](installation.md) | Requirements and installing the extension |
| [Configuration](configuration.md) | Connecting to QuickBooks, Production vs Development, all settings, and queueing existing Contributions |
| [Mapping financial accounts](financial-account-mapping.md) | Matching Financial Accounts to QuickBooks Products/Services and tax codes (AU and US) |
| [App assessment questionnaire](app-assessment-questionnaire.md) | Suggested answers for Intuit's app assessment when going to Production |
| [Initial sync: step by step](initial-sync.md) | A safe, ordered walkthrough from fresh install to your first synced Invoices |
| [Usage](usage.md) | Scheduled Jobs, the sync status on the Contact page, System Status checks and API rate limits |
| [Troubleshooting](troubleshooting.md) | What common error messages mean, and special notes on mapping behaviour |
| [Changes in this version](changelog.md) | What changed in the current release |
| [Contributing](https://github.com/agileware/au.com.agileware.civiquickbooks/blob/master/CONTRIBUTING.md) | Versioning and release process |

## Support and licence

The extension is licensed under the terms in
[LICENSE.txt](https://github.com/agileware/au.com.agileware.civiquickbooks/blob/master/LICENSE.txt).
For help, see the [Troubleshooting](troubleshooting.md) page, or
[contact Agileware](https://agileware.com.au/contact).

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
