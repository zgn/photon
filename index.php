<?php

ini_set('display_errors', 1);
error_reporting(7);
define( 'PHOTON__ALLOW_QUERY_STRINGS', 1 );
require dirname( __FILE__ ) . '/plugin.php';
// if ( file_exists( dirname( __FILE__ ) . '/../config.php' ) )
// 	require dirname( __FILE__ ) . '/../config.php';
// else if ( file_exists( dirname( __FILE__ ) . '/config.php' ) )
// 	require dirname( __FILE__ ) . '/config.php';
// Explicit Configuration
$allowed_functions = apply_filters( 'allowed_functions', array(
//	'q'           => RESERVED
//	'zoom'        => global resolution multiplier (argument filter)
	'h'           => 'setheight',       // done
	'w'           => 'setwidth',        // done
	'crop'        => 'crop',            // done
	's'      => 'resize_and_crop', // done
	'fit'         => 'fit_in_box',      // done
	'lb'          => 'letterbox',       // done
	'ulb'         => 'unletterbox',     // compat
	'filter'      => 'filter',          // compat
	'brightness'  => 'brightness',      // compat
	'contrast'    => 'contrast',        // compat
	'colorize'    => 'colorize',        // compat
	'smooth'      => 'smooth',          // compat
) );

unset( $allowed_functions['q'] );

$allowed_types = apply_filters( 'allowed_types', array(
	'gif',
	'jpg',
	'jpeg',
	'png',
) );

$disallowed_file_headers = apply_filters( 'disallowed_file_headers', array(
	'8BPS',
) );

// Expects a trailing slash
$tmpdir = apply_filters( 'tmpdir', '/tmp/' );
$remote_image_max_size = apply_filters( 'remote_image_max_size', 55 * 1024 * 1024 );

/* Array of domains exceptions
 * Keys are domain name
 * Values are bitmasks with the following options:
 * PHOTON__ALLOW_QUERY_STRINGS: Append the string found in the 'q' query string parameter as the query string of the remote URL
 */
$origin_domain_exceptions = apply_filters( 'origin_domain_exceptions', array() );

// You can override this by defining it in config.php
if ( ! defined( 'PHOTON__UPSCALE_MAX_PIXELS' ) )
	define( 'PHOTON__UPSCALE_MAX_PIXELS', 2000 );

require dirname( __FILE__ ) . '/libjpeg.php';

// Implicit configuration
if ( file_exists( '/usr/bin/optipng' ) )
	define( 'OPTIPNG', '/usr/bin/optipng' );
else
	define( 'OPTIPNG', false );

if ( file_exists( '/usr/bin/jpegoptim' ) )
	define( 'JPEGOPTIM', '/usr/bin/jpegoptim' );
else
	define( 'JPEGOPTIM', false );

/**
 * zoom - ( "zoom" function via the uri ) - Intended for improving visuals
 * on high pixel ratio devices and browsers when zoomed in. No zoom in crop.
 *
 * Valid zoom levels are 1,1.5,2-10.
 */
function zoom( $arguments, $function_name, $image ) {
	static $zoom;

	if ( !isset( $zoom ) ) {
		if ( isset( $_GET['zoom'] ) ) {
			$zoom = floatval( $_GET['zoom'] );
			// Clamp to 1-10
			$zoom = max( 1, $zoom );
			$zoom = min( 10, $zoom );
			if ( $zoom < 2 ) {
				// Round UP to the nearest half
				$zoom = ceil( $zoom * 2 ) / 2;
			} else {
				// Round UP to the nearest integer
				$zoom = ceil( $zoom );
			}
		} else {
			$zoom = false;
		}
	}

	if ( $zoom <= 1 )
		return $arguments;

	$w = $image->getimagewidth();
	$h = $image->getimageheight();

	switch ( $function_name ) {
		case 'setheight' :
		case 'setwidth' :
			$new_arguments = $arguments * $zoom;
			if ( substr( $arguments, -1 ) == '%' )
				$new_arguments .= '%';
			break;
		case 'fit_in_box' :
		case 'resize_and_crop' :
			list( $width, $height ) = explode( ',', $arguments );
			$new_width = $width * $zoom;
			$new_height = $height * $zoom;
			// Avoid dimensions larger than original.
			while ( ( $new_width > $w || $new_height > $h ) && $zoom > 1 ) {
				// Step down to the next lower zoom level.
				if ( $zoom > 2 ) {
					$zoom -= 1;
				} else {
					$zoom -= 0.5;
				}
				$new_width = $width * $zoom;
				$new_height = $height * $zoom;
			}
			$new_arguments = "$new_width,$new_height";
			break;
		default :
			$new_arguments = $arguments;
	}

	return $new_arguments;
}
add_filter( 'arguments', 'zoom', 10, 3 );

