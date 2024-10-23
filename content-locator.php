<?php
/*
Plugin Name: Content Locator
Description: Locates and lists various content types used across pages and posts, including Gutenberg blocks, ACF fields, shortcodes, and other content elements. Features expandable rows for viewing associated pages and content details.
Version: 1.1
Author: David Macfarlane
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Add admin menu item
add_action( 'admin_menu', 'content_locator_menu' );

function content_locator_menu() {
    add_menu_page(
        'Content Locator',
        'Content Locator',
        'manage_options',
        'content-locator',
        'content_locator_admin_page',
        'dashicons-search',
        20
    );
}

// Enqueue JavaScript for tabs and expandable rows
add_action( 'admin_enqueue_scripts', 'content_locator_enqueue_scripts' );

function content_locator_enqueue_scripts( $hook ) {
    if ( 'toplevel_page_content-locator' !== $hook ) {
        return;
    }
    wp_enqueue_style( 'wp-jquery-ui-dialog' ); // Use built-in jQuery UI CSS for WP admin

    // Inline script for handling the spinner, tabs, and expandable rows
    wp_add_inline_script( 'jquery-core', "
        jQuery(document).ready(function($) {
            $('#loading-spinner').show(); // Show the spinner initially

            // Hide the spinner once the content is fully loaded
            $(window).on('load', function() {
                $('#loading-spinner').fadeOut();
            });

            $('.nav-tab').click(function() {
                var tabId = $(this).attr('data-tab');
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                $('.tab-content').hide();
                $('#' + tabId).show();
            });

            // Show the first tab by default
            $('.nav-tab').first().addClass('nav-tab-active');
            $('.tab-content').first().show();

            // Toggle visibility for expandable rows
            $('.expandable-row').click(function() {
                $(this).nextUntil('.expandable-row').toggle();
            });
        });
    " );
}

// Get the title of a reusable block by its reference ID
function get_reusable_block_title( $ref_id ) {
    $reusable_block = get_post( $ref_id );
    return $reusable_block ? $reusable_block->post_title : 'Unknown Pattern';
}

// Get all ACF True/False and Checkbox fields with value '1' or 'yes' along with their Field Groups
function get_acf_true_false_and_checkbox_fields() {
    $acf_fields = [];
    if ( function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) ) {
        $field_groups = acf_get_field_groups();
        foreach ( $field_groups as $group ) {
            $fields = acf_get_fields( $group['key'] );
            if ( $fields ) {
                foreach ( $fields as $field ) {
                    if ( $field['type'] === 'true_false' || $field['type'] === 'checkbox' ) {
                        global $wpdb;
                        $meta_query = "
                            SELECT post_id, meta_value
                            FROM {$wpdb->postmeta}
                            WHERE meta_key = %s
                        ";
                        $prepared_query = $wpdb->prepare( $meta_query, $field['name'] );
                        $meta_results = $wpdb->get_results( $prepared_query );

                        if ( ! empty( $meta_results ) ) {
                            foreach ( $meta_results as $meta ) {
                                $value = maybe_unserialize( $meta->meta_value );
                                if ( $value === '1' || $value === 'yes' || ( is_array( $value ) && ( in_array( '1', $value ) || in_array( 'yes', $value ) ) ) ) {
                                    $post = get_post( $meta->post_id );
                                    if ( $post && in_array( $post->post_type, ['post', 'page'] ) && $post->post_status !== 'inherit' ) {
                                        $field_label = $field['label'] . ' (' . $group['title'] . ')';
                                        $acf_fields[ $field_label ][] = [
                                            'title'  => $post->post_title,
                                            'id'     => $post->ID,
                                            'type'   => $post->post_type,
                                            'status' => $post->post_status,
                                            'count'  => 1
                                        ];
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    return $acf_fields;
}

// Admin page content
function content_locator_admin_page() {
    global $wpdb;

    // Get all registered ACF true/false and checkbox fields
    $acf_true_false_fields = get_acf_true_false_and_checkbox_fields();

    // Query to find all posts containing Gutenberg blocks or shortcodes, restricted to pages and posts
    $query = "
        SELECT ID, post_title, post_type, post_status, post_content
        FROM {$wpdb->posts}
        WHERE (post_content LIKE '%<!-- wp:%' OR post_content LIKE '%[%')
        AND post_status NOT IN ('inherit', 'auto-draft', 'trash')
        AND post_type IN ('post', 'page')
    ";

    $results = $wpdb->get_results( $query );

    // Arrays to track different block types
    $patterns = [];
    $shortcodes = [];
    $acf_blocks = [];
    $native_blocks = [];
    $custom_native_blocks = [];
    $used_acf_blocks = [];

    // Parse each post to categorize blocks
    if ( ! empty( $results ) ) {
        foreach ( $results as $post ) {
            preg_match_all( '/<!-- wp:([a-zA-Z0-9-\/]+)(?: {"ref":(\d+)})? /', $post->post_content, $matches, PREG_SET_ORDER );
            if ( ! empty( $matches ) ) {
                foreach ( $matches as $match ) {
                    $block_name = $match[1];
                    $ref_id = isset( $match[2] ) ? $match[2] : null;
                    $entry_key = $post->ID;
                    $entry = [
                        'title'  => $post->post_title,
                        'id'     => $post->ID,
                        'type'   => $post->post_type,
                        'status' => $post->post_status,
                        'count'  => 1
                    ];

                    // Patterns (Reusable blocks)
                    if ( $block_name === 'block' && $ref_id ) {
                        $pattern_title = get_reusable_block_title( $ref_id );
                        if ( isset( $patterns[ $pattern_title ][ $entry_key ] ) ) {
                            $patterns[ $pattern_title ][ $entry_key ]['count']++;
                        } else {
                            $patterns[ $pattern_title ][ $entry_key ] = $entry;
                        }
                        continue;
                    }

                    // ACF Blocks
                    if ( strpos( $block_name, 'acf/' ) === 0 ) {
                        if ( isset( $acf_blocks[ $block_name ][ $entry_key ] ) ) {
                            $acf_blocks[ $block_name ][ $entry_key ]['count']++;
                        } else {
                            $acf_blocks[ $block_name ][ $entry_key ] = $entry;
                        }
                        $used_acf_blocks[ $block_name ] = true;
                        continue;
                    }

                    // Custom Native Blocks (with a '/' but not starting with 'acf/')
                    if ( strpos( $block_name, '/' ) !== false && strpos( $block_name, 'acf/' ) !== 0 ) {
                        if ( isset( $custom_native_blocks[ $block_name ][ $entry_key ] ) ) {
                            $custom_native_blocks[ $block_name ][ $entry_key ]['count']++;
                        } else {
                            $custom_native_blocks[ $block_name ][ $entry_key ] = $entry;
                        }
                        continue;
                    }

                    // Native Blocks (no '/' in the name)
                    if ( strpos( $block_name, '/' ) === false ) {
                        if ( isset( $native_blocks[ $block_name ][ $entry_key ] ) ) {
                            $native_blocks[ $block_name ][ $entry_key ]['count']++;
                        } else {
                            $native_blocks[ $block_name ][ $entry_key ] = $entry;
                        }
                        continue;
                    }
                }
            }

            // Shortcodes detection
            if ( preg_match_all( '/\[(\w+)[^\]]*\]/', $post->post_content, $shortcode_matches ) ) {
                foreach ( $shortcode_matches[0] as $shortcode ) {
                    $entry_key = $post->ID;
                    if ( isset( $shortcodes[ $shortcode ][ $entry_key ] ) ) {
                        $shortcodes[ $shortcode ][ $entry_key ]['count']++;
                    } else {
                        $shortcodes[ $shortcode ][ $entry_key ] = [
                            'title'    => $post->post_title,
                            'id'       => $post->ID,
                            'type'     => $post->post_type,
                            'status'   => $post->post_status,
                            'count'    => 1
                        ];
                    }
                }
            }
        }
    }

    echo '<div class="wrap">';
    echo '<h1>Content Locator</h1>';
    echo '<div id="loading-spinner" style="display: block; text-align: center; padding: 20px;"><img src="' . esc_url( admin_url( 'images/spinner-2x.gif' ) ) . '" alt="Loading..." /></div>'; // Loading spinner

    // Tabs navigation
    echo '<h2 class="nav-tab-wrapper" style="border-bottom: none;">';
    echo '<a href="#" class="nav-tab" data-tab="native-blocks-tab">Native Blocks</a>';
    echo '<a href="#" class="nav-tab" data-tab="custom-native-blocks-tab">Custom Blocks</a>';
    echo '<a href="#" class="nav-tab" data-tab="acf-blocks-tab">ACF Blocks</a>';
    echo '<a href="#" class="nav-tab" data-tab="true-false-fields-tab">True/False ACF Fields</a>';
    echo '<a href="#" class="nav-tab" data-tab="patterns-tab">Patterns</a>';
    echo '<a href="#" class="nav-tab" data-tab="shortcodes-tab">Shortcodes</a>';
    echo '</h2>';

    // Render expandable tables for each tab
    render_expandable_table('Patterns', $patterns, 'patterns-tab');
    render_expandable_table('Shortcodes', $shortcodes, 'shortcodes-tab');
    render_expandable_table('Used ACF Blocks', $acf_blocks, 'acf-blocks-tab');
    render_expandable_table('Native Blocks', $native_blocks, 'native-blocks-tab');
    render_expandable_table('Custom Native', $custom_native_blocks, 'custom-native-blocks-tab');
    render_expandable_table('True/False ACF Fields', $acf_true_false_fields, 'true-false-fields-tab');

    echo '</div>';
}

// Render expandable table for a tab
function render_expandable_table( $title, $items, $tab_id ) {
    echo '<div id="' . esc_attr( $tab_id ) . '" class="tab-content" style="display: none;">';
    if ( empty( $items ) ) {
        echo '<p>No ' . esc_html( $title ) . ' found.</p>';
        return;
    }
    echo '<table class="widefat fixed striped" cellspacing="0">';
    echo '<thead><tr><th style="width: 480px;">Block Name / Page Title</th><th>Count</th><th>Post Type</th><th>Status</th><th>Actions</th></tr></thead><tbody>';

    ksort( $items );
    foreach ( $items as $block_name => $pages ) {
        $total_count = array_sum( array_column( $pages, 'count' ) );
        echo '<tr class="expandable-row" style="cursor: pointer; background-color: #f1f1f1; font-weight: 500;">';
        echo '<td>' . esc_html( $block_name ) . '</td>';
        echo '<td colspan="4">' . $total_count . '</td>';
        echo '</tr>';

        foreach ( $pages as $page ) {
            echo '<tr class="expandable-content" style="display: none;">';
            echo '<td>' . esc_html( $page['title'] ) . '</td>';
            echo '<td>' . esc_html( $page['count'] ) . '</td>';
            echo '<td>' . esc_html( $page['type'] ) . '</td>';
            echo '<td>' . esc_html( $page['status'] ) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url( get_permalink( $page['id'] ) ) . '" target="_blank">View</a> | ';
            echo '<a href="' . esc_url( get_edit_post_link( $page['id'] ) ) . '">Edit</a>';
            echo '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table>';
    echo '</div>';
}
