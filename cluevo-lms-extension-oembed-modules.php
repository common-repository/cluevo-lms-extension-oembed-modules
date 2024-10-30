<?php
/**
 * Plugin Name: CLUEVO LMS Extension: Video Tutorial Manager for YouTube (and other oEmbed providers)
 * Plugin URI:  https://www.cluevo.at
 * Description: Enables oEmbed content as modules
 * Version:     1.3.1
 * Author:      CLUEVO
 * Author URI:  https://profiles.wordpress.org/cluevo/
 * Text Domain: cluevo
 * Domain Path: /lang
 * License:     GPL2
 * CLUEVO requires at least: 1.11.0
 * CLUEVO tested up to: 1.13.0
 */

if (!class_exists('CluevoExt_oEmbedModules')) {

  class CluevoExt_oEmbedModules {

    public static function adminInit() {
      if (!self::dependency_check()) {
        add_action('init', 'CluevoExt_oEmbedModules::load_plugin_textdomain');
        add_action('cluevo_handle_module_url_install', 'CluevoExt_oEmbedModules::handle_module_url_install');
        add_action('cluevo_register_module_types', 'CluevoExt_oEmbedModules::register_module_ui');
      }
    }

    public static function frontendInit() {
      if (!self::dependency_check()) {
        add_action('cluevo_enqueue_module_scripts', 'CluevoExt_oEmbedModules::enqueue_frontend_scripts');
        add_action('cluevo_save_module_progress_oembed', 'CluevoExt_oEmbedModules::save_module_progress');
        add_action('cluevo_display_module', 'CluevoExt_oEmbedModules::display_module');
      }
    }

    public static function enqueue_frontend_scripts() {
      wp_register_script('cluevo-oembed-module-script', plugins_url('/js/cluevo-oembed-module.js', __FILE__), array('jquery'), '20151215', true);
      wp_enqueue_script('cluevo-oembed-module-script');
    }

    public static function register_module_ui($args) {
      foreach ($args["types"] as $key => $value) {
        if (!empty($value["alt-type"]) && $value["alt-type"] == "cluevo-lms-extension-oembed") {
          unset($args["types"][$key]);
          break;
        }
      }
      $args["types"][] = [
        'name' => __('YouTube, Vimeo, etc.', 'cluevo'),
        'key' => 'oembed',
        'icon' => plugins_url("/images/WordPress-EXTENSION_VTM_ICON_256x256.png", __FILE__),
        'description' => __('Link to embeddable content to install as module. Example: YouTube, Vimeo, Soundclund, etc.', 'cluevo'),
        'field' => 'text',
        'field-placeholder' => 'https://www.youtube.com/watch?v=7yjHnu4oewE',
        'button-label' => __('Install Module', 'cluevo')
      ];
    }

    public static function init_attempt($intUserId, $intModuleId) {
      global $wpdb;
      $table = $wpdb->prefix . CLUEVO_DB_TABLE_MODULES_PROGRESS;
      $attemptId = cluevo_get_current_attempt_id($intUserId, $intModuleId) + 1;

      $sql = "INSERT INTO $table SET
        user_id = %d,
        module_id = %d,
        attempt_id = %d,
        score_min = %d,
        score_max = %s,
        is_practice = %s";

      $wpdb->query(
        $wpdb->prepare($sql, [
          $intUserId,
          $intModuleId,
          $attemptId,
          0,
          100,
          false,
        ])
      );
      return cluevo_get_module_progress($intUserId, $intModuleId);
    }

    public static function save_module_progress($args) {
      $userId = (int)$args["user_id"];
      $itemId = !empty($args["item_id"]) ? (int)$args["item_id"] : null;;
      $module = $args["module"];
      if (empty($module) || !property_exists($module, "type_name") || strtolower($module->type_name) !== "oembed") return;
      $moduleId = $module->module_id;
      $request = $args["request"];
      $max = (float)$request['max'];
      $score = (float)$request["score"];

      if ($max <= 0)
        return false;

      if (!empty($userId) && !empty($module)) {

        $state = self::init_attempt($userId, $moduleId);
        $state["score_max"] = $max;
        $state["score_scaled"] = 1;
        $state["score_raw"] = $score;
        $state["completion_status"] = "completed";
        $state["success_status"] = "passed";
        $state["item_id"] = $itemId;
        $attempt = (int)$state["attempt_id"];
        $practiceMode = cluevo_user_module_progress_complete($userId, $module->module_id);
        $pointsToAdd = 0;
        $sourceType = "";
        $item = null;
        if (!empty($itemId)) {
          $item = cluevo_get_learning_structure_item($itemId);
        }
        if (!$item) return;
        if (!$practiceMode) {
          $pointsWorth = !empty($item->points_worth) ? $item->points_worth : 0;
          $sourceType = "oembed-module";
          if ($pointsWorth > 0) {
            cluevo_add_points_to_user($userId, $pointsWorth, $sourceType, $module->module_id, $attempt);
            do_action('cluevo_award_user_progress_points_from_module', [
              "user_id" => $userId,
              "points_added" => $pointsWorth,
              "module_id" => $module->module_id,
              "item_id" => $itemId,
              "attempt_id" => $attempt
            ]);
          }
        } else {
          if ($state["completion_status"] == "completed" || $state["lesson_status"] == "completed" || $state["lesson_status"] == "passed") {
            $pointsToAdd = (!empty($item->practice_points)) ? $item->practice_points : 0;
            $sourceType = "oembed-module-practice";
            cluevo_add_points_to_user(
              $userId,
              $pointsToAdd,
              $sourceType,
              $module->module_id,
              $attempt
            );
            do_action('cluevo_award_user_practice_points_from_module', [
              "user_id" => $userId,
              "points_added" => $pointsToAdd,
              "module_id" => $module->module_id,
              "item_id" => $itemId,
              "attempt_id" => $attempt
            ]);
          }
        }
        if ($pointsToAdd > 0) {
          do_action('cluevo_user_points_awarded_from_module', [
            "user_id" => $userId,
            "module_id" => $module->module_id,
            "attempt_id" => $attempt,
            "points_added" => $pointsToAdd,
            "is_practice" => $practiceMode,
            "source-type" => $sourceType
          ]);
        }
        return cluevo_update_module_progress($userId, $moduleId, $state["attempt_id"], $state);
      }
    }

    public static function activate() {
      if (!self::dependency_check()) {
        wp_die(
          __('You must have the core CLUEVO LMS plugin installed and activated to use this plugin.', 'cluevo'),
          __('Error', 'cluevo'),
          [ 'back_link' => true ]
        );
      } else {
        global $wpdb;
        $table = $wpdb->prefix . CLUEVO_DB_TABLE_MODULE_TYPES;
        $sql = "INSERT IGNORE INTO $table SET type_id = %d, type_name = %s, type_description = %s";
        $wpdb->query(
          $wpdb->prepare($sql, [ 4, "oEmbed", "" ] )
        );
      }
    }

    public static function handle_module_url_install($args) {
      $url = $args["url"];
      $lang = $args["lang"];
      $parentModuleId = $args["parentModuleId"];
      $oembed = _wp_oembed_get_object();
      $result = $oembed->get_data($url);
      if (!array_key_exists("result", $args)) $args["result"] = [];

      if ($result !== false) {
        $provider_name = (!empty($result->provider_name)) ? $result->provider_name : false;
        $provider_url = (!empty($result->provider_url)) ? $result->provider_url : false;
        $type = (!empty($result->type)) ? $result->type : false;
        $title = (!empty($result->title)) ? $result->title : "N/A";
        $moduleType = 4;
        $overwrite = false;

        $id = null;
        if (!empty($parentModuleId)) {
          if (!cluevo_module_exists($parentModuleId, $lang)) { // create metadata page for the uploaded module if the page doesn't yet exist
            $metaTitle = (!empty($lang)) ? $title . " - $lang" : $title;
            $id = cluevo_create_module_metadata_post($metaTitle);
          }
        } else {
          if (!cluevo_module_exists($title, $lang)) { // create metadata page for the uploaded module if the page doesn't yet exist
            $id = cluevo_create_module_metadata_post($title);
          }
        }

        $overwrite = (!empty(cluevo_get_module($title))) ? true : false;

        if (!$overwrite) {
          cluevo_create_module($title, $moduleType, $id, null, null, $url, $lang, $parentModuleId);
          $module = cluevo_get_module($title);
          $args["result"] = $module;
        } else {
          $module = cluevo_get_module($title);
          $args["result"] = $module;
          if (!empty($module)) {
            $post = get_post($module->metadata_id);
            if (empty($post) || empty($module->metadata_id)) {
              $id = cluevo_create_module_metadata_post($title);
              cluevo_update_module_metadata_id($module->module_id, $id);
              $module->metadata_id = $id;
            }
          } else {
            $args["errors"][] = __("An error occurred while trying to activate this module.", "cluevo");
          }
        }
        if ($overwrite) {
          $args["messages"][] = __("The existing module has been overwritten.", "cluevo");
        } else {
          $args["messages"][] = __("Module activated.", "cluevo");
        }
        $args["handled"] = true;
      } else {
        $args["errors"][] = __("An error occurred while trying to activate the module: Invalid oEmbed Provider.", "cluevo");
      }
    }

    public static function load_plugin_textdomain() {
      $moFile = WP_LANG_DIR . '/' . dirname( plugin_basename( __FILE__ ) ) . '/' . get_locale() . '.mo';
      load_textdomain( 'cluevo', $moFile ); 
      if (!load_plugin_textdomain( 'cluevo', false, dirname( plugin_basename( __FILE__ ) ) . '/lang/' )) {
        $locale = get_locale();
        if (strtolower(substr($locale, 0, 2)) == 'de') {
          $moFile = plugin_dir_path(__FILE__) . '/lang/de.mo';
        } else {
          $moFile = plugin_dir_path(__FILE__) . '/lang/en.mo';
        }
        $dir = plugin_dir_path( __FILE__ );
        load_textdomain( 'cluevo', $moFile);
      }
    }

    public static function get_oembed_data($args) {
      try {
        $module = cluevo_get_module($args["id"]);
      } catch (Exception $ex) {
        return null;
      }
      $oembed = _wp_oembed_get_object();
      $result = $oembed->get_data($module->module_index);
      $result->cluevo_module = $module;
      return $result;
    }
    public static function display_module($args) {
      $module = $args["module"];
      if ($module->type_name == "oembed") {
        $oembed = _wp_oembed_get_object();
        $result = $oembed->get_data($module->module_index);
        echo $result->html;
        echo "<script>jQuery(document).ready(function() { cluevo_ext_oembed_save_progress($module->module_id, 100, 100); });</script>";
      }
    }

    public static function dependency_check() {
      return (defined('CLUEVO_ACTIVE') && CLUEVO_ACTIVE === true);
    }

    public static function display_dependency_admin_notice() {
      echo '<div class="notice notice-error">
      <p>' . sprintf(__("CLUEVO LMS not active!", "cluevo")) . '</p>
      </div>';
    }

    public static function display_dependency_notice_in_row($file, $data, $status) {
      if (!self::dependency_check()) {
        $curVersion = $data["Version"];
        $statusClass = "active";
        if ($data["new_version"] && version_compare($curVersion, $data["new_version"]) != 0) {
          $statusClass .= " update";
        }
        $out = '<tr class="plugin-update-tr ' . $statusClass . '"><td colspan="3" class="plugin-update colspanchange"><div class="notice inline notice-error notice-alt">';
        $out .=  "<p class=\"cluevo-update-compat-text\"><span class=\"dashicons dashicons-warning\"></span> " . esc_html__("The core CLUEVO LMS plugin must be active for this extension to work.", "cluevo") . "</p>";
        $out .= '</div></td></tr>';
        echo $out;
      }
    }
  }

  add_action('init', 'CluevoExt_oEmbedModules::dependency_check');

  CluevoExt_oEmbedModules::adminInit();
  CluevoExt_oEmbedModules::frontendInit();
  register_activation_hook(__FILE__, array( 'CluevoExt_oEmbedModules', 'activate'));

  //add_action('admin_notices', 'CluevoExt_oEmbedModules::display_dependency_admin_notice');
  add_action( 'after_plugin_row_cluevo-lms-extension-oembed-modules/cluevo-lms-extension-oembed-modules.php', 'CluevoExt_oEmbedModules::display_dependency_notice_in_row', 10, 3);

  add_action( 'rest_api_init', function () {

    register_rest_route( CLUEVO_PLUGIN_NAMESPACE . '/v1', '/extensions/oembed/modules/(?P<id>[\d]+)', array(
      'methods' => 'GET',
      'callback' => 'CluevoExt_oEmbedModules::get_oembed_data',
      'permission_callback' => function () {
        return true;
      }
    ) );
  });
}
?>
