<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPSE_Packer
{
    const CHUNK = 150;

    const LIST_FILE = 'filelist.txt';

    /**
     * เตรียมรายการไฟล์ทั้งหมดก่อนเริ่ม zip เก็บลงไฟล์ไม่ใช่ option
     * เพราะเว็บใหญ่ๆ รายการนี้ยาวเป็นหมื่นบรรทัด
     *
     * @param array $job
     * @return int จำนวนไฟล์
     */
    public static function prepare(&$job)
    {
        $files = WPSE_Writer::scan($job['dir']);
        $list  = wpse_job_dir($job['id'], self::LIST_FILE);

        file_put_contents($list, implode("\n", $files));

        $job['pack_total']  = count($files);
        $job['pack_cursor'] = 0;
        $job['zip']         = wpse_job_dir($job['id'], wpse_site_slug() . '-static-' . $job['id'] . '.zip');

        if (file_exists($job['zip'])) {
            @unlink($job['zip']);
        }

        return $job['pack_total'];
    }

    /**
     * ใส่ไฟล์ลง zip ทีละก้อน เปิด-ปิด archive ทุกก้อนเพื่อไม่ให้ค้างใน memory
     *
     * @param array $job
     * @return array
     */
    public static function run_batch(&$job)
    {
        if (!class_exists('ZipArchive')) {
            return ['error' => 'เซิร์ฟเวอร์นี้ไม่มี ZipArchive (PHP zip extension) — เปิดใช้ก่อนถึงจะแพ็กไฟล์ได้'];
        }

        $list = wpse_job_dir($job['id'], self::LIST_FILE);
        if (!file_exists($list)) {
            return ['error' => 'ไม่พบรายการไฟล์ ลองเริ่ม export ใหม่'];
        }

        $files  = file($list, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $cursor = (int) $job['pack_cursor'];
        $slice  = array_slice($files, $cursor, self::CHUNK);

        if (empty($slice)) {
            return ['drained' => true, 'added' => 0];
        }

        $zip  = new ZipArchive();
        $flag = $cursor === 0 ? ZipArchive::CREATE | ZipArchive::OVERWRITE : ZipArchive::CREATE;

        if ($zip->open($job['zip'], $flag) !== true) {
            return ['error' => 'เปิดไฟล์ zip ไม่ได้: ' . $job['zip']];
        }

        $added = 0;
        foreach ($slice as $rel) {
            $full = trailingslashit($job['dir']) . $rel;
            if (is_file($full)) {
                $zip->addFile($full, 'dist/' . $rel);
                $added++;
            }
        }

        $zip->close();

        $job['pack_cursor'] = $cursor + count($slice);

        return ['drained' => false, 'added' => $added];
    }

    /**
     * ใส่รายงานเข้าไปใน zip เป็นไฟล์สุดท้าย
     *
     * @param array $job
     * @param array $report
     * @return bool
     */
    public static function attach_report(&$job, $report)
    {
        if (!class_exists('ZipArchive')) {
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($job['zip'], ZipArchive::CREATE) !== true) {
            return false;
        }

        $zip->addFromString('export-report.json', wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $zip->addFromString('site.json', wp_json_encode(self::site_json($job), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $zip->close();

        return true;
    }

    /**
     * ค่าที่ tool ฝั่ง Windows ต้องใช้ตอน deploy ส่งไปพร้อม zip เลยจะได้ไม่ต้องพิมพ์เอง
     *
     * @param array $job
     * @return array
     */
    public static function site_json($job)
    {
        $target = wpse_target();

        return [
            'slug'          => wpse_site_slug(),
            'origin'        => $job['origin'],
            'target_domain' => $target,
            'worker_name'   => wpse_site_slug(),
            'account_id'    => '',
            'exported_at'   => $job['started'],
        ];
    }

    public static function zip_size($job)
    {
        return !empty($job['zip']) && file_exists($job['zip']) ? (int) filesize($job['zip']) : 0;
    }
}
