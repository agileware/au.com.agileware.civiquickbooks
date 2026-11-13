<div class="crm-summary-row">
    <div class="crm-label">
        QuickBooks Sync Status
    </div>
    <div class="crm-content">
        {if $syncStatus == 'synced'}
            Contact is synced with QuickBooks
        {elseif $syncStatus == 'queued'}
            Contact is queued for sync with QuickBooks
        {elseif $syncStatus == 'error'}
            Contact failed to sync with QuickBooks.
            <a href='#' class='quickbooks-sync' data-contact-id='{$contactID}' data-account-contact-id='{$accountContactID}'>Retry sync</a>
        {elseif $syncStatus == 'do_not_sync'}
            Contact is excluded from sync with QuickBooks (Do not sync)
        {else}
            <a href='#' class='quickbooks-sync' data-contact-id='{$contactID}' data-account-contact-id='{$accountContactID}'>Queue Sync to QuickBooks</a>
        {/if}
    </div>

    {literal}

        <script type="text/javascript">
            CRM.$(function($) {
                $('.quickbooks-sync').off('click.civiquickbooks').on('click.civiquickbooks', function(event) {
                    event.preventDefault();
                    var $link = $(this);
                    var params = {
                        contact_id: $link.data('contact-id'),
                        plugin: 'quickbooks',
                        accounts_needs_update: 1,
                        // Clear any earlier error so the Contact Push picks the contact up again.
                        error_data: '',
                        is_error_resolved: 1
                    };
                    // Update the existing record rather than trying to create a second one.
                    if ($link.data('account-contact-id')) {
                        params.id = $link.data('account-contact-id');
                    }
                    CRM.api3('AccountContact', 'create', params, true).done(function(result) {
                        if (!result.is_error) {
                            $link.closest('.crm-content').text('Contact is queued for sync with QuickBooks');
                        }
                    });
                });
            });
        </script>

    {/literal}
</div>
