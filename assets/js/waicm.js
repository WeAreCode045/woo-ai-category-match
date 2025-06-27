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
        $.post(waicm.ajax_url, {
            action: 'waicm_match_chunk',
            nonce: waicm.nonce
        }, function(response) {
            if (response.success) {
                total = response.data.total;
                processed += response.data.processed;
                response.data.results.forEach(function(row) {
                    $('#waicm-results-list').append('<li><strong>' + row.product + '</strong>: ' + row.category + '</li>');
                });
                $('#waicm-progress-status').html('<strong>Total processed:</strong> ' + processed + ' / ' + total);
                if (response.data.remaining > 0) {
                    setTimeout(processChunk, 1000);
                } else {
                    running = false;
                    $('#waicm-cancel-btn').hide();
                    $('#waicm-start-btn').prop('disabled', false);
                    if (response.data.no_match_count > 0) {
                        var unmatchedList = $('<ul id="waicm-unmatched-list"></ul>');
                        response.data.no_match_products.forEach(function(prod) {
                            unmatchedList.append('<li>' + prod.title + '</li>');
                        });
                        $('#waicm-progress-status').append(unmatchedList);
                    }
                }
            } else {
                running = false;
                $('#waicm-cancel-btn').hide();
                $('#waicm-progress-status').append('<br><span style="color:red;">Error: ' + response.data.message + '</span>');
                $('#waicm-start-btn').prop('disabled', false);
            }
        });
    }

    // --- STEP 2: External Site/Category Matching ---
    $('#waicm-ext-search-btn').off('click').on('click', function() {
        var url1 = $('#waicm-ext-url-1').val().trim();
        var url2 = $('#waicm-ext-url-2').val().trim();
        var instructions = $('#waicm-ext-instructions').val().trim();
        $('#waicm-step2-results').empty();
        if (!url1 && !url2) {
            alert('Please enter at least one URL.');
            return;
        }
        $('#waicm-ext-search-loading').show();
        $.post(waicm.ajax_url, {
            action: 'waicm_match_chunk',
            nonce: waicm.nonce
        }, function(response) {
            if (response.success && response.data.no_match_products && response.data.no_match_products.length > 0) {
                $.post(caiMatcher.ajax_url, {
                    action: 'cai_ext_check_all',
                    nonce: caiMatcher.ext_nonce,
                    products: response.data.no_match_products,
                    url1: url1,
                    url2: url2,
                    instructions: instructions
                }, function(response2) {
                    $('#cai-ext-search-loading').hide();
                    if (response2.success) {
                        var resHtml = '<ul>';
                        response2.data.results.forEach(function(prod) {
                            resHtml += '<li><strong>' + prod.title + ':</strong> ' + prod.category + '</li>';
                        });
                        resHtml += '</ul>';
                        $('#cai-step2-results').html(resHtml);
                    } else {
                        $('#cai-step2-results').html('<span style="color:red;">Error: ' + (response2.data && response2.data.message ? response2.data.message : 'Unknown error') + '</span>');
                    }
                });
            } else {
                $('#cai-ext-search-loading').hide();
                $('#cai-step2-results').html('<span style="color:red;">No uncategorized products found for external check.</span>');
            }
        });
    });
        totalAi = 0;
        processedAi = 0;
        $('#cai-results-list-ai').empty();
        $('#cai-progress-bar-ai').css('width', '0%').text('0%');
        $('#cai-progress-status-ai').text('Starting...');
        $('#cai-progress-ai').show();
        $('#cai-progress-bar').css('width', '0%').text('0%');
        $('#cai-progress-status').text('Starting...');
        $('#cai-progress').show();
        $(this).prop('disabled', true);
        processChunk();
    });
