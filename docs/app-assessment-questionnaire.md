# App assessment questionnaire

This page is part of setting up your Intuit app. The order is:

1. Create an Intuit Developer Account and an Intuit app
   ([Configuration](configuration.md#create-an-intuit-app-and-get-your-keys), steps 1 to 3).
2. **Complete the App assessment questionnaire on this page.**
3. Once Intuit approves it, copy the app's Production keys into CiviCRM and authorise
   ([Configuration](configuration.md#create-an-intuit-app-and-get-your-keys), steps 5 to 8, then
   [Authorise](configuration.md#authorise-civiquickbooks-access-to-your-quickbooks-online-account)).

When you create a new app on the Intuit Developer site and want to use its **Production** keys,
Intuit asks you to complete an *App assessment questionnaire*. This section lists the questions you
are likely to see and the answers that are accurate for a CiviQuickBooks app: a private app,
created by your own organisation, which connects your own CiviCRM site to your own QuickBooks Online
company using the Accounting API.

Notes before you start:

* The questionnaire is opened from your app on the Intuit Developer site. Choose your app from the
  drop down at the top, then work through the tabs on the left. Plan on 20 to 30 minutes.
* It must be completed by someone who is authorised to answer on behalf of your organisation.
* The number and wording of questions depends on the app and may change over time, so you may see
  more, fewer or differently worded questions than those below. Intuit's
  [FAQ](https://help.developer.intuit.com/s/article/New-app-assessment-process-FAQ) covers the
  process.
* Answers marked **Your call** depend on your organisation, not on the extension. Answer them
  truthfully for your own circumstances. The other answers describe how CiviQuickBooks behaves.

## General Questions

| # | Question | Suggested answer |
|---|---|---|
| 1 | Has your company ever received any complaints, lawsuits, or investigative requests from regulatory authorities or government agencies? | **Your call.** Typically `No`. |
| 2 | Have you worked with legal counsel to understand any regulatory requirements or other considerations related to your business activities and use of user data? | **Your call.** Typically `No`. |
| 3 | Have you reviewed and confirmed that you will comply with the [security policies](https://developer.intuit.com/app/developer/qbo/docs/go-live/publish-app/security-requirements)? | `Yes, I confirm that my app will comply with the security policies found above.` Read the linked policies first. |
| 4 | Apps that use Intuit APIs need to be relevant and clearly related to QuickBooks, accounting, payments, workflows, finance and other acceptable uses. Is your app designed to enhance, streamline or improve your or others' QuickBooks experience, or to facilitate a business process (e.g. syncing QBO data to another service)? | `Yes`. The extension syncs CiviCRM contributions with QuickBooks Online. |
| 5 | Are you or any of your representatives on any sanctions lists, or located or doing business in jurisdictions subject to comprehensive embargoes or sanctions? | **Your call.** Typically `No`. |
| 6 | Does your application involve any generative AI functionality? | `No`. The extension has no generative AI features. |

## App Information

| # | Question | Suggested answer |
|---|---|---|
| 1 | Which of the following is true about your app (at least one option must be checked)? | Tick **You built your app from scratch and wrote the code that lets it interact with Intuit APIs and data**. Leave the other options unticked. Your organisation does not ask its users to create an additional Intuit app, and the app is not a platform for other developers. |
| 2 | What platform(s) does your app utilize and make API calls from? | `Web/SaaS`. CiviCRM runs on your web server and makes the API calls from there. |
| 3 | How does your app interact with Intuit product data? | `It reads data from Intuit product(s)` and `It writes data to Intuit product(s)`. The extension pushes Customers, Invoices and Payments, and reads Invoices back. It does not delete data. |
| 4 | Are you building a private app for your team or business? Or, do you plan to make it publicly available? | `We're building a private app`. |
| 5 | Which types of QuickBooks Online users can use your app? | `Only the QuickBooks Online company admin who connected the app`. |
| 6 | Does your app integrate with platforms other than Intuit? | `No`, if you answer from the QuickBooks Online point of view. (CiviCRM and Account Sync are the other side of the sync and run on your own site.) If you are unsure, answer `Yes` and name CiviCRM. |

## Authorization and Authentication

| # | Question | Suggested answer |
|---|---|---|
| 1 | Have you tested connecting, disconnecting, and reconnecting your app with a sandbox or non-production company? | `Yes`. Set the `Environment` option to `Development` (see [Production or Development](configuration.md#are-you-using-a-quickbooks-online-production-or-development-account)) and test with an Intuit sandbox company first. |
| 2 | How often does your app refresh access tokens? | `Daily`. QuickBooks access tokens are short lived, and the extension refreshes them automatically whenever it is about to make an API call and the token is close to expiry. |
| 3 | Does your app retry authorization and authentication requests that have failed? | `Yes`. The next scheduled job run retries. |
| 4 | If your app encounters an authorization and authentication error, do you ask customers to reconnect to your app? | `Yes`. The settings page and System Status ask the administrator to click `Authorize` again. |
| 5 | Did you use the Intuit discovery document to get the latest endpoints required in the OAuth2.0 flow? | `Yes`. |
| 6a | Can your app handle errors due to expired access tokens? | `Yes`. |
| 6b | Can your app handle errors due to expired refresh tokens? | `Yes`. The administrator is prompted to re-authorise. |
| 6c | Can your app handle invalid grant errors? | `Yes`. |
| 6d | Can your app handle CSRF errors? | `Yes`. |
| 7 | Does your app rely on the OAuth playground or other offline tools to get access or refresh tokens? | `No`. Tokens are obtained through the `Authorize` link on the QuickBooks Online Settings page. |

## API Usage

| # | Question | Suggested answer |
|---|---|---|
| 1 | Which of the broad API categories does your app use? | `Accounting API` only. |
| 2 | How often does your app call our APIs for each customer? | `Daily`, assuming the Scheduled Jobs run daily or more often. Choose a different option if you schedule them differently. |

## Accounting API

| # | Question | Suggested answer |
|---|---|---|
| 1 | Which customer-facing version of QuickBooks Online is your app designed for? | Select every version your organisation might use. The extension does not depend on a particular version. |
| 2 | Can your app handle situations where users gain or lose access to version-specific features? Tell us how you plan to handle this situation. | `Yes`. The extension only uses core Customer, Invoice, Payment, Item and Tax Code features, which are not version specific. Failures are recorded as sync errors and shown to administrators. |
| 3 | Does your app utilize any of the following features? (Select all that you've verified and thoroughly tested) | Tick only the features you have actually tested. Typically none. |
| 4 | Do you use webhooks for your app? Is the endpoint URL active and functional? | `No`. The extension polls QuickBooks from Scheduled Jobs. |
| 5 | Do you use the CDC (Change Data Capture) operation for your app? | `No`. |

## Error Handling

| # | Question | Suggested answer |
|---|---|---|
| 1 | Have you tested if your app can handle API errors, including syntax and validation errors? | `Yes`. API errors are recorded against the Contact or Contribution (see [Troubleshooting](troubleshooting.md#troubleshooting)). |
| 2 | Does your app capture the value of the `intuit_tid` field from response headers? | `Yes`, once **Activate QBO logging?** is turned on. By default it is off, so the honest answer for a default install is `No`. Intuit recommends capturing it. |
| 3 | Does your app have a mechanism for storing all error information in logs that can be shared for troubleshooting purposes, if required? | `Yes`. Errors are kept in the Scheduled Job log and the sync error lists, and **Activate QBO logging?** writes full requests and responses to the CiviCRM log directory. |
| 4 | Do you provide a way for customers to contact you for support from within your app? | **Your call.** `No` unless your organisation runs a support channel linked from CiviCRM. |

## Security

| # | Question | Suggested answer |
|---|---|---|
| 1 | Has your company ever had a security breach that required notification to customers or government agencies/authorities? | **Your call.** Typically `No`. |
| 2 | Do you have a security team that regularly assesses vulnerabilities and risks for your app? | **Your call.** Answer for your organisation or hosting provider. |
| 3 | Are the client ID and client secret for your app stored securely (i.e. not hardcoded within your app or displayed in browser console logs)? | `Yes`. They are stored in CiviCRM settings and are never part of the extension code. |
| 4 | Does your app use multi-factor authentication? | **Your call.** This depends on how your CiviCRM site and the QuickBooks Online login are configured. |
| 5 | Does your app use Captcha for authentication? | `No`, unless your CMS login uses one. |
| 6 | Does your app use WebSocket? | `No`. |
| 7 | Once a customer's Intuit data is in your system, do you allow it to be used by or shown to anyone other than that customer? | `No, all Intuit customer data processed by our app is only used for the benefit of the original customer`. |

## Tips

* The questions above were captured from the questionnaire as it appeared in October 2026. If
  Intuit's wording differs, pick the answer that matches the intent of the question.
* If your answers are rejected or Intuit asks for more detail, reply on the support ticket Intuit
  opens. Intuit's [support page](https://help.developer.intuit.com/s/contactsupport) is the place to
  start.
* After approval, copy the **Production** `Client ID` and `Client Secret` into the settings page, as
  described in [Configuration](configuration.md#create-an-intuit-app-and-get-your-keys) (step 6 onward), then
  [authorise the connection](configuration.md#authorise-civiquickbooks-access-to-your-quickbooks-online-account).
