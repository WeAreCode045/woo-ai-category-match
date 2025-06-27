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

    function processChunk() {
        if (!running || cancelRequested) return;
        
        $.ajax({
            url: waicm.ajax_url,
            type: 'POST',
            data: {
                action: 'category_match_chunk',
                nonce: waicm.nonce
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    total = response.data.total;
                    processed += response.data.processed;
                    
                    if (response.data.results && response.data.results.length > 0) {
                        response.data.results.forEach(function(row) {
                            $('#waicm-results-list').append('<li><strong>' + row.product + '</strong>: ' + row.category + '</li>');
                        });
                    }
                    
                    $('#waicm-progress-status').html('<strong>Total processed:</strong> ' + processed + ' / ' + total);
                    
                    if (response.data.remaining > 0) {
                        setTimeout(processChunk, 1000);
                    } else {
                        running = false;
                        $('#waicm-cancel-btn').hide();
                        $('#waicm-start-btn').prop('disabled', false);
                        if (response.data.remaining > 0) {
                            $('#waicm-progress-status').append('<p>Completed processing batch. ' + response.data.remaining + ' products remaining.</p>');
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
