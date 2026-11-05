# Troubleshooting

Errors are shown in the Scheduled Job log, on the lists at `Contributions` > `Accounts Integration` >
`Invoice Errors` / `Contact Errors`, and on the Contact Summary page.

| Message | Meaning and what to do |
|---|---|
| `Contact N has no QuickBooks customer ID` | The Contact has not been pushed to QuickBooks yet, or its push failed. Run the Contact Push job, check that Contact for errors, then run the Invoice Push job again. |
| `No matching QBOItem ... Accounting Code: X` | No QuickBooks Product/Service is named exactly `X`. Fix the `Acctg Code` of the Financial Type's Income Account, or the QuickBooks name. See [Map your QuickBooks product/service](financial-account-mapping.md#map-your-quickbooks-productservice-to-civicrm-financial-account-codes). |
| `No valid LineItems in Contribution to push` | Every line item was left off because of a Product/Service mismatch. See the previous entry. |
| `Business Validation Error: You cannot enter more than 21 characters in the doc_num field` | A reference or Invoice number sent to QuickBooks is too long. Payment references are cut to 21 characters automatically; if this is about the Invoice number, shorten the Invoice Prefix. |
| `Invoice created in QuickBooks but pushing its payments failed` | The Invoice exists in QuickBooks but a Payment did not go through. The Invoice stays queued, and the next Invoice Push retries only the Payments not yet sent. The rest of the message says why. |
| The `Quickbooks Settings` page does not appear in the `Contributions` menu after installing | This was seen once on a new install, probably because the menu item could not be attached to Account Sync's `Accounts Integration` menu while both extensions were being enabled together (the item now sits directly under `Contributions` for that reason, and in earlier versions it was in `Accounts Integration`). Clear the CiviCRM caches (`Administer` > `System Settings` > `Cleanup Caches and Updates`) and reload, and check that both **Account Sync** and **QuickBooks Online Integration** are enabled. If the menu item is still missing, the page can still be opened by its address, `civicrm/admin/setting/quickbooks`, if that works on your site (see [Installation](installation.md)). Otherwise [contact Agileware](https://agileware.com.au/contact), because it is likely to need a fix. |
| `Invalid authorization code for access` when you click `Authorize` | The Client ID or Client Secret on the settings page is wrong or incomplete. Copy both Production keys again from the Intuit Developer site, submit, and authorise again. |
| `Authentication failure` or a System Status message about the refresh token | The QuickBooks authorisation has expired or been revoked. Click `Authorize` again on the settings page. |
| `QBO API rate limit exceeded` | QuickBooks is limiting requests. Wait, then run the job again; it continues where it stopped. |
| A Contact is marked `do not sync` | It failed to push more than 3 times. Fix the cause, then use `Retry sync` on the Contact Errors list or the link on the Contact Summary page. |
| `Matches QBO Contact ..., which is already synced to ... Deduplication is required.` | Two CiviCRM Contacts match the same QuickBooks Customer. Merge or rename the Contacts. |

Turning on **Activate QBO logging?** records the requests and responses to the CiviCRM log directory,
including the `intuit_tid` for each response, which helps when asking Intuit for support. Turn it off
again when you are done, as the logs contain customer data.

## Special notes

1. A line item whose Financial Type has no matching QuickBooks Product/Service
   name (via `Acctg Code`, see [Mapping financial accounts](financial-account-mapping.md)) is excluded from the invoice, so an
   invoice can be pushed with fewer line items than the Contribution actually
   has. If every line item is excluded this way, the invoice will not be
   pushed at all, and the Financial Type(s)/Accounting Code(s) that failed to
   match are recorded as a sync error against that Contribution, viewable on
   the Contact's summary page.
2. A line item whose Financial Type has no matching QuickBooks Sales Tax
   Account name (non-US companies only, see [Mapping financial accounts](financial-account-mapping.md#for-au-companies)) is still pushed, but
   without a Tax Code applied - there is currently no notification for this
   case, so review your Financial Account `Acctg Code` mappings carefully to
   avoid un-taxed invoice lines in QuickBooks.
3. Contributions and Contacts are queued by Account Sync, not by this extension. If something is not
   being sent, check first that it has been queued (see [How it works](how-it-works.md#how-it-works)).
4. Only CiviCRM Contributions are synchronised. Orders taken in another system, such as a separate
   online shop, are not Contributions and are not sent by this extension. They need their own
   QuickBooks connector.
