<?php

if (!defined('ABSPATH')) {
    exit;
}

/** @var array $settings */
/** @var array|null $job */

$running = $job && $job['phase'] !== WPSE_Job::PHASE_DONE;
?>
<div class="wrap wpse-wrap">
    <h1>Static Export <span class="wpse-ver">v<?php echo esc_html(WPSE_VERSION); ?></span></h1>
    <p class="wpse-lead">
        ดึงทุกหน้าของเว็บนี้ออกมาเป็นไฟล์ static แล้วแพ็กเป็น ZIP ไฟล์เดียว
        สำหรับเอาไปวางบน Cloudflare Workers — ผลที่ได้คือหน้าเว็บที่ render แล้วจริงๆ
        จึงรวมผลงานของ plugin ทุกตัวที่เปิดใช้อยู่
    </p>

    <?php if (isset($_GET['saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p>บันทึกค่าตั้งแล้ว</p></div>
    <?php endif; ?>

    <?php if (!class_exists('ZipArchive')) : ?>
        <div class="notice notice-error"><p>
            เซิร์ฟเวอร์นี้ไม่มี PHP zip extension — ดึงหน้าได้แต่แพ็ก ZIP ไม่ได้
            ต้องเปิด <code>ZipArchive</code> ก่อน
        </p></div>
    <?php endif; ?>

    <div class="wpse-grid">
        <div class="wpse-card">
            <h2>1. ตั้งค่า</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wpse_save">
                <?php wp_nonce_field('wpse_save'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="target_domain">โดเมนปลายทาง</label></th>
                        <td>
                            <input type="text" class="regular-text" id="target_domain" name="target_domain"
                                   value="<?php echo esc_attr($settings['target_domain']); ?>"
                                   placeholder="https://newdomain.com">
                            <p class="description">
                                โดเมนที่เว็บ static จะไปอยู่จริง ใช้เขียน canonical / og:url / sitemap
                                ถ้าเว้นว่างจะใช้โดเมนปัจจุบัน (<?php echo esc_html(wpse_origin()); ?>)
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="batch_size">จำนวนต่อรอบ</label></th>
                        <td>
                            <input type="number" id="batch_size" name="batch_size" min="1" max="100"
                                   value="<?php echo esc_attr($settings['batch_size']); ?>" class="small-text">
                            <span class="description">ยิงกี่ URL ต่อหนึ่ง request — โฮสต์ที่ตัดไวให้ลดลง</span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="delay_ms">หน่วงระหว่าง URL</label></th>
                        <td>
                            <input type="number" id="delay_ms" name="delay_ms" min="0" max="5000" step="50"
                                   value="<?php echo esc_attr($settings['delay_ms']); ?>" class="small-text"> มิลลิวินาที
                            <span class="description">กันไม่ให้ export ทำเว็บตัวเองล่ม</span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="timeout">timeout ต่อ URL</label></th>
                        <td>
                            <input type="number" id="timeout" name="timeout" min="5" max="120"
                                   value="<?php echo esc_attr($settings['timeout']); ?>" class="small-text"> วินาที
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="max_urls">เพดานจำนวน URL</label></th>
                        <td>
                            <input type="number" id="max_urls" name="max_urls" min="10" max="100000"
                                   value="<?php echo esc_attr($settings['max_urls']); ?>" class="small-text">
                            <p class="description">
                                กันเว็บที่ลิงก์วนไม่รู้จบ — Workers แผนฟรีรับได้ 20,000 ไฟล์ต่อ Worker
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="extra_urls">URL เพิ่มเอง</label></th>
                        <td>
                            <textarea id="extra_urls" name="extra_urls" rows="3" class="large-text code"
                                      placeholder="/หน้าที่ไม่มีลิงก์ชี้ถึง/"><?php echo esc_textarea($settings['extra_urls']); ?></textarea>
                            <p class="description">บรรทัดละ 1 URL สำหรับหน้าที่ไม่มีลิงก์ไหนชี้ถึงเลย</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="skip_patterns">ข้าม URL ที่มีคำนี้</label></th>
                        <td>
                            <textarea id="skip_patterns" name="skip_patterns" rows="3" class="large-text code"
                                      placeholder="/tag/"><?php echo esc_textarea($settings['skip_patterns']); ?></textarea>
                            <p class="description">บรรทัดละ 1 คำ เทียบแบบมีคำนี้อยู่ใน URL</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">ล้าง tag ของ WordPress</th>
                        <td>
                            <label>
                                <input type="checkbox" name="strip_wp_meta" value="1"
                                    <?php checked(!empty($settings['strip_wp_meta'])); ?>>
                                ตัด shortlink / oEmbed / RSD / generator ที่ตายอยู่แล้วบน static
                            </label>
                        </td>
                    </tr>
                </table>

                <?php submit_button('บันทึกค่าตั้ง'); ?>
            </form>
        </div>

        <div class="wpse-card">
            <h2>2. ส่งออก</h2>

            <p class="wpse-actions">
                <button class="button" id="wpse-selftest">ตรวจก่อนเริ่ม</button>
                <button class="button button-primary" id="wpse-start">เริ่ม export ใหม่</button>
                <button class="button" id="wpse-resume" <?php disabled(!$running); ?>>ทำต่อจากที่ค้าง</button>
                <button class="button" id="wpse-stop" disabled>หยุดพัก</button>
                <button class="button button-link-delete" id="wpse-reset">ล้างงานทิ้ง</button>
            </p>

            <div class="wpse-progress"><div class="wpse-bar" id="wpse-bar"></div></div>
            <p class="wpse-phase" id="wpse-phase">ยังไม่เริ่ม</p>

            <ul class="wpse-stats">
                <li>หน้า <b id="wpse-pages">0</b></li>
                <li>ไฟล์ประกอบ <b id="wpse-assets">0</b></li>
                <li>คิวเหลือ <b id="wpse-pending">0</b></li>
                <li>พลาด <b id="wpse-errors">0</b></li>
            </ul>

            <p id="wpse-zip" class="wpse-zip" hidden>
                <a href="#" class="button button-primary button-hero" id="wpse-download">⬇ โหลด ZIP</a>
                <span id="wpse-zipsize"></span>
            </p>

            <pre class="wpse-log" id="wpse-log"><?php
                echo $job && !empty($job['log']) ? esc_html(implode("\n", (array) $job['log'])) : 'ยังไม่มีบันทึก';
            ?></pre>

            <p class="description">
                ปิดแท็บนี้ได้ระหว่างทาง งานค้างอยู่ที่เดิม กลับมากด "ทำต่อจากที่ค้าง" ได้เลย
            </p>
        </div>
    </div>
</div>
