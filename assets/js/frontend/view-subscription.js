jQuery( document ).ready( function( $ ) {
	// Auto Renewal Toggle
	var $toggleContainer = $( '.asub-auto-renew-toggle' );
	var $toggle          = $( '.subscription-auto-renew-toggle', $toggleContainer );
	var $icon            = $toggle.find( 'i' );
	var txtColor         = null;
	var $paymentMethod   = $( '.subscription-payment-method' );

	// Early Renewal
	let $early_renewal_modal_submit  = $( '#early_renewal_modal_submit' );
	let $early_renewal_modal_content = $( '.asub-modal > .content-wrapper' );


	// Change Quantity
	let $qty_change_modal_submit = $('#qty_change_modal_submit'); 


	function getTxtColor() {
		if ( !txtColor && ( $icon && $icon.length ) ) {
			txtColor = getComputedStyle( $icon[0] ).color;
		}

		return txtColor;
	}

	function maybeApplyColor() {
		if ( $toggle.hasClass( 'subscription-auto-renew-toggle--on' ) && $icon.length ) {	
			$icon[0].style.backgroundColor = getTxtColor();
			$icon[0].style.borderColor     = getTxtColor();
		} else if( $icon.length ) {
			$icon[0].style.backgroundColor = null;
			$icon[0].style.borderColor     = null;
		}
	}

	function displayToggle() {
		$toggle.removeClass( 'subscription-auto-renew-toggle--hidden' );
	}

	function onToggle( e ) {
		e.preventDefault();

		// Remove focus from the toggle element.
		$toggle.blur();

		// Ignore the request if the toggle is disabled.
		if ( $toggle.hasClass( 'subscription-auto-renew-toggle--disabled' ) ) {
			return;
		}

		var ajaxHandler = function( action ) {
			var data = {
				subscription_id: awcViewSubscription.subscription_id,
				action:          action,
				security:        awcViewSubscription.auto_renew_nonce,
			};

			// While we're waiting for an AJAX response, block the toggle element to prevent spamming the server.
			blockToggle();

			$.ajax({
				url:  awcViewSubscription.ajax_url,
				data: data,
				type: 'POST',
				success: function( result ) {
					if ( result.payment_method ) {
						$paymentMethod.fadeOut( function() {
							$paymentMethod.html( result.payment_method ).fadeIn();
						});
					}
					if ( undefined !== result.is_manual  ) {
						$paymentMethod.data( 'is_manual', result.is_manual );
					}
				},
				error: function( jqxhr, status, exception ) {
					alert( 'Exception:', exception );
				},
				complete: unblockToggle
			});
		};

		// Enable auto-renew
		if ( $toggle.hasClass( 'subscription-auto-renew-toggle--off' ) ) {
			// if payment method already exists just turn automatic renewals on.
			if ( awcViewSubscription.has_payment_gateway ) {
				ajaxHandler( 'awc_enable_auto_renew' );
				displayToggleOn();
			} else if ( window.confirm( awcViewSubscription.add_payment_method_msg ) ) { // else add payment method
				window.location.href = awcViewSubscription.add_payment_method_url;
			}

		} else { // Disable auto-renew
			ajaxHandler( 'awc_disable_auto_renew' );
			displayToggleOff();
		}

		maybeApplyColor();
	}

	function displayToggleOn() {
		$icon.removeClass( 'fa-toggle-off' ).addClass( 'fa-toggle-on' );
		$toggle.removeClass( 'subscription-auto-renew-toggle--off' ).addClass( 'subscription-auto-renew-toggle--on' );
	}

	function displayToggleOff() {
		$icon.removeClass( 'fa-toggle-on' ).addClass( 'fa-toggle-off' );
		$toggle.removeClass( 'subscription-auto-renew-toggle--on' ).addClass( 'subscription-auto-renew-toggle--off' );
	}

	function blockToggle() {
		$toggleContainer.block({
			message: null,
			overlayCSS: { opacity: 0.0 }
		});
	}

	function unblockToggle() {
		$toggleContainer.unblock();
	}

	function blockModal() {
		$early_renewal_modal_content.block({
			message: null,
			overlayCSS: {
				background: '#fff',
				opacity: 0.6
			}
		});
	}

	function submitQtyUpdateRequest(e){
		e.preventDefault();
		blockModal();
		
		let $name = $('input[name*="s_qty_"]').attr('name');		
		$name = $name.replace( /[^\d.]/g, '' );
		let $item_id = parseInt($name);
		let $value = $('input[name*="s_qty_"]').val();
		let $href = e.target.href + '&item_value=' + $value + '&item_id=' + $item_id;

		window.location.assign($href);
		
	}

	// Don't display the early renewal modal for manual subscriptions, they will need to renew via the checkout.
	function shouldShowEarlyRenewalModal( event ) {
		// We're only interested in requests to show the early renewal modal.
		if ( '.subscription_renewal_early' !== $( event.modal ).data( 'modal-trigger' ) ) {
			return;
		}
		return $paymentMethod.data( 'is_manual' ) === 'no';
	};

	$toggle.on( 'click', onToggle );
	maybeApplyColor();
	displayToggle();

	$early_renewal_modal_submit.on( 'click', blockModal );
	$qty_change_modal_submit.on('click', submitQtyUpdateRequest);
	$( document ).on( 'awc_show_modal', shouldShowEarlyRenewalModal );
});

