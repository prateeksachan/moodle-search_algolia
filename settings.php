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
 * Algolia search engine settings.
 *
 * @package    search_algolia
 * @copyright  2017 Prateek Sachan {@link http://prateeksachan.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {

    if (!during_initial_install()) {
        $settings->add(new admin_setting_heading('search_algolia_connection',
                new lang_string('connectionsettings', 'search_algolia'), ''));
        $settings->add(new admin_setting_configtext('search_algolia/application_id',
            new lang_string('algolia_applicationid', 'search_algolia'),
            new lang_string('algolia_applicationid_desc', 'search_algolia'), '', PARAM_ALPHANUMEXT));
        $settings->add(new admin_setting_configtext('search_algolia/api_key',
            new lang_string('algolia_apikey', 'search_algolia'),
            new lang_string('algolia_apikey_desc', 'search_algolia'), '', PARAM_ALPHANUMEXT));
        $settings->add(new admin_setting_configtext('search_algolia/indexname',
            new lang_string('algolia_indexname', 'search_algolia'),
            new lang_string('algolia_indexname_desc', 'search_algolia'), 'moodle', PARAM_ALPHANUMEXT));
    }
}
