<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPSE_Admin
{
    const PAGE = 'wp-static-export';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);

        add_action('admin_post_wpse_save', [$this, 'save_settings']);
        add_action('admin_post_wpse_download', [$this, 'download']);

        add_action('wp_ajax_wpse_start', [$this, 'ajax_start']);
        add_action('wp_ajax_wpse_step', [$this, 'ajax_step']);
        add_action('wp_ajax_wpse_status', [$this, 'ajax_status']);
        add_action('wp_ajax_wpse_reset', [$this, 'ajax_reset']);
    }

    public function menu()
    {
        add_management_page(
            'Static Export',
            'Static Export',
            WPSE_CAP,
            self::PAGE,
            [$this, 'render']
        );
    }

    public function assets($hook)
    {
        if ($hook !== 'tools_page_' . self::PAGE) {
            return;
        }

        wp_enqueue_script('wpse-app', WPSE_URL . 'admin/app.js', [], WPSE_VERSION, true);
        wp_enqueue_style('wpse-app', WPSE_URL . 'admin/style.css', [], WPSE_VERSION);

        wp_localize_script(
            'wpse-app',
            'WPSE',
            [
                'ajax'  => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wpse'),
            ]
        );
    }

    public function render()
    {
        if (!current_user_can(WPSE_CAP)) {
            wp_die('ไม่มีสิทธิ์เข้าหน้านี้');
        }

        $settings = wpse_settings();
        $job      = WPSE_Job::get();

        require WPSE_DIR . 'admin/page.php';
    }

    public function save_settings()
    {
        if (!current_user_can(WPSE_CAP)) {
            wp_die('ไม่มีสิทธิ์');
        }
        check_admin_referer('wpse_save');

        $in = wp_unslash($_POST);

        update_option(
            'wpse_settings',
            [
                'target_domain' => esc_url_raw(trim((string) ($in['target_domain'] ?? ''))),
                'batch_size'    => max(1, min(100, (int) ($in['batch_size'] ?? 10))),
                'delay_ms'      => max(0, min(5000, (int) ($in['delay_ms'] ?? 150))),
                'timeout'       => max(5, min(120, (int) ($in['timeout'] ?? 20))),
                'max_urls'      => max(10, min(100000, (int) ($in['max_urls'] ?? 20000))),
                'extra_urls'    => sanitize_textarea_field((string) ($in['extra_urls'] ?? '')),
                'include_dirs'  => sanitize_textarea_field((string) ($in['include_dirs'] ?? '')),
                'skip_patterns' => sanitize_textarea_field((string) ($in['skip_patterns'] ?? '')),
                'strip_wp_meta' => empty($in['strip_wp_meta']) ? 0 : 1,
            ],
            false
        );

        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'saved' => 1], admin_url('tools.php')));
        exit;
    }

    public function ajax_start()
    {
        $this->guard();

        $job = WPSE_Job::start();

        $found = WPSE_Discovery::collect();
        $added = WPSE_Job::push($job['id'], $found['urls'], WPSE_Job::TYPE_PAGE);

        WPSE_Job::push($job['id'], [home_url('/' . WPSE_Crawler::PROBE_404 . '/')], WPSE_Job::TYPE_PAGE);

        foreach ($found['notes'] as $note) {
            WPSE_Job::log($job, $note);
        }
        WPSE_Job::log($job, 'เริ่ม export — ตั้งต้น ' . $added . ' URL');

        WPSE_Job::save($job);

        wp_send_json_success($this->status_payload($job));
    }

    public function ajax_step()
    {
        $this->guard();

        $job = WPSE_Job::get();
        if (!$job) {
            wp_send_json_error(['message' => 'ยังไม่มีงาน export กด "เริ่ม export" ก่อน']);
        }

        if ($job['phase'] === WPSE_Job::PHASE_CRAWL) {
            $res = WPSE_Crawler::run_batch($job);

            if (!empty($res['drained'])) {
                $total = WPSE_Packer::prepare($job);
                WPSE_Job::log($job, 'ดึงครบแล้ว เริ่มแพ็ก zip — ' . $total . ' ไฟล์');
                $job['phase'] = WPSE_Job::PHASE_PACK;
            }
        } elseif ($job['phase'] === WPSE_Job::PHASE_PACK) {
            $res = WPSE_Packer::run_batch($job);

            if (!empty($res['error'])) {
                WPSE_Job::log($job, $res['error']);
                WPSE_Job::save($job);
                wp_send_json_error(['message' => $res['error']]);
            }

            if (!empty($res['drained'])) {
                WPSE_Packer::attach_report($job, WPSE_Report::build($job));
                $job['phase']    = WPSE_Job::PHASE_DONE;
                $job['finished'] = current_time('mysql');
                WPSE_Job::log($job, 'เสร็จแล้ว — zip ' . size_format(WPSE_Packer::zip_size($job)));
            }
        }

        WPSE_Job::save($job);

        wp_send_json_success($this->status_payload($job));
    }

    public function ajax_status()
    {
        $this->guard();

        $job = WPSE_Job::get();
        if (!$job) {
            wp_send_json_success(['phase' => WPSE_Job::PHASE_IDLE]);
        }

        wp_send_json_success($this->status_payload($job));
    }

    public function ajax_reset()
    {
        $this->guard();

        WPSE_Job::reset();

        wp_send_json_success(['phase' => WPSE_Job::PHASE_IDLE]);
    }

    protected function guard()
    {
        if (!current_user_can(WPSE_CAP)) {
            wp_send_json_error(['message' => 'ไม่มีสิทธิ์'], 403);
        }
        check_ajax_referer('wpse', 'nonce');
    }

    /**
     * @param array $job
     * @return array
     */
    protected function status_payload($job)
    {
        $counts = WPSE_Job::counts($job['id']);

        $percent = 0;
        if ($job['phase'] === WPSE_Job::PHASE_CRAWL && $counts['total'] > 0) {
            $percent = (int) floor((($counts['done'] + $counts['error']) / $counts['total']) * 100);
        } elseif ($job['phase'] === WPSE_Job::PHASE_PACK && (int) $job['pack_total'] > 0) {
            $percent = (int) floor(((int) $job['pack_cursor'] / (int) $job['pack_total']) * 100);
        } elseif ($job['phase'] === WPSE_Job::PHASE_DONE) {
            $percent = 100;
        }

        return [
            'phase'    => $job['phase'],
            'percent'  => min(100, $percent),
            'counts'   => $counts,
            'pack'     => ['cursor' => (int) $job['pack_cursor'], 'total' => (int) $job['pack_total']],
            'log'      => array_slice((array) $job['log'], -14),
            'zip'      => [
                'ready' => $job['phase'] === WPSE_Job::PHASE_DONE,
                'size'  => size_format(WPSE_Packer::zip_size($job)),
                'url'   => $this->download_url($job),
            ],
        ];
    }

    /**
     * ต้องเป็น URL ดิบ ห้ามผ่าน wp_nonce_url() เพราะมันคืนค่าที่ esc_html แล้ว
     * (& กลายเป็น &amp;) ซึ่งถูกเมื่อพิมพ์ลง HTML ตรงๆ แต่ตัวนี้เดินทางผ่าน JSON
     * ไปโผล่ที่ href ด้วย JS ไม่มีใครถอด entity ให้ ผลคือ job/_wpnonce หายทั้งคู่
     * แล้ว WordPress ตอบ "ลิงก์ที่คุณตามได้หมดอายุแล้ว"
     *
     * @param array $job
     * @return string
     */
    protected function download_url($job)
    {
        $id = (int) $job['id'];

        return add_query_arg(
            [
                'action'   => 'wpse_download',
                'job'      => $id,
                '_wpnonce' => wp_create_nonce('wpse_download_' . $id),
            ],
            admin_url('admin-post.php')
        );
    }

    public function download()
    {
        if (!current_user_can(WPSE_CAP)) {
            wp_die('ไม่มีสิทธิ์');
        }

        $id = isset($_GET['job']) ? (int) $_GET['job'] : 0;
        check_admin_referer('wpse_download_' . $id);

        $job = WPSE_Job::get();
        if (!$job || (int) $job['id'] !== $id || empty($job['zip']) || !file_exists($job['zip'])) {
            wp_die('ไม่พบไฟล์ zip ของงานนี้');
        }

        $name = basename($job['zip']);
        $size = (int) filesize($job['zip']);

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . $size);

        while (ob_get_level()) {
            ob_end_clean();
        }

        readfile($job['zip']);
        exit;
    }
}
