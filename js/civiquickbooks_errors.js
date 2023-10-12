CRM.$(function($) {
    $('body').on('click','.quickbookserror-info',function(e){
        e.preventDefault();

        $.getJSON(CRM.url('civicrm/ajax/civiquickbooks/sync/contact/errors',
            {quickbookserrorid: $(this).data('quickbookserrorid')})
        ).done(function (result) {
            if((typeof result) == 'object') {
                result = Object.values(result);
            }
            if(result.length > 0) {
                CRM.alert(getErrorsText(result),'Contact sync','error');
            }
        });
    });

    $('body').on('click','.quickbookserror-invoice-info',function(e){
        e.preventDefault();

        $.getJSON(CRM.url('civicrm/ajax/civiquickbooks/sync/invoice/errors',
            {quickbookserrorid: $(this).data('quickbookserrorid')})
        ).done(function (result) {
            if((typeof result) == 'object') {
                result = Object.values(result);
            }
            if(result.length > 0) {
                CRM.alert(getErrorsText(result),'Invoice sync','error');
            }
        });
    });

    function getErrorsText(result) {
        let text = result.map(function(v) {
            if('object' == typeof v) {
                let error = Object.keys(v)[0];
                let string = v[error];
                if (response = string.match(/<IntuitResponse(?:.*|\n)*<\/IntuitResponse>/)) {
                    let responsedoc = $(response[0]);
                    string = responsedoc.find('Detail').text();
                }
                return string;
            } else {
                return v;
            }
        }).join('<br />');
        
        return text;
    }
});