/**
 * crop - ("crop" function via the uri) - crop an image
 *
 * @param (resource)image the source gd image resource
 * @param (string)args "x,y,w,h" widh each csv column being /^[0-9]+(px)?$/
 *                 all values in percentages by default, but can be set
 *                 to absolute pixel values by specifying px ie 25px
 *
 * @return (resource)image the resulting image gd resource
 *
 **/
function crop( &$image, $args ) {
	$args = explode( ',', $args );

	$w = $image->getimagewidth();
	$h = $image->getimageheight();

	if ( substr( $args[2], -2 ) == 'px' )
		$new_w = max( 0, min( $w, intval( $args[2] ) ) );
	else
		$new_w = round( $w * abs( intval( $args[2] ) ) / 100 );

	if ( substr( $args[3], -2 ) == 'px' )
		$new_h = max( 0, min( $h, intval( $args[3] ) ) );
	else
		$new_h = round( $h * abs( intval( $args[3] ) ) / 100 );

	if ( substr( $args[0], -2 ) == 'px' )
		$s_x = intval( $args[0] );
	else
		$s_x = round( $w * abs( intval( $args[0] ) ) / 100 );

	if ( substr( $args[1], -2 ) == 'px' )
		$s_y = intval( $args[1] );
	else
		$s_y = round( $h * abs( intval( $args[1] ) ) / 100 );

	$image->cropimage( $new_w, $new_h, $s_x, $s_y );
}

/**
 * setheight - ( "h" function via the uri ) - resize the image to an explicit height, maintaining its aspect ratio
 *
 * @param (resource)image the source gd image resource
 * @param (string)args "/^[0-9]+%?$/" the new height in pixels, or as a percentage if suffixed with an %
 * @param boolean $upscale Whether to allow upscaling or not, defaults to not allowing.
 *
 * @return (resource) the resulting gs image resource
 **/
function setheight( &$image, $args, $upscale = false ) {
	$w = $image->getimagewidth();
	$h = $image->getimageheight();

	if ( substr( $args, -1 ) == '%' )
		$new_height = round( $h * abs( intval( $args ) ) / 100 );
	else
		$new_height = intval( $args );

	// New height can't be calculated, then bail
	if ( ! $new_height )
		return;
	// New height is greater than original image, but we don't have permission to upscale
	if ( $new_height > $h && ! $upscale )
		return;
	// Sane limit when upscaling
	if ( $new_height > $h && $upscale && $new_height > PHOTON__UPSCALE_MAX_PIXELS )
		return;

	$ratio = $h / $new_height;

	$new_w = round( $w / $ratio );
	$new_h = round( $h / $ratio );
	$s_x = $s_y = 0;

	$image->scaleimage( $new_w, $new_h );
}

/**
 * setwidth - ( "w" function via the uri ) - resize the image to an explicit width, maintaining its aspect ratio
 *
 * @param (resource)image the source gd image resource
 * @param (string)args "/^[0-9]+%?$/" the new width in pixels, or as a percentage if suffixed with an %
 * @param boolean $upscale Whether to allow upscaling or not, defaults to not allowing.
 *
 * @return (resource) the resulting gs image resource
 **/
function setwidth( &$image, $args, $upscale = false ) {
	$w = $image->getimagewidth();
	$h = $image->getimageheight();

	if ( substr( $args, -1 ) == '%' )
		$new_width = round( $w * abs( intval( $args ) ) / 100 );
	else
		$new_width = intval( $args );

	// New width can't be calculated, then bail
	if ( ! $new_width )
		return;
	// New height is greater than original image, but we don't have permission to upscale
	if ( $new_width > $w && ! $upscale )
		return;
	// Sane limit when upscaling
	if ( $new_width > $w && $upscale && $new_width > PHOTON__UPSCALE_MAX_PIXELS )
		return;

	$ratio = $w / $new_width;

	$new_w = round( $w / $ratio );
	$new_h = round( $h / $ratio );
	$s_x = $s_y = 0;

	$image->scaleimage( $new_w, $new_h );
}

