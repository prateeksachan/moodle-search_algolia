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

## Todo
1. Add file indexing support.
2. Add Ajax support for a faster "search-as-you-type" search. [Related Moodle Tracker Issue Link](https://tracker.moodle.org/browse/MDL-53344)

## Credits
This plugin uses the [official Algolia PHP Client](https://github.com/algolia/algoliasearch-client-php)

## Issues, Contributing and Support
Please open a [Github issue](https://github.com/algolia/algoliasearch-client-javascript/issues) to report bugs.
Pull requests are welcome.

Feel free to [contact me](mailto:ps@prateeksachan.com?subject=Moodle%20Algolia%20integration) for any additional features or improvements.

## License
This project is licensed under the GNU GPL v3 or later. See the [LICENSE](https://github.com/prateeksachan/moodle-search_algolia/blob/master/LICENSE) file for details.