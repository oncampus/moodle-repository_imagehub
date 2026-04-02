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
 * repository_imagehub plugin implementation
 *
 * Documentation: {@link https://moodledev.io/docs/apis/plugintypes/repository}
 *
 * @package    repository_imagehub
 * @copyright  2024 ISB Bayern
 * @author     Stefan Hanauska <stefan.hanauska@csg-in.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use repository_imagehub\form\imagehub_zip_processor;

require_once($CFG->dirroot . '/repository/lib.php');

/**
 * Repository repository_imagehub implementation
 *
 * @package    repository_imagehub
 * @copyright  2024 ISB Bayern
 * @author     Stefan Hanauska <stefan.hanauska@csg-in.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository_imagehub extends repository {
    /** @var string The manual source type. */
    public const SOURCE_TYPE_MANUAL = 'manual';
    /** @var string The value of the manual source type. */
    public const SOURCE_TYPE_MANUAL_VALUE = '0';
    /** @var string The zip source type. */
    public const SOURCE_TYPE_ZIP = 'zip';
    /** @var string The value of the zip source type. */
    public const SOURCE_TYPE_ZIP_VALUE = '1';

    /**
     * Returns a structured list of ZIP files or their extracted image contents.
     *
     * - If the path is '/' (root), it lists all available ZIP files in the repository directory.
     *   Each ZIP is represented as a folder-like item, optionally with a preview thumbnail.
     *
     * - If the path starts with 'zip:', it lists all valid image files extracted from that ZIP archive.
     *   The images must have been previously extracted into Moodle's file storage.
     *
     * This method is part of a Moodle repository plugin.
     * See details on {@link http://docs.moodle.org/dev/Repository_plugins}
     *
     * @param string $path The path within the repository:
     *                     - '/' to list all ZIP files
     *                     - 'zip:filename' to show the contents of a specific ZIP
     * @param string $page (Unused) Page number – pagination is not implemented
     * @return array List data for the repository browser, with the following keys:
     *               - list: array of file or folder entries with metadata
     *               - path: (only for 'zip:' paths) breadcrumb-style navigation
     *               - norefresh, nologin, dynload, nosearch: repository display flags
     * @throws dml_exception
     * @throws coding_exception
     */
    public function get_listing($path = '', $page = ''): array {

        $path = $path ?: '/';
        $pluginroot = core_component::get_plugin_directory('repository', 'imagehub');
        $imagedir   = $pluginroot . '/images';

        $listing = [
            'list'      => [],
            'norefresh' => true,
            'nologin'   => true,
            'dynload'   => true,
            'nosearch'  => false,
        ];

        $fs = get_file_storage();
        $context = context_system::instance();

        foreach (glob($imagedir . '/*.zip') ?: [] as $zipfile) {
            $base = pathinfo($zipfile, PATHINFO_FILENAME);
            $itemid = crc32($base);

            $files = $fs->get_area_files($context->id, 'repository_imagehub', 'images', $itemid, 'id', false);

            if (!self::has_images($files)) {
                $processor = new imagehub_zip_processor();
                $processor->extract_zip_to_filearea($zipfile, $itemid);
                $files = $fs->get_area_files($context->id, 'repository_imagehub', 'images', $itemid, 'id', false);
            }

            $thumburl = self::get_thumb_url($files, $itemid);

            $listing['list'][] = [
                'title'     => $base,
                'path'      => 'zip:' . $base,
                'children'  => [],
                'thumbnail' => $thumburl,
                'nosearch'  => false,
            ];
        }

        if (str_starts_with($path, 'zip:')) {
            $zipname = substr($path, 4);
            $itemid = crc32($zipname);

            $listing['list'] = self::get_list_zip($itemid);

            $listing['path'] = [
                ['name' => get_string('pluginname', 'repository_imagehub'), 'path' => '/'],
                ['name' => $zipname, 'path' => 'zip:' . $zipname],
            ];
            return $listing;
        }
        return $listing;
    }

    /**
     * checks is file is imagefile
     *
     * @param stored_file $file
     * @return bool
     */
    private static function is_image_file(stored_file $file): bool {
        $types = core_filetypes::get_types();

        $ext = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
        return isset($types[$ext]) && !empty($types[$ext]['type']) && $types[$ext]['type'] === 'image';
    }

    /**
     * checks if directory has imagefiles
     *
     * @param array $files
     * @return bool
     */
    private static function has_images(array $files): bool {
        foreach ($files as $f) {
            if ($f->is_directory()) {
                continue;
            }
            if (self::is_image_file($f)) {
                return true;
            }
        }
        return false;
    }

    /**
     * gets thumbnail for folders
     *
     * @param array $files
     * @param int $itemid
     * @return string|null
     * @throws coding_exception
     * @throws dml_exception
     */
    private static function get_thumb_url(array $files, int $itemid): ?string {
        foreach ($files as $f) {
            if ($f->is_directory()) {
                continue;
            }
            if (!self::is_image_file($f)) {
                return self::get_first_image($itemid);
            }
        }
        return null;
    }

    /**
     * Determines whether a given filename is a valid image file.
     *
     * Hidden files (e.g. dotfiles) and unsupported extensions are excluded.
     *
     * @param string $filename The name of the file to check.
     * @return bool True if the file is a valid image; false otherwise.
     */
    private static function is_valid_image(string $filename): bool {
        if (str_starts_with($filename, '.') || str_starts_with($filename, '._')) {
            return false;
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, ['svg', 'png', 'jpg', 'jpeg', 'gif', 'webp']);
    }

    /**
     * Returns the thumbnail URL of the first valid image file for a given item ID.
     *
     * Scans the file area associated with the given item ID and returns the thumbnail
     * of the first non-directory, image file that matches the accepted extensions.
     *
     * @param int $itemid The item ID used to locate the file area.
     * @return string|null The thumbnail URL, or null if no valid image is found.
     * @throws dml_exception
     * @throws coding_exception
     */
    private static function get_first_image(int $itemid): ?string {
        $fs = get_file_storage();
        $context = context_system::instance();
        $files = $fs->get_area_files($context->id, 'repository_imagehub', 'images', $itemid, 'id', false);

        foreach ($files as $file) {
            if ($file->is_directory()) {
                continue;
            }
            if (!self::is_valid_image($file->get_filename())) {
                continue;
            }

            $thumb = self::get_thumbnail($file, 'thumb');
            return $thumb ? self::get_thumbnail_url($thumb, 'thumb')->out(false) : null;
        }
        return null;
    }

    /**
     * Returns a list of valid image files extracted from a ZIP archive, identified by item ID.
     *
     * Each image is returned with metadata for display in the repository browser, including icons
     * and thumbnails. Only non-directory files with supported image extensions are included.
     *
     * @param int $itemid The item ID corresponding to the extracted ZIP contents.
     * @return array List of image file metadata arrays.
     * @throws dml_exception
     * @throws coding_exception
     */
    private static function get_list_zip(int $itemid): array {
        global $OUTPUT;

        $fs = get_file_storage();
        $context = context_system::instance();
        $files = $fs->get_area_files($context->id, 'repository_imagehub', 'images', $itemid, 'id', false);

        $list = [];

        foreach ($files as $file) {
            if ($file->is_directory()) {
                continue;
            }
            if (!self::is_valid_image($file->get_filename())) {
                continue;
            }

            $fallback  = $OUTPUT->image_url(file_extension_icon($file->get_filename()))->out(false);
            $thumbfile = self::get_thumbnail($file, 'thumb');
            $iconfile  = self::get_thumbnail($file, 'icon');

            $thumburl = $thumbfile ? self::get_thumbnail_url($thumbfile, 'thumb')->out(false) : $fallback;
            $iconurl  = $iconfile ? self::get_thumbnail_url($iconfile, 'icon')->out(false) : $fallback;

            $entry = [
                'title'     => $file->get_filename(),
                'source'    => $file->get_id(),
                'size'      => $file->get_filesize(),

                'thumbnail' => $thumburl,
                'icon'      => $iconurl,

                'realthumbnail' => $thumburl,
                'realicon'      => $iconurl,

                'mimetype'      => $file->get_mimetype(),
                'nosearch'      => false,
            ];
            $list[] = $entry;
        }
        return $list;
    }

    /**
     * Search for files.
     *
     * @param string $search
     * @param int $page
     * @return array
     */
    public function search($search, $page = 0): array {
        return [
            'list' => self::get_file_list('', $page, $search),
            'norefresh' => true,
            'nologin' => true,
            'dynload' => true,
            'nosearch' => false,
            'issearchresult' => true,
        ];
    }

    /**
     * Get a list of files.
     * @param string $path
     * @param string $page
     * @param string $search
     * @return array
     * @throws dml_exception
     * @throws ddl_exception
     */
    public function get_file_list(string $path = '', string $page = '', string $search = ''): array {
        global $DB;

        $filearea = 'images';

        $pluginroot = core_component::get_plugin_directory('repository', 'imagehub');
        $imagedir   = $pluginroot . '/images';
        $search = trim((string)$search);

        $sourceids = self::get_source_ids_from_zip_directory($imagedir);
        $files = self::get_imagehub_files($sourceids, $filearea, $path);

        $results = [];
        if ($DB->get_manager()->table_exists('repository_imagehub')) {
            $results = $DB->get_records('repository_imagehub', null, '', 'fileid, title, description');
        }

        return self::build_filtered_filelist($files, $search, $results);
    }

    /**
     * Building filtered filelist from all files information
     *
     * @param array $files
     * @param string $search
     * @param array $metadata
     * @return array
     * @throws dml_exception
     */
    private static function build_filtered_filelist(array $files, string $search, array $metadata): array {
        global $OUTPUT;

        $filelist = [];

        foreach ($files as $file) {
            $filename = $file->get_filename();

            if ($search && mb_stripos($filename, $search) === false) {
                continue;
            }

            if (!str_starts_with($file->get_mimetype(), 'image')) {
                continue;
            }

            $fallbackicon = $OUTPUT->image_url(file_extension_icon($filename))->out(false);
            $fileid = $file->get_id();

            $entry = [
                'title' => $filename,
                'shorttitle' => $metadata[$fileid]->title ?? $filename,
                'size' => $file->get_filesize(),
                'filename' => $filename,
                'thumbnail' => $fallbackicon,
                'icon' => $fallbackicon,
                'realthumbnail' => self::get_thumbnail_url($file, 'thumb')->out(false),
                'realicon'      => self::get_thumbnail_url($file, 'icon')->out(false),
                'source' => $fileid,
                'author' => $file->get_author(),
                'license' => $file->get_license() ?? 'U',
            ];

            if ($file->get_mimetype() === 'image/svg+xml') {
                $entry['image_width'] = 100;
                $entry['image_height'] = 100;
            } else if ($imageinfo = @getimagesizefromstring($file->get_content())) {
                $entry['image_width'] = $imageinfo[0];
                $entry['image_height'] = $imageinfo[1];
            }
            $filelist[] = $entry;
        }
        return $filelist;
    }

    /**
     * getting source ids from .zip
     *
     * @param string $dir
     * @return array
     */
    private static function get_source_ids_from_zip_directory(string $dir): array {
        $sourceids = [];
        foreach (glob($dir . '/*.zip') ?: [] as $zipfile) {
            $zipname = pathinfo($zipfile, PATHINFO_FILENAME);
            $sourceids[] = crc32($zipname);
        }
        return $sourceids;
    }

    /**
     * getting all files from repository
     *
     * @param array $sourceids
     * @param string $filearea
     * @param string $path
     * @return array
     * @throws dml_exception
     */
    private static function get_imagehub_files(array $sourceids, string $filearea, string $path): array {
        $fs = get_file_storage();
        $contextid = context_system::instance()->id;
        $files = [];

        foreach ($sourceids as $sourceid) {
            $files = array_merge($files, $fs->get_directory_files(
                $contextid,
                'repository_imagehub',
                $filearea,
                $sourceid,
                ($path ?: '/'),
                true,
                false
            ));
        }
        return $files;
    }

    /**
     * This plugin supports only web images.
     */
    public function supported_filetypes() {
        return ['web_image'];
    }

    /**
     * Repository supports only internal files.
     *
     * @return int
     */
    public function supported_returntypes() {
        return FILE_INTERNAL;
    }

    /**
     * Create an instance for this plug-in
     *
     * @param string $type the type of the repository
     * @param int $userid the user id
     * @param stdClass $context the context
     * @param array $params the options for this instance
     * @param int $readonly whether to create it readonly or not (defaults to not)
     * @return mixed
     * @throws dml_exception
     * @throws required_capability_exception
     */
    public static function create($type, $userid, $context, $params, $readonly = 0) {
        require_capability('moodle/site:config', context_system::instance());
        return parent::create($type, $userid, $context, $params, $readonly);
    }

    /**
     * Get the configuration form for this repository type.
     * @param moodleform $mform
     * @param string $classname
     * @throws coding_exception
     */
    public static function type_config_form($mform, $classname = 'repository_imagehub'): void {
        $type = repository::get_type_by_typename('imagehub');

        // Link to managesources.
        if ($type !== null) {
            $url = new moodle_url('/repository/imagehub/managesources.php');
            $mform->addElement(
                'static',
                null,
                get_string('linktomanagesources', 'repository_imagehub', $url),
                get_string('linktomanagesources_description', 'repository_imagehub')
            );
        } else {
            $mform->addElement(
                'static',
                null,
                get_string('repositorynotenabled', 'repository_imagehub'),
                ''
            );
        }
    }

    /**
     * Return names of the general options.
     * By default: no general option name
     *
     * @return array
     */
    public static function get_type_option_names() {
        return ['pluginname'];
    }

    /**
     * Is this repository used to browse moodle files?
     *
     * @return boolean
     */
    public function has_moodle_files() {
        return true;
    }

    /**
     * Returns url of thumbnail file.
     *
     * @param stored_file $file current path in repository (dir and filename)
     * @param string $thumbsize 'thumb' or 'icon'
     * @return moodle_url
     * @throws dml_exception
     */
    protected static function get_thumbnail_url(stored_file $file, string $thumbsize): moodle_url {
        return moodle_url::make_pluginfile_url(
            context_system::instance()->id,
            'repository_imagehub',
            $thumbsize,
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename()
        );
    }

    /**
     * Returns thumbnail file.
     *
     * @param stored_file $file
     * @param string $thumbsize
     * @return stored_file|null
     * @throws dml_exception
     */
    public static function get_thumbnail($file, $thumbsize): ?stored_file {
        global $CFG;
        $filecontents = $file->get_content();

        $fs = get_file_storage();
        $contextid = context_system::instance()->id;
        if (
            !($thumbfile = $fs->get_file(
                context_system::instance()->id,
                'repository_imagehub',
                $thumbsize,
                $file->get_itemid(),
                $file->get_filepath(),
                $file->get_filename()
            ))
        ) {
            // svgs werden sonst nicht angezeigt
            if ($file->get_mimetype() === 'image/svg+xml') {
                $record = [
                    'contextid' => $contextid,
                    'component' => 'repository_imagehub',
                    'filearea'  => $thumbsize,
                    'itemid'    => $file->get_itemid(),
                    'filepath'  => $file->get_filepath(),
                    'filename'  => $file->get_filename(),
                ];
                return $fs->create_file_from_string($record, $file->get_content());
            }

            require_once($CFG->libdir . '/gdlib.php');
            if ($thumbsize === 'thumb') {
                $size = 90;
            } else {
                $size = 24;
            }
            if (!$data = generate_image_thumbnail_from_string($filecontents, $size, $size)) {
                return null;
            }
            $record = [
                'contextid' => context_system::instance()->id,
                'component' => 'repository_imagehub',
                'filearea' => $thumbsize,
                'itemid' => $file->get_itemid(),
                'filepath' => $file->get_filepath(),
                'filename' => $file->get_filename(),
            ];
            $thumbfile = $fs->create_file_from_string($record, $data);
        }
        return $thumbfile;
    }

    /**
     * Get the file reference.
     *
     * @param int $fileid
     * @return string
     */
    public function get_file_reference($fileid) {
        $fs = get_file_storage();
        $file = $fs->get_file_by_id($fileid);
        $filerecord = [
            'component' => $file->get_component(),
            'filearea'  => $file->get_filearea(),
            'itemid'    => $file->get_itemid(),
            'author'    => $file->get_author(),
            'filepath'  => $file->get_filepath(),
            'filename'  => $file->get_filename(),
            'contextid' => $file->get_contextid(),
        ];
        return file_storage::pack_reference($filerecord);
    }

    /**
     * Return whether the file is accessible.
     *
     * @param string $fileid
     * @return bool
     */
    public function file_is_accessible($fileid) {
        $fs = get_file_storage();
        $file = $fs->get_file_by_id($fileid);
        return $file->get_component() == 'repository_imagehub';
    }
}

/**
 * Deliver a file from the repository.
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool|null
 */
function repository_imagehub_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []): ?bool {
    require_login();
    if ($context->contextlevel !== CONTEXT_SYSTEM) {
        return false;
    }

    $valid = ['images', 'thumb', 'icon'];
    if (!in_array($filearea, $valid, true)) {
        return false;
    }

    if (count($args) < 2) {
        return false;
    }
    $itemid = (int) array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'repository_imagehub', $filearea, $itemid, $filepath, $filename);

    if (!$file) {
        if ($filearea === 'thumb' || $filearea === 'icon') {
            return false;
        }
        $src = $fs->get_file($context->id, 'repository_imagehub', 'images', $itemid, $filepath, $filename);

        if (!$src || $src->is_directory()) {
            return false;
        }
        if (!class_exists('repository_imagehub')) {
            require_once(__DIR__ . '/lib.php');
        }

        $file = repository_imagehub::get_thumbnail($src, $filearea);
        if (!$file) {
            return false;
        }
    }
    if ($file->is_directory()) {
        return false;
    }

    send_stored_file($file, 0, 0, false, $options);
    return true;
}
