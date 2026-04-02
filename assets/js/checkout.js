/**
 * WooCommerce Lightning Fedi — Payment verification polling.
 */
(function($) {
    'use strict';

    var paymentBox = document.getElementById('wlf-payment-box');
    if (!paymentBox) return;

    var orderId = paymentBox.getAttribute('data-order-id');
    if (!orderId) return;

    var checkInterval = (wlfData && wlfData.checkInterval) ? wlfData.checkInterval : 3000;
    var restUrl = wlfData ? wlfData.restUrl : '/wp-json/wlf/v1/';
    var maxChecks = 300; // 15 min at 3s interval
    var checks = 0;

    function checkPayment() {
        checks++;
        if (checks > maxChecks) {
            document.getElementById('wlf-status').innerHTML =
                '<p style="color:#ef4444">⏰ El invoice expiró. Recargá la página para generar uno nuevo.</p>';
            return;
        }

        $.ajax({
            url: restUrl + 'check-payment/' + orderId,
            method: 'GET',
            success: function(data) {
                if (data.paid) {
                    // Payment confirmed!
                    paymentBox.innerHTML =
                        '<div class="wlf-paid">' +
                        '<h2>✅ ¡Pago recibido!</h2>' +
                        '<p>Tu pago Lightning fue confirmado. Gracias.</p>' +
                        '</div>';

                    // Celebrate
                    if (typeof confetti === 'function') {
                        confetti({ particleCount: 100, spread: 70, origin: { y: 0.6 } });
                    }
                } else {
                    setTimeout(checkPayment, checkInterval);
                }
            },
            error: function() {
                // Retry on error
                setTimeout(checkPayment, checkInterval * 2);
            }
        });
    }

    // Start polling after a short delay
    setTimeout(checkPayment, checkInterval);

})(jQuery);
