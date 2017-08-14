[![Build Status](https://travis-ci.org/prateeksachan/moodle-search_algolia.svg?branch=master)](https://travis-ci.org/prateeksachan/moodle-search_algolia)
[![License](https://img.shields.io/badge/license-GPL--3.0-blue.svg)](https://github.com/prateeksachan/moodle-search_algolia/blob/master/LICENSE)

# Moodle Global Search - Algolia

This plugin integrates [Algolia](https://www.algolia.com) as the search engine for Moodle's Global Search.

## Supported Moodle Versions
Moodle 3.1 and later are currently supported. 

## About Algolia
Algolia is a hosted Search as a Service provider making it easy for websites to integrate search without any need to install/maintain additional servers.

It currently offers a free plan with 10k records and 100k API calls per month. More info about its plans [here](https://www.algolia.com/pricing)

## Algolia Credentials
This plugin relies on the Algolia service which requires you to create an account [here](https://www.algolia.com/users/sign_up) to obtain the `APPLICATION ID` and `API KEY` in your [dashboard](https://www.algolia.com/api-keys)

1. Create an Algolia Account.
2. Create a [new Application](https://www.algolia.com/manage/applications/new) and obtain the `APPLICATION ID` and `API KEY`.

## Installing the Plugin
1. Install the plugin by uploading the downloaded zip package from [Moodle Plugins](https://moodle.org/plugins/search_algolia)
2. You'll be asked for Algolia credentials (`Application ID` and `Admin API KEY`). Save these settings
3. Go to `Plugins` > `Search` > `Manage Global Search` in your Moodle site. Change search engine used to `Algolia` from the dropdown
4. Enable Global Search as it is disabled by default

## Todo
1. Add file indexing support.
2. Add Ajax support for a faster "search-as-you-type" search. [Related Moodle Tracker Issue Link](https://tracker.moodle.org/browse/MDL-53344)

## Credits
This plugin uses the [official Algolia PHP Client](https://github.com/algolia/algoliasearch-client-php)

## Issues, Contributing and Support
Please open a [Github issue](https://github.com/prateeksachan/moodle-search_algolia/issues) to report bugs.
Pull requests are welcome.

Feel free to [contact me](mailto:ps@prateeksachan.com?subject=Moodle%20Algolia%20integration) for any additional features or improvements.

## License
This project is licensed under the GNU GPL v3 or later. See the [LICENSE](https://github.com/prateeksachan/moodle-search_algolia/blob/master/LICENSE) file for details.
