CiviCRM Extension for CiviCRM Contribution and Contact synchronization with [QuickBooks Online](http://www.intuit.com.au/).

QuickBooks Online provides different Tax APIs for US and non-US countries. This extension has been developed and tested for QuickBooks Online, Australia. With initial development and testing to support QuickBooks Online, US.

About the Authors
------

This CiviCRM extension was developed by the team at [Agileware](https://agileware.com.au).

[Agileware](https://agileware.com.au) provide a range of CiviCRM services including:
 - CiviCRM migration
 - CiviCRM integration
 - CiviCRM extension development
 - CiviCRM support
 - CiviCRM hosting
 - CiviCRM remote training services.

Support your Australian [CiviCRM](https://civicrm.org) developers, [contact Agileware](https://agileware.com.au/contact) today!

Prerequisites
-------------

  * [CiviCRM](https://www.civicrm.org) 4.7 or greater  

Installation and configuration
-------------

1. Ensure the CiviContribute component is enabled
1. Download this repository(whole extension folder) to your CiviCRM dedicated extension directory (available at 'System Settings / Resource URLs').
1. Download the CiviCRM extension, **[Account Sync](https://github.com/eileenmcnaughton/nz.co.fuzion.accountsync/archive/master.zip)**.
1. In CiviCRM, go to 'System Settings / Extensions' and enable both extensions, **Account Sync** and **QuickBooks Online Integration**.
1. 'QuickBooks' will now be available in the 'Administer' menu. 
1. Click on the 'QuickBooks' menu to display the CiviQuickBooks Settings page.
1. Update the CiviQuickBooks configuration as detailed below.

### Configuration

#### To use this extension, you need to generate a QuickBooks consumer key and secret.

1. Create a [QuickBooks application](https://developer.intuit.com/docs/0100_quickbooks_online/0100_essentials/0085_develop_quickbooks_apps/0000_create_an_app) as a QuickBooks developer.
1. Select Accounting API.
1. The App will be automatically given a pair of development consumer key and secret. This is not used.
1. Use your new-created QuickBooks developer account to generate a [production consumer key and secret](https://developer.intuit.com/docs/0100_quickbooks_online/0100_essentials/0085_develop_quickbooks_apps/0005_use_your_app_with_production_keys).
1. Copy the production consumer key and secret to clipboard.
1. Paste these values to the 'Consumer Key' and 'Shared Secret' fields on CiviQuickBooks settings page.

#### Authorise CiviQuickbooks access to your QuickBooks Online account.

1. Click the 'Authorize' link as shown below the 'Access Token Expiry Date' field.
1. The QuickBooks Authentication page will now be displayed.
1. Follow the instructions and to complete the authentication process. 
1. After authentication, you will be redirected back to CiviCRM.
1. Open the CiviQuickBooks settings page.
1. To confirm QuickBooks authentication a date will be shown in the 'Access Token Expiry Date' field. If no date is shown then authentication has failed. Repeat the process.

#### Map your QuickBooks product/service to CiviCRM Financial account codes

Each QuickBooks Product/Service has a unique name. This is used in the CiviCRM Financial account codes to correctly code each Invoice in QuickBooks.

1. Open the QuickBooks Company, go to product/service settings page ([https://sandbox.qbo.intuit.com/app/items](https://sandbox.qbo.intuit.com/app/items))
1. Identify each QuickBooks Product/Service that you what to sync with CiviCRM
1. Open the CiviCRM Financial Account setting page (civicrm/admin/financial/financialAccount) and update the 'Acctg Code' of corresponding Financial account to be the same as each QuickBooks product/service name.
1. When setting up Contributions in CiviCRM,  ensure that the Financial Type for the Contribution is set to use the correct Financial Account as the Income Account.
1. During sync, the Contribution line item will be set to the corresponding QuickBooks Product/Service.
1. When a CiviCRM 'Acctg Code' does not match any QuickBooks Product/Service name, which means that the there is no product/service in Quickbooks has the same name, that particular line item will **NOT** be pushed through the invoice.

#### Map your QuickBooks tax account name to corresponding CiviCRM financial type's Sales Tax Account's `acctg code`. 

When the extension pushes an invoice to Quickbooks, it requires every item to have a specified Tax account.

#### For AU Companies:

1. Go to `GST` > `Rates&Settings`. There are many tax accounts listed there, with names in column `Tax name`, copy the tax name you want and paste it into the 'Acctg code' field of corresponding Tax financial account.
1. Open the CiviCRM Financial Account setting page (civicrm/admin/financial/financialAccount) and update the 'Acctg Code' of corresponding Financial account to be the same as each QuickBooks tax account name.
1. If a financial type does not contain any GST, a financial account also needs to be created with the corresponding Tax account name in Quickbooks filled out. For example, create a new financial account called `NO GST` with GST rate as `0`, `acctg code`as `GST free` (a tax account name which has GST rate as `0` also). And assign that financial account as corresponding line item's `Sales Tax account`.
1. When setting up Contributions in CiviCRM,  ensure that the Financial Type for the Contribution/line item is set to use the correct Financial Account as the `Sales Tax Account`.
1. During sync, the Contribution line item will be assigned with corresponding Tax account.
1. When a CiviCRM 'Acctg Code' does not match any QuickBooks Tax account name, which means that the there is no tax account in Quickbooks has the same name, that particular line item will **NOT** be pushed through the invoice.

#### For US Companies:

1. For US companies, each line item or product/service in an invoice can only be marked with `NON` (for non-taxable) or `TAX` (taxable), and the entire invoice will have a single tax rate selected as a state tax or a combination tax rate.
1. Users need to make sure that: 
 - In CiviCRM:
    - The financial type of each line item in the contribution is associated with a `sales tax financial account`
      - All those associated financial accounts need to have `TAX` or `NON` as the `acctg code` field.
      - All those associated financial accounts need to have `Tax Rate Name` of desired tax rate account in Quickbooks as the `account type code` field in CiviCRM. e.g. 'California' as the `financial type code`.
      - Make sure that all the financial types of line items have the same value of `account type code` in the `sales tax financial account`. The extension will pick the first line item that is taxable and with a `account type code`. And use that name to get the ID of `tax account` from Quickbooks.
  - In Quickbooks:
      - Make sure there are matched products/services that have the name that is same with the values of the `acctg code` of all `Income financial account`
 of all `financial types` used in the contributions.
      - Make sure all used `tax accounts` have been created and have names recored in the `account type code` of the `sales tax financial account` of matched `financial type`.

#### Special Notes:

1. As line items that have no matched Quickbooks product/service name filled out or no matched Quickbooks tax account name filled out will not be pushed in the invoice, an invoice could have less items pushed. If an invoice does not have even one item in it after the filtering, the invoice will not be pushed successfully.
1. As long as an invoice has at least one item in it after filtering, the information about those none-pushed items will be noted down as `customer memo` field. The `id` of the problematic financial type and its `acctg code` will be listed. In that case, you need to manually fix the invoice manually.
