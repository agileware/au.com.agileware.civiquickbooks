# Installation

## Requirements

* CiviCRM 6.11+
* The [Account Sync](https://github.com/eileenmcnaughton/nz.co.fuzion.accountsync) extension,
  declared as a dependency in `info.xml` and required for CiviQuickBooks to function
* An Intuit Developer Account, sign up at <https://developer.intuit.com/>. This account must be
  added as a user of `Admin` user type to the QuickBooks Online company being integrated.
* An Intuit App, created in that Intuit Developer Account, to provide the OAuth 2.0 Client ID and
  Client Secret. See [Create an Intuit app and get your
  keys](configuration.md#create-an-intuit-app-and-get-your-keys).
* A completed Intuit **App assessment questionnaire**, which Intuit requires before it issues the
  app's Production keys. See the [App assessment questionnaire](app-assessment-questionnaire.md)
  page. It takes 20 to 30 minutes, so do it early.

## Steps

1. Ensure the CiviContribute component is enabled
2. Download the [latest version of this
   extension](https://github.com/agileware/au.com.agileware.civiquickbooks/releases)
3. Unzip in the CiviCRM extension directory, as defined in `System Settings /
   Directories`.
4. Download the CiviCRM extension, **[Account
   Sync](https://github.com/eileenmcnaughton/nz.co.fuzion.accountsync/archive/master.tar.gz)**.
5. In CiviCRM, go to `System Settings / Extensions` and enable both extensions,
   **Account Sync** and **QuickBooks Online Integration**.
6. `Quickbooks Settings` will now be available directly under the `Contributions` menu. The sync
   error lists and the other Account Sync pages are in the `Accounts Integration` menu, also under
   `Contributions`. If you are upgrading from an earlier version, `Quickbooks Settings` was in the
   `Accounts Integration` menu and moves to `Contributions` the next time the CiviCRM caches are
   cleared, unless you have customised that menu item in the menu editor.
7. Click `Quickbooks Settings` to display the `QuickBooks Online Settings` page. Its address is
   `civicrm/admin/setting/quickbooks`, for example:
    * WordPress: `/wp-admin/admin.php?page=CiviCRM&q=civicrm/admin/setting/quickbooks&reset=1`
    * Drupal: `/civicrm/admin/setting/quickbooks?reset=1`
8. Continue with [Configuration](configuration.md) to connect to QuickBooks Online.
