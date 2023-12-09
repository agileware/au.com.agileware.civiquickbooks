{* HEADER *}
<div class="crm-block crm-form-block crm-{$entityInClassFormat}-block">

<div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="top"}
</div>

<table class="form-layout">
  {foreach from=$fields item=fieldSpec}
    {assign var=fieldName value=$fieldSpec.name}
    <tr class="crm-{$entityInClassFormat}-form-block-{$fieldName}">
      {include file="CRM/Core/Form/Field.tpl"}
        {* Add authorization details after the expiryDate information *}
        {if $fieldName == 'quickbooks_access_token_expiryDate'}
            {if $showClientKeysMessage}
                <tr><td></td><td>
                <p class="content">The Client ID and Client Secret are part of the QuickBooks Online App configuration.
                    To find the values for these, please <a
                            href="https://developer.intuit.com/app/developer/qbo/docs/develop/authentication-and-authorization/oauth-2.0#obtain-oauth2-credentials-for-your-app"
                            target="_blank">follow the instructions on the Intuit site</a>.</p>
                </td></tr>
            {/if}
            {if $redirect_url}
                <tr><td></td><td>
                <p class="content messages status no-popup crm-not-you-message">
                    <strong>
                        {if $isRefreshTokenExpired}
                            Reauthorize your App:
                            <br>
                        {else}
                            Authorize your App:
                            <br>
                        {/if}
                    </strong>
                    {if $isRefreshTokenExpired}
                        Refresh token is expired, you will need to
                        <a class="redirect_url" href="{$redirect_url}" title="Authorize Quickbooks Application">Reauthorize</a>
                        the QuickBooks application.
                        <br>
                        All contacts and contributions updates won't get synced with QuickBooks.
                    {else}
                        Once a Consumer Key and Shared Secret have been configured, you will need to
                        <a class="redirect_url" href="{$redirect_url}" title="Authorize Quickbooks Application">Authorize</a>
                        the QuickBooks application.
                        <br>
                        <br>
                        You must add this Redirect URI to your application:
                        <br>
                        {$redirect_url}
                    {/if}
                </p>
                </td></tr>
            {/if}
        {/if}
        </tr>
  {/foreach}
</table>

{* FOOTER *}
<div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="bottom"}
</div>
</div>
