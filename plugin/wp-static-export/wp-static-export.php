<?php
/**
 * Plugin Name: WP Static Export
 * Plugin URI:  https://example.local/wp-static-export
 * Description: ส่งออกเว็บทั้งเว็บเป็นไฟล์ static (HTML/CSS/JS/รูป) เป็นไฟล์ ZIP ไฟล์เดียว สำหรับเอาไปวางบน Cloudflare Workers
 * Version:     1.0.0
 * Author:      tardev
 * Text Domain: wp-static-export
 * Requires PHP: 7.4
 * Requires at least: 5.9
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WPSE_VERSION', '1.0.0');
define('WPSE_FILE', __FILE__);
define('WPSE_DIR', plugin_dir_path(__FILE__));
define('WPSE_URL', plugin_dir_url(__FILE__));
define('WPSE_CAP', 'manage_options');

require_once WPSE_DIR . 'includes/class-job.php';
require_once WPSE_DIR . 'includes/class-discovery.php';
require_once WPSE_DIR . 'includes/class-extractor.php';
require_once WPSE_DIR . 'includes/class-rewriter.php';
require_once WPSE_DIR . 'includes/class-writer.php';
require_once WPSE_DIR . 'includes/class-crawler.php';
require_once WPSE_DIR . 'includes/class-packer.php';
require_once WPSE_DIR . 'includes/class-report.php';
require_once WPSE_DIR . 'includes/class-admin.php';

register_activation_hook(__FILE__, ['WPSE_Job', 'create_table']);

/**
 * ค่าตั้งทั้งหมดของ plugin พร้อมค่า default
 *
 * @return array
 */
function wpse_settings()
{
    $defaults = [
        'target_domain' => '',
        'batch_size'    => 10,
        'delay_ms'      => 150,
        'timeout'       => 20,
        'max_urls'      => 20000,
        'extra_urls'    => '',
        'include_dirs'  => '',
        'skip_patterns' => '',
        'strip_wp_meta' => 1,
    ];

    $saved = get_option('wpse_settings', []);
    if (!is_array($saved)) {
        $saved = [];
    }

    return array_merge($defaults, $saved);
}

/**
 * โดเมนต้นทาง (ที่ WordPress ตัวนี้รันอยู่) แบบไม่มี slash ปิดท้าย
 *
 * @return string
 */
function wpse_origin()
{
    return untrailingslashit(home_url('', 'https'));
}

/**
 * โดเมนปลายทางที่จะเอาไปวาง ถ้ายังไม่ตั้งให้ใช้ต้นทางไปก่อน
 *
 * @return string
 */
function wpse_target()
{
    $s = wpse_settings();
    $t = trim($s['target_domain']);
    if ($t === '') {
        return wpse_origin();
    }
    if (strpos($t, 'http') !== 0) {
        $t = 'https://' . $t;
    }

    return untrailingslashit($t);
}

/**
 * โฟลเดอร์เก็บงาน export ของ job หนึ่งรอบ
 *
 * @param int    $job_id
 * @param string $sub
 * @return string
 */
function wpse_job_dir($job_id, $sub = '')
{
    $up  = wp_upload_dir();
    $dir = trailingslashit($up['basedir']) . 'wpse-export/' . (int) $job_id;
    if ($sub !== '') {
        $dir .= '/' . ltrim($sub, '/');
    }

    return $dir;
}

/**
 * สร้างโฟลเดอร์พร้อมไฟล์กันคนนอกเปิดดู
 *
 * @param string $dir
 * @return bool
 */
function wpse_mkdir($dir)
{
    if (is_dir($dir)) {
        return true;
    }

    return wp_mkdir_p($dir);
}

function wpse_protect_root()
{
    $up   = wp_upload_dir();
    $root = trailingslashit($up['basedir']) . 'wpse-export';
    wpse_mkdir($root);

    $ht = $root . '/.htaccess';
    if (!file_exists($ht)) {
        file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    $idx = $root . '/index.html';
    if (!file_exists($idx)) {
        file_put_contents($idx, '');
    }
}

/**
 * slug ของเว็บ ใช้ตั้งชื่อไฟล์ zip และเป็นค่าเริ่มต้นของชื่อ Worker
 *
 * @return string
 */
function wpse_site_slug()
{
    $host = wp_parse_url(home_url(), PHP_URL_HOST);
    $host = preg_replace('/^www\./', '', (string) $host);
    $slug = sanitize_title(str_replace('.', '-', $host));

    return $slug !== '' ? $slug : 'site';
}

new WPSE_Admin();
