<?php
/*
Plugin Name: BB Multisite Content
Description: Easily push content from one site to others in your multisite network
Version: 0.2
Author: Brown Box
Author URI: http://brownbox.net.au
License: GPLv2
Copyright 2016 Brown Box
*/

require_once('classes/meta_.php');

add_action('admin_init', 'bb_multisite_content_meta');
function bb_multisite_content_meta() {
    if (is_multisite()) {
        $fields = array();

        $blogs = bb_multisite_get_blogs();
        foreach ($blogs as $tmp_blog) {
            $title = str_replace('.'.$_SERVER['HTTP_HOST'], '', $tmp_blog->domain);
            if (!empty($_GET['post'])) {
                $target_id = get_post_meta((int)$_GET['post'], 'post_id_blog_'.$tmp_blog->blog_id, true);
                if ($target_id) {
                    $title .= ' (<a href="http://'.$tmp_blog->domain.'/wp-admin/post.php?post='.$target_id.'&action=edit" target="_blank">#'.$target_id.'</a>)';
                }
            }

            $fields[] = array(
                    'title' => $title,
                    'field_name' => 'push_to_blog_'.$tmp_blog->blog_id,
                    'type' => 'checkbox',
            );
        }
        new bb_multisite_content\metaClass('Content Sharing', bb_multisite_get_post_types(), $fields);
    }
}

function bb_multisite_get_post_types() {
    $skip_types = array(
            'transaction',
            'acf',
            'revision',
    );
    $post_types = get_post_types();
    foreach ($post_types as $idx => $post_type) {
        if (in_array($post_type, $skip_types)) {
            unset($post_types[$idx]);
        }
    }
    return $post_types;
}

add_action('save_post', 'bb_multisite_save_post', 99, 3);
function bb_multisite_save_post($post_id, $post, $update) {
    bb_multisite_push_content($post);
}

add_action('transition_post_status', 'bb_multisite_transition_post_status', 99, 3);
function bb_multisite_transition_post_status($new_status, $old_status, $post) {
    bb_multisite_push_content($post);
}

