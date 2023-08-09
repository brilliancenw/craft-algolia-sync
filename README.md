# Algolia Sync plugin for Craft CMS

Syncing elements with Algolia using their API

![Screenshot](./docs/img/algolia-sync-banner.jpg)

## Requirements

This plugin requires Craft CMS 3.4.18 or later.

## Installation

To install the plugin, follow these instructions.

1. Open your terminal and go to your Craft project:

        cd /path/to/project

2. Then tell Composer to load the plugin:

        composer require brilliancenw/craft-algolia-sync

3. In the Control Panel, go to Settings → Plugins and click the “Install” button for Algolia Sync.

4. In your .env file, load the Algolia Application ID, the Search Only API Key and the Admin API Key.

5. Select which element types should be synced with Algolia. If the default index names do not work for you, enter
   enter the names of the indexes you want to sync.  These values can be set per environment.

## Algolia Sync Information

When any record is added, edited or deleted, the Algolia Sync plugin will be alerted.  If that element type is
configured to be synced, it will package up these values and ship them off to the associated Algolia Index.

Prior to sending the data off, there is an event that is announaced - you can intercept the data and make changes
to whatever is put in the Algolia index.  You can add, edit or delete any of the data in that record (without actually
changing the data in Craft.)

Here's an example of updating the value of the field with handle `myCustomFieldHandle`:

        Event::on(
            \brilliance\algoliasync\services\AlgoliaSyncService::class,
            \brilliance\algoliasync\services\AlgoliaSyncService::EVENT_BEFORE_ALGOLIA_SYNC,
            function (\brilliance\algoliasync\events\beforeAlgoliaSyncEvent $event) {
                $event->recordUpdate['attributes']['myCustomFieldHandle'] = "Updating the content of this field";
            }
        );

## Some specific Field Types

### Asset Fields
When a record has an Asset field, if there is only one asset added, it will be a string of the URL of the asset.
If there are more than one assets added to that field in that record, it will be an array of the URLs of the asset.
This makes featured images very useful when configured in Algolia to be the image of the record.

### Date Fields
For every date field, three fields are sent to Algolia.
1. The unix timestamp of the date
2. The "friendly" view of the date (in American format `m/d/Y`)
3. the unix timestamp of the midnight of the date.  This is helpful for searching for ranges related to dates

### Category / User / Entry fields
For each of these fields, the value is sent as the title of the record as an Array.  Additionally, an array
of the Element IDs for each of these records is sent.
For example:
if the record contains a Category field with the name "Genre", and I have 3 categories selected,
the data sent will be

`Genre ['title 1', 'title 2', 'title 3']`
AND
`GenreIds [1,2,3]`

This can be used for some very complex JS work on the display of your search results in instantsearch.

## Algolia Sync Roadmap

Some things to do, and ideas for potential features:

- Configuration to exclude specific fields from the sync (for example: in a user record, do not send their email
  address to Algolia)
- Support for Matrix Fields
- Support for 3rd Party Field Types (maps, tables, etc)
- Custom date format for each date field

Pull Requests, Feature Requests and Bug Reports are welcome - please submit to the Git repository:
https://github.com/brilliancenw/craft-algolia-sync

Brought to you by Brilliance https://www.brilliancenw.com/
