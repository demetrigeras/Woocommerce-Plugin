/**
 * Stablecoin Pay — block-checkout payment-method content component.
 *
 * Mirrors the classic-checkout iframe flow from
 * `includes/sp-checkout-modal.php` but for the React-based block checkout.
 *
 * Flow when the customer selects "Pay with Crypto" and clicks Place Order:
 *
 *   1. Block checkout fires our `onPaymentSetup` callback.
 *   2. We POST billing/shipping to `admin-ajax.php?action=sp_process_payment`,
 *      which creates a WooCommerce order and a Stablecoin Pay purchase session
 *      server-side and returns a one-time hosted-checkout URL.
 *   3. We mount that URL in an inline iframe inside this component (same
 *      "above the Place Order button" placement as the classic flow).
 *   4. We deliberately return a Promise that NEVER resolves. Block
 *      checkout's spinner stays on while the customer pays inside the
 *      iframe — that's the correct in-flight state and matches the
 *      classic flow where the Place Order button is hidden.
 *   5. A postMessage listener (and a polling fallback) watches for the
 *      iframe to signal that payment is complete. When that happens we
 *      navigate the TOP-LEVEL browser to `/checkout/order-received/`,
 *      which closes out this React tree entirely. No top-level redirect
 *      to a separate payment page — the customer never leaves /checkout/
 *      until they're done.
 *
 * The classic and block flows share the same server-side handlers
 * (`sp_ajax_process_payment` + `WC_Gateway_SP::process_payment`),
 * so the order lifecycle, webhook handling, and refund path are
 * identical.
 */

