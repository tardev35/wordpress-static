<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPSE_Crawler
{
    // UA ของเบราว์เซอร์จริง — UA แปลกๆ ทำให้ WAF/ปลั๊กอินความปลอดภัยบล็อกทิ้งเปล่าๆ
    // ตัวที่บอกว่าเป็นเราคือ header X-WPSE-Export ซึ่งเอาไปทำ skip rule ได้
    const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    const HEADERS = ['X-WPSE-Export' => '1'];

    const PROBE_404 = '404-wpse-probe';

    const TEXT_ASSET_EXT = 'css|js|mjs|json|map';

    /**
     * ทำงานหนึ่งชุดตามที่ batch_size กำหนด แล้วคืนสถานะให้หน้า admin วนต่อ
     *
     * @param array $job
     * @return array
     */
    public static function run_batch(&$job)
    {
        $settings = wpse_settings();
        $rows     = WPSE_Job::claim($job['id'], (int) $settings['batch_size']);

        if (empty($rows)) {
            return ['worked' => 0, 'drained' => true];
        }

        $worked = 0;

        foreach ($rows as $row) {
            self::handle($job, $row, $settings);
            $worked++;

            if ((int) $settings['delay_ms'] > 0) {
                usleep((int) $settings['delay_ms'] * 1000);
            }
        }

        return ['worked' => $worked, 'drained' => false];
    }

    protected static function handle(&$job, $row, $settings)
    {
        $id   = (int) $row['id'];
        $url  = $row['url'];
        $type = $row['type'];

        $is_probe = strpos($url, self::PROBE_404) !== false;
        $ext      = strtolower((string) pathinfo((string) wp_parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        $binary   = $type === WPSE_Job::TYPE_ASSET && !preg_match('/^(' . self::TEXT_ASSET_EXT . ')$/', $ext);

        $rel = $is_probe ? '404.html' : WPSE_Writer::path_for($url, $type);

        if ($binary) {
            self::fetch_binary($job, $id, $url, $rel, $settings);

            return;
        }

        $res = wp_remote_get(
            $url,
            [
                'timeout'     => (int) $settings['timeout'],
                'redirection' => 0,
                'sslverify'   => false,
                'user-agent'  => self::UA,
                'headers'     => self::HEADERS,
            ]
        );

        if (is_wp_error($res)) {
            WPSE_Job::finish_row($id, WPSE_Job::STATUS_ERROR, 0, 0, '', $res->get_error_message());
            WPSE_Job::log($job, 'พลาด ' . $url . ' — ' . $res->get_error_message());

            return;
        }

        $code = (int) wp_remote_retrieve_response_code($res);

        if ($code >= 300 && $code < 400) {
            if ($is_probe) {
                WPSE_Job::finish_row($id, WPSE_Job::STATUS_ERROR, $code, 0, '', 'เว็บนี้ redirect หน้าที่ไม่มีอยู่ แทนที่จะตอบ 404 — จะไม่มี 404.html');
                WPSE_Job::log($job, 'ไม่ได้หน้า 404 ของธีม เพราะเว็บ redirect URL ที่ไม่มีอยู่ทิ้ง');

                return;
            }

            self::handle_redirect($job, $id, $url, $rel, $code, (string) wp_remote_retrieve_header($res, 'location'));

            return;
        }

        $body  = wp_remote_retrieve_body($res);
        $ctype = strtolower((string) wp_remote_retrieve_header($res, 'content-type'));


        if ($is_probe) {
            if ($code !== 404 && $code !== 200) {
                WPSE_Job::finish_row($id, WPSE_Job::STATUS_ERROR, $code, 0, '', 'หน้า 404 ของธีมตอบ ' . $code);

                return;
            }
        } elseif ($code !== 200) {
            WPSE_Job::finish_row($id, WPSE_Job::STATUS_ERROR, $code, 0, '', 'HTTP ' . $code);
            WPSE_Job::log($job, 'HTTP ' . $code . ' — ' . $url);

            if ($code === 403 && empty($job['hint_403'])) {
                $job['hint_403'] = 1;
                WPSE_Job::log($job, '↑ 403 มักแปลว่ามีไฟร์วอลล์ (Cloudflare/ปลั๊กอินความปลอดภัย) บล็อกอยู่');
                WPSE_Job::log($job, '   ทางแก้: สร้าง skip rule ให้ผ่านเมื่อ header X-WPSE-Export = 1 แล้วกดทำต่อ');
            }

            return;
        }

        $kind = self::kind_of($ctype, $ext);

        if ($kind === 'html' || $kind === 'xml') {
            self::enqueue_found($job, WPSE_Extractor::from_html($body, $url), $settings);
        } elseif ($kind === 'css') {
            self::enqueue_found($job, WPSE_Extractor::from_css($body, $url), $settings);
        }

        if ($kind !== 'binary') {
            $body = WPSE_Rewriter::apply($body, $kind, $rel);
        }

        $bytes = WPSE_Writer::write($job['dir'], $rel, $body);

        if ($bytes === false) {
            WPSE_Job::finish_row($id, WPSE_Job::STATUS_ERROR, $code, 0, $rel, 'เขียนไฟล์ไม่สำเร็จ');

            return;
        }

        WPSE_Job::finish_row($id, WPSE_Job::STATUS_DONE, $code, (int) $bytes, $rel);
    }

    /**
     * redirect คือพฤติกรรมของ PHP ไม่ใช่เนื้อหน้า ถ้าเดินตามไปเก็บปลายทางมาเซฟ
     * จะได้หน้าเว็บของคนอื่นฝังอยู่ในเว็บเรา — เขียนเป็นหน้า meta-refresh แทน
     * ซึ่งเป็นวิธีเดียวกับที่ Astro ทำให้ทุก entry ใน redirects:
     */
    protected static function handle_redirect(&$job, $id, $url, $rel, $code, $location)
    {
        if ($location === '') {
            WPSE_Job::finish_row($id, WPSE_Job::STATUS_ERROR, $code, 0, '', 'redirect ' . $code . ' แต่ไม่มี Location');

            return;
        }

        $to = WPSE_Extractor::normalize($location, $url);

        if ($to === '') {
            $to = $location;
        }

        if ($to === $url) {
            WPSE_Job::finish_row($id, WPSE_Job::STATUS_ERROR, $code, 0, '', 'redirect วนหาตัวเอง');

            return;
        }

        // WordPress เติม slash ปิดท้ายให้เองด้วย 301 — /promotion → /promotion/
        // ซึ่งสองอันนี้ลงไฟล์เดียวกันคือ promotion/index.html ถ้าเขียนหน้า redirect
        // ลงไปจะทับหน้าจริงที่ดึงมาแล้ว กลายเป็นหน้าที่พาไปหาตัวเองวนไม่รู้จบ
        if (WPSE_Extractor::is_internal($to)) {
            $rel_to = WPSE_Writer::path_for($to, WPSE_Extractor::classify($to));

            if ($rel_to === $rel) {
                if (!WPSE_Extractor::is_excluded($to)) {
                    WPSE_Job::push($job['id'], [$to], WPSE_Extractor::classify($to));
                }

                WPSE_Job::finish_row($id, WPSE_Job::STATUS_SKIP, $code, 0, $rel, 'redirect ' . $code . ' ไปที่ไฟล์เดียวกัน — ไม่เขียนทับ');

                return;
            }
        }

        if (WPSE_Extractor::is_internal($to) && !WPSE_Extractor::is_excluded($to)) {
            WPSE_Job::push($job['id'], [$to], WPSE_Extractor::classify($to));
        }

        $html  = WPSE_Rewriter::apply(self::redirect_stub($to), 'html');
        $bytes = WPSE_Writer::write($job['dir'], $rel, $html);

        if ($bytes === false) {
            WPSE_Job::finish_row($id, WPSE_Job::STATUS_ERROR, $code, 0, $rel, 'เขียนหน้า redirect ไม่สำเร็จ');

            return;
        }

        WPSE_Job::finish_row($id, WPSE_Job::STATUS_DONE, $code, (int) $bytes, $rel, 'redirect ' . $code . ' → ' . $to);
        WPSE_Job::log($job, 'redirect ' . $code . ' ' . $url . ' → ' . $to);
    }

    /**
     * @param string $to
     * @return string
     */
    public static function redirect_stub($to)
    {
        $safe = esc_url($to);
        $attr = esc_attr($to);
        $js   = wp_json_encode($to);

        return '<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>กำลังพาไปหน้าใหม่</title>
<meta name="robots" content="noindex, follow">
<meta http-equiv="refresh" content="0;url=' . $attr . '">
<link rel="preconnect" href="' . $attr . '">
</head>
<body>
<p>กำลังพาไปที่ <a href="' . $safe . '">' . $safe . '</a></p>
<script>window.location.replace(' . $js . ');</script>
</body>
</html>
';
    }

    /**
     * ไฟล์ที่ไม่ใช่ข้อความ เขียนลงดิสก์ตรงๆ ไม่ต้องผ่าน memory
     */
    protected static function fetch_binary(&$job, $id, $url, $rel, $settings)
    {
        $full = trailingslashit($job['dir']) . $rel;

        if (!is_dir(dirname($full)) && !wp_mkdir_p(dirname($full))) {
            WPSE_Job::finish_row($id, WPSE_Job::STATUS_ERROR, 0, 0, $rel, 'สร้างโฟลเดอร์ไม่สำเร็จ');

            return;
        }

        $res = wp_remote_get(
            $url,
            [
                'timeout'     => (int) $settings['timeout'],
                'redirection' => 3,
                'sslverify'   => false,
                'user-agent'  => self::UA,
                'headers'     => self::HEADERS,
                'stream'      => true,
                'filename'    => $full,
            ]
        );

        if (is_wp_error($res)) {
            @unlink($full);
            WPSE_Job::finish_row($id, WPSE_Job::STATUS_ERROR, 0, 0, $rel, $res->get_error_message());

            return;
        }

        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code !== 200) {
            @unlink($full);
            WPSE_Job::finish_row($id, WPSE_Job::STATUS_ERROR, $code, 0, $rel, 'HTTP ' . $code);

            return;
        }

        $bytes = file_exists($full) ? (int) filesize($full) : 0;
        WPSE_Job::finish_row($id, WPSE_Job::STATUS_DONE, $code, $bytes, $rel);
    }

    /**
     * @param array    $job
     * @param string[] $urls
     * @param array    $settings
     */
    protected static function enqueue_found(&$job, $urls, $settings)
    {
        if (empty($urls)) {
            return;
        }

        $counts = WPSE_Job::counts($job['id']);
        $room   = (int) $settings['max_urls'] - $counts['total'];

        if ($room <= 0) {
            WPSE_Job::log($job, 'ชนเพดาน max_urls แล้ว หยุดเพิ่ม URL ใหม่');

            return;
        }

        $pages  = [];
        $assets = [];

        foreach ($urls as $u) {
            if (WPSE_Extractor::classify($u) === WPSE_Job::TYPE_ASSET) {
                $assets[] = $u;
            } else {
                $pages[] = $u;
            }
        }

        if ($pages) {
            WPSE_Job::push($job['id'], array_slice($pages, 0, $room), WPSE_Job::TYPE_PAGE);
        }
        if ($assets) {
            WPSE_Job::push($job['id'], array_slice($assets, 0, $room), WPSE_Job::TYPE_ASSET);
        }
    }

    /**
     * @param string $ctype
     * @param string $ext
     * @return string html|css|script|xml|binary
     */
    public static function kind_of($ctype, $ext)
    {
        if (strpos($ctype, 'text/html') !== false || strpos($ctype, 'application/xhtml') !== false) {
            return 'html';
        }
        if (strpos($ctype, 'text/css') !== false || $ext === 'css') {
            return 'css';
        }
        if (strpos($ctype, 'javascript') !== false || in_array($ext, ['js', 'mjs'], true)) {
            return 'script';
        }
        if (strpos($ctype, 'xml') !== false || strpos($ctype, 'text/plain') !== false || in_array($ext, ['xml', 'txt'], true)) {
            return 'xml';
        }
        if (strpos($ctype, 'json') !== false || $ext === 'json' || $ext === 'map') {
            return 'script';
        }

        return 'binary';
    }
}
