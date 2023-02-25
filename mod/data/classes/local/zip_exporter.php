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
namespace mod_data\local;

use file_serving_exception;
use zip_archive;

class zip_exporter {

    const PATH_FOR_FILES_IN_ZIP = 'files/';

    private string $tmpdir;

    private string $zipfilepath;

    private string $filename;

    private zip_archive $ziparchive;

    private bool $ziparchiveclosed;

    public function __construct($filename) {
        $this->tmpdir = make_request_directory();
        if (!str_ends_with($filename, '.zip')) {
            $filename .= '.zip';
        }
        $this->filename = $filename;
        $this->zipfilepath = $this->tmpdir . '/' . $filename;
        $this->ziparchive = new zip_archive();
        $this->ziparchiveclosed = !$this->ziparchive->open($this->zipfilepath);
    }

    public function add_file_from_string($filenameinzip, $filecontent, $prefix = ''): void {
        $filenameinzip = self::PATH_FOR_FILES_IN_ZIP . $prefix . $filenameinzip;
        $filenameinzip = $this->get_unique_filename_in_zip($filenameinzip);

        $this->ziparchive->add_file_from_string($filenameinzip, $filecontent);
    }

    public function add_file_from_string_to_basedir($filenameinzip, $filecontent): void {
        $filenameinzip = $this->get_unique_filename_in_zip($filenameinzip);
        $this->ziparchive->add_file_from_string($filenameinzip, $filecontent);
    }

    public function get_zip_file(): string|null {
        $this->close_zip_archive();
        return $this->ziparchiveclosed ? $this->zipfilepath : null;
    }

    /**
     * @throws file_serving_exception
     */
    public function send_zip_file(): void {
        $this->close_zip_archive();
        if ($this->ziparchiveclosed) {
            send_file($this->zipfilepath, $this->filename, null, 0, false, true);
        } else {
            throw new file_serving_exception('Could not serve zip file, it could not be closed properly.');
        }
    }

    private function get_unique_filename_in_zip(string $filenameinzip): string {
        if (empty($this->ziparchive->list_files())) {
            return $filenameinzip;
        }
        $existingfilenames = array_map(fn($fileinfo) => $fileinfo->pathname, $this->ziparchive->list_files());
        $i = 1;

        while (in_array($filenameinzip, $existingfilenames)) {
            $extension = pathinfo($filenameinzip, PATHINFO_EXTENSION);
            $filenameinzipwithoutextension = substr($filenameinzip, 0,
                strlen($filenameinzip) - strlen($extension) - 1);
            $filenameinzip = $filenameinzipwithoutextension . '_' . $i . '.' . $extension;
            $i++;
        }
        return $filenameinzip;
    }

    private function close_zip_archive(): void {
        if (!$this->ziparchiveclosed) {
            $this->ziparchiveclosed = $this->ziparchive->close();
        }
    }

}