function bb_multisite_push_content(WP_Post $post) {
    if (!in_array($post->post_type, bb_multisite_get_post_types())) {
        return;
    }
    $post_id = $post->ID;
    unset($post->ID);
    $post_meta = get_post_meta($post_id);
    $post_terms = wp_get_object_terms($post_id, get_object_taxonomies($post));
    if (class_exists('Tax_Meta_Class')) {
        foreach ($post_terms as &$post_term) {
            $post_term->meta = get_option('tax_meta_'.$post_term->term_id);
        }
    }

    /* Featured image handling thanks to MU Featured Images plugin */
    $post_thumbnail = get_post_thumbnail_id($post_id);
    if ($post_thumbnail != false) {
        unset($post_meta['_thumbnail_id'], $post_meta['_ibenic_mufimg_image'], $post_meta['_ibenic_mufimg_src']);
        $imageMetaData = wp_get_attachment_metadata($post_thumbnail);
        $imageFull = wp_get_attachment_image_src($post_thumbnail, "full");
        $imgURL = $imageFull[0];
        $images["full"] = array(
                "url" => $imgURL,
                "width" => $imageMetaData["width"],
                "height" => $imageMetaData["height"]
        );
        foreach ($imageMetaData["sizes"] as $size => $sizeInfo) {
            $image = wp_get_attachment_image_src($post_thumbnail, $size);

            $images[$size] = array(
                    "url" => $image[0],
                    "width" => $sizeInfo["width"],
                    "height" => $sizeInfo["height"]
            );
        }
    }

    global $blog_id;
    $blogs = bb_multisite_get_blogs();

    // Remove some actions that will cause us headaches
    remove_action('save_post', 'bb_multisite_save_post', 99);
    remove_action('transition_post_status', 'bb_multisite_transition_post_status', 99);
    remove_action('added_post_meta', 'bb_multisite_push_meta', 10, 4);
    remove_action('updated_post_meta', 'bb_multisite_push_meta', 10);

    foreach ($blogs as $tmp_blog) {
        if ($_POST['push_to_blog_'.$tmp_blog->blog_id] == 'true') {
            unset($_POST['push_to_blog_'.$tmp_blog->blog_id]);

            if ($post->post_parent > 0) {
                $target_parent_id = get_post_meta($post->post_parent, 'post_id_blog_'.$tmp_blog->blog_id, true);
                if ($target_parent_id) {
                    $post->post_parent = $target_parent_id;
                }
            }
            $maybe_target_id = $post_meta['post_id_blog_'.$tmp_blog->blog_id][0];
            if (!empty($maybe_target_id)) { // Already pushed
                switch_to_blog($tmp_blog->blog_id);
                if (get_post($maybe_target_id)) { // Make sure it still exists
                    $target_id = $maybe_target_id;
                    restore_current_blog();
                    if (bb_multisite_can_update_post($post_id, $tmp_blog->blog_id, $target_id)) {
                        switch_to_blog($tmp_blog->blog_id);
                        $new_post = clone $post;
                        $new_post->ID = $target_id;
                        wp_update_post($new_post);

                        foreach ($post_meta as $meta_key => $meta_value) {
                            update_post_meta($target_id, $meta_key, $meta_value[0]);
                        }

                        wp_delete_object_term_relationships($target_id, get_object_taxonomies($new_post));
                        foreach ($post_terms as $term) {
                            if (!term_exists($term->slug, $term->taxonomy)) {
                                $term_details = wp_insert_term($term->name, $term->taxonomy, (array)$term);
                            }
                            if (!empty($term->meta)) {
                                update_option('tax_meta_'.$term_details['term_id'], $term->meta);
                            }
                            wp_set_object_terms($target_id, $term->slug, $term->taxonomy, true);
                        }

                        if (!empty($images)) { // Featured image handling
                            update_post_meta($target_id, '_ibenic_mufimg_image', $images);
                            update_post_meta($target_id, '_ibenic_mufimg_src', $imgURL);
                            update_post_meta($target_id, '_thumbnail_id', "1");
                        }

                        restore_current_blog();
                    }
                } else {
                    restore_current_blog();
                }
            }

            if (empty($target_id)) { // Need to create new post
                switch_to_blog($tmp_blog->blog_id);
                $new_id = wp_insert_post($post);
                foreach ($post_meta as $meta_key => $meta_value) {
                    update_post_meta($new_id, $meta_key, $meta_value[0]);
                }
                foreach ($post_terms as $term) {
                    if (!term_exists($term->slug, $term->taxonomy)) {
                        wp_insert_term($term->name, $term->taxonomy, (array)$term);
                    }
                    if (!empty($term->meta)) {
                        update_option('tax_meta_'.$term_details['term_id'], $term->meta);
                    }
                    wp_set_object_terms($new_id, $term->slug, $term->taxonomy, true);
                }
                if (!empty($images)) { // Featured image handling
                    update_post_meta($new_id, '_ibenic_mufimg_image', $images);
                    update_post_meta($new_id, '_ibenic_mufimg_src', $imgURL);
                    update_post_meta($new_id, '_thumbnail_id', "1");
                }
                restore_current_blog();
                update_post_meta($post_id, 'post_id_blog_'.$tmp_blog->blog_id, $new_id);
            }
        }
    }

    // Reinstate actions
    add_action('save_post', 'bb_multisite_save_post', 99, 3);
    add_action('transition_post_status', 'bb_multisite_transition_post_status', 99, 3);
    add_action('added_post_meta', 'bb_multisite_push_meta', 10, 4);
    add_action('updated_post_meta', 'bb_multisite_push_meta', 10, 4);
}

