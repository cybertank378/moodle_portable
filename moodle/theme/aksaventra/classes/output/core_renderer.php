<?php
namespace theme_aksaventra\output;

defined('MOODLE_INTERNAL') || die();

class core_renderer extends \theme_boost\output\core_renderer {

    public function favicon() {
        return $this->image_url('favicon', 'theme_aksaventra');
    }

    public function body_attributes($additionalclasses = []) {
        global $USER, $COURSE;

        if (!is_array($additionalclasses)) {
            $additionalclasses = explode(' ', $additionalclasses);
        }

        if (isloggedin() && !isguestuser()) {
            $additionalclasses[] = 'aksaventra-dashboard-layout';
            if (is_siteadmin()) {
                $additionalclasses[] = 'role-admin';
            } else {
                $context = \context_course::instance($COURSE->id);
                if (has_capability('moodle/course:manageactivities', $context, $USER->id)) {
                    $additionalclasses[] = 'role-teacher';
                } else {
                    $additionalclasses[] = 'role-student';
                }
            }
        }

        return parent::body_attributes($additionalclasses);
    }
}
