/**
 * MailOdds store connect/disconnect AJAX handlers.
 *
 * Expects `mailodds_store` object from wp_localize_script with:
 *   - ajaxurl: WordPress AJAX URL
 *   - nonce: Security nonce
 *   - confirm_disconnect: Translated confirm message
 */
(function($) {
	'use strict';

	$('#mailodds-connect-store').on('click', function() {
		var $btn = $(this);
		var $spinner = $('#mailodds-store-spinner');
		var $msg = $('#mailodds-store-message');

		$btn.prop('disabled', true);
		$spinner.addClass('is-active');
		$msg.text('');

		$.post(mailodds_store.ajaxurl, {
			action: 'mailodds_connect_store',
			_wpnonce: mailodds_store.nonce
		}, function(response) {
			$spinner.removeClass('is-active');
			if (response.success) {
				$msg.css('color', '#10b981').text(response.data.message || 'Connected!');
				setTimeout(function() { location.reload(); }, 1500);
			} else {
				$btn.prop('disabled', false);
				$msg.css('color', '#b91c1c').text(response.data.message || 'Connection failed.');
			}
		}).fail(function() {
			$spinner.removeClass('is-active');
			$btn.prop('disabled', false);
			$msg.css('color', '#b91c1c').text('Request failed. Please try again.');
		});
	});

	$('#mailodds-disconnect-store').on('click', function() {
		if (!confirm(mailodds_store.confirm_disconnect)) {
			return;
		}

		var $btn = $(this);
		var $spinner = $('#mailodds-store-spinner');
		var $msg = $('#mailodds-store-message');

		$btn.prop('disabled', true);
		$spinner.addClass('is-active');
		$msg.text('');

		$.post(mailodds_store.ajaxurl, {
			action: 'mailodds_disconnect_store',
			_wpnonce: mailodds_store.nonce
		}, function(response) {
			$spinner.removeClass('is-active');
			if (response.success) {
				$msg.css('color', '#10b981').text('Disconnected.');
				setTimeout(function() { location.reload(); }, 1500);
			} else {
				$btn.prop('disabled', false);
				$msg.css('color', '#b91c1c').text(response.data.message || 'Failed to disconnect.');
			}
		}).fail(function() {
			$spinner.removeClass('is-active');
			$btn.prop('disabled', false);
			$msg.css('color', '#b91c1c').text('Request failed. Please try again.');
		});
	});
})(jQuery);
