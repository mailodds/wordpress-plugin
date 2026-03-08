/**
 * MailOdds admin JS.
 *
 * Handles:
 * - Bulk validation (synchronous batch for < 100 users, job-based for >= 100)
 * - Suppression add/remove AJAX
 * - Policy management AJAX
 * - Job polling state machine
 */
(function($) {
	'use strict';

	// =========================================================================
	// Bulk validation: state machine
	// =========================================================================

	var state = 'idle'; // idle -> creating -> polling -> applying -> done
	var totalProcessed = 0;
	var activeJobId = null;
	var pollInterval = 3000;
	var pollTimer = null;
	var pollCount = 0;

	$('#mailodds-bulk-start').on('click', function(e) {
		e.preventDefault();
		var $btn = $(this);
		var count = parseInt($btn.data('count'), 10) || 0;
		var threshold = parseInt($btn.data('threshold'), 10) || 100;

		$btn.prop('disabled', true).text('Validating...');
		$('#mailodds-bulk-progress').show();
		totalProcessed = 0;

		if (count >= threshold) {
			createJob();
		} else {
			processBatch();
		}
	});

	// Resume polling for active job on page load
	var $activeNotice = $('#mailodds-active-job-notice');
	if ($activeNotice.length) {
		activeJobId = $activeNotice.data('job-id');
		if (activeJobId) {
			state = 'polling';
			$('#mailodds-bulk-progress').show();
			pollJobStatus();
		}
	}

	// Cancel button
	$('#mailodds-bulk-cancel').on('click', function(e) {
		e.preventDefault();
		var jobId = $(this).data('job');
		if (!jobId) return;

		$(this).prop('disabled', true);
		$.ajax({
			url: mailodds_ajax.ajaxurl,
			type: 'POST',
			data: {
				action: 'mailodds_cancel_job',
				nonce: mailodds_ajax.nonce,
				job_id: jobId
			},
			success: function(response) {
				if (response.success) {
					$('#mailodds-bulk-status').text('Job cancelled.');
					clearTimeout(pollTimer);
					setTimeout(function() { location.reload(); }, 1500);
				} else {
					$('#mailodds-bulk-status').text('Cancel failed: ' + (response.data ? response.data.message : 'Unknown error'));
				}
			}
		});
	});

	// Synchronous batch processing (< 100 users)
	function processBatch() {
		state = 'creating';
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
					state = 'idle';
					return;
				}

				totalProcessed = response.data.processed;
				$('#mailodds-bulk-text').text('Processed ' + totalProcessed + ' users...');

				if (response.data.done) {
					state = 'done';
					$('#mailodds-bulk-bar').css('width', '100%');
					$('#mailodds-bulk-status').text('Done! ' + totalProcessed + ' users validated.');
					$('#mailodds-bulk-start').hide();
					setTimeout(function() { location.reload(); }, 2000);
				} else {
					processBatch();
				}
			},
			error: function() {
				$('#mailodds-bulk-status').text('Network error. Please try again.');
				$('#mailodds-bulk-start').prop('disabled', false).text('Retry');
				state = 'idle';
			}
		});
	}

	// Job-based flow (>= 100 users)
	function createJob() {
		state = 'creating';
		$('#mailodds-bulk-text').text('Creating validation job...');

		$.ajax({
			url: mailodds_ajax.ajaxurl,
			type: 'POST',
			data: {
				action: 'mailodds_create_bulk_job',
				nonce: mailodds_ajax.nonce
			},
			success: function(response) {
				if (!response.success) {
					$('#mailodds-bulk-status').text('Error: ' + (response.data ? response.data.message : 'Unknown error'));
					$('#mailodds-bulk-start').prop('disabled', false).text('Retry');
					state = 'idle';
					return;
				}

				activeJobId = response.data.job_id;
				totalProcessed = 0;
				state = 'polling';
				pollCount = 0;
				pollJobStatus();
			},
			error: function() {
				$('#mailodds-bulk-status').text('Network error creating job.');
				$('#mailodds-bulk-start').prop('disabled', false).text('Retry');
				state = 'idle';
			}
		});
	}

	function pollJobStatus() {
		if (state !== 'polling' || !activeJobId) return;

		$.ajax({
			url: mailodds_ajax.ajaxurl,
			type: 'POST',
			data: {
				action: 'mailodds_poll_job_status',
				nonce: mailodds_ajax.nonce,
				job_id: activeJobId
			},
			success: function(response) {
				if (!response.success) {
					$('#mailodds-bulk-status').text('Poll error: ' + (response.data ? response.data.message : 'Unknown'));
					return;
				}

				var d = response.data;
				var total = d.total_count || 1;
				var processed = d.processed_count || 0;
				var pct = Math.round((processed / total) * 100);

				$('#mailodds-bulk-bar').css('width', pct + '%');
				$('#mailodds-bulk-text').text('Processing: ' + processed + ' / ' + total + ' (' + pct + '%)');

				if (d.status === 'completed') {
					state = 'applying';
					$('#mailodds-bulk-text').text('Applying results...');
					applyResults(1);
				} else if (d.status === 'failed' || d.status === 'cancelled') {
					state = 'idle';
					$('#mailodds-bulk-status').text('Job ' + d.status + '.');
					$('#mailodds-bulk-start').prop('disabled', false).text('Retry');
				} else {
					// Still processing - poll again with backoff
					pollCount++;
					var delay = pollCount > 10 ? 10000 : pollInterval;
					pollTimer = setTimeout(pollJobStatus, delay);
				}
			},
			error: function() {
				// Retry poll on network error
				pollTimer = setTimeout(pollJobStatus, pollInterval * 2);
			}
		});
	}

	function applyResults(page) {
		$.ajax({
			url: mailodds_ajax.ajaxurl,
			type: 'POST',
			data: {
				action: 'mailodds_apply_job_results',
				nonce: mailodds_ajax.nonce,
				job_id: activeJobId,
				results_page: page
			},
			success: function(response) {
				if (!response.success) {
					$('#mailodds-bulk-status').text('Apply error: ' + (response.data ? response.data.message : 'Unknown'));
					return;
				}

				totalProcessed += response.data.applied;
				$('#mailodds-bulk-text').text('Applied results: ' + totalProcessed + ' users updated...');

				if (response.data.has_more) {
					applyResults(response.data.next_page);
				} else {
					state = 'done';
					$('#mailodds-bulk-bar').css('width', '100%');
					$('#mailodds-bulk-status').text('Done! ' + totalProcessed + ' users validated.');
					$('#mailodds-bulk-start').hide();
					setTimeout(function() { location.reload(); }, 2000);
				}
			},
			error: function() {
				$('#mailodds-bulk-status').text('Network error applying results.');
			}
		});
	}

	// =========================================================================
	// Suppression management
	// =========================================================================

	$('#mailodds-supp-add').on('click', function(e) {
		e.preventDefault();
		var $btn = $(this);
		$btn.prop('disabled', true);

		$.ajax({
			url: mailodds_ajax.ajaxurl,
			type: 'POST',
			data: {
				action: 'mailodds_add_suppression',
				nonce: mailodds_ajax.nonce,
				email: $('#mailodds-supp-email').val(),
				type: $('#mailodds-supp-type').val(),
				reason: $('#mailodds-supp-reason').val()
			},
			success: function(response) {
				$btn.prop('disabled', false);
				if (response.success) {
					$('#mailodds-supp-add-status').text('Added.').css('color', '#00a32a');
					$('#mailodds-supp-email').val('');
					$('#mailodds-supp-reason').val('');
					setTimeout(function() { location.reload(); }, 1000);
				} else {
					$('#mailodds-supp-add-status').text(response.data ? response.data.message : 'Error').css('color', '#d63638');
				}
			},
			error: function() {
				$btn.prop('disabled', false);
				$('#mailodds-supp-add-status').text('Network error.').css('color', '#d63638');
			}
		});
	});

	$(document).on('click', '.mailodds-supp-remove', function(e) {
		e.preventDefault();
		var $btn = $(this);
		var email = $btn.data('email');
		$btn.prop('disabled', true);

		$.ajax({
			url: mailodds_ajax.ajaxurl,
			type: 'POST',
			data: {
				action: 'mailodds_remove_suppression',
				nonce: mailodds_ajax.nonce,
				email: email
			},
			success: function(response) {
				if (response.success) {
					$btn.closest('tr').fadeOut(300, function() { $(this).remove(); });
				} else {
					$btn.prop('disabled', false);
					alert(response.data ? response.data.message : 'Error');
				}
			},
			error: function() {
				$btn.prop('disabled', false);
				alert('Network error.');
			}
		});
	});

	// =========================================================================
	// Policy management
	// =========================================================================

	$('#mailodds-policy-create').on('click', function(e) {
		e.preventDefault();
		var name = $('#mailodds-policy-name').val();
		if (!name) { $('#mailodds-policy-create-status').text('Name required.'); return; }

		$(this).prop('disabled', true);
		$.ajax({
			url: mailodds_ajax.ajaxurl,
			type: 'POST',
			data: {
				action: 'mailodds_create_policy',
				nonce: mailodds_ajax.nonce,
				name: name
			},
			success: function(response) {
				$('#mailodds-policy-create').prop('disabled', false);
				if (response.success) {
					$('#mailodds-policy-create-status').text('Created.').css('color', '#00a32a');
					setTimeout(function() { location.reload(); }, 1000);
				} else {
					$('#mailodds-policy-create-status').text(response.data ? response.data.message : 'Error').css('color', '#d63638');
				}
			}
		});
	});

	$('.mailodds-preset-create').on('click', function(e) {
		e.preventDefault();
		var preset = $(this).data('preset');
		$(this).prop('disabled', true);

		$.ajax({
			url: mailodds_ajax.ajaxurl,
			type: 'POST',
			data: {
				action: 'mailodds_create_preset',
				nonce: mailodds_ajax.nonce,
				preset: preset
			},
			success: function(response) {
				$('.mailodds-preset-create').prop('disabled', false);
				if (response.success) {
					$('#mailodds-preset-status').text('Preset created.').css('color', '#00a32a');
					setTimeout(function() { location.reload(); }, 1000);
				} else {
					$('#mailodds-preset-status').text(response.data ? response.data.message : 'Error').css('color', '#d63638');
				}
			}
		});
	});

	$(document).on('click', '.mailodds-policy-delete', function(e) {
		e.preventDefault();
		var id = $(this).data('id');
		$(this).prop('disabled', true);

		$.ajax({
			url: mailodds_ajax.ajaxurl,
			type: 'POST',
			data: {
				action: 'mailodds_delete_policy',
				nonce: mailodds_ajax.nonce,
				policy_id: id
			},
			success: function(response) {
				if (response.success) {
					location.reload();
				} else {
					alert(response.data ? response.data.message : 'Error');
				}
			}
		});
	});

	$('#mailodds-policy-test').on('click', function(e) {
		e.preventDefault();
		var email = $('#mailodds-policy-test-email').val();
		var policyId = $('#mailodds-policy-test-id').val();
		if (!email || !policyId) {
			$('#mailodds-policy-test-result').text('Email and Policy ID required.');
			return;
		}

		$(this).prop('disabled', true);
		$.ajax({
			url: mailodds_ajax.ajaxurl,
			type: 'POST',
			data: {
				action: 'mailodds_test_policy',
				nonce: mailodds_ajax.nonce,
				email: email,
				policy_id: policyId
			},
			success: function(response) {
				$('#mailodds-policy-test').prop('disabled', false);
				if (response.success) {
					$('#mailodds-policy-test-result').html('<pre>' + JSON.stringify(response.data, null, 2) + '</pre>');
				} else {
					$('#mailodds-policy-test-result').text(response.data ? response.data.message : 'Error');
				}
			}
		});
	});

	$('#mailodds-rule-add').on('click', function(e) {
		e.preventDefault();
		var policyId = $(this).data('policy');

		$(this).prop('disabled', true);
		$.ajax({
			url: mailodds_ajax.ajaxurl,
			type: 'POST',
			data: {
				action: 'mailodds_add_rule',
				nonce: mailodds_ajax.nonce,
				policy_id: policyId,
				field: $('#mailodds-rule-field').val(),
				operator: $('#mailodds-rule-operator').val(),
				value: $('#mailodds-rule-value').val(),
				rule_action: $('#mailodds-rule-action').val()
			},
			success: function(response) {
				$('#mailodds-rule-add').prop('disabled', false);
				if (response.success) {
					$('#mailodds-rule-add-status').text('Rule added.').css('color', '#00a32a');
					setTimeout(function() { location.reload(); }, 1000);
				} else {
					$('#mailodds-rule-add-status').text(response.data ? response.data.message : 'Error').css('color', '#d63638');
				}
			}
		});
	});

	$(document).on('click', '.mailodds-rule-delete', function(e) {
		e.preventDefault();
		var $btn = $(this);

		$btn.prop('disabled', true);
		$.ajax({
			url: mailodds_ajax.ajaxurl,
			type: 'POST',
			data: {
				action: 'mailodds_delete_rule',
				nonce: mailodds_ajax.nonce,
				policy_id: $btn.data('policy'),
				rule_id: $btn.data('rule')
			},
			success: function(response) {
				if (response.success) {
					$btn.closest('tr').fadeOut(300, function() { $(this).remove(); });
				} else {
					$btn.prop('disabled', false);
					alert(response.data ? response.data.message : 'Error');
				}
			}
		});
	});

})(jQuery);
