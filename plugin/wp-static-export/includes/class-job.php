<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPSE_Job
{
    const OPTION = 'wpse_job';

    const STATUS_PENDING = 0;
    const STATUS_DONE    = 1;
    const STATUS_ERROR   = 2;
    const STATUS_SKIP    = 3;

    const TYPE_PAGE  = 'page';
    const TYPE_ASSET = 'asset';

    const PHASE_IDLE     = 'idle';
    const PHASE_CRAWL    = 'crawl';
    const PHASE_PACK     = 'pack';
    const PHASE_DONE     = 'done';

    public static function table()
    {
        global $wpdb;

        return $wpdb->prefix . 'wpse_queue';
    }

    public static function create_table()
    {
        global $wpdb;

        $table   = self::table();
        $collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta(
            "CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                job_id BIGINT UNSIGNED NOT NULL,
                url_hash CHAR(40) NOT NULL,
                url TEXT NOT NULL,
                type VARCHAR(8) NOT NULL DEFAULT 'page',
                status TINYINT NOT NULL DEFAULT 0,
                http_code SMALLINT NOT NULL DEFAULT 0,
                bytes INT UNSIGNED NOT NULL DEFAULT 0,
                depth SMALLINT NOT NULL DEFAULT 0,
                path TEXT NULL,
                note TEXT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY job_url (job_id, url_hash),
                KEY job_status (job_id, status, id)
            ) {$collate};"
        );

        wpse_protect_root();
    }

    public static function get()
    {
        $job = get_option(self::OPTION, null);

        return is_array($job) ? $job : null;
    }

    public static function save($job)
    {
        update_option(self::OPTION, $job, false);
    }

    public static function start()
    {
        self::create_table();
        self::clear_queue();

        $id  = time();
        $dir = wpse_job_dir($id, 'site');
        wpse_mkdir($dir);

        $job = [
            'id'          => $id,
            'phase'       => self::PHASE_CRAWL,
            'started'     => current_time('mysql'),
            'finished'    => '',
            'dir'         => $dir,
            'zip'         => '',
            'origin'      => wpse_origin(),
            'target'      => wpse_target(),
            'pack_cursor' => 0,
            'pack_total'  => 0,
            'bytes'       => 0,
            'log'         => [],
        ];

        self::save($job);

        return $job;
    }

    public static function reset()
    {
        self::clear_queue();
        delete_option(self::OPTION);
    }

    public static function clear_queue()
    {
        global $wpdb;

        $table = self::table();
        if (self::table_exists()) {
            $wpdb->query("TRUNCATE TABLE {$table}");
        }
    }

    public static function table_exists()
    {
        global $wpdb;

        $table = self::table();

        return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    }

    /**
     * ใส่ URL เข้าคิว ตัวซ้ำถูกกันด้วย UNIQUE KEY ไม่ต้อง SELECT ก่อน
     *
     * @param int      $job_id
     * @param string[] $urls
     * @param string   $type
     * @param int      $depth
     * @return int จำนวนที่เพิ่มเข้าไปจริง
     */
    public static function push($job_id, $urls, $type = self::TYPE_PAGE, $depth = 0)
    {
        global $wpdb;

        if (empty($urls)) {
            return 0;
        }

        $table = self::table();
        $added = 0;

        foreach (array_unique($urls) as $url) {
            $url = (string) $url;
            if ($url === '') {
                continue;
            }

            $ok = $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$table} (job_id, url_hash, url, type, status, depth)
                     VALUES (%d, %s, %s, %s, %d, %d)",
                    $job_id,
                    sha1($url),
                    $url,
                    $type,
                    self::STATUS_PENDING,
                    $depth
                )
            );

            if ($ok) {
                $added++;
            }
        }

        return $added;
    }

    public static function claim($job_id, $limit)
    {
        global $wpdb;

        $table = self::table();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, url, type, depth FROM {$table}
                 WHERE job_id = %d AND status = %d
                 ORDER BY id ASC
                 LIMIT %d",
                $job_id,
                self::STATUS_PENDING,
                (int) $limit
            ),
            ARRAY_A
        );
    }

    public static function finish_row($id, $status, $code = 0, $bytes = 0, $path = '', $note = '')
    {
        global $wpdb;

        $wpdb->update(
            self::table(),
            [
                'status'    => $status,
                'http_code' => (int) $code,
                'bytes'     => (int) $bytes,
                'path'      => $path,
                'note'      => $note,
            ],
            ['id' => (int) $id],
            ['%d', '%d', '%d', '%s', '%s'],
            ['%d']
        );
    }

    public static function counts($job_id)
    {
        global $wpdb;

        $table = self::table();
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT status, type, COUNT(*) AS n, SUM(bytes) AS b
                 FROM {$table} WHERE job_id = %d GROUP BY status, type",
                $job_id
            ),
            ARRAY_A
        );

        $out = [
            'pending' => 0,
            'done'    => 0,
            'error'   => 0,
            'skipped' => 0,
            'pages'   => 0,
            'assets'  => 0,
            'total'   => 0,
            'bytes'   => 0,
        ];

        foreach ((array) $rows as $r) {
            $n = (int) $r['n'];
            $out['total'] += $n;
            $out['bytes'] += (int) $r['b'];

            if ((int) $r['status'] === self::STATUS_PENDING) {
                $out['pending'] += $n;
            } elseif ((int) $r['status'] === self::STATUS_DONE) {
                $out['done'] += $n;
                if ($r['type'] === self::TYPE_PAGE) {
                    $out['pages'] += $n;
                } else {
                    $out['assets'] += $n;
                }
            } elseif ((int) $r['status'] === self::STATUS_ERROR) {
                $out['error'] += $n;
            } else {
                $out['skipped'] += $n;
            }
        }

        return $out;
    }

    public static function failures($job_id, $limit = 200)
    {
        global $wpdb;

        $table = self::table();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT url, http_code, note FROM {$table}
                 WHERE job_id = %d AND status = %d ORDER BY id ASC LIMIT %d",
                $job_id,
                self::STATUS_ERROR,
                (int) $limit
            ),
            ARRAY_A
        );
    }

    /**
     * หน้าที่ export ออกมาเป็น meta-refresh เพราะต้นทางตอบ 3xx
     *
     * @param int $job_id
     * @return array
     */
    public static function redirects($job_id)
    {
        global $wpdb;

        $table = self::table();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT url, http_code, path, note FROM {$table}
                 WHERE job_id = %d AND status = %d AND note LIKE 'redirect %%'
                 ORDER BY id ASC",
                $job_id,
                self::STATUS_DONE
            ),
            ARRAY_A
        );
    }

    public static function log(&$job, $line)
    {
        if (!isset($job['log']) || !is_array($job['log'])) {
            $job['log'] = [];
        }

        $job['log'][] = gmdate('H:i:s') . '  ' . $line;
        if (count($job['log']) > 60) {
            $job['log'] = array_slice($job['log'], -60);
        }
    }
}
