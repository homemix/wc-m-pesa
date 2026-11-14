/* global jQuery, wcMpesa */
(function ($) {
    'use strict';

    $(document).ready(function () {
        if (typeof wcMpesa === 'undefined' || !wcMpesa.orderId) {
            return;
        }

        var attempts     = 0;
        var maxAttempts  = wcMpesa.maxAttempts  || 20;
        var pollInterval = wcMpesa.pollInterval || 3000;
        var i18n         = wcMpesa.i18n         || {};

        var $notice = $('<div class="wc-mpesa-status-notice pending"></div>')
            .text(i18n.pending || 'Waiting for M-Pesa confirmation…');

        $('.woocommerce-order').prepend($notice);

        var interval = setInterval(function () {
            attempts++;

            $.ajax({
                url: wcMpesa.statusEndpoint,
                method: 'GET',
                data: { key: wcMpesa.orderKey || '' },
                success: function (response) {
                    if (response.status === 'success') {
                        clearInterval(interval);
                        $notice.removeClass('pending').addClass('success').text(i18n.success || 'Payment confirmed!');
                        setTimeout(function () {
                            if (response.redirect) {
                                window.location.href = response.redirect;
                            } else {
                                window.location.reload();
                            }
                        }, 1500);
                    } else if (response.status === 'failed') {
                        clearInterval(interval);
                        $notice.removeClass('pending').addClass('failed').text(i18n.failed || 'Payment failed.');
                    }
                    // 'pending' — keep polling.
                },
                error: function () {
                    // Network hiccup — keep polling until maxAttempts.
                }
            });

            if (attempts >= maxAttempts) {
                clearInterval(interval);
                $notice.removeClass('pending').addClass('timeout').text(i18n.timeout || 'Could not confirm payment.');
            }
        }, pollInterval);
    });
}(jQuery));
