{if $hasContactErrors}
    <div class="crm-summary-row">
        <div class="crm-label">
            Contact Sync Errors
        </div>
        <div class="crm-content">
            Contact <span class='error'>sync error</span> with QuickBooks <a href='#' class='helpicon error quickbookserror-info' data-quickbookserrorid='{$accountContactId}'></a>
        </div>
    </div>
{/if}