(function () {
    'use strict';

    var running = false;

    function $(id) {
        return document.getElementById(id);
    }

    function post(action, done, fail) {
        var body = new URLSearchParams();
        body.append('action', action);
        body.append('nonce', WPSE.nonce);

        fetch(WPSE.ajax, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.success) {
                    done(res.data);
                } else {
                    fail((res && res.data && res.data.message) || 'ไม่ทราบสาเหตุ');
                }
            })
            .catch(function (e) { fail(String(e)); });
    }

    var PHASE_TEXT = {
        idle: 'ยังไม่เริ่ม',
        crawl: 'กำลังดึงหน้าเว็บ',
        pack: 'กำลังแพ็ก ZIP',
        done: 'เสร็จแล้ว'
    };

    function paint(d) {
        if (!d) {
            return;
        }

        var pct = d.percent || 0;
        $('wpse-bar').style.width = pct + '%';
        $('wpse-phase').textContent = (PHASE_TEXT[d.phase] || d.phase) + ' — ' + pct + '%';

        if (d.counts) {
            $('wpse-pages').textContent = d.counts.pages || 0;
            $('wpse-assets').textContent = d.counts.assets || 0;
            $('wpse-pending').textContent = d.counts.pending || 0;
            $('wpse-errors').textContent = d.counts.error || 0;
        }

        if (d.log && d.log.length) {
            $('wpse-log').textContent = d.log.join('\n');
        }

        if (d.zip && d.zip.ready) {
            $('wpse-zip').hidden = false;
            $('wpse-download').href = d.zip.url;
            $('wpse-zipsize').textContent = d.zip.size;
        }
    }

    function stop() {
        running = false;
        $('wpse-stop').disabled = true;
        $('wpse-start').disabled = false;
        $('wpse-resume').disabled = false;
    }

    function loop() {
        if (!running) {
            return;
        }

        post('wpse_step', function (d) {
            paint(d);

            if (d.phase === 'done') {
                stop();

                return;
            }

            setTimeout(loop, 120);
        }, function (msg) {
            $('wpse-log').textContent += '\nหยุดเพราะ: ' + msg;
            stop();
        });
    }

    function begin() {
        running = true;
        $('wpse-stop').disabled = false;
        $('wpse-start').disabled = true;
        $('wpse-resume').disabled = true;
        $('wpse-zip').hidden = true;
        loop();
    }

    document.addEventListener('DOMContentLoaded', function () {
        $('wpse-start').addEventListener('click', function (e) {
            e.preventDefault();
            if (!window.confirm('เริ่ม export ใหม่ทั้งหมด งานเดิมที่ค้างอยู่จะถูกล้าง')) {
                return;
            }
            $('wpse-start').disabled = true;
            post('wpse_start', function (d) {
                paint(d);
                begin();
            }, function (msg) {
                window.alert('เริ่มไม่ได้: ' + msg);
                $('wpse-start').disabled = false;
            });
        });

        $('wpse-resume').addEventListener('click', function (e) {
            e.preventDefault();
            begin();
        });

        $('wpse-stop').addEventListener('click', function (e) {
            e.preventDefault();
            stop();
        });

        $('wpse-selftest').addEventListener('click', function (e) {
            e.preventDefault();
            $('wpse-selftest').disabled = true;
            $('wpse-log').textContent = 'กำลังตรวจ...';

            post('wpse_selftest', function (d) {
                $('wpse-selftest').disabled = false;
                $('wpse-log').textContent = (d.ok ? '[ok] ' : '[!!] ') + d.text;
                if (!d.ok) {
                    window.alert(d.text);
                }
            }, function (msg) {
                $('wpse-selftest').disabled = false;
                $('wpse-log').textContent = 'ตรวจไม่สำเร็จ: ' + msg;
            });
        });

        $('wpse-reset').addEventListener('click', function (e) {
            e.preventDefault();
            if (!window.confirm('ล้างงาน export ทิ้งทั้งหมด?')) {
                return;
            }
            post('wpse_reset', function () {
                window.location.reload();
            }, function (msg) {
                window.alert(msg);
            });
        });

        post('wpse_status', paint, function () {});
    });
})();