/**
 * fit_in_box - ( "fit" function via the uri ) - resize the image to fit it in the dimensions provided, maintaining its aspect ratio
 *
 * @param (resource)image the source gd image resource
 * @param (string)args "(int),(int)" width and height of the box
 *
 * @return (resource) the resulting gs image resource
 **/
function fit_in_box( &$image, $args ) {
	$w = $image->getimagewidth();
	$h = $image->getimageheight();

	list( $end_w, $end_h ) = explode( ',', $args );

	$end_w = abs( intval( $end_w ) );
	$end_h = abs( intval( $end_h ) );

	if ( ( $w == $end_w && $h == $end_h ) ||
		! $end_w || ! $end_h || ( $w < $end_w && $h < $end_h )
	) {
		return;
	}

	$image->scaleimage( $end_w, $end_h, true );
}

/**
 * resize_and_crop - ("resize" function via the uri) - originally by Alex M.
 *
 * Differs from setwidth, setheight, and crop in that you provide a width/height and it resizes to that and then crops off excess
 *
 * @param (resource) image the source gd image resource
 * @param (string) args "w,h" width,height in pixels
 *
 * @return (resource)image the resulting image gd resource
 *
 **/
function resize_and_crop( &$image, $args ) {
	$w = $image->getimagewidth();
	$h = $image->getimageheight();

	list( $end_w, $end_h ) = explode( 'x', $args );

	$end_w = (int) $end_w;
	$end_h = (int) $end_h;

	if ( 0 == $end_w || 0 == $end_h )
		return;

	if ( $end_w > $w && $end_w > PHOTON__UPSCALE_MAX_PIXELS )
		return;

	if ( $end_h > $h && $end_h > PHOTON__UPSCALE_MAX_PIXELS )
		return;

	$ratio_orig = $w / $h;
	$ratio_end = $end_w / $end_h;

	// If the original and new images are proportional (no cropping needed), just do a standard resize
	if ( $ratio_orig == $ratio_end )
		setwidth( $image, $end_w, true );

	// If we need to crop off the sides
	elseif ( $ratio_orig > $ratio_end ) {
		setheight( $image, $end_h, true );
		$x = floor( ( $image->getimagewidth() - $end_w ) / 2 );
		crop( $image, "{$x}px,0px,{$end_w}px,{$end_h}px" );
	}

	// If we need to crop off the top/bottom
	elseif ( $ratio_orig < $ratio_end ) {
		setwidth( $image, $end_w, true );
		$y = floor( ( $image->getimageheight() - $end_h ) / 2 );
		crop( $image, "0px,{$y}px,{$end_w}px,{$end_h}px" );
	}
}

/**
 * unletterbox - ("ulb" function via the uri) - originally by Demitrious K.
 *
 * Removes black letterboxing bands from the top and bottom of an image
 *
 * $param (resource) img the source gd image resource
 * $param (string) args true is the only acceptable argument
 *
 * @return (resource)image the resulting image gd resource
 **/
function unletterbox( &$img, $args ) {
	if ( 'true' !== $args )
		return $img;

	gmagick_to_gd( $img );

	// rgb values averaged per pixel, and then those averaged for the entire row
	$max_value_considered_black = 3;

	$width = imagesx( $img );
	$height = imagesy( $img );

	$first_nonblack_line = null;
	for( $h=0; $h < $height; $h++ ) {
		$line_value = 0;
		for( $w=0; $w < $width; $w++ ) {
			$rgb = imagecolorat( $img, $w, $h );
			$r = ( $rgb >> 16 ) & 0xFF;
			$g = ( $rgb >> 8 ) & 0xFF;
			$b = $rgb & 0xFF;
			$line_value += round( ( $r + $g + $b ) / 3 );
		}
		if ( round( $line_value/$width ) > $max_value_considered_black ) {
			$first_nonblack_line = $h + 1;
			break;
		}
	}
	if ( ! $first_nonblack_line ) {
		gd_to_gmagick( $img );
		return;
	}

	$last_nonblack_line = null;
	for( $h = $height - 1; $h >= 0; $h-- ) {
		$line_value = 0;
		for( $w=0; $w < $width; $w++ ) {
			$rgb = imagecolorat( $img, $w, $h );
			$r = ( $rgb >> 16 ) & 0xFF;
			$g = ( $rgb >> 8 ) & 0xFF;
			$b = $rgb & 0xFF;
			$line_value += round( ( $r + $g + $b ) / 3 );
		}
		if ( round( $line_value / $width ) > $max_value_considered_black ) {
			$last_nonblack_line = $h;
			break;
		}
	}
	if ( ! $last_nonblack_line || $last_nonblack_line <= $first_nonblack_line ) {
		gd_to_gmagick( $img );
		return;
	}

	$args = implode( ',',
		array(
			'0px',
			$first_nonblack_line . 'px',
			$width . 'px',
			( $last_nonblack_line - $first_nonblack_line ) . 'px',
		)
	);
	gd_to_gmagick( $img );
	crop( $img, $args );
}
// {{{ filter($image,$filter)

