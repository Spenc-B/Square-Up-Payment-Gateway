jQuery(function ($) {

    let card = null;
    let payments = null;

    async function initSquare() {

        if (typeof Square === 'undefined') {
            console.log('Square SDK not loaded');
            return;
        }

        payments = Square.payments(
            SquareParams.appId,
            SquareParams.locationId
        );

        try {

            card = await payments.card();

            await card.attach('#square-card-container');

            initCheckoutInterceptor();

            initExpressButton();

        } catch (err) {
            console.log('Square init error', err);
        }
    }

    async function initExpressButton() {

        try {

            const paymentRequest = payments.paymentRequest({
                countryCode: 'US',
                currencyCode: wc_checkout_params.currency_code || 'USD',
                total: {
                    amount: Math.round(parseFloat(wc_checkout_params.total || 0)),
                    label: 'Order Total'
                }
            });

            const button = await payments.paymentRequestButton({
                paymentRequest: paymentRequest
            });

            await button.attach('#square-express-container');

        } catch (e) {
            console.log('Express payment not available', e);
        }
    }

    function initCheckoutInterceptor() {

        $('form.checkout').on(
            'checkout_place_order_square_web_payments',
            async function () {

                if (!card) return false;

                try {

                    const result = await card.tokenize();

                    if (result.status !== 'OK') {
                        $('#square-errors').text('Card tokenization failed');
                        return false;
                    }

                    const orderId = $('input[name="woocommerce_checkout_update_totals"]').val() || 0;

                    return new Promise(function (resolve) {

                        $.post(SquareParams.ajaxUrl, {
                            action: 'square_process_payment',
                            token: result.token,
                            order_id: orderId,
                            nonce: SquareParams.nonce
                        }, function (resp) {

                            if (resp.success) {
                                resolve(true);
                            } else {
                                $('#square-errors').text('Payment failed');
                                resolve(false);
                            }

                        });

                    });

                } catch (err) {

                    console.log(err);
                    return false;
                }

            }
        );
    }

    initSquare();

});