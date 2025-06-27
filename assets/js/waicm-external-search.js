(function($) {
    'use strict';

    // External Search Module
    var WAICMExtSearch = {
        // Initialize the external search functionality
        init: function() {
            this.cacheElements();
            this.bindEvents();
            this.initProgressBar();
        },

        // Cache DOM elements
        cacheElements: function() {
            this.$button = $('#waicm-ext-search-btn');
            this.$loading = $('#waicm-ext-search-loading');
            this.$results = $('#waicm-external-search-results');
            this.$url1 = $('#waicm-ext-url-1');
            this.$cancelBtn = $('#waicm-cancel-external-search');
            this.$instructions = $('#waicm-ext-instructions');
            this.$progressStatus = $('#waicm-external-search-progress');
        },

        // Initialize progress bar
        initProgressBar: function() {
            if ($('#waicm-progress-container').length === 0) {
                this.$progressStatus.after(
                    '<div id="waicm-progress-container" class="waicm-progress">' +
                    '<div id="waicm-progress-bar" class="waicm-progress-bar">' +
                    '<span class="waicm-progress-text">0%</span></div></div>'
                );
            }
        },

        // Bind event handlers
        bindEvents: function() {
            var self = this;
            
            this.$button.on('click', function(e) {
                e.preventDefault();
                self.startSearch();
            });
        },

        // Start the search process
        startSearch: function() {
            var url1 = this.$url1.val().trim();
            var url2 = this.$url2.val().trim();
            var instructions = this.$instructions.val().trim();
            
            // Reset UI
            this.$results.empty();
            this.$button.prop('disabled', true);
            this.$loading.show();
            
            // Validate input
            if (!url1 && !url2) {
                this.showError('Please enter at least one URL.');
                return;
            }
            
            // Get uncategorized products
            this.getUncategorizedProducts(url1, url2, instructions);
        },

        // Get uncategorized products
        getUncategorizedProducts: function(url1, url2, instructions) {
            var self = this;
            
            $.ajax({
                url: categoryMatcher.ajax_url,
                type: 'POST',
                data: {
                    action: 'waicm_get_uncategorized_products',
                    nonce: categoryMatcher.nonce
                },
                dataType: 'json',
                beforeSend: function() {
                    self.updateProgress(0, 1);
                    self.$loading.show();
                }
            })
            .done(function(response) {
                if (response.success && response.data && response.data.products && response.data.products.length > 0) {
                    self.processProducts(response.data.products, url1, url2, instructions);
                } else {
                    self.showError('No uncategorized products found.');
                }
            })
            .fail(function(xhr, status, error) {
                self.handleAjaxError(xhr, status, error);
            });
        },

        // Process products one by one
        processProducts: function(products, url1, url2, instructions) {
            var self = this;
            var results = [];
            var total = products.length;
            var processed = 0;
            
            // Update progress
            this.updateProgress(0, total);
            
            // Process each product
            function processNext(index) {
                if (index >= total) {
                    self.searchComplete(results);
                    return;
                }
                
                var product = products[index];
                
                // Process the current product
                self.processProduct(product, url1, url2, instructions)
                    .done(function(result) {
                        if (result && result.success) {
                            results.push(result.data);
                        }
                        
                        // Update progress
                        processed++;
                        self.updateProgress(processed, total);
                        
                        // Process next product
                        processNext(index + 1);
                    })
                    .fail(function(error) {
                        console.error('Error processing product:', error);
                        
                        // Update progress and continue with next product
                        processed++;
                        self.updateProgress(processed, total);
                        processNext(index + 1);
                    });
            }
            
            // Start processing
            processNext(0);
        },

        // Process a single product
        processProduct: function(product, url1, url2, instructions) {
            return $.ajax({
                url: categoryMatcher.ajax_url,
                type: 'POST',
                data: {
                    action: 'waicm_ext_check_all',
                    nonce: categoryMatcher.nonce,
                    product_id: product.id,
                    url1: url1,
                    url2: url2,
                    instructions: instructions
                },
                dataType: 'json'
            });
        },

        // Handle search completion
        searchComplete: function(results) {
            this.$loading.hide();
            this.$button.prop('disabled', false);
            
            if (results && results.length > 0) {
                var resHtml = '<div class="waicm-results">';
                resHtml += '<h3>Found ' + results.length + ' potential category matches:</h3><ul class="waicm-results-list">';
                
                results.forEach(function(prod) {
                    if (prod && prod.title && prod.category) {
                        resHtml += '<li class="waicm-result-item"><strong>' + 
                                 prod.title + ':</strong> ' + prod.category + '</li>';
                    }
                });
                
                resHtml += '</ul></div>';
                this.$results.html(resHtml);
            } else {
                this.$results.html('<div class="waicm-notice waicm-notice-info">' +
                                  '<p>No matching categories found for the provided products.</p></div>');
            }
        },

        // Update progress bar and status
        updateProgress: function(processed, total) {
            var progressPercent = Math.round((processed / total) * 100);
            
            // Update progress text
            $('.waicm-progress-text').text(progressPercent + '%');
            
            // Update progress bar width
            $('#waicm-progress-bar').css('width', progressPercent + '%');
            
            // Update status text
            this.$progressStatus.html(
                '<strong>Progress:</strong> ' + progressPercent + '% ' +
                '(' + processed + ' of ' + total + ' products)'
            );
        },

        // Show error message
        showError: function(message) {
            this.$loading.hide();
            this.$button.prop('disabled', false);
            
            this.$results.html(
                '<div class="waicm-notice waicm-notice-error">' +
                '<p><strong>Error:</strong> ' + message + '</p>' +
                '</div>'
            );
        },

        // Handle AJAX errors
        handleAjaxError: function(xhr, status, error) {
            console.error('AJAX Error:', status, error);
            
            var errorMsg = 'An error occurred while processing your request. Please try again.';
            
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
            
            this.showError(errorMsg);
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        if ($('#waicm-external-search-wrap').length) {
            WAICMExtSearch.init();
        }
    });

})(jQuery);
