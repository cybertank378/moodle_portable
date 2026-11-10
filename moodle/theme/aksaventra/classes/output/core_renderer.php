<?php
namespace theme_aksaventra\output;

defined('MOODLE_INTERNAL') || die();

class core_renderer extends \theme_boost\output\core_renderer {

    public function favicon() {
        return $this->image_url('favicon', 'theme_aksaventra');
    }

    /**
     * Get the brand logo URL for sidebar and headers.
     *
     * @return string
     */
    public function get_brand_logo_url(): string {
        $theme = \theme_config::load('aksaventra');
        $fileurl = $theme->setting_file_url('brand_logo', 'brand_logo');
        if (!empty($fileurl)) {
            return $fileurl;
        }

        $brand_logo = get_config('theme_aksaventra', 'brand_logo');
        if (!empty($brand_logo)) {
            return \moodle_url::make_pluginfile_url(
                \context_system::instance()->id,
                'theme_aksaventra',
                'brand_logo',
                0,
                '/',
                $brand_logo
            )->out(false);
        }

        $core_logo = $this->get_compact_logo_url() ?: $this->get_logo_url();
        if ($core_logo) {
            return $core_logo->out(false);
        }

        return $this->image_url('logo', 'theme_aksaventra')->out(false);
    }

    /**
     * Get the login page hero logo URL.
     *
     * @return string
     */
    public function get_login_logo_url(): string {
        $theme = \theme_config::load('aksaventra');
        $fileurl = $theme->setting_file_url('login_logo', 'login_logo');
        if (!empty($fileurl)) {
            return $fileurl;
        }

        return $this->get_brand_logo_url();
    }

    /**
     * Get main brand title text from settings or fallback to short site name.
     *
     * @return string
     */
    public function get_brand_main(): string {
        global $SITE;
        $val = get_config('theme_aksaventra', 'brand_main');
        if (!empty($val)) {
            return format_string($val);
        }
        return !empty($SITE->shortname) ? format_string($SITE->shortname) : 'AKSAVENTRA';
    }

    /**
     * Get brand subtitle text from settings or fallback.
     *
     * @return string
     */
    public function get_brand_sub(): string {
        $val = get_config('theme_aksaventra', 'brand_sub');
        if ($val !== false && $val !== null && $val !== '') {
            return format_string($val);
        }
        return 'PORTABLE CBT';
    }

    /**
     * Get brand logo alt text from settings or brand main.
     *
     * @return string
     */
    public function get_brand_alt(): string {
        $alt = get_config('theme_aksaventra', 'brand_alt');
        if (!empty($alt)) {
            return format_string($alt);
        }
        return $this->get_brand_main();
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
