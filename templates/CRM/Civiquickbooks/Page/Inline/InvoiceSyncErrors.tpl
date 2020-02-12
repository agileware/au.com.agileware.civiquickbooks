{if $hasInvoiceErrors}
    <div class="crm-summary-row">
        <div class="crm-label">
            Invoice Sync Errors
        </div>
        <div class="crm-content">
            {$erroredInvoices} Contribution <span class='error'>not synced</span> with QuickBooks <a href='#' class='helpicon error quickbookserror-invoice-info' data-quickbookserrorid='{$contactID}'></a>
        </div>
    </div>
{/if}