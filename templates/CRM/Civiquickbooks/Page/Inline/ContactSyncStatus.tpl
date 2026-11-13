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
            {if $canManageSync}
                <a href='#' class='quickbooks-sync' data-contact-id='{$contactID}' data-account-contact-id='{$accountContactID}'>Retry sync</a>
            {/if}
        {elseif $syncStatus == 'do_not_sync'}
            Contact is excluded from sync with QuickBooks (Do not sync)
            {if $canManageSync}
                <a href='#' class='quickbooks-sync' data-contact-id='{$contactID}' data-account-contact-id='{$accountContactID}' data-resume='1'>Resume sync</a>
            {/if}
        {elseif $canManageSync}
            <a href='#' class='quickbooks-sync' data-contact-id='{$contactID}' data-account-contact-id='{$accountContactID}'>Queue Sync to QuickBooks</a>
        {else}
            Contact is not queued for sync with QuickBooks
        {/if}
    </div>

    {if $canManageSync}
    {literal}

        <script type="text/javascript">
            CRM.$(function($) {
                function queueSync($link) {
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
                    if ($link.data('resume')) {
                        params.do_not_sync = 0;
                    }
                    CRM.api3('AccountContact', 'create', params, true).done(function(result) {
                        if (!result.is_error) {
                            $link.closest('.crm-content').text('Contact is queued for sync with QuickBooks');
                        }
                    });
                }

                $('.quickbooks-sync').off('click.civiquickbooks').on('click.civiquickbooks', function(event) {
                    event.preventDefault();
                    var $link = $(this);
                    if ($link.data('resume')) {
                        CRM.confirm({
                            title: ts('Resume sync with QuickBooks?'),
                            message: ts('This Contact was excluded from sync, possibly because it matches a QuickBooks Customer that is already linked to another Contact. Merge or rename any duplicates first, or QuickBooks may reject the Contact or a duplicate Customer may be created.')
                        }).on('crmConfirm:yes', function() {
                            queueSync($link);
                        });
                    }
                    else {
                        queueSync($link);
                    }
                });
            });
        </script>

    {/literal}
    {/if}
</div>
