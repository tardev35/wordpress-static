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
     * @return string
     */
    public static function apply($content, $kind)
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
        foreach (self::origin_variants() as $variant) {
            $content = str_replace($variant, '', $content);
        }

        foreach (self::escaped_variants() as $variant) {
            $content = str_replace($variant, '', $content);
        }

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
        $target = wpse_target();

        foreach (self::origin_variants() as $variant) {
            $content = str_replace($variant, $target, $content);
        }

        foreach (self::escaped_variants() as $variant) {
            $content = str_replace($variant, str_replace('/', '\/', $target), $content);
        }

        return $content;
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
     * รูปแบบที่ถูก escape ไว้ใน JSON/JS เช่น https:\/\/example.com
     *
     * @return string[]
     */
    public static function escaped_variants()
    {
        $out = [];

        foreach (self::origin_variants() as $v) {
            $out[] = str_replace('/', '\/', $v);
        }

        return $out;
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
