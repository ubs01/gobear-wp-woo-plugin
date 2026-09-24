<?php
/**
 * Produces the two images a Gobear print order needs from a customer's uploaded photo and a
 * SKU's base_map print template:
 *  - a print-ready file: white everywhere, except the template's transparent print zone shows
 *    the customer's photo (this is the exact algorithm described in doc 3.15);
 *  - a rendering/preview file: the same, but composited over the phone's product image instead
 *    of white, for a more realistic "your photo on the case" preview.
 *
 * Positioning math is deliberately simple (translate + uniform scale only — no rotation), driven
 * by a small JSON payload from the browser describing where the shopper dragged/zoomed their
 * photo inside a same-aspect-ratio preview box. Compositing itself always happens server-side
 * (never in the browser) so it works regardless of whether Gobear's CDN sends CORS headers that
 * would otherwise let a <canvas> read pixels from those remote template images.
 *
 * Uses Imagick when available (fast, correct alpha compositing); falls back to a capped-resolution
 * GD pixel loop otherwise so the feature still works on hosts without the Imagick extension.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gobear_Image_Compositor {

	const REMOTE_CACHE_TTL = DAY_IN_SECONDS;
	const GD_MAX_DIMENSION = 1000; // Cap for the pixel-loop fallback so it can't time out the request.

	/**
	 * @param string $base_map_url    SKU's print-template image URL (transparent print zone).
	 * @param string $phone_image_url Phone product image URL, used as the rendering backdrop.
	 * @param string $photo_path      Local filesystem path to the customer's uploaded photo.
	 * @param array  $placement       { container_w, container_h, photo_natural_w, photo_natural_h, display_w, display_h, offset_x, offset_y } — all in the same "preview box" pixel space the browser used.
	 * @return array|WP_Error { print_path: string, rendering_path: string } Local temp file paths (caller is responsible for cleanup).
	 */
	public static function composite( $base_map_url, $phone_image_url, $photo_path, array $placement ) {
		$base_map_path = self::fetch_remote_image( $base_map_url );
		if ( is_wp_error( $base_map_path ) ) {
			return $base_map_path;
		}

		$phone_image_path = $phone_image_url ? self::fetch_remote_image( $phone_image_url ) : null;
		if ( is_wp_error( $phone_image_path ) ) {
			$phone_image_path = null; // Non-fatal — rendering preview will just fall back to white background.
		}

		if ( class_exists( 'Imagick' ) ) {
			return self::composite_with_imagick( $base_map_path, $phone_image_path, $photo_path, $placement );
		}

		return self::composite_with_gd( $base_map_path, $phone_image_path, $photo_path, $placement );
	}

	/**
	 * Downloads (and locally caches for a day) a remote template/product image so repeated
	 * designs against the same SKU don't re-download it every time.
	 *
	 * @return string|WP_Error Local file path.
	 */
	private static function fetch_remote_image( $url ) {
		$upload_dir = wp_upload_dir();
		$cache_dir  = trailingslashit( $upload_dir['basedir'] ) . 'gobear-cache/';
		if ( ! file_exists( $cache_dir ) ) {
			wp_mkdir_p( $cache_dir );
		}

		$ext       = pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION );
		$ext       = $ext ? preg_replace( '/[^a-z0-9]/i', '', $ext ) : 'png';
		$cache_key = md5( $url );
		$cache_path = $cache_dir . $cache_key . '.' . $ext;

		if ( file_exists( $cache_path ) && ( time() - filemtime( $cache_path ) ) < self::REMOTE_CACHE_TTL ) {
			return $cache_path;
		}

		$response = wp_remote_get( $url, array( 'timeout' => 25 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'gobear_template_fetch_failed', __( 'Could not download the case print template from Gobear.', 'gobear-personalization' ) );
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! $body ) {
			return new WP_Error( 'gobear_template_empty', __( 'Case print template appears to be empty.', 'gobear-personalization' ) );
		}

		file_put_contents( $cache_path, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		return $cache_path;
	}

	/**
	 * Scales the browser's "preview box" placement up to the base_map's real pixel dimensions.
	 *
	 * @return array { x, y, w, h } destination rectangle (in base_map pixel space) to draw the photo into.
	 */
	private static function scale_placement( array $placement, $target_w, $target_h ) {
		$container_w = max( 1, (float) ( $placement['container_w'] ?? 1 ) );
		$ratio       = $target_w / $container_w;

		return array(
			'x' => (int) round( ( (float) ( $placement['offset_x'] ?? 0 ) ) * $ratio ),
			'y' => (int) round( ( (float) ( $placement['offset_y'] ?? 0 ) ) * $ratio ),
			'w' => max( 1, (int) round( ( (float) ( $placement['display_w'] ?? $target_w ) ) * $ratio ) ),
			'h' => max( 1, (int) round( ( (float) ( $placement['display_h'] ?? $target_h ) ) * $ratio ) ),
		);
	}

	/**
	 * @return array|WP_Error
	 */
	private static function composite_with_imagick( $base_map_path, $phone_image_path, $photo_path, $placement ) {
		try {
			$base_map = new Imagick( $base_map_path );
			$base_map->setImageFormat( 'png' );
			$width  = $base_map->getImageWidth();
			$height = $base_map->getImageHeight();

			$rect = self::scale_placement( $placement, $width, $height );

			$photo = new Imagick( $photo_path );
			$photo->autoOrient();
			$photo->resizeImage( $rect['w'], $rect['h'], Imagick::FILTER_LANCZOS, 1, false );

			// Photo placed on its own transparent canvas the size of the template, then masked
			// by the template's alpha channel using the same Porter-Duff "destination-out"
			// operator an HTML <canvas> would use — kept where the mask is transparent, erased
			// where the mask is opaque.
			$masked = new Imagick();
			$masked->newImage( $width, $height, new ImagickPixel( 'transparent' ) );
			$masked->setImageFormat( 'png' );
			$masked->compositeImage( $photo, Imagick::COMPOSITE_OVER, $rect['x'], $rect['y'] );
			$masked->compositeImage( $base_map, Imagick::COMPOSITE_DSTOUT, 0, 0 );

			// Print file: white background, masked photo on top.
			$print = new Imagick();
			$print->newImage( $width, $height, new ImagickPixel( 'white' ) );
			$print->setImageFormat( 'png' );
			$print->compositeImage( $masked, Imagick::COMPOSITE_OVER, 0, 0 );

			// Rendering preview: phone product image (or white, if unavailable) as the backdrop.
			if ( $phone_image_path ) {
				$rendering = new Imagick( $phone_image_path );
				$rendering->setImageFormat( 'png' );
				$rendering->resizeImage( $width, $height, Imagick::FILTER_LANCZOS, 1, false );
			} else {
				$rendering = new Imagick();
				$rendering->newImage( $width, $height, new ImagickPixel( 'white' ) );
				$rendering->setImageFormat( 'png' );
			}
			$rendering->compositeImage( $masked, Imagick::COMPOSITE_OVER, 0, 0 );

			$print_path      = wp_tempnam( 'gobear-print.png' );
			$rendering_path  = wp_tempnam( 'gobear-render.png' );
			$print->writeImage( $print_path );
			$rendering->writeImage( $rendering_path );

			$print->clear();
			$rendering->clear();
			$masked->clear();
			$photo->clear();
			$base_map->clear();

			return array( 'print_path' => $print_path, 'rendering_path' => $rendering_path );
		} catch ( Throwable $e ) {
			return new WP_Error( 'gobear_composite_failed', $e->getMessage() );
		}
	}

	/**
	 * GD fallback. Resolution is capped (GD_MAX_DIMENSION) and blending is a manual per-pixel
	 * alpha loop, so this is slower than the Imagick path — acceptable for an on-demand "save
	 * design" action, but only used when Imagick isn't available.
	 *
	 * @return array|WP_Error
	 */
	private static function composite_with_gd( $base_map_path, $phone_image_path, $photo_path, $placement ) {
		if ( ! function_exists( 'imagecreatefromstring' ) ) {
			return new WP_Error( 'gobear_no_image_library', __( 'Neither Imagick nor GD is available on this server for image processing.', 'gobear-personalization' ) );
		}

		$base_map = @imagecreatefromstring( file_get_contents( $base_map_path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $base_map ) {
			return new WP_Error( 'gobear_bad_template', __( 'Could not read the case print template image.', 'gobear-personalization' ) );
		}

		$orig_w = imagesx( $base_map );
		$orig_h = imagesy( $base_map );

		// Downscale the working canvas if the template exceeds our safety cap, to keep the pixel
		// loop below from risking a request timeout.
		$scale  = min( 1, self::GD_MAX_DIMENSION / max( $orig_w, $orig_h ) );
		$width  = max( 1, (int) round( $orig_w * $scale ) );
		$height = max( 1, (int) round( $orig_h * $scale ) );

		if ( $scale < 1 ) {
			$resized = imagecreatetruecolor( $width, $height );
			imagealphablending( $resized, false );
			imagesavealpha( $resized, true );
			imagecopyresampled( $resized, $base_map, 0, 0, 0, 0, $width, $height, $orig_w, $orig_h );
			imagedestroy( $base_map );
			$base_map = $resized;
		}

		$photo = @imagecreatefromstring( file_get_contents( $photo_path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $photo ) {
			imagedestroy( $base_map );
			return new WP_Error( 'gobear_bad_photo', __( 'Could not read the uploaded photo.', 'gobear-personalization' ) );
		}

		$rect = self::scale_placement( $placement, $width, $height );

		$photo_layer = imagecreatetruecolor( $width, $height );
		imagealphablending( $photo_layer, false );
		imagesavealpha( $photo_layer, true );
		$transparent = imagecolorallocatealpha( $photo_layer, 0, 0, 0, 127 );
		imagefill( $photo_layer, 0, 0, $transparent );
		imagealphablending( $photo_layer, true );
		imagecopyresampled( $photo_layer, $photo, $rect['x'], $rect['y'], 0, 0, $rect['w'], $rect['h'], imagesx( $photo ), imagesy( $photo ) );
		imagedestroy( $photo );

		$phone_bg = null;
		if ( $phone_image_path ) {
			$src = @imagecreatefromstring( file_get_contents( $phone_image_path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $src ) {
				$phone_bg = imagecreatetruecolor( $width, $height );
				imagecopyresampled( $phone_bg, $src, 0, 0, 0, 0, $width, $height, imagesx( $src ), imagesy( $src ) );
				imagedestroy( $src );
			}
		}

		$print_canvas     = imagecreatetruecolor( $width, $height );
		$rendering_canvas = imagecreatetruecolor( $width, $height );
		$white            = imagecolorallocate( $print_canvas, 255, 255, 255 );
		imagefill( $print_canvas, 0, 0, $white );

		for ( $y = 0; $y < $height; $y++ ) {
			for ( $x = 0; $x < $width; $x++ ) {
				$mask_rgba   = imagecolorat( $base_map, $x, $y );
				$mask_alpha  = ( $mask_rgba >> 24 ) & 0x7F; // 0 = opaque template art, 127 = fully transparent print zone.
				$print_factor = $mask_alpha / 127.0;

				$photo_rgba  = imagecolorat( $photo_layer, $x, $y );
				$photo_alpha = 1 - ( ( ( $photo_rgba >> 24 ) & 0x7F ) / 127.0 );
				$pr = ( $photo_rgba >> 16 ) & 0xFF;
				$pg = ( $photo_rgba >> 8 ) & 0xFF;
				$pb = $photo_rgba & 0xFF;

				$mix = $print_factor * $photo_alpha;

				$print_r = (int) round( $pr * $mix + 255 * ( 1 - $mix ) );
				$print_g = (int) round( $pg * $mix + 255 * ( 1 - $mix ) );
				$print_b = (int) round( $pb * $mix + 255 * ( 1 - $mix ) );
				imagesetpixel( $print_canvas, $x, $y, imagecolorallocate( $print_canvas, $print_r, $print_g, $print_b ) );

				if ( $phone_bg ) {
					$bg_rgba = imagecolorat( $phone_bg, $x, $y );
					$br      = ( $bg_rgba >> 16 ) & 0xFF;
					$bg      = ( $bg_rgba >> 8 ) & 0xFF;
					$bb      = $bg_rgba & 0xFF;
				} else {
					$br = $bg = $bb = 255;
				}
				$rend_r = (int) round( $pr * $mix + $br * ( 1 - $mix ) );
				$rend_g = (int) round( $pg * $mix + $bg * ( 1 - $mix ) );
				$rend_b = (int) round( $pb * $mix + $bb * ( 1 - $mix ) );
				imagesetpixel( $rendering_canvas, $x, $y, imagecolorallocate( $rendering_canvas, $rend_r, $rend_g, $rend_b ) );
			}
		}

		$print_path     = wp_tempnam( 'gobear-print.png' );
		$rendering_path = wp_tempnam( 'gobear-render.png' );
		imagepng( $print_canvas, $print_path );
		imagepng( $rendering_canvas, $rendering_path );

		imagedestroy( $base_map );
		imagedestroy( $photo_layer );
		imagedestroy( $print_canvas );
		imagedestroy( $rendering_canvas );
		if ( $phone_bg ) {
			imagedestroy( $phone_bg );
		}

		return array( 'print_path' => $print_path, 'rendering_path' => $rendering_path );
	}
}
