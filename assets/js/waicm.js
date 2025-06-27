(function($) {
    'use strict';

    // Main plugin object
    var WAICM = {
        // Initialize the plugin
        init: function() {
            // Check if we're on the external search page
            if (typeof categoryMatcher !== 'undefined' && categoryMatcher.is_external_search === 'yes') {
                this.initExternalSearch();
            } else {
                this.initAutoCategorization();
            }
        },

        // Initialize auto-categorization functionality
        initAutoCategorization: function() {
            var running = false;
            var cancelRequested = false;
            var total = 0;
            var processed = 0;
            var results = [];
            var self = this;

            // Initialize UI elements
            this.initProgressBar();
            this.initEventHandlers();
        },

        // Initialize progress bar
        initProgressBar: function() {
            if ($('#waicm-progress-container').length === 0) {
                $('.waicm-progress-status').after(
                    '<div id="waicm-progress-container" class="waicm-progress">' +
                    '<div id="waicm-progress-bar" class="waicm-progress-bar">' +
                    '<span class="waicm-progress-text">0%</span></div></div>'
                );
            }
        },

        // Initialize event handlers
        initEventHandlers: function() {
            var self = this;
            
            // Start button click handler
            $('#waicm-start-btn').on('click', function() {
                self.startProcessing();
            });

            // Cancel button click handler
            $('#waicm-cancel-btn').on('click', function() {
                self.cancelProcessing();
            });
        },

        // Start processing products
        startProcessing: function() {
            if (this.running) return;
            
            this.running = true;
            this.cancelRequested = false;
            this.total = 0;
            this.processed = 0;
            this.results = [];
            
            $('.waicm-progress-status').html('<div class="waicm-loading"></div> <strong>Processing uncategorized products...</strong>');
            $('#waicm-results-list').empty();
            $('#waicm-cancel-btn').show().prop('disabled', false);
            $('#waicm-start-btn').prop('disabled', true);
            
            this.processChunk();
        },

        // Cancel processing
        cancelProcessing: function() {
            this.cancelRequested = true;
            this.running = false;
            $('.waicm-progress-status').append('<br><span class="waicm-error">Process cancelled by user.</span>');
            $('#waicm-cancel-btn').prop('disabled', true);
            $('#waicm-start-btn').prop('disabled', false).text('Start Processing');
        },

        // Process a chunk of products
        processChunk: function(currentChunk) {
            currentChunk = currentChunk || 0;
            
            if (!this.running || this.cancelRequested) {
                return;
            }

            var self = this;
            var postData = {
                action: 'waicm_match_chunk',
                current_chunk: currentChunk,
                nonce: categoryMatcher.nonce
            };
            
            $('.waicm-progress-status').html('<div class="waicm-loading"></div> Processing...');
            
            $.ajax({
                url: categoryMatcher.ajax_url,
                type: 'POST',
                data: postData,
                dataType: 'json',
                beforeSend: function() {
                    $('#waicm-start-btn').prop('disabled', true).html('<span class="waicm-loading"></span> Processing...');
                }
            })
            .done(function(response) {
                if (response.success && response.data) {
                    self.handleChunkResponse(response.data);
                } else {
                    self.handleError(response.data || 'An unknown error occurred');
                }
            })
            .fail(function(xhr, status, error) {
                self.handleError('AJAX request failed: ' + status);
            });
        },

        // Handle successful chunk response
        handleChunkResponse: function(data) {
            // Update results
            if (data.results && data.results.length > 0) {
                data.results.forEach(this.addResult.bind(this));
                this.scrollToBottom();
            }
            
            // Update progress
            this.updateProgress(data);
            
            // Process next chunk if needed
            if (data.current_chunk < data.total_chunks && data.remaining > 0) {
                setTimeout(this.processChunk.bind(this, data.current_chunk), 500);
            } else {
                this.completeProcessing();
            }
        },

        // Add a result to the results list
        addResult: function(row) {
            var statusClass = row.status === 'error' ? 'waicm-error' : 'waicm-success';
            var statusIcon = row.status === 'error' ? '❌' : '✅';
            $('#waicm-results-list').append(
                '<div class="waicm-result-item ' + statusClass + '">' +
                '<strong>' + row.product + '</strong>: ' + row.message + ' ' + statusIcon +
                '</div>'
            );
        },

        // Scroll to bottom of results
        scrollToBottom: function() {
            var resultsContainer = $('#waicm-results');
            resultsContainer.scrollTop(resultsContainer[0].scrollHeight);
        },

        // Update progress display
        updateProgress: function(data) {
            var progressPercent = Math.min(100, Math.round((data.current_chunk / data.total_chunks) * 100));
            var totalProcessed = data.total_processed || 0;
            var totalProducts = data.total_products || 0;
            
            $('.waicm-progress-text').text(progressPercent + '%');
            $('#waicm-progress-bar').css('width', progressPercent + '%');
            $('.waicm-progress-status').html(
                '<strong>Progress:</strong> ' + progressPercent + '% ' +
                '(' + totalProcessed + ' of ' + totalProducts + ' products)'
            );
        },

        // Handle errors
        handleError: function(message) {
            console.error('Error:', message);
            $('.waicm-progress-status').append('<div class="waicm-error">Error: ' + message + '</div>');
            this.running = false;
            $('#waicm-cancel-btn').hide();
            $('#waicm-start-btn').prop('disabled', false).text('Start Processing');
        },

        // Complete processing
        completeProcessing: function() {
            this.running = false;
            $('#waicm-cancel-btn').hide();
            $('#waicm-start-btn').prop('disabled', false).text('Start Again');
            $('.waicm-progress-status').append('<div class="waicm-success">Processing complete!</div>');
        },

        // Initialize external search functionality
        initExternalSearch: function() {
            // External search functionality will be implemented here
            console.log('External search initialized');
        }
    };

    // Initialize the plugin when the document is ready
    $(document).ready(function() {
        WAICM.init();
    });

})(jQuery);