/**
 * Box resizes an image and fills the background with black
 *
 * @param object $image
 * @param array $args
 */
function letterbox( &$image, $args ) {
	$w = $image->getimagewidth();
	$h = $image->getimageheight();

	list( $end_w, $end_h ) = explode( ',', $args );

	$end_w = abs( intval( $end_w ) );
	$end_h = abs( intval( $end_h ) );

	if ( ( $w == $end_w && $h == $end_h ) ||
		! $end_w || ! $end_h || ( $w < $end_w && $h < $end_h )
	) {
		return;
	}

	$image->scaleimage( $end_w, $end_h, true );

	$new_w = $image->getimagewidth();
	$new_h = $image->getimageheight();
	$border_h = round( ( $end_h - $new_h ) / 2 );
	$border_w = round( ( $end_w - $new_w ) / 2 );

	if ( $border_h > PHOTON__UPSCALE_MAX_PIXELS ||
		$border_w > PHOTON__UPSCALE_MAX_PIXELS )
	{
		return;
	}

	$image->borderimage('#000', $border_w, $border_h );

	// Since we create the borders with rounded values
	// we have to chop any excessive pixels off.
	$crop_x = $border_w * 2 + $new_w - $end_w;
	$crop_y = $border_h * 2 + $new_h - $end_h;
	if ( $crop_x || $crop_y )
		$image->cropimage( $end_w, $end_h, $crop_x, $crop_y );
}

/**
 * filter - ("filter" via the uri) - originally by Alex M.
 *
 * Performs various filters on the image such as grayscale
 * This is only for filters that accept no args
 *
 * @param resource $image The source GD image resource
 * @param string $filter The filter name
 * @return resource The resulting GD imageresource
 **/
function filter( &$image, $filter ) {
	$args = explode( ',', $filter );
	$filter = array_shift( $args );
	gmagick_to_gd( $image );
	switch ( $filter ) {
		case 'negate':
			do_action( 'bump_stats', 'filter_negate' );
			imagefilter( $image, IMG_FILTER_NEGATE );
			break;
		case 'grayscale':
		case 'greyscale':
			do_action( 'bump_stats', 'filter_grayscale' );
			imagefilter( $image, IMG_FILTER_GRAYSCALE );
			break;
		case 'sepia':
			do_action( 'bump_stats', 'filter_sepia' );
			imagefilter( $image, IMG_FILTER_GRAYSCALE );
			imagefilter( $image, IMG_FILTER_COLORIZE, 90, 60, 40 );
			break;
		case 'edgedetect':
			do_action( 'bump_stats', 'filter_edgedetect' );
			imagefilter( $image, IMG_FILTER_EDGEDETECT );
			break;
		case 'emboss':
			do_action( 'bump_stats', 'filter_emboss' );
			imagefilter( $image, IMG_FILTER_EMBOSS );
			break;
		case 'blurgaussian':
			do_action( 'bump_stats', 'filter_blurgaussian' );
			imagefilter( $image, IMG_FILTER_GAUSSIAN_BLUR );
			break;
		case 'blurselective':
			do_action( 'bump_stats', 'filter_blurselective' );
			imagefilter( $image, IMG_FILTER_SELECTIVE_BLUR );
			break;
		case 'meanremoval':
			do_action( 'bump_stats', 'filter_meanremoval' );
			imagefilter( $image, IMG_FILTER_MEAN_REMOVAL );
			break;
	}
	gd_to_gmagick( $image );
}
// }}}
/**
 * brightness - ("brightness" via the uri) - originally by Alex M.
 *
 * Adjusts image brightness (-255 through 255)
 *
 * @param resource $original The source GD image resource
 * @param resource $brightness The brightness adjustment value
 * @return resource The resulting GD imageresource
 **/
function brightness( &$image, $brightness ) {
	$brightness = (int) $brightness;

	gmagick_to_gd( $image );
	imagefilter( $image, IMG_FILTER_BRIGHTNESS, $brightness );
	gd_to_gmagick( $image );
}

