<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPSE_Discovery
{
    /**
     * รวม URL ตั้งต้นจากทุกทางแล้วส่งกลับเป็น list ที่ dedupe แล้ว
     *
     * @return array{urls: string[], notes: string[]}
     */
    public static function collect()
    {
        $urls  = [];
        $notes = [];

        $urls[] = home_url('/');

        $sitemaps = self::sitemaps();
        foreach ($sitemaps as $s) {
            $urls[] = $s;
        }
        $notes[] = $sitemaps
            ? 'sitemap ที่ใช้: ' . implode(', ', $sitemaps)
            : 'ไม่พบ sitemap — ใช้การไล่จากฐานข้อมูลกับลิงก์ในหน้าแทน';

        $n = count($urls);
        foreach (self::singular() as $u) {
            $urls[] = $u;
        }
        $notes[] = 'หน้า/โพสต์/CPT: ' . (count($urls) - $n) . ' URL';

        $n = count($urls);
        foreach (self::archives() as $u) {
            $urls[] = $u;
        }
        $notes[] = 'หน้ารวม + หน้า pagination: ' . (count($urls) - $n) . ' URL';

        $n = count($urls);
        foreach (self::terms() as $u) {
            $urls[] = $u;
        }
        $notes[] = 'หมวดหมู่/แท็ก: ' . (count($urls) - $n) . ' URL';

        foreach (self::extras() as $u) {
            $urls[] = $u;
        }

        $urls[] = home_url('/robots.txt');

        // เว็บส่วนใหญ่ไม่มี favicon.ico ที่รากจริงๆ (ใช้ <link rel=icon> ชี้ไฟล์ใน uploads แทน)
        // ถ้าใส่ไปดื้อๆ จะได้ 404 ติดรายงานทุกรอบจนคนอ่านนึกว่า export พัง
        if (self::head_ok(home_url('/favicon.ico'))) {
            $urls[] = home_url('/favicon.ico');
        }

        $clean = [];
        foreach ($urls as $u) {
            $u = WPSE_Extractor::normalize($u);
            if ($u !== '' && WPSE_Extractor::is_internal($u) && !WPSE_Extractor::is_excluded($u)) {
                $clean[$u] = true;
            }
        }

        return ['urls' => array_keys($clean), 'notes' => $notes];
    }

    /**
     * หา sitemap ที่เว็บนี้ใช้จริง ตัวไหนมีอยู่ก็เอาตัวนั้น
     *
     * @return string[]
     */
    public static function sitemaps()
    {
        $found = [];

        $candidates = [
            'sitemap_index.xml',
            'sitemap.xml',
            'wp-sitemap.xml',
        ];

        if (function_exists('wp_sitemaps_get_server') && (bool) apply_filters('wp_sitemaps_enabled', true)) {
            $found[] = home_url('/wp-sitemap.xml');
        }

        foreach ($candidates as $c) {
            $url = home_url('/' . $c);
            if (in_array($url, $found, true)) {
                continue;
            }
            if (self::head_ok($url)) {
                $found[] = $url;
            }
        }

        return $found;
    }

    protected static function head_ok($url)
    {
        $res = wp_remote_head(
            $url,
            [
                'timeout'     => 8,
                'redirection' => 3,
                'sslverify'   => false,
                'user-agent'  => WPSE_Crawler::UA,
            ]
        );

        if (is_wp_error($res)) {
            return false;
        }

        return (int) wp_remote_retrieve_response_code($res) === 200;
    }

    /**
     * ทุก post type สาธารณะที่เผยแพร่แล้ว รวม CPT ที่ plugin สร้าง
     *
     * @return string[]
     */
    public static function singular()
    {
        global $wpdb;

        $types = get_post_types(['public' => true], 'names');
        unset($types['attachment']);

        if (empty($types)) {
            return [];
        }

        $in  = "'" . implode("','", array_map('esc_sql', $types)) . "'";
        $ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_status = 'publish' AND post_type IN ({$in})
             ORDER BY ID ASC"
        );

        $urls = [];
        foreach ($ids as $id) {
            $link = get_permalink((int) $id);
            if ($link) {
                $urls[] = $link;
            }
        }

        return $urls;
    }

    /**
     * หน้ารวมโพสต์ + archive ของแต่ละ post type พร้อมหน้า pagination ทุกหน้า
     *
     * @return string[]
     */
    public static function archives()
    {
        global $wpdb;

        $per  = max(1, (int) get_option('posts_per_page', 10));
        $urls = [];

        $blog = get_option('page_for_posts') ? get_permalink((int) get_option('page_for_posts')) : home_url('/');
        if ($blog) {
            $count = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'post'"
            );
            $urls = array_merge($urls, self::paged($blog, $count, $per));
        }

        $types = get_post_types(['public' => true, 'has_archive' => true], 'names');
        foreach ($types as $type) {
            $link = get_post_type_archive_link($type);
            if (!$link) {
                continue;
            }
            $count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = %s",
                    $type
                )
            );
            $urls = array_merge($urls, self::paged($link, $count, $per));
        }

        return $urls;
    }

    /**
     * term ของ taxonomy สาธารณะที่มีโพสต์อยู่จริง พร้อม pagination
     *
     * @return string[]
     */
    public static function terms()
    {
        $per  = max(1, (int) get_option('posts_per_page', 10));
        $urls = [];

        $taxes = get_taxonomies(['public' => true], 'names');
        if (empty($taxes)) {
            return $urls;
        }

        $terms = get_terms(
            [
                'taxonomy'   => array_values($taxes),
                'hide_empty' => true,
                'number'     => 0,
            ]
        );

        if (is_wp_error($terms)) {
            return $urls;
        }

        foreach ($terms as $term) {
            $link = get_term_link($term);
            if (is_wp_error($link) || !$link) {
                continue;
            }
            $urls = array_merge($urls, self::paged($link, (int) $term->count, $per));
        }

        return $urls;
    }

    /**
     * @return string[]
     */
    public static function extras()
    {
        $s   = wpse_settings();
        $out = [];

        foreach (preg_split('/\r\n|\r|\n/', (string) $s['extra_urls']) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (strpos($line, 'http') !== 0) {
                $line = home_url('/' . ltrim($line, '/'));
            }
            $out[] = $line;
        }

        return $out;
    }

    /**
     * สร้าง URL หน้า 1..N ของ archive หนึ่งชุด
     *
     * @param string $base
     * @param int    $count
     * @param int    $per
     * @return string[]
     */
    protected static function paged($base, $count, $per)
    {
        $urls  = [$base];
        $pages = (int) ceil($count / max(1, $per));

        if ($pages < 2) {
            return $urls;
        }

        $pretty = (bool) get_option('permalink_structure');

        for ($i = 2; $i <= $pages; $i++) {
            if ($pretty) {
                $urls[] = trailingslashit($base) . 'page/' . $i . '/';
            } else {
                $urls[] = add_query_arg('paged', $i, $base);
            }
        }

        return $urls;
    }
}
