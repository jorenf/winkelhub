/**
 * LogicTrade Sync — Admin JavaScript
 */
(function ($) {
    'use strict';

    var config = window.logictradeSyncAdmin || {};

    /* -----------------------------------------------------------------
     * Manual Product Sync
     * ----------------------------------------------------------------- */
    $(document).on('click', '#logictrade-run-sync', function () {
        var $btn      = $(this);
        var $progress = $('#logictrade-sync-progress');
        var $result   = $('#logictrade-sync-result');

        $btn.prop('disabled', true);
        $progress.show();
        $result.empty();

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'logictrade_manual_sync',
                nonce: config.nonce
            },
            timeout: 600000 // 10 minutes — sync can be long.
        })
        .done(function (response) {
            var data = response.data || {};
            var cls  = response.success ? 'notice-success' : 'notice-error';
            $result.html(
                '<div class="notice ' + cls + ' inline"><p>' +
                escapeHtml(data.message || 'Sync complete.') +
                '</p></div>'
            );
        })
        .fail(function (xhr) {
            var msg = 'Sync request failed.';
            if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                msg = xhr.responseJSON.data.message;
            }
            $result.html(
                '<div class="notice notice-error inline"><p>' + escapeHtml(msg) + '</p></div>'
            );
        })
        .always(function () {
            $btn.prop('disabled', false);
            $progress.hide();
        });
    });

    /* -----------------------------------------------------------------
     * Export Order (meta box button)
     * ----------------------------------------------------------------- */
    $(document).on('click', '.logictrade-export-order', function () {
        var $btn    = $(this);
        var orderId = $btn.data('order-id');
        var $result = $btn.siblings('.logictrade-export-result');

        if (!orderId) return;

        $btn.prop('disabled', true).text(config.i18n.exporting || 'Exporting...');
        $result.empty();

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'logictrade_export_order',
                nonce: config.nonce,
                order_id: orderId
            }
        })
        .done(function (response) {
            var data = response.data || {};
            if (response.success) {
                $result.html(
                    '<div class="notice notice-success inline"><p>' + escapeHtml(data.message) + '</p></div>'
                );
                $btn.text('Already Exported');
            } else {
                $result.html(
                    '<div class="notice notice-error inline"><p>' + escapeHtml(data.message) + '</p></div>'
                );
                $btn.prop('disabled', false).text('Export to LogicTrade');
            }
        })
        .fail(function () {
            $result.html(
                '<div class="notice notice-error inline"><p>Export request failed.</p></div>'
            );
            $btn.prop('disabled', false).text('Export to LogicTrade');
        });
    });

    /* -----------------------------------------------------------------
     * Category Mapping Save
     * ----------------------------------------------------------------- */
    $(document).on('submit', '#logictrade-category-mapping-form', function (e) {
        e.preventDefault();

        var $form    = $(this);
        var $btn     = $('#logictrade-save-mappings');
        var $spinner = $('#logictrade-mapping-spinner');
        var $result  = $('#logictrade-mapping-result');

        // Collect mappings from form.
        var mappings = [];
        $form.find('select[name^="mappings"]').each(function () {
            var $select = $(this);
            var $row    = $select.closest('tr');
            var groupId   = $row.find('input[name$="[logictrade_group_id]"]').val();
            var groupName = $row.find('input[name$="[logictrade_group_name]"]').val();
            var wcCatId   = $select.val();

            mappings.push({
                logictrade_group_id: groupId,
                logictrade_group_name: groupName,
                wc_category_id: wcCatId
            });
        });

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $result.empty();

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'logictrade_save_category_mapping',
                nonce: config.nonce,
                mappings: mappings
            }
        })
        .done(function (response) {
            var data = response.data || {};
            var cls  = response.success ? 'notice-success' : 'notice-error';
            $result.html(
                '<div class="notice ' + cls + ' is-dismissible inline"><p>' +
                escapeHtml(data.message || 'Saved.') +
                '</p></div>'
            );
        })
        .fail(function () {
            $result.html(
                '<div class="notice notice-error inline"><p>Save failed.</p></div>'
            );
        })
        .always(function () {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
        });
    });

    /* -----------------------------------------------------------------
     * Connection Test (Settings page)
     * ----------------------------------------------------------------- */
    $(document).on('click', '#logictrade-test-connection', function () {
        var $btn     = $(this);
        var $spinner = $('#logictrade-test-spinner');
        var $result  = $('#logictrade-test-result');

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $result.empty();

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'logictrade_test_connection',
                nonce: config.nonce
            },
            timeout: 30000
        })
        .done(function (response) {
            if (response.success) {
                $result.html(
                    '<div class="notice notice-success inline"><p>Connection successful! ' +
                    escapeHtml(response.data.message || '') +
                    '</p></div>'
                );
            } else {
                $result.html(
                    '<div class="notice notice-error inline"><p>' +
                    escapeHtml(response.data.message || 'Connection failed.') +
                    '</p></div>'
                );
            }
        })
        .fail(function () {
            $result.html(
                '<div class="notice notice-error inline"><p>Connection test failed.</p></div>'
            );
        })
        .always(function () {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
        });
    });

    /* -----------------------------------------------------------------
     * Utility: escape HTML to prevent XSS in dynamic notices.
     * ----------------------------------------------------------------- */
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

})(jQuery);
