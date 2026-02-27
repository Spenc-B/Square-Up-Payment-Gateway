(function($){
    'use strict';

    let payments;
    let card;
    let prButton;

    function showCardContainerMessage(msg, isError) {
        const container = document.querySelector('#card-container');
        if (!container) return;
        container.innerHTML = '<div class="garilla-square-message" style="padding:12px;border:1px solid ' + (isError ? '#e0b4b4' : '#b7e0b4') + ';background:' + (isError ? '#fff6f6' : '#f6fffa') + ';color:' + (isError ? '#9f3a38' : '#2b5d2b') + ';">' + msg + '</div>';
    }

    function loadSquareScript(url) {
        return new Promise(function(resolve, reject){
            if (window.Square) {
                console.debug('Square SDK already present');
                return resolve();
            }
            const s = document.createElement('script');
            s.src = url;
            s.onload = function(){
                console.debug('Square SDK loaded from', url);
                resolve();
            };
            s.onerror = function(err){
                console.error('Failed to load Square SDK', err);
                reject(err);
            };
            document.head.appendChild(s);
        });
    }

    let initialized = false;

    async function initialize() {
        if (initialized) return;
        console.debug('Initializing Garilla Square Payments', window.garillaSquareSettings);
        if (typeof garillaSquareSettings === 'undefined' || !garillaSquareSettings.applicationId) {
            console.warn('garillaSquareSettings not available or applicationId missing');
            showCardContainerMessage('Payment setup is not configured. Please contact support.', true);
            return;
        }

        const env = garillaSquareSettings.environment === 'production' ? 'production' : 'sandbox';
        const scriptUrl = env === 'production' ? 'https://web.squarecdn.com/v1/square.js' : 'https://sandbox.web.squarecdn.com/v1/square.js';

        try {
            await loadSquareScript(scriptUrl);
        } catch (e) {
            console.error('Failed to load Square script', e);
            showCardContainerMessage('Could not load payment library. Please try again later.', true);
            return;
        }

        if (!window.Square || !window.Square.payments) {
            console.error('Square.payments not available on window');
            showCardContainerMessage('Payment library loaded but is incompatible. Please contact support.', true);
            return;
        }

        try {
            payments = window.Square.payments(garillaSquareSettings.applicationId, garillaSquareSettings.locationId);
            console.debug('Square.payments initialized', payments);

            card = await payments.card();
            await card.attach('#card-container');
            console.debug('Card attached to #card-container');
            showCardContainerMessage('Card payment ready', false);

            if (garillaSquareSettings.enableExpress) {
                try {
                    const currency = (window.woocommerce_params && window.woocommerce_params.currency) ? window.woocommerce_params.currency : 'USD';
                    const total = (document.querySelector('.order-total .amount') && document.querySelector('.order-total .amount').innerText) || '0.00';

                    const paymentRequest = payments.paymentRequest({
                        countryCode: 'US',
                        currencyCode: currency,
                        total: {label: 'Order total', amount: total}
                    });

                    prButton = await payments.paymentRequestButton(paymentRequest);
                    const canUse = await prButton.canCreatePaymentRequest();
                    console.debug('Payment Request button availability:', canUse);
                    if (canUse) {
                        await prButton.attach('#payment-request-button');
                        console.debug('Payment Request button attached');
                        // attach click handler to tokenize and submit
                        const prEl = document.querySelector('#payment-request-button button') || document.querySelector('#payment-request-button');
                        if (prEl) {
                            prEl.addEventListener('click', async function(e){
                                e.preventDefault();
                                try {
                                    const result = await prButton.tokenize();
                                    if (result.status === 'OK' && result.token) {
                                        document.getElementById('square_payment_nonce').value = result.token;
                                        submitCheckoutForm();
                                    } else {
                                        console.error('Tokenization failed', result);
                                        showCardContainerMessage('Payment failed to tokenize: ' + (result.errors ? JSON.stringify(result.errors) : 'Unknown'), true);
                                    }
                                } catch (err) {
                                    console.error(err);
                                    showCardContainerMessage('Payment failed: ' + err.message, true);
                                }
                            });
                        }
                    } else {
                        // Hide the payment request button area if not available
                        const prContainer = document.querySelector('#payment-request-button');
                        if (prContainer) prContainer.style.display = 'none';
                    }
                } catch (err) {
                    console.warn('Payment Request may not be available', err);
                }
            }

            // mark initialized only after setup completed without throwing
            initialized = true;
        } catch (err) {
            console.error('Square Payments initialization error', err);
            showCardContainerMessage('Payment initialization error. Please try again later.', true);
        }
    }

    // If the checkout is injected dynamically (shortcode rendered later, blocks, or theme JS),
    // observe the document for insertion of the card container and initialize then.
    function observeForCheckoutInsert() {
        if (document.querySelector('#card-container')) {
            // container already exists, try initialize immediately
            initialize();
            return;
        }

        const observer = new MutationObserver(function(mutations, obs) {
            if (document.querySelector('#card-container')) {
                console.debug('Detected #card-container insertion — initializing Square payments');
                initialize();
                obs.disconnect();
            }
        });

        observer.observe(document.documentElement || document.body, { childList: true, subtree: true });
    }

    async function tokenizeCardAndSubmit() {
        if (!card) {
            showCardContainerMessage('Payment form not initialized.', true);
            return;
        }
        try {
            const result = await card.tokenize();
            if (result.status === 'OK' && result.token) {
                document.getElementById('square_payment_nonce').value = result.token;
                submitCheckoutForm();
            } else {
                let message = 'Payment could not be tokenized.';
                if (result.status === 'ERROR' && result.errors) {
                    message = result.errors.map(e => e.message).join('\n');
                }
                showCardContainerMessage(message, true);
                $('button[name=woocommerce_checkout_place_order]').prop('disabled', false);
            }
        } catch (err) {
            console.error(err);
            showCardContainerMessage('Payment failed: ' + err.message, true);
            $('button[name=woocommerce_checkout_place_order]').prop('disabled', false);
        }
    }

    function submitCheckoutForm() {
        // Submit WooCommerce checkout form
        const form = document.querySelector('form.checkout');
        if (form) {
            form.submit();
        }
    }

    // Intercept checkout submit when our gateway is selected
    $(function(){
        // Try to initialize immediately; also observe for late insertion
        initialize();
        observeForCheckoutInsert();

        $('form.checkout').on('submit', function(e){
            const selected = $('input[name=payment_method]:checked').val();
            if (selected === 'garilla_square') {
                // If nonce already present, allow (happens when pr-button submitted)
                if (document.getElementById('square_payment_nonce').value) {
                    return true;
                }

                e.preventDefault();
                $('button[name=woocommerce_checkout_place_order]').prop('disabled', true);
                tokenizeCardAndSubmit();
                return false;
            }
        });
    });

})(jQuery);
