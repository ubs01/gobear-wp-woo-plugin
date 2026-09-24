/**
 * Storefront "Design Your Case" tool. Pure vanilla JS, no build step/dependencies.
 *
 * Responsibility split: this file only handles on-screen positioning (drag + zoom) using plain
 * <img> elements and CSS transforms — it never touches <canvas> pixel data, so it works
 * regardless of whether Gobear's CDN sends CORS headers on the template/product images. The
 * actual pixel compositing (masking the photo into the case-shaped print zone) happens server
 * side once the shopper confirms their design; see class-gobear-image-compositor.php.
 */
( function () {
	'use strict';

	if ( typeof GobearDesignTool === 'undefined' ) {
		return;
	}

	function onReady( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	function qs( root, sel ) {
		return root.querySelector( sel );
	}

	function setHidden( el, hidden ) {
		if ( ! el ) {
			return;
		}
		if ( hidden ) {
			el.setAttribute( 'hidden', 'hidden' );
		} else {
			el.removeAttribute( 'hidden' );
		}
	}

	function initTool( root ) {
		var productId   = root.getAttribute( 'data-product-id' );
		var openBtn      = qs( root, '.gobear-open-design-tool' );
		var modal        = qs( root, '.gobear-modal' );
		var closeBtn     = qs( root, '.gobear-modal-close' );
		var backdrop     = qs( root, '.gobear-modal-backdrop' );
		var modelSelect  = qs( root, '.gobear-model-select' );
		var materialField = qs( root, '.gobear-material-field' );
		var skuSelect    = qs( root, '.gobear-sku-select' );
		var photoInput   = qs( root, '.gobear-photo-input' );
		var zoomField    = qs( root, '.gobear-zoom-field' );
		var zoomRange    = qs( root, '.gobear-zoom-range' );
		var confirmBtn   = qs( root, '.gobear-confirm-design' );
		var savingIndicator = qs( root, '.gobear-saving-indicator' );
		var errorEl      = qs( root, '.gobear-design-error' );
		var tokenInput   = qs( root, '.gobear-design-token' );
		var requiredNotice = qs( root, '.gobear-design-required-notice' );

		var summary      = qs( root, '.gobear-design-summary' );
		var summaryThumb = qs( root, '.gobear-design-summary-thumb' );
		var summaryModel = qs( root, '.gobear-design-summary-model' );
		var summarySku   = qs( root, '.gobear-design-summary-sku' );
		var editBtn      = qs( root, '.gobear-edit-design' );

		var previewBox   = qs( root, '.gobear-preview-box' );
		var previewPhone = qs( root, '.gobear-preview-phone-bg' );
		var previewPhoto = qs( root, '.gobear-preview-photo' );
		var previewTemplate = qs( root, '.gobear-preview-template' );
		var previewPlaceholder = qs( root, '.gobear-preview-placeholder' );

		var models = null; // Loaded lazily on first open.
		var currentModel = null;
		var currentSku = null;
		var photoFile = null;

		// Photo placement state, all in "preview box" pixel space.
		var state = {
			natW: 0,
			natH: 0,
			coverW: 0,
			coverH: 0,
			scale: 1,
			centerX: 0,
			centerY: 0,
		};
		var dragging = false;
		var dragStart = null;

		function showError( msg ) {
			if ( ! errorEl ) {
				return;
			}
			errorEl.textContent = msg;
			setHidden( errorEl, ! msg );
		}

		function openModal() {
			setHidden( modal, false );
			if ( models === null ) {
				loadCatalog();
			}
		}

		function closeModal() {
			setHidden( modal, true );
		}

		function loadCatalog() {
			modelSelect.innerHTML = '<option value="">' + GobearDesignTool.i18n.loading + '</option>';
			var url = GobearDesignTool.ajaxUrl + '?action=gobear_get_catalog&product_id=' + encodeURIComponent( productId ) + '&nonce=' + encodeURIComponent( GobearDesignTool.nonce );
			fetch( url, { credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( json ) {
					if ( ! json.success ) {
						showError( ( json.data && json.data.message ) || GobearDesignTool.i18n.error );
						return;
					}
					models = json.data.models || [];
					populateModelSelect();
				} )
				.catch( function () { showError( GobearDesignTool.i18n.error ); } );
		}

		function populateModelSelect() {
			modelSelect.innerHTML = '<option value="">' + GobearDesignTool.i18n.chooseModel + '</option>';
			models.forEach( function ( model ) {
				var opt = document.createElement( 'option' );
				opt.value = model.model_id;
				opt.textContent = model.full_name || model.model_name;
				modelSelect.appendChild( opt );
			} );
		}

		function findModel( modelId ) {
			for ( var i = 0; i < models.length; i++ ) {
				if ( String( models[ i ].model_id ) === String( modelId ) ) {
					return models[ i ];
				}
			}
			return null;
		}

		function flattenSkus( model ) {
			var out = [];
			( model.materials || [] ).forEach( function ( material ) {
				( material.skus || [] ).forEach( function ( sku ) {
					out.push( {
						sku_id: sku.sku_id,
						sku_name: sku.sku_name,
						base_map: sku.base_map,
						material_name: material.material_name,
					} );
				} );
			} );
			return out;
		}

		function onModelChange() {
			var modelId = modelSelect.value;
			currentModel = modelId ? findModel( modelId ) : null;
			currentSku = null;
			resetPhoto();

			if ( ! currentModel ) {
				setHidden( materialField, true );
				previewPhone.src = '';
				previewTemplate.src = '';
				setHidden( previewPlaceholder, false );
				updateConfirmState();
				return;
			}

			previewPhone.src = currentModel.phone_image || '';
			setHidden( previewPlaceholder, true );

			var skus = flattenSkus( currentModel );
			if ( skus.length > 1 ) {
				skuSelect.innerHTML = '';
				skus.forEach( function ( sku ) {
					var opt = document.createElement( 'option' );
					opt.value = sku.sku_id;
					opt.textContent = ( sku.material_name ? sku.material_name + ' — ' : '' ) + sku.sku_name;
					skuSelect.appendChild( opt );
				} );
				setHidden( materialField, false );
				currentSku = skus[ 0 ];
			} else if ( skus.length === 1 ) {
				setHidden( materialField, true );
				currentSku = skus[ 0 ];
			} else {
				currentSku = null;
			}

			applySkuTemplate();
			updateConfirmState();
		}

		function onSkuChange() {
			var skuId = skuSelect.value;
			var skus = flattenSkus( currentModel || {} );
			for ( var i = 0; i < skus.length; i++ ) {
				if ( String( skus[ i ].sku_id ) === String( skuId ) ) {
					currentSku = skus[ i ];
					break;
				}
			}
			applySkuTemplate();
		}

		function applySkuTemplate() {
			previewTemplate.src = currentSku ? currentSku.base_map : '';
		}

		function resetPhoto() {
			photoFile = null;
			setHidden( previewPhoto, true );
			setHidden( zoomField, true );
			previewPhoto.style.transform = '';
			photoInput.value = '';
		}

		function onPhotoChange( e ) {
			var file = e.target.files && e.target.files[ 0 ];
			if ( ! file ) {
				return;
			}
			photoFile = file;

			var img = new Image();
			img.onload = function () {
				state.natW = img.naturalWidth;
				state.natH = img.naturalHeight;

				var box = previewBox.getBoundingClientRect();
				var coverScale = Math.max( box.width / state.natW, box.height / state.natH );
				state.coverW = state.natW * coverScale;
				state.coverH = state.natH * coverScale;
				state.scale = 1;
				state.centerX = box.width / 2;
				state.centerY = box.height / 2;

				previewPhoto.src = img.src;
				setHidden( previewPhoto, false );
				setHidden( zoomField, false );
				zoomRange.value = 1;
				renderPhotoTransform();
				updateConfirmState();
			};
			img.onerror = function () {
				showError( GobearDesignTool.i18n.error );
			};
			img.src = URL.createObjectURL( file );
		}

		function renderPhotoTransform() {
			var w = state.coverW * state.scale;
			var h = state.coverH * state.scale;
			var left = state.centerX - w / 2;
			var top  = state.centerY - h / 2;
			previewPhoto.style.width  = w + 'px';
			previewPhoto.style.height = h + 'px';
			previewPhoto.style.left   = left + 'px';
			previewPhoto.style.top    = top + 'px';
		}

		function onZoomInput() {
			state.scale = parseFloat( zoomRange.value );
			renderPhotoTransform();
		}

		function pointerPos( e ) {
			var box = previewBox.getBoundingClientRect();
			var point = ( e.touches && e.touches[ 0 ] ) ? e.touches[ 0 ] : e;
			return { x: point.clientX - box.left, y: point.clientY - box.top };
		}

		function onDragStart( e ) {
			if ( setHiddenState( previewPhoto ) ) {
				return;
			}
			dragging = true;
			var pos = pointerPos( e );
			dragStart = { x: pos.x, y: pos.y, centerX: state.centerX, centerY: state.centerY };
			e.preventDefault();
		}

		function setHiddenState( el ) {
			return el.hasAttribute( 'hidden' );
		}

		function onDragMove( e ) {
			if ( ! dragging ) {
				return;
			}
			var pos = pointerPos( e );
			state.centerX = dragStart.centerX + ( pos.x - dragStart.x );
			state.centerY = dragStart.centerY + ( pos.y - dragStart.y );
			renderPhotoTransform();
		}

		function onDragEnd() {
			dragging = false;
		}

		function updateConfirmState() {
			confirmBtn.disabled = ! ( currentModel && currentSku && photoFile );
		}

		function onConfirm() {
			if ( ! currentModel || ! currentSku || ! photoFile ) {
				return;
			}
			showError( '' );
			confirmBtn.disabled = true;
			setHidden( savingIndicator, false );

			var box = previewBox.getBoundingClientRect();
			var w = state.coverW * state.scale;
			var h = state.coverH * state.scale;
			var designMeta = {
				container_w: box.width,
				container_h: box.height,
				photo_natural_w: state.natW,
				photo_natural_h: state.natH,
				display_w: w,
				display_h: h,
				offset_x: state.centerX - w / 2,
				offset_y: state.centerY - h / 2,
			};

			var formData = new FormData();
			formData.append( 'action', 'gobear_save_design' );
			formData.append( 'nonce', GobearDesignTool.nonce );
			formData.append( 'product_id', productId );
			formData.append( 'model_id', currentModel.model_id );
			formData.append( 'sku_id', currentSku.sku_id );
			formData.append( 'design_meta', JSON.stringify( designMeta ) );
			formData.append( 'photo', photoFile );

			fetch( GobearDesignTool.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: formData } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( json ) {
					setHidden( savingIndicator, true );
					if ( ! json.success ) {
						showError( ( json.data && json.data.message ) || GobearDesignTool.i18n.error );
						confirmBtn.disabled = false;
						return;
					}
					tokenInput.value = json.data.design_token;
					summaryThumb.src = json.data.preview_url;
					summaryModel.textContent = json.data.model_name;
					summarySku.textContent = json.data.sku_name;
					setHidden( summary, false );
					setHidden( requiredNotice, true );
					confirmBtn.disabled = false;
					closeModal();
				} )
				.catch( function () {
					setHidden( savingIndicator, true );
					confirmBtn.disabled = false;
					showError( GobearDesignTool.i18n.error );
				} );
		}

		openBtn.addEventListener( 'click', openModal );
		if ( editBtn ) {
			editBtn.addEventListener( 'click', openModal );
		}
		closeBtn.addEventListener( 'click', closeModal );
		backdrop.addEventListener( 'click', closeModal );
		modelSelect.addEventListener( 'change', onModelChange );
		skuSelect.addEventListener( 'change', onSkuChange );
		photoInput.addEventListener( 'change', onPhotoChange );
		zoomRange.addEventListener( 'input', onZoomInput );
		confirmBtn.addEventListener( 'click', onConfirm );

		previewPhoto.addEventListener( 'mousedown', onDragStart );
		previewPhoto.addEventListener( 'touchstart', onDragStart, { passive: false } );
		window.addEventListener( 'mousemove', onDragMove );
		window.addEventListener( 'touchmove', onDragMove, { passive: false } );
		window.addEventListener( 'mouseup', onDragEnd );
		window.addEventListener( 'touchend', onDragEnd );

		// Client-side nicety on top of the server-side validation: block submitting the
		// add-to-cart form without a saved design and point the shopper at the design button.
		var form = root.closest( 'form.cart' );
		if ( form ) {
			form.addEventListener( 'submit', function ( e ) {
				if ( ! tokenInput.value ) {
					e.preventDefault();
					setHidden( requiredNotice, false );
					openBtn.scrollIntoView( { behavior: 'smooth', block: 'center' } );
				}
			} );
		}
	}

	onReady( function () {
		document.querySelectorAll( '.gobear-design-tool' ).forEach( initTool );
	} );
} )();
