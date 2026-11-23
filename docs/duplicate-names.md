# Customer names, Vendors and "name already exists" errors

A Contact that fails to push with `The name supplied already exists` (QuickBooks error 6240, `Duplicate
Name Exists Error`) is one of the most common sync problems. This page explains why it happens and what
to do, including the difference between a Customer and a Vendor in QuickBooks.

## What you will see

* On the `Contact Errors` list, and in the Contact's `QuickBooks Sync Status`, the Contact shows as
  `failed to sync with QuickBooks`, with a message that says what already uses the name and what to do.
* On the `Invoice Errors` list, every Contribution of that Contact fails with `Contact N has no
  QuickBooks customer ID`. That is only a knock-on effect. Fix the Contact and the Invoices follow (see
  [After you fix it](#after-you-fix-it)).

## Customers, Vendors and other names in QuickBooks

QuickBooks Online keeps different kinds of people and organisations in separate lists:

| In QuickBooks | Who it is | Used for |
|---|---|---|
| **Customer** | Someone you sell to or bill | Invoices and the Payments received against them |
| **Vendor** | Someone you buy from or pay | Bills and expenses, for example a speaker, supplier or contractor you pay |
| **Employee** | Someone on your payroll | Payroll and expense claims |
| **Other name** | Anyone else | Journal entries and cheques that fit none of the above |

A person or organisation can be in more than one list. A member who also invoices you for speaking
fees really is both a Customer and a Vendor.

**QuickBooks only allows each name to be used once across all of these lists**, and a record that has
been made inactive still holds its name. So a Customer cannot be created if a Vendor, an Employee, an
Other name or another Customer already has the same display name. QuickBooks also cannot turn a Vendor
into a Customer. They are different kinds of record.

This extension always sends a CiviCRM Contact to QuickBooks as a **Customer**, because Contributions
become Invoices, and Invoices can only be raised against Customers. The Customer's display name is the
Contact's CiviCRM display name.

## What the extension does for you

When QuickBooks refuses a new Customer because its name is in use, the Contact Push looks up what holds
the name, including inactive records, and acts on what it finds. Linking an existing Customer is
controlled by the **Link existing QuickBooks customers with the same name?** setting on the
`QuickBooks Online Settings` page. It is on by default, including on existing sites. Everything else in
the table happens whichever way it is set.

| What already has the name | What happens |
|---|---|
| A **Customer**, active or inactive, with the setting on | The extension links the Contact to that Customer. If the Customer was inactive it is made active again. The Customer is then updated from the CiviCRM Contact, as for any synced Contact. The Contact is not an error. The extension records what it did in the CiviCRM log. |
| A **Customer**, active or inactive, with the setting **off** | Nothing is changed. The Contact fails with a message saying a Customer already has the name and that linking is turned off. Turn the setting on, or rename the Customer in QuickBooks, then use `Retry sync`. |
| A **Customer** already linked to a *different* CiviCRM Contact | Nothing is changed. The Contact fails with a message naming the other Contact. Merge the duplicate Contacts, or change the display name of one of them, then use `Retry sync`. |
| A **Customer** that Account Sync holds as an unmatched QuickBooks customer | Nothing is changed. The Contact fails with a message naming that record. Delete the unmatched record, then use `Retry sync`. |
| A **Vendor** or **Employee** | Nothing is changed in QuickBooks. The Contact fails with a message naming the Vendor or Employee and its QuickBooks ID. See [Fixing a Vendor or Employee clash](#fixing-a-vendor-or-employee-clash). |
| Nothing the extension can find | The Contact fails with a message saying so. The name probably belongs to an Other name record, or differs only in spacing or punctuation. See [When no match is found](#when-no-match-is-found). |

Linking is based on the display name matching exactly (QuickBooks ignores upper and lower case). If a
different person really does have the same name as an existing Customer, linking would attach the
Contact to the wrong Customer. If that could happen at your organisation, untick the setting so that
these Contacts are reported instead, or rename one of the two people in advance.

The extension never renames, deactivates or deletes anything in QuickBooks. It only creates
Customers, updates the Customers it syncs, and, with the setting on, reactivates an inactive Customer
when it needs to link to it.

## Fixing a Vendor or Employee clash

A Vendor or Employee cannot be linked to a Contact, and the extension does not rename it because that
record belongs to your accounts payable or payroll. Someone with access to QuickBooks needs to rename
it so the name is free:

1. In QuickBooks, open the record named in the message. The message gives its QuickBooks ID. In the
   address bar, it is the number after `nameId=` when the record is open. Search the Vendors (or
   Employees) list, and use the list's status filter to include inactive records.
2. Edit it and change its **Display name**, for example from `Jane Citizen` to `Jane Citizen
   (Vendor)`. Only the name changes. Bills, payments and history stay attached to the Vendor.
3. Go to [After you fix it](#after-you-fix-it).

If you do not have access to QuickBooks, send the message to whoever does, for example your bookkeeper
or treasurer. The message contains everything they need.

Renaming the Vendor, rather than changing the CiviCRM Contact, is usually best. The Customer then
keeps the Contact's real name, and the Vendor is clearly marked for finance staff.

## When no match is found

If the message says that no Customer, Vendor or Employee with that name was found:

* The name may be used by an **Other name** record. QuickBooks does not allow the extension to look
  those up. Ask your QuickBooks administrator to look in the Other names list and rename the record.
* The name may differ from what you expect only in spacing or punctuation. Compare the Contact's
  display name in CiviCRM with the QuickBooks lists.
* Turn on **Activate QBO logging?** on the QuickBooks Settings page, use `Retry sync` and run the
  Contact Push again. The log, in the CiviCRM log folder, shows the request sent to QuickBooks and its
  response. Turn logging off again afterwards, because the log contains customer data and the
  access token.

## After you fix it

1. On the `Contact Errors` list (or in the Contact's `QuickBooks Sync Status`), use **Retry sync** for
   the Contact. This clears the error and queues the Contact again. A Contact that has failed is
   left out of later Contact Pushes until you do this.
2. Run the **Civiquickbooks Contact Push Job**. The Contact is now created as a Customer, or linked
   to the existing one.
3. Check that the Contact now shows as synced and has no error.
4. Run the **Civiquickbooks Invoice Push Job**. The Invoices that failed with `has no QuickBooks
   customer ID` are still queued and are pushed now.

## Avoiding it

* If some people are both customers and vendors, agree a naming convention with your finance team
  before the first sync. For example, keep the Vendor's name as it is and add `(Vendor)` to the
  Customer's, or the other way round, and apply it to the Vendor in QuickBooks.
* Before the [initial sync](initial-sync.md), look through the QuickBooks Customers, Vendors and
  Employees lists for people who are also Contacts in CiviCRM.
* Run the **Civiquickbooks Contact Pull Job** before the first Contact Push, so that existing
  Customers are matched first (see [Step 8 of the initial sync](initial-sync.md)).
