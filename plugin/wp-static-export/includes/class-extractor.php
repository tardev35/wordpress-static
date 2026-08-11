<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPSE_Extractor
{
    const ASSET_EXT = 'jpg|jpeg|jpe|png|gif|webp|avif|svg|svgz|ico|bmp|tif|tiff|css|js|mjs|json|map|woff|woff2|ttf|eot|otf|mp4|m4v|webm|ogv|ogg|oga|mp3|wav|flac|pdf|zip|rar|doc|docx|xls|xlsx|ppt|pptx|csv|txt|xml';

    // xsl คือ stylesheet ของ sitemap ที่ Yoast/core แนบมา เป็น XML ที่ต้องแก้ URL
    // เหมือนหน้าอื่น แต่ต้องเก็บเป็นไฟล์ชื่อเดิม ไม่ใช่โฟลเดอร์ที่มี index.html
    const PAGE_EXT = 'html|htm|xml|xsl|xslt|txt';

    /**
     * ทำ URL ให้เป็น absolute + ตัด fragment + คลาย entity
     *
     * @param string      $url
     * @param string|null $base
     * @return string  คืนค่าว่างเมื่อใช้ไม่ได้
     */
    public static function normalize($url, $base = null)
    {
        $url = trim(html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8'));

        if ($url === '' || $url === '#') {
            return '';
        }

        foreach (['mailto:', 'tel:', 'javascript:', 'data:', 'sms:', 'ftp:', 'callto:', 'whatsapp:', 'intent:'] as $bad) {
            if (stripos($url, $bad) === 0) {
                return '';
            }
        }

        // ตัวแปรของ template ที่ยังไม่ถูกแทนค่า เช่น {$sitemapURL} ใน XSLT ของ sitemap
        // ไม่ใช่ URL จริง ถ้าปล่อยไปจะได้ 301/404 ขยะติดมาทุกรอบ
        if (strpos($url, '{') !== false || strpos($url, '}') !== false) {
            return '';
        }

        $hash = strpos($url, '#');
        if ($hash !== false) {
            $url = substr($url, 0, $hash);
        }
        if ($url === '') {
            return '';
        }

        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        }

        if (!preg_match('#^https?://#i', $url)) {
            $url = self::resolve($url, $base ? $base : home_url('/'));
        }

        if ($url === '') {
            return '';
        }

        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return '';
        }

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : 'https';
        $host   = strtolower($parts['host']);
        $port   = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path   = isset($parts['path']) ? $parts['path'] : '/';
        $query  = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

        $path = preg_replace('#/{2,}#', '/', $path);

        return $scheme . '://' . $host . $port . $path . $query;
    }

    /**
     * แปลง relative path เทียบกับ base ให้เป็น absolute
     *
     * @param string $rel
     * @param string $base
     * @return string
     */
    public static function resolve($rel, $base)
    {
        $b = wp_parse_url($base);
        if (!$b || empty($b['host'])) {
            return '';
        }

        $scheme = isset($b['scheme']) ? $b['scheme'] : 'https';
        $root   = $scheme . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');

        if (strpos($rel, '/') === 0) {
            $path = $rel;
        } else {
            $dir = isset($b['path']) ? $b['path'] : '/';
            if (substr($dir, -1) !== '/') {
                $dir = dirname($dir);
                $dir = $dir === '\\' || $dir === '.' ? '/' : trailingslashit($dir);
            }
            $path = $dir . $rel;
        }

        $qs = '';
        $q  = strpos($path, '?');
        if ($q !== false) {
            $qs   = substr($path, $q);
            $path = substr($path, 0, $q);
        }

        $out = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '.' || $seg === '') {
                continue;
            }
            if ($seg === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $seg;
        }

        $trailing = substr($path, -1) === '/' ? '/' : '';

        return $root . '/' . implode('/', $out) . ($out ? $trailing : '') . $qs;
    }

    /**
     * @param string $url
     * @return bool
     */
    public static function is_internal($url)
    {
        $host = wp_parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return false;
        }

        $home = wp_parse_url(home_url(), PHP_URL_HOST);

        $host = preg_replace('/^www\./i', '', strtolower($host));
        $home = preg_replace('/^www\./i', '', strtolower((string) $home));

        return $host === $home;
    }

    /**
     * URL ที่ไม่ควรเก็บลง static — หลังบ้าน WP, endpoint ที่ตายอยู่แล้ว, หน้าซ้ำจาก query string
     *
     * @param string $url
     * @return bool
     */
    public static function is_excluded($url)
    {
        $lower = strtolower($url);

        $hard = [
            '/wp-admin/',
            'wp-login.php',
            'wp-signup.php',
            'wp-cron.php',
            'wp-trackback.php',
            'xmlrpc.php',
            '/wp-json/',
            'rest_route=',
            '/trackback/',
            'action=lostpassword',
            '?share=',
            '?replytocom',
            'add-to-cart=',
            '?print=',
            '/embed/',
        ];

        foreach ($hard as $needle) {
            if (strpos($lower, $needle) !== false) {
                return true;
            }
        }

        $s = wpse_settings();
        foreach (preg_split('/\r\n|\r|\n/', (string) $s['skip_patterns']) as $pat) {
            $pat = trim($pat);
            if ($pat !== '' && strpos($lower, strtolower($pat)) !== false) {
                return true;
            }
        }

        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        if ($query !== '' && self::classify($url) === WPSE_Job::TYPE_PAGE) {
            if (get_option('permalink_structure')) {
                parse_str($query, $args);
                $allowed = ['paged', 'page'];
                foreach (array_keys($args) as $key) {
                    if (!in_array($key, $allowed, true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param string $url
     * @return string page|asset
     */
    public static function classify($url)
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $ext  = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        if ($ext === '' || preg_match('/^(' . self::PAGE_EXT . ')$/', $ext)) {
            return WPSE_Job::TYPE_PAGE;
        }
        if (preg_match('/^(' . self::ASSET_EXT . ')$/', $ext)) {
            return WPSE_Job::TYPE_ASSET;
        }

        return WPSE_Job::TYPE_PAGE;
    }

    /**
     * ดึง URL ทั้งหมดที่หน้านี้อ้างถึง
     *
     * @param string $html
     * @param string $base
     * @return string[]
     */
    public static function from_html($html, $base)
    {
        $found = [];

        if (preg_match_all('/\s(?:href|src|poster)\s*=\s*(["\'])(.*?)\1/is', $html, $m)) {
            foreach ($m[2] as $v) {
                $found[] = $v;
            }
        }

        $lazy = 'data-src|data-lazy-src|data-original|data-bg|data-background|data-background-image|data-thumb|data-large_image|data-full-url';
        if (preg_match_all('/\s(?:' . $lazy . ')\s*=\s*(["\'])(.*?)\1/is', $html, $m)) {
            foreach ($m[2] as $v) {
                if (self::is_url_like($v)) {
                    $found[] = $v;
                }
            }
        }

        $sets = 'srcset|data-srcset|imagesrcset|data-lazy-srcset';
        if (preg_match_all('/\s(?:' . $sets . ')\s*=\s*(["\'])(.*?)\1/is', $html, $m)) {
            foreach ($m[2] as $v) {
                foreach (self::from_srcset($v) as $u) {
                    $found[] = $u;
                }
            }
        }

        if (preg_match_all('/<meta[^>]+(?:property|name)\s*=\s*["\'](?:og:image|og:image:url|og:image:secure_url|twitter:image|twitter:image:src)["\'][^>]*>/i', $html, $m)) {
            foreach ($m[0] as $tag) {
                if (preg_match('/content\s*=\s*(["\'])(.*?)\1/is', $tag, $c)) {
                    $found[] = $c[2];
                }
            }
        }

        if (preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $html, $m)) {
            foreach ($m[1] as $css) {
                foreach (self::from_css($css, $base) as $u) {
                    $found[] = $u;
                }
            }
        }

        if (preg_match_all('/\sstyle\s*=\s*(["\'])(.*?)\1/is', $html, $m)) {
            foreach ($m[2] as $css) {
                foreach (self::from_css($css, $base) as $u) {
                    $found[] = $u;
                }
            }
        }

        if (preg_match_all('/<loc>\s*(.*?)\s*<\/loc>/is', $html, $m)) {
            foreach ($m[1] as $v) {
                $found[] = $v;
            }
        }

        return self::finalize($found, $base);
    }

    /**
     * ธีมบางตัวใช้ data-* เก็บอย่างอื่นที่ไม่ใช่ URL — Flatsome เขียน
     * data-bg="main-menu-overlay" โดยหมายถึงชื่อคลาส CSS ถ้าเหมาว่าเป็น URL
     * จะได้ 404 หนึ่งใบต่อหนึ่งหน้า
     *
     * @param string $value
     * @return bool
     */
    public static function is_url_like($value)
    {
        $value = trim($value);

        if ($value === '') {
            return false;
        }
        if (preg_match('#^(https?:)?//#i', $value) || strpos($value, '/') !== false) {
            return true;
        }

        return (bool) preg_match('/\.(' . self::ASSET_EXT . ')($|\?)/i', $value);
    }

    /**
     * @param string $css
     * @param string $base
     * @return string[]
     */
    public static function from_css($css, $base)
    {
        $found = [];

        if (preg_match_all('/url\(\s*(["\']?)(.*?)\1\s*\)/is', $css, $m)) {
            foreach ($m[2] as $v) {
                $found[] = $v;
            }
        }

        if (preg_match_all('/@import\s+(?:url\(\s*)?(["\'])(.*?)\1/is', $css, $m)) {
            foreach ($m[2] as $v) {
                $found[] = $v;
            }
        }

        return self::finalize($found, $base);
    }

    /**
     * @param string $value
     * @return string[]
     */
    public static function from_srcset($value)
    {
        $out = [];

        foreach (preg_split('/\s*,\s*/', $value) as $cand) {
            $cand = trim($cand);
            if ($cand === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $cand);
            if (!empty($parts[0])) {
                $out[] = $parts[0];
            }
        }

        return $out;
    }

    /**
     * @param string[] $urls
     * @param string   $base
     * @return string[]
     */
    protected static function finalize($urls, $base)
    {
        $out = [];

        foreach ($urls as $u) {
            $n = self::normalize($u, $base);
            if ($n === '' || !self::is_internal($n) || self::is_excluded($n)) {
                continue;
            }
            $out[$n] = true;
        }

        return array_keys($out);
    }
}
