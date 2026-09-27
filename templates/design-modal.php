<?php
/**
 * Storefront "Design Your Case" button + modal, printed inside the WooCommerce add-to-cart form
 * via woocommerce_before_add_to_cart_button. All interactivity lives in assets/js/design-tool.js;
 * this file only prints the static markup and the hidden field the server-side validation reads.
 *
 * @var WC_Product $product Set by the calling hook.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="gobear-design-tool" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>">
	<input type="hidden" name="gobear_design_token" class="gobear-design-token" value="" />

	<div class="gobear-design-summary" hidden>
		<img class="gobear-design-summary-thumb" src="" alt="" />
		<div class="gobear-design-summary-text">
			<strong class="gobear-design-summary-model"></strong>
			<span class="gobear-design-summary-sku"></span>
		</div>
		<button type="button" class="button gobear-edit-design"><?php esc_html_e( 'Edit design', 'gobear-personalization' ); ?></button>
	</div>

	<button type="button" class="button gobear-open-design-tool">
		<?php esc_html_e( 'Design Your Case', 'gobear-personalization' ); ?>
	</button>

	<p class="gobear-design-required-notice description" hidden>
		<?php esc_html_e( 'Please design your case before adding it to your cart.', 'gobear-personalization' ); ?>
	</p>

	<div class="gobear-modal" hidden>
		<div class="gobear-modal-backdrop"></div>
		<div class="gobear-modal-dialog" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Design Your Case', 'gobear-personalization' ); ?>">
			<button type="button" class="gobear-modal-close" aria-label="<?php esc_attr_e( 'Close', 'gobear-personalization' ); ?>">&times;</button>
			<h2><?php esc_html_e( 'Design Your Case', 'gobear-personalization' ); ?></h2>

			<div class="gobear-modal-body">
				<div class="gobear-modal-controls">
					<p class="gobear-field">
						<label for="gobear-model-select"><?php esc_html_e( 'Phone model', 'gobear-personalization' ); ?></label>
						<select class="gobear-model-select" id="gobear-model-select">
							<option value=""><?php esc_html_e( 'Loading…', 'gobear-personalization' ); ?></option>
						</select>
					</p>
					<p class="gobear-field gobear-material-field" hidden>
						<label for="gobear-sku-select"><?php esc_html_e( 'Case style', 'gobear-personalization' ); ?></label>
						<select class="gobear-sku-select" id="gobear-sku-select"></select>
					</p>
					<p class="gobear-field">
						<label for="gobear-photo-input"><?php esc_html_e( 'Your photo', 'gobear-personalization' ); ?></label>
						<input type="file" id="gobear-photo-input" class="gobear-photo-input" accept="image/png,image/jpeg" />
					</p>
					<p class="gobear-field gobear-zoom-field" hidden>
						<label for="gobear-zoom-range"><?php esc_html_e( 'Zoom', 'gobear-personalization' ); ?></label>
						<input type="range" id="gobear-zoom-range" class="gobear-zoom-range" min="1" max="3" step="0.01" value="1" />
					</p>
					<p class="gobear-design-error" role="alert" hidden></p>
					<p>
						<button type="button" class="button button-primary gobear-confirm-design" disabled>
							<?php esc_html_e( 'Use this design', 'gobear-personalization' ); ?>
						</button>
						<span class="gobear-saving-indicator" hidden><?php esc_html_e( 'Saving your design…', 'gobear-personalization' ); ?></span>
					</p>
					<img class="gobear-phone-image" alt="" hidden />
				</div>
				<div class="gobear-modal-preview">
					<div class="gobear-preview-box">
						<img class="gobear-preview-photo" alt="" hidden />
						<img class="gobear-preview-template" alt="" />
						<p class="gobear-preview-placeholder"><?php esc_html_e( 'Choose a phone model to begin.', 'gobear-personalization' ); ?></p>
					</div>
					<p class="description"><?php esc_html_e( 'Drag your photo to position it; use the zoom slider to resize. The area shown in the cut-out will be printed on your case.', 'gobear-personalization' ); ?></p>
				</div>
			</div>
		</div>
	</div>
</div>
