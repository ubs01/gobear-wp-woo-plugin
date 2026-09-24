/**
 * Back-office "Fulfill" screen: loads the device list (with live slot-stock checks) for a
 * pending job and dispatches it to whichever device staff pick. Vanilla JS, no dependencies.
 */
( function () {
	'use strict';

	if ( typeof GobearFulfillment === 'undefined' ) {
		return;
	}

	function onReady( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	function post( action, params ) {
		var formData = new FormData();
		formData.append( 'action', action );
		formData.append( 'nonce', GobearFulfillment.nonce );
		Object.keys( params || {} ).forEach( function ( key ) {
			formData.append( key, params[ key ] );
		} );
		return fetch( GobearFulfillment.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: formData } )
			.then( function ( r ) { return r.json(); } );
	}

	function initDevicePicker( container ) {
		var fulfillmentId = container.getAttribute( 'data-fulfillment-id' );

		post( 'gobear_list_devices', { fulfillment_id: fulfillmentId } ).then( function ( json ) {
			if ( ! json.success ) {
				container.innerHTML = '<p class="gobear-error">' + escapeHtml( ( json.data && json.data.message ) || GobearFulfillment.i18n.error ) + '</p>';
				return;
			}

			var devices = json.data.devices || [];
			if ( ! devices.length ) {
				container.innerHTML = '<p>' + GobearFulfillment.i18n.noDevices + '</p>';
				return;
			}

			var html = '<table class="widefat striped"><thead><tr>'
				+ '<th></th><th>Device</th><th>Online</th><th>Status</th><th>Case Stock</th>'
				+ '</tr></thead><tbody>';

			devices.forEach( function ( device, index ) {
				var runStatusLabels = { 0: 'Unknown', 1: 'Idle', 2: 'Busy', 3: 'Locked', 4: 'Fault' };
				var stockLabel = '&mdash;';
				if ( device.has_slots ) {
					if ( device.slot_stock === null ) {
						stockLabel = 'Unknown';
					} else if ( device.slot_stock > 0 ) {
						stockLabel = device.slot_stock + ' loaded';
					} else {
						stockLabel = '<strong class="gobear-no-stock">' + GobearFulfillment.i18n.noStock + '</strong>';
					}
				}

				html += '<tr>'
					+ '<td><input type="radio" name="gobear_device" value="' + escapeHtml( device.device_id ) + '" data-device-sn="' + escapeHtml( device.device_sn ) + '" id="gobear-device-' + index + '" ' + ( device.is_online ? '' : 'disabled' ) + ' /></td>'
					+ '<td><label for="gobear-device-' + index + '">' + escapeHtml( device.device_name ) + ' (' + escapeHtml( device.device_sn ) + ')</label></td>'
					+ '<td>' + ( device.is_online ? 'Online' : 'Offline' ) + '</td>'
					+ '<td>' + ( runStatusLabels[ device.run_status ] || device.run_status ) + '</td>'
					+ '<td>' + stockLabel + '</td>'
					+ '</tr>';
			} );

			html += '</tbody></table>'
				+ '<p><button type="button" class="button button-primary" id="gobear-send-to-device" disabled>Send to Device</button> '
				+ '<span id="gobear-send-result"></span></p>';

			container.innerHTML = html;

			var sendBtn = document.getElementById( 'gobear-send-to-device' );
			container.querySelectorAll( 'input[name="gobear_device"]' ).forEach( function ( radio ) {
				radio.addEventListener( 'change', function () {
					sendBtn.disabled = false;
				} );
			} );

			sendBtn.addEventListener( 'click', function () {
				var checked = container.querySelector( 'input[name="gobear_device"]:checked' );
				if ( ! checked ) {
					return;
				}
				if ( ! window.confirm( GobearFulfillment.i18n.confirmSend ) ) {
					return;
				}

				sendBtn.disabled = true;
				var resultEl = document.getElementById( 'gobear-send-result' );
				resultEl.textContent = GobearFulfillment.i18n.sending;

				post( 'gobear_send_to_device', {
					fulfillment_id: fulfillmentId,
					device_id: checked.value,
					device_sn: checked.getAttribute( 'data-device-sn' ),
				} ).then( function ( json ) {
					if ( ! json.success ) {
						resultEl.textContent = ( json.data && json.data.message ) || GobearFulfillment.i18n.error;
						sendBtn.disabled = false;
						return;
					}
					resultEl.textContent = GobearFulfillment.i18n.sent + ' ' + ( json.data.gobear_order_sn || '' );
					window.location.reload();
				} );
			} );
		} );
	}

	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.textContent = String( str === undefined || str === null ? '' : str );
		return div.innerHTML;
	}

	onReady( function () {
		var picker = document.getElementById( 'gobear-device-picker' );
		if ( picker ) {
			initDevicePicker( picker );
		}

		var refreshBtn = document.getElementById( 'gobear-refresh-status' );
		if ( refreshBtn ) {
			refreshBtn.addEventListener( 'click', function () {
				var resultEl = document.getElementById( 'gobear-status-result' );
				resultEl.textContent = '…';
				post( 'gobear_refresh_status', { fulfillment_id: refreshBtn.getAttribute( 'data-fulfillment-id' ) } ).then( function ( json ) {
					if ( ! json.success ) {
						resultEl.textContent = ( json.data && json.data.message ) || GobearFulfillment.i18n.error;
						return;
					}
					window.location.reload();
				} );
			} );
		}

		var completeBtn = document.getElementById( 'gobear-mark-complete' );
		if ( completeBtn ) {
			completeBtn.addEventListener( 'click', function () {
				if ( ! window.confirm( 'Mark this job as complete? Only do this if you have confirmed production actually finished.' ) ) {
					return;
				}
				var resultEl = document.getElementById( 'gobear-status-result' );
				resultEl.textContent = '…';
				post( 'gobear_mark_complete', { fulfillment_id: completeBtn.getAttribute( 'data-fulfillment-id' ) } ).then( function ( json ) {
					if ( ! json.success ) {
						resultEl.textContent = ( json.data && json.data.message ) || GobearFulfillment.i18n.error;
						return;
					}
					window.location.reload();
				} );
			} );
		}
	} );
} )();