add_action('added_post_meta', 'bb_multisite_push_meta', 10, 4);
add_action('updated_post_meta', 'bb_multisite_push_meta', 10, 4);
function bb_multisite_push_meta($meta_id, $object_id, $meta_key, $meta_value) {
    if (strpos($meta_key, 'post_id_blog_') === false) {
        $post_meta = get_post_meta($object_id);
        foreach ($post_meta as $mk => $mv) {
            if (strpos($mk, 'post_id_blog_') !== false) {
                $tmp_blog_id = str_replace('post_id_blog_', '', $mk);
                $target_post_id = $mv[0];
                if (bb_multisite_can_update_post($object_id, $tmp_blog_id, $target_post_id)) {
                    switch_to_blog($tmp_blog_id);
                    remove_action('updated_post_meta', 'bb_multisite_push_meta', 10);
                    update_post_meta($target_post_id, $meta_key, $meta_value);
                    add_action('updated_post_meta', 'bb_multisite_push_meta', 10, 4);
                    restore_current_blog();
                }
            }
        }
    }
}

// add_action('set_object_terms', 'bb_multisite_set_object_terms', 10, 6);
// function bb_multisite_set_object_terms($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids) {

// }

add_action('edit_term', 'bb_multisite_push_term', 10, 3);
function bb_multisite_push_term($term_id, $tt_id, $taxonomy) {
    $term = get_term($term_id, $taxonomy);
    $term_slug = $term->slug;
    $term_details = array(
            'name' => $term->name,
            'slug' => $term->slug,
            'description' => $term->description,
    );
    if (class_exists('Tax_Meta_Class')) {
        $term_meta = get_option('tax_meta_'.$term_id);
    }
    global $blog_id;
    $blogs = bb_multisite_get_blogs();

    remove_action('edit_term', 'bb_multisite_push_term', 10);
    foreach ($blogs as $tmp_blog) {
        switch_to_blog($tmp_blog->blog_id);
        $blog_term = term_exists($term_slug, $taxonomy);
        if (!is_null($blog_term)) {
            $result = wp_update_term((int)$blog_term['term_id'], $taxonomy, $term_details);
            if (!empty($term_meta)) {
                update_option('tax_meta_'.$blog_term['term_id'], $term_meta);
            }
        }
        restore_current_blog();
    }
    add_action('edit_term', 'bb_multisite_push_term', 10, 3);
}

add_action('updated_option', 'bb_multisite_push_term_meta', 10, 3);
function bb_multisite_push_term_meta($option, $old_value, $value) {
    if (strpos($option, 'tax_meta_') !== false && class_exists('Tax_Meta_Class')) {
        $term_id = str_replace('tax_meta_', '', $option);
        $term = get_term($term_id);
        $taxonomy = $term->taxonomy;
        $term_slug = $term->slug;
        global $blog_id;
        $blogs = bb_multisite_get_blogs();

        remove_action('updated_option', 'bb_multisite_push_term_meta', 10);
        foreach ($blogs as $tmp_blog) {
            switch_to_blog($tmp_blog->blog_id);
            $blog_term = term_exists($term_slug, $taxonomy);
            if (!is_null($blog_term)) {
                update_option('tax_meta_'.$blog_term['term_id'], $value);
            }
            restore_current_blog();
        }
        add_action('updated_option', 'bb_multisite_push_term_meta', 10, 3);
    }
}

function bb_multisite_get_blogs() {
    global $wpdb, $blog_id;
    $blogs = $wpdb->get_results("SELECT * FROM $wpdb->blogs WHERE archived = 0 AND spam = 0 AND deleted = 0", OBJECT);
    foreach ($blogs as $idx => $tmp_blog) {
        if ($tmp_blog->blog_id == $blog_id) {
            unset($blogs[$idx]);
            break;
        }
    }
    return $blogs;
}

function bb_multisite_can_update_post($from_post_id, $to_blog_id, $to_post_id) {
    $from_post = get_post($from_post_id);
    switch_to_blog($to_blog_id);
    $to_post = get_post($to_post_id);
    restore_current_blog();

    return $from_post->post_type == $to_post->post_type && $from_post->post_author == $to_post->post_author;
}
