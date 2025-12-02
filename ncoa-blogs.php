<?php

/**
 * Plugin Name: NCOA Blogs
 * Description: Blog posting for NCOA networked sites
 * Version: 0.3.15
 * Author: Rohan
 * Requires at least: 6.0
 * Tested up to: 6.8.2
 */

// Register activation hook to initialize options
register_activation_hook(__FILE__, 'ncoa_blogs_activate');
function ncoa_blogs_activate() {
   // Initialize the option if it doesn't exist
   if (get_option('ncoa_blog_post_status') === false) {
      add_option('ncoa_blog_post_status', 'draft');
   }
}

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
      error_log('NCOA Blogs Authorisation failed. Provided: ' . $auth_header[0]);
      return new WP_Error('rest_forbidden', __('Incorrect authorisation', 'ncoa-blogs'), array('status' => 401));
   }
}

// Create post
function ncoa_create_blog_post($post_data) {
   // Determine post status from option (Tools > NCOA Blogs). Default to 'publish'.
   $allowed_status = array('publish', 'draft');
   $option_status = get_option('ncoa_blog_post_status', 'draft');
   if (! in_array($option_status, $allowed_status, true)) {
      $option_status = 'draft';
   }

   // Get the selected post author from settings. Default to 1.
   $post_author = absint(get_option('ncoa_blog_post_author', 1));

   $post_id = wp_insert_post([
      'post_title'   => sanitize_text_field($post_data['title']),
      'post_content' => wp_kses_post($post_data['content']) . '<div class="src">' . $post_data["source"] . '</div>',
      'post_status'  => $option_status,
      'post_author'  => $post_author,
      'post_type'    => 'post',
      'tags_input'   => $post_data['pillars'],
   ]);
   // Set featured image for this post from the provided url
   set_post_thumbnail($post_id, ncoa_upload_image_from_url($post_data['image'], $post_id, $post_data['title']));

   // Set SEO title and meta description
   $seo_title = $post_data['seo_title'] ?? $post_data['post_title'];
   $seo_description = $post_data['seo_description'] ?? wp_trim_words(strip_tags($post_data['post_content']), 30, '...');
   ncoa_update_seo_meta($post_id, $seo_title, $seo_description);

   // Return response
   if ($post_id && !is_wp_error($post_id)) {
      return rest_ensure_response('success');
   } else {
      error_log('NCOA Blogs Error creating post: ' . $post_id->get_error_message());
      return rest_ensure_response(['error' => 'Post could not be created']);
   }
}

// Set SEO title and meta description
function ncoa_update_seo_meta($post_id, $seo_title, $seo_description) {
   // Check for Yoast or Rank Math
   if (in_array('wordpress-seo/wp-seo.php', apply_filters('active_plugins', get_option('active_plugins')))) {
      update_post_meta($post_id, '_yoast_wpseo_title', $seo_title);
      update_post_meta($post_id, '_yoast_wpseo_metadesc', $seo_description);
   }

   if (in_array('seo-by-rank-math/rank-math.php', apply_filters('active_plugins', get_option('active_plugins')))) {
      // Update Rank Math SEO title and description
      update_post_meta($post_id, 'rank_math_title', $seo_title . ' %sep% %sitename%');
      update_post_meta($post_id, 'rank_math_description', $seo_description);
   }
}

// Grab image from provided URL and save it as a WordPress attachment
function ncoa_upload_image_from_url($image_url, $post_id = 0, $desc = null, $return_type = 'id') {
   require_once(ABSPATH . 'wp-admin/includes/file.php');
   require_once(ABSPATH . 'wp-admin/includes/media.php');
   require_once(ABSPATH . 'wp-admin/includes/image.php');
   $attachment_id = media_sideload_image($image_url, $post_id, $desc, $return_type);
   if (is_wp_error($attachment_id)) {
      error_log('NCOA Blogs Error sideloading image: ' . $attachment_id->get_error_message());
      return false;
   }
   return $attachment_id;
}

// Add shortcode to display post featured image
add_shortcode('image', 'ncoa_blog_image');
function ncoa_blog_image($atts) {
   if (has_post_thumbnail()) {
      $img_url = get_the_post_thumbnail_url(get_the_ID(), 'large');
      return '<img src="' . esc_url($img_url) . '" alt="' . esc_attr(get_the_title()) . '" class="blog-image"/  >';
   }
   return '';
}

// Add shortcode to display banner content
add_shortcode('blogbanner', 'ncoa_blog_banner');
function ncoa_blog_banner($atts = array(), $content = null) {
   $atts = shortcode_atts(array(
      'background' => '',
   ), $atts, 'blogbanner');

   $background = $atts['background'];
   if (empty($background)) {
      $background = get_option('ncoa_blog_banner_bg', plugin_dir_url(__FILE__) . 'assets/default-bg.jpg');
   }
   // Ensure URL is escaped for output
   $background = esc_url($background);

   $gradient = "linear-gradient(to bottom, rgba(0, 0, 0, 0) 0%, rgba(0, 0, 0, 0) 70%, rgba(0, 0, 0, 0.1) 100%)";
   $output = '<div class="blog-banner" style="background-image: ' . esc_attr($gradient) . ', url(\'' . esc_url($background) . '\');">' . do_shortcode($content) . '</div>';
   return $output;
}

