/* global wcPayJunctionVars, PayJunction, jQuery */
jQuery(function($) {
    // If SDK not present or publishableKey not set, do nothing.
    if (typeof PayJunction === 'undefined' || typeof wcPayJunctionVars === 'undefined' || !wcPayJunctionVars.publishableKey) {
        return;
    }

    var pj = PayJunction(wcPayJunctionVars.publishableKey);
    var gatewayId = wcPayJunctionVars.gatewayId || 'payjunctionrest';

    function tokenizeCard() {
        $('#pj-errors').text('');

        var cardNumber = $('#ccnum').val();
        var expMonth = $('#expmonth').val();
        var expYear = $('#expyear').val();
        var cvv = $('#cvv').val();

        // Basic front-end check (if CVV is expected use it)
        if (!cardNumber || !expMonth || !expYear || (wcPayJunctionVars.cvvmode === 'yes' && !cvv)) {
            $('#pj-errors').text('Please complete all card fields.');
            return $.Deferred().reject().promise();
        }

        var paymentData = {
            cardNumber: cardNumber,
            cardExpMonth: expMonth,
            cardExpYear: expYear,
            cardCvv: cvv
        };

        var deferred = $.Deferred();

        pj.createToken(paymentData)
            .then(function(result) {
                if (result && result.tokenId) {
                    $('#pj_token_id').val(result.tokenId);

                    // Remove name attributes from card fields so PAN/CVV are not POSTed
                    $('#ccnum').removeAttr('name');
                    $('#expmonth').removeAttr('name');
                    $('#expyear').removeAttr('name');
                    $('#cvv').removeAttr('name');

                    // Optionally clear the values
                    $('#ccnum, #expmonth, #expyear, #cvv').val('');

                    deferred.resolve();
                } else if (result && result.errors && result.errors.length) {
                    var formattedErrors = result.errors.map(function(e) {
                        return (e.parameter ? e.parameter + ': ' : '') + e.message;
                    }).join(' | ');
                    $('#pj-errors').text(formattedErrors);
                    deferred.reject();
                } else {
                    $('#pj-errors').text('Tokenization failed. Please check your card details.');
                    deferred.reject();
                }
            })
            .catch(function(err) {
                console.error('PayJunction tokenization error', err);
                $('#pj-errors').text('Tokenization error. Please try again.');
                deferred.reject();
            });

        return deferred.promise();
    }

    // Hook into WC checkout submission for this gateway only.
    $('form.checkout, form#order_review').on('checkout_place_order_' + gatewayId, function(e) {
        var $form = $(this);

        // If a token is already present we allow the submission
        if ($('#pj_token_id').val()) {
            return true;
        }

        // If publishableKey set, try tokenizing
        e.preventDefault();

        tokenizeCard().done(function() {
            // Unbind the handler to avoid infinite loop and submit
            $form.off('checkout_place_order_' + gatewayId);
            $form.submit();
        }).fail(function() {
            // Keep user on page; errors are already shown
        });

        return false;
    });
});
