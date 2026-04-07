/**
 * Lamprozo - Admin JavaScript
 */

jQuery(document).ready(function($) {
    $('#lamprozo-test-api').on('click', function() {
        const button = $(this);
        const response = $('#lamprozo-api-response');

        button.prop('disabled', true).text('Loading...');

        $.ajax({
            url: lamprozoData.apiUrl + '/info',
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', lamprozoData.nonce);
            },
            success: function(data) {
                response.text(JSON.stringify(data, null, 2)).addClass('show');
                button.prop('disabled', false).text('Test REST API');
            },
            error: function(xhr) {
                response.text('Error: ' + xhr.responseText).addClass('show');
                button.prop('disabled', false).text('Test REST API');
            }
        });
    });
});
