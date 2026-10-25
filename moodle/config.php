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

$CFG->wwwroot   = 'http://127.0.0.1:8080';
$CFG->dataroot  = 'D:/Project/moodle_installer/moodledata';
$CFG->admin     = 'admin';

$CFG->directorypermissions = 0777;

$CFG->enableanalytics = 0;
$CFG->enablebadges = 0;
$CFG->enablecompetencies = 0;
$CFG->enableblogs = 0;
$CFG->messaging = 0;

require_once(__DIR__ . '/lib/setup.php');