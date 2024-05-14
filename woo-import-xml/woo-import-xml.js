jQuery(document).ready(function($) {
    var importStopped = false;
var total_products = 0;
var imported_products = 0;
    $('#woo-import-xml-stop').click(function(e) {
        e.preventDefault();
        importStopped = true; // set the flag to true to stop the import
        $('#woo-import-xml').prop('disabled', false).text('Start Import');
        $(this).prop('disabled', true);
    });


    $('#woo-import-xml').click(function(e) {
        e.preventDefault();

        var logDiv = $('#log');
        logDiv.html('<p>Starting import...</p>');
        $('#woo-import-xml').prop('disabled', true);
        $('#woo-import-xml').text('Importing...');
        $('#woo-import-xml-stop').prop('disabled', false);
        // Function to process import step
        function processStep(step, num) {

            if(importStopped) { // check if the stop button was clicked
                logDiv.append('<p>Import stopped by user.</p>');
                $('#woo-import-xml').prop('disabled', false).text('Start Import');
                $('#woo-import-xml-stop').prop('disabled', true);
                importStopped = false; // reset the flag
                return; // exit the function
            }


            $.ajax({
                url: woo_import_xml.ajaxurl,
                type: 'POST',
                data: {
                    action: 'woo_import_xml',
                    step: step,
                    num: num // Pass the current product index if necessary
                },
                dataType: 'json', // Expect a JSON response from the server
                success: function(response) {
                    logDiv.append('<p>' + response.message + '</p>');
                    if (step == 0) {
                        total_products = response.total;
                        imported_products = 0;
                    }

                    // If there's a next step, process it
                    if (response.success && response.continue) {
                        imported_products = response.next_num;
                        var percent = Math.round((imported_products / total_products) * 100);
                        $('#progress-bar').css('width', percent + '%');
                        $('#progress-text').text('Imported ' + (imported_products+1) + ' of ' + total_products + ' products (' + percent + '%)');
                        processStep(1, response.next_num);
                    } else {
                        logDiv.append('<p>Import completed successfully.</p>');
                        logDiv.append('<p>Setting to draft not imported products...</p>');
                        $.ajax({
                            url: woo_import_xml.ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'woo_import_xml',
                                step: 2
                            },
                            dataType: 'json',
                            success: function(response) {
                                logDiv.append('<p>' + response.message + '</p>');
                            },
                            error: function(jqXHR, textStatus, errorThrown) {
                                logDiv.append('<p>Error: ' + errorThrown + '</p>');
                            }
                        });
                        alert('Import completed successfully.');
                        $('#woo-import-xml').prop('disabled', false);
                        $('#woo-import-xml').text('Import');
                        $('#woo-import-xml-stop').prop('disabled', true);
                        $('#woo-import-xml-stop-flag').checked = false;
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    logDiv.append('<p>Error: ' + errorThrown + '</p>');
                }
            });
            logDiv.scrollTop(logDiv[0].scrollHeight);
        }
        var start_num = document.getElementById('woo-import-xml-start-from').value;
        // Start the import with the first step
        processStep(0,start_num );
    });
});