/**
 * contrast - ("contrast" via the uri) - originally by Alex M.
 *
 * Adjusts image contrast (-100 through 100)
 *
 * @param resource $original The source GD image resource
 * @param resource $contrast The contrast adjustment value
 * @return resource The resulting GD imageresource
 **/
function contrast( &$image, $contrast ) {
	$contrast = (int) $contrast;

	gmagick_to_gd( $image );
	imagefilter( $image, IMG_FILTER_CONTRAST, $contrast * -1 ); // Make +value increase contrast
	gd_to_gmagick( $image );
}

/**
 * colorize - ("colorize" via the uri) - originally by Alex M.
 *
 * Hues the image to a certain color:  red,green,blue
 *
 * @param resource $original The source GD image resource
 * @param resource $colors A comma seperated rgb value (255,255,255 = white)
 * @return resource The resulting GD imageresource
 **/
function colorize( &$image, $colors ) {
	$colors = explode( ',', $colors );
	$color = array_map( 'intval', $colors );

	$red   = ( !empty($color[0]) ) ? $color[0] : 0;
	$green = ( !empty($color[1]) ) ? $color[1] : 0;
	$blue  = ( !empty($color[2]) ) ? $color[2] : 0;

	gmagick_to_gd( $image );
	imagefilter( $image, IMG_FILTER_COLORIZE, $red, $green, $blue );
	gd_to_gmagick( $image );
}

/**
 * smooth - ("smooth" via the uri) - originally by Alex M.
 *
 * Adjusts image smoothness
 *
 * @param resource $original The source GD image resource
 * @param resource $smoothness The smoothness adjustment value
 * @return resource The resulting GD imageresource
 **/
function smooth( &$image, $smoothness ) {
	gmagick_to_gd( $image );
	imagefilter( $image, IMG_FILTER_SMOOTH, (float) $smoothness );
	gd_to_gmagick( $image );
}

function httpdie( $code='404 Not Found', $message='Error: 404 Not Found' ) {
	$numerical_error_code = preg_replace( '/[^\\d]/', '', $code );
	do_action( 'bump_stats', "http_error-$numerical_error_code" );
	header( 'HTTP/1.1 ' . $code );
	die( $message );
}

function gmagick_to_gd( &$image ) {
	global $type;
	if ( $type == "jpeg" )
		$image->setcompressionquality( 100 );
	$image = imagecreatefromstring( $image->getimageblob() );
}

function gd_to_gmagick( &$image ) {
	global $type;
	ob_start();
	switch( $type ) {
		case 'gif':
			imagegif( $image, null );
			break;
		case 'png':
			imagepng( $image, null, 0 );
			break;
		default:
			imagejpeg( $image, null, 100 );
			break;
	}
	$image = new Imagick();
	$image->readimageblob( ob_get_clean() );
}

function fetch_raw_data( $url, $timeout = 10, $connect_timeout = 2 ) {
	$ch = curl_init( $url );
	curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, $connect_timeout );
	curl_setopt( $ch, CURLOPT_TIMEOUT, $timeout );
	curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
	curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
	curl_setopt( $ch, CURLOPT_MAXREDIRS, 3 );
	curl_setopt( $ch, CURLOPT_USERAGENT, 'Photon/1.0' );
	curl_setopt( $ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );
	curl_setopt( $ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );
/*
	curl_setopt( $ch, CURLOPT_WRITEFUNCTION, function( $curl_handle, $data ) {
		global $raw_data, $raw_data_size, $remote_image_max_size;

		$data_size = strlen( $data );
		$raw_data .= $data;
		$raw_data_size += $data_size;

		if ( $raw_data_size > $remote_image_max_size )
			httpdie( '400 Bad Request', "You can only process images up to $remote_image_max_size bytes." );

		return $data_size;
	} );
*/

	return curl_exec( $ch );
}

function do_a_filter( $function_name, $arguments ) {
	global $image, $allowed_functions, $type;

	if ( ! isset( $allowed_functions[$function_name] ) )
		return;

	$function_name = $allowed_functions[$function_name];
	if ( function_exists( $function_name ) && is_callable( $function_name ) ) {
		do_action( 'bump_stats', $function_name );
		if ( 'gif' == $type ) {
			/* allowed functions have identical names as private members of the Gif_Image class */
			$image->add_function( $function_name, $arguments );
		} else {
			$arguments = apply_filters( 'arguments', $arguments, $function_name, $image );
			$function_name( $image, $arguments );
		}
	}
}

