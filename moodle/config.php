<?php  // Moodle configuration file

unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = 'mariadb';
$CFG->dblibrary = 'native';
$CFG->dbhost    = '127.0.0.1';
$CFG->dbname    = 'moodle';
$CFG->dbuser    = 'root';
$CFG->dbpass    = '';
$CFG->prefix    = 'mdl_';
$CFG->dboptions = array (
  'dbpersist' => 0,
  'dbport' => 3306,
  'dbsocket' => '',
  'dbcollation' => 'utf8mb4_unicode_ci',
);

$CFG->wwwroot   = !empty($_SERVER['HTTP_HOST']) ? (((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://') . $_SERVER['HTTP_HOST']) : 'http://192.168.0.4:8080';
$CFG->dataroot  = dirname(__DIR__) . '/moodledata';
$CFG->tempdir   = $CFG->dataroot . '/temp';
$CFG->cachedir  = $CFG->dataroot . '/cache';
$CFG->localcachedir = $CFG->dataroot . '/localcache';

$CFG->admin     = 'admin';

$CFG->directorypermissions = 0777;

$CFG->enableanalytics = 0;
$CFG->enablebadges = 0;
$CFG->enablecompetencies = 0;
$CFG->enableblogs = 0;
$CFG->messaging = 0;

$CFG->session_handler_class = '\core\session\file';
$CFG->session_file_save_path = $CFG->dataroot . '/sessions';

require_once(__DIR__ . '/lib/setup.php');