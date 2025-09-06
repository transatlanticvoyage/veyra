/**
 * Veyra Elephant Tools - Admin Bar Post Title Editor
 */
(function($) {
    'use strict';
    
    window.VeyraElephantTools = {
        
        modal: null,
        overlay: null,
        
        init: function() {
            this.createModal();
        },
        
        createModal: function() {
            // Create overlay
            this.overlay = $('<div id="veyra-modal-overlay"></div>');
            
            // Create modal
            this.modal = $('<div id="veyra-elephant-modal">' +
                '<div class="veyra-modal-header">' +
                    '<h3>Elephant Tools - Edit Post Title</h3>' +
                    '<button class="veyra-modal-close">&times;</button>' +
                '</div>' +
                '<div class="veyra-modal-body">' +
                    '<div class="veyra-loading">Loading...</div>' +
                    '<div class="veyra-content" style="display:none;">' +
                        '<label for="veyra-post-title">Post Title:</label>' +
                        '<input type="text" id="veyra-post-title" class="veyra-title-input" />' +
                        '<div class="veyra-modal-actions">' +
                            '<button id="veyra-save-title" class="veyra-btn-save">Save</button>' +
                            '<button id="veyra-cancel" class="veyra-btn-cancel">Cancel</button>' +
                        '</div>' +
                    '</div>' +
                    '<div class="veyra-message"></div>' +
                '</div>' +
            '</div>');
            
            // Append to body
            $('body').append(this.overlay).append(this.modal);
            
            // Bind events
            this.bindEvents();
        },
        
        bindEvents: function() {
            var self = this;
            
            // Close modal events
            this.overlay.on('click', function() {
                self.closeModal();
            });
            
            $('.veyra-modal-close, #veyra-cancel').on('click', function() {
                self.closeModal();
            });
            
            // Save button
            $('#veyra-save-title').on('click', function() {
                self.saveTitle();
            });
            
            // Enter key to save
            $('#veyra-post-title').on('keypress', function(e) {
                if (e.which === 13) {
                    self.saveTitle();
                }
            });
            
            // ESC key to close
            $(document).on('keydown', function(e) {
                if (e.keyCode === 27 && self.modal.is(':visible')) {
                    self.closeModal();
                }
            });
        },
        
        openModal: function() {
            var self = this;
            
            // Show modal and overlay
            this.overlay.fadeIn(200);
            this.modal.fadeIn(200);
            
            // Reset state
            $('.veyra-loading').show();
            $('.veyra-content').hide();
            $('.veyra-message').hide().empty();
            
            // Get current post title
            $.ajax({
                url: veyra_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'veyra_get_post_title',
                    post_id: veyra_ajax.current_post_id,
                    nonce: veyra_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#veyra-post-title').val(response.data.post_title);
                        $('.veyra-loading').hide();
                        $('.veyra-content').show();
                        $('#veyra-post-title').focus().select();
                    } else {
                        self.showMessage('Error loading post title', 'error');
                    }
                },
                error: function() {
                    self.showMessage('Failed to load post data', 'error');
                }
            });
        },
        
        closeModal: function() {
            this.overlay.fadeOut(200);
            this.modal.fadeOut(200);
        },
        
        saveTitle: function() {
            var self = this;
            var newTitle = $('#veyra-post-title').val().trim();
            
            if (!newTitle) {
                this.showMessage('Please enter a title', 'error');
                return;
            }
            
            // Disable save button during request
            $('#veyra-save-title').prop('disabled', true).text('Saving...');
            
            $.ajax({
                url: veyra_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'veyra_update_post_title',
                    post_id: veyra_ajax.current_post_id,
                    new_title: newTitle,
                    nonce: veyra_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.showMessage('Title updated successfully!', 'success');
                        
                        // Update page title if possible
                        document.title = newTitle;
                        $('h1, .entry-title, .page-title').first().text(newTitle);
                        
                        // Close modal after delay
                        setTimeout(function() {
                            self.closeModal();
                        }, 1500);
                    } else {
                        self.showMessage('Failed to update title', 'error');
                    }
                },
                error: function() {
                    self.showMessage('Error updating title', 'error');
                },
                complete: function() {
                    $('#veyra-save-title').prop('disabled', false).text('Save');
                }
            });
        },
        
        showMessage: function(message, type) {
            var messageClass = type === 'error' ? 'veyra-error' : 'veyra-success';
            $('.veyra-message')
                .removeClass('veyra-error veyra-success')
                .addClass(messageClass)
                .html(message)
                .fadeIn(200);
            
            $('.veyra-loading').hide();
            $('.veyra-content').show();
        }
    };
    
    // Initialize when DOM is ready
    $(document).ready(function() {
        VeyraElephantTools.init();
    });
    
})(jQuery);