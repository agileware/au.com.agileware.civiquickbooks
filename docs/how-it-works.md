# How it works

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
   automatically. The [initial sync](initial-sync.md) includes a one-click option to
   queue them.
2. A Contact is pushed to QuickBooks Online as a Customer, and a Contribution as an Invoice (with a
   Payment recorded against it if the Contribution has been paid). The Customer must exist in
   QuickBooks before its Invoice can be pushed, so Contacts are always pushed first.

## What is sent to QuickBooks

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
