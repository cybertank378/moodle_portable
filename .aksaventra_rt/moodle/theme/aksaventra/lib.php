<?php
defined('MOODLE_INTERNAL') || die();

function theme_aksaventra_get_main_scss_content($theme) {
    global $CFG;
    $scss = '';
    $defaultpreset = $CFG->dirroot . '/theme/boost/scss/preset/default.scss';
    if (file_exists($defaultpreset)) {
        $scss .= file_get_contents($defaultpreset);
    }
    return $scss;
}

function theme_aksaventra_get_pre_scss($theme) {
    $scss = '';
    $prescssfile = __DIR__ . '/scss/pre.scss';
    if (file_exists($prescssfile)) {
        $scss .= file_get_contents($prescssfile);
    }
    return $scss;
}

function theme_aksaventra_get_extra_scss($theme) {
    $scss = '';
    $components = [
        '/scss/components/_login.scss',
        '/scss/components/_sidebar.scss',
        '/scss/components/_roles.scss',
        '/scss/components/_cbt_quiz.scss',
        '/scss/post.scss',
    ];

    foreach ($components as $file) {
        $fullpath = __DIR__ . $file;
        if (file_exists($fullpath)) {
            $scss .= "\n" . file_get_contents($fullpath);
        }
    }

    if (!empty($theme->settings->scss)) {
        $scss .= "\n" . $theme->settings->scss;
    }
    if (!empty($theme->settings->scsspost)) {
        $scss .= "\n" . $theme->settings->scsspost;
    }

    return $scss;
}

/**
 * Serves any files associated with the theme settings.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool
 */
function theme_aksaventra_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel != CONTEXT_SYSTEM) {
        return false;
    }
    if ($filearea === 'brand_logo') {
        $theme = theme_config::load('aksaventra');
        return $theme->setting_file_serve('brand_logo', $args, $forcedownload, $options);
    }
    return false;
}
