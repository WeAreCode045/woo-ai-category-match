jQuery(document).ready(function($) {
    // --- External Site/Category Matching ---
    $('#waicm-ext-search-btn').on('click', function() {
        var url1 = $('#waicm-ext-url-1').val().trim();
        var url2 = $('#waicm-ext-url-2').val().trim();
        var instructions = $('#waicm-ext-instructions').val().trim();
        var $button = $(this);
        var $loading = $('#waicm-ext-search-loading');
        
        // Reset UI
        $('#waicm-step2-results').empty();
        $button.prop('disabled', true);
        $loading.show();
        
        if (!url1 && !url2) {
            alert('Please enter at least one URL.');
            $loading.hide();
            $button.prop('disabled', false);
            return;
        }
        
        // Function to update progress
        function updateProgress(processed, total) {
            var progressPercent = Math.round((processed / total) * 100);
            
            // Update progress status
            $('#waicm-step2-progress-status').html(
                '<strong>Progress:</strong> ' + progressPercent + '% ' +
                '(' + processed + ' of ' + total + ' products)'
            );
            
            // Update or create progress bar
            if ($('#waicm-step2-progress-bar').length === 0) {
                $('#waicm-step2-progress-status').after(
                    '<div class="progress" style="height: 20px; margin: 10px 0; background: #f0f0f0; border-radius: 4px; overflow: hidden;">' +
                    '<div id="waicm-step2-progress-bar" class="progress-bar" role="progressbar" style="width: ' + progressPercent + '%; ' +
                    'background: #007cba; color: white; text-align: center; line-height: 20px;" ' +
                    'aria-valuenow="' + progressPercent + '" aria-valuemin="0" aria-valuemax="100">' +
                    progressPercent + '%</div></div>'
                );
            } else {
                $('#waicm-step2-progress-bar')
                    .css('width', progressPercent + '%')
                    .attr('aria-valuenow', progressPercent)
                    .text(progressPercent + '%');
            }
        }
        
        // First, get all uncategorized products
        $.ajax({
            url: waicm.ajax_url,
            type: 'POST',
            data: {
                action: 'waicm_get_uncategorized_products',
                nonce: waicm.nonce
            },
            dataType: 'json',
            success: function(response) {
                if (!response.success || !response.data.products || response.data.products.length === 0) {
                    $loading.hide();
                    $button.prop('disabled', false);
                    $('#waicm-step2-results').html('<p>No uncategorized products found.</p>');
                    return;
                }

                var products = response.data.products;
                var totalProducts = products.length;
                var processedProducts = 0;
                var results = [];
                
                // Initialize progress
                updateProgress(0, totalProducts);
                
                // Process products one by one
                function processNextProduct(index) {
                    if (index >= totalProducts) {
                        // All products processed
                        $loading.hide();
                        $button.prop('disabled', false);
                        
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
                        return;
                    }
                    
                    // Process current product
                    var product = products[index];
                    
                    $.ajax({
                        url: waicm.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'waicm_ext_check_all',
                            nonce: waicm.nonce,
                            products: [product], // Process one product at a time
                            url1: url1,
                            url2: url2,
                            instructions: instructions
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success && response.data && response.data.results) {
                                results = results.concat(response.data.results);
                            }
                            
                            // Update progress and process next product
                            processedProducts++;
                            updateProgress(processedProducts, totalProducts);
                            
                            // Process next product with a small delay
                            setTimeout(function() {
                                processNextProduct(index + 1);
                            }, 500);
                        },
                        error: function(xhr) {
                            console.error('Error processing product:', product, xhr);
                            
                            // Continue with next product even if one fails
                            processedProducts++;
                            updateProgress(processedProducts, totalProducts);
                            
                            // Process next product with a small delay
                            setTimeout(function() {
                                processNextProduct(index + 1);
                            }, 500);
                        }
                    });
                }
                
                // Start processing products
                processNextProduct(0);
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', status, error);
                console.error('Response:', xhr.responseText);
                
                var errorMessage = 'Failed to fetch uncategorized products. ';
                if (status === 'timeout') {
                    errorMessage = 'The request to fetch products timed out. The server might be busy. Please try again in a moment.';
                } else if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMessage += xhr.responseJSON.message;
                }
                
                $loading.hide();
                $button.prop('disabled', false);
                $('#waicm-step2-results').html('<div class="notice notice-error"><p>' + errorMessage + '</p></div>');
            }
        });
    });
});
