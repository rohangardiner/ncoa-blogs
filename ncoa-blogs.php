<?php

/**
 * Plugin Name: NCOA Blogs
 * Description: Blog posting for NOCA networked sites
 * Version: 0.1.0
 * Author: Rohan
 */

// Create endppoint
add_action('rest_api_init', function () {
   register_rest_route('ncoa-blogs/v1', '/create', array(
      'methods' => 'POST',
      'callback' => 'ncoa_create_blog_post',
      'permission_callback' => 'ncoa_check_token',
   ));
});

// Callback to check Authorization Bearer token matches expected const defined in wp-config
function ncoa_check_token(WP_REST_Request $request) {
   $headers = $request->get_headers();
   $auth_header = isset($headers['authorization']) ? $headers['authorization'] : '';
   $expected_token = 'Bearer ' . BLOG_TOKEN;

   if ($auth_header[0] == $expected_token) {
      return true;
   } else {
      return new WP_Error('rest_forbidden', __('Incorrect authorisation', 'ncoa-blogs'), array('status' => 401));
   }
}

// Create post
function ncoa_create_blog_post($post_data) {
   $post_id = wp_insert_post([
      'post_title'   => sanitize_text_field($post_data['title']),
      'post_content' => wp_kses_post($post_data['content']),
      'post_status'  => 'publish',
      'post_author'  => 1,
      'post_type'    => 'post'
   ]);
   // Set featured image for this post from the provided url
   set_post_thumbnail($post_id, ncoa_upload_image_from_url($post_data['image'], $post_id, $post_data['title']));

   if ($post_id && !is_wp_error($post_id)) {
      return rest_ensure_response(['created' => $post_id]);
   } else {
      return rest_ensure_response(['error' => 'Post could not be created']);
   }
}

// Grab image from provided URL and save it as a WordPress attachment
function ncoa_upload_image_from_url($image_url, $post_id = 0, $desc = null, $return_type = 'id') {
   require_once(ABSPATH . 'wp-admin/includes/file.php');
   require_once(ABSPATH . 'wp-admin/includes/media.php');
   require_once(ABSPATH . 'wp-admin/includes/image.php');
   $attachment_id = media_sideload_image($image_url, $post_id, $desc, $return_type);
   if (is_wp_error($attachment_id)) {
      error_log('Error sideloading image: ' . $attachment_id->get_error_message());
      return false;
   }
   return $attachment_id;
}

// Plugin updater
if (is_admin()) {
   include_once('updater.php');
   $config = array(
      'slug' => plugin_basename(__FILE__), // this is the slug of your plugin
      'proper_folder_name' => 'plugin-name', // this is the name of the folder your plugin lives in
      'api_url' => 'https://api.github.com/repos/username/repository-name', // the GitHub API url of your GitHub repo
      'raw_url' => 'https://raw.github.com/username/repository-name/master', // the GitHub raw url of your GitHub repo
      'github_url' => 'https://github.com/username/repository-name', // the GitHub url of your GitHub repo
      'zip_url' => 'https://github.com/username/repository-name/zipball/master', // the zip url of the GitHub repo
      'sslverify' => true, // whether WP should check the validity of the SSL cert when getting an update, see https://github.com/jkudish/WordPress-GitHub-Plugin-Updater/issues/2 and https://github.com/jkudish/WordPress-GitHub-Plugin-Updater/issues/4 for details
      'requires' => '3.0', // which version of WordPress does your plugin require?
      'tested' => '3.3', // which version of WordPress is your plugin tested up to?
      'readme' => 'README.md', // which file to use as the readme for the version number
      'access_token' => '', // Access private repositories by authorizing under Plugins > GitHub Updates when this example plugin is installed
   );
   new WP_GitHub_Updater($config);
}
