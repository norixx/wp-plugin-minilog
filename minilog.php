<?php

/**
 * Plugin Name: Minilog
 * Plugin URI:  https://github.com/norixx/wp-plugin-minilog
 * Description: 開発用デバッグログおよび読み込みテンプレート一覧を表示するプラグインです。
 * Version:     0.1.1
 * Author:      Taro Shinjyuku
 * License:     GPL2
 */

// Restrict direct access
if (!defined('ABSPATH')) {
  exit;
}

/**
 * 1. Create admin menu and page under the settings
 */
class Minilog_Settings
{

  /**
   * Initialization
   */
  public static function init()
  {
    add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
    add_action('admin_init', [__CLASS__, 'register_settings']);
  }

  /**
   * Create menu under the Settings
   */
  public static function add_admin_menu()
  {
    add_options_page(
      _x('Minilog settings', 'minilog'), // Page title
      'Minilog', // Menu title
      'manage_options', // Capability
      'minilog',   // Slug
      [__CLASS__, 'render_settings_page'] // ⭐️ Render callback
    );
  }

  /**
   * Register settings
   */
  public static function register_settings()
  {
    register_setting('minilog_settings_group', 'minilog_is_enabled'); // 🔄 Minilog swich on(1)/off(0)
  }

  /**
   * ⭐️ Render HTML
   */
  public static function render_settings_page()
  {
    $enabled = get_option('minilog_is_enabled', '1'); // Default value is on(1)
?>
    <div class="wrap">
      <h1><?= _x('Minilog settings', 'minilog'); ?></h1>
      <form method="post" action="options.php">
        <?php
        settings_fields('minilog_settings_group');
        do_settings_sections('minilog');
        ?>
        <table class="form-table">
          <tr valign="top">
            <th scope="row">デバッグログの出力</th>
            <td>
              <label>
                <!-- 🔄 Minilog on/off -->
                <input type="checkbox" name="minilog_is_enabled" value="1" <?php checked('1', $enabled); ?> />
                有効にする（wp_footer でログとテンプレート一覧を出力します）
              </label>
            </td>
          </tr>
        </table>
        <?php submit_button(); ?>
      </form>
    </div>
<?php
  }
}
Minilog_Settings::init();


/**
 * 2. ログ出力機能（オン/オフの判定を追加）
 */
class Minilog_Logger
{
  // Data accumulator
  private static $logs = [];

  /**
   * Initialization
   */
  public static function init(): void
  {
    // 管理画面でのオン/オフ設定を確認
    $enabled = get_option('minilog_is_enabled', '1');
    if ($enabled !== '1') {
      return; // Do nothing if the setting is off
    }

    // CSS読み込みフック登録
    add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_styles']);

    // テンプレート一覧の取得もログ有効時のみ動作させるようにフックを登録
    add_action('wp_footer', [__CLASS__, 'get_included_files'], 9998);

    // 出力！
    add_action('wp_footer', [__CLASS__, 'render'], 9999);
  }

  /**
   * ログ蓄積用関数
   */
  public static function log(mixed $data = [], string $label = ''): void
  {
    // そもそも設定がオフの場合はログを蓄積しない
    $enabled = get_option('minilog_is_enabled', '0');
    if ($enabled !== '1') {
      return;
    }

    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
    $caller = (isset($trace[1]['function']) && $trace[1]['function'] === 'minilog') ? $trace[1] : $trace[0];

    self::$logs[] = [
      'label' => $label,
      'data'  => $data,
      'file'  => isset($caller['file']) ? basename($caller['file']) : 'unknown',
      'line'  => isset($caller['line']) ? $caller['line'] : 0
    ];
  }

  /**
   * wp_footerでデータを全部出力するメソッド
   */
  public static function render()
  {
    if (empty(self::$logs)) {
      return;
    }

    $log_data = [
      'count' => count(self::$logs)
    ];
    $header = <<< HEADER
      <section class="c-minilog">
        <header class="c-minilog__header">
          <h1 class="c-minilog__title"><span class="dashicons dashicons-editor-ul"></span> Minilog ( {$log_data['count']} ) <input type="checkbox" class="c-minilog__checkbox">
          </h1>
        </header>
        <div class="c-minilog__content">
HEADER;
    echo $header;

    foreach (self::$logs as $index => $log) {
      $body = <<< BODY
        <div class="c-minilog__log">
          <div class="c-minilog__log-header">
              <strong class="c-minilog__label">{$log['label']}</strong>
              <span class="c-minilog__file">{$log['file']} : {$log['line']}</span>
          </div>
BODY;
      echo $body;

      ob_start();
      var_dump($log['data']);
      $dump_output = ob_get_clean();

      echo '<pre class="c-minilog__code">' . htmlspecialchars($dump_output, ENT_QUOTES, 'UTF-8') . '</pre>';

      echo '</div><!-- /.c-minilog__log -->';
    }

    $footer = <<< FOOTER
      </div><!-- /.c-minilog__content -->
    </section><!-- /.c-minilog -->
FOOTER;
    echo $footer;
  }

  public static function get_included_files()
  {
    global $template;
    $theme_dir = get_template_directory();
    $included = get_included_files();

    if ($template) {
      array_unshift($included, $template);
    }

    $theme_files = array_unique($included);
    $theme_files = array_filter($theme_files, function ($file) use ($theme_dir) {
      return strpos($file, $theme_dir) !== false;
    });

    $theme_files = array_map(function ($file) use ($theme_dir) {
      return str_replace($theme_dir, '', $file);
    }, $theme_files);

    minilog(array_values($theme_files), '読み込まれたテーマファイル一覧');
  }

  /**
   * CSSの読み込み処理
   */
  public static function enqueue_styles()
  {
    // Dashicons
    wp_enqueue_style('dashicons');

    // Google Fonts
    wp_enqueue_style(
      'minilog-google-fonts',
      'https://fonts.googleapis.com/css2?family=JetBrains+Mono:ital,wght@0,100..800;1,100..800&family=Noto+Sans+JP:wght@100..900&display=swap'
    );

    // Minilog Style
    wp_enqueue_style(
      'minilog-styles', // 一意の識別子（ハンドル名）
      plugins_url('minilog.min.css', __FILE__), // ファイルのURL（__FILE__ はこの minilog.php を指す）
      [], // 依存する他のCSSがあればここに指定（なければ空の配列）
      '1.0.0' // キャッシュ対策用のバージョン（任意）
    );
  }
}

// クラスの初期化（フックを有効化し、renderメソッドを実行）
Minilog_Logger::init();


/**
 * ヘルパー関数（毎回クラス名を書く手間を省くため）
 * どこからでも minilog($data, 'ラベル') で呼び出せます。
 */
if (!function_exists('minilog')) {
  function minilog($data = [], $label = 'Log')
  {
    Minilog_Logger::log($data, $label);
  }
}
