<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace repository_imagehub\form;

use context_system;
use ZipArchive;

/**
 * Class imagehub_zip_prozessor
 *
 * handles .zip files, caches files and imporoves performance
 * called while loading files for repository
 *
 * @package repository_imagehub
 * @author 2025 Ramona Rommel <ramona.rommel@oncampus.de>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
class imagehub_zip_processor {
    public function extract_zip_to_filearea(string $zipfilepath, int $itemid): void {
        $fs = get_file_storage();
        $context = context_system::instance();

        $zip = new ZipArchive();

        if ($zip->open($zipfilepath)) {
            // Cache für Pfad-Dateien.
            $filepathcache = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                if (substr($entry, -1) === '/') {
                    continue;
                }

                $filename = basename($entry);
                if (str_starts_with($filename, '.') || str_starts_with($filename, '._')) {
                    continue;
                }

                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if (!in_array($ext, ['svg', 'png', 'jpg', 'jpeg', 'gif', 'webp'])) {
                    continue;
                }

                $filepath = '/' . dirname($entry) . '/';
                $filepath = clean_param($filepath, PARAM_PATH);

                // Pfad-Cache laden.
                if (!isset($filepathcache[$filepath])) {
                    $existing = $fs->get_directory_files(
                        $context->id,
                        'repository_imagehub',
                        'images',
                        $itemid,
                        $filepath,
                    );
                    $filepathcache[$filepath] = array_reduce($existing, function ($carry, $file) {
                        $carry[$file->get_filename()] = true;
                        return $carry;
                    }, []);
                }

                // Lokaler Check.
                if (isset($filepathcache[$filepath][$filename])) {
                    continue;
                }

                $content = $zip->getFromIndex($i);
                if ($content === false) {
                    continue;
                }

                $filerecord = [
                    'contextid' => $context->id,
                    'component' => 'repository_imagehub',
                    'filearea'  => 'images',
                    'itemid'    => $itemid,
                    'filepath'  => $filepath,
                    'filename'  => $filename,
                ];
                $fs->create_file_from_string($filerecord, $content);

                // In Cache laden.
                $filepathcache[$filepath][$filename] = true;
            }
            $zip->close();
        }
    }
}
