/**
 * Media Usage Inspector - Media Modal Integration
 *
 * Adds "Where Used" functionality to the WordPress media modal
 */

(function($, wp) {
    'use strict';

    // Bail if media views not available
    if (!wp || !wp.media || !wp.media.view) {
        return;
    }

    /**
     * Extend the Attachment Details view
     */
    var originalAttachmentDetailsTwoColumn = wp.media.view.Attachment.Details.TwoColumn;

    wp.media.view.Attachment.Details.TwoColumn = originalAttachmentDetailsTwoColumn.extend({

        /**
         * Render the view
         */
        render: function() {
            originalAttachmentDetailsTwoColumn.prototype.render.apply(this, arguments);

            var self = this;

            // Add usage panel after a short delay to ensure the view is fully rendered
            setTimeout(function() {
                self.addUsagePanel();
            }, 100);

            return this;
        },

        /**
         * Add the usage panel to the details
         */
        addUsagePanel: function() {
            var self = this;
            var $details = this.$el.find('.attachment-info');

            // Remove existing panel if present
            $details.find('.mui-modal-usage-panel').remove();

            // Create usage panel container
            var $panel = $('<div class="mui-modal-usage-panel setting"></div>');
            $panel.append('<span class="name">' + unmamMediaModal.strings.whereUsed + '</span>');

            var $content = $('<div class="mui-modal-usage-content"></div>');
            $content.html('<span class="spinner is-active" style="float: none; margin: 0;"></span> ' + unmamMediaModal.strings.loading);
            $panel.append($content);

            // Insert before the actions
            var $actions = $details.find('.attachment-actions');
            if ($actions.length) {
                $actions.before($panel);
            } else {
                $details.append($panel);
            }

            // Fetch usage data
            this.fetchUsage($content);
        },

        /**
         * Fetch usage data via AJAX
         */
        fetchUsage: function($container) {
            var self = this;
            var attachmentId = this.model.get('id');

            $.ajax({
                url: unmamMediaModal.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'unmam_get_attachment_usage',
                    nonce: unmamMediaModal.nonce,
                    attachment_id: attachmentId
                },
                success: function(response) {
                    if (response.success) {
                        self.renderUsage($container, response.data);
                    } else {
                        $container.html('<span class="mui-error">' + (response.data || unmamMediaModal.strings.error) + '</span>');
                    }
                },
                error: function() {
                    $container.html('<span class="mui-error">' + unmamMediaModal.strings.error + '</span>');
                }
            });
        },

        /**
         * Render usage data
         */
        renderUsage: function($container, data) {
            var self = this;
            var html = '';

            // Summary
            html += '<div class="mui-modal-summary">';
            if (data.total_count > 0) {
                html += '<span class="mui-count mui-found">' +
                    unmamMediaModal.strings.usedIn.replace('%d', data.total_count) +
                    '</span>';
            } else {
                html += '<span class="mui-count mui-none">' + unmamMediaModal.strings.noReferences + '</span>';
            }

            if (data.is_safe) {
                html += ' <span class="mui-safe-indicator">' + unmamMediaModal.strings.markedSafe + '</span>';
            }
            html += '</div>';

            // Reference list
            if (data.references && data.references.length > 0) {
                html += '<ul class="mui-modal-refs">';
                data.references.forEach(function(ref) {
                    html += '<li class="mui-modal-ref">';
                    html += '<span class="mui-ref-badge">' + self.escapeHtml(ref.context_label || ref.context_type) + '</span>';

                    if (ref.source_id > 0) {
                        html += '<span class="mui-ref-title">' + self.escapeHtml(ref.source_title || 'Post #' + ref.source_id) + '</span>';
                        if (ref.edit_link) {
                            html += '<a href="' + ref.edit_link + '" target="_blank" class="mui-ref-link">' + unmamMediaModal.strings.edit + '</a>';
                        }
                    } else {
                        html += '<span class="mui-ref-title">' + self.escapeHtml(ref.context_key) + '</span>';
                    }

                    html += '</li>';
                });
                html += '</ul>';

                if (data.has_more) {
                    html += '<p class="mui-modal-more"><a href="#">' + 'View all references' + '</a></p>';
                }
            }

            // Actions
            html += '<div class="mui-modal-actions">';
            if (data.is_safe) {
                html += '<button type="button" class="button button-small mui-unmark-safe-btn">' +
                    unmamMediaModal.strings.unmarkSafe + '</button>';
            } else {
                html += '<button type="button" class="button button-small mui-mark-safe-btn">' +
                    unmamMediaModal.strings.markSafe + '</button>';
            }
            html += '</div>';

            $container.html(html);

            // Bind action events
            this.bindUsageActions($container);
        },

        /**
         * Bind usage action events
         */
        bindUsageActions: function($container) {
            var self = this;
            var attachmentId = this.model.get('id');

            $container.find('.mui-mark-safe-btn').on('click', function(e) {
                e.preventDefault();
                self.markSafe(attachmentId, $container);
            });

            $container.find('.mui-unmark-safe-btn').on('click', function(e) {
                e.preventDefault();
                self.unmarkSafe(attachmentId, $container);
            });
        },

        /**
         * Mark attachment as safe
         */
        markSafe: function(attachmentId, $container) {
            var self = this;

            $.ajax({
                url: unmamMediaModal.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'unmam_mark_safe',
                    nonce: unmamMediaModal.nonce,
                    attachment_id: attachmentId
                },
                success: function(response) {
                    if (response.success) {
                        // Re-fetch and render
                        self.fetchUsage($container);
                    }
                }
            });
        },

        /**
         * Unmark attachment as safe
         */
        unmarkSafe: function(attachmentId, $container) {
            var self = this;

            $.ajax({
                url: unmamMediaModal.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'unmam_unmark_safe',
                    nonce: unmamMediaModal.nonce,
                    attachment_id: attachmentId
                },
                success: function(response) {
                    if (response.success) {
                        // Re-fetch and render
                        self.fetchUsage($container);
                    }
                }
            });
        },

        /**
         * Escape HTML
         */
        escapeHtml: function(text) {
            if (!text) return '';
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    });

    /**
     * Add CSS for the modal
     */
    $('<style>')
        .prop('type', 'text/css')
        .html(`
            .mui-modal-usage-panel {
                margin-top: 15px;
                padding-top: 15px;
                border-top: 1px solid #dcdcde;
            }

            .mui-modal-usage-panel .name {
                font-weight: 600;
                display: block;
                margin-bottom: 10px;
            }

            .mui-modal-summary {
                margin-bottom: 10px;
            }

            .mui-count {
                font-weight: 500;
            }

            .mui-found {
                color: #00a32a;
            }

            .mui-none {
                color: #787c82;
            }

            .mui-safe-indicator {
                display: inline-block;
                padding: 2px 8px;
                background: #d7f7dc;
                color: #006b1a;
                border-radius: 3px;
                font-size: 11px;
                margin-left: 10px;
            }

            .mui-modal-refs {
                list-style: none;
                padding: 0;
                margin: 0 0 10px;
                max-height: 150px;
                overflow-y: auto;
            }

            .mui-modal-ref {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 6px 0;
                border-bottom: 1px solid #f0f0f1;
                font-size: 12px;
            }

            .mui-modal-ref:last-child {
                border-bottom: none;
            }

            .mui-ref-badge {
                display: inline-block;
                padding: 2px 6px;
                background: #f0f0f1;
                border-radius: 3px;
                font-size: 10px;
                color: #50575e;
                white-space: nowrap;
            }

            .mui-ref-title {
                flex: 1;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .mui-ref-link {
                text-decoration: none;
                color: #2271b1;
            }

            .mui-modal-actions {
                margin-top: 10px;
                padding-top: 10px;
                border-top: 1px solid #f0f0f1;
            }

            .mui-error {
                color: #d63638;
            }
        `)
        .appendTo('head');

})(jQuery, window.wp);
