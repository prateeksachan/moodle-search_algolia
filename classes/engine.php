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
 * Algolia engine.
 *
 * Provides an interface between Moodles Global search functionality
 * and the Algolia (https://www.algolia.com) search engine.
 *
 * @package    search_algolia
 * @copyright  2017 Prateek Sachan {@link http://prateeksachan.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_algolia;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../algoliasearch/algoliasearch.php');

/**
 * Algolia engine.
 *
 * @package    search_algolia
 * @copyright  2017 Prateek Sachan {@link http://prateeksachan.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine extends \core_search\engine {

    /**
     * The maximum number of results to fetch at a time.
     */
    const QUERY_SIZE = 120;

    /**
     * Marker for the start of a highlight.
     */
    const HIGHLIGHT_START = '@@HI_S@@';

    /**
     * Marker for the end of a highlight.
     */
    const HIGHLIGHT_END = '@@HI_E@@';

    /**
     * @var \AlgoliaClient
     */
    protected $client = null;

    /**
     * @var bool True if we should reuse AlgoliaClients, false if not.
     */
    protected $cacheclient = true;

    /**
     * @var \AlgoliaIndex
     */
    protected $index = null;

    /**
     * @var \curl Direct curl object.
     */
    protected $curl = null;

    /**
     * @var array Fields that can be highlighted.
     */
    protected $highlightfields = array('title', 'content', 'description1', 'description2');

    /**
     * @var array Fields that have facet support.
     */
    protected $facetfields = array('areaid', 'itemid', 'contextid', 'courseid', 'owneruserid', 'modified', 'type');

    /**
     * @var int Number of total docs reported by Algolia for the last query.
     */
    protected $totalenginedocs = 0;

    /**
     * @var int Number of docs we have processed for the last query.
     */
    protected $processeddocs = 0;

    /**
     * @var int Number of docs that have been skipped while processing the last query.
     */
    protected $skippeddocs = 0;

    /**
     * @var array ACLs required by the Admin.
     */
    protected $requiredacls = array('addObject', 'deleteObject', 'listIndexes', 'deleteIndex', 'settings', 'editSettings');

    /**
     * Initialises the search engine configuration.
     *
     * @return void
     */
    public function __construct() {
        parent::__construct();
    }

    /**
     * Executes an Algolia query by applying filters returning its results.
     *
     * @throws \core_search\engine_exception
     * @param  stdClass  $filters Containing query and filters.
     * @param  array     $usercontexts Contexts where the user has access. True if the user can access all contexts.
     * @param  int       $limit The maximum number of results to return.
     * @return \core_search\document[] Results or false if no results
     */
    public function execute_query($filters, $usercontexts, $limit = 0) {
        global $USER;
        $multiquerysearchqueries = [];
        $maxlimit = \core_search\manager::MAX_RESULTS;

        if (empty($limit)) {
            $limit = $maxlimit;
        }

        // If there is any problem we trigger the exception as soon as possible.
        $client = $this->get_search_client();

        $q = preg_replace('/\s+/', ' ', $filters->q);

        $filters = $this->get_search_filters($filters, $usercontexts);

        // We expect good match rates, so for our first get, we will get a small number of records.
        $length = min($limit * 3, static::QUERY_SIZE);

        $args = array(
            'optionalWords' => explode(' ', $q),
            'filters' => implode(' AND ', $filters['facetfilters']),
            'length' => $length,
            'offset' => 0
        );

        // In case of title filter, we need to do a multiQuery search and restrict the searchableAttributes.
        if ($filters['titlefilter']) {
            $args['length'] = $length / 2;
            $args['indexName'] = $this->config->indexname;

            $titlequeryargs = $args;

            $args['restrictSearchableAttributes'] = array_values(array_diff($this->highlightfields, array('title')));
            $args['query'] = $q;

            $titlequeryargs['query'] = $filters['titlefilter'][0];
            $titlequeryargs['restrictSearchableAttributes'] = array('title');

            $multiquerysearchqueries[] = $titlequeryargs;
            $multiquerysearchqueries[] = $args;
        }

        $response = $this->get_query_response($q, $args, $multiquerysearchqueries);

        // Get count data out of the response, and reset our counters.
        list($included, $found) = $this->get_response_counts($response);
        $this->totalenginedocs = $found;
        $this->processeddocs = 0;
        $this->skippeddocs = 0;
        if ($included == 0 || $this->totalenginedocs == 0) {
            // No results.
            return array();
        }

        // Get valid documents out of the response.
        $results = $this->process_response($response, $limit);

        // We have processed all the docs in the response at this point.
        $this->processeddocs += $included;

        // If we haven't reached the limit, and there are more docs left in Algolia, lets keep trying.
        while (count($results) < $limit && ($this->totalenginedocs - $this->processeddocs) > 0) {
            // Offset the start of the query, and since we are making another call, get more per call.
            $args['length'] = static::QUERY_SIZE;
            $args['offset'] = $this->processeddocs;

            $response = $this->get_query_response($q, $args, $multiquerysearchqueries);
            list($included, $found) = $this->get_response_counts($response);
            if ($included == 0 || $found == 0) {
                // No new results were found. Found being empty would be weird, so we will just return.
                return $results;
            }
            $this->totalenginedocs = $found;

            // Get the new response docs, limiting to remaining we need, then add it to the end of the results array.
            $newdocs = $this->process_response($response, $limit - count($results));
            $results = array_merge($results, $newdocs);

            // Add to our processed docs count.
            $this->processeddocs += $included;
        }

        return $results;
    }

    /**
     * Takes a query and returns the response.
     *
     * @param  string  $query.
     * @param  array      $query parameters.
     * @return array|false Response or false on error.
     */
    protected function get_query_response($q, $args, $multiquerysearchqueries = null) {
        ini_set('arg_separator.output', '&');
        try {
            if ($multiquerysearchqueries) {
                return $this->convery_multi_query_response($this->get_search_client()->multipleQueries($multiquerysearchqueries));
            } else {
                return $this->get_search_index()->search($q, $args);
            }
        } catch (\Exception $ex) {
            debugging('Error executing search query: ' . $ex->getMessage(), DEBUG_DEVELOPER);
            $this->queryerror = $ex->getMessage();
            return false;
        }
    }

    /**
     * Returns the total number of documents available for the most recently call to execute_query.
     *
     * @return int
     */
    public function get_query_total_count() {
        // Return the total engine count minus the docs we have determined are bad.
        return $this->totalenginedocs - $this->skippeddocs;
    }

    /**
     * Returns count information for a provided response. Will return 0, 0 for invalid or empty responses.
     *
     * @param array $response The response document from Algolia.
     * @return array A two part array. First how many response docs are in the response.
     *               Second, how many results are vailable in the engine.
     */
    protected function get_response_counts($response) {
        $found = 0;
        $included = 0;

        // Get the number of results for standard queries.
        $found = $response['nbHits'];
        $included = count($response['hits']);

        return array($included, $found);
    }

    /**
     * Prepares filters.
     *
     * @param stdClass  $filters Containing query and filters.
     * @param array     $usercontexts Contexts where the user has access. True if the user can access all contexts.
     * @return array    filters to be consumed by Algolia
     */
    protected function get_search_filters($filters, $usercontexts) {
        global $USER;

        // Let's keep these changes internal.
        $data = clone $filters;

        $facetfilters = array();
        $titlefilter = array();

        // Search filters applied, we don't cache these filters as we don't want to pollute the cache with tmp filters
        // we are really interested in caching contexts filters instead.
        if (!empty($data->title)) {
            $title = preg_replace('/\s+/', ' ', $data->title);
            if ($title) {
                $titlefilter[] = $title;
            }
        }
        if (!empty($data->areaids)) {
            // If areaids are specified, we want to get any that match.
            $facetfilters[] = '(' . $this->form_filter_condition('areaid', $data->areaids, false, ':') . ')';
        }
        if (!empty($data->courseids)) {
            $facetfilters[] = '(' . $this->form_filter_condition('courseid', $data->courseids) . ')';
        }

        if (!empty($data->timestart) and !empty($data->timeend)) {
            $facetfilters[] = ('modified:' . $data->timestart . ' TO ' . $data->timeend);
        } else if (!empty($data->timestart) or !empty($data->timeend)) {
            if (empty($data->timestart)) {
                $data->timestart = '*';
                $facetfilters[] = ('modified <= ' . $data->timeend);
            }

            if (empty($data->timeend)) {
                $data->timeend = '*';
                $facetfilters[] = ('modified >= ' . $data->timestart);
            }
        }

        // Restrict to users who are supposed to be able to see a particular result.
        $facetfilters[] = '('.$this->form_filter_condition('owneruserid', array(\core_search\manager::NO_OWNER_ID, $USER->id)).')';

        // And finally restrict it to the context where the user can access, we want this one cached.
        // If the user can access all contexts $usercontexts value is just true, we don't need to filter
        // in that case.
        if ($usercontexts && is_array($usercontexts)) {
            // Join all area contexts into a single array and implode.
            $allcontexts = array();
            foreach ($usercontexts as $areaid => $areacontexts) {
                if (!empty($data->areaids) && !in_array($areaid, $data->areaids)) {
                    // Skip unused areas.
                    continue;
                }
                foreach ($areacontexts as $contextid) {
                    // Ensure they are unique.
                    $allcontexts[$contextid] = $contextid;
                }
            }
            if (empty($allcontexts)) {
                // This means there are no valid contexts for them, so they get no results.
                return array();
            }
            $facetfilters[] = '(' . $this->form_filter_condition('contextid', array_values($allcontexts)) . ')';
        }

        $facetfilters[] = '(' . $this->form_filter_condition('type', array(\core_search\manager::TYPE_TEXT)) . ')';

        return array('facetfilters' => $facetfilters, 'titlefilter' => $titlefilter);
    }

    /**
     * Build filter condition.
     *
     * @param string    $key For the filter attribute.
     * @param array     $values All values for which the filter has to be applied for.
     * @param bool      $isint Type of filter values.
     * @param string    $operator Operator to be appended in the filter.
     * @param string    $condition Boolean condition to be applied in the filter.
     * @return string   Filter query statement to be applied.
     */
    public function form_filter_condition($key, $values, $isint = true, $operator = '=', $condition = 'OR') {
        $filters = array();

        for ($i = 0; $i < count($values); $i++) {
            if ($isint === true) {
                $filters[] = $key . $operator . $values[$i];
            } else {
                $filters[] = $key . $operator . '"' . $values[$i] . '"';
            }
        }

        return implode(' ' . $condition . ' ', $filters);
    }

    /**
     * Attributes being used by Algolia.
     *
     * @return array $attributes All attributes used by Algolia.
     */
    public function get_attributes() {
        $documentclass = $this->get_document_classname();
        $fields = $documentclass::get_default_fields_definition();
        $attributes = [];

        foreach ($fields as $key => $field) {
            $attributes[] = $key;
        }

        return $attributes;
    }

    /**
     * Filters the response on Moodle side.
     *
     * @param array     $response Algolia array containing the response returned.
     * @param int       $limit The maximum number of results to return. 0 for all.
     * @param bool      $skipaccesscheck Don't use check_access() on results. Only to be used when results have known access.
     * @return array    $out containing final results to be displayed.
     */
    protected function process_response($response, $limit = 0, $skipaccesscheck = false) {
        global $USER;

        if (empty($response)) {
            return array();
        }

        if (isset($response->grouped)) {
            return $this->grouped_files_process_response($response, $limit);
        }

        $userid = $USER->id;
        $noownerid = \core_search\manager::NO_OWNER_ID;

        $numgranted = 0;

        if (!$docs = $response['hits']) {
            return array();
        }

        $out = array();
        if (!empty($response['nbHits'])) {

            // Iterate through the results checking its availability and whether they are available for the user or not.
            foreach ($docs as $key => $docdata) {
                if ($docdata['owneruserid'] != $noownerid && $docdata['owneruserid'] != $userid) {
                    // If owneruserid is set, no other user should be able to access this record.
                    continue;
                }

                if (!$searcharea = $this->get_search_area($docdata['areaid'])) {
                    continue;
                }

                $docdata = $this->standarize_algolia_obj($docdata);

                if ($skipaccesscheck) {
                    $access = \core_search\manager::ACCESS_GRANTED;
                } else {
                    $access = $searcharea->check_access($docdata['itemid']);
                }
                switch ($access) {
                    case \core_search\manager::ACCESS_DELETED:
                        $this->delete_by_id($docdata['objectID']);
                        // Remove one from our processed and total counters, since we promptly deleted.
                        $this->processeddocs--;
                        $this->totalenginedocs--;
                        break;
                    case \core_search\manager::ACCESS_DENIED:
                        $this->skippeddocs++;
                        break;
                    case \core_search\manager::ACCESS_GRANTED:
                        $numgranted++;

                        // Add the doc.
                        $out[] = $this->to_document($searcharea, $docdata);
                        break;
                }

                // Stop when we hit our limit.
                if (!empty($limit) && count($out) >= $limit) {
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Processes the multi query response by merging the nbHits and hits attributes of both queries.
     * Passes it to process_response()
     *
     * @param array     $response Algolia array containing the multi query response returned.
     * @param int       $limit The maximum number of results to return. 0 for all.
     * @param bool      $skipaccesscheck Don't use check_access() on results. Only to be used when results have known access.
     * @return array    $out containing final results to be displayed.
     */
    protected function convery_multi_query_response($response) {
        global $USER;

        if (empty($response)) {
            return array();
        }

        $titlequeryresponse = $response['results'][0];
        $defaultqueryresponse = $response['results'][1];

        // If there are no hits for the title query, then we need to return an empty response.
        if (!$titlequeryresponse['nbHits']) {
            $titlequeryresponse['nbHits'] = 0;
            $titlequeryresponse['hits'] = array();
            return $titlequeryresponse;
        }

        $titlequeryresponsedocids = array();
        $defaultqueryresponsedocids = array();

        foreach ($titlequeryresponse['hits'] as $hit) {
            $titlequeryresponsedocids[] = $hit['id'];
        }

        foreach ($defaultqueryresponse['hits'] as $hit) {
            $defaultqueryresponsedocids[] = $hit['id'];
        }

        $defaultqueryresponse['hits'] = array();
        $defaultqueryresponse['nbHits'] = 0;

        $commondocids = array_values(array_intersect($titlequeryresponsedocids, $defaultqueryresponsedocids));

        foreach ($titlequeryresponse['hits'] as $doc) {
            if (in_array($doc['id'], $commondocids)) {
                $defaultqueryresponse['nbHits'] += 1;
                $defaultqueryresponse['hits'][] = $doc;
            }
        }

        return $defaultqueryresponse;
    }

    /**
     * Returns a standard php array from Algolia's returned doc.
     *
     * @param array     $doc Result Algolia document
     * @return array    $docdata returned document as an array.
     */
    public function standarize_algolia_obj($doc) {
        $documentclass = $this->get_document_classname();
        $fields = $this->get_attributes();

        $docdata = array();
        $docdata['objectID'] = $doc['objectID'];

        foreach ($fields as $field) {
            if (isset($doc[$field])) {
                $docdata[$field] = $doc[$field];
            }
        }

        foreach ($doc['_highlightResult'] as $key => $value) {
            if (isset($docdata[$key])) {
                $docdata[$key] = $doc['_highlightResult'][$key]['value'];
            }
        }

        return $docdata;
    }

    /**
     * Adds a document to the search engine.
     *
     * @param document $document
     * @param bool     $fileindexing True if file indexing is to be used
     * @return bool
     */
    public function add_document($document, $fileindexing = false) {
        $docdata = $document->export_for_engine();

        if (!$this->add_algolia_document($docdata)) {
            return false;
        }

        return true;
    }

    /**
     * Adds a text document to the search engine.
     *
     * @param array $doc
     * @return bool
     */
    protected function add_algolia_document($doc, $waitlastcall = true) {
        $objectid = $doc['id'];
        $index = $this->get_search_index();

        try {
            $result = $index->addObject($doc, $objectid);

            if ($waitlastcall) {
                $index->waitTask($result['taskID']);
            }

            return true;
        } catch (\Exception $e) {
            debugging('Algolia error adding document with id ' . $doc['id'] . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        return false;
    }

    /**
     * Do any area cleanup needed, and do anything to confirm contents.
     *
     * Return false to prevent the search area completed time and stats from being updated.
     *
     * @param \core_search\base $searcharea The search area that was complete
     * @param int $numdocs The number of documents that were added to the index
     * @param bool $fullindex True if a full index is being performed
     * @return bool True means that data is considered indexed
     */
    public function area_index_complete($searcharea, $numdocs = 0, $fullindex = false) {
        if ($numdocs > 0) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Dummy function as Algolia itself handles it internally.
     *
     * @return void
     */
    public function optimize() {
        return true;
    }

    /**
     * Deletes the specified document.
     *
     * @param string $id The document id to delete
     * @return void
     */
    public function delete_by_id($id) {
        $index = $this->get_search_index();
        $res = $index->deleteObject($id);
    }

    /**
     * Clears provided index or all indices
     *
     * @param string $index
     * @return void
     */
    public function clear_indices($index = null) {
        $client = $this->get_search_client();

        if ($index) {
            $client->deleteIndex($index);
        } else {
            $indices = $client->listIndexes();
            foreach ($indices['items'] as $index) {
                $client->deleteIndex($index['name']);
            }
        }
    }

    /**
     * Delete all area's documents.
     *
     * @param string $areaid
     * @return void
     */
    public function delete($areaid = null) {
        $index = $this->get_search_index();

        if ($areaid) {
            $res = $index->deleteByQuery('', array('filters' => 'areaid:' . $areaid));
        } else {
            $res = $index->deleteByQuery('');
        }
    }

    /**
     * Check if the Algolia server is available.
     *
     * @return true|string Returns true if all good or an error string.
     */
    public function is_server_ready() {

        $configured = $this->is_server_configured();
        if ($configured !== true) {
            return $configured;
        }

        if ($this->index) {
            return true;
        }

        try {
            $this->setup_index();
        } catch (\Exception $e) {
            throw new \core_search\engine_exception('indexerror', 'search_algolia');
        }

        return true;
    }

    /**
     * Highlight settings for Algolia
     *
     */
    protected function get_highlight_settings() {
        $settings = array(
            'attributesToHighlight' => $this->highlightfields,
            'highlightPreTag' => self::HIGHLIGHT_START,
            'highlightPostTag' => self::HIGHLIGHT_END,
            'restrictHighlightAndSnippetArrays' => true,
        );

        return $settings;
    }

    /**
     * Initializes and applies settings to the Algolia index.
     *
     */
    public function setup_index() {
        $index = $this->get_search_client()->initIndex($this->config->indexname);

        if ($index) {
            $this->index = $index;
        }

        $highlightsettings = $this->get_highlight_settings();

        $highlightfields = $this->highlightfields;
        array_walk($highlightfields, function(&$value, $key) {
            $value = 'unordered(' . $value . ')';
        });

        $facetfields = $this->facetfields;
        array_walk($facetfields, function(&$value, $key) {
            $value = 'filterOnly(' . $value . ')';
        });

        $settings = $highlightsettings;
        $settings['searchableAttributes'] = $highlightfields;
        $settings['attributesForFaceting'] = $facetfields;
        $settings['typoTolerance'] = false;

        $this->index->setSettings($settings);
    }

    /**
     * Is the algolia server properly configured and reachable?
     *
     * @return true|string Returns true if all good or an error string.
     */
    public function is_server_configured() {

        if (empty($this->config->application_id) || empty($this->config->api_key) || empty($this->config->indexname)) {
            return 'No algolia configuration found';
        }

        if (!$client = $this->get_search_client(false)) {
            return get_string('engineserverstatus', 'search');
        }

        try {
            $this->validate_credentials($this->config->api_key);
        } catch (\Exception $e) {
            return get_string('invalidcredentials', 'search_algolia');
        }

        return true;
    }

    /**
     * Validates Algolia credentials.
     *
     * @throws \core_search\engine_exception
     * @param bool $apikey Algolia Admin API key
     * @return bool
     */
    public function validate_credentials($apikey) {
        $client = $this->get_search_client(false);

        try {
            $client->listUserKeys();
            return;
        } catch (\Exception $ex) {
            // Do nothing.
            $a = false;
        }

        $userkey = $client->getUserKeyACL($apikey);

        $missingacls = array();
        foreach ($this->requiredacls as $requiredacl) {
            if (!in_array($requiredacl, $userkey['acl'])) {
                $missingacls[] = $requiredacl;
            }
        }

        if (!empty($missingacls)) {
            throw new \core_search\engine_exception('missingacls', 'search_algolia', implode(', ', $missingacls));
        }

        return true;
    }

    /**
     * Checks if the Algolia Client Library is available.
     *
     * @return bool
     */
    public function is_installed() {
        return class_exists('\AlgoliaSearch\Client');
    }

    /**
     * Returns the algolia client instance.
     *
     * @throws \core_search\engine_exception
     * @param bool $triggerexception
     * @return \AlgoliaClient
     */
    protected function get_search_client($triggerexception = true) {
        // Type comparison as it is set to false if not available.
        if ($this->client !== null) {
            return $this->client;
        }

        if (!class_exists('\AlgoliaSearch\Client')) {
            throw new \core_search\engine_exception('enginenotinstalled', 'search', '', 'algolia');
        }

        $client = new \AlgoliaSearch\Client($this->config->application_id, $this->config->api_key);

        if ($client === false && $triggerexception) {
            throw new \core_search\engine_exception('engineserverstatus', 'search');
        }

        if ($this->cacheclient) {
            $this->client = $client;
        }

        return $client;
    }

    /**
     * Returns the algolia index instance.
     *
     * @return \AlgoliaIndex
     */
    protected function get_search_index() {
        if (!$this->index) {
            $this->index = $this->get_search_client()->initIndex($this->config->indexname);
        }

        return $this->index;
    }
}
