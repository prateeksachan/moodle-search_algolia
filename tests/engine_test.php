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
 * Algolia search engine unit tests.
 *
 * @package    search_algolia
 * @copyright  2017 Prateek Sachan {@link http://prateeksachan.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/search/tests/fixtures/testable_core_search.php');
require_once($CFG->dirroot . '/search/tests/fixtures/mock_search_area.php');
require_once($CFG->dirroot . '/search/engine/algolia/tests/fixtures/testable_engine.php');

/**
 * Algolia search engine unit tests.
 *
 * @package    search_algolia
 * @copyright  2017 Prateek Sachan {@link http://prateeksachan.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_algolia_engine_testcase extends advanced_testcase {

    /**
     * @var \core_search::manager
     */
    protected $search = null;

    /**
     * @var Instace of core_search_generator.
     */
    protected $generator = null;

    /**
     * @var Instace of testable_engine.
     */
    protected $engine = null;

    /**
     * @var Index name.
     */
    protected $index = null;

    public function setUp() {
        $this->resetAfterTest();
        set_config('enableglobalsearch', true);

        $applicationid = getenv('TEST_SEARCH_ALGOLIA_APPLICATION_ID');
        $apikey = getenv('TEST_SEARCH_ALGOLIA_ADMIN_API_KEY');

        $this->index = 'moodle' . rand(0, time());
        set_config('indexname', $this->index, 'search_algolia');

        if (!$applicationid && defined('TEST_SEARCH_ALGOLIA_APPLICATION_ID')) {
            $applicationid = TEST_SEARCH_ALGOLIA_APPLICATION_ID;
        }
        if (!$apikey && defined('TEST_SEARCH_ALGOLIA_ADMIN_API_KEY')) {
            $apikey = TEST_SEARCH_ALGOLIA_ADMIN_API_KEY;
        }

        if (!$applicationid || !$apikey) {
            $this->markTestSkipped('Elastic extension test server not set.');
        }

        set_config('application_id', $applicationid, 'search_algolia');
        set_config('api_key', $apikey, 'search_algolia');

        $this->generator = self::getDataGenerator()->get_plugin_generator('core_search');
        $this->generator->setup();

        // Inject search algolia engine into the testable core search as we need to add the mock
        // search component to it.
        $this->engine = new \search_algolia\testable_engine();
        $this->search = testable_core_search::instance($this->engine);
        $areaid = \core_search\manager::generate_areaid('core_mocksearch', 'mock_search_area');
        $this->search->add_search_area($areaid, new core_mocksearch\search\mock_search_area());

        $this->setAdminUser();

        // Cleanup before doing anything on it as the index it is out of this test control.
        $this->engine->clear_indices();
        $this->engine->setup_index();

        $this->search->index(true);
    }

    public function tearDown() {
        // For unit tests before PHP 7, teardown is called even on skip. So only do our teardown if we did setup.
        if ($this->generator) {
            // Moodle DML freaks out if we don't teardown the temp table after each run.
            $this->generator->teardown();
            $this->generator = null;
        }
    }

    /**
     * Simple data provider to allow tests to be run with file indexing on and off.
     */
    public function file_indexing_provider() {
        return array(
            'file-indexing-off' => array(0)
        );
    }

    public function test_connection() {
        $this->assertTrue($this->engine->is_server_ready());
    }

    /**
     * @dataProvider file_indexing_provider
     */
    public function test_index($fileindexing) {
        global $DB;

        $this->engine->test_set_config('fileindexing', $fileindexing);

        $record = new \stdClass();
        $record->timemodified = time() - 1;

        $this->generator->create_record($record);

        // Data gets into the search engine.
        $this->assertTrue($this->search->index());

        // Not anymore as everything was already added.
        sleep(1);
        $this->assertFalse($this->search->index());

        $this->generator->create_record();

        // Indexing again once there is new data.
        $this->assertTrue($this->search->index());
    }

    /**
     * @dataProvider file_indexing_provider
     */
    public function test_search($fileindexing) {
        global $USER, $DB;

        $this->engine->test_set_config('fileindexing', $fileindexing);

        $this->generator->create_record();
        $record = new \stdClass();
        $record->title = "Special title";
        $this->generator->create_record($record);

        $this->search->index();

        $querydata = new stdClass();
        $querydata->q = 'message';
        $results = $this->search->search($querydata);
        $this->assertCount(2, $results);

        // Based on core_mocksearch\search\indexer.
        $this->assertEquals($USER->id, $results[0]->get('userid'));
        $this->assertEquals(\context_system::instance()->id, $results[0]->get('contextid'));

        // Do a test to make sure we aren't searching non-query fields, like areaid.
        $querydata->q = \core_search\manager::generate_areaid('core_mocksearch', 'mock_search_area');
        $this->assertCount(0, $this->search->search($querydata));
        $querydata->q = 'message';

        sleep(1);
        $beforeadding = time();
        sleep(1);
        $this->generator->create_record();
        $this->search->index();

        // Timestart.
        $querydata->timestart = $beforeadding;
        $this->assertCount(1, $this->search->search($querydata));

        // Timeend.
        unset($querydata->timestart);
        $querydata->timeend = $beforeadding;
        $this->assertCount(2, $this->search->search($querydata));

        // Title.
        unset($querydata->timeend);
        $querydata->title = 'Special title';
        $this->assertCount(1, $this->search->search($querydata));

        // Course IDs.
        unset($querydata->title);
        $querydata->courseids = array(SITEID + 1);
        $this->assertCount(0, $this->search->search($querydata));

        $querydata->courseids = array(SITEID);
        $this->assertCount(3, $this->search->search($querydata));

        // Now try some area-id combinations.
        unset($querydata->courseids);
        $forumpostareaid = \core_search\manager::generate_areaid('mod_forum', 'post');
        $mockareaid = \core_search\manager::generate_areaid('core_mocksearch', 'mock_search_area');

        $querydata->areaids = array($forumpostareaid);
        $this->assertCount(0, $this->search->search($querydata));

        $querydata->areaids = array($forumpostareaid, $mockareaid);
        $this->assertCount(3, $this->search->search($querydata));

        $querydata->areaids = array($mockareaid);
        $this->assertCount(3, $this->search->search($querydata));

        $querydata->areaids = array();
        $this->assertCount(3, $this->search->search($querydata));

        // Check that index contents get updated.
        $this->generator->delete_all();
        $this->search->index(true);
        unset($querydata->title);
        $querydata->q = '*';
        $this->assertCount(0, $this->search->search($querydata));
    }

    /**
     * @dataProvider file_indexing_provider
     */
    public function test_delete($fileindexing) {
        $this->engine->test_set_config('fileindexing', $fileindexing);

        $this->generator->create_record();
        $this->generator->create_record();
        $this->search->index();

        $querydata = new stdClass();
        $querydata->q = 'message';

        $this->assertCount(2, $this->search->search($querydata));

        $areaid = \core_search\manager::generate_areaid('core_mocksearch', 'mock_search_area');
        $this->search->delete_index($areaid);
        $this->assertCount(0, $this->search->search($querydata));
    }

    /**
     * @dataProvider file_indexing_provider
     */
    public function test_alloweduserid($fileindexing) {
        $this->engine->test_set_config('fileindexing', $fileindexing);

        $area = new core_mocksearch\search\mock_search_area();

        $record = $this->generator->create_record();

        // Get the doc and insert the default doc.
        $doc = $area->get_document($record);
        $this->engine->add_document($doc);

        $users = array();
        $users[] = $this->getDataGenerator()->create_user();
        $users[] = $this->getDataGenerator()->create_user();
        $users[] = $this->getDataGenerator()->create_user();

        // Add a record that only user 100 can see.
        $originalid = $doc->get('id');

        // Now add a custom doc for each user.
        foreach ($users as $user) {
            $doc = $area->get_document($record);
            $doc->set('id', $originalid.'-'.$user->id);
            $doc->set('owneruserid', $user->id);
            $this->engine->add_document($doc);
        }

        $this->engine->area_index_complete($area->get_area_id());

        $querydata = new stdClass();
        $querydata->q = 'message';
        $querydata->title = $doc->get('title');

        // We are going to go through each user and see if they get the original and the owned doc.
        foreach ($users as $user) {
            $this->setUser($user);

            $results = $this->search->search($querydata);
            $this->assertCount(2, $results);

            $owned = 0;
            $notowned = 0;

            // We don't know what order we will get the results in, so we are doing this.
            foreach ($results as $result) {
                $owneruserid = $result->get('owneruserid');
                if (empty($owneruserid)) {
                    $notowned++;
                    $this->assertEquals(0, $owneruserid);
                    $this->assertEquals($originalid, $result->get('id'));
                } else {
                    $owned++;
                    $this->assertEquals($user->id, $owneruserid);
                    $this->assertEquals($originalid.'-'.$user->id, $result->get('id'));
                }
            }

            $this->assertEquals(1, $owned);
            $this->assertEquals(1, $notowned);
        }

        // Now test a user with no owned results.
        $otheruser = $this->getDataGenerator()->create_user();
        $this->setUser($otheruser);

        $results = $this->search->search($querydata);
        $this->assertCount(1, $results);

        $this->assertEquals(0, $results[0]->get('owneruserid'));
        $this->assertEquals($originalid, $results[0]->get('id'));
    }

    /**
     * @dataProvider file_indexing_provider
     */
    public function test_highlight($fileindexing) {
        global $PAGE;

        $this->engine->test_set_config('fileindexing', $fileindexing);

        $this->generator->create_record();
        $this->search->index();

        $querydata = new stdClass();
        $querydata->q = 'message';

        $results = $this->search->search($querydata);
        $this->assertCount(1, $results);

        $result = reset($results);

        $regex = '|'.\search_algolia\engine::HIGHLIGHT_START.'message'.\search_algolia\engine::HIGHLIGHT_END.'|';
        $this->assertRegExp($regex, $result->get('content'));

        $searchrenderer = $PAGE->get_renderer('core_search');
        $exported = $result->export_for_template($searchrenderer);

        $regex = '|<span class="highlight">message</span>|';
        $this->assertRegExp($regex, $exported['content']);
    }

    /**
     * Test that expected results are returned for 2 users
     *
     * @dataProvider file_indexing_provider
     */
    public function test_algolia_filling($fileindexing) {
        $this->engine->test_set_config('fileindexing', $fileindexing);

        $user1 = self::getDataGenerator()->create_user();
        $user2 = self::getDataGenerator()->create_user();

        // We are going to create a bunch of records that user 1 can see with 2 keywords.
        // Then we are going to create a bunch for user 2 with only 1 of the keywords.
        // If user 2 searches for both keywords, Algolia will return all of the user 1 results, then the user 2 results.
        // This is because the user 1 results will match 2 keywords, while the others will match only 1.

        $record = new \stdClass();

        // First create a bunch of records for user 1 to see.
        $record->denyuserids = array($user2->id);
        $record->content = 'Something1 Something2';
        $maxresults = (int)(\core_search\manager::MAX_RESULTS * .10);
        for ($i = 0; $i < $maxresults; $i++) {
            $this->generator->create_record($record);
        }

        // Then create a bunch of records for user 2 to see.
        $record->denyuserids = array($user1->id);
        $record->content = 'Something1';
        for ($i = 0; $i < $maxresults; $i++) {
            $this->generator->create_record($record);
        }

        $this->search->index();

        // Check that user 1 sees all their results.
        $this->setUser($user1);
        $querydata = new stdClass();
        $querydata->q = 'Something1 Something2';
        $results = $this->search->search($querydata);
        $this->assertCount($maxresults, $results);

        // Check that user 2 will see theirs, even though they may be crouded out.
        $this->setUser($user2);
        $results = $this->search->search($querydata);
        $this->assertCount($maxresults, $results);
    }

    /**
     * Create 40 docs, that will be return from Algolia in 10 hidden, 10 visible, 10 hidden, 10 visible if you query for:
     * Something1 Something2 Something3 Something4, with the specified user set.
     */
    protected function setup_user_hidden_docs($user) {
        // These results will come first, and will not be visible by the user.
        $record = new \stdClass();
        $record->denyuserids = array($user->id);
        $record->content = 'Something1 Something2 Something3 Something4';
        for ($i = 0; $i < 10; $i++) {
            $this->generator->create_record($record);
        }

        // These results will come second, and will  be visible by the user.
        unset($record->denyuserids);
        $record->content = 'Something1 Something2 Something3';
        for ($i = 0; $i < 10; $i++) {
            $this->generator->create_record($record);
        }

        // These results will come third, and will not be visible by the user.
        $record->denyuserids = array($user->id);
        $record->content = 'Something1 Something2';
        for ($i = 0; $i < 10; $i++) {
            $this->generator->create_record($record);
        }

        // These results will come fourth, and will be visible by the user.
        unset($record->denyuserids);
        $record->content = 'Something1 ';
        for ($i = 0; $i < 10; $i++) {
            $this->generator->create_record($record);
        }
    }

    /**
     * Test that counts are what we expect.
     *
     * @dataProvider file_indexing_provider
     */
    public function test_get_query_total_count($fileindexing) {
        $this->engine->test_set_config('fileindexing', $fileindexing);

        $user = self::getDataGenerator()->create_user();
        $this->setup_user_hidden_docs($user);
        $this->search->index();

        $this->setUser($user);
        $querydata = new stdClass();
        $querydata->q = 'Something1 Something2 Something3 Something4';

        // In this first set, it should have determined the first 10 of 40 are bad, so there could be up to 30 left.
        $results = $this->engine->execute_query($querydata, true, 5);
        $this->assertEquals(30, $this->engine->get_query_total_count());
        $this->assertCount(5, $results);

        // To get to 15, it has to process the first 10 that are bad, 10 that are good, 10 that are bad, then 5 that are good.
        // So we now know 20 are bad out of 40.
        $results = $this->engine->execute_query($querydata, true, 15);
        $this->assertEquals(20, $this->engine->get_query_total_count());
        $this->assertCount(15, $results);

        // Try to get more then all, make sure we still see 20 count and 20 returned.
        $results = $this->engine->execute_query($querydata, true, 30);
        $this->assertEquals(20, $this->engine->get_query_total_count());
        $this->assertCount(20, $results);
    }

    /**
     * Test that paged results are what we expect.
     *
     * @dataProvider file_indexing_provider
     */
    public function test_manager_paged_search($fileindexing) {
        $this->engine->test_set_config('fileindexing', $fileindexing);

        $user = self::getDataGenerator()->create_user();
        $this->setup_user_hidden_docs($user);
        $this->search->index();

        // Check that user 1 sees all their results.
        $this->setUser($user);
        $querydata = new stdClass();
        $querydata->q = 'Something1 Something2 Something3 Something4';

        // On this first page, it should have determined the first 10 of 40 are bad, so there could be up to 30 left.
        $results = $this->search->paged_search($querydata, 0);
        $this->assertEquals(30, $results->totalcount);
        $this->assertCount(10, $results->results);
        $this->assertEquals(0, $results->actualpage);

        // On the second page, it should have found the next 10 bad ones, so we no know there are only 20 total.
        $results = $this->search->paged_search($querydata, 1);
        $this->assertEquals(20, $results->totalcount);
        $this->assertCount(10, $results->results);
        $this->assertEquals(1, $results->actualpage);

        // Try to get an additional page - we should get back page 1 results, since that is the last page with valid results.
        $results = $this->search->paged_search($querydata, 2);
        $this->assertEquals(20, $results->totalcount);
        $this->assertCount(10, $results->results);
        $this->assertEquals(1, $results->actualpage);
    }
}
