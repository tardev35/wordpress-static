<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPSE_Writer
{
    const MAX_SEGMENT = 100;
    const MAX_PATH    = 200;

    /**
     * แปลง URL เป็น path ไฟล์ที่เขียนได้ทั้งบน Linux และ Windows
     *
     * @param string $url
     * @param string $type page|asset
     * @return string path แบบ relative ใช้ / คั่น
     */
    public static function path_for($url, $type)
    {
        $parts = wp_parse_url($url);
        $path  = isset($parts['path']) ? $parts['path'] : '/';
        $query = isset($parts['query']) ? $parts['query'] : '';

        $path = rawurldecode($path);

        $segments = [];
        foreach (explode('/', trim($path, '/')) as $seg) {
            if ($seg === '') {
                continue;
            }
            $segments[] = self::safe_segment($seg);
        }

        if ($type === WPSE_Job::TYPE_ASSET) {
            if (empty($segments)) {
                $segments[] = 'index';
            }

            return self::cap(implode('/', $segments));
        }

        $last = end($segments);
        $ext  = $last === false ? '' : strtolower((string) pathinfo($last, PATHINFO_EXTENSION));

        if ($query !== '') {
            $segments[] = self::safe_segment('q-' . str_replace(['=', '&'], ['-', '_'], $query));
            $ext        = '';
        }

        if ($ext === '' || !preg_match('/^(' . WPSE_Extractor::PAGE_EXT . ')$/', $ext)) {
            $segments[] = 'index.html';
        }

        if (empty($segments)) {
            $segments[] = 'index.html';
        }

        return self::cap(implode('/', $segments));
    }

    /**
     * ตัดอักขระที่ Windows เขียนไม่ได้ และชื่อสงวนของ DOS
     *
     * @param string $segment
     * @return string
     */
    public static function safe_segment($segment)
    {
        $segment = preg_replace('/[\x00-\x1F\x7F]/u', '', $segment);
        $segment = str_replace(['\\', ':', '*', '?', '"', '<', '>', '|'], '-', $segment);
        $segment = rtrim($segment, ". \t");

        if ($segment === '' || $segment === '.' || $segment === '..') {
            $segment = '_';
        }

        if (preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\..*)?$/i', $segment)) {
            $segment = '_' . $segment;
        }

        if (function_exists('mb_strlen') && mb_strlen($segment, 'UTF-8') > self::MAX_SEGMENT) {
            $keep    = mb_substr($segment, 0, self::MAX_SEGMENT - 9, 'UTF-8');
            $segment = $keep . '-' . substr(sha1($segment), 0, 8);
        }

        return $segment;
    }

    /**
     * กัน path ยาวเกินจน Windows เปิดไม่ได้
     *
     * นับ "ตัวอักษร" ไม่ใช่ byte — ภาษาไทยตัวละ 3 byte ใน UTF-8 ถ้าใช้ strlen()
     * ชื่อไฟล์ไทยยาวปกติจะถูกตัดทั้งที่ไม่ได้ยาวจริง แล้วลิงก์ในหน้าเว็บที่ยัง
     * ชี้ path เดิมจะกลายเป็น 404 ทันที
     *
     * @param string $rel
     * @return string
     */
    protected static function cap($rel)
    {
        $len = function_exists('mb_strlen') ? mb_strlen($rel, 'UTF-8') : strlen($rel);

        if ($len <= self::MAX_PATH) {
            return $rel;
        }

        $base = basename($rel);
        $dir  = dirname($rel);
        $hash = substr(sha1($rel), 0, 10);

        $room  = max(1, self::MAX_PATH - mb_strlen($base, 'UTF-8') - 12);
        $short = rtrim(mb_substr($dir, 0, $room, 'UTF-8'), '/');

        return $short . '/' . $hash . '-' . $base;
    }

    /**
     * @param string $dir  โฟลเดอร์รากของงาน export
     * @param string $rel
     * @param string $content
     * @return int|false จำนวน byte ที่เขียน
     */
    public static function write($dir, $rel, $content)
    {
        $full = trailingslashit($dir) . $rel;
        $sub  = dirname($full);

        if (!is_dir($sub) && !wp_mkdir_p($sub)) {
            return false;
        }

        if (is_dir($full)) {
            $full .= '/index.html';
            if (!is_dir(dirname($full))) {
                wp_mkdir_p(dirname($full));
            }
        }

        return file_put_contents($full, $content);
    }

    /**
     * ไล่ไฟล์ทั้งหมดใน export ออกมาเป็น list สำหรับขั้นตอน zip
     *
     * @param string $dir
     * @return string[] path แบบ relative
     */
    public static function scan($dir)
    {
        $out = [];
        $dir = untrailingslashit($dir);

        if (!is_dir($dir)) {
            return $out;
        }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $out[] = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
        }

        sort($out);

        return $out;
    }
}