function photon_cache_headers($content_type = 'image/jpeg', $expires = 63115200 ) {
	header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', time() ) . ' GMT' );
	header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + $expires ) . ' GMT' );
	header( 'Cache-Control: public, max-age='.$expires );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Content-Type: ' . $content_type );
}

$relative_path = substr($_SERVER['REQUEST_URI'], strpos($_SERVER['REQUEST_URI'], '/',1));
$absolute_path = realpath(dirname(__FILE__) . "/../uploads/") . $relative_path;
#echo $absolute_path;exit;
if ($pos = strpos($absolute_path, '?')) {
    $absolute_path = substr($absolute_path, 0, $pos);
}
#var_dump($absolute_path);exit;
$raw_data = file_get_contents($absolute_path);
$raw_data_size = strlen($raw_data);

foreach ( $disallowed_file_headers as $file_header ) {
	if ( substr( $raw_data, 0, strlen( $file_header ) ) == $file_header )
		httpdie( '400 Bad Request', 'Error 0002. The type of image you are trying to process is not allowed.' );
}

if ( 'GIF' === substr( $raw_data, 0, 3 ) ) {
	require dirname( __FILE__ ) . '/libgif.php';
	$image = new Gif_Image( $raw_data );
	$type = 'gif';
	if ( 0 === strlen( $_SERVER['QUERY_STRING'] ) ) {
		do_action( 'bump_stats', 'image_gif' );
		photon_cache_headers( 'image/gif' );
		die( $raw_data );
	}
} else {
	try {
		$image = new Imagick();
		$image->readimageblob( $raw_data );
		$type = strtolower( $image->getimageformat() );
	} catch ( ImagickException $e ) {
		httpdie( '400 Bad Request', 'We cannot complete this request, remote data was invalid' );
	}
}

if ( ! in_array( $type, $allowed_types ) )
	httpdie( '400 Bad Request', 'Error 0003. The type of image you are trying to process is not allowed.' );

if ( $type == 'jpeg' )
	$quality = get_jpeg_quality( $raw_data, $raw_data_size );
else
	$quality = 90;
unset( $raw_data );

try {
	// Run through all uri supplied functions which are valid and allowed
	foreach( $_GET as $function_name => $arguments ) {
		if ( !is_array( $arguments ) )
			$arguments = array( $arguments );
		foreach ( $arguments as $argument ) {
			if ( $argument === apply_filters( $function_name, $argument ) )
				do_a_filter( $function_name, $argument );
		}
	}

	switch ( $type ) {
		case 'png':
			do_action( 'bump_stats', 'image_png' );
			$image->setcompressionquality( $quality );
			$tmp = tempnam( $tmpdir, 'OPTIPNG-' );
			$image->writeImage( $tmp );
			$og = filesize( $tmp );
			exec( OPTIPNG . " $tmp" );
			clearstatcache();
			$save = $og - filesize( $tmp );
			do_action( 'bump_stats', 'png_bytes_saved', $save );
			$fp = fopen( $tmp, 'r' );
			photon_cache_headers( 'image/png' );
			header( 'Content-Length: ' . filesize( $tmp ) );
			header( 'X-Bytes-Saved: ' . $save );
			unlink( $tmp );
			fpassthru( $fp );
			break;
		case 'gif':
			do_action( 'bump_stats', 'image_gif' );
			if ( $image->process_image() ) {
				photon_cache_headers( 'image/gif' );
				echo $image->get_imageblob();
			} else {
				httpdie( '400 Bad Request', "Sorry, the parameters you provided were not valid" );
			}
			break;
		default:
			do_action( 'bump_stats', 'image_jpeg' );
			$image->setcompressionquality( $quality );
			$tmp = tempnam( $tmpdir, 'JPEGOPTIM-' );
			$image->writeImage( $tmp );
			$og = filesize( $tmp );
			exec( JPEGOPTIM . " --all-progressive -p $tmp" );
			clearstatcache();
			$save = $og - filesize( $tmp );
			do_action( 'bump_stats', 'jpg_bytes_saved', $save );
			$fp = fopen( $tmp, 'r' );
			photon_cache_headers( );
			header( 'Content-Length: ' . filesize( $tmp ) );
			header( 'X-Bytes-Saved: ' . $save );
			unlink( $tmp );
			fpassthru( $fp );
			break;
	}

} catch ( ImagickException $e ) {
	httpdie( '400 Bad Request', "Sorry, the parameters you provided were not valid" );
}

