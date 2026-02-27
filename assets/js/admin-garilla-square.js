(function($){
    'use strict';

    $(function(){
        $('#garilla-square-test-connection').on('click', function(e){
            e.preventDefault();
            var $btn = $(this);
            var $result = $('#garilla-square-test-result');
            $result.text('Testing...');
            $btn.prop('disabled', true);

            $.post(garillaSquareAdmin.ajax_url, {
                action: 'garilla_square_test_connection',
                security: garillaSquareAdmin.nonce
            }).done(function(resp){
                if (resp && resp.success) {
                    $result.html('<span style="color:green">Success: ' + resp.data.message + '</span>');
                } else {
                    var msg = (resp && resp.data) ? resp.data : 'Unknown error';
                    $result.html('<span style="color:red">Failed: ' + msg + '</span>');
                }
            }).fail(function(xhr){
                var text = xhr && xhr.responseText ? xhr.responseText : 'Request failed';
                $result.html('<span style="color:red">Error: ' + text + '</span>');
            }).always(function(){
                $btn.prop('disabled', false);
            });
        });
    });

})(jQuery);
