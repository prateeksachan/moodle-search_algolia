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
 * Respresents a document to index in Algolia.
 *
 * @package    search_algolia
 * @copyright  2017 Prateek Sachan {@link http://prateeksachan.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_algolia;

defined('MOODLE_INTERNAL') || die();

/**
 * Algolia engine.
 *
 * @package    search_algolia
 * @copyright  2017 Prateek Sachan {@link http://prateeksachan.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class document extends \core_search\document {
    /**
     * Indicates the file contents were not indexed due to an error.
     */
    const INDEXED_FILE_ERROR = -1;

    /**
     * Indicates the file contents were not indexed due filtering/settings.
     */
    const INDEXED_FILE_FALSE = 0;

    /**
     * Indicates the file contents are indexed with the record.
     */
    const INDEXED_FILE_TRUE = 1;

    /**
     * Any fields that are engine specifc. These are fields that are solely used by a seach engine plugin
     * for internal purposes.
     *
     * @var array
     */
    protected static $enginefields = array(
        'algolia_filegroupingid' => array(
            'type' => 'string'
        ),
        'algolia_fileid' => array(
            'type' => 'string'
        ),
        'algolia_filecontenthash' => array(
            'type' => 'string'
        ),
        // Stores the status of file indexing.
        'algolia_fileindexstatus' => array(
            'type' => 'int'
        ),
        // Field to index, but not store, file contents.
        'algolia_filecontent' => array(
            'type' => 'text'
        )
    );

    /**
     * Formats the string according to the search engine needs.
     *
     * @param string $string
     * @return string
     */
    public static function format_string_for_engine($string) {
        return \core_text::str_max_bytes($string, 32766);
    }

    /**
     * Overwritten to use markdown format as we use markdown for algolia highlighting.
     *
     * @return int
     */
    protected function get_text_format() {
        return FORMAT_HTML;
    }

    /**
     * Formats a text string coming from the search engine.
     *
     * @param  string $text Text to format
     * @return string HTML text to be renderer
     */
    protected function format_text($text) {
        // Since we allow output for highlighting, we need to encode html entities.
        // This ensures plaintext html chars don't become valid html.
        $out = s($text);

        $startcount = 0;
        $endcount = 0;

        // Remove end/start pairs that span a few common seperation characters. Allows us to highlight phrases instead of words.
        $regex = '|'.engine::HIGHLIGHT_END.'([ .,-]{0,3})'.engine::HIGHLIGHT_START.'|';
        $out = preg_replace($regex, '$1', $out);

        // Now replace our start and end highlight markers.
        $out = str_replace(engine::HIGHLIGHT_START, '<span class="highlight">', $out, $startcount);
        $out = str_replace(engine::HIGHLIGHT_END, '</span>', $out, $endcount);

        // This makes sure any highlight tags are balanced, incase truncation or the highlight text contained our markers.
        while ($startcount > $endcount) {
            $out .= '</span>';
            $endcount++;
        }
        while ($startcount < $endcount) {
            $out = '<span class="highlight">' . $out;
            $endcount++;
        }

        return parent::format_text($out);
    }

    /**
     * Apply any defaults to unset fields before export. Called after document building, but before export.
     *
     * Sub-classes of this should make sure to call parent::apply_defaults().
     */
    protected function apply_defaults() {
        parent::apply_defaults();

        // We want to set the algolia_filegroupingid to id if it isn't set.
        if (!isset($this->data['algolia_filegroupingid'])) {
            $this->data['algolia_filegroupingid'] = $this->data['id'];
        }
    }

    /**
     * Export the data for the given file in relation to this document.
     *
     * @param \stored_file $file The stored file we are talking about.
     * @return array
     */
    public function export_file_for_engine($file) {
        $data = $this->export_for_engine();

        // Content is index in the main document.
        unset($data['content']);
        unset($data['description1']);
        unset($data['description2']);

        // Going to append the fileid to give it a unique id.
        $data['id'] = $data['id'].'-algoliafile'.$file->get_id();
        $data['type'] = \core_search\manager::TYPE_FILE;
        $data['algolia_fileid'] = $file->get_id();
        $data['algolia_filecontenthash'] = $file->get_contenthash();
        $data['algolia_fileindexstatus'] = self::INDEXED_FILE_TRUE;
        $data['title'] = $file->get_filename();
        $data['modified'] = $file->get_timemodified();

        return $data;
    }
}
