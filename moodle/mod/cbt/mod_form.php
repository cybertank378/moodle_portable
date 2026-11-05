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
 * The main CBT configuration form definition.
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

class mod_cbt_mod_form extends moodleform_mod {

    public function definition() {
        $mform = $this->_form;

        // 1. General settings
        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('cbtname', 'mod_cbt'), ['size' => '64']);
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements(get_string('intro', 'mod_cbt'));

        // 2. Timing
        $mform->addElement('header', 'timing', get_string('timing', 'quiz'));

        $mform->addElement('date_time_selector', 'timeopen', get_string('timeopen', 'mod_cbt'), ['optional' => true]);
        $mform->addElement('date_time_selector', 'timeclose', get_string('timeclose', 'mod_cbt'), ['optional' => true]);

        $mform->addElement('duration', 'timelimit', get_string('timelimit', 'mod_cbt'), ['optional' => true]);
        $mform->addHelpButton('timelimit', 'timelimit', 'mod_cbt');
        $mform->setDefault('timelimit', 7200); // 120 minutes default

        // 3. Security & Anti-Cheat Settings
        $mform->addElement('header', 'security', get_string('security', 'quiz'));

        $mform->addElement('text', 'maxstrikes', get_string('maxstrikes', 'mod_cbt'), ['size' => '4']);
        $mform->setType('maxstrikes', PARAM_INT);
        $mform->setDefault('maxstrikes', 3);
        $mform->addHelpButton('maxstrikes', 'maxstrikes', 'mod_cbt');

        $mform->addElement('text', 'active_token', get_string('active_token', 'mod_cbt'), ['size' => '10']);
        $mform->setType('active_token', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('active_token', 'active_token', 'mod_cbt');

        $mform->addElement('text', 'tokensecret', get_string('tokensecret', 'mod_cbt'), ['size' => '32']);
        $mform->setType('tokensecret', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('tokensecret', 'tokensecret', 'mod_cbt');

        // 4. Grade
        $this->standard_grading_coursemodule_elements();

        // 5. Standard module elements
        $this->standard_coursemodule_elements();

        // 6. Action buttons
        $this->add_action_buttons();
    }
}

