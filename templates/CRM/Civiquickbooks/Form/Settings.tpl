{* HEADER *}
<div class="crm-submit-buttons">
  {include file="CRM/common/formButtons.tpl" location="top"}
</div>

{* FIELDS (AUTOMATIC LAYOUT) *}
{foreach from=$elementNames item=elementName}
  <div class="crm-section">
    <div class="label">{$form.$elementName.label}</div>
    <div class="content">{$form.$elementName.html}</div>
    <div class="description content">{$description_array.$elementName}</div>

    {* Add authorization details after the expiryDate information *}
    {if $elementName == 'quickbooks_access_token_expiryDate'}
      <p class="content">The Consumer Key and Shared Secret are part of the QuickBooks Online App configuration.  To find the values for these, please <a href="https://developer.intuit.com/docs/0100_quickbooks_online/0100_essentials/0085_develop_quickbooks_apps/0005_use_your_app_with_production_keys">follow the instructions on the Intuit site</a>.</p>
      {if $redirect_url}<p class="content"><strong>Authorize your App:</strong>  Once a Consumer Key and Shared Secret have been configured, you will need to <a class="redirect_url" href="{$redirect_url}" title="Authorize Quickbooks Application">Authorize</a> the QuickBooks application.</p>{/if}
      </dl>
    {/if}
    <div class="clear"></div>
  </div>
{/foreach}

{* FOOTER *}
<div class="crm-submit-buttons">
  {include file="CRM/common/formButtons.tpl" location="bottom"}
</div>
