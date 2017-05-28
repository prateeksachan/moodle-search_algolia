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
 * Algolia search unit tests.
 *
 * @package    search_algolia
 * @copyright  2017 Prateek Sachan {@link http://prateeksachan.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_algolia;

defined('MOODLE_INTERNAL') || die;

/**
 * Algolia engine.
 *
 * @package    search_algolia
 * @copyright  2017 Prateek Sachan {@link http://prateeksachan.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class testable_engine extends \search_algolia\engine {
    /**
     * Function that lets us update the internally cached config object of the engine.
     */
    public function test_set_config($name, $value) {
        $this->config->$name = $value;
    }
}