// Add shortcode to display Google-style related question dropdowns
add_shortcode('relatedpillars', 'ncoa_related_pillars');
function ncoa_related_pillars() {
   $post_tags = get_the_tags();
   if (empty($post_tags)) {
      return;
   }
   $tag_ids = array();
   foreach ($post_tags as $tag) {
      $tag_ids[] = $tag->term_id;
   }

   $output = '';

   global $post; // Refer to current post object to get ID to exclude
   $args = array(
      'post_type' => 'post',
      'post_status' => 'publish',
      'tag__in' => $tag_ids,
      'posts_per_page' => -1,
      'post__not_in' => array($post->ID)
   );
   $query = new WP_Query($args);

   if ($query->have_posts()) {
      $output .= '<div class="blog-related">';
      $output .= '<h3>People also ask</h3>';
      while ($query->have_posts()) {
         $query->the_post();
         $current_post_id = get_the_ID();
         $output .= '<details>
            <summary>' . get_the_title($current_post_id) . '</summary>
            <p class="blog-related-content">' . get_the_excerpt($current_post_id) . '</p>
            <div class="blog-related-links">
               <img src="' . get_the_post_thumbnail_url($current_post_id, 'thumbnail') . '" class="blog-related-thumb">
               <div class="blog-related-col">
                  <span class="blog-related-url">' . get_permalink($current_post_id) . '</span>
                  <a class="blog-related-title" href="' . get_permalink($current_post_id) . '">' . get_the_title($current_post_id) . '</a>
               </div>
            </div>
         </details>';
      }
      wp_reset_postdata(); // Restore original post data
      $output .= '</div>';
   }
   return $output;
}

// Handle plugin updating
add_action('init', 'ncoa_plugin_updater_init');
function ncoa_plugin_updater_init() {
   include_once 'updater.php';

   if (! defined('WP_GITHUB_FORCE_UPDATE')) {
      define('WP_GITHUB_FORCE_UPDATE', true);
   }

   if (is_admin()) {
      $config = array(
         'slug' => plugin_basename(__FILE__),
         'proper_folder_name' => 'ncoa-blogs', // this is the name of the folder your plugin lives in
         'api_url' => 'https://api.github.com/repos/rohangardiner/ncoa-blogs', // the GitHub API url of your GitHub repo
         'raw_url' => 'https://raw.github.com/rohangardiner/ncoa-blogs/main', // the GitHub raw url of your GitHub repo
         'github_url' => 'https://github.com/rohangardiner/ncoa-blogs', // the GitHub url of your GitHub repo
         'zip_url' => 'https://github.com/rohangardiner/ncoa-blogs/zipball/main', // the zip url of the GitHub repo
         'sslverify' => true, // whether WP should check the validity of the SSL cert when getting an update, see https://github.com/jkudish/WordPress-GitHub-Plugin-Updater/issues/2 and https://github.com/jkudish/WordPress-GitHub-Plugin-Updater/issues/4 for details
         'requires' => '6.0', // which version of WordPress does your plugin require?
         'tested' => '6.8.2', // which version of WordPress is your plugin tested up to?
         'readme' => 'readme.md', // which file to use as the readme for the version number
         'access_token' => '', // Access private repositories by authorizing under Plugins > GitHub Updates when this example plugin is installed
      );
      new WP_GitHub_Updater_Ncoa_Blogs($config);
   }
}

