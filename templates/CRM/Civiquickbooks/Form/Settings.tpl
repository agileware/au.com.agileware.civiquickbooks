{* HEADER *}
<div class="crm-submit-buttons">
  {include file="CRM/common/formButtons.tpl" location="top"}
</div>

{* FIELDS (AUTOMATIC LAYOUT) *}
{foreach from=$elementNames item=elementName}
  <div class="crm-section">
    <div class="label">{$form.$elementName.label}</div>
    <div class="content">
      {if $elementName == 'quickbooks_access_token_expiryDate' or $elementName == 'quickbooks_refresh_token_expiryDate'}
        {$form.$elementName.value|crmDate}
      {else}
        {$form.$elementName.html}
      {/if}
    </div>
    <div class="description content">{$description_array.$elementName}</div>

    {* Add authorization details after the expiryDate information *}
    {if $elementName == 'quickbooks_access_token_expiryDate'}
      {if $showClientKeysMessage}
        <p class="content">The Client ID and Client Secret are part of the QuickBooks Online App configuration.  To find the values for these, please <a href="https://developer.intuit.com/app/developer/qbo/docs/develop/authentication-and-authorization/oauth-2.0#obtain-oauth2-credentials-for-your-app" target="_blank">follow the instructions on the Intuit site</a>.</p>
      {/if}
      {if $redirect_url}
        <p class="content"><strong>Authorize your App:</strong>  Once a Consumer Key and Shared Secret have been configured, you will need to <a class="redirect_url" href="{$redirect_url}" title="Authorize Quickbooks Application">Authorize</a> the QuickBooks application.</p>
      {/if}
      </dl>
    {/if}
    <div class="clear"></div>
  </div>
{/foreach}

{* FOOTER *}
<div class="crm-submit-buttons">
  {include file="CRM/common/formButtons.tpl" location="bottom"}
</div>
