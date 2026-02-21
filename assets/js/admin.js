/**
 * Media Usage Inspector Admin Scripts
 *
 * Handles background scanning with polling, pause/resume functionality,
 * and background job queue for bulk operations.
 */

(function($) {
    'use strict';

    var MUI_Admin = {

        /**
         * Current scan state
         */
        scanState: null,

        /**
         * Current job state
         */
        jobState: null,

        /**
         * Polling interval ID for scan
         */
        pollIntervalId: null,

        /**
         * Polling interval ID for jobs
         */
        jobPollIntervalId: null,

        /**
         * Is polling active
         */
        isPolling: false,

        /**
         * Is job polling active
         */
        isJobPolling: false,

        /**
         * Is batch processing active (frontend-driven scan)
         */
        isBatchProcessing: false,

        /**
         * Retry count for batch processing errors
         */
        batchRetryCount: 0,

        /**
         * Maximum retries for batch errors
         */
        maxBatchRetries: 3,

        /**
         * Is job batch processing active
         */
        isJobBatchProcessing: false,

        /**
         * Retry count for job batch processing errors
         */
        jobBatchRetryCount: 0,

        /**
         * Initialize
         */
        init: function() {
            this.scanState = unmamAdmin.backgroundState || { status: 'idle' };
            this.jobState = unmamAdmin.jobState || { job: { status: 'completed' } };
            this.createModal();
            this.bindEvents();
            this.checkInitialState();
        },

        /**
         * Stored callback for modal confirmation
         */
        modalCallback: null,

        /**
         * Stored callback for processing mode selection
         */
        processingModeCallback: null,

        /**
         * Create modal HTML structure
         */
        createModal: function() {
            var self = this;

            if ($('#mui-confirm-modal').length) {
                return; // Already exists
            }

            var modalHtml = '<div class="mui-modal-overlay" id="mui-confirm-modal">' +
                '<div class="mui-modal">' +
                    '<div class="mui-modal-header">' +
                        '<div class="mui-modal-icon mui-modal-icon-warning">' +
                            '<span class="dashicons dashicons-warning"></span>' +
                        '</div>' +
                        '<h3 class="mui-modal-title" id="mui-modal-title">Confirm Action</h3>' +
                    '</div>' +
                    '<div class="mui-modal-body">' +
                        '<p class="mui-modal-message" id="mui-modal-message">Are you sure you want to proceed?</p>' +
                    '</div>' +
                    '<div class="mui-modal-footer">' +
                        '<button type="button" class="button" id="mui-modal-cancel">Cancel</button>' +
                        '<button type="button" class="button button-primary" id="mui-modal-confirm">Confirm</button>' +
                    '</div>' +
                '</div>' +
            '</div>';

            $('body').append(modalHtml);

            // Use event delegation for modal buttons (more reliable)
            $(document).on('click', '#mui-modal-confirm', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $('#mui-confirm-modal').removeClass('mui-modal-visible');
                if (typeof self.modalCallback === 'function') {
                    var cb = self.modalCallback;
                    self.modalCallback = null; // Clear before calling
                    cb();
                }
            });

            $(document).on('click', '#mui-modal-cancel', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $('#mui-confirm-modal').removeClass('mui-modal-visible');
                self.modalCallback = null;
            });

            // Close on overlay click
            $(document).on('click', '#mui-confirm-modal', function(e) {
                if ($(e.target).is('#mui-confirm-modal')) {
                    $(this).removeClass('mui-modal-visible');
                    self.modalCallback = null;
                }
            });

            // Close on Escape key
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && $('#mui-confirm-modal').hasClass('mui-modal-visible')) {
                    $('#mui-confirm-modal').removeClass('mui-modal-visible');
                    self.modalCallback = null;
                }
            });

            // Create processing mode selection modal
            this.createProcessingModeModal();
        },

        /**
         * Create processing mode selection modal
         */
        createProcessingModeModal: function() {
            var self = this;

            if ($('#mui-processing-mode-modal').length) {
                return;
            }

            var modalHtml = '<div class="mui-modal-overlay" id="mui-processing-mode-modal">' +
                '<div class="mui-modal mui-modal-wide">' +
                    '<div class="mui-modal-header">' +
                        '<div class="mui-modal-icon mui-modal-icon-info">' +
                            '<span class="dashicons dashicons-admin-settings"></span>' +
                        '</div>' +
                        '<h3 class="mui-modal-title">Choose Processing Strategy</h3>' +
                    '</div>' +
                    '<div class="mui-modal-body">' +
                        '<p class="mui-modal-message">How would you like batch operations (scan, delete, attach, etc.) to be processed?</p>' +
                        '<div class="mui-processing-options">' +
                            '<label class="mui-processing-option mui-processing-option-selected">' +
                                '<input type="radio" name="unmam_processing_mode_choice" value="frontend" checked>' +
                                '<div class="mui-option-content">' +
                                    '<strong><span class="dashicons dashicons-laptop"></span> Browser-Driven</strong>' +
                                    '<span class="mui-recommended-badge">Recommended</span>' +
                                    '<p>Fast and reliable. Progress updates in real-time.</p>' +
                                    '<p class="mui-option-note"><span class="dashicons dashicons-info"></span> Requires keeping this browser tab open until the operation completes.</p>' +
                                '</div>' +
                            '</label>' +
                            '<label class="mui-processing-option">' +
                                '<input type="radio" name="unmam_processing_mode_choice" value="background">' +
                                '<div class="mui-option-content">' +
                                    '<strong><span class="dashicons dashicons-cloud"></span> Background (WP-Cron)</strong>' +
                                    '<p>Processing continues even after closing the browser.</p>' +
                                    '<p class="mui-option-note"><span class="dashicons dashicons-warning"></span> Relies on site visitor traffic. May be slower on low-traffic sites.</p>' +
                                '</div>' +
                            '</label>' +
                        '</div>' +
                        '<p class="mui-modal-footer-note"><span class="dashicons dashicons-info"></span> You can change this later in the Settings tab.</p>' +
                    '</div>' +
                    '<div class="mui-modal-footer">' +
                        '<button type="button" class="button" id="mui-processing-mode-cancel">Cancel</button>' +
                        '<button type="button" class="button button-primary" id="mui-processing-mode-confirm">Continue</button>' +
                    '</div>' +
                '</div>' +
            '</div>';

            $('body').append(modalHtml);

            // Handle radio button selection styling
            $(document).on('change', 'input[name="unmam_processing_mode_choice"]', function() {
                $('.mui-processing-option').removeClass('mui-processing-option-selected');
                $(this).closest('.mui-processing-option').addClass('mui-processing-option-selected');
            });

            // Handle confirm button
            $(document).on('click', '#mui-processing-mode-confirm', function(e) {
                e.preventDefault();
                e.stopPropagation();

                var selectedMode = $('input[name="unmam_processing_mode_choice"]:checked').val();
                $('#mui-processing-mode-modal').removeClass('mui-modal-visible');

                // Save the setting
                self.saveProcessingMode(selectedMode, function() {
                    // Update the local setting
                    unmamAdmin.processingMode = selectedMode;

                    // Call the stored callback
                    if (typeof self.processingModeCallback === 'function') {
                        var cb = self.processingModeCallback;
                        self.processingModeCallback = null;
                        cb(selectedMode);
                    }
                });
            });

            // Handle cancel button
            $(document).on('click', '#mui-processing-mode-cancel', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $('#mui-processing-mode-modal').removeClass('mui-modal-visible');
                self.processingModeCallback = null;
            });

            // Close on overlay click
            $(document).on('click', '#mui-processing-mode-modal', function(e) {
                if ($(e.target).is('#mui-processing-mode-modal')) {
                    $(this).removeClass('mui-modal-visible');
                    self.processingModeCallback = null;
                }
            });
        },

        /**
         * Show processing mode selection modal
         * @param {Function} callback - Called with selected mode ('frontend' or 'background')
         */
        showProcessingModeModal: function(callback) {
            this.processingModeCallback = callback;
            $('#mui-processing-mode-modal').addClass('mui-modal-visible');
        },

        /**
         * Save processing mode setting via AJAX
         * @param {string} mode - 'frontend' or 'background'
         * @param {Function} callback - Called when save completes
         */
        saveProcessingMode: function(mode, callback) {
            $.ajax({
                url: unmamAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'unmam_save_processing_mode',
                    nonce: unmamAdmin.nonce,
                    processing_mode: mode
                },
                success: function(response) {
                    if (callback) {
                        callback(response.success);
                    }
                },
                error: function() {
                    if (callback) {
                        callback(false);
                    }
                }
            });
        },

        /**
         * Show confirmation modal
         * @param {Object} options - Modal options
         * @param {string} options.title - Modal title
         * @param {string} options.message - Modal message
         * @param {string} options.confirmText - Confirm button text
         * @param {string} options.cancelText - Cancel button text
         * @param {string} options.type - Modal type (warning, danger, info)
         * @param {Function} callback - Callback function when confirmed
         */
        showConfirmModal: function(options, callback) {
            var defaults = {
                title: 'Confirm Action',
                message: 'Are you sure you want to proceed?',
                confirmText: 'Confirm',
                cancelText: 'Cancel',
                type: 'warning'
            };

            var settings = $.extend({}, defaults, options);
            var $modal = $('#mui-confirm-modal');
            var $icon = $modal.find('.mui-modal-icon');

            // Store the callback
            this.modalCallback = callback;

            // Update modal content
            $('#mui-modal-title').text(settings.title);
            $('#mui-modal-message').html(settings.message);
            $('#mui-modal-confirm').text(settings.confirmText);
            $('#mui-modal-cancel').text(settings.cancelText);

            // Update icon type
            $icon.removeClass('mui-modal-icon-warning mui-modal-icon-danger mui-modal-icon-info');
            $icon.addClass('mui-modal-icon-' + settings.type);

            // Update icon
            var iconClass = 'dashicons-warning';
            if (settings.type === 'danger') {
                iconClass = 'dashicons-trash';
                $('#mui-modal-confirm').removeClass('button-primary').addClass('button-danger');
            } else if (settings.type === 'info') {
                iconClass = 'dashicons-info';
                $('#mui-modal-confirm').removeClass('button-danger').addClass('button-primary');
            } else {
                $('#mui-modal-confirm').removeClass('button-danger').addClass('button-primary');
            }
            $icon.find('.dashicons').attr('class', 'dashicons ' + iconClass);

            // Show modal
            $modal.addClass('mui-modal-visible');
        },

        /**
         * Check initial state and start processing if needed
         */
        checkInitialState: function() {
            // Update bulk action buttons based on initial scan state
            this.updateBulkActionButtons(this.scanState.status);

            // Check scan state - start processing if scan is running
            if (this.scanState.status === 'running') {
                // Use appropriate processing method based on mode
                if (unmamAdmin.processingMode === 'background') {
                    this.startPolling();
                } else {
                    // Default to frontend-driven batch processing
                    this.batchRetryCount = 0;
                    this.runScanLoop();
                }
            }

            // Check job state - start processing if job is running
            if (this.jobState && this.jobState.job) {
                // Get the job's processing mode, fallback to global setting
                var jobProcessingMode = this.jobState.processing_mode || unmamAdmin.processingMode || 'frontend';

                if (this.jobState.job.status === 'running') {
                    // Use appropriate processing method based on job's mode
                    if (jobProcessingMode === 'background') {
                        this.startJobPolling();
                    } else {
                        // Default to frontend-driven batch processing
                        this.runJobLoop();
                    }
                }

                // For paused jobs, show the status bar with correct processing mode info
                if (this.jobState.job.status === 'paused') {
                    this.updateJobStatusBar(this.jobState);
                }
            }
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            var self = this;

            // Start scan button
            $(document).on('click', '#mui-start-scan', function(e) {
                e.preventDefault();
                self.startScan();
            });

            // Pause scan button
            $(document).on('click', '#mui-pause-scan', function(e) {
                e.preventDefault();
                self.pauseScan();
            });

            // Resume scan button
            $(document).on('click', '#mui-resume-scan', function(e) {
                e.preventDefault();
                self.resumeScan();
            });

            // Stop scan button
            $(document).on('click', '#mui-stop-scan', function(e) {
                e.preventDefault();
                self.stopScan();
            });

            // Export report
            $(document).on('click', '#mui-export-report', function(e) {
                e.preventDefault();
                self.exportReport();
            });

            // Attach all used
            $(document).on('click', '#mui-attach-all-used', function(e) {
                e.preventDefault();
                self.attachAllUsed();
            });

            // Mark/unmark safe
            $(document).on('click', '.mui-mark-safe', function(e) {
                e.preventDefault();
                self.markSafe($(this));
            });

            $(document).on('click', '.mui-unmark-safe', function(e) {
                e.preventDefault();
                self.unmarkSafe($(this));
            });

            // History tab: Single revert
            $(document).on('click', '.mui-revert-single', function(e) {
                e.preventDefault();
                self.revertSingle($(this));
            });

            // History tab: Select all checkbox
            $(document).on('change', '#mui-select-all', function() {
                var isChecked = $(this).prop('checked');
                $('.mui-history-checkbox').prop('checked', isChecked);
                self.updateRevertSelectedButton();
            });

            // History tab: Individual checkbox change
            $(document).on('change', '.mui-history-checkbox', function() {
                self.updateRevertSelectedButton();
            });

            // History tab: Bulk revert
            $(document).on('click', '#mui-revert-selected', function(e) {
                e.preventDefault();
                self.revertBulk();
            });

            // Unused Media tab: Select all checkbox
            $(document).on('change', '#mui-unused-select-all', function() {
                var isChecked = $(this).prop('checked');
                $('.mui-unused-checkbox').prop('checked', isChecked);
                self.updateUnusedButtons();
            });

            // Unused Media tab: Individual checkbox change
            $(document).on('change', '.mui-unused-checkbox', function() {
                self.updateUnusedButtons();
            });

            // Unused Media tab: Trash single
            $(document).on('click', '.mui-trash-single', function(e) {
                e.preventDefault();
                self.trashSingle($(this));
            });

            // Unused Media tab: Trash selected (bulk - uses job queue)
            $(document).on('click', '#mui-trash-selected', function(e) {
                e.preventDefault();
                self.trashBulk();
            });

            // Unused Media tab: Restore single
            $(document).on('click', '.mui-restore-single', function(e) {
                e.preventDefault();
                self.restoreSingle($(this));
            });

            // Unused Media tab: Restore selected (bulk - uses job queue)
            $(document).on('click', '#mui-restore-selected', function(e) {
                e.preventDefault();
                self.restoreBulk();
            });

            // Unused Media tab: Delete single permanently
            $(document).on('click', '.mui-delete-single', function(e) {
                e.preventDefault();
                self.deleteSinglePermanently($(this));
            });

            // Unused Media tab: Delete selected permanently (bulk - uses job queue)
            $(document).on('click', '#mui-delete-selected-permanently', function(e) {
                e.preventDefault();
                self.deleteBulkPermanently();
            });

            // Unused Media tab: Empty trash (uses job queue)
            $(document).on('click', '#mui-empty-trash', function(e) {
                e.preventDefault();
                self.emptyTrash();
            });

            // Unused Media tab: Trash all unused (uses job queue)
            $(document).on('click', '#mui-trash-all-unused', function(e) {
                e.preventDefault();
                self.trashAllUnused();
            });

            // History tab: Revert all active (uses job queue)
            $(document).on('click', '#mui-revert-all-active', function(e) {
                e.preventDefault();
                self.revertAllActive();
            });

            // Unattached Media tab: Attach all (uses job queue)
            $(document).on('click', '#mui-attach-all-unattached', function(e) {
                e.preventDefault();
                self.attachAllUnattached();
            });

            // Unattached Media tab: Attach selected (uses job queue)
            $(document).on('click', '#mui-attach-selected', function(e) {
                e.preventDefault();
                self.attachSelected();
            });

            // Unattached Media tab: Attach single
            $(document).on('click', '.mui-attach-single', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var attachmentId = $btn.data('id');
                var parentId = $btn.data('parent');
                self.attachSingle(attachmentId, parentId, $btn);
            });

            // Unattached Media tab: Select all checkbox
            $(document).on('change', '#mui-unattached-select-all', function() {
                var checked = $(this).prop('checked');
                $('.mui-unattached-checkbox').prop('checked', checked);
                self.updateUnattachedButtons();
            });

            // Unattached Media tab: Individual checkboxes
            $(document).on('change', '.mui-unattached-checkbox', function() {
                self.updateUnattachedButtons();
            });

            // Job queue controls
            $(document).on('click', '#mui-pause-job', function(e) {
                e.preventDefault();
                self.pauseJob();
            });

            $(document).on('click', '#mui-resume-job', function(e) {
                e.preventDefault();
                self.resumeJob();
            });

            $(document).on('click', '#mui-stop-job', function(e) {
                e.preventDefault();
                self.stopJob();
            });

            // Refresh page button (shown after job completion)
            $(document).on('click', '#mui-refresh-page', function(e) {
                e.preventDefault();
                window.location.reload();
            });
        },

        /**
         * Start a new scan
         */
        startScan: function() {
            var self = this;

            // Check if processing mode is not set (first time)
            if (!unmamAdmin.processingMode) {
                this.showProcessingModeModal(function(mode) {
                    // After mode is selected, proceed with scan
                    self.doStartScan(mode);
                });
                return;
            }

            // Processing mode is already set, proceed normally
            this.showConfirmModal({
                title: unmamAdmin.strings.confirmScanTitle || 'Start New Scan',
                message: unmamAdmin.strings.confirmScan,
                confirmText: unmamAdmin.strings.startScan || 'Start Scan',
                type: 'info'
            }, function() {
                self.doStartScan(unmamAdmin.processingMode);
            });
        },

        /**
         * Actually start the scan with the given processing mode
         * @param {string} mode - 'frontend' or 'background'
         */
        doStartScan: function(mode) {
            var self = this;
            var $button = $('#mui-start-scan');
            $button.prop('disabled', true).text(unmamAdmin.strings.scanning);

            $.ajax({
                url: unmamAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'unmam_start_background_scan',
                    nonce: unmamAdmin.nonce,
                    processing_mode: mode
                }
            }).done(function(response) {
                if (response.success) {
                    // Update UI to show scanning state
                    self.scanState = response.data.state;
                    self.updateUI(response.data);

                    // Use appropriate processing method based on mode
                    if (mode === 'frontend') {
                        // Start frontend-driven batch processing
                        self.batchRetryCount = 0;
                        self.runScanLoop();
                    } else {
                        // Background mode - use polling to monitor progress
                        self.startPolling();
                    }
                } else {
                    self.showError(response.data || unmamAdmin.strings.error);
                    $button.prop('disabled', false).text(unmamAdmin.strings.startScan);
                }
            }).fail(function() {
                self.showError(unmamAdmin.strings.error);
                $button.prop('disabled', false).text(unmamAdmin.strings.startScan);
            });
        },

        /**
         * Pause the scan
         */
        pauseScan: function() {
            var self = this;
            var $button = $('#mui-pause-scan');

            $button.prop('disabled', true).text(unmamAdmin.strings.pausing);

            // Stop batch processing
            self.isBatchProcessing = false;

            $.ajax({
                url: unmamAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'unmam_pause_scan',
                    nonce: unmamAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.stopPolling();
                        self.updateUI(response.data);
                    } else {
                        self.showError(response.data || unmamAdmin.strings.error);
                        $button.prop('disabled', false).text('Pause Scan');
                    }
                },
                error: function() {
                    self.showError(unmamAdmin.strings.error);
                    $button.prop('disabled', false).text('Pause Scan');
                }
            });
        },

        /**
         * Resume a paused scan
         */
        resumeScan: function() {
            var self = this;
            var $button = $('#mui-resume-scan');

            $button.prop('disabled', true).text(unmamAdmin.strings.resuming);

            $.ajax({
                url: unmamAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'unmam_resume_scan',
                    nonce: unmamAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.updateUI(response.data);
                        // Use appropriate processing method based on mode
                        if (unmamAdmin.processingMode === 'background') {
                            self.startPolling();
                        } else {
                            // Default to frontend-driven batch processing
                            self.batchRetryCount = 0;
                            self.runScanLoop();
                        }
                    } else {
                        self.showError(response.data || unmamAdmin.strings.error);
                        $button.prop('disabled', false).text('Resume Scan');
                    }
                },
                error: function() {
                    self.showError(unmamAdmin.strings.error);
                    $button.prop('disabled', false).text('Resume Scan');
                }
            });
        },

        /**
         * Stop the scan completely
         */
        stopScan: function() {
            var self = this;

            this.showConfirmModal({
                title: 'Cancel Scan',
                message: 'Are you sure you want to cancel the scan? All progress will be reset and you\'ll need to start a new scan.',
                confirmText: 'Cancel Scan',
                type: 'danger'
            }, function() {
                var $button = $('#mui-stop-scan');
                $button.prop('disabled', true).text(unmamAdmin.strings.stopping);

                // Stop batch processing
                self.isBatchProcessing = false;

                $.ajax({
                    url: unmamAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'unmam_stop_scan',
                        nonce: unmamAdmin.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            self.stopPolling();
                            self.updateUI(response.data);
                        } else {
                            self.showError(response.data || unmamAdmin.strings.error);
                        }
                    },
                    error: function() {
                        self.showError(unmamAdmin.strings.error);
                    }
                });
            });
        },

        /**
         * Run the scan loop (frontend-driven batch processing)
         *
         * This is more reliable than WP-Cron or loopback HTTP because
         * the browser directly drives the process. As long as the page
         * is open, batches will be processed continuously.
         */
        runScanLoop: function() {
            var self = this;

            // Check if already processing
            if (this.isBatchProcessing) {
                return;
            }

            this.isBatchProcessing = true;
            this.processScanBatch();
        },

        /**
         * Stop the scan loop
         */
        stopScanLoop: function() {
            this.isBatchProcessing = false;
        },

        /**
         * Process a single batch and continue if more work remains
         */
        processScanBatch: function() {
            var self = this;

            // Check if we should stop
            if (!this.isBatchProcessing) {
                return;
            }

            $.ajax({
                url: unmamAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'unmam_process_scan_batch',
                    nonce: unmamAdmin.nonce
                },
                timeout: 60000 // 60 second timeout for batch processing
            }).done(function(response) {
                if (response.success) {
                    // Reset retry count on success
                    self.batchRetryCount = 0;

                    // Update UI with new progress
                    self.updateUI({
                        state: response.data.state,
                        scan_progress: response.data.scan_progress
                    });

                    // Check if finished
                    if (response.data.finished) {
                        self.isBatchProcessing = false;

                        if (response.data.reason === 'scan_completed') {
                            // Scan completed successfully
                            self.refreshStatistics();
                        }
                        // For 'not_running' or other reasons, just stop
                        return;
                    }

                    // Check if paused
                    if (response.data.reason === 'paused') {
                        self.isBatchProcessing = false;
                        return;
                    }

                    // More work to do - process next batch with delay from resource settings
                    // This prevents overwhelming the server and allows UI updates
                    var batchDelay = unmamAdmin.batchDelay || 100;
                    setTimeout(function() {
                        self.processScanBatch();
                    }, batchDelay);

                } else {
                    // Server returned an error
                    self.handleBatchError('Server error: ' + (response.data || 'Unknown error'));
                }
            }).fail(function(xhr, status, error) {
                // Network or timeout error
                self.handleBatchError('Request failed: ' + error);
            });
        },

        /**
         * Handle batch processing errors with retry logic
         */
        handleBatchError: function(errorMessage) {
            var self = this;

            this.batchRetryCount++;

            if (this.batchRetryCount <= this.maxBatchRetries) {
                // Wait and retry with exponential backoff
                var delay = Math.min(1000 * Math.pow(2, this.batchRetryCount - 1), 10000);

                setTimeout(function() {
                    if (self.isBatchProcessing) {
                        self.processScanBatch();
                    }
                }, delay);
            } else {
                // Too many retries, stop and show error
                this.isBatchProcessing = false;
                this.showError('Scan stopped due to repeated errors. Please try again or check your server logs.');

                // Update UI to show scan is stalled
                this.pollStatus(); // Get current state
            }
        },

        // ==========================================
        // Job Batch Processing (frontend-driven)
        // ==========================================

        /**
         * Run the job batch loop (frontend-driven processing)
         */
        runJobLoop: function() {
            var self = this;

            if (this.isJobBatchProcessing) {
                return;
            }

            this.isJobBatchProcessing = true;
            this.jobBatchRetryCount = 0;
            this.processJobBatch();
        },

        /**
         * Stop the job batch loop
         */
        stopJobLoop: function() {
            this.isJobBatchProcessing = false;
        },

        /**
         * Process a single job batch and continue if more work remains
         */
        processJobBatch: function() {
            var self = this;

            if (!this.isJobBatchProcessing) {
                return;
            }

            $.ajax({
                url: unmamAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'unmam_process_job_batch',
                    nonce: unmamAdmin.nonce
                },
                timeout: 60000
            }).done(function(response) {
                if (response.success) {
                    self.jobBatchRetryCount = 0;

                    // Update UI with new progress
                    if (response.data.status) {
                        self.jobState = response.data.status;
                        self.updateJobStatusBar(response.data.status);
                    }

                    // Check if finished
                    if (response.data.finished) {
                        self.isJobBatchProcessing = false;

                        if (response.data.reason === 'job_completed') {
                            self.onJobComplete(response.data.status);
                        }
                        return;
                    }

                    // Check for resource pause
                    if (response.data.reason === 'resource_pause') {
                        // Wait a bit longer before retrying
                        setTimeout(function() {
                            self.processJobBatch();
                        }, 2000);
                        return;
                    }

                    // More work to do - process next batch
                    var batchDelay = unmamAdmin.batchDelay || 100;
                    setTimeout(function() {
                        self.processJobBatch();
                    }, batchDelay);

                } else {
                    self.handleJobBatchError('Server error: ' + (response.data || 'Unknown error'));
                }
            }).fail(function(xhr, status, error) {
                self.handleJobBatchError('Request failed: ' + error);
            });
        },

        /**
         * Handle job batch processing errors with retry logic
         */
        handleJobBatchError: function(errorMessage) {
            var self = this;

            this.jobBatchRetryCount++;

            if (this.jobBatchRetryCount <= this.maxBatchRetries) {
                var delay = Math.min(1000 * Math.pow(2, this.jobBatchRetryCount - 1), 10000);

                setTimeout(function() {
                    if (self.isJobBatchProcessing) {
                        self.processJobBatch();
                    }
                }, delay);
            } else {
                this.isJobBatchProcessing = false;
                this.showError('Process stopped due to repeated errors. Please try again or check your server logs.');
                this.pollJobStatus();
            }
        },

        /**
         * Start a job with appropriate processing mode
         * @param {string} jobType - Type of job (attach, trash, delete, etc.)
         * @param {array} itemIds - Array of item IDs to process
         * @param {object} meta - Additional metadata for the job
         * @param {string} buttonSelector - Selector for the button to re-enable on error
         * @param {string} originalText - Original button text
         */
        startJobWithLoop: function(jobType, itemIds, meta, buttonSelector, originalText) {
            var self = this;
            var $button = $(buttonSelector);

            // Check if processing mode is not set (first time)
            if (!unmamAdmin.processingMode) {
                this.showProcessingModeModal(function(mode) {
                    // After mode is selected, proceed with job
                    self.doStartJob(jobType, itemIds, meta, $button, originalText, mode);
                });
                return;
            }

            // Processing mode is already set, proceed
            this.doStartJob(jobType, itemIds, meta, $button, originalText, unmamAdmin.processingMode);
        },

        /**
         * Actually start the job with the given processing mode
         */
        doStartJob: function(jobType, itemIds, meta, $button, originalText, mode) {
            var self = this;

            $.ajax({
                url: unmamAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'unmam_start_job',
                    nonce: unmamAdmin.nonce,
                    job_type: jobType,
                    item_ids: itemIds,
                    meta: meta || {},
                    processing_mode: mode
                }
            }).done(function(response) {
                if (response.success) {
                    // Update job state and show progress bar
                    self.jobState = response.data;
                    self.createJobStatusBar(response.data);
                    self.updateJobStatusBar(response.data);

                    // Use appropriate processing method based on mode
                    if (mode === 'frontend') {
                        // Frontend-driven batch processing
                        self.runJobLoop();
                    } else {
                        // Background mode - use polling to monitor progress
                        self.startJobPolling();
                    }
                } else {
                    self.showError(response.data || unmamAdmin.strings.error);
                    if ($button.length) {
                        $button.prop('disabled', false).text(originalText);
                    }
                }
            }).fail(function() {
                self.showError(unmamAdmin.strings.error);
                if ($button.length) {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        },

        /**
         * Start polling for status updates
         */
        startPolling: function() {
            var self = this;

            if (this.isPolling) {
                return;
            }

            this.isPolling = true;
            this.pollIntervalId = setInterval(function() {
                self.pollStatus();
            }, unmamAdmin.pollInterval || 3000);
        },

        /**
         * Stop polling
         */
        stopPolling: function() {
            this.isPolling = false;
            if (this.pollIntervalId) {
                clearInterval(this.pollIntervalId);
                this.pollIntervalId = null;
            }
        },

        /**
         * Poll for status update
         */
        pollStatus: function() {
            var self = this;

            $.ajax({
                url: unmamAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'unmam_get_scan_status',
                    nonce: unmamAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.updateUI(response.data);

                        // Check if we should stop polling
                        var state = response.data.state;
                        if (state.status !== 'running') {
                            self.stopPolling();

                            // If completed, refresh statistics
                            if (state.status === 'completed') {
                                self.refreshStatistics();
                            }
                        }
                    }
                },
                error: function() {
                    // Continue polling even on error
                }
            });
        },

        /**
         * Update UI based on status
         */
        updateUI: function(data) {
            var state = data.state || {};
            var scanProgress = data.scan_progress || {};
            var percentage = scanProgress.overall ? scanProgress.overall.percentage : 0;
            var currentType = state.current_type || 'posts';

            this.scanState = state;

            // Update progress bar
            var $progressBar = $('.mui-progress-bar');
            var $progressFill = $('#mui-progress-fill');
            var $progressText = $('#mui-progress-text');
            var $attachPanel = $('#mui-attach-panel');

            // Show progress bar when running or paused (even if percentage is 0)
            if (state.status === 'running' || state.status === 'paused' || percentage > 0) {
                $progressBar.show();
                $progressText.show();
            }

            // Hide/show the "Fix Unattached Media" panel based on scan state
            if (state.status === 'running' || state.status === 'paused') {
                $attachPanel.hide();
            } else {
                $attachPanel.show();
            }

            $progressFill.css('width', percentage + '%');
            $progressText.text(percentage + '%');

            // Update status badge and buttons
            var $statusContainer = $('#mui-scan-status');
            var $actionsContainer = $('.mui-scan-actions');
            var $scanInfo = $('#mui-scan-info');

            // Update status badge
            var badgeHtml = '';
            var actionsHtml = '';

            switch (state.status) {
                case 'running':
                    badgeHtml = '<span class="mui-status-badge mui-status-running">' +
                                unmamAdmin.strings.scanningBg +
                                '<span class="mui-pulse"></span></span>';
                    actionsHtml = '<button type="button" class="button button-secondary" id="mui-pause-scan">Pause Scan</button>' +
                                  '<button type="button" class="button button-link-delete" id="mui-stop-scan">Cancel Scan</button>';
                    $scanInfo.text(unmamAdmin.strings.currentlyScanning.replace('%s', this.capitalize(currentType)));
                    break;

                case 'paused':
                    badgeHtml = '<span class="mui-status-badge mui-status-warning">' + unmamAdmin.strings.paused + '</span>';
                    actionsHtml = '<button type="button" class="button button-primary" id="mui-resume-scan">Resume Scan</button>' +
                                  '<button type="button" class="button button-link-delete" id="mui-stop-scan">Cancel Scan</button>';
                    $scanInfo.text('Scan paused at ' + percentage + '%. Click Resume to continue or Cancel to start fresh.');
                    break;

                case 'completed':
                    badgeHtml = '<span class="mui-status-badge mui-status-success">' + unmamAdmin.strings.indexUpToDate + '</span>';
                    actionsHtml = '<button type="button" class="button button-primary" id="mui-start-scan">' + unmamAdmin.strings.startScan + '</button>';
                    $scanInfo.text('');
                    percentage = 100;
                    $progressFill.css('width', '100%');
                    $progressText.text('100%');
                    break;

                default: // idle
                    badgeHtml = '<span class="mui-status-badge mui-status-pending">' + unmamAdmin.strings.notIndexed + '</span>';
                    actionsHtml = '<button type="button" class="button button-primary" id="mui-start-scan">' + unmamAdmin.strings.startScan + '</button>';
                    $scanInfo.text('');
                    // Reset and hide progress bar for idle state
                    percentage = 0;
                    $progressFill.css('width', '0%');
                    $progressText.text('0%');
                    $progressBar.hide();
                    $progressText.hide();
                    break;
            }

            // Add export button to all states
            actionsHtml += '<button type="button" class="button" id="mui-export-report">Export Report (CSV)</button>';

            // Update DOM
            $statusContainer.find('.mui-status-badge').replaceWith(badgeHtml);
            $actionsContainer.html(actionsHtml);

            // Update resource info if available
            if (data.resource_status) {
                var resourceInfo = 'Memory: ' + data.resource_status.memory_percent + '% | ' +
                                   'Batch: ' + data.resource_status.recommended_batch + ' items';

                if (!$('#mui-resource-info').length) {
                    $scanInfo.after('<div id="mui-resource-info" style="font-size: 11px; color: #666; margin-top: 5px;"></div>');
                }
                $('#mui-resource-info').text(resourceInfo);
            }

            // Update bulk action button states based on scan status
            this.updateBulkActionButtons(state.status);
        },

        /**
         * Enable/disable bulk action buttons based on scan status
         * When a scan is running or paused, bulk operations should be disabled
         * to prevent interference with the scan process.
         *
         * @param {string} scanStatus - Current scan status (running, paused, completed, idle)
         */
        updateBulkActionButtons: function(scanStatus) {
            var isScanning = (scanStatus === 'running' || scanStatus === 'paused');

            // List of bulk action button selectors to disable during scanning
            var bulkButtons = [
                '#mui-attach-all-used',
                '#mui-trash-selected',
                '#mui-restore-selected',
                '#mui-delete-selected-permanently',
                '#mui-empty-trash',
                '#mui-trash-all-unused',
                '#mui-revert-selected',
                '#mui-revert-all-active',
                '#mui-attach-all-unattached',
                '#mui-attach-selected'
            ];

            bulkButtons.forEach(function(selector) {
                var $button = $(selector);
                if ($button.length) {
                    if (isScanning) {
                        $button.prop('disabled', true);
                        if (!$button.data('original-title')) {
                            $button.data('original-title', $button.attr('title') || '');
                        }
                        $button.attr('title', unmamAdmin.strings.disabledDuringScan || 'Disabled while scan is in progress');
                    } else {
                        // Only re-enable if button wasn't disabled for other reasons (like no selection)
                        // Check specific button logic
                        var shouldEnable = true;

                        // Check selection-based buttons
                        if (selector === '#mui-trash-selected' || selector === '#mui-restore-selected' || selector === '#mui-delete-selected-permanently') {
                            shouldEnable = $('.mui-unused-checkbox:checked').length > 0;
                        } else if (selector === '#mui-revert-selected') {
                            shouldEnable = $('.mui-history-checkbox:checked').length > 0;
                        } else if (selector === '#mui-attach-selected') {
                            shouldEnable = $('.mui-unattached-checkbox:checked').length > 0;
                        }

                        if (shouldEnable) {
                            $button.prop('disabled', false);
                        }
                        $button.attr('title', $button.data('original-title') || '');
                    }
                }
            });

            // Also show/hide a notice in panels during scanning
            if (isScanning) {
                if (!$('.mui-scanning-notice').length) {
                    var noticeHtml = '<div class="mui-scanning-notice mui-notice mui-notice-warning" style="margin: 10px 0;">' +
                        '<span class="dashicons dashicons-info" style="color: #dba617;"></span> ' +
                        (unmamAdmin.strings.bulkDisabledDuringScan || 'Bulk operations are disabled while a scan is in progress. Please wait for the scan to complete or cancel it.') +
                        '</div>';
                    // Add notice to panels that have bulk actions
                    $('#mui-attach-panel, .mui-unused-filters, .mui-history-filters, .mui-unattached-filters').prepend(noticeHtml);
                }
            } else {
                $('.mui-scanning-notice').remove();
            }
        },

        /**
         * Capitalize first letter
         */
        capitalize: function(str) {
            return str.charAt(0).toUpperCase() + str.slice(1);
        },

        /**
         * Refresh statistics after scan completion
         */
        refreshStatistics: function() {
            var self = this;

            // Update scan info text to show completion
            $('#mui-scan-info').html(
                '<span style="color: #00a32a;"><span class="dashicons dashicons-yes-alt"></span> ' +
                'Scan completed successfully. Refresh the page to see updated statistics.</span>'
            );

            // Show a refresh button in the actions area
            var $actionsContainer = $('.mui-scan-actions');
            $actionsContainer.html(
                '<button type="button" class="button button-primary" onclick="location.reload();">Refresh Page</button>' +
                '<button type="button" class="button" id="mui-export-report">Export Report (CSV)</button>'
            );

            // Optionally fetch updated stats (for UI updates that don't need reload)
            $.ajax({
                url: unmamAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'unmam_get_statistics',
                    nonce: unmamAdmin.nonce
                },
                success: function(response) {
                    if (response.success && response.data) {
                        // Update stat cards if they exist
                        if (response.data.total_media !== undefined) {
                            // Update displayed stats without reload
                            self.updateStatCards(response.data);
                        }
                    }
                }
            });
        },

        /**
         * Update stat cards with new data (without reload)
         */
        updateStatCards: function(stats) {
            // Update any visible stat numbers on the page
            // This is a best-effort update - full data requires refresh
            if (stats.unused_count !== undefined) {
                $('a[href*="tab=unused"] .mui-tab-badge').text(stats.unused_count);
            }
        },

        /**
         * Export report
         */
        exportReport: function() {
            var $button = $('#mui-export-report');
            $button.prop('disabled', true);

            $.ajax({
                url: unmamAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'unmam_export_report',
                    nonce: unmamAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Download CSV
                        var blob = new Blob([response.data.csv], { type: 'text/csv' });
                        var url = URL.createObjectURL(blob);
                        var a = document.createElement('a');
                        a.href = url;
                        a.download = response.data.filename;
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        URL.revokeObjectURL(url);
                    }
                    $button.prop('disabled', false);
                },
                error: function() {
                    $button.prop('disabled', false);
                }
            });
        },

        /**
         * Attach all used but unattached media (uses job queue)
         */
        attachAllUsed: function() {
            var self = this;

            this.showConfirmModal({
                title: 'Attach Media',
                message: unmamAdmin.strings.confirmAttach,
                confirmText: 'Attach All',
                type: 'info'
            }, function() {
                var $button = $('#mui-attach-all-used');
                var $status = $('#mui-attach-status');
                var originalText = $button.text();

                $button.prop('disabled', true).text(unmamAdmin.strings.attaching);
                $status.html('<span class="spinner is-active" style="float: none; margin: 0 5px;"></span>');

                // Start job with frontend-driven processing (no page reload)
                self.startJobWithLoop('attach', [], {}, '#mui-attach-all-used', originalText);
            });
        },

        /**
         * Mark as safe
         */
        markSafe: function($button) {
            var attachmentId = $button.data('attachment-id');

            $.ajax({
                url: unmamAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'unmam_mark_safe',
                    nonce: unmamAdmin.nonce,
                    attachment_id: attachmentId
                },
                success: function(response) {
                    if (response.success) {
                        $button.text('Unmark as Safe')
                               .removeClass('mui-mark-safe')
                               .addClass('mui-unmark-safe');

                        // Add safe badge
                        $button.closest('.mui-usage-panel')
                               .find('.mui-usage-summary')
                               .append('<span class="mui-safe-badge">Marked Safe</span>');
                    }
                }
            });
        },

        /**
         * Unmark as safe
         */
        unmarkSafe: function($button) {
            var attachmentId = $button.data('attachment-id');

            $.ajax({
                url: unmamAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'unmam_unmark_safe',
                    nonce: unmamAdmin.nonce,
                    attachment_id: attachmentId
                },
                success: function(response) {
                    if (response.success) {
                        $button.text('Mark as Safe')
                               .removeClass('mui-unmark-safe')
                               .addClass('mui-mark-safe');

                        // Remove safe badge
                        $button.closest('.mui-usage-panel')
                               .find('.mui-safe-badge')
                               .remove();
                    }
                }
            });
        },

        /**
         * Revert a single history item
         */
        revertSingle: function($button) {
            var self = this;
            var historyId = $button.data('id');
            var $row = $button.closest('tr');
            var originalText = $button.text();

            this.showConfirmModal({
                title: 'Revert Change',
                message: unmamAdmin.strings.confirmRevert,
                confirmText: 'Revert',
                type: 'warning'
            }, function() {
                $button.prop('disabled', true).text(unmamAdmin.strings.reverting);

                $.ajax({
                    url: unmamAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'unmam_revert_change',
                        nonce: unmamAdmin.nonce,
                        history_id: historyId
                    },
                    success: function(response) {
                        if (response.success) {
                            // Update row to show reverted status
                            $row.find('.mui-status-badge').removeClass('mui-status-success').addClass('mui-status-warning').text('Reverted');
                            $button.replaceWith('<span class="description">Already reverted</span>');
                            $row.find('.mui-history-checkbox').remove();

                            // Update stats if visible
                            self.updateHistoryStats(response.data.stats);

                            // Update unattached media badge if count is provided
                            if (response.data.unattached_count !== undefined) {
                                self.updateUnattachedBadge(response.data.unattached_count);
                            }
                        } else {
                            self.showError(response.data || unmamAdmin.strings.revertError);
                            $button.prop('disabled', false).text(originalText);
                        }
                    },
                    error: function() {
                        self.showError(unmamAdmin.strings.revertError);
                        $button.prop('disabled', false).text(originalText);
                    }
                });
            });
        },

        /**
         * Update the Unattached Media tab badge
         * @param {number} count - Number of unattached media items
         */
        updateUnattachedBadge: function(count) {
            var $badge = $('a[href*="tab=unattached"] .mui-tab-badge');
            if (count > 0) {
                if ($badge.length) {
                    $badge.text(count);
                } else {
                    $('a[href*="tab=unattached"]').append('<span class="mui-tab-badge mui-badge-info">' + count + '</span>');
                }
            } else {
                $badge.remove();
            }
        },

        /**
         * Update revert selected button state
         */
        updateRevertSelectedButton: function() {
            var checkedCount = $('.mui-history-checkbox:checked').length;
            $('#mui-revert-selected').prop('disabled', checkedCount === 0);
        },

        /**
         * Revert multiple history items (uses job queue for bulk)
         */
        revertBulk: function() {
            var self = this;
            var $checkedBoxes = $('.mui-history-checkbox:checked');

            if ($checkedBoxes.length === 0) {
                self.showConfirmModal({
                    title: 'No Selection',
                    message: unmamAdmin.strings.noSelection,
                    confirmText: 'OK',
                    type: 'info'
                }, function() {});
                return;
            }

            var historyIds = [];
            $checkedBoxes.each(function() {
                historyIds.push($(this).val());
            });

            this.showConfirmModal({
                title: 'Revert Selected',
                message: unmamAdmin.strings.confirmRevertBulk,
                confirmText: 'Revert Selected',
                type: 'warning'
            }, function() {
                var $button = $('#mui-revert-selected');
                var originalText = $button.text();

                $button.prop('disabled', true).text(unmamAdmin.strings.reverting);

                // Start job with frontend-driven processing (no page reload)
                self.startJobWithLoop('revert', historyIds, {}, '#mui-revert-selected', originalText);
            });
        },

        /**
         * Update history stats display
         */
        updateHistoryStats: function(stats) {
            if (!stats) return;

            // Update stat cards if they exist
            $('.mui-stats-small .mui-stat-card').each(function(index) {
                var $card = $(this);
                var $number = $card.find('.mui-stat-number');
                var label = $card.find('.mui-stat-label').text().toLowerCase();

                if (label.indexOf('total') >= 0) {
                    $number.text(stats.total_changes.toLocaleString());
                } else if (label.indexOf('active') >= 0) {
                    $number.text(stats.active_changes.toLocaleString());
                } else if (label.indexOf('reverted') >= 0) {
                    $number.text(stats.reverted_changes.toLocaleString());
                } else if (label.indexOf('unique') >= 0) {
                    $number.text(stats.unique_attachments.toLocaleString());
                }
            });

            // Update tab badge
            var $badge = $('.nav-tab .mui-tab-badge');
            if (stats.active_changes > 0) {
                if ($badge.length) {
                    $badge.text(stats.active_changes);
                } else {
                    $('a[href*="tab=history"]').append('<span class="mui-tab-badge">' + stats.active_changes + '</span>');
                }
            } else {
                $badge.remove();
            }
        },

        /**
         * Update unused media buttons state
         */
        updateUnusedButtons: function() {
            var checkedCount = $('.mui-unused-checkbox:checked').length;
            $('#mui-trash-selected').prop('disabled', checkedCount === 0);
            $('#mui-restore-selected').prop('disabled', checkedCount === 0);
            $('#mui-delete-selected-permanently').prop('disabled', checkedCount === 0);
        },

        /**
         * Trash a single media item (immediate, single item)
         */
        trashSingle: function($button) {
            var self = this;
            var attachmentId = $button.data('id');
            var $row = $button.closest('tr');
            var originalText = $button.text();

            this.showConfirmModal({
                title: 'Move to Trash',
                message: unmamAdmin.strings.confirmTrash,
                confirmText: 'Move to Trash',
                type: 'warning'
            }, function() {
                $button.prop('disabled', true).text(unmamAdmin.strings.trashing);

                $.ajax({
                    url: unmamAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'unmam_trash_media',
                        nonce: unmamAdmin.nonce,
                        attachment_id: attachmentId
                    },
                    success: function(response) {
                        if (response.success) {
                            $row.fadeOut(300, function() {
                                $(this).remove();
                                self.updateUnusedCounts(response.data);
                            });
                        } else {
                            self.showError(response.data || unmamAdmin.strings.trashError);
                            $button.prop('disabled', false).text(originalText);
                        }
                    },
                    error: function() {
                        self.showError(unmamAdmin.strings.trashError);
                        $button.prop('disabled', false).text(originalText);
                    }
                });
            });
        },

        /**
         * Trash multiple media items (uses job queue)
         */
        trashBulk: function() {
            var self = this;
            var $checkedBoxes = $('.mui-unused-checkbox:checked');

            if ($checkedBoxes.length === 0) {
                self.showConfirmModal({
                    title: 'No Selection',
                    message: unmamAdmin.strings.noSelection,
                    confirmText: 'OK',
                    type: 'info'
                }, function() {});
                return;
            }

            var attachmentIds = [];
            $checkedBoxes.each(function() {
                attachmentIds.push($(this).val());
            });

            this.showConfirmModal({
                title: 'Trash Selected',
                message: unmamAdmin.strings.confirmTrashBulk,
                confirmText: 'Trash Selected',
                type: 'warning'
            }, function() {
                var $button = $('#mui-trash-selected');
                var originalText = $button.text();

                $button.prop('disabled', true).text(unmamAdmin.strings.trashing);

                // Start job with frontend-driven processing (no page reload)
                self.startJobWithLoop('trash', attachmentIds, {}, '#mui-trash-selected', originalText);
            });
        },

        /**
         * Restore a single media item (immediate, single item)
         */
        restoreSingle: function($button) {
            var self = this;
            var attachmentId = $button.data('id');
            var $row = $button.closest('tr');
            var originalText = $button.text();

            this.showConfirmModal({
                title: 'Restore from Trash',
                message: unmamAdmin.strings.confirmRestore,
                confirmText: 'Restore',
                type: 'info'
            }, function() {
                $button.prop('disabled', true).text(unmamAdmin.strings.restoring);

                $.ajax({
                    url: unmamAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'unmam_restore_media',
                        nonce: unmamAdmin.nonce,
                        attachment_id: attachmentId
                    },
                    success: function(response) {
                        if (response.success) {
                            $row.fadeOut(300, function() {
                                $(this).remove();
                                self.updateUnusedCounts(response.data);
                            });
                        } else {
                            self.showError(response.data || unmamAdmin.strings.restoreError);
                            $button.prop('disabled', false).text(originalText);
                        }
                    },
                    error: function() {
                        self.showError(unmamAdmin.strings.restoreError);
                        $button.prop('disabled', false).text(originalText);
                    }
                });
            });
        },

        /**
         * Restore multiple media items (uses job queue)
         */
        restoreBulk: function() {
            var self = this;
            var $checkedBoxes = $('.mui-unused-checkbox:checked');

            if ($checkedBoxes.length === 0) {
                self.showConfirmModal({
                    title: 'No Selection',
                    message: unmamAdmin.strings.noSelection,
                    confirmText: 'OK',
                    type: 'info'
                }, function() {});
                return;
            }

            var attachmentIds = [];
            $checkedBoxes.each(function() {
                attachmentIds.push($(this).val());
            });

            this.showConfirmModal({
                title: 'Restore Selected',
                message: unmamAdmin.strings.confirmRestoreBulk,
                confirmText: 'Restore Selected',
                type: 'info'
            }, function() {
                var $button = $('#mui-restore-selected');
                var originalText = $button.text();

                $button.prop('disabled', true).text(unmamAdmin.strings.restoring);

                // Start job with frontend-driven processing (no page reload)
                self.startJobWithLoop('restore', attachmentIds, {}, '#mui-restore-selected', originalText);
            });
        },

        /**
         * Delete a single media item permanently (immediate, single item)
         */
        deleteSinglePermanently: function($button) {
            var self = this;
            var attachmentId = $button.data('id');
            var $row = $button.closest('tr');
            var originalText = $button.text();

            this.showConfirmModal({
                title: 'Delete Permanently',
                message: unmamAdmin.strings.confirmDelete,
                confirmText: 'Delete Permanently',
                type: 'danger'
            }, function() {
                $button.prop('disabled', true).text(unmamAdmin.strings.deleting);

                $.ajax({
                    url: unmamAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'unmam_delete_single',
                        nonce: unmamAdmin.nonce,
                        attachment_id: attachmentId
                    },
                    success: function(response) {
                        if (response.success) {
                            $row.fadeOut(300, function() {
                                $(this).remove();
                                self.updateUnusedCounts(response.data);
                            });
                        } else {
                            self.showError(response.data || unmamAdmin.strings.deleteError);
                            $button.prop('disabled', false).text(originalText);
                        }
                    },
                    error: function() {
                        self.showError(unmamAdmin.strings.deleteError);
                        $button.prop('disabled', false).text(originalText);
                    }
                });
            });
        },

        /**
         * Delete multiple media items permanently (uses job queue)
         */
        deleteBulkPermanently: function() {
            var self = this;
            var $checkedBoxes = $('.mui-unused-checkbox:checked');

            if ($checkedBoxes.length === 0) {
                self.showConfirmModal({
                    title: 'No Selection',
                    message: unmamAdmin.strings.noSelection,
                    confirmText: 'OK',
                    type: 'info'
                }, function() {});
                return;
            }

            var attachmentIds = [];
            $checkedBoxes.each(function() {
                attachmentIds.push($(this).val());
            });

            this.showConfirmModal({
                title: 'Delete Permanently',
                message: unmamAdmin.strings.confirmDeleteBulk,
                confirmText: 'Delete Permanently',
                type: 'danger'
            }, function() {
                var $button = $('#mui-delete-selected-permanently');
                var originalText = $button.text();

                $button.prop('disabled', true).text(unmamAdmin.strings.deleting);

                // Start job with frontend-driven processing (no page reload)
                self.startJobWithLoop('delete', attachmentIds, {}, '#mui-delete-selected-permanently', originalText);
            });
        },

        /**
         * Empty trash (uses job queue)
         */
        emptyTrash: function() {
            var self = this;

            this.showConfirmModal({
                title: 'Empty Trash',
                message: unmamAdmin.strings.confirmEmptyTrash,
                confirmText: 'Empty Trash',
                type: 'danger'
            }, function() {
                var $button = $('#mui-empty-trash');
                var originalText = $button.text();

                $button.prop('disabled', true).text(unmamAdmin.strings.deleting);

                // Start job with frontend-driven processing (no page reload)
                self.startJobWithLoop('empty_trash', [], {}, '#mui-empty-trash', originalText);
            });
        },

        /**
         * Trash all unused media (uses job queue)
         */
        trashAllUnused: function() {
            var self = this;

            this.showConfirmModal({
                title: 'Trash All Unused',
                message: unmamAdmin.strings.confirmTrashAllUnused,
                confirmText: 'Trash All',
                type: 'warning'
            }, function() {
                var $button = $('#mui-trash-all-unused');
                var originalText = $button.text();

                $button.prop('disabled', true).text(unmamAdmin.strings.trashing);

                // Start job with frontend-driven processing (no page reload)
                self.startJobWithLoop('trash', [], {}, '#mui-trash-all-unused', originalText);
            });
        },

        /**
         * Revert all active changes (uses job queue)
         */
        revertAllActive: function() {
            var self = this;

            this.showConfirmModal({
                title: 'Revert All Changes',
                message: unmamAdmin.strings.confirmRevertAll,
                confirmText: 'Revert All',
                type: 'warning'
            }, function() {
                var $button = $('#mui-revert-all-active');
                var originalText = $button.text();

                $button.prop('disabled', true).text(unmamAdmin.strings.reverting);

                // Start job with frontend-driven processing (no page reload)
                self.startJobWithLoop('revert', [], {}, '#mui-revert-all-active', originalText);
            });
        },

        /**
         * Attach all unattached media (uses job queue)
         */
        attachAllUnattached: function() {
            var self = this;

            this.showConfirmModal({
                title: 'Attach All Media',
                message: unmamAdmin.strings.confirmAttachAll,
                confirmText: 'Attach All',
                type: 'info'
            }, function() {
                var $button = $('#mui-attach-all-unattached');
                var originalText = $button.text();

                $button.prop('disabled', true).text(unmamAdmin.strings.attaching);

                // Start job with frontend-driven processing (no page reload)
                self.startJobWithLoop('attach', [], {}, '#mui-attach-all-unattached', originalText);
            });
        },

        /**
         * Attach selected unattached media (uses job queue)
         */
        attachSelected: function() {
            var self = this;
            var selectedIds = [];

            $('.mui-unattached-checkbox:checked').each(function() {
                selectedIds.push(parseInt($(this).val()));
            });

            if (selectedIds.length === 0) {
                self.showConfirmModal({
                    title: 'No Selection',
                    message: unmamAdmin.strings.noSelection,
                    confirmText: 'OK',
                    type: 'info'
                }, function() {});
                return;
            }

            this.showConfirmModal({
                title: 'Attach Selected Media',
                message: unmamAdmin.strings.confirmAttachSelected,
                confirmText: 'Attach Selected',
                type: 'info'
            }, function() {
                var $button = $('#mui-attach-selected');
                var originalText = $button.text();

                $button.prop('disabled', true).text(unmamAdmin.strings.attaching);

                // Start job with frontend-driven processing (no page reload)
                self.startJobWithLoop('attach', selectedIds, {}, '#mui-attach-selected', originalText);
            });
        },

        /**
         * Attach a single media item (immediate)
         */
        attachSingle: function(attachmentId, parentId, $button) {
            var self = this;
            var originalText = $button.text();

            $button.prop('disabled', true).text(unmamAdmin.strings.attaching);

            $.ajax({
                url: unmamAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'unmam_attach_single',
                    nonce: unmamAdmin.nonce,
                    attachment_id: attachmentId,
                    parent_id: parentId
                },
                success: function(response) {
                    if (response.success) {
                        // Remove the row from the table
                        $button.closest('tr').fadeOut(300, function() {
                            $(this).remove();

                            // Update the count in the stats card
                            var $countSpan = $('.mui-stat-card.mui-stat-info .mui-stat-number');
                            if ($countSpan.length) {
                                var newCount = response.data.unattached_count || 0;
                                $countSpan.text(newCount.toLocaleString());
                            }

                            // Update the "Attach All" button text
                            var $attachAllBtn = $('#mui-attach-all-unattached');
                            if ($attachAllBtn.length && response.data.unattached_count !== undefined) {
                                if (response.data.unattached_count > 0) {
                                    $attachAllBtn.text(unmamAdmin.strings.attachAll ? unmamAdmin.strings.attachAll.replace('%d', response.data.unattached_count) : 'Attach All ' + response.data.unattached_count);
                                } else {
                                    $attachAllBtn.prop('disabled', true).text('No items to attach');
                                }
                            }

                            // If no more rows, show empty message
                            if ($('#mui-unattached-table tbody tr').length === 0) {
                                $('#mui-unattached-table').replaceWith('<p class="description" style="padding: 20px; text-align: center;">No unattached media found that is currently in use.</p>');
                                $('#mui-attach-all-unattached, #mui-attach-selected').prop('disabled', true);
                            }
                        });
                    } else {
                        self.showError(response.data || unmamAdmin.strings.attachError);
                        $button.prop('disabled', false).text(originalText);
                    }
                },
                error: function() {
                    self.showError(unmamAdmin.strings.attachError);
                    $button.prop('disabled', false).text(originalText);
                }
            });
        },

        /**
         * Update unattached buttons based on selection
         */
        updateUnattachedButtons: function() {
            var selectedCount = $('.mui-unattached-checkbox:checked').length;
            var totalCount = $('.mui-unattached-checkbox').length;

            $('#mui-attach-selected').prop('disabled', selectedCount === 0);
            $('#mui-unattached-select-all').prop('checked', selectedCount === totalCount && totalCount > 0);
        },

        /**
         * Update unused/trash counts
         */
        updateUnusedCounts: function(data) {
            if (!data) return;

            // Update stat cards
            $('.mui-stats-small .mui-stat-card').each(function() {
                var $card = $(this);
                var $number = $card.find('.mui-stat-number');
                var label = $card.find('.mui-stat-label').text().toLowerCase();

                if (label.indexOf('unused') >= 0) {
                    $number.text(data.unused_count.toLocaleString());
                } else if (label.indexOf('trash') >= 0) {
                    $number.text(data.trash_count.toLocaleString());
                }
            });

            // Update filter counts
            $('.mui-unused-filters .count').each(function() {
                var $count = $(this);
                var $link = $count.closest('a');
                var href = $link.attr('href');

                if (href.indexOf('view=trash') >= 0) {
                    $count.text('(' + data.trash_count + ')');
                } else if (href.indexOf('view=unused') >= 0 || href.indexOf('view') === -1) {
                    $count.text('(' + data.unused_count + ')');
                }
            });

            // Update unused tab badge
            var $badge = $('a[href*="tab=unused"] .mui-tab-badge');
            if (data.unused_count > 0) {
                if ($badge.length) {
                    $badge.text(data.unused_count);
                } else {
                    $('a[href*="tab=unused"]').append('<span class="mui-tab-badge mui-badge-warning">' + data.unused_count + '</span>');
                }
            } else {
                $badge.remove();
            }

            // Update unattached tab badge if count is provided
            if (data.unattached_count !== undefined) {
                this.updateUnattachedBadge(data.unattached_count);
            }
        },

        // ==========================================
        // Job Queue Methods
        // ==========================================

        /**
         * Start polling for job status
         */
        startJobPolling: function() {
            var self = this;

            if (this.isJobPolling) {
                return;
            }

            this.isJobPolling = true;
            this.jobPollIntervalId = setInterval(function() {
                self.pollJobStatus();
            }, unmamAdmin.pollInterval || 3000);
        },

        /**
         * Stop job polling
         */
        stopJobPolling: function() {
            this.isJobPolling = false;
            if (this.jobPollIntervalId) {
                clearInterval(this.jobPollIntervalId);
                this.jobPollIntervalId = null;
            }
        },

        /**
         * Poll for job status
         */
        pollJobStatus: function() {
            var self = this;

            $.ajax({
                url: unmamAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'unmam_get_job_status',
                    nonce: unmamAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.jobState = response.data;
                        self.updateJobStatusBar(response.data);

                        // Update counts
                        if (response.data.counts) {
                            self.updateUnusedCounts(response.data.counts);
                        }

                        // Check if we should stop polling
                        var status = response.data.job.status;
                        if (status !== 'running' && status !== 'paused') {
                            self.stopJobPolling();

                            // Handle completion
                            if (status === 'completed' || status === 'cancelled') {
                                self.onJobComplete(response.data);
                            }
                        }
                    }
                },
                error: function() {
                    // Continue polling even on error
                }
            });
        },

        /**
         * Update the job status bar UI
         */
        updateJobStatusBar: function(data) {
            var job = data.job || {};
            var $statusBar = $('#mui-job-status-bar');

            // If no status bar exists and job is active, create one
            if (!$statusBar.length && (job.status === 'running' || job.status === 'paused')) {
                this.createJobStatusBar(data);
                $statusBar = $('#mui-job-status-bar');
            }

            if (!$statusBar.length) {
                return;
            }

            // Update classes
            $statusBar.removeClass('mui-job-running mui-job-paused mui-job-completed mui-job-cancelled mui-job-hidden');

            if (job.status === 'running') {
                $statusBar.addClass('mui-job-running');
            } else if (job.status === 'paused') {
                $statusBar.addClass('mui-job-paused');
            } else if (job.status === 'completed') {
                $statusBar.addClass('mui-job-completed');
            } else if (job.status === 'cancelled') {
                $statusBar.addClass('mui-job-cancelled');
            } else {
                $statusBar.addClass('mui-job-hidden');
            }

            // Update progress
            var percentage = data.percentage || 0;
            $('#mui-job-progress-fill').css('width', percentage + '%');

            // Update text
            var progressText = unmamAdmin.strings.processingItems
                .replace('%1$d', job.processed_items || 0)
                .replace('%2$d', job.total_items || 0);
            $('#mui-job-progress-text').text(progressText);

            // Update buttons
            var $actions = $statusBar.find('.mui-job-actions');
            if (job.status === 'running') {
                $actions.html(
                    '<button type="button" class="button button-small" id="mui-pause-job">' + unmamAdmin.strings.pauseProcess + '</button>' +
                    '<button type="button" class="button button-small button-link-delete" id="mui-stop-job">' + unmamAdmin.strings.stopProcess + '</button>'
                );
            } else if (job.status === 'paused') {
                $actions.html(
                    '<button type="button" class="button button-small button-primary" id="mui-resume-job">' + unmamAdmin.strings.resumeProcess + '</button>' +
                    '<button type="button" class="button button-small button-link-delete" id="mui-stop-job">' + unmamAdmin.strings.stopProcess + '</button>'
                );
            } else if (job.status === 'completed' || job.status === 'cancelled') {
                // Show refresh button for completed/cancelled jobs
                $actions.html(
                    '<button type="button" class="button button-small button-primary" id="mui-refresh-page">' + (unmamAdmin.strings.refreshPage || 'Refresh Page') + '</button>'
                );
            }

            // Update job type label
            var $badge = $statusBar.find('.mui-status-badge');
            if (job.status === 'running') {
                $badge.removeClass('mui-status-success mui-status-warning').addClass('mui-status-running');
                $badge.html(data.job_type_label + '<span class="mui-pulse"></span>');
            } else if (job.status === 'paused') {
                $badge.removeClass('mui-status-success mui-status-running').addClass('mui-status-warning');
                $badge.text(unmamAdmin.strings.jobPaused + ': ' + data.job_type_label);
            } else if (job.status === 'completed') {
                $badge.removeClass('mui-status-running mui-status-warning').addClass('mui-status-success');
                $badge.text((unmamAdmin.strings.jobCompleted || 'Completed') + ': ' + data.job_type_label);
            } else if (job.status === 'cancelled') {
                $badge.removeClass('mui-status-running mui-status-success').addClass('mui-status-warning');
                $badge.text((unmamAdmin.strings.jobCancelled || 'Cancelled') + ': ' + data.job_type_label);
            }

            // Update the note text based on status
            var $note = $statusBar.find('.mui-job-note');
            if ($note.length) {
                if (job.status === 'completed') {
                    var successCount = job.successful_items || 0;
                    var failedCount = job.failed_items || 0;
                    var successText = unmamAdmin.strings.jobSuccess ? unmamAdmin.strings.jobSuccess.replace('%d', successCount) : 'Successfully processed ' + successCount + ' items.';
                    var noteHtml = '<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>' + successText;
                    if (failedCount > 0) {
                        var failedText = unmamAdmin.strings.jobErrors ? unmamAdmin.strings.jobErrors.replace('%d', failedCount) : failedCount + ' items failed.';
                        noteHtml += ' <span style="color: #d63638;">' + failedText + '</span>';
                    }
                    $note.html(noteHtml);
                } else if (job.status === 'cancelled') {
                    $note.html('<span class="dashicons dashicons-warning" style="color: #dba617;"></span>' + (unmamAdmin.strings.jobCancelled || 'Job was cancelled.'));
                } else {
                    // For running/paused, show processing mode note
                    var jobProcessingMode = data.processing_mode || unmamAdmin.processingMode || 'frontend';
                    var noteIcon = jobProcessingMode === 'background' ? 'dashicons-info' : 'dashicons-warning';
                    var noteText = jobProcessingMode === 'background'
                        ? (unmamAdmin.strings.jobNoteBackground || 'This process runs in the background. You can close this page and it will continue automatically.')
                        : (unmamAdmin.strings.jobNoteFrontend || 'Keep this browser tab open. Processing will stop if you close it.');
                    $note.html('<span class="dashicons ' + noteIcon + '"></span>' + noteText);
                }
            }
        },

        /**
         * Create the job status bar dynamically
         */
        createJobStatusBar: function(data) {
            var job = data.job || {};
            var percentage = data.percentage || 0;

            // Get the processing mode from the job data, fallback to global setting
            var jobProcessingMode = data.processing_mode || unmamAdmin.processingMode || 'frontend';

            // Get the appropriate note based on job's processing mode
            var noteIcon = jobProcessingMode === 'background' ? 'dashicons-info' : 'dashicons-warning';
            var noteText = jobProcessingMode === 'background'
                ? (unmamAdmin.strings.jobNoteBackground || 'This process runs in the background. You can close this page and it will continue automatically.')
                : (unmamAdmin.strings.jobNoteFrontend || 'Keep this browser tab open. Processing will stop if you close it.');

            var html = '<div class="mui-job-status-bar mui-job-running" id="mui-job-status-bar">' +
                '<div class="mui-job-status-inner">' +
                    '<div class="mui-job-info">' +
                        '<span class="mui-status-badge mui-status-running">' + data.job_type_label + '<span class="mui-pulse"></span></span>' +
                        '<span class="mui-job-progress-text" id="mui-job-progress-text">' +
                            unmamAdmin.strings.processingItems.replace('%1$d', job.processed_items || 0).replace('%2$d', job.total_items || 0) +
                        '</span>' +
                    '</div>' +
                    '<div class="mui-job-progress-bar">' +
                        '<div class="mui-progress-fill" id="mui-job-progress-fill" style="width: ' + percentage + '%;"></div>' +
                    '</div>' +
                    '<div class="mui-job-actions">' +
                        '<button type="button" class="button button-small" id="mui-pause-job">' + unmamAdmin.strings.pauseProcess + '</button>' +
                        '<button type="button" class="button button-small button-link-delete" id="mui-stop-job">' + unmamAdmin.strings.stopProcess + '</button>' +
                    '</div>' +
                '</div>' +
                '<p class="mui-job-note">' +
                    '<span class="dashicons ' + noteIcon + '"></span>' +
                    noteText +
                '</p>' +
            '</div>';

            // Insert after the h1 title
            $('.mui-admin-wrap > h1').after(html);
        },

        /**
         * Pause the current job
         */
        pauseJob: function() {
            var self = this;
            var $button = $('#mui-pause-job');

            $button.prop('disabled', true);

            // Stop batch processing
            self.isJobBatchProcessing = false;

            $.ajax({
                url: unmamAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'unmam_pause_job',
                    nonce: unmamAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.stopJobPolling();
                        self.jobState = response.data;
                        self.updateJobStatusBar(response.data);
                    } else {
                        self.showError(response.data || unmamAdmin.strings.error);
                        $button.prop('disabled', false);
                    }
                },
                error: function() {
                    self.showError(unmamAdmin.strings.error);
                    $button.prop('disabled', false);
                }
            });
        },

        /**
         * Resume the current job
         */
        resumeJob: function() {
            var self = this;
            var $button = $('#mui-resume-job');

            $button.prop('disabled', true);

            $.ajax({
                url: unmamAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'unmam_resume_job',
                    nonce: unmamAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.jobState = response.data;
                        self.updateJobStatusBar(response.data);

                        // Use the job's processing mode, not the global setting
                        var jobProcessingMode = response.data.processing_mode || unmamAdmin.processingMode || 'frontend';

                        // Use appropriate processing method based on job's mode
                        if (jobProcessingMode === 'background') {
                            self.startJobPolling();
                        } else {
                            // Default to frontend-driven batch processing
                            self.runJobLoop();
                        }
                    } else {
                        self.showError(response.data || unmamAdmin.strings.error);
                        $button.prop('disabled', false);
                    }
                },
                error: function() {
                    self.showError(unmamAdmin.strings.error);
                    $button.prop('disabled', false);
                }
            });
        },

        /**
         * Stop the current job
         */
        stopJob: function() {
            var self = this;

            this.showConfirmModal({
                title: 'Stop Process',
                message: unmamAdmin.strings.confirmStopJob,
                confirmText: 'Stop Process',
                type: 'danger'
            }, function() {
                var $button = $('#mui-stop-job');
                $button.prop('disabled', true);

                // Stop batch processing
                self.isJobBatchProcessing = false;

                $.ajax({
                    url: unmamAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'unmam_stop_job',
                        nonce: unmamAdmin.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            self.stopJobPolling();
                            self.jobState = response.data;
                            self.updateJobStatusBar(response.data);
                            self.onJobComplete(response.data);
                        } else {
                            self.showError(response.data || unmamAdmin.strings.error);
                            $button.prop('disabled', false);
                        }
                    },
                    error: function() {
                        self.showError(unmamAdmin.strings.error);
                        $button.prop('disabled', false);
                    }
                });
            });
        },

        /**
         * Handle job completion
         */
        onJobComplete: function(data) {
            var self = this;
            var job = data.job || {};
            var $statusBar = $('#mui-job-status-bar');

            // Update the status bar for completion
            $statusBar.removeClass('mui-job-running mui-job-paused');

            if (job.status === 'completed') {
                $statusBar.addClass('mui-job-completed');
                $statusBar.find('.mui-status-badge').removeClass('mui-status-running mui-status-warning')
                    .addClass('mui-status-success').text(unmamAdmin.strings.jobCompleted || 'Completed');
            } else if (job.status === 'cancelled') {
                $statusBar.addClass('mui-job-cancelled');
                $statusBar.find('.mui-status-badge').removeClass('mui-status-running mui-status-warning')
                    .addClass('mui-status-pending').text(unmamAdmin.strings.jobCancelled || 'Cancelled');
            }

            // Update the progress text to show final results
            var resultText = (unmamAdmin.strings.jobSuccess || 'Successfully processed %d items').replace('%d', job.successful_items || 0);
            if (job.failed_items > 0) {
                resultText += ' ' + (unmamAdmin.strings.jobErrors || '(%d failed)').replace('%d', job.failed_items);
            }
            $('#mui-job-progress-text').text(resultText);

            // Update counts if available
            if (data.counts) {
                self.updateUnusedCounts(data.counts);
            }

            // Update buttons to show "Refresh Page" button
            $statusBar.find('.mui-job-actions').html(
                '<button type="button" class="button button-small button-primary" onclick="location.reload();">' +
                (unmamAdmin.strings.refreshPage || 'Refresh Page') +
                '</button>'
            );

            // Update the note
            $statusBar.find('.mui-job-note').html(
                '<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span> ' +
                'Process completed. Refresh the page to see updated data.'
            );
        },

        /**
         * Show error message
         */
        showError: function(message) {
            this.showConfirmModal({
                title: 'Error',
                message: message,
                confirmText: 'OK',
                type: 'danger'
            }, function() {});
        }
    };

    $(document).ready(function() {
        MUI_Admin.init();
    });

})(jQuery);
