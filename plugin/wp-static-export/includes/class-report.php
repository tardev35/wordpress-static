<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPSE_Report
{
    const SCAN_LIMIT = 3000;

    /**
     * รายงานที่แนบไปกับ zip — ตัวเลข ปัญหาที่เจอ และรายชื่อ plugin ทั้งหมด
     *
     * @param array $job
     * @return array
     */
    public static function build($job)
    {
        $counts = WPSE_Job::counts($job['id']);
        $scan   = self::scan_output($job);

        return [
            'generated_at'   => current_time('mysql'),
            'origin'         => $job['origin'],
            'target_domain'  => $job['target'],
            'wordpress'      => get_bloginfo('version'),
            'php'            => PHP_VERSION,
            'theme'          => self::theme(),
            'counts'         => [
                'pages'   => $counts['pages'],
                'assets'  => $counts['assets'],
                'errors'  => $counts['error'],
                'files'   => $scan['files'],
                'bytes'   => $scan['bytes'],
                'largest' => $scan['largest'],
            ],
            'cloudflare'     => self::limits($scan),
            'failed_urls'    => WPSE_Job::failures($job['id']),
            'redirects'      => WPSE_Job::redirects($job['id']),
            'external_hosts' => $scan['hosts'],
            'dynamic_bits'   => $scan['dynamic'],
            'active_plugins' => self::plugins(),
            'scan_truncated' => $scan['truncated'],
        ];
    }

    /**
     * @return array
     */
    public static function plugins()
    {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $out = [];

        foreach ((array) get_option('active_plugins', []) as $file) {
            $path = WP_PLUGIN_DIR . '/' . $file;
            $data = file_exists($path) ? get_plugin_data($path, false, false) : [];
            $out[] = [
                'file'    => $file,
                'name'    => isset($data['Name']) && $data['Name'] !== '' ? $data['Name'] : $file,
                'version' => isset($data['Version']) ? $data['Version'] : '',
            ];
        }

        if (function_exists('get_mu_plugins')) {
            foreach (get_mu_plugins() as $file => $data) {
                $out[] = [
                    'file'    => 'mu-plugins/' . $file,
                    'name'    => isset($data['Name']) ? $data['Name'] : $file,
                    'version' => isset($data['Version']) ? $data['Version'] : '',
                ];
            }
        }

        return $out;
    }

    public static function theme()
    {
        $theme = wp_get_theme();

        return [
            'name'    => $theme->get('Name'),
            'version' => $theme->get('Version'),
            'parent'  => $theme->parent() ? $theme->parent()->get('Name') : '',
        ];
    }

    /**
     * เพดานของ Workers Static Assets แผนฟรี — ต้องรู้ตั้งแต่ตอนนี้ ไม่ใช่ตอน deploy แล้วเด้ง
     *
     * @param array $scan
     * @return array
     */
    protected static function limits($scan)
    {
        $max_files = 20000;
        $max_file  = 25 * 1024 * 1024;

        return [
            'file_limit'        => $max_files,
            'file_size_limit'   => $max_file,
            'files_ok'          => $scan['files'] <= $max_files,
            'file_sizes_ok'     => $scan['largest'] <= $max_file,
            'largest_file'      => $scan['largest_file'],
        ];
    }

    /**
     * อ่านไฟล์ที่ export ออกมาแล้ว หาสิ่งที่ static รองรับไม่ได้ และลิงก์ที่ยังชี้ออกนอก
     *
     * @param array $job
     * @return array
     */
    protected static function scan_output($job)
    {
        $files   = WPSE_Writer::scan($job['dir']);
        $bytes   = 0;
        $largest = 0;
        $largest_file = '';
        $hosts   = [];
        $dynamic = [
            'forms'          => [],
            'admin_ajax'     => [],
            'wp_json'        => [],
            'origin_leftover'=> [],
        ];

        $origin_host = strtolower((string) wp_parse_url($job['origin'], PHP_URL_HOST));
        $target_host = strtolower((string) wp_parse_url($job['target'], PHP_URL_HOST));

        $scanned   = 0;
        $truncated = false;

        foreach ($files as $rel) {
            $full = trailingslashit($job['dir']) . $rel;
            $size = is_file($full) ? (int) filesize($full) : 0;
            $bytes += $size;

            if ($size > $largest) {
                $largest      = $size;
                $largest_file = $rel;
            }

            if (!preg_match('/\.(html|htm|xml|css|js|json|txt)$/i', $rel)) {
                continue;
            }

            if ($scanned >= self::SCAN_LIMIT) {
                $truncated = true;
                continue;
            }
            $scanned++;

            $html = (string) file_get_contents($full);

            if (preg_match('/<form\b/i', $html)) {
                $dynamic['forms'][] = $rel;
            }
            if (strpos($html, 'admin-ajax.php') !== false) {
                $dynamic['admin_ajax'][] = $rel;
            }
            if (strpos($html, '/wp-json/') !== false) {
                $dynamic['wp_json'][] = $rel;
            }
            if ($origin_host !== '' && $origin_host !== $target_host && stripos($html, $origin_host) !== false) {
                $dynamic['origin_leftover'][] = $rel;
            }

            if (preg_match_all('#\shref=["\']https?://([^/"\']+)#i', $html, $m)) {
                foreach ($m[1] as $h) {
                    $h = strtolower($h);
                    if ($h !== $origin_host && $h !== $target_host) {
                        $hosts[$h] = isset($hosts[$h]) ? $hosts[$h] + 1 : 1;
                    }
                }
            }
            if (preg_match_all('#\ssrc=["\']https?://([^/"\']+)#i', $html, $m)) {
                foreach ($m[1] as $h) {
                    $h = strtolower($h);
                    if ($h !== $origin_host && $h !== $target_host) {
                        $hosts[$h] = isset($hosts[$h]) ? $hosts[$h] + 1 : 1;
                    }
                }
            }
        }

        arsort($hosts);

        foreach ($dynamic as $key => $list) {
            $dynamic[$key] = array_slice($list, 0, 50);
        }

        return [
            'files'        => count($files),
            'bytes'        => $bytes,
            'largest'      => $largest,
            'largest_file' => $largest_file,
            'hosts'        => $hosts,
            'dynamic'      => $dynamic,
            'truncated'    => $truncated,
        ];
    }
}
