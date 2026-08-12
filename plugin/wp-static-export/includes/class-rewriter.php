<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPSE_Rewriter
{
    const TOKEN = "\x02WPSE_ABS\x03";

    /**
     * แก้เนื้อไฟล์ก่อนเขียนลงดิสก์ ตามชนิดของไฟล์
     *
     * @param string $content
     * @param string $kind html|css|xml|script
     * @param string $rel  path ปลายทางในไฟล์ zip ใช้แยกไฟล์ที่ต้องแก้เป็นพิเศษ
     * @return string
     */
    public static function apply($content, $kind, $rel = '')
    {
        $settings = wpse_settings();

        if ($kind === 'html') {
            if (!empty($settings['strip_wp_meta'])) {
                $content = self::strip_wp_meta($content);
            }
            $content = self::absolutise_seo_tags($content);
        }

        if ($kind === 'xml') {
            $content = self::to_target($content);

            if (basename(str_replace('\\', '/', $rel)) === 'robots.txt') {
                $content = self::robots($content);
            }

            return $content;
        }

        $content = self::to_relative($content);
        $content = str_replace(self::TOKEN, wpse_target(), $content);

        return $content;
    }

    /**
     * ทุกที่ที่ชี้โดเมนต้นทาง → path แบบ root-relative
     *
     * @param string $content
     * @return string
     */
    public static function to_relative($content)
    {
        $content = preg_replace(self::origin_pattern(false), '', $content);
        $content = preg_replace(self::origin_pattern(true), '', $content);

        foreach (self::encoded_variants() as $variant) {
            $content = str_replace($variant, rawurlencode(wpse_target()), $content);
        }

        return $content;
    }

    /**
     * ทุกที่ที่ชี้โดเมนต้นทาง → โดเมนปลายทางแบบเต็ม (ใช้กับ sitemap/feed ที่ต้องเป็น absolute)
     *
     * @param string $content
     * @return string
     */
    public static function to_target($content)
    {
        $target  = wpse_target();
        $escaped = str_replace('/', '\/', $target);

        $content = preg_replace_callback(
            self::origin_pattern(false),
            function () use ($target) {
                return $target;
            },
            $content
        );

        $content = preg_replace_callback(
            self::origin_pattern(true),
            function () use ($escaped) {
                return $escaped;
            },
            $content
        );

        return $content;
    }

    /**
     * โดเมนต้นทางทุกรูปแบบในนัดเดียว — https:// http:// และ //host (มี www หรือไม่มีก็ได้)
     *
     * ต้องเป็น regex ตัวเดียวจบในรอบเดียว ห้ามไล่ str_replace ทีละแบบ เพราะตอนที่โดเมน
     * ปลายทางเท่ากับต้นทาง (ย้ายโฮสต์แต่ใช้โดเมนเดิม ซึ่งเป็นเคสปกติของงานนี้) รอบของ
     * //host จะไปเจอ //host ที่ซ่อนอยู่ใน https://host ที่เพิ่งเขียนลงไปเอง แล้วเขียนทับ
     * เป็น https:https://host ทั้ง sitemap และ feed
     *
     * @param bool $escaped จับรูปแบบที่ escape ไว้ใน JSON/JS (https:\/\/host)
     * @return string
     */
    protected static function origin_pattern($escaped)
    {
        $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        $bare = preg_quote(preg_replace('/^www\./', '', $host), '#');
        $sep  = $escaped ? '\\\\/' : '/';

        return '#(?:https?:)?' . $sep . $sep . '(?:www\.)?' . $bare . '#i';
    }

    /**
     * robots.txt ของ WordPress สั่งห้ามบอตเข้าหลังบ้าน แต่บน static ไม่มีหลังบ้านให้เข้า
     * บรรทัดพวกนั้นเลยเหลือค่าเป็นแค่ป้ายบอกคนอ่านว่าเว็บนี้เคยเป็น WordPress
     *
     * @param string $txt
     * @return string
     */
    public static function robots($txt)
    {
        $dead = '#wp-admin|wp-login\.php|wp-json|xmlrpc\.php|wp-cron\.php|wp-signup\.php|wp-trackback\.php#i';

        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $txt) as $line) {
            if (preg_match('/^\s*(?:dis)?allow\s*:/i', $line) && preg_match($dead, $line)) {
                continue;
            }
            if (preg_match('/^(\s*sitemap\s*:\s*)(\S+)(.*)$/i', $line, $m)) {
                $line = $m[1] . preg_replace('#(?<!:)/{2,}#', '/', $m[2]) . $m[3];
            }
            $out[] = $line;
        }

        return preg_replace('/\n{3,}/', "\n\n", implode("\n", $out));
    }

    /**
     * canonical / og:url / og:image / twitter:image ต้องเป็น URL เต็มของโดเมนใหม่
     * ไม่งั้น Google กับปุ่มแชร์อ่านไม่รู้เรื่อง
     *
     * @param string $html
     * @return string
     */
    public static function absolutise_seo_tags($html)
    {
        $patterns = [
            '/(<link[^>]+rel\s*=\s*["\']canonical["\'][^>]*href\s*=\s*["\'])([^"\']+)(["\'])/i',
            '/(<meta[^>]+(?:property|name)\s*=\s*["\'](?:og:url|og:image|og:image:url|og:image:secure_url|twitter:image|twitter:image:src|twitter:url)["\'][^>]*content\s*=\s*["\'])([^"\']+)(["\'])/i',
            '/(<meta[^>]+content\s*=\s*["\'])([^"\']+)(["\'][^>]*(?:property|name)\s*=\s*["\'](?:og:url|og:image|og:image:url|og:image:secure_url|twitter:image|twitter:image:src|twitter:url)["\'])/i',
        ];

        foreach ($patterns as $re) {
            $html = preg_replace_callback(
                $re,
                function ($m) {
                    $url = $m[2];
                    foreach (self::origin_variants() as $variant) {
                        if (strpos($url, $variant) === 0) {
                            $url = self::TOKEN . substr($url, strlen($variant));

                            return $m[1] . $url . $m[3];
                        }
                    }
                    if (strpos($url, '/') === 0) {
                        $url = self::TOKEN . $url;
                    }

                    return $m[1] . $url . $m[3];
                },
                $html
            );
        }

        return $html;
    }

    /**
     * ลบ tag ที่ชี้ไปยัง endpoint ของ WordPress ซึ่งไม่มีอยู่แล้วบน static
     *
     * @param string $html
     * @return string
     */
    public static function strip_wp_meta($html)
    {
        $kill = [
            '/<link[^>]+rel=["\']EditURI["\'][^>]*>\s*/i',
            '/<link[^>]+rel=["\']wlwmanifest["\'][^>]*>\s*/i',
            '/<link[^>]+rel=["\']shortlink["\'][^>]*>\s*/i',
            '/<link[^>]+rel=["\']pingback["\'][^>]*>\s*/i',
            '/<link[^>]+rel=["\']https:\/\/api\.w\.org\/["\'][^>]*>\s*/i',
            '/<link[^>]+type=["\'][^"\']*oembed[^"\']*["\'][^>]*>\s*/i',
            '/<link[^>]+href=["\'][^"\']*\/wp-json\/[^"\']*["\'][^>]*>\s*/i',
            '/<link[^>]+rel=["\']dns-prefetch["\'][^>]*s\.w\.org[^>]*>\s*/i',
            '/<meta[^>]+name=["\']generator["\'][^>]*>\s*/i',
            '/<script[^>]+wp-emoji-release\.min\.js[^>]*>\s*<\/script>\s*/i',
            // speculationrules ของ WP 6.8 เป็นบัญชี URL ที่ห้าม prefetch ซึ่งครึ่งหนึ่ง
            // เป็น path ของหลังบ้านที่ static ไม่มีอยู่แล้ว เก็บไว้ก็ไม่ได้ทำอะไร
            '/<script[^>]+type=["\']speculationrules["\'][^>]*>.*?<\/script>\s*/is',
        ];

        foreach ($kill as $re) {
            $html = preg_replace($re, '', $html);
        }

        $html = preg_replace('/<script[^>]*>\s*window\._wpemojiSettings\s*=.*?<\/script>\s*/is', '', $html);

        return $html;
    }

    /**
     * @return string[]
     */
    public static function origin_variants()
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        $bare = preg_replace('/^www\./', '', $host);

        $hosts = array_unique([$host, $bare, 'www.' . $bare]);
        $out   = [];

        foreach ($hosts as $h) {
            $out[] = 'https://' . $h;
            $out[] = 'http://' . $h;
            $out[] = '//' . $h;
        }

        $cache = $out;

        return $cache;
    }

    /**
     * รูปแบบที่ถูก percent-encode ไว้ใน query string ของปุ่มแชร์
     *
     * @return string[]
     */
    public static function encoded_variants()
    {
        $out = [];

        foreach (self::origin_variants() as $v) {
            if (strpos($v, 'http') === 0) {
                $out[] = rawurlencode($v);
            }
        }

        return $out;
    }
}