import {
	createPortal,
	useCallback,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { useSelect } from '@wordpress/data';

const IFRAME_DOM_ID = 'sp-blocks-iframe';
const REDIRECT_CHECK_INTERVAL_MS = 1000;
const REDIRECT_CHECK_MAX_MS = 5 * 60 * 1000; // give up after 5 minutes

const Content = ( { settings, ...props } ) => {
	const { eventRegistration, emitResponse, billing, shippingData } = props;
	const { onPaymentSetup } = eventRegistration || {};

	// Read the live cart totals straight from the block-checkout data
	// store. These are the same numbers the customer sees rendered on
	// the page (subtotal + shipping + tax + fees - discounts), expressed
	// as minor units (e.g. cents) alongside the currency's minor-unit
	// exponent. Passing the displayed total into our AJAX endpoint is the
	// only reliable way to guarantee the purchase session is created with
	// the exact amount the customer agreed to pay — the server-side cart
	// can drift in block-checkout because the Store API doesn't fire the
	// same hooks classic checkout does.
	const cartTotals = useSelect( ( select ) => {
		const store = select( 'wc/store/cart' );
		if ( ! store ) {
			return null;
		}
		const data = store.getCartData ? store.getCartData() : null;
		return data?.totals || null;
	}, [] );

	const [ checkoutUrl, setCheckoutUrl ] = useState( null );

	// Holds the resolver of the in-flight onPaymentSetup Promise so the
	// "X / ESC / backdrop-click" close handler can cancel the payment by
	// resolving with an ERROR. That unlocks the Place Order button and
	// lets the customer try again (creating a fresh order each time).
	const pendingResolveRef = useRef( null );

	// Keep latest billing/shipping/totals in refs so the onPaymentSetup
	// callback (registered once) can read fresh values when the customer
	// eventually clicks Place Order.
	const billingRef = useRef( billing );
	const shippingRef = useRef( shippingData );
	const totalsRef = useRef( cartTotals );
	useEffect( () => {
		billingRef.current = billing;
		shippingRef.current = shippingData;
		totalsRef.current = cartTotals;
	}, [ billing, shippingData, cartTotals ] );

	// Top-level navigation to the order-received page. Same effect as
	// `window.location.href = ...` in the classic flow.
	const navigateTopLevel = useCallback( ( url ) => {
		if ( typeof url === 'string' && url ) {
			window.location.href = url;
		}
	}, [] );

	// Close the modal AND cancel the in-flight onPaymentSetup Promise so
	// block checkout unlocks the Place Order button. We deliberately omit
	// a user-visible message: if the customer actually completed payment
	// and closed the modal before the redirect signal arrived, a
	// "canceled" error would be misleading — the webhook will still mark
	// the order as paid. The unlocked button is enough feedback that the
	// modal closed; the customer can click Place Order again (which
	// starts a fresh session) if they truly did cancel.
	//
	// The on-hold order created server-side is intentionally left in WC
	// admin — merchants can clean up abandoned on-hold orders manually
	// or via a future automated cleanup task.
	const handleClose = useCallback( () => {
		setCheckoutUrl( null );
		if ( typeof pendingResolveRef.current === 'function' ) {
			pendingResolveRef.current( {
				type: emitResponse.responseTypes.ERROR,
				message: '',
			} );
			pendingResolveRef.current = null;
		}
	}, [ emitResponse ] );

	// ESC key closes the modal while it's open.
	useEffect( () => {
		if ( ! checkoutUrl ) {
			return undefined;
		}
		const onKey = ( event ) => {
			if ( event.key === 'Escape' ) {
				handleClose();
			}
		};
		window.addEventListener( 'keydown', onKey );
		return () => window.removeEventListener( 'keydown', onKey );
	}, [ checkoutUrl, handleClose ] );

	// Wire up redirect-detection whenever the iframe is mounted.
	useEffect( () => {
		if ( ! checkoutUrl ) {
			return undefined;
		}

		// 1) postMessage from the hosted checkout (cross-origin friendly).
		const onMessage = ( event ) => {
			const data = event?.data;
			if (
				data &&
				typeof data === 'object' &&
				data.type === 'redirect' &&
				data.url
			) {
				navigateTopLevel( data.url );
				return;
			}
			if (
				typeof data === 'string' &&
				data.includes( 'order-received' )
			) {
				navigateTopLevel( data );
			}
		};
		window.addEventListener( 'message', onMessage );

		// 2) Polling fallback that watches the iframe's same-origin URL.
		//    Cross-origin reads throw, which we silently swallow — that's
		//    expected while the customer is still on the Stablecoin Pay host.
		//    The check succeeds once the hosted checkout itself navigates
		//    back to the merchant's /checkout/order-received/ page.
		const interval = setInterval( () => {
			try {
				const iframe = document.getElementById( IFRAME_DOM_ID );
				if ( iframe && iframe.contentWindow ) {
					const url = iframe.contentWindow.location.href;
					if ( url && url.includes( 'order-received' ) ) {
						clearInterval( interval );
						navigateTopLevel( url );
					}
				}
			} catch ( _ ) {
				/* cross-origin, expected — wait for postMessage */
			}
		}, REDIRECT_CHECK_INTERVAL_MS );

		const stopper = setTimeout(
			() => clearInterval( interval ),
			REDIRECT_CHECK_MAX_MS
		);

		return () => {
			window.removeEventListener( 'message', onMessage );
			clearInterval( interval );
			clearTimeout( stopper );
		};
	}, [ checkoutUrl, navigateTopLevel ] );

	// Register the onPaymentSetup callback once per mount.
	useEffect( () => {
		if ( typeof onPaymentSetup !== 'function' ) {
			return undefined;
		}

		const unsubscribe = onPaymentSetup( async () => {
			try {
				// Build the same snake_case payload the classic flow uses
				// (see includes/sp-checkout-modal.php around L340).
				const billingAddr =
					billingRef.current?.billingAddress || {};
				const shippingAddr =
					shippingRef.current?.shippingAddress || {};

				// Resolve email/phone across every spot WC has put them
				// over the years. Different WC Blocks versions surface
				// these in different places:
				//   - `billing.billingAddress.email|phone` (modern)
				//   - `billing.email|phone` (older API)
				//   - `shipping.shippingAddress.email|phone` (when "use
				//      same address for billing" is on and the customer
				//      only typed into the shipping fieldset)
				//   - the live cart store's `customerData` (always fresh)
				//
				// Without this fallback the server bounces the request
				// with "Please fill in required fields: Phone" even though
				// the customer can see the phone right there on screen.
				const liveBillingFromStore =
					cartTotals && billingRef.current
						? billingRef.current
						: null;
				const pickFirst = ( ...vals ) => {
					for ( const v of vals ) {
						if ( typeof v === 'string' && v.trim() !== '' ) {
							return v.trim();
						}
					}
					return '';
				};
				const resolvedEmail = pickFirst(
					billingAddr.email,
					shippingAddr.email,
					billingRef.current?.email,
					shippingRef.current?.email,
					liveBillingFromStore?.email
				);
				const resolvedPhone = pickFirst(
					billingAddr.phone,
					shippingAddr.phone,
					billingRef.current?.phone,
					shippingRef.current?.phone,
					liveBillingFromStore?.phone
				);

				const addressFields = [
					'first_name',
					'last_name',
					'company',
					'address_1',
					'address_2',
					'city',
					'state',
					'postcode',
					'country',
				];

				const payload = {
					action: settings.processAction,
					security: settings.nonce,
					payment_method: 'sp',
					billing_email: resolvedEmail,
					billing_phone: resolvedPhone,
					// Also send the customer-level email/phone fields so
					// that *any* version of the WC AJAX validator finds
					// them — block checkout's contact-info block writes
					// here on some installs.
					shipping_phone: resolvedPhone,
					shipping_email: resolvedEmail,
				};

				addressFields.forEach( ( key ) => {
					// Mirror in both directions so that whichever fieldset
					// the customer typed into, the other one is populated
					// too (ship-to-same-address case).
					const billingVal =
						billingAddr[ key ] || shippingAddr[ key ] || '';
					const shippingVal =
						shippingAddr[ key ] || billingAddr[ key ] || '';
					payload[ `billing_${ key }` ] = billingVal;
					payload[ `shipping_${ key }` ] = shippingVal;
				} );

				// eslint-disable-next-line no-console
				console.log( '[Stablecoin Pay] Submitting checkout payload', {
					billing_email: resolvedEmail,
					billing_phone: resolvedPhone,
					billing_first_name: payload.billing_first_name,
					billing_last_name: payload.billing_last_name,
					billing_address_1: payload.billing_address_1,
					billing_city: payload.billing_city,
					billing_country: payload.billing_country,
				} );

				// Forward the displayed totals as minor units (e.g. cents)
				// so the server can build the purchase session with the
				// exact figure the customer just agreed to. Server-side
				// recalculation is unreliable in block checkout because
				// the Store API path doesn't fire the same cart-sync
				// hooks classic checkout does.
				//
				// IMPORTANT: `totals.total_price` is the GRAND TOTAL — the
				// "Total" line at the bottom of the cart summary (e.g.
				// $450.80). It already includes items + shipping + tax +
				// fees − discounts.
				//
				// `totals.total_items` is the SUBTOTAL — sum of line items
				// only (e.g. $402.50). We deliberately do NOT send this as
				// the amount; we only ship it for analytics/metadata.
				const totalsNow = totalsRef.current;
				if ( totalsNow ) {
					payload.cart_total_minor =
						totalsNow.total_price ?? ''; // ← grand total
					payload.cart_total_tax_minor =
						totalsNow.total_tax ?? '';
					payload.cart_total_shipping_minor =
						totalsNow.total_shipping ?? '';
					payload.cart_total_items_minor =
						totalsNow.total_items ?? ''; // ← subtotal (metadata only)
					payload.cart_total_fees_minor =
						totalsNow.total_fees ?? '';
					payload.cart_total_discount_minor =
						totalsNow.total_discount ?? '';
					payload.cart_currency_code =
						totalsNow.currency_code || '';
					payload.cart_currency_minor_unit =
						totalsNow.currency_minor_unit ?? '2';

					// Visible-in-DevTools confirmation that we're shipping
					// the grand total, not the subtotal.
					const minorUnit = parseInt(
						totalsNow.currency_minor_unit ?? '2',
						10
					);
					const divisor = Math.pow(
						10,
						Number.isFinite( minorUnit ) ? minorUnit : 2
					);
					const grandTotal = totalsNow.total_price
						? parseInt( totalsNow.total_price, 10 ) / divisor
						: null;
					const subtotal = totalsNow.total_items
						? parseInt( totalsNow.total_items, 10 ) / divisor
						: null;
					// eslint-disable-next-line no-console
					console.log(
						'[Stablecoin Pay] Sending grand total to gateway:',
						{
							grand_total: grandTotal,
							subtotal,
							currency:
								totalsNow.currency_code || 'unknown',
						}
					);
				}

				const response = await fetch( settings.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type':
							'application/x-www-form-urlencoded',
					},
					body: new URLSearchParams( payload ).toString(),
				} );

				const data = await response.json();
				const url =
					data?.data?.sp_checkout_url ||
					data?.sp_checkout_url ||
					null;

				if ( ! data?.success || ! url ) {
					const serverMsg =
						typeof data?.data === 'string'
							? data.data
							: data?.data?.message;
					return {
						type: emitResponse.responseTypes.ERROR,
						message:
							serverMsg ||
							'Could not start the crypto payment session. Please try again.',
					};
				}

				// Mount the iframe — this triggers the JSX below to render
				// the centered modal.
				setCheckoutUrl( url );

				// Wait for one of two outcomes:
				//   (a) Payment completes: the redirect-detection effect
				//       navigates the top-level browser to
				//       /checkout/order-received/ and this component is
				//       unmounted — the Promise never resolves and that's
				//       fine.
				//   (b) Customer dismisses the modal: `handleClose` below
				//       calls the resolver with an ERROR, which unlocks
				//       the Place Order button and surfaces a friendly
				//       cancel message in the block checkout UI.
				const cancellationResult = await new Promise(
					( resolve ) => {
						pendingResolveRef.current = resolve;
					}
				);
				pendingResolveRef.current = null;
				return cancellationResult;
			} catch ( err ) {
				return {
					type: emitResponse.responseTypes.ERROR,
					message:
						err?.message ||
						'Unexpected error starting crypto payment.',
				};
			}
		} );

		return () => {
			if ( typeof unsubscribe === 'function' ) {
				unsubscribe();
			}
		};
	}, [ onPaymentSetup, emitResponse, settings ] );

	// --- Render -------------------------------------------------------

	// While the iframe is open we render it via a portal to <body> as a
	// centered modal so it escapes block-checkout's narrow payment-method
	// slot. The iframe gets its intended ~600px width on any layout, and
	// we don't touch any styling outside our own modal.
	const iframePanel =
		checkoutUrl && typeof document !== 'undefined'
			? createPortal(
					<div
						className="sp-blocks-iframe-portal"
						role="dialog"
						aria-modal="true"
						aria-label="Crypto checkout"
						onClick={ ( event ) => {
							// Only close on backdrop click (not on clicks
							// that bubble up from inside the iframe container).
							if ( event.target === event.currentTarget ) {
								handleClose();
							}
						} }
						style={ {
							position: 'fixed',
							inset: 0,
							zIndex: 999999,
							display: 'flex',
							alignItems: 'center',
							justifyContent: 'center',
							padding: '16px',
							background: 'rgba(15, 23, 42, 0.55)',
							boxSizing: 'border-box',
						} }
					>
						<div
							className="sp-blocks-iframe-container"
							style={ {
								position: 'relative',
								width: 'min(520px, calc(100vw - 24px))',
								height: 'min(820px, calc(100vh - 24px))',
								background: '#fff',
								borderRadius: '16px',
								boxShadow:
									'0 20px 48px rgba(0, 0, 0, 0.28)',
								overflow: 'hidden',
								display: 'flex',
								flexDirection: 'column',
							} }
						>
							<button
								type="button"
								onClick={ handleClose }
								aria-label="Close crypto checkout"
								style={ {
									position: 'absolute',
									top: '10px',
									right: '10px',
									width: '32px',
									height: '32px',
									padding: 0,
									border: 0,
									borderRadius: '50%',
									background: 'rgba(15, 23, 42, 0.75)',
									color: '#fff',
									fontSize: '18px',
									lineHeight: '32px',
									textAlign: 'center',
									cursor: 'pointer',
									zIndex: 2,
									boxShadow:
										'0 2px 6px rgba(0, 0, 0, 0.18)',
								} }
							>
								×
							</button>
							<iframe
								id={ IFRAME_DOM_ID }
								title="Crypto checkout"
								src={ checkoutUrl }
								style={ {
									width: '100%',
									flex: 1,
									border: 0,
									display: 'block',
								} }
								allow="clipboard-read *; clipboard-write *; publickey-credentials-create *; publickey-credentials-get *; autoplay *; camera *; microphone *; payment *; fullscreen *"
							/>
						</div>
					</div>,
					document.body
			  )
			: null;

	return (
		<div className="sp-block-payment">
			{ iframePanel }
		</div>
	);
};

export default Content;
