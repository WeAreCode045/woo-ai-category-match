jQuery(document).ready(function($) {
    // --- STEP 1: AI Auto-categorization ---
    var running = false;
    var cancelRequested = false;
    var total = 0;
    var processed = 0;
    var results = [];

    $('#waicm-start-btn').on('click', function() {
        if (running) return;
        running = true;
        cancelRequested = false;
        total = 0;
        processed = 0;
        results = [];
        $('#waicm-progress-status').html('<strong>Processing uncategorized products...</strong>');
        $('#waicm-results-list').empty();
        $('#waicm-cancel-btn').show().prop('disabled', false);
        $('#waicm-start-btn').prop('disabled', true);
        processChunk();
    });

    $('#waicm-cancel-btn').on('click', function() {
        cancelRequested = true;
        running = false;
        $('#waicm-progress-status').append('<br><span style="color:red;">Process cancelled by user.</span>');
        $('#waicm-cancel-btn').prop('disabled', true);
        $('#waicm-start-btn').prop('disabled', false);
    });

    function processChunk(currentChunk = 0) {
        if (!running || cancelRequested) return;
        
        // Get the nonce from the meta tag as a fallback
        var nonce = waicm.ajax_nonce || $('meta[name="waicm_nonce"]').attr('value');
        
        if (!nonce) {
            console.error('Security nonce is missing. Please refresh the page and try again.');
            return;
        }
        
        console.log('Sending AJAX request with nonce:', nonce);
        console.log('Current chunk:', currentChunk);
        
        // Prepare the data object
        var postData = {
            action: 'waicm_match_chunk',
            _ajax_nonce: nonce,
            nonce: nonce,
            current_chunk: currentChunk || 0
        };
        
        console.log('POST data:', postData);
        
        // Show loading state
        $('#waicm-progress-status').html('Processing...');
        
        $.ajax({
            url: waicm.ajax_url,
            type: 'POST',
            data: postData,
            beforeSend: function(xhr) {
                console.log('AJAX request started');
                // Add loading class to button
                $('#waicm-start-btn').prop('disabled', true).text('Processing...');
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', status, error);
                console.error('Response:', xhr.responseText);
                
                // Parse the response if possible
                var errorMsg = 'An error occurred. Please try again.';
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response.data && response.data.message) {
                        errorMsg = response.data.message;
                    } else if (response.message) {
                        errorMsg = response.message;
                    }
                } catch (e) {
                    console.error('Error parsing error response:', e);
                }
                
                // Show error to user
                $('#waicm-progress-status').html('<span style="color:red;">Error: ' + errorMsg + '</span>');
                $('#waicm-start-btn').prop('disabled', false).text('Start AI Categorization');
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    var data = response.data;
                    
                    // Update progress
                    if (data.results && data.results.length > 0) {
                        data.results.forEach(function(row) {
                            $('#waicm-results-list').append('<li><strong>' + row.product + '</strong>: ' + row.category + '</li>');
                        });
                    }
                    
                    // Calculate progress
                    var progressPercent = Math.round((data.current_chunk / data.total_chunks) * 100);
                    var processedInBatch = data.processed;
                    var chunkSize = data.processed + (data.remaining > 0 ? 0 : data.processed);
                    var totalProcessed = (data.current_chunk - 1) * chunkSize + processedInBatch;
                    
                    // Update UI
                    $('#waicm-progress-status').html(
                        '<strong>Progress:</strong> ' + progressPercent + '% ' +
                        '(' + totalProcessed + ' of ' + data.total_products + ' products)'
                    );
                    
                    // Update progress bar if it exists, or create it
                    if ($('#waicm-progress-bar').length === 0) {
                        $('#waicm-progress-status').after(
                            '<div class="progress" style="height: 20px; margin: 10px 0; background: #f0f0f0; border-radius: 4px; overflow: hidden;">' +
                            '<div id="waicm-progress-bar" class="progress-bar" role="progressbar" style="width: ' + progressPercent + '%; ' +
                            'background: #007cba; color: white; text-align: center; line-height: 20px;" ' +
                            'aria-valuenow="' + progressPercent + '" aria-valuemin="0" aria-valuemax="100">' +
                            progressPercent + '%</div></div>'
                        );
                    } else {
                        $('#waicm-progress-bar')
                            .css('width', progressPercent + '%')
                            .attr('aria-valuenow', progressPercent)
                            .text(progressPercent + '%');
                    }
                    
                    // Process next chunk if needed
                    if (data.current_chunk < data.total_chunks && data.remaining > 0) {
                        setTimeout(function() {
                            processChunk(data.current_chunk);
                        }, 1000);
                    } else {
                        running = false;
                        $('#waicm-cancel-btn').hide();
                        $('#waicm-start-btn').prop('disabled', false);
                        if (data.remaining > 0) {
                            $('#waicm-progress-status').append('<p>Completed processing batch. ' + data.remaining + ' products remaining.</p>');
                        } else {
                            $('#waicm-progress-status').append('<p style="color:green;">All products processed successfully!</p>');
                        }
                    }
                } else {
                    running = false;
                    $('#waicm-cancel-btn').hide();
                    $('#waicm-progress-status').append('<br><span style="color:red;">Error: ' + (response.data.message || 'Unknown error occurred') + '</span>');
                    $('#waicm-start-btn').prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                running = false;
                $('#waicm-cancel-btn').hide();
                var errorMessage = 'An error occurred';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMessage = xhr.responseJSON.data.message;
                } else if (xhr.statusText) {
                    errorMessage = xhr.statusText;
                }
                $('#waicm-progress-status').append('<br><span style="color:red;">Error: ' + errorMessage + '</span>');
                $('#waicm-start-btn').prop('disabled', false);
            }
        });
    }

    // --- STEP 2: External Site/Category Matching ---
    $('#waicm-ext-search-btn').on('click', function() {
        var url1 = $('#waicm-ext-url-1').val().trim();
        var url2 = $('#waicm-ext-url-2').val().trim();
        var instructions = $('#waicm-ext-instructions').val().trim();
        
        $('#waicm-step2-results').empty();
        
        if (!url1 && !url2) {
            alert('Please enter at least one URL.');
            return;
        }
        
        // Show loading state
        var $button = $(this);
        var $loading = $('#waicm-ext-search-loading');
        $button.prop('disabled', true);
        $loading.show();
        
        // First, get all uncategorized products
        $.post(waicm.ajax_url, {
            action: 'waicm_get_uncategorized_products',
            nonce: waicm.nonce
        }, function(response) {
            if (!response.success || !response.data.products || response.data.products.length === 0) {
                $loading.hide();
                $button.prop('disabled', false);
                $('#waicm-step2-results').html('<p>No uncategorized products found.</p>');
                return;
            }
            
            // Now process these products with the external URLs
            $.post(waicm.ajax_url, {
                action: 'waicm_ext_check_all',
                nonce: waicm.ext_nonce,
                products: response.data.products,
                url1: url1,
                url2: url2,
                instructions: instructions
            }, function(processResponse) {
                $loading.hide();
                $button.prop('disabled', false);
                
                if (processResponse.success) {
                    var results = processResponse.data.results || [];
                    if (results.length > 0) {
                        var resHtml = '<div class="waicm-results">';
                        resHtml += '<p>Found ' + results.length + ' potential category matches:</p><ul>';
                        results.forEach(function(prod) {
                            if (prod && prod.title && prod.category) {
                                resHtml += '<li><strong>' + prod.title + ':</strong> ' + prod.category + '</li>';
                            }
                        });
                        resHtml += '</ul></div>';
                        $('#waicm-step2-results').html(resHtml);
                    } else {
                        $('#waicm-step2-results').html('<p>No matching categories found for the provided products.</p>');
                    }
                } else {
                    var errorMsg = processResponse.data && processResponse.data.message 
                        ? processResponse.data.message 
                        : 'An error occurred while processing the request.';
                    $('#waicm-step2-results').html('<p style="color:red;">Error: ' + errorMsg + '</p>');
                }
            }).fail(function() {
                $loading.hide();
                $button.prop('disabled', false);
                $('#waicm-step2-results').html('<p style="color:red;">Failed to process products. Please try again.</p>');
            });
        }).fail(function() {
            $loading.hide();
            $button.prop('disabled', false);
            $('#waicm-step2-results').html('<p style="color:red;">Failed to fetch uncategorized products. Please try again.</p>');
        });
    });
});
