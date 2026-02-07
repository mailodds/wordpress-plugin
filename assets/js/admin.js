/**
 * MailOdds bulk validation admin JS.
 */
(function($) {
	'use strict';

	var totalProcessed = 0;

	$('#mailodds-bulk-start').on('click', function(e) {
		e.preventDefault();
		var $btn = $(this);
		$btn.prop('disabled', true).text('Validating...');
		$('#mailodds-bulk-progress').show();
		totalProcessed = 0;
		processBatch();
	});

	function processBatch() {
		$.ajax({
			url: mailodds_ajax.ajaxurl,
			type: 'POST',
			data: {
				action: 'mailodds_bulk_validate',
				nonce: mailodds_ajax.nonce,
				offset: totalProcessed
			},
			success: function(response) {
				if (!response.success) {
					$('#mailodds-bulk-status').text('Error: ' + (response.data ? response.data.message : 'Unknown error'));
					$('#mailodds-bulk-start').prop('disabled', false).text('Retry');
					return;
				}

				totalProcessed = response.data.processed;
				$('#mailodds-bulk-text').text('Processed ' + totalProcessed + ' users...');

				if (response.data.done) {
					$('#mailodds-bulk-bar').css('width', '100%');
					$('#mailodds-bulk-status').text('Done! ' + totalProcessed + ' users validated.');
					$('#mailodds-bulk-start').hide();
					setTimeout(function() {
						location.reload();
					}, 2000);
				} else {
					// Continue with next batch
					processBatch();
				}
			},
			error: function() {
				$('#mailodds-bulk-status').text('Network error. Please try again.');
				$('#mailodds-bulk-start').prop('disabled', false).text('Retry');
			}
		});
	}

})(jQuery);
