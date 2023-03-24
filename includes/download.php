<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'init', 'dae_download_file' );
function dae_download_file() {
    if ( preg_match("/\/download\/([a-f0-9]{64})[\/\?]/", $_SERVER["REQUEST_URI"], $matches) ) {
        $download_hash = $matches[1];
    } else return;

    if ( empty( $download_hash ) || empty( $_GET['email'] ) || empty( $_GET['nonce'] ) ) {
        return;
    }

    // send this early to prevent caching any error
    header( "Cache-Control: no-store, max-age=0" );

    $email = sanitize_email( rawurldecode( $_GET['email'] ) );

    $messages = get_option( 'dae_messages' );

    if ( ! mckp_verify_nonce( $download_hash, $email ) ) {
        die( ! empty( $messages['unvalid_link'] ) ? esc_html( $messages['unvalid_link'] ) : esc_html__( 'This link is no longer available.', 'download-after-email' ) );
    }


    if ( $subscriber = DAE_Subscriber::get_instance( $email ) ) {
        if ( empty( $messages['optional_checkbox'] ) && apply_filters( 'dae_run_integrations', true, $subscriber, $download_hash ) ) {

            if ( class_exists( 'DAE_Integrations' ) ) {
                DAE_Integrations::run( $subscriber );
            }

            do_action( 'dae_download_integrations', $subscriber );

        } else {

            if (
                ( ! empty( $subscriber->meta['optional_checkbox'] )
                || ( ! empty( $subscriber->meta['optin_time'] ) && ! $subscriber->has_used_links ) )
                && apply_filters( 'dae_run_integrations_optional', true, $subscriber, $download_hash )
            ) {

                if ( class_exists( 'DAE_Integrations' ) ) {
                    DAE_Integrations::run( $subscriber );
                }

                do_action( 'dae_download_integrations_optional', $subscriber );

            }

        }

        DAE_Subscriber::update_link( $subscriber->id, $download_hash );

    } else {
        die( ! empty( $messages['download_failed'] ) ? esc_html( $messages['download_failed'] ) : esc_html__( 'This download file could not be found. If this error persists, please feel free to contact me.', 'download-after-email' ) );
    }

    $ok = false;
    $file_hash="";
    foreach ($subscriber->links as $fh => $data) {
        if ( $data['download_hash'] == $download_hash ) {
            $file_hash = $fh;
            $ok = true;
            break;
        }
    }

    if ((! $ok) || ($file_hash=="")) {
        die( ! empty( $messages['download_failed'] ) ? esc_html( $messages['download_failed'] ) : esc_html__( 'This download file could not be found. If this error persists, please feel free to contact me.', 'download-after-email' ) );
    }

    $options = get_option( 'dae_options' );
    if ( ( empty( $options['unlimited_links'] ) ) && ( $subscriber->links[$file_hash]['link_used'] == 'used') ) {
        // check if existing link is still valid
        $validity = ( $options['limit_links'] != 'once' ? (int)$options['limit_links'] : 0 );
        $current_time = current_time( 'timestamp' );
        $link_date = strtotime( $subscriber->links[ $file_hash ]['time'] );
        $expiration_time = $link_date + $options['limit_links'] * 3600;

        // link is expired so disallow access to file and remove nonce
        if ( $current_time > $expiration_time ) {
            mckp_delete_nonce( $download_hash, $email );
            die( ! empty( $messages['unvalid_link'] ) ? esc_html( $messages['unvalid_link'] ) : esc_html__( 'This link is no longer available', 'download-after-email' ) );
        }
    }

    global $wpdb;

    $hash_table = $wpdb->prefix . 'dae_attachment_map';
    $links_table = $wpdb->prefix . 'dae_links';
    $res = $wpdb->get_results( $wpdb->prepare( "SELECT attachment_id FROM ".$links_table." LEFT JOIN ".$hash_table." ON ".$links_table.".file_hash=".$hash_table.".file_hash WHERE ".$links_table.".file_hash = %s", $file_hash ) );
    if ( empty($res) ) {
        die( ! empty( $messages['download_failed'] ) ? esc_html( $messages['download_failed'] ) : esc_html__( 'This download file could not be found. If this error persists, please feel free to contact me.', 'download-after-email' ) );
    }

    $id = $res[0]->attachment_id;

    // first check if file exists locally
    $filename = get_attached_file( $id, true );
    $filepath = $filename;
    if ( ! file_exists( $filename ) ) { // try to download it if possible
        $full_url = wp_get_attachment_url( $id );
        if ( ! function_exists( 'download_url' ) ) {
            require_once( ABSPATH . '/wp-admin/includes/file.php' );
        }

        $filepath = download_url( $full_url );
        if ( is_wp_error( $filepath ) ) {
            die( ! empty( $messages['download_failed'] ) ? esc_html( $messages['download_failed'] ) : esc_html__( 'This download file could not be found. If this error persists, please feel free to contact me.', 'download-after-email' ) );
        }
    }

    $mime_content_type = mime_content_type( $filepath );
    $file_size = filesize( $filepath );

    $base = basename($filename);
    $apparent_fn = substr($base, 0, 3) != "nl-" ? $base : substr($base, 3);

    header( "Content-Disposition: attachment; filename=\"".$apparent_fn."\"" );
    header( "Content-Type: $mime_content_type" );
    header( "Content-Length: $file_size" );
    set_time_limit( 0 );
    readfile($filepath);

    exit;
}

?>