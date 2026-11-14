/* global jQuery */
(function ($) {
    'use strict';

    $(document).ready(function () {
        var $env = $('#woocommerce_wc_mpesa_environment');

        function toggleSandboxNotice() {
            var $row = $env.closest('tr');
            $row.find('.wc-mpesa-sandbox-notice').remove();

            if ($env.val() === 'sandbox') {
                $row.after(
                    '<tr class="wc-mpesa-sandbox-notice-row">' +
                    '<th></th><td>' +
                    '<p class="wc-mpesa-sandbox-notice">&#9888; You are in <strong>Sandbox</strong> mode. ' +
                    'No real money will be transferred. Switch to <em>Production</em> when you go live.</p>' +
                    '</td></tr>'
                );
            }
        }

        $env.on('change', toggleSandboxNotice);
        toggleSandboxNotice();
    });
}(jQuery));
