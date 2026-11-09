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

namespace mod_cbt\application\usecase;

use mod_cbt\infrastructure\cache\muc_snapshot_store;

defined('MOODLE_INTERNAL') || die();

/**
 * Use case to manage questions and question bank from Next.js frontend into Moodle.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manage_question_bank {

    /**
     * Get questions associated with a CBT exam.
     *
     * @param int $cbtid
     * @return array
     */
    public function get_questions(int $cbtid): array {
        global $DB;
        $cbt = $DB->get_record('cbt', ['id' => $cbtid], '*', MUST_EXIST);

        // Fetch current snapshot from cache
        $snapshot = muc_snapshot_store::get_questions_snapshot($cbt);
        return $snapshot;
    }

    /**
     * Invalidate and rebuild MUC snapshot after bank updates.
     *
     * @param int $cbtid
     * @return array
     */
    public function refresh_snapshot(int $cbtid): array {
        global $DB;
        $cbt = $DB->get_record('cbt', ['id' => $cbtid], '*', MUST_EXIST);

        muc_snapshot_store::invalidate($cbtid);
        $rebuilt = muc_snapshot_store::build_and_store_snapshots($cbt);

        return [
            'success' => true,
            'cbt_id' => $cbtid,
            'total_questions' => count($rebuilt['snapshot']),
            'message' => 'Question bank snapshot cache successfully refreshed.',
        ];
    }
}

