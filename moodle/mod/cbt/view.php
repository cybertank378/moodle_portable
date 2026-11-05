<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * CBT activity view page.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once(__DIR__ . '/lib.php');

$id = optional_param('id', 0, PARAM_INT); // Course Module ID.
$c  = optional_param('c', 0, PARAM_INT);  // CBT instance ID.

if ($id) {
    $cm = get_coursemodule_from_id('cbt', $id, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $cbt = $DB->get_record('cbt', ['id' => $cm->instance], '*', MUST_EXIST);
} else {
    $cbt = $DB->get_record('cbt', ['id' => $c], '*', MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cbt->course], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('cbt', $cbt->id, $course->id, false, MUST_EXIST);
}

require_login($course, true, $cm);
$context = \context_module::instance($cm->id);
require_capability('mod/cbt:view', $context);

$PAGE->set_url('/mod/cbt/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($cbt->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($cbt->name));

if (!empty($cbt->intro)) {
    echo $OUTPUT->box(format_module_intro('cbt', $cbt, $cm->id), 'generalbox mod_introbox', 'cbtintro');
}

// Display exam info and launch button
$now = time();
$repo = new \mod_cbt\infrastructure\persistence\db_attempt_repository();
$attempt_rec = $repo->get_user_attempt((int)$cbt->id, (int)$USER->id);

echo '<div class="cbt-summary-card card p-4 my-4 shadow-sm" style="max-width: 650px; margin: auto; border-radius: 16px;">';
echo '<h4 class="mb-3 text-primary">Informasi Ujian</h4>';
echo '<ul class="list-group list-group-flush mb-4">';

$duration_str = $cbt->timelimit > 0 ? format_time($cbt->timelimit) : get_string('unlimited');
echo "<li class=\"list-group-item d-flex justify-content-between\"><span>Durasi Ujian:</span><strong>{$duration_str}</strong></li>";
echo "<li class=\"list-group-item d-flex justify-content-between\"><span>Batas Pelanggaran:</span><strong>{$cbt->maxstrikes} Kali</strong></li>";

if ($attempt_rec) {
    $status_label = ucfirst($attempt_rec->status);
    $status_color = $attempt_rec->status === 'submitted' ? 'success' : ($attempt_rec->status === 'locked' ? 'danger' : 'primary');
    echo "<li class=\"list-group-item d-flex justify-content-between\"><span>Status Pengerjaan:</span><span class=\"badge bg-{$status_color}\">{$status_label}</span></li>";
    if ($attempt_rec->sumgrades !== null) {
        echo "<li class=\"list-group-item d-flex justify-content-between\"><span>Nilai Akhir:</span><strong class=\"text-success\">{$attempt_rec->sumgrades} / {$cbt->grade}</strong></li>";
    }
}

echo '</ul>';

// Launch CTA
if ($attempt_rec && $attempt_rec->status === 'submitted') {
    echo '<div class="alert alert-success text-center">Ujian Anda telah selesai dikirimkan. Terima kasih.</div>';
} else if ($attempt_rec && $attempt_rec->status === 'locked') {
    echo '<div class="alert alert-danger text-center">Ujian Anda terkunci karena pelanggaran integritas. Silakan hubungi proktor di ruang ujian.</div>';
} else {
    $launch_url = new moodle_url('/mod/cbt/launch.php', ['id' => $cm->id]);
    echo '<div class="text-center">';
    echo html_writer::link($launch_url, 'Buka Aplikasi Ujian CBT (Next.js)', [
        'class' => 'btn btn-primary btn-lg px-4 py-2 font-weight-bold shadow',
        'style' => 'border-radius: 12px; background: linear-gradient(135deg, #0d2b5c, #0097a7); border: none;',
    ]);
    echo '</div>';
}

echo '</div>';

echo $OUTPUT->footer();

