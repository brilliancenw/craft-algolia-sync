/**
 * Algolia Sync plugin for Craft CMS
 *
 * Algolia Sync JS
 *
 * @author    Mark Middleton
 * @copyright Copyright (c) 2018 Mark Middleton
 * @link      https://www.brilliancenw.com/
 * @package   AlgoliaSync
 * @since     1.0.0
 */
$( document ).ready(function() {
    // alert('I have loaded the asset bundle');

    $("#algoliaSyncLoad").submit(function(e) {

        e.preventDefault(); // avoid to execute the actual submit of the form.

        var form = $(this);
        var url = form.attr('action');

        $.ajax({
            type: "POST",
            url: url,
            data: form.serialize(), // serializes the form's elements.
            success: function(data)
            {
                $('.algoliaLoadCheckbox').prop( "checked", false );
                alert(data); // show response from the php script.
            }
        });
    });
});
