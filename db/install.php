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
 * Install script for Imagehub
 *
 * Documentation: {@link https://moodledev.io/docs/guides/upgrade}
 *
 * @package    repository_imagehub
 * @copyright  2024 ISB Bayern
 * @author     Stefan Hanauska <stefan.hanauska@csg-in.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Executed on installation of Imagehub
 *
 * @return bool
 */
function xmldb_repository_imagehub_install() {
    global $CFG, $DB;

    require_once($CFG->dirroot . '/repository/lib.php');
    require_once($CFG->dirroot . '/repository/imagehub/lib.php');
    require_once($CFG->libdir . '/filelib.php');

    $sysctx = \context_system::instance();

    $recordid = $DB->insert_record('repository_imagehub_sources', [
        'title'        => 'Manual',
        'type'         => repository_imagehub::SOURCE_TYPE_MANUAL_VALUE,
        'timemodified' => time(),
        'lastupdate'   => time(),
    ]);

    $fs     = get_file_storage();
    $packer = get_file_packer('application/zip');

    $fs->delete_area_files($sysctx->id, 'repository_imagehub', 'images', $recordid);
    $fs->create_directory($sysctx->id, 'repository_imagehub', 'images', $recordid, '/');

    $zipdir   = __DIR__ . '/../images';
    $zipnames = ['openmoji.zip', 'iradesign.zip'];

    foreach ($zipnames as $zipname) {
        $localzip = $zipdir . '/' . $zipname;
        if (!file_exists($localzip)) {
            continue;
        }
        $filerec = [
            'contextid' => $sysctx->id,
            'component' => 'repository_imagehub',
            'filearea'  => 'images',
            'itemid'    => $recordid,
            'filepath'  => '/',
            'filename'  => $zipname,
        ];
        $zipfile = $fs->create_file_from_pathname($filerec, $localzip);
        $packer->extract_to_storage($zipfile, $sysctx->id, 'repository_imagehub', 'images', $recordid, '/');
        $zipfile->delete(); // ZIP entfernen
    }

    // Zone.Identifier-Reste entfernen
    $files = $fs->get_area_files($sysctx->id, 'repository_imagehub', 'images', $recordid, 'id', false);
    foreach ($files as $f) {
        if ($f->is_directory()) { continue; }
        $name = $f->get_filename();
        $mime = $f->get_mimetype();
        if (stripos($name, 'Zone.Identifier') !== false
            || stripos($name, 'Zone.Identifier:$DATA') !== false
            || ($mime === 'text/plain' && preg_match('/zone\.identifier/i', $name))) {
            $f->delete();
        }
    }

    // Repo automatisch aktivieren
    $type = $DB->get_record('repository', ['type' => 'imagehub']);
    if (!$type) {
        // Fallback: Typ-Eintrag anlegen, falls noch nicht erstellt.
        $type = (object)[
            'type'      => 'imagehub',
            'visible'   => 1,
            'sortorder' => 0,
        ];
        $type->id = $DB->insert_record('repository', $type);
    } else if ((int)$type->visible !== 1) {
        $DB->set_field('repository', 'visible', 1, ['id' => $type->id]);
    }

    $instanceid = $DB->get_field('repository_instances', 'id',
        ['typeid' => $type->id, 'contextid' => $sysctx->id]);
    if (!$instanceid) {
        $instanceid = $DB->insert_record('repository_instances', (object)[
            'typeid'       => $type->id,
            'name'         => get_string('pluginname', 'repository_imagehub'),
            'contextid'    => $sysctx->id,
            'visible'      => 1,
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);
    }

    $DB->delete_records('repository_instance_config', ['instanceid' => $instanceid, 'name' => 'sourceid']);
    $DB->insert_record('repository_instance_config', (object)[
        'instanceid' => $instanceid,
        'name'       => 'sourceid',
        'value'      => $recordid,
    ]);

    set_config('defaultsourceid', $recordid, 'repository_imagehub');
    return true;
}


