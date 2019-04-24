<div class="crm-summary-row">
    <div class="crm-label">
        QuickBooks Sync Status
    </div>
    <div class="crm-content">
        {if $syncStatus == 0}
            <a href='#' id='quickbooks-sync' data-contact-id={$contactID}>Queue Sync to QuickBooks</a>
        {elseif $syncStatus == 1}
            Contact is synced with QuickBooks
        {elseif $syncStatus == 2}
            Contact is queued for sync with QuickBooks
        {/if}
    </div>

    {literal}

        <script type="text/javascript">
            cj('#quickbooks-sync').click(function( event) {
                event.preventDefault();
                CRM.api('account_contact', 'create',{
                    'contact_id' : cj(this).data('contact-id'),
                    'plugin' : 'quickbooks',
                    'accounts_needs_update' : 1,
                });
                cj(this).replaceWith('<p>Contact is queued for sync with QuickBooks</p>');
            });
        </script>

    {/literal}
</div>