// Handle loading translations
add_action('plugins_loaded', 'ncoa_load_textdomain');
function ncoa_load_textdomain() {
   load_plugin_textdomain('ncoa-blogs', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}

// Handle enqueueing styles
add_action('wp_enqueue_scripts', 'ncoa_blog_styles');
function ncoa_blog_styles() {
   wp_enqueue_style('ncoa-blog-style', plugin_dir_url(__FILE__) . '/ncoa-blog-styles.css', array(), '1.0.0', 'all');
}

// -------------------------
// Admin settings: Tools > NCOA Blogs
// -------------------------

add_action('admin_menu', 'ncoa_blogs_admin_menu');
function ncoa_blogs_admin_menu() {
   // Places page under Tools
   add_management_page(
      __('NCOA Blogs', 'ncoa-blogs'),
      __('NCOA Blogs', 'ncoa-blogs'),
      'manage_options',
      'ncoa-blogs',
      'ncoa_blogs_settings_page'
   );
}

add_action('admin_init', 'ncoa_blogs_settings_init');
function ncoa_blogs_settings_init() {
   register_setting('ncoa_blogs_settings', 'ncoa_blog_post_status', array(
      'type' => 'string',
      'sanitize_callback' => 'ncoa_sanitize_post_status',
      'default' => 'draft',
   ));

   register_setting('ncoa_blogs_settings', 'ncoa_blog_post_author', array(
      'type' => 'integer',
      'sanitize_callback' => 'ncoa_sanitize_post_author',
      'default' => 1,
   ));

   register_setting('ncoa_blogs_settings', 'ncoa_blog_banner_bg', array(
      'type' => 'string',
      'sanitize_callback' => 'ncoa_sanitize_blog_banner_bg',
      'default' => plugin_dir_url(__FILE__) . 'assets/default-bg.jpg',
   ));

   add_settings_section(
      'ncoa_blogs_main_section',
      __('Settings', 'ncoa-blogs'),
      'ncoa_blogs_main_section_cb',
      'ncoa-blogs'
   );

   add_settings_field(
      'ncoa_blog_post_status',
      __('Blog post status', 'ncoa-blogs'),
      'ncoa_blog_post_status_field_cb',
      'ncoa-blogs',
      'ncoa_blogs_main_section'
   );

   add_settings_field(
      'ncoa_blog_post_author',
      __('Blog post author', 'ncoa-blogs'),
      'ncoa_blog_post_author_field_cb',
      'ncoa-blogs',
      'ncoa_blogs_main_section'
   );

   add_settings_field(
      'ncoa_blog_banner_bg',
      __('Blog banner background', 'ncoa-blogs'),
      'ncoa_blog_banner_bg_field_cb',
      'ncoa-blogs',
      'ncoa_blogs_main_section'
   );
}

function ncoa_blogs_main_section_cb() {
   echo '<p>' . esc_html__('Control the default settings when creating blog posts via the NCOA REST endpoint.', 'ncoa-blogs') . '</p>';
}

function ncoa_sanitize_post_status($val) {
   $val = sanitize_text_field($val);
   $allowed = array('publish', 'draft');
   if (in_array($val, $allowed, true)) {
      return $val;
   }
   return 'draft';
}

function ncoa_sanitize_post_author($val) {
   $val = absint($val);
   $user = get_userdata($val);
   if ($user) {
      return $val;
   }
   return 1;
}

function ncoa_sanitize_blog_banner_bg($val) {
   // Normalize and validate a URL for storage
   if (function_exists('esc_url_raw')) {
      $val = esc_url_raw($val);
   } else {
      $val = filter_var($val, FILTER_SANITIZE_URL);
   }
   if (empty($val)) {
      return plugin_dir_url(__FILE__) . 'assets/default-bg.jpg';
   }
   return $val;
}

function ncoa_blog_post_status_field_cb() {
   $val = get_option('ncoa_blog_post_status', 'draft');
?>
   <fieldset>
      <label>
         <input type="radio" name="ncoa_blog_post_status" value="publish" <?php checked('publish', $val); ?> />
         <?php esc_html_e('Publish', 'ncoa-blogs'); ?>
      </label><br />
      <label>
         <input type="radio" name="ncoa_blog_post_status" value="draft" <?php checked('draft', $val); ?> />
         <?php esc_html_e('Draft', 'ncoa-blogs'); ?>
      </label>
   </fieldset>
<?php
}

function ncoa_blog_post_author_field_cb() {
   $val = absint(get_option('ncoa_blog_post_author', 1));
   $users = get_users(array('order' => 'ASC', 'orderby' => 'display_name'));
?>
   <select name="ncoa_blog_post_author" id="ncoa_blog_post_author">
      <?php
      foreach ($users as $user) {
         $selected = selected($user->ID, $val, false);
         echo '<option value="' . esc_attr($user->ID) . '"' . $selected . '>' . esc_html($user->display_name) . ' (' . esc_html($user->user_login) . ')</option>';
      }
      ?>
   </select>
   <p class="description"><?php esc_html_e('Select the default author for blog posts created via the REST endpoint.', 'ncoa-blogs'); ?></p>
<?php
}

function ncoa_blog_banner_bg_field_cb() {
   $val = get_option('ncoa_blog_banner_bg', plugin_dir_url(__FILE__) . 'assets/default-bg.jpg');
?>
   <fieldset>
      <label>
         <input type="text" name="ncoa_blog_banner_bg" value="<?php echo esc_attr($val); ?>" placeholder="<?php echo esc_attr(plugin_dir_url(__FILE__) . 'assets/default-bg.jpg'); ?>" />
      </label>
   </fieldset>
   <p class="description"><?php esc_html_e('Enter an image URL to be used for the background of blog banner shortcode', 'ncoa-blogs'); ?></p>
<?php
}

function ncoa_blogs_settings_page() {
   if (! current_user_can('manage_options')) {
      return;
   }
?>
   <div class="wrap">
      <h1><?php esc_html_e('NCOA Blogs', 'ncoa-blogs'); ?></h1>
      <form method="post" action="options.php">
         <?php
         settings_fields('ncoa_blogs_settings');
         do_settings_sections('ncoa-blogs');
         submit_button();
         ?>
      </form>
   </div>
<?php
}
